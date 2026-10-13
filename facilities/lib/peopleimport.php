<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csvio.php';

/**
 * The people directory, loaded from an export anybody at PRI can pull
 * for themselves: the employee file, with a name, an email, the primary
 * organisation, and a room.
 *
 * The email carries the NetID, which is the thing the old form's name
 * lookup never gave back. That single fact is what lets a request name a
 * person the system can actually reach.
 */

const PEOPLE_FIELD_MAP = [
    'employee'     => 'DisplayName',
    'name'         => 'DisplayName',
    'full name'    => 'DisplayName',
    'display name' => 'DisplayName',
    'email'        => 'Email',
    'email address'=> 'Email',
    'netid'        => 'NetID',
    'net id'       => 'NetID',
    'uin'          => 'UIN',
    'primary org'  => 'Org',
    'org'          => 'Org',
    'organization' => 'Org',
    'department'   => 'Org',
    'survey'       => 'SurveyCode',
    'unit'         => 'SurveyCode',
    'room'         => 'RoomText',
    'position'     => 'Position',
    'title'        => 'Position',
    'position type'=> 'Position',
];

/** "ISGS.ISGS_SubsurfEnergy_" or "INHS/Fish Crus (cataylor)" -> ISGS, INHS */
function people_survey_from_org(string $org): ?string
{
    return preg_match('/^\s*(INHS|ISGS|ISWS|ISAS|ISTC|OED)\b/i', $org, $m)
        ? strtoupper($m[1]) : null;
}

/** NetID from an email, since that is how the export identifies people. */
function people_netid_from_email(string $email): ?string
{
    $email = trim(strtolower($email));
    if ($email === '' || !str_contains($email, '@')) {
        return null;
    }
    $local = substr($email, 0, strpos($email, '@'));
    return preg_match('/^[a-z0-9._-]{2,64}$/', $local) ? $local : null;
}

function people_parse(string $blob): array
{
    $blob = trim($blob);
    if ($blob === '') {
        return ['rows' => [], 'error' => 'Nothing was pasted.'];
    }

    $grid = [];
    if (stripos($blob, '<tr') !== false) {
        $prev = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $blob);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        foreach ($doc->getElementsByTagName('tr') as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $node) {
                if ($node instanceof DOMElement
                    && in_array(strtolower($node->tagName), ['td', 'th'], true)) {
                    $cells[] = preg_replace('/\s+/', ' ', trim($node->textContent));
                }
            }
            if ($cells) {
                $grid[] = $cells;
            }
        }
    } else {
        foreach (preg_split('/\r\n|\r|\n/', $blob) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $grid[] = str_contains($line, "\t")
                ? array_map('trim', explode("\t", $line))
                : [trim($line)];
        }
    }
    if (count($grid) < 2) {
        return ['rows' => [], 'error' => 'That does not look like a table of people.'];
    }

    $headerAt = -1;
    $fields = [];
    foreach (array_slice($grid, 0, 4) as $i => $row) {
        $candidate = [];
        foreach ($row as $c => $label) {
            $key = strtolower(trim(preg_replace('/\s+/', ' ', (string)$label)));
            if (isset(PEOPLE_FIELD_MAP[$key])) {
                $candidate[$c] = PEOPLE_FIELD_MAP[$key];
            }
        }
        $has = array_values($candidate);
        if (in_array('DisplayName', $has, true)
            && (in_array('Email', $has, true) || in_array('NetID', $has, true))) {
            $headerAt = $i;
            $fields = $candidate;
            break;
        }
    }
    if ($headerAt < 0) {
        return ['rows' => [], 'error' => 'The heading row was not recognised. It needs a name '
            . 'column (Employee or Name) and either Email or NetID.'];
    }

    $rows = [];
    foreach (array_slice($grid, $headerAt + 1) as $row) {
        $rec = [];
        foreach ($fields as $c => $field) {
            $v = trim((string)($row[$c] ?? ''));
            if ($v !== '') {
                $rec[$field] = $v;
            }
        }
        $netid = $rec['NetID'] ?? null;
        if ($netid === null && !empty($rec['Email'])) {
            $netid = people_netid_from_email((string)$rec['Email']);
        }
        if ($netid === null || empty($rec['DisplayName'])) {
            continue;
        }
        $rec['NetID'] = strtolower((string)$netid);
        if (empty($rec['SurveyCode']) && !empty($rec['Org'])) {
            $rec['SurveyCode'] = people_survey_from_org((string)$rec['Org']);
        }
        $rows[] = $rec;
    }
    return ['rows' => $rows, 'columns' => array_values(array_unique($fields))];
}

/**
 * Load parsed people. Matched on NetID, so a later export refreshes
 * rather than duplicates, and a blank field never overwrites something
 * already known.
 */
function people_import(string $blob, string $byNetID): array
{
    $parsed = people_parse($blob);
    if (!empty($parsed['error'])) {
        return ['error' => $parsed['error']];
    }
    if (!$parsed['rows']) {
        return ['error' => 'No usable rows: every row needs a name and an email or NetID.'];
    }

    $pdo = db('work');
    $chk = $pdo->prepare('SELECT COUNT(*) FROM ref.People WHERE NetID = ?');
    $ins = $pdo->prepare('INSERT INTO ref.People (NetID, DisplayName, Email, UIN, SurveyCode,
                          Org, Position, SourceLabel, UpdatedAt)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, SYSDATETIME())');
    $label = 'employee export ' . date('Y-m-d');
    $added = $updated = 0;

    foreach ($parsed['rows'] as $r) {
        $chk->execute([$r['NetID']]);
        if ((int)$chk->fetchColumn() === 0) {
            $ins->execute([$r['NetID'], $r['DisplayName'],
                           $r['Email'] ?? ($r['NetID'] . '@illinois.edu'),
                           $r['UIN'] ?? null, $r['SurveyCode'] ?? null, $r['Org'] ?? null,
                           $r['Position'] ?? null, $label]);
            $added++;
            continue;
        }
        $sets = ['DisplayName = ?'];
        $vals = [$r['DisplayName']];
        foreach (['Email', 'UIN', 'SurveyCode', 'Org', 'Position'] as $f) {
            if (!empty($r[$f])) {
                $sets[] = "$f = ?";
                $vals[] = $r[$f];
            }
        }
        $sets[] = 'SourceLabel = ?';
        $vals[] = $label;
        $sets[] = 'UpdatedAt = SYSDATETIME()';
        $sets[] = 'IsActive = 1';
        $vals[] = $r['NetID'];
        $pdo->prepare('UPDATE ref.People SET ' . implode(', ', $sets) . ' WHERE NetID = ?')
            ->execute($vals);
        $updated++;
    }

    $total = (int)$pdo->query('SELECT COUNT(*) FROM ref.People WHERE IsActive = 1')->fetchColumn();
    return ['added' => $added, 'updated' => $updated, 'total' => $total,
            'columns' => $parsed['columns']];
}

function people_import_file(string $tmpPath, string $byNetID): array
{
    $fh = @fopen($tmpPath, 'r');
    if (!$fh) {
        return ['error' => 'That file could not be read.'];
    }
    $lines = [];
    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) === 1 && trim((string)$row[0]) === '') {
            continue;
        }
        $lines[] = implode("\t", array_map(fn($c) => str_replace("\t", ' ', (string)$c), $row));
    }
    fclose($fh);
    return people_import(implode("\n", $lines), $byNetID);
}
