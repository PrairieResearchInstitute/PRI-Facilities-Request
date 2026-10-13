<?php
declare(strict_types=1);

/**
 * Every table in all three databases, as CSVs, in one archive.
 *
 * The per-table downloads are still there for when one table is what you
 * want. This is for when the answer is "all of it": a backup, a handover,
 * or work in a spreadsheet away from the app.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/bulkexport.php';

if (!app_installed()) {
    header('Location: install.php');
    exit;
}
current_user();
require_role('admin');

set_time_limit(600);
bulk_export_stream();
