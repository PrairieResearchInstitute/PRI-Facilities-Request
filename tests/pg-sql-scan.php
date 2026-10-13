<?php
declare(strict_types=1);

/**
 * Every SQL statement written as a literal in the application, planned by
 * PostgreSQL. Started by tests/run-pg.php on the test database.
 *
 * The page crawl and tests/pg-app.php run the paths a GET or the example
 * data reaches; most form submissions are POST-only. This reads the PHP
 * source instead: each string literal (or chain of literals joined with
 * ".") that begins with SELECT, INSERT, UPDATE, DELETE or WITH is translated
 * for the register its db('...') call names (or the first register holding
 * every table it mentions), its parameters become NULL, and PostgreSQL is
 * asked to EXPLAIN it. EXPLAIN parses, resolves every table, column,
 * function and operator, and plans, without running anything.
 *
 * A literal with a PHP variable spliced into it is planned with each
 * variable replaced by a value that keeps the statement whole where the
 * shape is clear (a number after TOP or in an arithmetic position), and
 * skipped otherwise; the skipped count is printed, never hidden.
 *
 * Ends with "All <n> SQL literals plan on PostgreSQL (<m> skipped)." or
 * exits 1 listing each statement PostgreSQL refused, with file and line.
 */

if (getenv('PRIOPS_LOCAL_CONFIG') === false) {
    fwrite(STDERR, "Run through tests/run-pg.php, which sets up the scratch settings.\n");
    exit(2);
}
require_once __DIR__ . '/../facilities/lib/db.php';
if (db_engine() !== 'postgres' || !preg_match('/_test$/', db_pg_database())) {
    fwrite(STDERR, "Refusing to run: not on the PostgreSQL test database.\n");
    exit(2);
}
require_once __DIR__ . '/../facilities/lib/pgsql.php';

$app = dirname(__DIR__);
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($app . '/facilities', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (substr((string)$f, -4) === '.php') {
        $files[] = (string)$f;
    }
}
// The command-line tools that run on either engine (digests, documents,
// audits). tools/pg speaks PostgreSQL already, and a tool that calls
// db_localdb_only() refuses to run on PostgreSQL.
foreach (glob($app . '/tools/*.php') ?: [] as $f) {
    if (!str_contains((string)file_get_contents($f), 'db_localdb_only(')) {
        $files[] = $f;
    }
}
sort($files);
// install.php speaks SQL Server to build LocalDB, and dbcatalog.php holds
// each engine's own catalogue query; neither sends SQL Server text to
// PostgreSQL.
$files = array_values(array_filter($files, fn($f) => !in_array(basename($f), ['install.php', 'dbcatalog.php'], true)));

/**
 * Statements known not to plan, each with the reason. Nothing is listed to
 * make the suite pass: an entry is a fact about the application that holds
 * on LocalDB too.
 */
const SCAN_KNOWN = [
    // form_people() reads gis.Roster on Space, which holds no such table on
    // LocalDB either (registers.json, backup of 2026-10-04); nothing calls it.
    'facilities/lib/adminforms.php:form_people' => 'FROM gis.Roster',
];

/** Literal text of a string token, or null when it interpolates. */
function scan_literal(array $tok): ?string
{
    if ($tok[0] !== T_CONSTANT_ENCAPSED_STRING) {
        return null;
    }
    $raw = $tok[1];
    if ($raw[0] === "'") {
        return str_replace(["\\'", '\\\\'], ["'", '\\'], substr($raw, 1, -1));
    }
    return stripcslashes(substr($raw, 1, -1));
}

