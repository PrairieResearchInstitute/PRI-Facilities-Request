<?php
declare(strict_types=1);

require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/rooms.php';

/**
 * The administrative forms behind the map: key assignment, equipment
 * ingest and movement, and room assignment. Each writes an event so the
 * record explains itself later, and each keeps the room connector
 * current so the map stays truthful.
 */

/** Options for a room picker, limited to one building. */
function form_rooms(string $bldg): array
{
    if ($bldg === '') {
        return [];
    }
    $stmt = db('space')->prepare(
        'SELECT RoomCode, FloorCode, RoomID, RoomName FROM gis.Rooms
         WHERE BldgNumber = ? ORDER BY FloorCode, RoomID');
    $stmt->execute([$bldg]);
    return $stmt->fetchAll();
}

function form_buildings(): array
{
    return db('space')->query(
        'SELECT b.BldgNumber, b.Name,
                (SELECT COUNT(*) FROM gis.Rooms r WHERE r.BldgNumber = b.BldgNumber) AS Rooms
         FROM ref.Buildings b WHERE b.IsActive = 1 ORDER BY b.SortKey'
    )->fetchAll();
}

function form_people(): array
{
    // Everyone C3PO knows about, so a form offers real names.
    return db('space')->query(
        'SELECT DISTINCT NetID, PersonName FROM gis.Occupants
         WHERE NetID IS NOT NULL AND PersonName IS NOT NULL
         UNION
         SELECT DISTINCT NetID, PersonName FROM gis.Roster
         WHERE NetID IS NOT NULL AND PersonName IS NOT NULL'
    )->fetchAll();
}

// =====================================================================
// Key assignment
// =====================================================================

const KEY_STATES = ['Active', 'Returned', 'Lost', 'Void'];
const KEY_TYPES = ['Key', 'iCard', 'iCard (UIUC F&S system)', 'Padlock', 'Cabinet key', 'Other'];
const EMP_STATUSES = ['Active', 'Terminated', 'Retired', 'Student', 'Visitor'];

function keys_issue(array $in, string $byNetID): string
{
    $pdo = db('keys');
    $bldg = trim((string)($in['bldg'] ?? ''));
    $roomText = trim((string)($in['room'] ?? ''));
    $code = room_resolve('work', $bldg, $roomText);

    $bname = null;
    if ($bldg !== '') {
        $st = $pdo->prepare('SELECT Name FROM ref.Buildings WHERE BldgNumber = ?');
        $st->execute([$bldg]);
        $bname = $st->fetchColumn() ?: null;
    }

    $pdo->prepare(
        'INSERT INTO gis.RoomKeys (RoomCode, BldgNumber, RoomID, KeyCode, HolderName, HolderNetID,
            IssuedOn, Notes, KeyState, KeyType, Quantity, SurveyCode, EmpStatus, AssignedBy,
            BuildingText, FloorText, SourceLabel, IsManaged, UpdatedAt, UpdatedByNetID)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, SYSDATETIME(), ?)'
    )->execute([
        $code, $bldg ?: null, $roomText ?: null, trim((string)$in['key_code']),
        trim((string)$in['holder_name']) ?: null, trim((string)$in['holder_netid']) ?: null,
        trim((string)($in['issued_on'] ?? '')) ?: date('M j, Y'),
        trim((string)($in['notes'] ?? '')) ?: null,
        'Active', trim((string)($in['key_type'] ?? 'Key')),
        is_numeric($in['qty'] ?? null) ? (int)$in['qty'] : 1,
        trim((string)($in['survey'] ?? '')) ?: null,
        trim((string)($in['emp_status'] ?? '')) ?: null,
        trim((string)($in['assigned_by'] ?? '')) ?: $byNetID,
        $bname, trim((string)($in['floor'] ?? '')) ?: null,
        'issued in app', $byNetID,
    ]);
    $id = (int)$pdo->lastInsertId();
    keys_event($id, 'issued', null, 'Active',
        'Key ' . $in['key_code'] . ' to ' . ($in['holder_name'] ?: $in['holder_netid']), $byNetID);

    return 'Key ' . $in['key_code'] . ' issued to '
         . ($in['holder_name'] ?: $in['holder_netid'])
         . ($code ? ' and linked to room ' . $code : ($roomText ? ' (room not mapped)' : ''));
}

