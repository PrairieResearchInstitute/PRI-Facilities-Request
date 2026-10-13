<?php
declare(strict_types=1);

/**
 * PostgreSQL behind the application's SQL Server queries.
 *
 * The application has about seven hundred queries written for SQL Server,
 * and SQL Server stays the default until the owner switches dataMode. So
 * the queries are not forked: each statement is translated once, here, on
 * its way to PostgreSQL, and the rows come back in the shape the code
 * expects. Only what no translation can carry (string concatenation with
 * "+", catalogue queries, FOR XML PATH) is rewritten at its call site.
 *
 * What the translation does, and why each one matters:
 *  - schema.Table becomes <register>.<schema>_<table>: one database, ten
 *    schemas, one per register (platform D-027). The SQL Server schema
 *    survives as a prefix because wr.Approvals, sh.Approvals and
 *    rq.Approvals are three different tables.
 *  - TOP n becomes LIMIT n; WITH (UPDLOCK, HOLDLOCK) becomes FOR UPDATE.
 *  - SYSDATETIME() and GETDATE() become statement_timestamp() as a local
 *    timestamp: once per statement, as SQL Server evaluates them.
 *  - ISNULL, LEN, DATEADD, DATEDIFF, YEAR, MONTH, DAY, CHARINDEX become
 *    their PostgreSQL forms; CAST(... AS NVARCHAR(n)) keeps its truncation.
 *  - [brackets] and N'' prefixes go; LIKE becomes ILIKE, because SQL
 *    Server's collation compares without case (the columns are citext).
 *  - ORDER BY gains NULLS FIRST / NULLS LAST, because SQL Server sorts
 *    nulls first ascending and PostgreSQL sorts them last.
 *  - Result columns come back spelled as the query or the SQL Server
 *    schema spells them (PostgreSQL folds unquoted names to lower case).
 *  - Values come back as strings, as pdo_sqlsrv returns them.
 *  - Inside a transaction every statement runs under a savepoint, because
 *    SQL Server rolls back only a failed statement whereas PostgreSQL
 *    aborts the whole transaction.
 */

require_once __DIR__ . '/pg_catalog.php';

/** Hints SQL Server accepts in WITH (...) after a table name. */
const PG_TABLE_HINTS = ['UPDLOCK', 'HOLDLOCK', 'NOLOCK', 'ROWLOCK', 'READPAST', 'TABLOCK', 'TABLOCKX',
                        'XLOCK', 'SERIALIZABLE', 'READCOMMITTED', 'REPEATABLEREAD', 'PAGLOCK', 'NOWAIT'];

/** Words that end an ORDER BY list or a query block at the same depth. */
const PG_CLAUSE_END = ['LIMIT', 'OFFSET', 'FETCH', 'FOR', 'UNION', 'EXCEPT', 'INTERSECT', 'OPTION'];

/** Register name for a LocalDB database name (three-part names in tools). */
function pg_register_for_database(string $db): ?string
{
    foreach (APP_DB_LABELS as $reg => $label) {
        if (strcasecmp($label, $db) === 0) {
            return $reg;
        }
    }
    return null;
}

/** Lower-case 'schema.table' => canonical 'schema.Table', every table any register holds. */
function pg_known_tables(): array
{
    static $t = null;
    if ($t === null) {
        $t = [];
        foreach (array_keys(PG_TABLE_COLUMNS) as $q) {
            $t[strtolower($q)] = $q;
        }
    }
    return $t;
}

/** The PostgreSQL name of a SQL Server table inside a register schema. */
function pg_table_name(string $register, string $q): string
{
    return $register . '.' . strtolower(str_replace('.', '_', $q));
}

/**
 * Tokens with their text kept, so the output keeps the query's layout.
 * Each token: [type, text]. Types: ws, str, id, bid (bracketed), qid
 * ("quoted"), num, param, op.
 */