$found = [];
foreach ($files as $file) {
    $toks = token_get_all((string)file_get_contents($file));
    $n = count($toks);
    for ($i = 0; $i < $n; $i++) {
        $t = $toks[$i];
        $isStr = is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING;
        $isEncaps = $t === '"' || (is_array($t) && $t[0] === T_START_HEREDOC);
        if (!$isStr && !$isEncaps) {
            continue;
        }
        $line = is_array($t) ? $t[2] : 0;
        // Gather a chain: literal ( . literal )*, with interpolated parts marked.
        $parts = [];
        $spliced = false;
        $j = $i;
        while ($j < $n) {
            $x = $toks[$j];
            if (is_array($x) && $x[0] === T_CONSTANT_ENCAPSED_STRING) {
                $parts[] = scan_literal($x);
                $j++;
            } elseif ($x === '"' || (is_array($x) && $x[0] === T_START_HEREDOC)) {
                $end = $x === '"' ? '"' : T_END_HEREDOC;
                $j++;
                $buf = '';
                while ($j < $n && !($toks[$j] === $end || (is_array($toks[$j]) && $toks[$j][0] === $end))) {
                    $y = $toks[$j];
                    if (is_array($y) && $y[0] === T_ENCAPSED_AND_WHITESPACE) {
                        $buf .= stripcslashes($y[1]);
                    } else {
                        $buf .= "\x01";            // an interpolated variable
                        $spliced = true;
                        if ($y === '{' || (is_array($y) && $y[0] === T_CURLY_OPEN)) {
                            $depth = 1;
                            $j++;
                            while ($j < $n && $depth > 0) {
                                if ($toks[$j] === '{' || (is_array($toks[$j]) && in_array($toks[$j][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) { $depth++; }
                                if ($toks[$j] === '}') { $depth--; }
                                $j++;
                            }
                            continue;
                        }
                        if (is_array($y) && $y[0] === T_VARIABLE) {
                            // $a['x'] or $a->b inside the string
                            while ($j + 1 < $n && ($toks[$j + 1] === '[' || (is_array($toks[$j + 1]) && $toks[$j + 1][0] === T_OBJECT_OPERATOR))) {
                                $j++;
                                while ($j < $n && $toks[$j] !== ']' && !(is_array($toks[$j]) && $toks[$j][0] === T_STRING)) { $j++; }
                            }
                        }
                    }
                    $j++;
                }
                $j++;
                $parts[] = $buf;
            } else {
                break;
            }
            // Skip whitespace; continue the chain on ".".
            $k = $j;
            while ($k < $n && is_array($toks[$k]) && $toks[$k][0] === T_WHITESPACE) { $k++; }
            if ($k < $n && $toks[$k] === '.') {
                $k++;
                while ($k < $n && is_array($toks[$k]) && in_array($toks[$k][0], [T_WHITESPACE, T_COMMENT], true)) { $k++; }
                $x = $toks[$k] ?? null;
                if ((is_array($x) && $x[0] === T_CONSTANT_ENCAPSED_STRING) || $x === '"' || (is_array($x) && $x[0] === T_START_HEREDOC)) {
                    $j = $k;
                    continue;
                }
                // Something else is joined in: a variable or a call.
                $parts[] = "\x01";
                $spliced = true;
                // Keep reading after it if another literal follows ". expr ."
                $depth = 0;
                while ($k < $n) {
                    $x = $toks[$k];
                    if ($x === '(' || $x === '[') { $depth++; }
                    if ($x === ')' || $x === ']') { if ($depth === 0) { break; } $depth--; }
                    if ($depth === 0 && ($x === ',' || $x === ';')) { break; }
                    if ($depth === 0 && $x === '.') {
                        $m = $k + 1;
                        while ($m < $n && is_array($toks[$m]) && $toks[$m][0] === T_WHITESPACE) { $m++; }
                        $y = $toks[$m] ?? null;
                        if ((is_array($y) && $y[0] === T_CONSTANT_ENCAPSED_STRING) || $y === '"') {
                            $k = $m;
                            break;
                        }
                    }
                    $k++;
                }
                $y = $toks[$k] ?? null;
                if ((is_array($y) && $y[0] === T_CONSTANT_ENCAPSED_STRING) || $y === '"') {
                    $j = $k;
                    continue;
                }
                $j = $k;
            }
            break;
        }
        $sql = implode('', array_map(fn($p) => (string)$p, $parts));
        if (preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE|WITH)\b/i', $sql) && preg_match('/\b(FROM|INTO|SET|VALUES)\b/i', $sql)) {
            // Which register: the nearest db('x') or db_for('t') before the literal.
            $reg = null;
            for ($b = $i - 1; $b >= max(0, $i - 40); $b--) {
                $x = $toks[$b];
                if (is_array($x) && $x[0] === T_STRING && in_array($x[1], ['db', 'db_for'], true)) {
                    $arg = $toks[$b + 2] ?? null;
                    if (is_array($arg) && $arg[0] === T_CONSTANT_ENCAPSED_STRING) {
                        $v = scan_literal($arg);
                        $reg = $x[1] === 'db' ? $v : db_home((string)$v);
                    }
                    break;
                }
                if ($x === ';' || $x === '{' || $x === '}') {
                    break;
                }
            }
            $found[] = ['file' => substr($file, strlen($app) + 1), 'line' => $line, 'sql' => $sql,
                        'spliced' => $spliced, 'reg' => $reg];
        }
        $i = max($i, $j - 1);
    }
}

/** First register that holds every table the statement names. */
function scan_register(string $sql): ?string
{
    $known = pg_known_tables();
    preg_match_all('/\b([A-Za-z]+)\s*\.\s*\[?([A-Za-z]+)\]?/', $sql, $m, PREG_SET_ORDER);
    $tables = [];
    foreach ($m as $x) {
        $q = strtolower($x[1] . '.' . $x[2]);
        if (isset($known[$q])) {
            $tables[] = $known[$q];
        }
    }
    if (!$tables) {
        return null;
    }
    foreach (PG_REGISTER_TABLES as $reg => $have) {
        if (!array_diff($tables, $have)) {
            return $reg;
        }
    }
    return null;
}

$ok = $skipped = 0;
$bad = [];
$seen = [];
foreach ($found as $f) {
    $sql = $f['sql'];
    if ($f['spliced']) {
        // A splice right after TOP, an operator or a comparison is a number.
        $sql = preg_replace('/(\bTOP\s*\(?\s*|[-+*\/]\s*)\x01/i', '${1}1', $sql);
        $sql = preg_replace('/([=<>]\s*|\bIN\s*\(\s*)\x01/i', '${1}NULL', $sql);
        if (str_contains($sql, "\x01")) {
            $skipped++;
            if (in_array("--list-skipped", $argv, true)) {
                echo 'skip  ' . $f['file'] . ':' . $f['line'] . ' ' . substr(preg_replace('/\s+/', ' ', str_replace("\x01", '$', $sql)), 0, 200) . "\n";
            }
            continue;
        }
    }
    $reg = $f['reg'] ?? scan_register($sql);
    if ($reg === null || !in_array($reg, APP_DB_NAMES, true)) {
        $reg = scan_register($sql) ?? 'work';
    }
    foreach (SCAN_KNOWN as $where => $needle) {
        if (str_starts_with(str_replace('\\', '/', $f['file']), explode(':', $where)[0]) && str_contains($sql, $needle)) {
            continue 2;
        }
    }
    $key = $reg . "\0" . $sql;
    if (isset($seen[$key])) {
        continue;
    }
    $seen[$key] = true;
    try {
        $pg = pg_translate($sql, $reg);
        $pg = preg_replace('/(?<![:\w]):[A-Za-z_]\w*|\?/', 'NULL', $pg);
        db($reg)->queryNative('EXPLAIN ' . $pg);
        $ok++;
    } catch (Throwable $e) {
        $msg = preg_replace('/\s+/', ' ', $e->getMessage());
        $bad[] = "{$f['file']}:{$f['line']} [$reg] " . substr($msg, 0, 260) . "\n      " . substr(preg_replace('/\s+/', ' ', $pg ?? $sql), 0, 300);
    }
}
foreach ($bad as $b) {
    echo "FAIL  $b\n";
}
$total = $ok + count($bad);
if ($bad) {
    echo count($bad) . " of $total SQL literals did not plan on PostgreSQL ($skipped skipped).\n";
    exit(1);
}
echo "All $total SQL literals plan on PostgreSQL ($skipped skipped).\n";