function keys_event(int $roomKeyID, string $event, ?string $from, ?string $to,
                    string $detail, string $byNetID): void
{
    db('keys')->prepare(
        'INSERT INTO gis.KeyEvents (RoomKeyID, EventCode, KeyStateFrom, KeyStateTo, Detail, ByNetID)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$roomKeyID, $event, $from, $to, $detail, $byNetID]);
}

/** Return, lose, void, or re-activate one key assignment. */
function keys_set_state(int $id, string $state, string $note, string $byNetID): string
{
    $pdo = db('keys');
    $st = $pdo->prepare('SELECT KeyState, KeyCode, HolderName FROM gis.RoomKeys WHERE RoomKeyID = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) {
        return 'That key assignment no longer exists.';
    }
    $when = date('M j, Y');
    $sets = ['KeyState = ?', 'UpdatedAt = SYSDATETIME()', 'UpdatedByNetID = ?'];
    $vals = [$state, $byNetID];
    if ($state === 'Returned') {
        $sets[] = 'ReturnedOn = ?';
        $vals[] = $when;
    } elseif ($state === 'Lost') {
        $sets[] = 'LostOn = ?';
        $vals[] = $when;
    }
    if ($note !== '') {
        $sets[] = 'Notes = ?';
        $vals[] = $note;
    }
    $vals[] = $id;
    $pdo->prepare('UPDATE gis.RoomKeys SET ' . implode(', ', $sets) . ' WHERE RoomKeyID = ?')
        ->execute($vals);

    $event = ['Returned' => 'returned', 'Lost' => 'lost', 'Void' => 'voided', 'Active' => 'edited'][$state] ?? 'edited';
    keys_event($id, $event, (string)$row['KeyState'], $state, $note ?: '', $byNetID);

    return 'Key ' . $row['KeyCode'] . ' marked ' . strtolower($state)
         . ($row['HolderName'] ? ' for ' . $row['HolderName'] : '') . '.';
}

function keys_edit(int $id, array $in, string $byNetID): string
{
    $pdo = db('keys');
    $bldg = trim((string)($in['bldg'] ?? ''));
    $roomText = trim((string)($in['room'] ?? ''));
    $code = room_resolve('work', $bldg, $roomText);
    $pdo->prepare(
        'UPDATE gis.RoomKeys SET KeyCode = ?, KeyType = ?, Quantity = ?, HolderName = ?,
            HolderNetID = ?, EmpStatus = ?, SurveyCode = ?, BldgNumber = ?, RoomID = ?,
            FloorText = ?, RoomCode = ?, Notes = ?, IssuedOn = ?, KeyState = ?,
            UpdatedAt = SYSDATETIME(), UpdatedByNetID = ?
         WHERE RoomKeyID = ?'
    )->execute([
        trim((string)$in['key_code']), trim((string)($in['key_type'] ?? 'Key')),
        is_numeric($in['qty'] ?? null) ? (int)$in['qty'] : 1,
        trim((string)($in['holder_name'] ?? '')) ?: null,
        trim((string)($in['holder_netid'] ?? '')) ?: null,
        trim((string)($in['emp_status'] ?? '')) ?: null,
        trim((string)($in['survey'] ?? '')) ?: null,
        $bldg ?: null, $roomText ?: null, trim((string)($in['floor'] ?? '')) ?: null,
        $code, trim((string)($in['notes'] ?? '')) ?: null,
        trim((string)($in['issued_on'] ?? '')) ?: null,
        in_array($in['key_state'] ?? '', KEY_STATES, true) ? $in['key_state'] : 'Active',
        $byNetID, $id,
    ]);
    keys_event($id, 'edited', null, (string)($in['key_state'] ?? ''), 'Details edited', $byNetID);
    return 'Key assignment updated.';
}

// =====================================================================
// Equipment
// =====================================================================

function equipment_find_by_tag(string $tag): ?array
{
    $st = db('equipment')->prepare('SELECT TOP 1 * FROM gis.Inventory WHERE PropertyTag = ?');
    $st->execute([trim($tag)]);
    return $st->fetch() ?: null;
}

function equipment_ingest(array $in, string $byNetID): string
{
    $pdo = db('equipment');
    $tag = trim((string)($in['tag'] ?? ''));
    if ($tag !== '' && equipment_find_by_tag($tag)) {
        return 'Property tag ' . $tag . ' already exists. Use transfer or edit instead.';
    }
    $bldg = trim((string)($in['bldg'] ?? ''));
    $roomText = trim((string)($in['room'] ?? ''));
    $code = room_resolve('work', $bldg, $roomText);
    $money = fn($v) => is_numeric(preg_replace('/[^0-9.\-]/', '', (string)$v))
        ? (float)preg_replace('/[^0-9.\-]/', '', (string)$v) : null;

    $pdo->prepare(
        'INSERT INTO gis.Inventory (RoomCode, BldgNumber, RoomID, PropertyTag, Description, Model,
            SerialNumber, Person, AcquiredOn, Cost, DepreciatedValue, FundingSource, Status,
            Condition, SurveyCode, IsManaged, UpdatedAt, UpdatedByNetID)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'active\', ?, ?, 1, SYSDATETIME(), ?)'
    )->execute([
        $code, $bldg ?: null, $roomText ?: null, $tag ?: null,
        trim((string)$in['description']), trim((string)($in['model'] ?? '')) ?: null,
        trim((string)($in['serial'] ?? '')) ?: null, trim((string)($in['person'] ?? '')) ?: null,
        trim((string)($in['acquired'] ?? '')) ?: date('M j, Y'),
        $money($in['cost'] ?? null), $money($in['value'] ?? null),
        trim((string)($in['funding'] ?? '')) ?: null,
        trim((string)($in['condition'] ?? '')) ?: null,
        trim((string)($in['survey'] ?? '')) ?: null, $byNetID,
    ]);
    $id = (int)$pdo->lastInsertId();
    equipment_event($id, $tag, 'ingested', null, $code, null, $bldg,
        trim((string)$in['description']), null, $byNetID);
    return 'Equipment added' . ($tag ? ' as ' . $tag : '')
         . ($code ? ' in room ' . $code : ($bldg ? ' in building ' . $bldg : '')) . '.';
}