function pg_lex(string $sql): array
{
    $t = [];
    $n = strlen($sql);
    $i = 0;
    while ($i < $n) {
        $c = $sql[$i];
        if (ctype_space($c)) {
            $j = $i;
            while ($j < $n && ctype_space($sql[$j])) { $j++; }
            $t[] = ['ws', substr($sql, $i, $j - $i)];
            $i = $j;
            continue;
        }
        if ($c === '-' && ($sql[$i + 1] ?? '') === '-') {
            $j = strpos($sql, "\n", $i);
            $j = $j === false ? $n : $j;
            $t[] = ['ws', ' '];
            $i = $j;
            continue;
        }
        if ($c === '/' && ($sql[$i + 1] ?? '') === '*') {
            $j = strpos($sql, '*/', $i + 2);
            $i = $j === false ? $n : $j + 2;
            $t[] = ['ws', ' '];
            continue;
        }
        if ($c === "'" || (($c === 'N' || $c === 'n') && ($sql[$i + 1] ?? '') === "'"
                && ($i === 0 || (!ctype_alnum($sql[$i - 1]) && $sql[$i - 1] !== '_')))) {
            $j = $c === "'" ? $i + 1 : $i + 2;
            while ($j < $n) {
                if ($sql[$j] === "'") {
                    if (($sql[$j + 1] ?? '') === "'") { $j += 2; continue; }
                    break;
                }
                $j++;
            }
            $raw = substr($sql, $i, $j - $i + 1);
            $t[] = ['str', $raw[0] === "'" ? $raw : substr($raw, 1)];
            $i = $j + 1;
            continue;
        }
        if ($c === '[') {
            $j = strpos($sql, ']', $i);
            if ($j === false) {
                $t[] = ['op', $c];
                $i++;
                continue;
            }
            $t[] = ['bid', substr($sql, $i + 1, $j - $i - 1)];
            $i = $j + 1;
            continue;
        }
        if ($c === '"') {
            $j = strpos($sql, '"', $i + 1);
            $j = $j === false ? $n - 1 : $j;
            $t[] = ['qid', substr($sql, $i, $j - $i + 1)];
            $i = $j + 1;
            continue;
        }
        if ($c === '?') {
            $t[] = ['param', '?'];
            $i++;
            continue;
        }
        if ($c === ':' && ($sql[$i + 1] ?? '') !== ':' && preg_match('/\G:[A-Za-z_]\w*/', $sql, $m, 0, $i)
                && ($i === 0 || $sql[$i - 1] !== ':')) {
            $t[] = ['param', $m[0]];
            $i += strlen($m[0]);
            continue;
        }
        if (ctype_alpha($c) || $c === '_' || $c === '@' || $c === '#') {
            $j = $i + 1;
            while ($j < $n && (ctype_alnum($sql[$j]) || $sql[$j] === '_' || $sql[$j] === '$' || $sql[$j] === '@' || $sql[$j] === '#')) { $j++; }
            $t[] = ['id', substr($sql, $i, $j - $i)];
            $i = $j;
            continue;
        }
        if (ctype_digit($c)) {
            $j = $i + 1;
            while ($j < $n && (ctype_digit($sql[$j]) || $sql[$j] === '.')) { $j++; }
            $t[] = ['num', substr($sql, $i, $j - $i)];
            $i = $j;
            continue;
        }
        $two = substr($sql, $i, 2);
        if (in_array($two, ['<=', '>=', '<>', '!=', '::', '||'], true)) {
            $t[] = ['op', $two];
            $i += 2;
            continue;
        }
        $t[] = ['op', $c];
        $i++;
    }
    return $t;
}

function pg_unlex(array $t): string
{
    $s = '';
    foreach ($t as $x) {
        $s .= $x[1];
    }
    return $s;
}

/** Next significant token index after $i, or null. */
function pg_next(array $t, int $i): ?int
{
    $n = count($t);
    for ($j = $i + 1; $j < $n; $j++) {
        if ($t[$j][0] !== 'ws') {
            return $j;
        }
    }
    return null;
}

function pg_prev(array $t, int $i): ?int
{
    for ($j = $i - 1; $j >= 0; $j--) {
        if ($t[$j][0] !== 'ws') {
            return $j;
        }
    }
    return null;
}

function pg_is(array $t, ?int $i, string $word): bool
{
    return $i !== null && isset($t[$i]) && $t[$i][0] === 'id' && strcasecmp($t[$i][1], $word) === 0;
}

function pg_isop(array $t, ?int $i, string $op): bool
{
    return $i !== null && isset($t[$i]) && $t[$i][0] === 'op' && $t[$i][1] === $op;
}

/** Index of the ')' matching the '(' at $i. */
function pg_close(array $t, int $i): int
{
    $depth = 0;
    $n = count($t);
    for ($j = $i; $j < $n; $j++) {
        if ($t[$j][0] === 'op') {
            if ($t[$j][1] === '(') { $depth++; }
            elseif ($t[$j][1] === ')') { $depth--; if ($depth === 0) { return $j; } }
        }
    }
    throw new RuntimeException('Unbalanced parentheses in a query.');
}

