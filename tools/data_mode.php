<?php
declare(strict_types=1);

/**
 * Print the engine that holds the registers, "localdb" or "postgres", from
 * dataMode in config.local.json (db_engine() in facilities/lib/db.php).
 *
 *   php tools/data_mode.php
 *
 * The launchers ask this before they start anything. On "postgres" they
 * pass -d extension=pdo_pgsql to PHP (the bundled php.ini loads only
 * pdo_sqlsrv, and tools/php is protected) and skip every LocalDB step: the
 * ODBC driver check, waking the instance, the readiness wait and the
 * keep-alive. Opens no database and prints no setting but the engine.
 */

require __DIR__ . '/../facilities/lib/db.php';

echo db_engine(), PHP_EOL;
