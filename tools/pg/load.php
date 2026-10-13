<?php
declare(strict_types=1);

/**
 * Load the ten registers into PostgreSQL and prove every row arrived.
 *
 * Switch-over (the owner, with the application closed and a fresh LocalDB
 * backup taken with tools\backup.ps1):
 *
 *   tools\php\php.exe -d extension=pdo_pgsql tools\pg\migrate.php --database live
 *   tools\php\php.exe -d extension=pdo_pgsql tools\pg\load.php --database live
 *
 * Source: the ten LocalDB databases named in config.php (the same
 * connections the application opens on LocalDB), read with SELECT only.
 * Target: the register schemas of PGDATABASE (live) or PGTESTDATABASE
 * (test). The whole load runs in ONE PostgreSQL transaction and commits only
 * after verification passes, so a failed load leaves the target as it was.
 *
 * What it checks before copying anything (exit 1 and nothing written):
 *  - every source table exists in the target register schema (a table the
 *    baseline does not know is a schema change nobody ported);
 *  - every source column exists in the target table;
 *  - every target column the source lacks is nullable or has a default;
 *  - the target tables are empty, unless --replace (which deletes their rows
 *    inside the same transaction first).
 *
 * Then: rows copied with their original identity values, foreign keys
 * deferred to the commit, every identity sequence set past the highest id
 * (drez-sqlite-to-postgres-port rule 8), and every row of every table
 * compared field by field with its source, matched on primary key: numbers
 * numerically, timestamps to the microsecond, text exactly (rule 9).
 * Ends with "VERIFIED: <n> rows across <m> tables match the source." and
 * exit 0, or "FAILED: ..." and exit 1.
 *
 * Options:
 *   --database test|live     the target (required)
 *   --replace                empty the target tables first (same transaction)
 *   --dry                    check shapes and counts only; write nothing
 *   --register <name>        one register only (repeatable)
 * Test only (refused unless the target database ends in _test):
 *   --from-pg <prefix>       read the register schemas of the target database
 *                            itself as the source; <prefix> "" means work, move...
 *   --target-prefix <p>      write into schemas <p>work, <p>move ... (created
 *                            from every migrations/pgsql file if missing)
 *   --sabotage               change one copied value before verification, to
 *                            prove verification fails and nothing commits
 *
 * Credentials: config.php for LocalDB, pg.env for PostgreSQL; nothing printed.
 */

require_once __DIR__ . '/pgcli.php';
require_once __DIR__ . '/../../facilities/lib/pg_catalog.php';
require_once __DIR__ . '/load_values.php';

pgcli_timezone();
$db = pgcli_database($argv);
$replace = pgcli_flag($argv, 'replace');
$dry = pgcli_flag($argv, 'dry');
$sabotage = pgcli_flag($argv, 'sabotage');
$fromPg = pgcli_arg($argv, 'from-pg');
$tPrefix = (string)pgcli_arg($argv, 'target-prefix', '');
$only = [];
foreach ($argv as $i => $a) {
    if ($a === '--register' && isset($argv[$i + 1])) {
        $only[] = $argv[$i + 1];
    }
}
$isTest = (bool)preg_match('/_test$/', $db);
if (($fromPg !== null || $tPrefix !== '' || $sabotage) && !$isTest) {
    fwrite(STDERR, "Refusing: --from-pg, --target-prefix and --sabotage are for a database ending in _test only.\n");
    exit(2);
}
if ($tPrefix !== '' && !preg_match('/^[a-z][a-z0-9_]*$/', $tPrefix)) {
    fwrite(STDERR, "--target-prefix takes lower-case letters, digits and underscores.\n");
    exit(2);
}
$registers = $only ?: APP_DB_NAMES;
foreach ($registers as $r) {
    if (!in_array($r, APP_DB_NAMES, true)) {
        fwrite(STDERR, "Unknown register $r.\n");
        exit(2);
    }
}
$say = fn(string $s) => print($s . PHP_EOL);

// ---------------------------------------------------------------- source ---

/** Read-only view of one register on either engine; keys in SQL Server spelling. */
interface LoadSource
{
    public function tables(string $reg): array;            // canonical 'schema.Table' list
    public function columns(string $reg, string $t): array; // canonical names, in order
    public function rows(string $reg, string $t, array $cols): iterable;
    public function label(): string;
}