/** Split tokens between $from and $to (exclusive) on commas at depth 0; returns rendered args. */
function pg_args(array $t, int $from, int $to): array
{
    $args = [];
    $cur = '';
    $depth = 0;
    for ($j = $from; $j < $to; $j++) {
        if ($t[$j][0] === 'op') {
            if ($t[$j][1] === '(') { $depth++; }
            if ($t[$j][1] === ')') { $depth--; }
            if ($t[$j][1] === ',' && $depth === 0) {
                $args[] = trim($cur);
                $cur = '';
                continue;
            }
        }
        $cur .= $t[$j][1];
    }
    $args[] = trim($cur);
    return $args;
}

/** PostgreSQL text for one T-SQL type name (in CAST). */
function pg_cast_type(string $type): string
{
    $u = strtoupper(preg_replace('/\s+/', '', $type));
    if (preg_match('/^N?VARCHAR\(MAX\)$|^N?TEXT$/', $u)) {
        return 'public.citext';
    }
    if (preg_match('/^N?(VAR)?CHAR(\((\d+)\))?$/', $u, $m)) {
        return '';                                         // handled by the caller: keeps truncation
    }
    $map = ['BIT' => 'smallint', 'DATETIME2' => 'timestamp(6)', 'DATETIME' => 'timestamp(6)',
            'SMALLDATETIME' => 'timestamp(0)', 'FLOAT' => 'double precision', 'TINYINT' => 'smallint',
            'MONEY' => 'numeric(19,4)', 'UNIQUEIDENTIFIER' => 'uuid'];
    $base = preg_replace('/\(.*$/', '', $u);
    if (isset($map[$base])) {
        return preg_match('/^DATETIME2\((\d)\)$/', $u, $m) ? 'timestamp(' . min(6, (int)$m[1]) . ')' : $map[$base];
    }
    return strtolower($type);
}

/**
 * Translate one SQL Server statement for a register schema. Pure: the same
 * input always gives the same output, so the result is cached per process.
 */
function pg_translate(string $sql, string $register): string
{
    static $cache = [];
    $key = $register . "\0" . $sql;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $t = pg_lex($sql);
    $t = pg_pass_names($t, $register);
    $t = pg_pass_functions($t);
    $t = pg_pass_keywords($t);
    $t = pg_pass_order_nulls($t);
    $t = pg_pass_top_and_locks($t);
    $out = pg_unlex($t);
    if (count($cache) > 4000) {
        $cache = [];
    }
    return $cache[$key] = $out;
}

/** Brackets, table names, N'' (already stripped by the lexer). */
function pg_pass_names(array $t, string $register): array
{
    static $reserved = ['all', 'analyse', 'analyze', 'and', 'any', 'array', 'as', 'asc', 'asymmetric', 'both',
        'case', 'cast', 'check', 'collate', 'column', 'constraint', 'create', 'current_date', 'current_role',
        'current_time', 'current_timestamp', 'current_user', 'default', 'deferrable', 'desc', 'distinct', 'do',
        'else', 'end', 'except', 'false', 'fetch', 'for', 'foreign', 'from', 'grant', 'group', 'having', 'in',
        'initially', 'intersect', 'into', 'lateral', 'leading', 'limit', 'localtime', 'localtimestamp', 'not',
        'null', 'offset', 'on', 'only', 'or', 'order', 'placing', 'primary', 'references', 'returning', 'select',
        'session_user', 'some', 'symmetric', 'table', 'then', 'to', 'trailing', 'true', 'union', 'unique', 'user',
        'using', 'variadic', 'when', 'where', 'window', 'with'];
    $known = pg_known_tables();
    foreach ($t as $i => $x) {
        if ($x[0] === 'bid') {
            $name = $x[1];
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) && !in_array(strtolower($name), $reserved, true)) {
                $t[$i] = ['id', $name];
            } else {
                // A name PostgreSQL would not take bare: keep it quoted, in its own spelling.
                $t[$i] = ['qid', '"' . str_replace('"', '""', $name) . '"'];
            }
        }
    }
    $n = count($t);
    for ($i = 0; $i < $n; $i++) {
        if ($t[$i][0] !== 'id') {
            continue;
        }
        // db.schema.table (the split tools name other databases this way)
        $d1 = $i + 1;
        if (pg_isop($t, $d1, '.') && isset($t[$i + 2]) && $t[$i + 2][0] === 'id') {
            $first = $t[$i][1];
            $second = $t[$i + 2][1];
            if (pg_isop($t, $i + 3, '.') && isset($t[$i + 4]) && $t[$i + 4][0] === 'id') {
                $reg = pg_register_for_database($first);
                $q = strtolower($second . '.' . $t[$i + 4][1]);
                if ($reg !== null && isset($known[$q])) {
                    $t[$i] = ['id', pg_table_name($reg, $known[$q])];
                    for ($k = $i + 1; $k <= $i + 4; $k++) { $t[$k] = ['ws', '']; }
                    $i += 4;
                    continue;
                }
            }
            $q = strtolower($first . '.' . $second);
            if (isset($known[$q]) && !pg_isop($t, pg_prev($t, $i), '.')) {
                $t[$i] = ['id', pg_table_name($register, $known[$q])];
                $t[$i + 1] = ['ws', ''];
                $t[$i + 2] = ['ws', ''];
                $i += 2;
            }
        }
    }
    return $t;
}

