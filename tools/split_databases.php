<?php
declare(strict_types=1);

/**
 * Give purchasing, keys and the fleet databases of their own.
 *
 * These are live programmes that get audited on their own terms, and
 * "it is a schema inside the work database" is not an answer when
 * somebody asks for the purchasing log. So they move out.
 *
 * They stay cross-referenced. Nothing here creates a foreign key across
 * a database boundary - that has never been allowed and still is not -
 * so the joins run on the natural keys they already run on: RoomCode,
 * BldgNumber, NetID, FleetNumber, PropertyTag, TicketNumber, CfopCode.
 * Those are stable, human-readable, and mean the same thing everywhere.
 *
 * The move is done in two passes on purpose. This one copies and
 * verifies; nothing is dropped. drop_moved_tables.php removes the
 * originals afterwards, once the counts have been read by a person.
 *
 *   php tools/split_databases.php            what it would do
 *   php tools/split_databases.php --apply    do it
 */

require_once __DIR__ . '/../facilities/lib/db.php';
db_localdb_only('tools/split_databases.php');

$apply = in_array('--apply', $argv, true);

// ---------------------------------------------------------------- plan
const NEW_DATABASES = [
    'purchasing' => 'PRI_FacilitiesPurchasing',
    'keys'       => 'PRI_FacilitiesKeys',
    'fleet'      => 'PRI_FacilitiesFleet',
];

/**
 * What moves, and where from. Schema and table names do not change - only
 * which database they are in - so every query in the application keeps
 * the same SQL and changes only the connection it runs on.
 */
const MOVES = [
    'purchasing' => [
        ['work', 'rq.PurchaseRequests'],
        ['work', 'rq.PurchaseItems'],
        ['work', 'rq.PurchaseFunding'],
        ['work', 'rq.PurchaseEvents'],
        ['work', 'ref.BannerAccountCodes'],
    ],
    'keys' => [
        ['work', 'rq.KeyRequests'],
        ['work', 'gis.RoomKeys'],
        ['work', 'gis.KeyEvents'],
    ],
    'fleet' => [
        ['shop', 'ref.FleetVehicles'],
        ['shop', 'ref.DriverFormTypes'],
        ['shop', 'sh.DriverForms'],
        ['shop', 'sh.VehicleBookings'],
        ['shop', 'sh.VehicleReadings'],
    ],
];

/**
 * The scaffolding every database in this system has: who the people are,
 * what the buildings are called, where the settings live, and the places
 * notifications and history are written. Copied from Work, which is the
 * canonical copy, and kept current afterwards by the reference publish.
 */
const SCAFFOLD = [
    'app.Users', 'app.Settings', 'app.NotifyRules', 'app.AdminAccess', 'app.DigestPreferences',
    'app.Notifications', 'app.Attachments', 'app.AuditLog', 'app.StatusHistory', 'app.Notes',
    'ref.Buildings', 'ref.BuildingAliases', 'ref.Rooms', 'ref.People', 'ref.Surveys',
    'ref.Owners', 'ref.StatusFlow', 'ref.PickLists', 'ref.PickListTypes',
];

/**
 * Copied for their shape, not their contents. These are each database's
 * own log - what it sent, what changed in it, what was attached to it -
 * and Work's history is Work's. Duplicating it into three more places
 * would put the same notification in four databases and make any count
 * of them a lie.
 *
 * app.Counters is absent on purpose. Ticket numbers come from one place
 * or they collide, and that place is Work.
 */
const STRUCTURE_ONLY = [
    'app.Notifications', 'app.Attachments', 'app.AuditLog', 'app.StatusHistory', 'app.Notes',
];

/** Purchasing asks accounts who their approvers are, so it needs them locally. */
const EXTRA_SCAFFOLD = [
    'purchasing' => [['shop', 'ref.CfopAccounts']],
    'keys'       => [],
    'fleet'      => [['work', 'ref.PickLists'], ['work', 'ref.PickListTypes']],
];

function say(string $s): void
{
    echo $s . "\n";
}

/** A connection to one of the new databases, which app_config does not know yet. */
function raw(string $database): PDO
{
    $base = app_config()['db']['work'];
    $base['database'] = $database;
    return db_connect($base);
}

/** A connection with no database selected, for CREATE DATABASE. */
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