final class LocalDbSource implements LoadSource
{
    private array $pdo = [];

    private function conn(string $reg): PDO
    {
        if (!isset($this->pdo[$reg])) {
            $config = app_config()['db'] ?? [];
            $d = $config[$reg] ?? null;
            if ($d === null) {
                if (!isset($config['work'])) {
                    throw new RuntimeException('config.php names no LocalDB connection for Work.');
                }
                $d = $config['work'];
                $d['database'] = APP_DB_LABELS[$reg];
            }
            $this->pdo[$reg] = db_connect($d);
        }
        return $this->pdo[$reg];
    }

    /** The one way this tool talks to LocalDB: a SELECT, or it refuses. */
    private function select(string $reg, string $sql, array $params = []): PDOStatement
    {
        if (!preg_match('/^\s*SELECT\b/i', $sql)) {
            throw new LogicException('The loader reads LocalDB with SELECT only.');
        }
        $st = $this->conn($reg)->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public function tables(string $reg): array
    {
        $out = [];
        foreach ($this->select($reg, "SELECT s.name AS s, t.name AS t FROM sys.tables t
                                         JOIN sys.schemas s ON s.schema_id = t.schema_id
                                        WHERE t.is_ms_shipped = 0 ORDER BY s.name, t.name") as $r) {
            $out[] = $r['s'] . '.' . $r['t'];
        }
        return $out;
    }

    public function columns(string $reg, string $t): array
    {
        return $this->select($reg, 'SELECT c.name FROM sys.columns c WHERE c.object_id = OBJECT_ID(?) AND c.is_computed = 0
                                    ORDER BY c.column_id', [$t])->fetchAll(PDO::FETCH_COLUMN);
    }

    public function rows(string $reg, string $t, array $cols): iterable
    {
        [$s, $n] = explode('.', $t, 2);
        $list = implode(', ', array_map(fn($c) => '[' . str_replace(']', ']]', $c) . ']', $cols));
        $st = $this->select($reg, "SELECT $list FROM [" . str_replace(']', ']]', $s) . '].[' . str_replace(']', ']]', $n) . ']');
        while (($r = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
            yield $r;
        }
    }

    public function label(): string
    {
        return 'LocalDB (config.php)';
    }
}

final class PgSource implements LoadSource
{
    public function __construct(private PDO $pdo, private string $prefix)
    {
    }

    public function tables(string $reg): array
    {
        $st = $this->pdo->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE'");
        $st->execute([$this->prefix . $reg]);
        return array_map('db_pg_canonical_table', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    public function columns(string $reg, string $t): array
    {
        $st = $this->pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position');
        $st->execute([$this->prefix . $reg, strtolower(str_replace('.', '_', $t))]);
        return array_map(fn($c) => PG_COLUMN_NAMES[$c] ?? $c, $st->fetchAll(PDO::FETCH_COLUMN));
    }

    public function rows(string $reg, string $t, array $cols): iterable
    {
        $list = implode(', ', array_map('strtolower', $cols));
        $st = $this->pdo->query("SELECT $list FROM " . $this->prefix . $reg . '.' . strtolower(str_replace('.', '_', $t)));
        while (($r = $st->fetch(PDO::FETCH_NUM)) !== false) {
            $row = [];
            foreach ($cols as $i => $c) {
                $row[$c] = $r[$i] === null ? null : (is_bool($r[$i]) ? (int)$r[$i] : (string)$r[$i]);
            }
            yield $row;
        }
    }

    public function label(): string
    {
        return 'PostgreSQL schemas ' . ($this->prefix === '' ? '' : $this->prefix) . '<register> (test)';
    }
}

// ---------------------------------------------------------------- target ---

$pg = pgcli_connect($db);
$source = $fromPg !== null ? new PgSource(pgcli_connect($db), $fromPg) : new LocalDbSource();
if ($fromPg !== null && $fromPg === $tPrefix) {
    fwrite(STDERR, "Source and target are the same schemas.\n");
    exit(2);
}
$say("source: " . $source->label());
$say("target: $db, schemas " . ($tPrefix !== '' ? $tPrefix : '') . '<register>' . ($dry ? ' (dry run)' : ''));

if ($tPrefix !== '') {
    // Test only: build the prefixed schemas, when missing, from every numbered
    // migration in order, so they match what migrate.php builds.
    $have = $pg->query("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = " . $pg->quote($tPrefix . 'work'))->fetchColumn();
    if (!(int)$have) {
        $mdir = dirname(__DIR__, 2) . '/migrations/pgsql';
        $mfiles = array_values(preg_grep('/^\d{4}_[a-z0-9_]+\.sql$/', scandir($mdir) ?: []));
        sort($mfiles, SORT_STRING);
        $alt = implode('|', APP_DB_NAMES);
        foreach ($mfiles as $mf) {
            $sql = (string)file_get_contents($mdir . '/' . $mf);
            $sql = preg_replace('/\bCREATE SCHEMA (' . $alt . ');/', 'CREATE SCHEMA ' . $tPrefix . '$1;', $sql);
            $sql = preg_replace('/(?<![\w.\'])(' . $alt . ')\.(?=[a-z_]+\b)/', $tPrefix . '$1.', $sql);
            $pg->exec($sql);
        }
        $say("built schemas {$tPrefix}work ... {$tPrefix}plans from " . count($mfiles) . ' migrations');
    }
}

/** Target column info: name => [data_type, nullable, has_default]. */
function target_columns(PDO $pg, string $schema, string $pgTable): array
{
    $st = $pg->prepare('SELECT column_name, data_type, is_nullable, column_default, is_identity
                          FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position');
    $st->execute([$schema, $pgTable]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[$r['column_name']] = ['type' => $r['data_type'], 'nullable' => $r['is_nullable'] === 'YES',
                                   'default' => $r['column_default'] !== null || $r['is_identity'] === 'YES'];
    }
    return $out;
}

function target_pk(PDO $pg, string $schema, string $pgTable): array
{
    $st = $pg->prepare("SELECT a.attname FROM pg_index i
                          JOIN pg_class c ON c.oid = i.indrelid JOIN pg_namespace n ON n.oid = c.relnamespace
                          JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum = ANY(i.indkey)
                         WHERE i.indisprimary AND n.nspname = ? AND c.relname = ? ORDER BY array_position(i.indkey, a.attnum)");
    $st->execute([$schema, $pgTable]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

// ------------------------------------------------------------ preflight ---

$plan = [];          // [reg, table, pgSchema, pgTable, cols, tcols, pk, identity]
$problems = [];
$notes = [];
foreach ($registers as $reg) {
    $schema = $tPrefix . $reg;
    $srcTables = $source->tables($reg);
    $tgtTables = [];
    $st = $pg->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE'");
    $st->execute([$schema]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $tgtTables[$t] = true;
    }
    if (!$tgtTables) {
        $problems[] = "$reg: the target has no schema $schema; run tools/pg/migrate.php first";
        continue;
    }
    foreach ($srcTables as $t) {
        $pgTable = strtolower(str_replace('.', '_', $t));
        if (!isset($tgtTables[$pgTable])) {
            $problems[] = "$reg: source table $t has no table in $schema (a schema change nobody ported)";
            continue;
        }
        $cols = $source->columns($reg, $t);
        $tcols = target_columns($pg, $schema, $pgTable);
        $missing = array_filter($cols, fn($c) => !isset($tcols[strtolower($c)]));
        if ($missing) {
            $problems[] = "$reg $t: source column(s) " . implode(', ', $missing) . ' missing in the target';
            continue;
        }
        $srcLower = array_map('strtolower', $cols);
        foreach ($tcols as $c => $info) {
            if (!in_array($c, $srcLower, true) && !$info['nullable'] && !$info['default']) {
                $problems[] = "$reg $t: target column $c is required but the source has no such column";
            } elseif (!in_array($c, $srcLower, true)) {
                $notes[] = "$reg $t: target column $c has no source column and stays " . ($info['default'] ? 'at its default' : 'NULL');
            }
        }
        $plan[] = [$reg, $t, $schema, $pgTable, $cols, $tcols, target_pk($pg, $schema, $pgTable), PG_IDENTITY_COLUMNS[$t] ?? null];
        unset($tgtTables[$pgTable]);
    }
    foreach (array_keys($tgtTables) as $left) {
        $notes[] = "$reg: target table $left has no source table and stays empty";
    }
}
foreach ($notes as $n) {
    $say("note: $n");
}
if ($problems) {
    foreach ($problems as $p) {
        $say("PROBLEM: $p");
    }
    $say('FAILED: ' . count($problems) . ' problem(s) before copying; nothing was written.');
    exit(1);
}
$nonEmpty = [];
foreach ($plan as [$reg, $t, $schema, $pgTable]) {
    $n = (int)$pg->query("SELECT COUNT(*) FROM $schema.$pgTable")->fetchColumn();
    if ($n > 0) {
        $nonEmpty[] = "$schema.$pgTable ($n)";
    }
}
if ($nonEmpty && !$replace && !$dry) {
    $say('FAILED: the target already holds rows in ' . count($nonEmpty) . ' table(s), e.g. ' . implode(', ', array_slice($nonEmpty, 0, 5))
        . '. Pass --replace to empty them inside the load transaction; nothing was written.');
    exit(1);
}
if ($dry) {
    $total = 0;
    foreach ($plan as [$reg, $t, , , $cols]) {
        $c = 0;
        foreach ($source->rows($reg, $t, [$cols[0]]) as $_) {
            $c++;
        }
        $total += $c;
    }
    $say('DRY RUN: ' . count($plan) . " tables, $total source rows; the shapes match; nothing was written.");
    exit(0);
}

// ----------------------------------------------------------------- load ---

$pg->beginTransaction();
try {
    $pg->exec('SET CONSTRAINTS ALL DEFERRED');
    if ($replace) {
        foreach (array_reverse($plan) as [, , $schema, $pgTable]) {
            $pg->exec("DELETE FROM $schema.$pgTable");
        }
    }
    $copied = 0;
    foreach ($plan as [$reg, $t, $schema, $pgTable, $cols, $tcols]) {
        $lc = array_map('strtolower', $cols);
        $types = array_map(fn($c) => $tcols[$c]['type'], $lc);
        $per = max(1, intdiv(30000, max(1, count($cols))));
        $batch = [];
        $flush = function () use (&$batch, $pg, $schema, $pgTable, $lc) {
            if (!$batch) {
                return;
            }
            $row = '(' . implode(', ', array_fill(0, count($lc), '?')) . ')';
            $sql = "INSERT INTO $schema.$pgTable (" . implode(', ', $lc) . ') OVERRIDING SYSTEM VALUE VALUES '
                 . implode(', ', array_fill(0, count($batch), $row));
            $params = [];
            foreach ($batch as $b) {
                foreach ($b as $v) {
                    $params[] = $v;
                }
            }
            $pg->prepare($sql)->execute($params);
            $batch = [];
        };
        foreach ($source->rows($reg, $t, $cols) as $r) {
            $vals = [];
            foreach ($cols as $i => $c) {
                $v = $r[$c];
                // PostgreSQL parses each by its column type, except that a
                // datetime2 with seven fractional digits is cut to six here,
                // half up: PostgreSQL rounds an exact half through a double
                // (.5099075 becomes .509907), which verification would flag.
                $vals[] = $v === null ? null
                        : ($types[$i] === 'timestamp without time zone' ? norm_value((string)$v, $types[$i]) : (string)$v);
            }
            $batch[] = $vals;
            $copied++;
            if (count($batch) >= $per) {
                $flush();
            }
        }
        $flush();
    }
    // Identity sequences past the highest loaded id.
    foreach ($plan as [$reg, $t, $schema, $pgTable, , , , $ident]) {
        if ($ident === null) {
            continue;
        }
        $col = strtolower($ident);
        $pg->query("SELECT setval(pg_get_serial_sequence('$schema.$pgTable', '$col'),
                           COALESCE((SELECT MAX($col) FROM $schema.$pgTable), 1),
                           (SELECT MAX($col) FROM $schema.$pgTable) IS NOT NULL)")->fetchColumn();
    }
    if ($sabotage) {
        // The first text value that accepts the change. Each attempt runs
        // under a savepoint, because a length or value check can refuse the
        // longer text, and the first table holding rows is not the same in
        // every run: round 2 of the suite once met a short BldgNumber first,
        // and the check constraint, not verification, stopped the load.
        $done = false;
        foreach ($plan as [$reg, $t, $schema, $pgTable, , $tcols, $pk]) {
            if (!$pk || (int)$pg->query("SELECT COUNT(*) FROM $schema.$pgTable")->fetchColumn() === 0) {
                continue;
            }
            foreach ($tcols as $c => $info) {
                if ($info['type'] !== 'USER-DEFINED' || in_array($c, $pk, true)) {
                    continue;
                }
                $pg->exec('SAVEPOINT load_sabotage');
                try {
                    $pg->exec("UPDATE $schema.$pgTable SET $c = COALESCE($c, '') || ' (sabotaged)'
                                WHERE " . $pk[0] . " = (SELECT MIN(" . $pk[0] . ") FROM $schema.$pgTable)");
                    $pg->exec('RELEASE SAVEPOINT load_sabotage');
                    $say("sabotage: changed $c in one row of $schema.$pgTable");
                    $done = true;
                    break 2;
                } catch (PDOException $e) {
                    $pg->exec('ROLLBACK TO SAVEPOINT load_sabotage');
                }
            }
        }
        if (!$done) {
            throw new RuntimeException('--sabotage found no text value it could change.');
        }
    }

    // ----------------------------------------------------------- verify ---
    $diffs = [];
    $rowsChecked = 0;
    foreach ($plan as [$reg, $t, $schema, $pgTable, $cols, $tcols, $pk]) {
        $lc = array_map('strtolower', $cols);
        $types = array_combine($lc, array_map(fn($c) => $tcols[$c]['type'], $lc));
        $keyCols = $pk ?: $lc;
        $keyOf = function (array $row) use ($keyCols, $types): string {
            $k = [];
            foreach ($keyCols as $c) {
                $v = norm_value($row[$c] ?? null, $types[$c]);
                $k[] = $v === null ? "\0" : mb_strtolower($v);
            }
            return implode("\x1f", $k);
        };
        $srcRows = [];
        foreach ($source->rows($reg, $t, $cols) as $r) {
            $row = [];
            foreach ($cols as $i => $c) {
                $row[$lc[$i]] = $r[$c] === null ? null : (string)$r[$c];
            }
            $srcRows[$keyOf($row)][] = $row;
        }
        $tgtCount = 0;
        $st = $pg->query("SELECT " . implode(', ', $lc) . " FROM $schema.$pgTable");
        $tgtRows = [];
        while (($r = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
            $row = [];
            foreach ($lc as $c) {
                $v = $r[$c];
                $row[$c] = $v === null ? null : (is_bool($v) ? (string)(int)$v : (string)$v);
            }
            $tgtRows[$keyOf($row)][] = $row;
            $tgtCount++;
        }
        $srcCount = array_sum(array_map('count', $srcRows));
        if ($srcCount !== $tgtCount) {
            $diffs[] = "$schema.$pgTable: $srcCount source rows, $tgtCount target rows";
            continue;
        }
        foreach ($srcRows as $k => $list) {
            $other = $tgtRows[$k] ?? [];
            $k = (string)$k;                       // PHP turns a numeric key into an int
            if (count($other) !== count($list)) {
                $diffs[] = "$schema.$pgTable: key " . str_replace("\x1f", ',', $k) . ' appears ' . count($list) . ' time(s) in the source and ' . count($other) . ' in the target';
                continue;
            }
            foreach ($list as $i => $srow) {
                $trow = $other[$i];
                foreach ($lc as $c) {
                    $a = norm_value($srow[$c], $types[$c]);
                    $b = norm_value($trow[$c], $types[$c]);
                    if ($a !== $b) {
                        $diffs[] = "$schema.$pgTable key " . str_replace("\x1f", ',', $k) . " column $c: source "
                                 . var_export($a, true) . ', target ' . var_export($b, true);
                        break;
                    }
                }
                $rowsChecked++;
            }
        }
    }
    if ($diffs) {
        $pg->rollBack();
        foreach (array_slice($diffs, 0, 40) as $d) {
            $say("DIFF: $d");
        }
        $say('FAILED: ' . count($diffs) . ' difference(s). The PostgreSQL copy is not trustworthy; the load was rolled back and nothing was kept.');
        exit(1);
    }
    $pg->commit();
    $say("loaded $copied rows");
    $say("VERIFIED: $rowsChecked rows across " . count($plan) . ' tables match the source.');
    exit(0);
} catch (Throwable $e) {
    if ($pg->inTransaction()) {
        $pg->rollBack();
    }
    $say('FAILED: ' . preg_replace('/\s+/', ' ', $e->getMessage()) . ' The load was rolled back; nothing was kept.');
    exit(1);
}