/** Function calls, rewritten right to left so arguments are done first. */
function pg_pass_functions(array $t): array
{
    for ($i = count($t) - 1; $i >= 0; $i--) {
        if ($t[$i][0] !== 'id') {
            continue;
        }
        $name = strtoupper($t[$i][1]);
        $open = pg_next($t, $i);
        $isCall = pg_isop($t, $open, '(');
        $prev = pg_prev($t, $i);
        if (pg_isop($t, $prev, '.')) {
            continue;                                   // a column such as r.Year
        }
        if (in_array($name, ['SYSDATETIME', 'GETDATE'], true) && $isCall) {
            $close = pg_close($t, $open);
            pg_replace($t, $i, $close, 'CAST(statement_timestamp() AS timestamp(6))');
            continue;
        }
        if ($name === 'CURRENT_TIMESTAMP' && !$isCall) {
            $t[$i] = ['id', 'CAST(statement_timestamp() AS timestamp(6))'];
            continue;
        }
        if (in_array($name, ['SYSUTCDATETIME', 'GETUTCDATE'], true) && $isCall) {
            $close = pg_close($t, $open);
            pg_replace($t, $i, $close, "CAST(statement_timestamp() AT TIME ZONE 'UTC' AS timestamp(6))");
            continue;
        }
        if (!$isCall) {
            continue;
        }
        $close = pg_close($t, $open);
        switch ($name) {
            case 'ISNULL':
                $t[$i] = ['id', 'COALESCE'];
                break;
            case 'COUNT_BIG':
                $t[$i] = ['id', 'COUNT'];
                break;
            case 'LEN':
                $a = pg_args($t, $open + 1, $close);
                pg_replace($t, $i, $close, 'char_length(rtrim(' . $a[0] . '))');
                break;
            case 'CHARINDEX':
                $a = pg_args($t, $open + 1, $close);
                if (count($a) === 2) {
                    pg_replace($t, $i, $close, 'strpos(' . $a[1] . ', ' . $a[0] . ')');
                }
                break;
            case 'YEAR':
            case 'MONTH':
            case 'DAY':
                $a = pg_args($t, $open + 1, $close);
                pg_replace($t, $i, $close, 'CAST(EXTRACT(' . $name . ' FROM ' . $a[0] . ') AS integer)');
                break;
            case 'DATEADD':
                $a = pg_args($t, $open + 1, $close);
                pg_replace($t, $i, $close, pg_dateadd($a));
                break;
            case 'DATEDIFF':
                $a = pg_args($t, $open + 1, $close);
                pg_replace($t, $i, $close, pg_datediff($a));
                break;
            case 'DATEPART':
                $a = pg_args($t, $open + 1, $close);
                pg_replace($t, $i, $close, 'CAST(EXTRACT(' . pg_unit($a[0]) . ' FROM ' . $a[1] . ') AS integer)');
                break;
            case 'ROUND':
                $a = pg_args($t, $open + 1, $close);
                if (count($a) === 2) {
                    pg_replace($t, $i, $close, 'round(CAST(' . $a[0] . ' AS numeric), ' . $a[1] . ')');
                }
                break;
            case 'CAST':
                pg_rewrite_cast($t, $i, $open, $close);
                break;
            case 'IIF':
                $a = pg_args($t, $open + 1, $close);
                pg_replace($t, $i, $close, '(CASE WHEN ' . $a[0] . ' THEN ' . $a[1] . ' ELSE ' . $a[2] . ' END)');
                break;
            case 'NEWID':
                pg_replace($t, $i, $close, 'gen_random_uuid()');
                break;
        }
    }
    return $t;
}

/** Replace tokens $from..$to (inclusive) by one token of text. */
function pg_replace(array &$t, int $from, int $to, string $text): void
{
    $t[$from] = ['id', $text];
    for ($k = $from + 1; $k <= $to; $k++) {
        $t[$k] = ['ws', ''];
    }
}

