<?php
declare(strict_types=1);

/**
 * Exit 0 when the database actually answers, 1 when it does not.
 *
 *   php tools/dbready.php [seconds]
 *
 * "Running" from sqllocaldb is not the same as "will answer a query". The
 * launcher used to start the instance and then start the web server and
 * the keep-alive straight away, so two processes made their first
 * connection while the instance was still settling. A second engine then
 * gets spawned that sqllocaldb loses track of, and from that moment the
 * instance reports Stopped while an orphan holds it - the "Login failed"
 * that keeps coming back.
 *
 * The wait is SHORT on purpose. This is called repeatedly, so a probe
 * carrying the application's own 45 second timeout (rule 5, which is right
 * for one real attempt) turns a stopped instance into minutes of silence
 * with no browser window at all. That is what it did on 5 October: the
 * launcher looked dead because it was still probing.
 */

require __DIR__ . '/../facilities/lib/db.php';

$wait = max(2, min(15, (int)($argv[1] ?? 5)));

// On PostgreSQL the same question goes to the Work register's schema.
if (db_engine() === 'postgres') {
    try {
        db('work')->query('SELECT 1')->fetch();
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

$descriptor = app_config()['db']['work'];
$descriptor['connect_timeout'] = $wait;

try {
    $pdo = db_connect($descriptor);
    $pdo->query('SELECT 1')->fetch();
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, explode(PHP_EOL, str_replace("\r\n", PHP_EOL, $e->getMessage()))[0] . PHP_EOL);
    exit(1);
}
