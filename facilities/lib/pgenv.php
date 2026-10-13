<?php
declare(strict_types=1);

/**
 * The PostgreSQL credentials, read from pg.env in the data folder.
 *
 * The owner writes pg.env with platform\tools\pg-provision.ps1: one
 * KEY=VALUE line each for PGHOST, PGPORT, PGDATABASE, PGUSER, PGPASSWORD
 * and PGTESTDATABASE. It sits beside storage\ in the data folder, outside
 * the repository, so no credential is ever committed, and nothing in this
 * application prints, logs or copies its contents: an error names the
 * file and the missing key, never a value.
 *
 * config.local.json may name another file with "pgEnv", relative to the
 * application folder (a drive letter is refused, as for dataFolder). The
 * test harness uses that to read the real pg.env while its storage and
 * runtime folders stay in a scratch data folder.
 */

require_once __DIR__ . '/paths.php';
require_once __DIR__ . '/serverenv.php';

const PG_ENV_KEYS = ['PGHOST', 'PGPORT', 'PGDATABASE', 'PGUSER', 'PGPASSWORD'];

/** The pg.env file this installation reads. */
function pg_env_path(): string
{
    $named = trim((string)(local_config()['pgEnv'] ?? ''));
    if ($named === '') {
        return data_root() . DIRECTORY_SEPARATOR . 'pg.env';
    }
    if (paths_is_absolute($named)) {
        throw new RuntimeException('pgEnv in config.local.json must be relative to the application folder.');
    }
    return paths_app_root() . DIRECTORY_SEPARATOR
         . str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $named);
}

/**
 * Parse a pg.env file into an array. Blank lines and # comments are
 * skipped; a value may be wrapped in single or double quotes. Throws when
 * the file is missing or a required key is absent, naming only the key.
 */
function pg_env_parse(string $file): array
{
    if (!is_file($file)) {
        throw new RuntimeException('pg.env was not found in the data folder. The owner writes it with '
            . 'platform\\tools\\pg-provision.ps1 before the application can use PostgreSQL.');
    }
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', (string)file_get_contents($file)) as $line) {
        $line = trim(preg_replace('/^\xEF\xBB\xBF/', '', $line));
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (strncasecmp($line, 'export ', 7) === 0) {
            $line = ltrim(substr($line, 7));
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $key = trim(substr($line, 0, $eq));
        $val = trim(substr($line, $eq + 1));
        if (strlen($val) >= 2 && ($val[0] === '"' || $val[0] === "'") && substr($val, -1) === $val[0]) {
            $val = substr($val, 1, -1);
        }
        $out[$key] = $val;
    }
    foreach (PG_ENV_KEYS as $k) {
        if (!isset($out[$k]) || $out[$k] === '') {
            throw new RuntimeException('pg.env has no ' . $k . ' line.');
        }
    }
    return $out;
}

/** The parsed pg.env of this installation, read once per process. */
function pg_env(): array
{
    static $env = null;
    if ($env === null) {
        // A server tier has no pg.env: its environment file carries the PG* lines.
        $env = server_tier() !== null ? server_pg_env(PG_ENV_KEYS) : pg_env_parse(pg_env_path());
    }
    return $env;
}
