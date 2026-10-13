<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Where the databases are, and how to get to them.
 *
 * The application began with all ten databases on the same machine that
 * served the pages, reached through a LocalDB pipe. That cannot be
 * shared: LocalDB is a user-mode instance with no listener, so a second
 * computer has no way in. Moving to a real instance is therefore not a
 * code change but a settings change - and this is the screen that makes
 * it one, rather than an edit to a file the spec says never to edit.
 *
 * The password follows the rule already used for the mail relay: an
 * empty box means "leave the stored one alone", so somebody can change
 * the host without being made to retype a secret.
 */

/** The connection descriptor the ten databases share. */
function dbs_current(): array
{
    $cfg = app_config()['db'] ?? [];
    $c = $cfg['work'] ?? [];
    return [
        'host'            => (string)($c['host'] ?? ''),
        'port'            => (int)($c['port'] ?? 0),
        'auth'            => (string)($c['auth'] ?? 'windows'),
        'username'        => (string)($c['username'] ?? ''),
        'has_password'    => ($c['password'] ?? '') !== '',
        'trust_cert'      => !empty($c['trust_cert']),
        'connect_timeout' => (int)($c['connect_timeout'] ?? 5),
    ];
}

/** Is this a LocalDB pipe - the kind no other machine can reach? */
function dbs_is_localdb(?string $host = null): bool
{
    $host = $host ?? dbs_current()['host'];
    return stripos($host, '(localdb)') !== false;
}

/**
 * Try one descriptor against one database and time it. Used both for the
 * live status list and for "test without saving", so what the test says
 * is what the application will experience.
 */
function dbs_try(array $descriptor, string $database): array
{
    $descriptor['database'] = $database;
    $started = microtime(true);
    try {
        $pdo = db_connect($descriptor);
        $pdo->query('SELECT 1')->fetch();
        return ['ok' => true, 'ms' => (int)round((microtime(true) - $started) * 1000),
                'error' => null];
    } catch (Throwable $e) {
        return ['ok' => false, 'ms' => (int)round((microtime(true) - $started) * 1000),
                'error' => db_reason($e)];
    }
}

/** Every database, tried with the settings as they stand. */
function dbs_status(): array
{
    $cfg = app_config()['db'] ?? [];
    $base = $cfg['work'] ?? [];
    $out = [];
    foreach (APP_DB_NAMES as $name) {
        // Mirror db() exactly: an entry the config never names still has a
        // database of its own, reached with the shared server settings.
        // Reading $base['database'] here instead would have reported two
        // registers as Work and hidden whether they answer at all.
        $d = $cfg[$name] ?? null;
        $database = $d === null
            ? APP_DB_LABELS[$name]
            : (string)($d['database'] ?? APP_DB_LABELS[$name]);
        $d = $d ?? $base;
        $out[$name] = dbs_try($d, $database)
            + ['database' => $database, 'named' => isset($cfg[$name])];
    }
    return $out;
}

/** The descriptor a submitted form describes, without saving it. */
function dbs_from_input(array $in): array
{
    $current = dbs_current();
    $stored = app_config()['db']['work'] ?? [];
    $auth = ($in['auth'] ?? 'windows') === 'sql' ? 'sql' : 'windows';
    return [
        'host'            => trim((string)($in['host'] ?? '')),
        'port'            => (int)($in['port'] ?? 0),
        'database'        => (string)($stored['database'] ?? APP_DB_LABELS['work']),
        'auth'            => $auth,
        'username'        => $auth === 'sql' ? trim((string)($in['username'] ?? '')) : '',
        // Blank means keep what is stored, so the host can be changed
        // without retyping the password.
        'password'        => $auth === 'sql'
            ? (($in['password'] ?? '') !== ''
               ? (string)$in['password'] : (string)($stored['password'] ?? ''))
            : '',
        'trust_cert'      => !empty($in['trust_cert']),
        'connect_timeout' => max(1, (int)($in['connect_timeout'] ?? 5)),
    ];
}

/**
 * Write the settings for all ten databases.
 *
 * They share a server, so one form drives every entry; only the database
 * name differs. Written whole to a temporary file and moved into place,
 * so a failed write cannot leave the application without a config.
 */
function dbs_save(array $in): array
{
    $d = dbs_from_input($in);
    if ($d['host'] === '') {
        return [false, 'The server has to be named.'];
    }
    if ($d['auth'] === 'sql' && $d['username'] === '') {
        return [false, 'A SQL login needs a user name.'];
    }
    // Proven before it is written. Saving settings that cannot connect
    // would lock everybody out of the application, including this page.
    $probe = dbs_try($d, (string)$d['database']);
    if (!$probe['ok']) {
        return [false, 'Not saved - that server refused the connection: ' . $probe['error']];
    }

    $config = app_config();
    foreach (APP_DB_NAMES as $name) {
        $entry = $d;
        $entry['database'] = $config['db'][$name]['database'] ?? APP_DB_LABELS[$name];
        $config['db'][$name] = $entry;
    }
    $php = '<?php' . PHP_EOL
         . '// Written by the application. Never hand edit (spec rule 4).' . PHP_EOL
         . 'return ' . var_export($config, true) . ';' . PHP_EOL;
    $tmp = config_path() . '.tmp';
    if (file_put_contents($tmp, $php, LOCK_EX) === false || !rename($tmp, config_path())) {
        @unlink($tmp);
        return [false, 'Could not write config.php. Check the permissions on the application root.'];
    }
    @chmod(config_path(), 0640);
    return [true, 'Saved. All ten databases now point at ' . $d['host']
                . ' and the connection was proven before writing.'];
}

/**
 * How much disk the ten registers occupy, in MB.
 *
 * Asked of the server rather than of the folder, so it stays true after
 * the databases move. Returns null if the server will not say.
 */
function dbs_total_mb(): ?int
{
    return db_total_mb();
}
