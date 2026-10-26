<?php
declare(strict_types=1);

/**
 * Start and stop the estate's PostgreSQL cluster on the drive for this app
 * (platform D-028, app D-011).
 *
 *   php tools/pg/cluster.php start          start it unless it already runs
 *   php -d extension=pdo_pgsql tools/pg/cluster.php stop
 *                                           stop it cleanly unless another app is connected
 *   php -d extension=pdo_pgsql tools/pg/cluster.php watch <port>
 *                                           wait until this app's server stops answering, then stop
 *   php tools/pg/cluster.php status
 *
 * The launchers call start before the PHP server starts and leave watch
 * running beside it, only when dataMode is postgres. STOP SERVER.bat calls
 * stop after it ends the server.
 *
 * Which tool does the work. The cluster belongs to the shared script
 * <estate>\runtime\postgresql\estate-pg.js. When a node.exe exists, on the
 * drive under <estate>\runtime or on PATH, this file runs
 * "node estate-pg.js start|stop" and nothing else, so every app on the
 * drive starts and stops the cluster through one piece of code. This app
 * runs on a portable PHP and may meet a computer with no Node at all; then
 * this file drives the runtime's pg_ctl itself with the arguments
 * estate-pg.js uses (tools/pg/estate.php holds them): the same data folder,
 * log and port 5433, and a fast, clean stop (pg_ctl stop -m fast). The one
 * difference is how the stop counts the other apps' connections:
 * estate-pg.js asks as the cluster superuser, whose password file this app
 * never reads, so the fallback asks as this app's own login role from
 * pg.env. Any other login's client sessions still show in pg_stat_activity
 * (usesysid is visible to every role), so the count is the same. When the
 * count cannot be made, the fallback does not stop the cluster.
 *
 * Exit codes: 0 done (or nothing to do), 1 failed, 2 usage.
 */

require_once __DIR__ . '/estate.php';

$cmd = $argv[1] ?? 'status';
$say = fn(string $s) => print($s . PHP_EOL);

