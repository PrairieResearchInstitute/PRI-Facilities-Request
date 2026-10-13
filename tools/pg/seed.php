<?php
declare(strict_types=1);

/**
 * Reference data for a PostgreSQL installation that starts empty: the
 * INSERT, UPDATE, DELETE and MERGE statements of sql/00 to sql/35, run in
 * script order through the translation layer.
 *
 *   php tools/pg/seed.php --database test|live [--verbose]
 *
 * Who needs it: the PostgreSQL tests, and a fresh installation on Radiant
 * that does not come from LocalDB. The switch-over does NOT use it; that
 * loads every live row with tools/pg/load.php.
 *
 * How the scripts are read: structure (CREATE, ALTER, DROP) is skipped,
 * because migrations/pgsql builds the schema. IF conditions are evaluated:
 * OBJECT_ID, SCHEMA_ID and COL_LENGTH against the register's catalogue, an
 * EXISTS over sys.* as false (those guard structure), and any other
 * condition by asking PostgreSQL. A data statement runs in every register
 * schema that holds all the tables it names, which is where the LocalDB
 * split left copies of the same rows. A statement that uses a T-SQL
 * variable or a WHILE loop is skipped and reported. Each statement runs
 * under a savepoint, so one failure is reported and the rest carry on;
 * the run exits 1 if any statement failed.
 */

require_once __DIR__ . '/pgcli.php';
require_once __DIR__ . '/tsql_model.php';
require_once __DIR__ . '/../../facilities/lib/pgsql.php';

pgcli_timezone();
$db = pgcli_database($argv);
$verbose = pgcli_flag($argv, 'verbose');

/** Parse a token list into statements: ['if', cond, then, else] | ['block', [..]] | ['stmt', tokens]. */
function seed_parse(array $t, int &$i, int $n): ?array
{
    while ($i < $n && $t[$i][1] === ';') {
        $i++;
    }
    if ($i >= $n) {
        return null;
    }
    $w = $t[$i][0] === 'id' ? $t[$i][2] : '';
    if ($w === 'IF') {
        // The condition runs to the start of the next statement at depth 0.
        $j = $i + 1;
        $depth = 0;
        $starts = ['BEGIN', 'INSERT', 'UPDATE', 'DELETE', 'MERGE', 'CREATE', 'ALTER', 'DROP', 'EXEC', 'EXECUTE',
                   'PRINT', 'DECLARE', 'SET', 'WHILE', 'IF', 'SELECT', 'WITH', 'RETURN'];
        while ($j < $n) {
            if ($t[$j][1] === '(') { $depth++; }
            if ($t[$j][1] === ')') { $depth--; }
            if ($depth === 0 && $t[$j][0] === 'id' && in_array($t[$j][2], $starts, true)) {
                $prev = $t[$j - 1][2] ?? '';
                if (!($t[$j][2] === 'SELECT' && $prev === '(')) {
                    break;
                }
            }
            $j++;
        }
        $cond = array_slice($t, $i + 1, $j - $i - 1);
        $i = $j;
        $then = seed_parse($t, $i, $n);
        $else = null;
        while ($i < $n && $t[$i][1] === ';') { $i++; }
        if ($i < $n && $t[$i][0] === 'id' && $t[$i][2] === 'ELSE') {
            $i++;
            $else = seed_parse($t, $i, $n);
        }
        return ['if', $cond, $then, $else];
    }
    if ($w === 'BEGIN' && !in_array($t[$i + 1][2] ?? '', ['TRAN', 'TRANSACTION', 'TRY', 'CATCH'], true)) {
        $i++;
        $body = [];
        while ($i < $n && !($t[$i][0] === 'id' && $t[$i][2] === 'END')) {
            $s = seed_parse($t, $i, $n);
            if ($s === null) {
                break;
            }
            $body[] = $s;
        }
        $i++;                                         // END
        return ['block', $body];
    }
    if ($w === 'WHILE') {
        $j = $i + 1;
        $depth = 0;
        while ($j < $n && !($depth === 0 && $t[$j][0] === 'id' && $t[$j][2] === 'BEGIN')) {
            if ($t[$j][1] === '(') { $depth++; }
            if ($t[$j][1] === ')') { $depth--; }
            $j++;
        }
        $i = $j;
        $body = seed_parse($t, $i, $n);
        return ['skip', 'WHILE loop', $body];
    }
    $first = $t[$i][2];
    // MERGE ... ; runs to its terminating semicolon (its body holds UPDATE and INSERT words).
    if ($w === 'MERGE') {
        $j = $i;
        $depth = 0;
        while ($j < $n && !($depth === 0 && $t[$j][1] === ';')) {
            if ($t[$j][1] === '(') { $depth++; }
            if ($t[$j][1] === ')') { $depth--; }
            $j++;
        }
        $stmt = array_slice($t, $i, $j - $i);
        $i = $j;
        return ['stmt', $stmt];
    }
    $skipFirst = 1;
    if ($w === 'INSERT' || $w === 'UPDATE' || $w === 'DELETE') {
        // An INSERT ... SELECT or an UPDATE ... FROM keeps its own SELECT.
        $end = seed_dml_end($t, $i, $n);
    } else {
        $end = tsql_statement_end($t, $i, $skipFirst);
    }
    if ($end <= $i) {
        $end = $i + 1;
    }
    $stmt = array_slice($t, $i, $end - $i);
    $i = $end;
    return ['stmt', $stmt];
}