function equipment_event(int $invID, ?string $tag, string $event, ?string $fromCode, ?string $toCode,
                         ?string $fromBldg, ?string $toBldg, string $detail,
                         ?string $moveTicket, string $byNetID): void
{
    db('equipment')->prepare(
        'INSERT INTO gis.InventoryEvents (InventoryID, PropertyTag, EventCode, FromRoomCode,
            ToRoomCode, FromBldgNumber, ToBldgNumber, Detail, MoveTicket, ByNetID)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$invID, $tag, $event, $fromCode, $toCode, $fromBldg, $toBldg,
                $detail, $moveTicket, $byNetID]);
}

/**
 * Move one item to another room. This is what keeps the room tables
 * honest: the item's location changes here, not only on the paperwork.
 */
function equipment_transfer(int $invID, string $toBldg, string $toRoom, string $person,
                            string $note, string $byNetID, ?string $moveTicket = null): string
{
    $pdo = db('equipment');
    $st = $pdo->prepare('SELECT * FROM gis.Inventory WHERE InventoryID = ?');
    $st->execute([$invID]);
    $item = $st->fetch();
    if (!$item) {
        return 'That item no longer exists.';
    }
    $toCode = room_resolve('work', $toBldg, $toRoom);
    $pdo->prepare(
        'UPDATE gis.Inventory SET BldgNumber = ?, RoomID = ?, RoomCode = ?, BannerRoom = NULL,
            Person = COALESCE(NULLIF(?, \'\'), Person), Status = \'active\',
            LastMovedAt = SYSDATETIME(), UpdatedAt = SYSDATETIME(), UpdatedByNetID = ?
         WHERE InventoryID = ?'
    )->execute([$toBldg ?: null, $toRoom ?: null, $toCode, $person, $byNetID, $invID]);

    equipment_event($invID, $item['PropertyTag'], 'transferred', $item['RoomCode'], $toCode,
        $item['BldgNumber'], $toBldg ?: null,
        trim($note) ?: 'Moved to ' . ($toCode ?: ($toBldg . ' ' . $toRoom)), $moveTicket, $byNetID);

    return ($item['PropertyTag'] ?: 'Item') . ' moved to '
         . ($toCode ?: trim($toBldg . ' ' . $toRoom))
         . ($toCode ? '' : ' (room not mapped, recorded as typed)') . '.';
}

/**
 * Hand responsibility for an item to somebody else. The item does not
 * move; only who answers for it changes. Rooms change through a move
 * request, so the property record and the paperwork stay in step.
 */
function equipment_reassign(int $invID, string $person, string $note, string $byNetID): string
{
    $person = trim($person);
    if ($person === '') {
        return 'Name the person taking responsibility.';
    }
    $pdo = db('equipment');
    $st = $pdo->prepare('SELECT PropertyTag, Person, RoomCode, BldgNumber FROM gis.Inventory
                         WHERE InventoryID = ?');
    $st->execute([$invID]);
    $item = $st->fetch();
    if (!$item) {
        return 'That item no longer exists.';
    }
    $pdo->prepare('UPDATE gis.Inventory SET Person = ?, UpdatedAt = SYSDATETIME(),
                   UpdatedByNetID = ? WHERE InventoryID = ?')
        ->execute([$person, $byNetID, $invID]);
    equipment_event($invID, $item['PropertyTag'], 'edited', $item['RoomCode'], $item['RoomCode'],
        $item['BldgNumber'], $item['BldgNumber'],
        'Responsibility moved from ' . ((string)$item['Person'] ?: 'nobody recorded')
        . ' to ' . $person . ($note !== '' ? ': ' . $note : ''), null, $byNetID);
    return ($item['PropertyTag'] ?: 'Item') . ' is now the responsibility of ' . $person . '.';
}

function equipment_remove(int $invID, string $status, string $reason, string $byNetID): string
{
    $pdo = db('equipment');
    $st = $pdo->prepare('SELECT PropertyTag, RoomCode, BldgNumber FROM gis.Inventory WHERE InventoryID = ?');
    $st->execute([$invID]);
    $item = $st->fetch();
    if (!$item) {
        return 'That item no longer exists.';
    }
    $pdo->prepare(
        'UPDATE gis.Inventory SET Status = ?, RemovedOn = ?, RemovalReason = ?,
            UpdatedAt = SYSDATETIME(), UpdatedByNetID = ? WHERE InventoryID = ?'
    )->execute([$status, date('M j, Y'), $reason ?: null, $byNetID, $invID]);
    equipment_event($invID, $item['PropertyTag'], 'removed', $item['RoomCode'], null,
        $item['BldgNumber'], null, $status . ($reason ? ': ' . $reason : ''), null, $byNetID);
    return ($item['PropertyTag'] ?: 'Item') . ' recorded as ' . $status . '.';
}

/**
 * Apply a completed move request to the equipment records: every item
 * carrying a property tag moves to its destination room, and items sent
 * to trash or surplus are retired. This is the link the specification
 * asks for between a move order and the room tables.
 */
function equipment_apply_move(int $moveRequestID, string $byNetID): array
{
    $mv = db('move');
    $st = $mv->prepare(
        'SELECT m.TicketNumber, i.* FROM mv.MoveItems i
         JOIN mv.MoveRequests m ON m.MoveRequestID = i.MoveRequestID
         WHERE i.MoveRequestID = ?');
    $st->execute([$moveRequestID]);
    $items = $st->fetchAll();

    $moved = $retired = $unmatched = 0;
    foreach ($items as $it) {
        $tag = trim((string)($it['PTag'] ?? '')) ?: trim((string)($it['PRITag'] ?? ''));
        if ($tag === '') {
            $unmatched++;
            continue;
        }
        $item = equipment_find_by_tag($tag);
        if (!$item) {
            $unmatched++;
            continue;
        }
        if (in_array($it['MoveType'], ['trash', 'surplus'], true)) {
            equipment_remove((int)$item['InventoryID'],
                $it['MoveType'] === 'trash' ? 'trashed' : 'surplus',
                'Move request ' . $it['TicketNumber'], $byNetID);
            $retired++;
        } else {
            equipment_transfer((int)$item['InventoryID'], (string)$it['ToBldgNumber'],
                (string)$it['ToRoom'], (string)($it['NewCustodian'] ?? ''),
                'Move request ' . $it['TicketNumber'], $byNetID, (string)$it['TicketNumber']);
            $moved++;
        }
    }
    return ['moved' => $moved, 'retired' => $retired, 'unmatched' => $unmatched,
            'items' => count($items)];
}

// =====================================================================
// Room assignment
// =====================================================================

function room_assign(string $roomCode, array $in, string $byNetID): string
{
    $pdo = db('space');
    $st = $pdo->prepare('SELECT COUNT(*) FROM gis.RoomResponsibility WHERE RoomCode = ?');
    $st->execute([$roomCode]);
    $exists = (int)$st->fetchColumn() > 0;

    $fields = [
        'SurveyCode' => trim((string)($in['survey'] ?? '')) ?: null,
        'PrincipalInvestigator' => trim((string)($in['pi_name'] ?? '')) ?: null,
        'PrincipalInvestigatorNetID' => trim((string)($in['pi_netid'] ?? '')) ?: null,
        'SafetyContact' => trim((string)($in['safety_name'] ?? '')) ?: null,
        'SafetyContactNetID' => trim((string)($in['safety_netid'] ?? '')) ?: null,
        'PrimaryContactNetID' => trim((string)($in['primary_netid'] ?? '')) ?: null,
        'RoomUseOverride' => trim((string)($in['use_override'] ?? '')) ?: null,
        'AccessRestriction' => trim((string)($in['access'] ?? '')) ?: null,
        'Notes' => trim((string)($in['notes'] ?? '')) ?: null,
    ];
    if ($exists) {
        $sets = [];
        foreach (array_keys($fields) as $c) {
            $sets[] = "$c = ?";
        }
        $sets[] = 'UpdatedAt = SYSDATETIME()';
        $sets[] = 'UpdatedByNetID = ?';
        $vals = array_values($fields);
        $vals[] = $byNetID;
        $vals[] = $roomCode;
        $pdo->prepare('UPDATE gis.RoomResponsibility SET ' . implode(', ', $sets) . ' WHERE RoomCode = ?')
            ->execute($vals);
    } else {
        $cols = array_keys($fields);
        $pdo->prepare('INSERT INTO gis.RoomResponsibility (RoomCode, ' . implode(', ', $cols)
            . ', UpdatedAt, UpdatedByNetID) VALUES (?, ' . implode(', ', array_fill(0, count($cols), '?'))
            . ', SYSDATETIME(), ?)')
            ->execute(array_merge([$roomCode], array_values($fields), [$byNetID]));
    }
    room_assign_event($roomCode, 'responsibility',
        'Survey ' . ($fields['SurveyCode'] ?? 'unset')
        . ', PI ' . ($fields['PrincipalInvestigator'] ?? 'unset')
        . ', safety ' . ($fields['SafetyContact'] ?? 'unset'), $byNetID);
    return 'Room ' . $roomCode . ' assignment saved.';
}

function room_assign_event(string $roomCode, string $event, string $detail, string $byNetID): void
{
    db('space')->prepare(
        'INSERT INTO gis.RoomAssignmentEvents (RoomCode, EventCode, Detail, ByNetID)
         VALUES (?, ?, ?, ?)'
    )->execute([$roomCode, $event, $detail, $byNetID]);
}

function room_occupant_add(string $roomCode, array $in, string $byNetID): string
{
    $pdo = db('space');
    $st = $pdo->prepare('SELECT BldgNumber, FloorCode, RoomID FROM gis.Rooms WHERE RoomCode = ?');
    $st->execute([$roomCode]);
    $room = $st->fetch();
    if (!$room) {
        return 'That room is not mapped.';
    }
    $pdo->prepare(
        'INSERT INTO gis.Occupants (RoomCode, BldgNumber, FloorCode, RoomID, PersonName, NetID,
            Org, EmpType, PrimaryOffice, Notes, IsManaged, AssignedOn, ByNetID)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)'
    )->execute([
        $roomCode, $room['BldgNumber'], $room['FloorCode'], $room['RoomID'],
        trim((string)$in['person_name']), trim((string)($in['person_netid'] ?? '')) ?: null,
        trim((string)($in['org'] ?? '')) ?: null, trim((string)($in['emp_type'] ?? '')) ?: null,
        !empty($in['primary']) ? 'Y' : 'N', trim((string)($in['notes'] ?? '')) ?: null,
        date('M j, Y'), $byNetID,
    ]);
    room_assign_event($roomCode, 'occupant_added', trim((string)$in['person_name']), $byNetID);
    return trim((string)$in['person_name']) . ' assigned to ' . $roomCode . '.';
}

function room_occupant_remove(int $occupantID, string $byNetID): string
{
    $pdo = db('space');
    $st = $pdo->prepare('SELECT RoomCode, PersonName, IsManaged FROM gis.Occupants WHERE OccupantID = ?');
    $st->execute([$occupantID]);
    $row = $st->fetch();
    if (!$row) {
        return 'That assignment no longer exists.';
    }
    // A row from the C3PO extract is ended rather than deleted, so the
    // next extract does not silently resurrect it without explanation.
    $pdo->prepare('UPDATE gis.Occupants SET EndedOn = ?, IsManaged = 1, ByNetID = ?
                   WHERE OccupantID = ?')
        ->execute([date('M j, Y'), $byNetID, $occupantID]);
    room_assign_event((string)$row['RoomCode'], 'occupant_removed',
        (string)$row['PersonName'], $byNetID);
    return $row['PersonName'] . ' removed from ' . $row['RoomCode'] . '.';
}