/** Run a program; [exit code, stdout+stderr]. $capture false sends output to NUL (see start). */
function cluster_run(array $cmd, bool $capture = true): array
{
    $env = getenv();
    $env['PGTZ'] = 'UTC';
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $spec = $capture
        ? [0 => ['file', $null, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']]
        : [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']];
    $p = @proc_open($cmd, $spec, $pipes, null, $env, ['bypass_shell' => true]);
    if (!is_resource($p)) {
        return [1, 'could not start ' . basename($cmd[0])];
    }
    $out = '';
    if ($capture) {
        $out = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
    }
    return [proc_close($p), trim($out)];
}

function cluster_initialized(array $p): bool
{
    return is_file($p['data'] . DIRECTORY_SEPARATOR . 'PG_VERSION');
}

function cluster_running(array $p): bool
{
    return cluster_run(array_merge([estate_pg_program('pg_isready', $p)], estate_pg_ready_args()))[0] === 0;
}

/**
 * Client sessions on the cluster other than the one this count opens,
 * asked as this app's own role. Null when the count cannot be made.
 */
function cluster_other_connections(?string &$why): ?int
{
    if (!extension_loaded('pdo_pgsql')) {
        $why = 'PHP was started without pdo_pgsql (pass -d extension=pdo_pgsql)';
        return null;
    }
    try {
        require_once __DIR__ . '/../../facilities/lib/pgenv.php';
        $env = pg_env();
        $pdo = new PDO('pgsql:host=' . ESTATE_PG_HOST . ';port=' . ESTATE_PG_PORT . ';dbname=' . $env['PGDATABASE']
                       . ';connect_timeout=10', $env['PGUSER'], $env['PGPASSWORD'],
                       [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $n = $pdo->query("SELECT count(*) FROM pg_stat_activity
                           WHERE pid <> pg_backend_pid() AND usesysid IS NOT NULL
                             AND coalesce(backend_type, 'client backend') = 'client backend'")->fetchColumn();
        $pdo = null;
        return (int)$n;
    } catch (Throwable $e) {
        // pg_env() names a file or a key at most, never a value.
        $why = 'the count failed: ' . $e->getMessage();
        return null;
    }
}

/**
 * Start the cluster on Windows the way launch() in estate-pg.js does, for a
 * computer with no Node to run that script. The server's own output is
 * appended to the cluster log rather than piped, because a pipe handed to a
 * server that outlives this process would be held open for its whole life;
 * the log is where a failed start explains itself. Returns when the cluster
 * answers pg_isready, or after 60 seconds, which is what estate-pg.js waits.
 */
function cluster_launch_windows(array $p): void
{
    $env = getenv();
    $env['PGTZ'] = 'UTC';
    $spec = [
        0 => ['file', 'NUL', 'r'],
        1 => ['file', $p['log'], 'a'],
        2 => ['file', $p['log'], 'a'],
    ];
    $h = @proc_open(estate_pg_launch_command($p), $spec, $pipes, null, $env, ['bypass_shell' => true]);
    if (is_resource($h)) {
        proc_close($h);
    }
    for ($waited = 0; $waited < 60000; $waited += 250) {
        if (cluster_running($p)) {
            return;
        }
        usleep(250000);
    }
}

function cluster_start(array $p, callable $say): int
{
    if (!cluster_initialized($p)) {
        $say('The drive\'s PostgreSQL cluster does not exist yet (' . $p['data'] . '). '
            . 'It is created once with: node "' . $p['script'] . '" init');
        return 1;
    }
    if (cluster_running($p)) {
        $say('start: already running on ' . ESTATE_PG_HOST . ':' . ESTATE_PG_PORT);
        return 0;
    }
    // Output goes to NUL, never to a pipe: the server pg_ctl leaves running
    // inherits the handles it was given and would hold a pipe open for its
    // whole life. The cluster's own log says why a start failed.
    $node = estate_node($p);
    $how = $node !== null ? 'node estate-pg.js' : 'pg_ctl';
    if ($node !== null) {
        cluster_run([$node, $p['script'], 'start'], false);
    } else {
        if (!is_dir(dirname($p['log']))) {
            @mkdir(dirname($p['log']), 0777, true);
        }
        if (PHP_OS_FAMILY === 'Windows') {
            cluster_launch_windows($p);
        } else {
            cluster_run(array_merge([estate_pg_program('pg_ctl', $p)], estate_pg_start_args($p)), false);
        }
    }
    if (!cluster_running($p)) {
        $say('PostgreSQL on the drive did not start (' . $how . '). Its log: ' . $p['log']);
        return 1;
    }
    $say('start: started on ' . ESTATE_PG_HOST . ':' . ESTATE_PG_PORT . ' (' . $how . ')');
    return 0;
}

function cluster_stop(array $p, callable $say): int
{
    if (!cluster_initialized($p) || !cluster_running($p)) {
        $say('stop: not running');
        return 0;
    }
    $node = estate_node($p);
    if ($node !== null) {
        [$code, $out] = cluster_run([$node, $p['script'], 'stop']);
        $say($out !== '' ? $out : 'stop: node estate-pg.js exited ' . $code);
        return $code === 0 ? 0 : 1;
    }
    $why = '';
    $n = cluster_other_connections($why);
    if ($n === null) {
        $say('stop: left running, because ' . $why . '.');
        return 1;
    }
    if ($n > 0) {
        $say('stop: left running, ' . $n . ' other connection(s) still open');
        return 0;
    }
    [$code, $out] = cluster_run(array_merge([estate_pg_program('pg_ctl', $p)], estate_pg_stop_args($p)));
    if ($code !== 0) {
        $say('pg_ctl stop failed: ' . substr($out, 0, 600));
        return 1;
    }
    $say('stop: clean shutdown (pg_ctl)');
    return 0;
}

/** Is this app's server still answering? health.php touches no database. */
function cluster_app_alive(int $port): bool
{
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
    $body = @file_get_contents('http://127.0.0.1:' . $port . '/health.php', false, $ctx);
    return is_string($body) && str_contains($body, 'PRIOps');
}

try {
    $p = estate_pg_paths();
} catch (Throwable $e) {
    $say($e->getMessage());
    exit(1);
}

if ($cmd === 'start') {
    exit(cluster_start($p, $say));
}
if ($cmd === 'stop') {
    exit(cluster_stop($p, $say));
}
if ($cmd === 'watch') {
    $port = (int)($argv[2] ?? 0);
    if ($port <= 0) {
        fwrite(STDERR, "Usage: php tools/pg/cluster.php watch <port>\n");
        exit(2);
    }
    // Give the server time to come up, then stop the cluster once it has
    // missed two checks in a row (the keep-alive's rule for LocalDB).
    sleep(20);
    $missed = 0;
    while ($missed < 2) {
        $missed = cluster_app_alive($port) ? 0 : $missed + 1;
        if ($missed < 2) {
            sleep(15);
        }
    }
    exit(cluster_stop($p, $say));
}
if ($cmd === 'status') {
    $node = estate_node($p);
    $say('data:    ' . $p['data'] . (cluster_initialized($p) ? '' : ' (not created yet)'));
    $say('bin:     ' . $p['bin']);
    $say('log:     ' . $p['log']);
    $say('address: ' . ESTATE_PG_HOST . ':' . ESTATE_PG_PORT);
    $say('running: ' . (cluster_initialized($p) && cluster_running($p) ? 'yes' : 'no'));
    $say('driver:  ' . ($node !== null ? 'node estate-pg.js (' . $node . ')' : 'pg_ctl (no node.exe on the drive or on PATH)'));
    exit(0);
}
fwrite(STDERR, "Usage: php tools/pg/cluster.php start | stop | watch <port> | status\n");
exit(2);