function sourceDatabase(string $key): string
{
    return APP_DB_LABELS[$key];
}

// ------------------------------------------------------------- the run
say($apply ? 'Splitting the databases.' : 'Dry run. Nothing will be changed. Add --apply to do it.');
say('');

foreach (NEW_DATABASES as $key => $dbName) {
    $exists = (int)server()->query(
        "SELECT COUNT(*) FROM sys.databases WHERE name = " . server()->quote($dbName))->fetchColumn();
    if ($exists) {
        say("$dbName already exists.");
    } else {
        say(($apply ? 'creating ' : 'would create ') . $dbName);
        if ($apply) {
            server()->exec('CREATE DATABASE [' . $dbName . ']');
        }
    }
}
say('');

/**
 * Copy one table, with its identity, defaults, keys, checks and indexes.
 *
 * SELECT INTO carries the columns, their types and the identity property,
 * and nothing else - so everything else is read off the source and
 * replayed. A table copied without its defaults looks right until the
 * first insert that omits a column.
 */
function copyTable(PDO $target, string $targetDb, string $sourceDb, string $table,
                   bool $apply): array
{
    $q = quoted($table);
    [$schema, $name] = explode('.', $table, 2);

    $already = (int)$target->query(
        "SELECT COUNT(*) FROM sys.tables t JOIN sys.schemas s ON s.schema_id = t.schema_id
          WHERE s.name = " . $target->quote($schema) . " AND t.name = " . $target->quote($name)
    )->fetchColumn();
    if ($already) {
        $n = (int)$target->query("SELECT COUNT(*) FROM $q")->fetchColumn();
        return ['skipped' => true, 'rows' => $n];
    }
    if (!$apply) {
        return ['skipped' => false, 'rows' => null];
    }

    $empty = in_array($table, STRUCTURE_ONLY, true);
    $target->exec("SELECT * INTO $q FROM [$sourceDb].$q" . ($empty ? ' WHERE 1 = 0' : ''));

    // --- defaults -----------------------------------------------------
    $src = raw($sourceDb);
    $defaults = $src->query(
        "SELECT c.name AS col, d.definition AS def
           FROM sys.default_constraints d
           JOIN sys.columns c ON c.object_id = d.parent_object_id AND c.column_id = d.parent_column_id
          WHERE d.parent_object_id = OBJECT_ID('$table')")->fetchAll();
    foreach ($defaults as $d) {
        $target->exec("ALTER TABLE $q ADD DEFAULT {$d['def']} FOR [{$d['col']}]");
    }

    // --- primary key and unique constraints ---------------------------
    $keys = $src->query(
        "SELECT i.name, i.is_primary_key, i.is_unique_constraint,
                STUFF((SELECT ', [' + c2.name + ']' + CASE WHEN ic2.is_descending_key = 1
                          THEN ' DESC' ELSE '' END
                         FROM sys.index_columns ic2
                         JOIN sys.columns c2 ON c2.object_id = ic2.object_id
                                            AND c2.column_id = ic2.column_id
                        WHERE ic2.object_id = i.object_id AND ic2.index_id = i.index_id
                          AND ic2.is_included_column = 0
                        ORDER BY ic2.key_ordinal
                        FOR XML PATH('')), 1, 2, '') AS cols
           FROM sys.indexes i
          WHERE i.object_id = OBJECT_ID('$table')
            AND (i.is_primary_key = 1 OR i.is_unique_constraint = 1)")->fetchAll();
    foreach ($keys as $k) {
        $kind = (int)$k['is_primary_key'] === 1 ? 'PRIMARY KEY' : 'UNIQUE';
        $target->exec("ALTER TABLE $q ADD CONSTRAINT [{$k['name']}] $kind ({$k['cols']})");
    }

    // --- check constraints --------------------------------------------
    $checks = $src->query(
        "SELECT name, definition FROM sys.check_constraints
          WHERE parent_object_id = OBJECT_ID('$table')")->fetchAll();
    foreach ($checks as $c) {
        $target->exec("ALTER TABLE $q ADD CONSTRAINT [{$c['name']}] CHECK {$c['definition']}");
    }

    // --- the ordinary indexes -----------------------------------------
    $indexes = $src->query(
        "SELECT i.name, i.is_unique,
                STUFF((SELECT ', [' + c2.name + ']' + CASE WHEN ic2.is_descending_key = 1
                          THEN ' DESC' ELSE '' END
                         FROM sys.index_columns ic2
                         JOIN sys.columns c2 ON c2.object_id = ic2.object_id
                                            AND c2.column_id = ic2.column_id
                        WHERE ic2.object_id = i.object_id AND ic2.index_id = i.index_id
                          AND ic2.is_included_column = 0
                        ORDER BY ic2.key_ordinal
                        FOR XML PATH('')), 1, 2, '') AS cols
           FROM sys.indexes i
          WHERE i.object_id = OBJECT_ID('$table')
            AND i.is_primary_key = 0 AND i.is_unique_constraint = 0
            AND i.type_desc <> 'HEAP' AND i.name IS NOT NULL")->fetchAll();
    foreach ($indexes as $x) {
        $unique = (int)$x['is_unique'] === 1 ? 'UNIQUE ' : '';
        $target->exec("CREATE {$unique}INDEX [{$x['name']}] ON $q ({$x['cols']})");
    }

    $n = (int)$target->query("SELECT COUNT(*) FROM $q")->fetchColumn();
    return ['skipped' => false, 'rows' => $n, 'empty' => $empty];
}

$report = [];
foreach (NEW_DATABASES as $key => $dbName) {
    say("--- $dbName ---");
    $target = $apply ? raw($dbName) : null;

    if ($apply) {
        foreach (['app', 'ref', 'rq', 'gis', 'sh', 'wr', 'mv'] as $schema) {
            $has = (int)$target->query(
                "SELECT COUNT(*) FROM sys.schemas WHERE name = " . $target->quote($schema))->fetchColumn();
            if (!$has) {
                $target->exec("CREATE SCHEMA [$schema]");
            }
        }
    }

    $work = array_merge(
        array_map(fn($t) => ['work', $t], SCAFFOLD),
        EXTRA_SCAFFOLD[$key] ?? [],
        MOVES[$key]
    );

    // The same table can be named twice - PickLists is scaffolding and
    // also listed for the fleet - so each one is copied once.
    $seen = [];
    foreach ($work as [$fromKey, $table]) {
        if (isset($seen[$table])) {
            continue;
        }
        $seen[$table] = true;
        $sourceDb = sourceDatabase($fromKey);
        $moving = in_array([$fromKey, $table], MOVES[$key], true);

        if (!$apply) {
            $n = (int)db($fromKey)->query('SELECT COUNT(*) FROM ' . quoted($table))->fetchColumn();
            $how = $moving ? 'MOVES' : (in_array($table, STRUCTURE_ONLY, true) ? 'shape' : 'copy');
            say(sprintf('  %-28s %-9s from %-20s %s',
                $table, $how, $sourceDb,
                $how === 'shape' ? 'structure only' : number_format($n) . ' rows'));
            continue;
        }

        try {
            $res = copyTable($target, $dbName, $sourceDb, $table, true);
            $was = (int)db($fromKey)->query('SELECT COUNT(*) FROM ' . quoted($table))->fetchColumn();
            $want = in_array($table, STRUCTURE_ONLY, true) ? 0 : $was;
            $ok = $res['skipped'] ? 'already there'
                : ($res['rows'] === $want ? 'ok' : 'ROW COUNT DIFFERS');
            say(sprintf('  %-28s %-9s %6s rows  %s',
                $table, $moving ? 'MOVES' : 'copy', number_format((int)$res['rows']), $ok));
            $report[] = [$dbName, $table, $want, $res['rows'], $moving];
        } catch (Throwable $e) {
            say(sprintf('  %-28s FAILED: %s', $table, substr($e->getMessage(), 0, 120)));
        }
    }
    say('');
}

if ($apply) {
    $bad = array_filter($report, fn($r) => $r[2] !== $r[3]);
    say($bad === []
        ? 'Every table copied with the row count it started with.'
        : count($bad) . ' TABLES DIFFER IN ROW COUNT - look before dropping anything.');
    say('');
    say('Nothing has been dropped. The originals are still in place and still being read.');
    say('Next: update config.php and lib/db.php, run the application against the new');
    say('databases, and only then run tools/drop_moved_tables.php.');
}
