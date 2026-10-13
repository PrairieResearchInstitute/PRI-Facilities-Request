<?php
declare(strict_types=1);

/**
 * Carry out one round of the split, from tools/split_plan.php.
 *
 * Copies and verifies; drops nothing. The same two-pass discipline as the
 * first round: prove the copy, run the application against it, and only
 * then remove the original.
 *
 *   php tools/split_run.php space
 *   php tools/split_run.php space --apply
 *   php tools/split_run.php equipment --apply
 */

require_once __DIR__ . '/../facilities/lib/db.php';
db_localdb_only('tools/split_run.php');
require_once __DIR__ . '/split_plan.php';

$round = $argv[1] ?? '';
$apply = in_array('--apply', $argv, true);

if (!isset(SPLIT_ROUNDS[$round])) {
    fwrite(STDERR, "usage: split_run.php <" . implode('|', array_keys(SPLIT_ROUNDS)) . "> [--apply]\n");
    exit(1);
}

[$dbName, $purpose] = SPLIT_DATABASES[$round];
$moves = SPLIT_ROUNDS[$round];

function say(string $s): void { echo $s . "\n"; }

function raw(string $database): PDO
{
    $base = app_config()['db']['work'];
    $base['database'] = $database;
    return db_connect($base);
}

function server(): PDO
{
    $base = app_config()['db']['work'];
    $base['database'] = 'master';
    return db_connect($base);
}

function quoted(string $table): string
{
    [$s, $t] = explode('.', $table, 2);
    return '[' . str_replace(']', ']]', $s) . '].[' . str_replace(']', ']]', $t) . ']';
}

say($apply ? "Splitting out $dbName." : "Dry run for $dbName. Add --apply to do it.");
say('  ' . $purpose);
say('');

// ---- the database itself ---------------------------------------------
$exists = (int)server()->query(
    'SELECT COUNT(*) FROM sys.databases WHERE name = ' . server()->quote($dbName))->fetchColumn();
if (!$exists) {
    say(($apply ? 'creating ' : 'would create ') . $dbName);
    if ($apply) {
        server()->exec('CREATE DATABASE [' . $dbName . ']');
    }
} else {
    say("$dbName already exists.");
}

$target = $apply ? raw($dbName) : null;
if ($apply) {
    foreach (['app', 'ref', 'rq', 'gis', 'sh', 'wr', 'mv'] as $schema) {
        $has = (int)$target->query(
            'SELECT COUNT(*) FROM sys.schemas WHERE name = ' . $target->quote($schema))->fetchColumn();
        if (!$has) {
            $target->exec("CREATE SCHEMA [$schema]");
        }
    }
}

