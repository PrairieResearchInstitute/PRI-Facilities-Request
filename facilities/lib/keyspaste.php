<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rooms.php';

/**
 * Key assignments arrive as a web page table with no export, so an
 * administrator selects the table in the browser, copies it, and pastes
 * it here. Both forms of paste are accepted: the HTML a browser puts on
 * the clipboard, and the tab separated text a plain paste produces.
 *
 * The geodatabase carries the key code that opens a room; it carries no
 * holder, no state, and no date. This is where those come from.
 */

/** Column headings the C3PO key page uses, mapped to our fields. */
function keys_field_map(): array
{
    return [
        'assignee'    => 'HolderName',
        'employee'    => 'HolderName',
        'name'        => 'HolderName',
        'netid'       => 'HolderNetID',
        'net id'      => 'HolderNetID',
        'emp status'  => 'EmpStatus',
        'employee status' => 'EmpStatus',
        'survey'      => 'SurveyCode',
        'state'       => 'KeyState',
        'key state'   => 'KeyState',
        'building'    => 'BuildingText',
        'floor'       => 'FloorText',
        'room'        => 'RoomID',
        'key #'       => 'KeyCode',
        'key num'     => 'KeyCode',
        'key number'  => 'KeyCode',
        'key'         => 'KeyCode',
        'type'        => 'KeyType',
        'qty'         => 'Quantity',
        'quantity'    => 'Quantity',
        'assigned by' => 'AssignedBy',
        'comments'    => 'Notes',
        'comment'     => 'Notes',
        'assigned on' => 'IssuedOn',
        'assigned'    => 'IssuedOn',
    ];
}

/** Split a pasted blob into a table of cells. */
function keys_parse(string $blob): array
{
    $blob = trim($blob);
    if ($blob === '') {
        return [];
    }
    if (stripos($blob, '<tr') !== false || stripos($blob, '<table') !== false) {
        return keys_parse_html($blob);
    }
    // Three shapes arrive here. A paste straight out of the browser or
    // Excel is tab separated. A file saved from Excel is comma separated,
    // and has to be read as CSV rather than split on commas, because
    // "Angel, James" is one name and not two fields. Anything else is
    // fixed-width text, split on runs of spaces.
    $lines = preg_split('/\r\n|\r|\n/', $blob) ?: [];
    $first = '';
    foreach ($lines as $l) {
        if (trim($l) !== '') {
            $first = $l;
            break;
        }
    }
    $csv = !str_contains($first, "\t") && substr_count($first, ',') >= 5;

    $rows = [];
    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }
        if (str_contains($line, "\t")) {
            $cells = explode("\t", $line);
        } elseif ($csv) {
            $cells = str_getcsv($line);
        } else {
            $cells = preg_split('/\s{2,}/', trim($line));
        }
        $rows[] = array_map(fn($c) => trim((string)$c), $cells);
    }
    return $rows;
}

function keys_parse_html(string $html): array
{
    $rows = [];
    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    foreach ($doc->getElementsByTagName('tr') as $tr) {
        $cells = [];
        foreach ($tr->childNodes as $node) {
            if ($node instanceof DOMElement && in_array(strtolower($node->tagName), ['td', 'th'], true)) {
                $text = preg_replace('/\s+/', ' ', trim($node->textContent));
                $cells[] = $text;
            }
        }
        if ($cells) {
            $rows[] = $cells;
        }
    }
    return $rows;
}

/** Which columns of this row are recognisable headings? */
function keys_header_fields(array $row, array $map): array
{
    $out = [];
    foreach ($row as $c => $label) {
        $key = strtolower(trim(preg_replace('/\s+/', ' ', (string)$label)));
        if (isset($map[$key])) {
            $out[$c] = $map[$key];
        }
    }
    return $out;
}

/**
 * Two things the C3PO page does that a paste has to survive.
 *
 * The heading row repeats part way down every page, so it turns up again
 * and again in the middle of the data; taken literally it would file a
 * key holder called "Assignee".
 *
 * And the Actions column stacks its buttons, so each row arrives as
 * three: the real one ending in "Return Key", then "Mark Lost" and
 * "Edit" on lines of their own with every earlier cell empty. Those are
 * page furniture, not keys.
 */