function pg_unit(string $u): string
{
    $u = strtolower(trim($u, " \t\n\r'\""));
    $map = ['yy' => 'year', 'yyyy' => 'year', 'year' => 'year', 'qq' => 'quarter', 'q' => 'quarter',
            'quarter' => 'quarter', 'mm' => 'month', 'm' => 'month', 'month' => 'month', 'dd' => 'day',
            'd' => 'day', 'day' => 'day', 'dy' => 'doy', 'dayofyear' => 'doy', 'wk' => 'week', 'ww' => 'week',
            'week' => 'week', 'hh' => 'hour', 'hour' => 'hour', 'mi' => 'minute', 'n' => 'minute',
            'minute' => 'minute', 'ss' => 'second', 's' => 'second', 'second' => 'second', 'dw' => 'dow',
            'weekday' => 'dow'];
    if (!isset($map[$u])) {
        throw new RuntimeException('No PostgreSQL unit for ' . $u);
    }
    return $map[$u];
}

/** DATEADD(unit, n, d). A date stays a date for day, week, month and year. */
function pg_dateadd(array $a): string
{
    $unit = pg_unit($a[0]);
    $n = $a[1];
    $d = $a[2];
    if ($unit === 'quarter') {
        $unit = 'month';
        $n = '3 * (' . $n . ')';
    }
    if ($unit === 'week') {
        $unit = 'day';
        $n = '7 * (' . $n . ')';
    }
    $expr = '(' . $d . ') + (' . $n . ') * INTERVAL \'1 ' . $unit . '\'';
    if (preg_match('/^CAST\(.*AS\s+DATE\)$/is', trim($d)) && in_array($unit, ['day', 'month', 'year'], true)) {
        return 'CAST(' . $expr . ' AS date)';
    }
    return '(' . $expr . ')';
}

/** DATEDIFF(unit, a, b): boundaries crossed, as SQL Server counts them. */
function pg_datediff(array $a): string
{
    $unit = pg_unit($a[0]);
    $x = $a[1];
    $y = $a[2];
    switch ($unit) {
        case 'day':
            return '(CAST(' . $y . ' AS date) - CAST(' . $x . ' AS date))';
        case 'week':
            return "CAST(((CAST($y AS date) - CAST(EXTRACT(DOW FROM CAST($y AS date)) AS integer)) - (CAST($x AS date) - CAST(EXTRACT(DOW FROM CAST($x AS date)) AS integer))) / 7 AS integer)";
        case 'month':
            return "CAST((EXTRACT(YEAR FROM CAST($y AS timestamp)) - EXTRACT(YEAR FROM CAST($x AS timestamp))) * 12 + EXTRACT(MONTH FROM CAST($y AS timestamp)) - EXTRACT(MONTH FROM CAST($x AS timestamp)) AS integer)";
        case 'quarter':
            return "CAST((EXTRACT(YEAR FROM CAST($y AS timestamp)) - EXTRACT(YEAR FROM CAST($x AS timestamp))) * 4 + EXTRACT(QUARTER FROM CAST($y AS timestamp)) - EXTRACT(QUARTER FROM CAST($x AS timestamp)) AS integer)";
        case 'year':
            return "CAST(EXTRACT(YEAR FROM CAST($y AS timestamp)) - EXTRACT(YEAR FROM CAST($x AS timestamp)) AS integer)";
        case 'hour':
        case 'minute':
        case 'second':
            $div = ['hour' => 3600, 'minute' => 60, 'second' => 1][$unit];
            return "CAST(floor(EXTRACT(EPOCH FROM (date_trunc('$unit', CAST($y AS timestamp)) - date_trunc('$unit', CAST($x AS timestamp)))) / $div) AS integer)";
    }
    throw new RuntimeException('DATEDIFF unit not translated: ' . $unit);
}

/** CAST(x AS type): keep NVARCHAR(n) truncation and case-insensitivity. */
function pg_rewrite_cast(array &$t, int $i, int $open, int $close): void
{
    // Find AS at depth 1.
    $depth = 0;
    $as = null;
    for ($j = $open; $j < $close; $j++) {
        if ($t[$j][0] === 'op' && $t[$j][1] === '(') { $depth++; }
        if ($t[$j][0] === 'op' && $t[$j][1] === ')') { $depth--; }
        if ($depth === 1 && pg_is($t, $j, 'AS')) {
            $as = $j;
        }
    }
    if ($as === null) {
        return;
    }
    $expr = trim(pg_unlex(array_slice($t, $open + 1, $as - $open - 1)));
    $type = trim(pg_unlex(array_slice($t, $as + 1, $close - $as - 1)));
    $u = strtoupper(preg_replace('/\s+/', '', $type));
    if (preg_match('/^N?(VAR)?CHAR(\((\d+)\))?$/', $u, $m)) {
        $len = $m[3] ?? '';
        $len = $len === '' ? ($m[1] === 'VAR' ? '30' : '1') : $len;
        $pg = 'CAST(CAST(' . $expr . ' AS ' . ($m[1] === 'VAR' ? 'varchar' : 'char') . '(' . $len . ')) AS public.citext)';
    } else {
        $pg = 'CAST(' . $expr . ' AS ' . pg_cast_type($type) . ')';
    }
    pg_replace($t, $i, $close, $pg);
}

