<?php
declare(strict_types=1);

/**
 * Hold LocalDB open for as long as the application is running.
 *
 *   php tools/keepalive.php <port>
 *
 * Why this exists, and it is the whole reason the application kept dying:
 *
 * LocalDB stops itself after a few minutes with no client connected. When
 * it starts again it gets a NEW named pipe. The application's web server
 * is one long-lived process, and the driver inside it has already cached
 * the OLD pipe - so every request from then on fails with "Login failed
 * for user ...", which reads like a password problem and is not one. A
 * freshly started process connects perfectly, which is what made this so
 * confusing: every check from the command line passed while the browser
 * kept failing.
 *
 * Proven on 28 Sep 2026: with LocalDB left running and untouched,
 * restarting only the web server turned 503 into 200.
 *
 * So: keep one connection open. An instance with a client attached does
 * not idle out, never restarts, and never changes its pipe.
 *
 * This process ends when the application does - it watches the port and
 * exits once the server stops answering, so it cannot be left behind.
 */

require __DIR__ . '/../facilities/lib/db.php';

// PostgreSQL neither idles out nor changes its address, so there is
// nothing to hold open; the launchers do not start this on PostgreSQL.
if (db_engine() !== 'localdb') {
    exit(0);
}

$port = (int)($argv[1] ?? 8080);
$every = 45;          // comfortably inside LocalDB's idle timeout
$missed = 0;

/** Is the application still serving? health.php touches no database. */
function app_alive(int $port): bool
{
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
    $body = @file_get_contents('http://127.0.0.1:' . $port . '/health.php', false, $ctx);
    return is_string($body) && str_contains($body, 'PRIOps');
}

$pdo = null;
// Let the web server make its first connection alone. Two processes
// connecting at the same instant is what spawned the engine that
// sqllocaldb then lost track of.
sleep(20);
while (true) {
    // Reconnect if we have never connected, or the connection has gone.
    try {
        if ($pdo === null) {
            $pdo = db_connect(app_config()['db']['work']);
        }
        $pdo->query('SELECT 1')->fetch();
    } catch (Throwable $e) {
        $pdo = null;          // next turn opens a fresh one, waking the instance
    }

    if (!app_alive($port)) {
        $missed++;
        if ($missed >= 2) {
            exit(0);          // the application has closed; so do we
        }
    } else {
        $missed = 0;
    }
    sleep($every);
}
