<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/ui.php';

/**
 * Location and contents lookups for the intake forms, so nobody types a
 * room number and nobody retypes what the property record already knows.
 *
 *   ?bldg=0109                 floors and rooms in that building
 *   ?bldg=0109&floor=01        rooms on that floor
 *   ?room_code=0109_01_0167    the tagged property in that room
 */

header('Content-Type: application/json');
if (!app_installed()) {
    http_response_code(503);
    echo json_encode(['error' => 'not installed']);
    exit;
}
current_user();

// Two questions on one endpoint: what rooms a building has, which is the
// estate, and what property is in a room, which is the inventory.
$space = db('space');
$equipment = db('equipment');
$roomCode = trim((string)($_GET['room_code'] ?? ''));

if ($roomCode !== '') {
    $stmt = $equipment->prepare(
        'SELECT InventoryID, PropertyTag, Description, Model, SerialNumber, Person, Cost
         FROM gis.Inventory
         WHERE RoomCode = ? AND Status = \'active\'
         ORDER BY Description');
    $stmt->execute([$roomCode]);
    $items = [];
    foreach ($stmt as $r) {
        $items[] = [
            'id' => (int)$r['InventoryID'],
            'tag' => (string)$r['PropertyTag'],
            'description' => (string)$r['Description'],
            'model' => (string)$r['Model'],
            'serial' => (string)$r['SerialNumber'],
            'person' => (string)$r['Person'],
        ];
    }
    echo json_encode(['items' => $items]);
    exit;
}

$bldg = trim((string)($_GET['bldg'] ?? ''));
if ($bldg === '') {
    echo json_encode(['floors' => [], 'rooms' => []]);
    exit;
}

$floor = trim((string)($_GET['floor'] ?? ''));
$sql = 'SELECT RoomCode, FloorCode, RoomID, RoomName FROM gis.Rooms WHERE BldgNumber = ?'
     . ($floor !== '' ? ' AND FloorCode = ?' : '')
     . ' ORDER BY FloorCode, RoomID';
$stmt = $space->prepare($sql);
$stmt->execute($floor !== '' ? [$bldg, $floor] : [$bldg]);

$rooms = [];
$floors = [];
foreach ($stmt as $r) {
    $floors[(string)$r['FloorCode']] = true;
    $rooms[] = [
        'code'  => (string)$r['RoomCode'],
        'floor' => (string)$r['FloorCode'],
        'room'  => (string)$r['RoomID'],
        'name'  => (string)$r['RoomName'],
    ];
}
ksort($floors);
echo json_encode(['floors' => array_keys($floors), 'rooms' => $rooms]);