/** End of a DML statement: ';' or a statement word at depth 0 that cannot be part of it. */
function seed_dml_end(array $t, int $i, int $n): int
{
    $depth = 0;
    $stops = ['IF', 'BEGIN', 'END', 'EXEC', 'EXECUTE', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'ALTER', 'DROP',
              'PRINT', 'DECLARE', 'WHILE', 'ELSE', 'MERGE', 'GO'];
    for ($j = $i + 1; $j < $n; $j++) {
        $x = $t[$j];
        if ($x[1] === '(') { $depth++; continue; }
        if ($x[1] === ')') { $depth--; if ($depth < 0) { return $j; } continue; }
        if ($depth > 0) { continue; }
        if ($x[1] === ';') { return $j; }
        if ($x[0] === 'id' && in_array($x[2], $stops, true)) {
            $p = $t[$j - 1][2] ?? '';
            if (($x[2] === 'DELETE' || $x[2] === 'UPDATE') && $p === 'ON') { continue; }
            return $j;
        }
    }
    return $n;
}

/** Tables a statement names (canonical 'schema.Table'). */
function seed_tables(array $t): array
{
    $known = pg_known_tables();
    $out = [];
    $n = count($t);
    for ($i = 0; $i + 2 < $n; $i++) {
        if ($t[$i][0] === 'id' && $t[$i + 1][1] === '.' && $t[$i + 2][0] === 'id') {
            $q = strtolower($t[$i][1] . '.' . $t[$i + 2][1]);
            if (isset($known[$q])) {
                $out[$known[$q]] = true;
            }
        }
    }
    return array_keys($out);
}

/** T-SQL MERGE into PostgreSQL 17 MERGE. */
function seed_merge(string $sql): string
{
    $sql = preg_replace('/^\s*MERGE\s+(?!INTO\b)/i', 'MERGE INTO ', $sql);
    // UPDATE SET t.Col = s.Col: PostgreSQL names the target column bare.
    $sql = preg_replace_callback('/\bUPDATE\s+SET\s+(.*?)(?=\bWHEN\b|$)/is', function ($m) {
        $set = preg_replace('/(^|,)\s*\w+\.(\w+)\s*=/', '$1 $2 =', $m[1]);
        return 'UPDATE SET ' . $set;
    }, $sql);
    $sql = preg_replace('/\bWHEN\s+NOT\s+MATCHED\s+BY\s+TARGET\b/i', 'WHEN NOT MATCHED', $sql);
    return $sql;
}

