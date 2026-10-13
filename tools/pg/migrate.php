<?php
declare(strict_types=1);

/**
 * Numbered PostgreSQL migrations for PRI Facilities Request.
 *
 *   php tools/pg/migrate.php --database test|live [--status] [--dry]
 *   php tools/pg/migrate.php --database test --reset     (test only: drop the
 *                                                         ten schemas and the
 *                                                         registry first)
 *
 * Files: migrations/pgsql/NNNN_name.sql, applied in number order, each in one
 * transaction with its registry row (PostgreSQL rolls DDL back with it).
 * Registry: public.schema_migrations(version, name, checksum, applied_at,
 * status, error). A failure is recorded and stops the run; an applied file
 * whose checksum changed, or that disappeared, stops the run before anything
 * else happens (drez-append-only-migrations rules 1, 3, 4, 5 and 7).
 *
 * Exit codes: 0 done, 1 a migration failed or a checksum changed, 2 usage.
 */

require_once __DIR__ . '/pgcli.php';

pgcli_timezone();
$db = pgcli_database($argv);
$dir = dirname(__DIR__, 2) . '/migrations/pgsql';
$pdo = pgcli_connect($db, true);
$say = fn(string $s) => print($s . PHP_EOL);

if (pgcli_flag($argv, 'reset')) {
    if (!preg_match('/_test$/', $db)) {
        fwrite(STDERR, "Refusing --reset on $db: only a database ending in _test is ever emptied.\n");
        exit(2);
    }
    foreach (APP_DB_NAMES as $reg) {
        $pdo->exec('DROP SCHEMA IF EXISTS ' . $reg . ' CASCADE');
    }
    $pdo->exec('DROP TABLE IF EXISTS public.schema_migrations');
    $say("reset: the ten register schemas and the registry are gone from $db");
}

$pdo->exec('CREATE TABLE IF NOT EXISTS public.schema_migrations (
    version    text PRIMARY KEY,
    name       text NOT NULL,
    checksum   text NOT NULL,
    applied_at timestamp NOT NULL DEFAULT LOCALTIMESTAMP,
    status     text NOT NULL,
    error      text
)');

$files = [];
foreach (scandir($dir) ?: [] as $f) {
    if (preg_match('/^(\d{4})_([a-z0-9_]+)\.sql$/', $f, $m)) {
        if (isset($files[$m[1]])) {
            fwrite(STDERR, "Two migrations claim number {$m[1]}.\n");
            exit(2);
        }
        $files[$m[1]] = ['name' => $m[2], 'file' => $dir . '/' . $f];
    }
}
ksort($files, SORT_STRING);
$applied = [];
foreach ($pdo->query('SELECT version, name, checksum, status FROM public.schema_migrations') as $r) {
    $applied[$r['version']] = $r;
}
foreach ($applied as $v => $r) {
    if ($r['status'] !== 'ok') {
        continue;
    }
    if (!isset($files[$v])) {
        fwrite(STDERR, "migration $v ({$r['name']}) was applied but its file is gone; restore it.\n");
        exit(1);
    }
    $sum = hash('sha256', str_replace("\r\n", "\n", (string)file_get_contents($files[$v]['file'])));
    if ($sum !== $r['checksum']) {
        fwrite(STDERR, "migration $v changed after it was applied; put the change in a new migration.\n");
        exit(1);
    }
}
$pending = array_filter($files, fn($v) => ($applied[$v]['status'] ?? '') !== 'ok', ARRAY_FILTER_USE_KEY);
$say("database $db: " . count($files) . ' declared, ' . (count($files) - count($pending)) . ' applied, '
    . count($pending) . ' pending');
if (pgcli_flag($argv, 'status') || pgcli_flag($argv, 'dry')) {
    foreach ($pending as $v => $f) {
        $say("  pending $v {$f['name']}");
    }
    exit(0);
}
foreach ($pending as $v => $f) {
    $sql = str_replace("\r\n", "\n", (string)file_get_contents($f['file']));
    $sum = hash('sha256', $sql);
    try {
        $pdo->beginTransaction();
        $pdo->exec($sql);
        $pdo->prepare('DELETE FROM public.schema_migrations WHERE version = ?')->execute([$v]);
        $pdo->prepare("INSERT INTO public.schema_migrations (version, name, checksum, status) VALUES (?, ?, ?, 'ok')")
            ->execute([$v, $f['name'], $sum]);
        $pdo->commit();
        $say("  applied $v {$f['name']}");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->prepare('DELETE FROM public.schema_migrations WHERE version = ?')->execute([$v]);
        $pdo->prepare("INSERT INTO public.schema_migrations (version, name, checksum, status, error) VALUES (?, ?, ?, 'failed', ?)")
            ->execute([$v, $f['name'], $sum, substr($e->getMessage(), 0, 2000)]);
        fwrite(STDERR, "  FAILED $v {$f['name']}: " . $e->getMessage() . "\n");
        exit(1);
    }
}
// Radiant: the owner login ran the migrations, so the app login (PGUSER)
// gets row rights on every register schema, now and for tables made later.
[$ownerUser] = pgcli_owner();
$appUser = (string)pg_env()['PGUSER'];
if (strcasecmp($ownerUser, $appUser) !== 0) {
    $role = '"' . str_replace('"', '""', $appUser) . '"';
    foreach (APP_DB_NAMES as $reg) {
        $pdo->exec("GRANT USAGE ON SCHEMA $reg TO $role");
        $pdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA $reg TO $role");
        $pdo->exec("GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA $reg TO $role");
        $pdo->exec("ALTER DEFAULT PRIVILEGES IN SCHEMA $reg GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO $role");
        $pdo->exec("ALTER DEFAULT PRIVILEGES IN SCHEMA $reg GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO $role");
    }
    $pdo->exec("GRANT SELECT ON public.schema_migrations TO $role");
    $say('grants: row rights on the ten register schemas for the app login');
}
$say('migrations: done');
