<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/ui.php';
require_once __DIR__ . '/../lib/lookups.php';
require_once __DIR__ . '/../lib/bannercodes.php';

/**
 * Transport for the two lookups. All it does is check the caller, take a
 * query string, and hand back what lib/lookups.php found.
 *
 *   ?what=cfop&q=1-594662     accounts by code, activity or purpose
 *   ?what=person&q=dres       people by name or NetID
 *   ?what=banner&q=supplies   Banner account codes a person may enter
 *
 * The queries themselves live in lib/lookups.php, which is the one place
 * to change when the campus directory becomes reachable.
 */

header('Content-Type: application/json');
if (!app_installed()) {
    http_response_code(503);
    echo json_encode(['error' => 'not installed']);
    exit;
}
current_user();

$what = (string)($_GET['what'] ?? 'cfop');
$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
    echo json_encode(['matches' => []]);
    exit;
}

$matches = match ($what) {
    'person' => lookup_people($q),
    'banner' => lookup_banner($q),
    default  => lookup_cfop($q),
};
echo json_encode(['matches' => $matches]);