/** Evaluate an IF condition for a register; returns bool. */
function seed_condition(array $cond, string $reg, PDO $pdo): bool
{
    $sql = tsql_render($cond);
    $tables = PG_REGISTER_TABLES[$reg];
    $has = function (string $q) use ($tables): bool {
        foreach ($tables as $t) {
            if (strcasecmp($t, $q) === 0) {
                return true;
            }
        }
        return false;
    };
    $sql = preg_replace_callback("/OBJECT_ID\\s*\\(\\s*'([^']+)'\\s*(,\\s*'[A-Z]+'\\s*)?\\)\\s+IS\\s+(NOT\\s+)?NULL/i",
        fn($m) => ($has($m[1]) xor ($m[3] ?? '') === '') ? 'TRUE' : 'FALSE', $sql);
    $sql = preg_replace_callback("/SCHEMA_ID\\s*\\(\\s*'[^']+'\\s*\\)\\s+IS\\s+(NOT\\s+)?NULL/i",
        fn($m) => ($m[1] ?? '') !== '' ? 'TRUE' : 'FALSE', $sql);
    $sql = preg_replace_callback("/COL_LENGTH\\s*\\(\\s*'([^']+)'\\s*,\\s*'([^']+)'\\s*\\)\\s+IS\\s+(NOT\\s+)?NULL/i",
        function ($m) use ($has) {
            $exists = $has($m[1]) && in_array(strtolower($m[2]), array_map('strtolower', PG_TABLE_COLUMNS[$m[1]] ?? []), true);
            return ($exists xor ($m[3] ?? '') === '') ? 'TRUE' : 'FALSE';
        }, $sql);
    $sql = preg_replace("/COL_LENGTH\\s*\\([^)]*\\)\\s*[<>=]+\\s*\\d+/i", 'FALSE', $sql);
    // EXISTS over the SQL Server catalogue guards structure, which migrations built.
    $sql = preg_replace_callback('/(NOT\s+)?EXISTS\s*\(\s*SELECT\b/i', function ($m) {
        return $m[0];
    }, $sql);
    $sql = seed_strip_sys_exists($sql);
    $pdo->exec('SAVEPOINT seed_if');
    try {
        $tr = pg_translate('SELECT CASE WHEN ' . $sql . ' THEN 1 ELSE 0 END', $reg);
        $v = (int)$pdo->query($tr)->fetchColumn() === 1;
        $pdo->exec('RELEASE SAVEPOINT seed_if');
        return $v;
    } catch (Throwable $e) {
        // A condition over a table this register does not hold: the
        // script's guard would have stopped there too.
        $pdo->exec('ROLLBACK TO SAVEPOINT seed_if');
        return false;
    }
}

/** Replace [NOT] EXISTS (SELECT ... FROM sys.x ...) with FALSE / TRUE. */
function seed_strip_sys_exists(string $sql): string
{
    $out = '';
    $i = 0;
    while (preg_match('/(NOT\s+)?EXISTS\s*\(/i', $sql, $m, PREG_OFFSET_CAPTURE, $i)) {
        $start = $m[0][1];
        $open = $start + strlen($m[0][0]) - 1;
        $depth = 0;
        $j = $open;
        for (; $j < strlen($sql); $j++) {
            if ($sql[$j] === '(') { $depth++; }
            if ($sql[$j] === ')') { $depth--; if ($depth === 0) { break; } }
        }
        $inner = substr($sql, $open + 1, $j - $open - 1);
        $out .= substr($sql, $i, $start - $i);
        if (preg_match('/\bsys\s*\.\s*\w+|INFORMATION_SCHEMA/i', $inner)) {
            $out .= ($m[1][0] ?? '') !== '' ? 'TRUE' : 'FALSE';
        } else {
            $out .= substr($sql, $start, $j - $start + 1);
        }
        $i = $j + 1;
    }
    return $out . substr($sql, $i);
}

$pdo = pgcli_connect($db);
$pdo->exec('SET search_path TO public');
$files = glob(dirname(__DIR__, 2) . '/sql/[0-9][0-9]_*.sql');
sort($files);
$ran = $skipped = $failed = 0;
$notes = [];

