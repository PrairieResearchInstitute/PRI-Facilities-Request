<?php
declare(strict_types=1);

/**
 * The estate's PostgreSQL on the portable drive (platform D-028, app D-011).
 *
 * PostgreSQL, program and data, travels on the estate drive: the runtime in
 * <estate>\runtime\postgresql\17, one cluster in <estate>\data\postgres\17,
 * listening on 127.0.0.1 port 5433. Nothing is installed on the computer.
 * The shared script <estate>\runtime\postgresql\estate-pg.js owns the
 * cluster; this file mirrors its paths() so the PHP side (the launchers'
 * helper tools/pg/cluster.php and tools/pg/backup.php) finds the same
 * folders without Node. <estate> is the folder holding .estate-live, found
 * by walking up from the application folder, never by a drive letter.
 *
 * Nothing here opens a database or reads a credential file. The cluster's
 * superuser file (<estate>\data\postgres\superuser.env) is read only by
 * estate-pg.js, never by this application.
 */

const ESTATE_PG_HOST = '127.0.0.1';
const ESTATE_PG_PORT = 5433;

/** The estate folder: the nearest ancestor of $from holding .estate-live, or null. */
function estate_dir(?string $from = null): ?string
{
    $d = realpath($from ?? dirname(__DIR__, 2));
    if ($d === false) {
        return null;
    }
    while (true) {
        if (is_file($d . DIRECTORY_SEPARATOR . '.estate-live')) {
            return $d;
        }
        $up = dirname($d);
        if ($up === $d) {
            return null;
        }
        $d = $up;
    }
}

/**
 * The same folders as paths() in estate-pg.js. Throws when no .estate-live
 * marker sits above the application folder (a frozen copy, or a clone
 * outside the estate).
 */
function estate_pg_paths(?string $from = null): array
{
    $estate = estate_dir($from);
    if ($estate === null) {
        throw new RuntimeException('No .estate-live marker above the application folder, so the drive\'s '
            . 'PostgreSQL cannot be found. Run the app from the live App_to_Migrate folder on the drive.');
    }
    $s = DIRECTORY_SEPARATOR;
    $home = $estate . $s . 'data' . $s . 'postgres';
    return [
        'estate' => $estate,
        'bin'    => $estate . $s . 'runtime' . $s . 'postgresql' . $s . '17' . $s . 'bin',
        'home'   => $home,
        'data'   => $home . $s . '17',
        'log'    => $home . $s . 'log' . $s . 'postgresql.log',
        'script' => $estate . $s . 'runtime' . $s . 'postgresql' . $s . 'estate-pg.js',
    ];
}

/** Full path of a client program (pg_dump, pg_restore, psql, pg_ctl) in the drive's runtime. */
function estate_pg_program(string $name, ?array $p = null): string
{
    $p = $p ?? estate_pg_paths();
    return $p['bin'] . DIRECTORY_SEPARATOR . $name . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
}

/**
 * A node.exe to run estate-pg.js with: one carried on the drive under
 * <estate>\runtime first, then node on PATH. Null when neither exists, and
 * the caller then drives pg_ctl itself (tools/pg/cluster.php).
 */
function estate_node(?array $p = null): ?string
{
    $p = $p ?? estate_pg_paths();
    $exe = PHP_OS_FAMILY === 'Windows' ? 'node.exe' : 'node';
    $rt = $p['estate'] . DIRECTORY_SEPARATOR . 'runtime';
    foreach (['', 'node', 'nodejs'] as $sub) {
        $f = $rt . ($sub === '' ? '' : DIRECTORY_SEPARATOR . $sub) . DIRECTORY_SEPARATOR . $exe;
        if (is_file($f)) {
            return $f;
        }
    }
    foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $dir) {
        $dir = trim($dir, " \"");
        if ($dir !== '' && is_file($dir . DIRECTORY_SEPARATOR . $exe)) {
            return $dir . DIRECTORY_SEPARATOR . $exe;
        }
    }
    return null;
}

/** pg_ctl arguments for a start, exactly as start() in estate-pg.js passes them. */
function estate_pg_start_args(array $p): array
{
    return ['start', '-D', $p['data'], '-l', $p['log'], '-w', '-t', '60', '-o', '-p ' . ESTATE_PG_PORT];
}

/** pg_ctl arguments for the fast, clean stop of stop() in estate-pg.js. */
function estate_pg_stop_args(array $p): array
{
    return ['stop', '-D', $p['data'], '-m', 'fast', '-w', '-t', '60'];
}

/** pg_isready arguments, as running() in estate-pg.js passes them. */
function estate_pg_ready_args(): array
{
    return ['-h', ESTATE_PG_HOST, '-p', (string)ESTATE_PG_PORT, '-t', '3'];
}
