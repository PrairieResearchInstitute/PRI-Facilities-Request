<?php
declare(strict_types=1);

/**
 * Verified backups of the PostgreSQL registers: the PostgreSQL side of
 * tools/backup.ps1, which backs up the ten LocalDB databases.
 *
 *   php tools/pg/backup.php --database live|test [--out <folder>]
 *   php tools/pg/backup.php --database test --drill <file.dump>
 *
 * A backup is one pg_dump file in custom format holding the ten register
 * schemas and the migrations registry, with the exact row count of every
 * table recorded beside it at the moment of the dump (one transaction, so
 * the counts and the dump see the same data). It is verified by reading
 * the file back with pg_restore --list: every table must have its data
 * entry. A file that fails is deleted, because a backup nobody can restore
 * is worse than none (the rule backup.ps1 already follows).
 *
 * --drill restores a dump into the TEST database only (its ten schemas
 * and registry are dropped first) and compares every table's row count
 * with the record taken at backup time.
 *
 * Default folder: <data folder>\backups\pg, beside the LocalDB backups
 * (D-007). pg_dump and pg_restore come from the PostgreSQL runtime on the
 * drive, <estate>\runtime\postgresql\17\bin (tools/pg/estate.php, D-011),
 * never from PATH, so no PostgreSQL installed on the computer is used.
 * The drive's cluster must be running (tools/pg/cluster.php start).
 * Credentials go to them through the environment, read from pg.env, and
 * are never printed or written.
 *
 * Exit codes: 0 verified, 1 failed (nothing kept), 2 usage.
 */

require_once __DIR__ . '/pgcli.php';
require_once __DIR__ . '/estate.php';

pgcli_timezone();
$db = pgcli_database($argv);
$drill = pgcli_arg($argv, 'drill');
$say = fn(string $s) => print($s . PHP_EOL);

/** Full path of a PostgreSQL client program in the drive's runtime, or exit 2. */
function backup_program(string $name): string
{
    try {
        $exe = estate_pg_program($name);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(2);
    }
    if (!is_file($exe)) {
        fwrite(STDERR, "$name was not found in the drive's PostgreSQL runtime ($exe).\n");
        exit(2);
    }
    return $exe;
}

/** Run a PostgreSQL client with the credentials in its environment; [exit, stdout, stderr]. */
function backup_run(array $cmd, string $db): array
{
    $env = pg_env();
    $vars = getenv();
    $vars['PGHOST'] = $env['PGHOST'];
    $vars['PGPORT'] = (string)$env['PGPORT'];
    $vars['PGUSER'] = $env['PGUSER'];
    $vars['PGPASSWORD'] = $env['PGPASSWORD'];
    $vars['PGDATABASE'] = $db;
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $vars, ['bypass_shell' => true]);
    if (!is_resource($p)) {
        return [1, '', 'could not start ' . basename($cmd[0])];
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($p), (string)$out, (string)$err];
}