$exec = function (array $node, string $file) use (&$exec, $pdo, $verbose, &$ran, &$skipped, &$failed, &$notes, $db): void {
    if ($node === null) {
        return;
    }
    switch ($node[0]) {
        case 'block':
            foreach ($node[1] as $s) {
                $exec($s, $file);
            }
            return;
        case 'skip':
            $skipped++;
            $notes[] = "$file: skipped a {$node[1]}";
            return;
        case 'if':
            // Evaluated per register inside the statement runner: the
            // condition and its branch travel together.
            $exec(['cond', $node], $file);
            return;
        case 'cond':
            [, $cond, $then, $else] = $node[1];
            foreach (APP_DB_NAMES as $reg) {
                $branch = seed_condition($cond, $reg, $pdo) ? $then : $else;
                if ($branch !== null) {
                    $exec(['in', $reg, $branch], $file);
                }
            }
            return;
        case 'in':
            [, $reg, $inner] = $node;
            if ($inner[0] === 'stmt') {
                seed_run($inner[1], [$reg], $file, $pdo, $verbose, $ran, $skipped, $failed, $notes);
            } elseif ($inner[0] === 'block') {
                foreach ($inner[1] as $s) {
                    $exec(['in', $reg, $s], $file);
                }
            } elseif ($inner[0] === 'if') {
                [, $cond, $then, $else] = $inner;
                $branch = seed_condition($cond, $reg, $pdo) ? $then : $else;
                if ($branch !== null) {
                    $exec(['in', $reg, $branch], $file);
                }
            } elseif ($inner[0] === 'skip') {
                $skipped++;
                $notes[] = "$file: skipped a {$inner[1]} in $reg";
            }
            return;
        case 'stmt':
            seed_run($node[1], APP_DB_NAMES, $file, $pdo, $verbose, $ran, $skipped, $failed, $notes);
            return;
    }
};

function seed_run(array $tokens, array $regs, string $file, PDO $pdo, bool $verbose, int &$ran, int &$skipped, int &$failed, array &$notes): void
{
    if (!$tokens || $tokens[0][0] !== 'id') {
        return;
    }
    $w = $tokens[0][2];
    if (!in_array($w, ['INSERT', 'UPDATE', 'DELETE', 'MERGE'], true)) {
        return;                                        // structure, variables, PRINT, USE: not data
    }
    $sql = tsql_render($tokens);
    if (preg_match('/@\w+/', $sql)) {
        $skipped++;
        $notes[] = "$file: skipped a statement that uses a T-SQL variable: " . substr($sql, 0, 90);
        return;
    }
    // Two T-SQL forms with no PostgreSQL twin, both one-off data moves on
    // databases that already held rows (lists split out of a setting, the
    // example accounts switched off); an empty database has nothing to move.
    if (preg_match('/\b(CROSS|OUTER)\s+APPLY\b/i', $sql) || preg_match('/^UPDATE\s+\w+\s+SET\b.*\bFROM\b/is', $sql)) {
        $skipped++;
        $notes[] = "$file: skipped a T-SQL-only data move: " . substr($sql, 0, 90);
        return;
    }
    $tables = seed_tables($tokens);
    if (!$tables) {
        return;
    }
    if ($w === 'MERGE') {
        $sql = seed_merge($sql);
    }
    foreach ($regs as $reg) {
        $holds = array_map('strtolower', PG_REGISTER_TABLES[$reg]);
        foreach ($tables as $t) {
            if (!in_array(strtolower($t), $holds, true)) {
                continue 2;
            }
        }
        $tr = pg_translate($sql, $reg);
        if ($w === 'INSERT' && !preg_match('/\bON\s+CONFLICT\b/i', $tr)) {
            $tr .= ' ON CONFLICT DO NOTHING';
        }
        $pdo->exec('SAVEPOINT seed');
        try {
            $pdo->exec($tr);
            $pdo->exec('RELEASE SAVEPOINT seed');
            $ran++;
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK TO SAVEPOINT seed');
            $failed++;
            $notes[] = "$file [$reg]: " . preg_replace('/\s+/', ' ', $e->getMessage()) . "\n    " . substr($tr, 0, 300);
        }
    }
}

$pdo->beginTransaction();
foreach ($files as $f) {
    $name = basename($f);
    $sql = preg_replace('/^\xEF\xBB\xBF/', '', (string)file_get_contents($f));
    foreach (preg_split('/^\s*GO\s*;?\s*$/mi', $sql) as $batch) {
        $flat = tsql_strip_comments(tsql_expand_exec(tsql_strip_comments($batch)));
        $t = tsql_tokens($flat);
        $n = count($t);
        $i = 0;
        while ($i < $n) {
            $before = $i;
            $node = seed_parse($t, $i, $n);
            if ($node === null) {
                break;
            }
            $exec($node, $name);
            if ($i === $before) {
                $i++;
            }
        }
    }
}
$pdo->commit();
foreach ($notes as $note) {
    if ($verbose || !str_contains($note, 'skipped')) {
        echo "note: $note\n";
    }
}
echo "seed $db: $ran statement(s) ran, $skipped skipped, $failed failed\n";
exit($failed ? 1 : 0);
