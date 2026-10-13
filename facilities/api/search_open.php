<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/ui.php';
require_once __DIR__ . '/../lib/dedupe.php';

/**
 * Live duplicate candidates during work request intake (spec Section 14).
 * GET building_id, type_id, q, room, location. Returns candidates ranked
 * by similarity for the forced-look gate.
 */

header('Content-Type: application/json');
if (!app_installed()) {
    http_response_code(503);
    echo json_encode(['error' => 'not installed']);
    exit;
}
current_user();   // session + provisioning

$buildingId = (int)($_GET['building_id'] ?? 0);
if ($buildingId <= 0) {
    echo json_encode(['candidates' => []]);
    exit;
}

$cands = dedupe_candidates([
    'building_id' => $buildingId,
    'type_id'     => (int)($_GET['type_id'] ?? 0),
    'text'        => (string)($_GET['q'] ?? ''),
    'room'        => (string)($_GET['room'] ?? ''),
    'location'    => (string)($_GET['location'] ?? ''),
]);

$out = [];
foreach (array_slice($cands, 0, 8) as $c) {
    $out[] = [
        'id'            => (int)$c['WorkRequestID'],
        'ticket'        => $c['TicketNumber'],
        'title'         => $c['Title'],
        'room'          => $c['RoomNumber'],
        'location'      => $c['WrittenLocation'],
        'status'        => $c['Status'],
        'age_days'      => (int)floor((time() - strtotime((string)$c['CreatedAt'])) / 86400),
        'confirmations' => (int)$c['Confirmations'],
        'similarity'    => $c['_similarity'],
    ];
}
echo json_encode(['candidates' => $out]);
