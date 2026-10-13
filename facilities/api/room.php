<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/ui.php';

/**
 * Everything known about one room, gathered across the space data and the
 * three request databases. Room numbers in requests are free text, so the
 * match is on a normalised form of the room identifier within the
 * building rather than on an identifier that requests do not carry.
 */

header('Content-Type: application/json');
if (!app_installed()) {
    http_response_code(503);
    echo json_encode(['error' => 'not installed']);
    exit;
}
current_user();
require_role('coordinator');

/** Comparable room key: upper case, no separators, no leading zeros. */
function room_key(?string $s): string
{
    $t = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$s));
    $t = ltrim($t, '0');
    return $t === '' ? '0' : $t;
}

$code = (string)($_GET['code'] ?? '');
/* Everything known about one room, and that knowledge is spread across
   the system by design: the room itself and who answers for it are the
   estate, the work orders are Work, the property is the inventory, the
   keys are the key register. One handle each, joined on the room code. */
$space = db('space');
$work = db('work');
$equipment = db('equipment');

$stmt = $space->prepare('SELECT * FROM gis.Rooms WHERE RoomCode = ?');
$stmt->execute([$code]);
$room = $stmt->fetch();
if (!$room) {
    http_response_code(404);
    echo json_encode(['error' => 'No such room.']);
    exit;
}
$bldg = (string)$room['BldgNumber'];
$rkey = room_key((string)$room['RoomID']);

// ---- responsibility ----
$resp = $space->prepare('SELECT * FROM gis.RoomResponsibility WHERE RoomCode = ?');
$resp->execute([$code]);
$resp = $resp->fetch() ?: [];