/**
 * LIKE to ILIKE; "+" beside a string literal to "||" (in T-SQL a plus
 * with text on either side joins strings).
 */
function pg_pass_keywords(array $t): array
{
    foreach ($t as $i => $x) {
        if ($x[0] === 'id' && strcasecmp($x[1], 'LIKE') === 0) {
            $t[$i] = ['id', 'ILIKE'];
        }
        if ($x[0] === 'op' && $x[1] === '+') {
            $p = pg_prev($t, $i);
            $nx = pg_next($t, $i);
            if (($p !== null && $t[$p][0] === 'str') || ($nx !== null && $t[$nx][0] === 'str')) {
                $t[$i] = ['op', '||'];
            }
        }
    }
    return $t;
}

/** ORDER BY items get SQL Server's null placement. */
function pg_pass_order_nulls(array $t): array
{
    $n = count($t);
    $inserts = [];                                     // index => text to append after token
    for ($i = 0; $i < $n; $i++) {
        if (!pg_is($t, $i, 'ORDER')) {
            continue;
        }
        $by = pg_next($t, $i);
        if (!pg_is($t, $by, 'BY')) {
            continue;
        }
        $depth = 0;
        $itemEnd = null;
        $lastSig = null;
        $hasNulls = false;
        $dir = null;
        for ($j = $by + 1; $j <= $n; $j++) {
            $end = $j === $n;
            if (!$end) {
                $x = $t[$j];
                if ($x[0] === 'op' && $x[1] === '(') { $depth++; }
                if ($x[0] === 'op' && $x[1] === ')') {
                    if ($depth === 0) { $end = true; } else { $depth--; }
                }
                if (!$end && $depth === 0 && (($x[0] === 'op' && $x[1] === ';')
                        || ($x[0] === 'id' && in_array(strtoupper($x[1]), PG_CLAUSE_END, true)))) {
                    $end = true;
                }
            }
            $comma = !$end && $depth === 0 && $t[$j][0] === 'op' && $t[$j][1] === ',';
            if ($end || $comma) {
                if ($lastSig !== null && !$hasNulls) {
                    $inserts[$lastSig] = $dir === 'DESC' ? ' NULLS LAST' : ' NULLS FIRST';
                }
                if ($end) {
                    break;
                }
                $lastSig = null;
                $hasNulls = false;
                $dir = null;
                continue;
            }
            $x = $t[$j];
            if ($x[0] === 'ws') {
                continue;
            }
            if ($depth === 0 && $x[0] === 'id') {
                $w = strtoupper($x[1]);
                if ($w === 'NULLS') { $hasNulls = true; }
                if ($w === 'DESC' || $w === 'ASC') { $dir = $w; }
            }
            $lastSig = $j;
        }
    }
    krsort($inserts);
    foreach ($inserts as $at => $text) {
        array_splice($t, $at + 1, 0, [['ws', $text]]);
    }
    return $t;
}

/**
 * TOP n to LIMIT n, and locking hints to FOR UPDATE, each at the end of its
 * own query block (a sub-query keeps its LIMIT inside its parentheses).
 */