/** Exact row count of every table in the ten register schemas. */
function backup_counts(PDO $pg): array
{
    $names = implode(', ', array_map(fn($r) => $pg->quote($r), APP_DB_NAMES));
    $tables = $pg->query("SELECT table_schema || '.' || table_name FROM information_schema.tables
                           WHERE table_schema IN ($names) AND table_type = 'BASE TABLE'
                           ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($tables as $t) {
        $out[$t] = (int)$pg->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    }
    return $out;
}

$schemaArgs = [];
foreach (APP_DB_NAMES as $r) {
    $schemaArgs[] = '--schema=' . $r;
}
$schemaArgs[] = '--schema=public';             // the migrations registry; -t would silence every -n

// ------------------------------------------------------------------ drill ---
if ($drill !== null) {
    if (!preg_match('/_test$/', $db)) {
        fwrite(STDERR, "Refusing --drill on $db: a drill restores into the test database only.\n");
        exit(2);
    }
    $counts = preg_replace('/\.dump$/', '.rowcounts.json', $drill);
    if (!is_file($drill) || !is_file($counts)) {
        fwrite(STDERR, "--drill needs a .dump file and its .rowcounts.json beside it.\n");
        exit(2);
    }
    $expected = json_decode((string)file_get_contents($counts), true);
    $pg = pgcli_connect($db);
    foreach (APP_DB_NAMES as $r) {
        $pg->exec('DROP SCHEMA IF EXISTS ' . $r . ' CASCADE');
    }
    $pg->exec('DROP TABLE IF EXISTS public.schema_migrations');
    // Restore the ten schemas and the registry only: the public schema
    // itself, and anything else in it, already exist in any database.
    [$code, $list, $err] = backup_run([backup_program('pg_restore'), '--list', $drill], $db);
    if ($code !== 0) {
        $say('DRILL FAILED: pg_restore could not read the file: ' . trim(explode("\n", $err)[0]));
        exit(1);
    }
    $keep = '/ (' . implode('|', APP_DB_NAMES) . ') |\bschema_migrations\b/';
    $lines = array_filter(preg_split('/\r\n|\n/', $list), fn($l) => $l !== '' && ($l[0] === ';' || preg_match($keep, $l)));
    $listFile = $drill . '.restore-list';
    file_put_contents($listFile, implode(PHP_EOL, $lines) . PHP_EOL);
    [$code, , $err] = backup_run([backup_program('pg_restore'), '--no-owner', '--no-privileges', '--exit-on-error',
                                  '--use-list=' . $listFile, '--dbname=' . $db, $drill], $db);
    @unlink($listFile);
    if ($code !== 0) {
        $say('DRILL FAILED: pg_restore exited ' . $code . ': ' . trim(explode("\n", $err)[0]));
        exit(1);
    }
    $got = backup_counts($pg);
    $bad = [];
    foreach ($expected as $t => $n) {
        if (($got[$t] ?? -1) !== (int)$n) {
            $bad[] = "$t: expected $n, restored " . ($got[$t] ?? 'missing');
        }
    }
    foreach (array_diff_key($got, $expected) as $t => $n) {
        $bad[] = "$t: restored but not in the record";
    }
    if ($bad) {
        foreach ($bad as $b) {
            $say("DIFF: $b");
        }
        $say('DRILL FAILED: ' . count($bad) . ' table(s) differ.');
        exit(1);
    }
    $say('DRILL PASSED: ' . count($expected) . ' tables, ' . array_sum($expected)
        . ' rows, every count matches the record taken at backup time.');
    exit(0);
}

// ----------------------------------------------------------------- backup ---
$dir = pgcli_arg($argv, 'out') ?? (data_root() . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'pg');
if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
    fwrite(STDERR, "Could not make the backup folder.\n");
    exit(1);
}
$stamp = date('Y-m-d_His');
$file = $dir . DIRECTORY_SEPARATOR . $db . '_' . $stamp . '.dump';
$countFile = preg_replace('/\.dump$/', '.rowcounts.json', $file);

// The counts and the dump read one snapshot: the counts run in a
// repeatable-read transaction whose snapshot pg_dump then shares.
$pg = pgcli_connect($db);
$pg->exec('BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
$snapshot = (string)$pg->query('SELECT pg_export_snapshot()')->fetchColumn();
$counts = backup_counts($pg);
[$code, , $err] = backup_run(array_merge([backup_program('pg_dump'), '--format=custom', '--no-owner',
                                           '--no-privileges', '--snapshot=' . $snapshot, '--file=' . $file],
                                          $schemaArgs), $db);
$pg->exec('COMMIT');
$fail = function (string $why) use ($file, $countFile, $say): void {
    @unlink($file);
    @unlink($countFile);
    $say('BACKUP FAILED: ' . $why . ' Nothing was kept.');
    exit(1);
};
if ($code !== 0 || !is_file($file)) {
    $fail('pg_dump exited ' . $code . ': ' . trim(explode("\n", $err)[0]) . '.');
}
file_put_contents($countFile, json_encode($counts, JSON_PRETTY_PRINT) . PHP_EOL);

[$code, $list, $err] = backup_run([backup_program('pg_restore'), '--list', $file], $db);
if ($code !== 0) {
    $fail('pg_restore could not read the file back: ' . trim(explode("\n", $err)[0]) . '.');
}
$missing = [];
foreach (array_keys($counts) as $t) {
    [$schema, $table] = explode('.', $t, 2);
    if (!preg_match('/TABLE DATA ' . preg_quote($schema, '/') . ' ' . preg_quote($table, '/') . ' /', $list)) {
        $missing[] = $t;
    }
}
if ($missing) {
    $fail(count($missing) . ' table(s) have no data entry in the file, e.g. ' . implode(', ', array_slice($missing, 0, 3)) . '.');
}
$say('BACKUP VERIFIED: ' . basename($file) . ', ' . count($counts) . ' tables, ' . array_sum($counts)
    . ' rows, ' . round(filesize($file) / 1048576, 2) . ' MB; row counts in ' . basename($countFile) . '.');
exit(0);
