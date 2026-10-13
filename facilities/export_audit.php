<?php
declare(strict_types=1);

/**
 * Everything outstanding in the data, as one CSV to work through away
 * from the screen.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/dataaudit.php';

if (!app_installed()) {
    header('Location: install.php');
    exit;
}
current_user();
require_role('coordinator');

set_time_limit(300);
audit_csv_stream();
