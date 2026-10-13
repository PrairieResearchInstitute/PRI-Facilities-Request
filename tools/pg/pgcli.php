<?php
declare(strict_types=1);

/**
 * Shared by the command-line PostgreSQL tools (migrate, seed, load, the
 * tests): which database a run targets, and a plain PDO connection to it.
 *
 * The target is always named on the command line, --database test or
 * --database live, so a tool never reaches the real database by default.
 * "test" is PGTESTDATABASE from pg.env and must end in _test; "live" is
 * PGDATABASE. Credentials come from pg.env through facilities/lib/pgenv.php
 * and are never printed.
 */

require_once __DIR__ . '/../../facilities/lib/db.php';

function pgcli_arg(array $argv, string $name, ?string $default = null): ?string
{
    foreach ($argv as $i => $a) {
        if ($a === '--' . $name && isset($argv[$i + 1])) {
            return $argv[$i + 1];
        }
        if (str_starts_with($a, '--' . $name . '=')) {
            return substr($a, strlen($name) + 3);
        }
    }
    return $default;
}

function pgcli_flag(array $argv, string $name): bool
{
    return in_array('--' . $name, $argv, true);
}

/** The database name for --database test|live; exits 2 on anything else. */
function pgcli_database(array $argv): string
{
    $which = pgcli_arg($argv, 'database');
    $env = pg_env();
    if ($which === 'test') {
        $db = (string)($env['PGTESTDATABASE'] ?? '');
        if (!preg_match('/_test$/', $db)) {
            fwrite(STDERR, "Refusing to run: PGTESTDATABASE in pg.env does not end in _test.\n");
            exit(2);
        }
        return $db;
    }
    if ($which === 'live') {
        return (string)$env['PGDATABASE'];
    }
    fwrite(STDERR, "Name the target: --database test (PGTESTDATABASE) or --database live (PGDATABASE).\n");
    exit(2);
}

/**
 * The login that owns the schema. On Radiant the logins split (owner,
 * 2026-10-12): <app>_owner runs migrations, <app>_app (PGUSER) has row
 * rights only. PG_OWNER_USER and PG_OWNER_PASSWORD in the environment name
 * the owner; without them the owner is PGUSER, as on the drive cluster.
 */
function pgcli_owner(): array
{
    $env = pg_env();
    $u = trim((string)(getenv('PG_OWNER_USER') ?: ''));
    if ($u === '') {
        return [$env['PGUSER'], $env['PGPASSWORD']];
    }
    $p = (string)(getenv('PG_OWNER_PASSWORD') ?: '');
    if ($p === '') {
        fwrite(STDERR, "PG_OWNER_USER is set but PG_OWNER_PASSWORD is not.
");
        exit(2);
    }
    return [$u, $p];
}

/** A plain connection (no translation) to $db, search_path public; $owner logs in as the schema owner. */
function pgcli_connect(string $db, bool $owner = false): PDO
{
    $env = pg_env();
    [$user, $password] = $owner ? pgcli_owner() : [$env['PGUSER'], $env['PGPASSWORD']];
    $pdo = new PDO('pgsql:host=' . $env['PGHOST'] . ';port=' . (int)$env['PGPORT'] . ';dbname=' . $db
                   . ';connect_timeout=45', $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("SET TIME ZONE '" . str_replace("'", "''", date_default_timezone_get()) . "'");
    return $pdo;
}

/** The application timezone without needing config.php (CLI tools before install). */
function pgcli_timezone(): void
{
    try {
        app_config();
    } catch (Throwable $e) {
        app_set_timezone(APP_DEFAULT_TIMEZONE);
    }
}
