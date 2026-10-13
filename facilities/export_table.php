<?php
declare(strict_types=1);

/**
 * Any table in any of the three databases as CSV. The board and manifest
 * exports carry natural keys for people; this one carries the table as it
 * stands, for moving data to another environment or into a report.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/tableexport.php';

if (!app_installed()) {
    header('Location: install.php');
    exit;
}
current_user();
require_role('coordinator');

export_table_stream((string)($_GET['db'] ?? 'work'), (string)($_GET['table'] ?? ''));
