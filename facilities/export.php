<?php
declare(strict_types=1);

/**
 * CSV extract with the current board filters applied (spec Section 12.1).
 * Natural keys only: building numbers, survey codes, NetIDs.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/csvio.php';

if (!app_installed()) {
    header('Location: install.php');
    exit;
}
current_user();
require_role('coordinator');

$asked = (string)($_GET['tab'] ?? $_GET['dataset'] ?? $_GET['what'] ?? 'work');
$dataset = in_array($asked, ['work', 'move', 'shop', 'space', 'key',
                             'purchase', 'purchasefunding'], true) ? $asked : 'work';
$defs = csv_datasets()[$dataset];

csv_send($defs['file'], $defs['header'], csv_rows($dataset, [
    'survey' => (string)($_GET['survey'] ?? ''),
    'status' => (string)($_GET['status'] ?? ''),
]));