$occ = $space->prepare('SELECT PersonName, NetID, Org, EmpType, PrimaryOffice, ConfirmedOn
                       FROM gis.Occupants WHERE RoomCode = ? ORDER BY PrimaryOffice DESC, PersonName');
$occ->execute([$code]);
$occupants = $occ->fetchAll();

// The survey follows from whoever occupies the room, unless assigned.
$surveys = [];
foreach ($occupants as $o) {
    if (preg_match('/^(INHS|ISGS|ISWS|ISAS|ISTC|OED)/i', (string)$o['Org'], $m)) {
        $surveys[strtoupper($m[1])] = true;
    }
}

// ---- work requests, open and closed, with money ----
$wr = $work->prepare(
    'SELECT w.WorkRequestID, w.TicketNumber, w.Title, w.Status, w.RoomNumber, w.RoomCode,
            w.EstimateAmount, w.CreatedAt, w.ClosedAt, t.Name AS TypeName
     FROM wr.WorkRequests w JOIN ref.WorkTypes t ON t.WorkTypeID = w.WorkTypeID
     WHERE w.BldgNumber = ?'
);
$wr->execute([$bldg]);
$workOrders = [];
$spent = 0.0;
$openCount = 0;
foreach ($wr as $r) {
    // The connector when it is set, the typed room otherwise, so rows
    // written before the room list was published still appear.
    $match = ($r['RoomCode'] !== null && $r['RoomCode'] !== '')
        ? ($r['RoomCode'] === $code)
        : (room_key((string)$r['RoomNumber']) === $rkey);
    if (!$match) {
        continue;
    }
    $spent += (float)$r['EstimateAmount'];
    if (!in_array($r['Status'], ['closed', 'verified', 'declined', 'merged'], true)) {
        $openCount++;
    }
    $workOrders[] = [
        'id' => (int)$r['WorkRequestID'], 'ticket' => $r['TicketNumber'], 'title' => $r['Title'],
        'status' => $r['Status'], 'type' => $r['TypeName'],
        'amount' => $r['EstimateAmount'] !== null ? (float)$r['EstimateAmount'] : null,
        'created' => substr((string)$r['CreatedAt'], 0, 10),
        'closed' => $r['ClosedAt'] ? substr((string)$r['ClosedAt'], 0, 10) : null,
    ];
}

// Shop work landing in this room counts as money spent on the room too.
$shopOrders = [];
try {
    $sh = db('shop')->prepare(
        'SELECT ShopRequestID, TicketNumber, WorkDescription, Status, RoomNumber, EstimateAmount,
                CreatedAt, ClosedAt FROM sh.ShopRequests WHERE BldgNumber = ?'
    );
    $sh->execute([$bldg]);
    foreach ($sh as $r) {
        if (room_key((string)$r['RoomNumber']) !== $rkey) {
            continue;
        }
        $spent += (float)$r['EstimateAmount'];
        if (!in_array($r['Status'], ['closed', 'complete', 'denied'], true)) {
            $openCount++;
        }
        $shopOrders[] = [
            'id' => (int)$r['ShopRequestID'], 'ticket' => $r['TicketNumber'],
            'title' => mb_strimwidth((string)$r['WorkDescription'], 0, 90, '…'),
            'status' => $r['Status'],
            'amount' => $r['EstimateAmount'] !== null ? (float)$r['EstimateAmount'] : null,
            'created' => substr((string)$r['CreatedAt'], 0, 10),
            'closed' => $r['ClosedAt'] ? substr((string)$r['ClosedAt'], 0, 10) : null,
        ];
    }
} catch (Throwable $e) { /* shop database unavailable */ }

// ---- move orders touching this room, either end ----
$moves = [];
try {
    $mv = db('move')->prepare(
        'SELECT m.MoveRequestID, m.TicketNumber, m.Status, m.NeededByDate, m.RequestedByNetID,
                i.Description, i.MoveType, i.FromBldgNumber, i.FromRoom, i.ToBldgNumber, i.ToRoom,
                i.PTag
         FROM mv.MoveItems i JOIN mv.MoveRequests m ON m.MoveRequestID = i.MoveRequestID
         WHERE i.FromBldgNumber = ? OR i.ToBldgNumber = ?'
    );
    $mv->execute([$bldg, $bldg]);
    foreach ($mv as $r) {
        $from = ($r['FromBldgNumber'] === $bldg) && room_key((string)$r['FromRoom']) === $rkey;
        $to = ($r['ToBldgNumber'] === $bldg) && room_key((string)$r['ToRoom']) === $rkey;
        if (!$from && !$to) {
            continue;
        }
        $moves[] = [
            'id' => (int)$r['MoveRequestID'], 'ticket' => $r['TicketNumber'],
            'status' => $r['Status'], 'needed' => substr((string)$r['NeededByDate'], 0, 10),
            'direction' => $from ? 'out of this room' : 'into this room',
            'item' => $r['Description'], 'move_type' => $r['MoveType'],
            'ptag' => $r['PTag'], 'by' => $r['RequestedByNetID'],
        ];
    }
} catch (Throwable $e) { /* move database unavailable */ }

// ---- inventory ----
$inv = $equipment->prepare('SELECT PropertyTag, Description, Model, SerialNumber, Person, AcquiredOn,
                              Cost, DepreciatedValue, FundingSource
                       FROM gis.Inventory WHERE RoomCode = ? ORDER BY Description');
$inv->execute([$code]);
$inventory = $inv->fetchAll();
$invCost = 0.0;
$invValue = 0.0;
foreach ($inventory as $i) {
    $invCost += (float)$i['Cost'];
    $invValue += (float)$i['DepreciatedValue'];
}

// ---- keys ----
// Holders link by room code when the building name resolved; otherwise
// fall back to the building and typed room, which is how a pasted
// register usually arrives.
// Keys have a database of their own; the room code is what ties them.
$keys = db('keys')->prepare(
    'SELECT KeyCode, HolderName, HolderNetID, IssuedOn, Notes, KeyState, KeyType, Quantity,
            EmpStatus, AssignedBy, SurveyCode
     FROM gis.RoomKeys
     WHERE RoomCode = ? OR (RoomCode IS NULL AND BldgNumber = ? AND
           UPPER(REPLACE(REPLACE(RoomID, \' \', \'\'), \'-\', \'\')) = ?)
     ORDER BY HolderName');
$keys->execute([$code, $bldg, ltrim(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$room['RoomID'])), '0')]);
$keys = $keys->fetchAll();

// ---- safety equipment ----
$saf = $space->prepare('SELECT Kind, Tag, Brand, Model, SerialNumber, Descriptor, Status, LastTested
                       FROM gis.Safety WHERE RoomCode = ? ORDER BY Kind, Tag');
$saf->execute([$code]);
$safety = $saf->fetchAll();

echo json_encode([
    'room' => [
        'code' => $room['RoomCode'], 'building' => $bldg, 'floor' => $room['FloorCode'],
        'room' => $room['RoomID'], 'name' => $room['RoomName'],
        'category' => trim(((string)$room['CategoryCode']) . ' ' . ((string)$room['CategoryDesc'])),
        'use' => trim(((string)$room['UseCode']) . ' ' . ((string)$room['UseDesc'])),
        'collection' => $room['CollectionType'],
        'department' => $room['Department'], 'division' => $room['DivisionName'] ?: $room['Division'],
        'area' => $room['AreaSqFt'] !== null ? (float)$room['AreaSqFt'] : null,
        'key_code_room' => $room['KeyCodeRoom'] ?? null,
        'key_code_building' => $room['KeyCodeBuilding'] ?? null,
        'drs_inspect' => $room['DrsInspect'] ?? null,
        'other_description' => $room['OtherDescription'] ?? null,
    ],
    'responsibility' => [
        'survey' => $resp['SurveyCode'] ?? (implode(', ', array_keys($surveys)) ?: null),
        'survey_source' => !empty($resp['SurveyCode']) ? 'assigned' : ($surveys ? 'from occupancy' : 'unknown'),
        'pi' => $resp['PrincipalInvestigator'] ?? null,
        'safety_contact' => $resp['SafetyContact'] ?? null,
        'notes' => $resp['Notes'] ?? null,
        'occupants' => $occupants,
    ],
    'work_orders' => ['work' => $workOrders, 'shop' => $shopOrders,
                      'total_spent' => round($spent, 2), 'open_count' => $openCount],
    'inventory' => ['items' => $inventory, 'total_cost' => round($invCost, 2),
                    'total_value' => round($invValue, 2)],
    'keys' => $keys,
    'moves' => $moves,
    'safety' => $safety,
]);