/** Structure, identity, defaults, keys, checks, indexes - then the rows. */
function copyTable(PDO $target, string $sourceDb, string $table, bool $emptyOnly): array
{
    $q = quoted($table);
    [$schema, $name] = explode('.', $table, 2);

    $already = (int)$target->query(
        'SELECT COUNT(*) FROM sys.tables t JOIN sys.schemas s ON s.schema_id = t.schema_id
          WHERE s.name = ' . $target->quote($schema) . ' AND t.name = ' . $target->quote($name)
    )->fetchColumn();
    if ($already) {
        return ['skipped' => true,
                'rows' => (int)$target->query("SELECT COUNT(*) FROM $q")->fetchColumn()];
    }

    $target->exec("SELECT * INTO $q FROM [$sourceDb].$q" . ($emptyOnly ? ' WHERE 1 = 0' : ''));
    $src = raw($sourceDb);

    foreach ($src->query(
        "SELECT c.name AS col, d.definition AS def
           FROM sys.default_constraints d
           JOIN sys.columns c ON c.object_id = d.parent_object_id
                             AND c.column_id = d.parent_column_id
          WHERE d.parent_object_id = OBJECT_ID('$table')") as $d) {
        $target->exec("ALTER TABLE $q ADD DEFAULT {$d['def']} FOR [{$d['col']}]");
    }

    $cols = "STUFF((SELECT ', [' + c2.name + ']' + CASE WHEN ic2.is_descending_key = 1
                        THEN ' DESC' ELSE '' END
                      FROM sys.index_columns ic2
                      JOIN sys.columns c2 ON c2.object_id = ic2.object_id
                                         AND c2.column_id = ic2.column_id
                     WHERE ic2.object_id = i.object_id AND ic2.index_id = i.index_id
                       AND ic2.is_included_column = 0
                     ORDER BY ic2.key_ordinal FOR XML PATH('')), 1, 2, '') AS cols";

    foreach ($src->query(
        "SELECT i.name, i.is_primary_key, $cols FROM sys.indexes i
          WHERE i.object_id = OBJECT_ID('$table')
            AND (i.is_primary_key = 1 OR i.is_unique_constraint = 1)") as $k) {
        $kind = (int)$k['is_primary_key'] === 1 ? 'PRIMARY KEY' : 'UNIQUE';
        $target->exec("ALTER TABLE $q ADD CONSTRAINT [{$k['name']}] $kind ({$k['cols']})");
    }

    foreach ($src->query(
        "SELECT name, definition FROM sys.check_constraints
          WHERE parent_object_id = OBJECT_ID('$table')") as $c) {
        $target->exec("ALTER TABLE $q ADD CONSTRAINT [{$c['name']}] CHECK {$c['definition']}");
    }

    foreach ($src->query(
        "SELECT i.name, i.is_unique, $cols FROM sys.indexes i
          WHERE i.object_id = OBJECT_ID('$table')
            AND i.is_primary_key = 0 AND i.is_unique_constraint = 0
            AND i.type_desc <> 'HEAP' AND i.name IS NOT NULL") as $x) {
        $unique = (int)$x['is_unique'] === 1 ? 'UNIQUE ' : '';
        $target->exec("CREATE {$unique}INDEX [{$x['name']}] ON $q ({$x['cols']})");
    }

    return ['skipped' => false,
            'rows' => (int)$target->query("SELECT COUNT(*) FROM $q")->fetchColumn()];
}

// The scaffolding first, then what actually moves.
$work = [];
foreach (SPLIT_SCAFFOLD as $t) {
    $work[] = ['work', $t, false];
}
foreach ($moves as [$from, $t]) {
    $work[] = [$from, $t, true];
}

$seen = [];
$problems = 0;
foreach ($work as [$from, $table, $moving]) {
    if (isset($seen[$table])) {
        continue;
    }
    $seen[$table] = true;
    $sourceDb = APP_DB_LABELS[$from];
    $emptyOnly = in_array($table, SPLIT_STRUCTURE_ONLY, true);

    if (!$apply) {
        $n = (int)db($from)->query('SELECT COUNT(*) FROM ' . quoted($table))->fetchColumn();
        say(sprintf('  %-28s %-6s %s', $table, $moving ? 'MOVES' : ($emptyOnly ? 'shape' : 'copy'),
            $emptyOnly ? 'structure only' : number_format($n) . ' rows'));
        continue;
    }

    try {
        $res = copyTable($target, $sourceDb, $table, $emptyOnly);
        $was = (int)db($from)->query('SELECT COUNT(*) FROM ' . quoted($table))->fetchColumn();
        $want = $emptyOnly ? 0 : $was;
        $ok = $res['skipped'] ? 'already there' : ($res['rows'] === $want ? 'ok' : 'ROW COUNT DIFFERS');
        if ($ok === 'ROW COUNT DIFFERS') {
            $problems++;
        }
        say(sprintf('  %-28s %-6s %8s rows  %s', $table,
            $moving ? 'MOVES' : ($emptyOnly ? 'shape' : 'copy'),
            number_format((int)$res['rows']), $ok));
    } catch (Throwable $e) {
        $problems++;
        say(sprintf('  %-28s FAILED: %s', $table, substr($e->getMessage(), 0, 110)));
    }
}

say('');
if ($apply) {
    say($problems === 0
        ? "Copied. Nothing dropped - the originals are still there and still being read."
        : "$problems problem(s). Look before going further.");
    say('Next: tools/replay_foreign_keys.php, then repoint the code, then verify, then drop.');
}