function pg_pass_top_and_locks(array $t): array
{
    $n = count($t);
    $appends = [];                                     // block end index => [limit, forUpdate]
    $blocks = [];                                      // SELECT index => end index
    $blockEnd = function (int $start) use ($t, $n): int {
        $depth = 0;
        for ($j = $start + 1; $j < $n; $j++) {
            $x = $t[$j];
            if ($x[0] === 'op' && $x[1] === '(') { $depth++; }
            if ($x[0] === 'op' && $x[1] === ')') {
                if ($depth === 0) { return $j; }
                $depth--;
            }
            if ($depth === 0 && (($x[0] === 'op' && $x[1] === ';')
                    || ($x[0] === 'id' && in_array(strtoupper($x[1]), ['UNION', 'EXCEPT', 'INTERSECT'], true)))) {
                return $j;
            }
        }
        return $n;
    };
    // The innermost SELECT that contains index $k.
    $owner = function (int $k) use ($t, $blockEnd): ?int {
        $depth = 0;
        for ($j = $k; $j >= 0; $j--) {
            $x = $t[$j];
            if ($x[0] === 'op' && $x[1] === ')') { $depth++; }
            if ($x[0] === 'op' && $x[1] === '(') { if ($depth > 0) { $depth--; } }
            if ($depth === 0 && pg_is($t, $j, 'SELECT') && $blockEnd($j) > $k) {
                return $j;
            }
        }
        return null;
    };
    for ($i = 0; $i < $n; $i++) {
        if (pg_is($t, $i, 'TOP')) {
            $p = pg_prev($t, $i);
            if (!(pg_is($t, $p, 'SELECT') || pg_is($t, $p, 'DISTINCT') || pg_is($t, $p, 'ALL'))) {
                continue;
            }
            $v = pg_next($t, $i);
            if (pg_isop($t, $v, '(')) {
                $c = pg_close($t, $v);
                $limit = trim(pg_unlex(array_slice($t, $v + 1, $c - $v - 1)));
                $last = $c;
            } else {
                $limit = $t[$v][1];
                $last = $v;
            }
            $after = pg_next($t, $last);
            if (pg_is($t, $after, 'PERCENT') || (pg_is($t, $after, 'WITH') && pg_is($t, pg_next($t, $after), 'TIES'))) {
                throw new RuntimeException('TOP PERCENT and WITH TIES are not translated.');
            }
            $sel = $owner($i);
            for ($k = $i; $k <= $last; $k++) {
                $t[$k] = ['ws', $k === $i ? ' ' : ''];
            }
            if ($sel !== null) {
                $end = $blockEnd($sel);
                $appends[$end]['limit'] = $limit;
            }
            continue;
        }
        if (pg_is($t, $i, 'WITH')) {
            $o = pg_next($t, $i);
            if (!pg_isop($t, $o, '(')) {
                continue;
            }
            $h = pg_next($t, $o);
            if ($h === null || $t[$h][0] !== 'id' || !in_array(strtoupper($t[$h][1]), PG_TABLE_HINTS, true)) {
                continue;
            }
            $c = pg_close($t, $o);
            $words = strtoupper(pg_unlex(array_slice($t, $o + 1, $c - $o - 1)));
            $lock = (bool)preg_match('/\b(UPDLOCK|XLOCK|TABLOCKX)\b/', $words);
            $sel = $owner($i);
            for ($k = $i; $k <= $c; $k++) {
                $t[$k] = ['ws', $k === $i ? ' ' : ''];
            }
            if ($lock && $sel !== null) {
                $appends[$blockEnd($sel)]['lock'] = true;
            }
        }
    }
    krsort($appends);
    foreach ($appends as $at => $a) {
        $text = '';
        if (isset($a['limit'])) {
            $text .= ' LIMIT ' . $a['limit'];
        }
        if (!empty($a['lock'])) {
            $text .= ' FOR UPDATE';
        }
        // Before a trailing ';' or ')', after any trailing whitespace is kept.
        array_splice($t, $at, 0, [['ws', $text . ($at < $n ? ' ' : '')]]);
    }
    return $t;
}

/**
 * Lower-case result name => the spelling the code reads. The query's own
 * identifiers win (an alias, or a column written as the code spells it),
 * then the SQL Server schema for SELECT *.
 */
function pg_key_map(string $sql): array
{
    static $cache = [];
    if (isset($cache[$sql])) {
        return $cache[$sql];
    }
    $map = PG_COLUMN_NAMES;
    $t = pg_lex($sql);
    $alias = [];
    foreach ($t as $i => $x) {
        if ($x[0] === 'id' || $x[0] === 'bid') {
            $name = $x[1];
            if (!preg_match('/^[A-Za-z_]\w*$/', $name)) {
                continue;
            }
            $l = strtolower($name);
            $p = pg_prev($t, $i);
            if (pg_is($t, $p, 'AS')) {
                $alias[$l] = $name;
            } elseif ($name !== $l || !isset($map[$l])) {
                $map[$l] = $name;
            }
        }
    }
    foreach ($alias as $l => $name) {
        $map[$l] = $name;
    }
    foreach ($map as $l => $name) {
        if ($l === $name) {
            unset($map[$l]);
        }
    }
    if (count($cache) > 4000) {
        $cache = [];
    }
    return $cache[$sql] = $map;
}

/** Rename the keys of one fetched row. */
function pg_rekey(array $row, array $map): array
{
    $out = [];
    foreach ($row as $k => $v) {
        $out[is_string($k) ? ($map[$k] ?? $k) : $k] = $v;
    }
    return $out;
}

/**
 * The PDO connection of one register schema. Every statement is
 * translated on the way in; every row is re-keyed on the way out.
 */
class FacilitiesPgPdo extends PDO
{
    public string $register;
    private int $savepoint = 0;