const KEYS_ACTION_WORDS = ['return key', 'mark lost', 'edit', 'actions',
                           'return', 'lost', 'delete', 'view'];

function keys_row_kind(array $row, array $map, array $fields): string
{
    $values = array_map(fn($c) => trim((string)$c), $row);
    $nonEmpty = array_values(array_filter($values, fn($v) => $v !== '' && $v !== ','));
    if (!$nonEmpty) {
        return 'blank';
    }
    // A repeat of the heading row: several of its cells are column names.
    if (count(array_unique(keys_header_fields($row, $map))) >= 3) {
        return 'header';
    }
    // Nothing but an action button.
    $onlyActions = true;
    foreach ($nonEmpty as $v) {
        if (!in_array(strtolower($v), KEYS_ACTION_WORDS, true)) {
            $onlyActions = false;
            break;
        }
    }
    return $onlyActions ? 'action' : 'data';
}

/**
 * Load pasted key assignments. Returns a report; writes nothing when the
 * heading row cannot be recognised, because a silent mismatch would fill
 * the table with rubbish.
 */
function keys_import(string $blob, bool $replace = true): array
{
    $rows = keys_parse($blob);
    if (count($rows) < 2) {
        return ['error' => 'Nothing recognisable was pasted. Select the table on the page, '
                         . 'copy it, and paste the whole thing including its heading row.'];
    }

    // Find the heading row: the first row that maps at least three columns.
    $map = keys_field_map();
    $headerIndex = -1;
    $fields = [];
    foreach (array_slice($rows, 0, 5) as $i => $row) {
        $candidate = keys_header_fields($row, $map);
        if (count(array_unique($candidate)) >= 3) {
            $headerIndex = $i;
            $fields = $candidate;
            break;
        }
    }
    if ($headerIndex < 0) {
        return ['error' => 'The heading row was not recognised. Expected columns such as '
                         . 'Assignee, Survey, State, Building, Floor, Room, Key #, Type, '
                         . 'Assigned By, Assigned On.'];
    }

    $pdo = db('keys');
    // The clear-out and the reload are one unit of work. They were two:
    // the register was emptied, and if nothing then loaded - a paste that
    // was all headers and furniture, or an insert that failed partway -
    // the keys were simply gone, with nothing to put back. Now nothing is
    // visible to anyone else until the reload has proved itself, and a
    // paste with no usable rows leaves the register exactly as it was.
    $inTransaction = false;
    if ($replace) {
        $pdo->beginTransaction();
        $inTransaction = true;
        // A fresh register replaces what came from C3PO before it, but not
        // keys issued through this system since the last pull: those are
        // ours and C3PO has never heard of them.
        $pdo->exec('DELETE FROM gis.RoomKeys WHERE ISNULL(IsManaged, 0) = 0');
    }
    $stmt = $pdo->prepare(
        'INSERT INTO gis.RoomKeys (RoomCode, BldgNumber, RoomID, KeyCode, HolderName, HolderNetID,
            IssuedOn, Notes, KeyState, KeyType, Quantity, SurveyCode, EmpStatus, AssignedBy,
            BuildingText, FloorText, SourceLabel)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    // Building text on the key page is a name, not a number.
    $byName = [];
    foreach ($pdo->query('SELECT BldgNumber, Name FROM ref.Buildings') as $b) {
        $byName[keys_norm_name($b['Name'])] = $b['BldgNumber'];
    }
    foreach ($pdo->query('SELECT BldgNumber, AliasNorm FROM ref.BuildingAliases a
                          JOIN ref.Buildings b ON b.BuildingID = a.BuildingID') as $a) {
        $byName[keys_norm_name($a['AliasNorm'])] = $a['BldgNumber'];
    }

    $loaded = 0;
    $unresolvedBuilding = 0;
    $linkedRooms = 0;
    $ambiguous = 0;
    $inferredBuilding = 0;
    $skipped = ['header' => 0, 'action' => 0, 'blank' => 0, 'furniture' => 0];
    $label = 'pasted ' . date('Y-m-d');

    foreach (array_slice($rows, $headerIndex + 1) as $row) {
        $kind = keys_row_kind($row, $map, $fields);
        if ($kind !== 'data') {
            $skipped[$kind]++;
            continue;
        }
        $rec = array_fill_keys(['HolderName', 'HolderNetID', 'EmpStatus', 'SurveyCode', 'KeyState',
                                'BuildingText', 'FloorText', 'RoomID', 'KeyCode', 'KeyType',
                                'Quantity', 'AssignedBy', 'Notes', 'IssuedOn'], null);
        foreach ($fields as $c => $field) {
            $v = trim((string)($row[$c] ?? ''));
            if ($v !== '' && $v !== ',') {
                $rec[$field] = $v;
            }
        }
        // Every real assignment names a key or a room. A stray line that
        // only carries a name is the printed date or a page footer that
        // came along with the selection.
        if ($rec['KeyCode'] === null && $rec['RoomID'] === null) {
            $skipped['furniture']++;
            continue;
        }
        $bldg = $byName[keys_norm_name((string)$rec['BuildingText'])] ?? null;
        if ($rec['BuildingText'] !== null && $bldg === null) {
            $unresolvedBuilding++;
        }
        // Some rows name no building, only a reader: "IC2", "IC2 Library
        // 208". Across the rows that carry both, IC1 is always ISWS
        // Building 01 and IC11 always Building 11, so the reader names the
        // building. The guess is only kept if the plans agree - either the
        // reader is the whole of the entry, or the room beside it is a
        // real room of that building.
        if ($bldg === null && preg_match('/^IC\s*(\d{1,2})\b/i', (string)$rec['RoomID'], $m)) {
            $guess = $byName[keys_norm_name('ISWS Building ' . str_pad($m[1], 2, '0', STR_PAD_LEFT))] ?? null;
            if ($guess !== null) {
                [$c2] = keys_resolve_room($guess, $rec['RoomID'], $rec['FloorText']);
                if ($c2 !== null || rtrim((string)$rec['RoomID']) === 'IC' . $m[1]) {
                    $bldg = $guess;
                    $rec['BuildingText'] = 'ISWS Building ' . str_pad($m[1], 2, '0', STR_PAD_LEFT);
                    $inferredBuilding++;
                }
            }
        }
        [$code, $floor, $how] = keys_resolve_room($bldg, $rec['RoomID'], $rec['FloorText']);
        if ($code !== null) {
            $linkedRooms++;
            // The register leaves the floor blank on most rows; the plans
            // know it, so it is written back rather than left empty.
            if ($floor !== null && (string)$rec['FloorText'] === '') {
                $rec['FloorText'] = $floor;
            }
        } elseif ($how === 'ambiguous') {
            $ambiguous++;
        }
        $stmt->execute([
            $code, $bldg, $rec['RoomID'], $rec['KeyCode'], $rec['HolderName'], $rec['HolderNetID'],
            $rec['IssuedOn'], $rec['Notes'], $rec['KeyState'], $rec['KeyType'],
            is_numeric($rec['Quantity']) ? (int)$rec['Quantity'] : null,
            $rec['SurveyCode'], $rec['EmpStatus'], $rec['AssignedBy'],
            $rec['BuildingText'], $rec['FloorText'], $label,
        ]);
        $loaded++;
    }

    if ($inTransaction) {
        if ($loaded === 0) {
            // Nothing usable arrived. Emptying the register on the strength
            // of a bad paste is the one outcome that cannot be undone.
            $pdo->rollBack();
            return [
                'loaded' => 0,
                'linked' => 0,
                'unresolved_building' => $unresolvedBuilding,
                'ambiguous_floor' => $ambiguous,
                'building_from_reader' => $inferredBuilding,
                'skipped' => $skipped,
                'columns' => array_values(array_unique($fields)),
                'error' => 'Nothing was loaded, so the register was left as it was. '
                         . 'Check that the pasted rows carry key data rather than headings.',
            ];
        }
        $pdo->commit();
    }

    return [
        'loaded' => $loaded,
        'linked' => $linkedRooms,
        'unresolved_building' => $unresolvedBuilding,
        'ambiguous_floor' => $ambiguous,
        'building_from_reader' => $inferredBuilding,
        'skipped' => $skipped,
        'columns' => array_values(array_unique($fields)),
    ];
}

/**
 * A key's room, pinned to a floor from the plans.
 *
 * The register almost never fills its Floor column - fourteen per cent of
 * rows - but the floor plans know where every room is, so it can be read
 * back rather than asked for. Most of the estate makes this easy: the
 * eleven ISWS buildings are single storey, so a room number there can
 * only be on floor 01.
 *
 * Where a number genuinely appears on two floors of one building, the
 * plans cannot choose and neither will this: the register's own Floor
 * column decides if it has one, and otherwise the key is left attached to
 * the building only. Attaching it to the wrong floor would be worse than
 * leaving it, because the map would then show it somewhere it is not.
 *
 * Returns [RoomCode|null, FloorCode|null, outcome] where outcome is one of
 * matched, ambiguous, unknown.
 */
function keys_room_hits(string $bldg, string $room): array
{
    static $cache = [];
    $ck = $bldg . '|' . $room;
    if (!array_key_exists($ck, $cache)) {
        try {
            // ref.Rooms is the copy published into every database and
            // carries the same room code and floor, so this stays local.
            $stmt = db('keys')->prepare(
                'SELECT RoomCode, FloorCode FROM ref.Rooms
                 WHERE BldgNumber = ? AND LTRIM(RTRIM(RoomID)) = ?');
            $stmt->execute([$bldg, $room]);
            $cache[$ck] = $stmt->fetchAll();
        } catch (Throwable $e) {
            $cache[$ck] = [];
        }
    }
    return $cache[$ck];
}

function keys_resolve_room(?string $bldg, ?string $roomText, ?string $floorText): array
{
    $bldg = trim((string)$bldg);
    $room = room_normal($roomText);
    if ($bldg === '' || $room === '') {
        return [null, null, 'unknown'];
    }
    $hits = keys_room_hits($bldg, $room);

    // Some entries name the door rather than the room ("IC11 Office 1138")
    // and some name every room one key opens ("193, 194, 195, 196, 197").
    // Both are handled by trying each word left to right and keeping the
    // first that is actually a room of this building: the door prefix
    // "IC11" matches nothing and is passed over, while a list attaches to
    // the room named first. Nothing is lost either way - the original
    // text is kept in RoomID, so a key that opens five rooms still says
    // so; RoomCode is only the room it hangs off on the map.
    // A word may also carry the reader prefix welded on: IC1100 is the
    // card reader for room 1100, while IC11 is the reader for the whole
    // of building 11. Stripping the letters and asking the plans settles
    // which - 1100 is a room of that building and 11 is not.
    if (!$hits) {
        $words = preg_split('/[^A-Za-z0-9]+/', (string)$roomText, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($words as $w) {
            foreach ([$w, preg_replace('/^[A-Za-z]+/', '', $w)] as $try) {
                if ($try === '' || $try === null) {
                    continue;
                }
                $got = keys_room_hits($bldg, room_normal($try));
                if ($got) {
                    $hits = $got;
                    break 2;
                }
            }
        }
    }
    if (!$hits) {
        return [null, null, 'unknown'];
    }
    if (count($hits) === 1) {
        return [(string)$hits[0]['RoomCode'], (string)$hits[0]['FloorCode'], 'matched'];
    }
    // Two or more floors carry this number. Let the register break the tie.
    $want = strtoupper(trim((string)$floorText));
    if ($want !== '') {
        foreach ($hits as $h) {
            $f = strtoupper((string)$h['FloorCode']);
            if ($f === $want || ltrim($f, '0') === ltrim($want, '0')) {
                return [(string)$h['RoomCode'], (string)$h['FloorCode'], 'matched'];
            }
        }
    }
    return [null, null, 'ambiguous'];
}

function keys_norm_name(string $s): string
{
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}
