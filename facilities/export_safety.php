<?php
declare(strict_types=1);

/**
 * Safety equipment manifest as CSV: where every fixture is, ready for
 * whoever walks the building.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/csvio.php';

if (!app_installed()) {
    header('Location: install.php');
    exit;
}
current_user();
require_role('coordinator');

$bldg = trim((string)($_GET['bldg'] ?? ''));
$kind = trim((string)($_GET['kind'] ?? ''));
$where = [];
$params = [];
if ($bldg !== '') {
    $where[] = 's.BldgNumber = ?';
    $params[] = $bldg;
}
if ($kind !== '') {
    $where[] = 's.Kind = ?';
    $params[] = $kind;
}
$stmt = db('space')->prepare(
    'SELECT s.BldgNumber, b.Name AS BuildingName, r.FloorCode, s.RoomID, r.RoomName, s.RoomCode,
            s.Kind, s.Tag, s.Brand, s.Model, s.SerialNumber, s.Descriptor, s.Status, s.LastTested,
            s.NextDue
     FROM gis.Safety s
     LEFT JOIN gis.Rooms r ON r.RoomCode = s.RoomCode
     LEFT JOIN ref.Buildings b ON b.BldgNumber = s.BldgNumber'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
    . ' ORDER BY s.BldgNumber, r.FloorCode, s.RoomID, s.Kind');
$stmt->execute($params);

$rows = [];
foreach ($stmt as $r) {
    $rows[] = [$r['BldgNumber'], $r['BuildingName'], $r['FloorCode'], $r['RoomID'], $r['RoomName'],
               $r['RoomCode'], str_replace('_', ' ', (string)$r['Kind']), $r['Tag'], $r['Brand'],
               $r['Model'], $r['SerialNumber'], $r['Descriptor'], $r['Status'], $r['LastTested'],
               $r['NextDue']];
}

csv_send('safety_manifest.csv',
    ['Building Number', 'Building Name', 'Floor', 'Room', 'Room Name', 'Room Code', 'Equipment',
     'Tag', 'Brand', 'Model', 'Serial Number', 'Descriptor', 'Status', 'Last Tested', 'Next Due'],
    $rows);