    public function __construct(string $dsn, string $user, string $password, string $register, string $timezone)
    {
        parent::__construct($dsn, $user, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Client-side binding: values arrive as literals PostgreSQL types
            // from their context, so "? IS NULL" and "LIMIT ?" work as they
            // do on SQL Server.
            PDO::ATTR_EMULATE_PREPARES   => true,
            // pdo_sqlsrv returns every value as a string; so does this.
            PDO::ATTR_STRINGIFY_FETCHES  => true,
            PDO::ATTR_STATEMENT_CLASS    => [FacilitiesPgStatement::class, []],
        ]);
        $this->register = $register;
        parent::exec('SET search_path TO ' . $register . ', public');
        parent::exec("SET TIME ZONE '" . str_replace("'", "''", $timezone) . "'");
        parent::exec("SET application_name TO 'priops-" . $register . "'");
    }

    public function translate(string $sql): string
    {
        return pg_translate($sql, $this->register);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $stmt = parent::prepare($this->translate($query), $options);
        if ($stmt instanceof FacilitiesPgStatement) {
            $stmt->attach($this, pg_key_map($query));
        }
        return $stmt;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $stmt = $this->prepare($query);
        $stmt->execute();
        if ($fetchMode !== null) {
            $stmt->setFetchMode($fetchMode, ...$fetchModeArgs);
        }
        return $stmt;
    }

    public function exec(string $statement): int|false
    {
        $sql = $this->translate($statement);
        return $this->guarded(fn() => parent::exec($sql));
    }

    /** Run $fn; inside a transaction, under a savepoint (statement-level rollback). */
    public function guarded(callable $fn): mixed
    {
        if (!$this->inTransaction()) {
            return $fn();
        }
        $name = 'priops_sp' . (++$this->savepoint);
        parent::exec('SAVEPOINT ' . $name);
        try {
            $r = $fn();
        } catch (Throwable $e) {
            parent::exec('ROLLBACK TO SAVEPOINT ' . $name);
            parent::exec('RELEASE SAVEPOINT ' . $name);
            throw $e;
        }
        parent::exec('RELEASE SAVEPOINT ' . $name);
        return $r;
    }

    /** SQL sent untranslated, for tools that write PostgreSQL directly. */
    public function execNative(string $sql): int|false
    {
        return parent::exec($sql);
    }

    public function queryNative(string $sql): PDOStatement|false
    {
        $stmt = parent::prepare($sql);
        $stmt->execute();
        return $stmt;
    }
}

class FacilitiesPgStatement extends PDOStatement
{
    private ?FacilitiesPgPdo $owner = null;
    private array $keys = [];

    protected function __construct()
    {
    }

    public function attach(FacilitiesPgPdo $owner, array $keys): void
    {
        $this->owner = $owner;
        $this->keys = $keys;
    }

    public function execute(?array $params = null): bool
    {
        if ($params !== null) {
            foreach ($params as $k => $v) {
                if (is_bool($v)) {
                    $params[$k] = $v ? 1 : 0;          // a PHP bool binds as 't'/'f', which smallint refuses
                }
            }
        }
        if ($this->owner === null) {
            return parent::execute($params);
        }
        return $this->owner->guarded(fn() => parent::execute($params));
    }

    private function wantsKeys(int $mode): bool
    {
        $base = $mode & ~(PDO::FETCH_GROUP | PDO::FETCH_UNIQUE);
        return in_array($base, [PDO::FETCH_DEFAULT, PDO::FETCH_ASSOC, PDO::FETCH_BOTH, PDO::FETCH_NAMED], true);
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        $row = parent::fetch($mode, $cursorOrientation, $cursorOffset);
        if (is_array($row) && $this->keys && $this->wantsKeys($mode)) {
            return pg_rekey($row, $this->keys);
        }
        return $row;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = parent::fetchAll($mode, ...$args);
        if (!$this->keys || !$this->wantsKeys($mode)) {
            return $rows;
        }
        $grouped = ($mode & (PDO::FETCH_GROUP | PDO::FETCH_UNIQUE)) !== 0;
        foreach ($rows as $k => $row) {
            if ($grouped && ($mode & PDO::FETCH_GROUP)) {
                foreach ($row as $kk => $r) {
                    $rows[$k][$kk] = is_array($r) ? pg_rekey($r, $this->keys) : $r;
                }
            } elseif (is_array($row)) {
                $rows[$k] = pg_rekey($row, $this->keys);
            }
        }
        return $rows;
    }

    public function getIterator(): Iterator
    {
        while (($row = $this->fetch()) !== false) {
            yield $row;
        }
    }
}
