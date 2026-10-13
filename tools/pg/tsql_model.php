<?php
declare(strict_types=1);

/**
 * The SQL Server schema as a column model, rebuilt from sql/00 to sql/35.
 *
 * The ten LocalDB databases were built by running these scripts (and the
 * split tools, which copy tables whole), so replaying their DDL in order
 * gives every table, column, type, default, key and check the application
 * relies on, without opening a database. The PostgreSQL baseline is written
 * from this model by tools/pg/make_baseline.php.
 *
 * Every conditional is taken as having run: the live databases went through
 * every script, and an "IF COL_LENGTH(...) IS NULL ... ADD" whose column is
 * already in the model is skipped, which is what the condition did.
 *
 * Library: tsql_model() returns the model. CLI:
 *   php tools/pg/tsql_model.php            prints the model as JSON
 *   php tools/pg/tsql_model.php --summary  one line per table
 */

/** Columns added by tools/recover_dropped_data.php (all nullable). */
const TSQL_TOOL_COLUMNS = [
    'ref.Buildings' => [
        'Name2' => 'NVARCHAR(300)', 'AddressName' => 'NVARCHAR(200)', 'StateCode' => 'NVARCHAR(10)',
        'PostalCode' => 'NVARCHAR(20)', 'LocationClass' => 'NVARCHAR(30)', 'BuildingType' => 'NVARCHAR(60)',
        'Ownership' => 'NVARCHAR(40)', 'Condition' => 'NVARCHAR(30)', 'SurveyCode' => 'NVARCHAR(10)',
        'PriPriority' => 'INT',
    ],
    // Added on Equipment's copy only; Work's ref.People only. The baseline
    // gives every copy of the table the column, which the loader allows
    // because each is nullable.
    'gis.Inventory' => [
        'PurchaseDocument' => 'NVARCHAR(60)', 'PoNumber' => 'NVARCHAR(30)', 'RequisitionNumber' => 'NVARCHAR(30)',
        'PurchaseDocKind' => 'NVARCHAR(12)', 'AccumulatedDepreciation' => 'DECIMAL(14,2)',
    ],
    'ref.People' => [
        'PositionType' => 'NVARCHAR(60)', 'HomeBuilding' => 'NVARCHAR(60)', 'HomeRoom' => 'NVARCHAR(40)',
        'FieldStation' => 'NVARCHAR(120)', 'RoomText' => 'NVARCHAR(200)',
    ],
];

/** Strip -- and block comments, leaving string literals intact. */
function tsql_strip_comments(string $sql): string
{
    $out = '';
    $n = strlen($sql);
    for ($i = 0; $i < $n; $i++) {
        $c = $sql[$i];
        if ($c === "'") {
            $j = $i + 1;
            while ($j < $n) {
                if ($sql[$j] === "'") {
                    if ($j + 1 < $n && $sql[$j + 1] === "'") { $j += 2; continue; }
                    break;
                }
                $j++;
            }
            $out .= substr($sql, $i, $j - $i + 1);
            $i = $j;
            continue;
        }
        if ($c === '-' && ($sql[$i + 1] ?? '') === '-') {
            while ($i < $n && $sql[$i] !== "\n") { $i++; }
            $out .= "\n";
            continue;
        }
        if ($c === '/' && ($sql[$i + 1] ?? '') === '*') {
            $end = strpos($sql, '*/', $i + 2);
            $i = $end === false ? $n : $end + 1;
            $out .= ' ';
            continue;
        }
        $out .= $c;
    }
    return $out;
}

/**
 * Replace each EXEC('...') / EXEC(N'...') with its unescaped body and a
 * semicolon, recursively, so the DDL inside dynamic SQL is read like any
 * other statement.
 */
function tsql_expand_exec(string $sql): string
{
    $guard = 0;
    while (preg_match('/\bEXEC(?:UTE)?\s*\(\s*N?\'/i', $sql, $m, PREG_OFFSET_CAPTURE) && $guard++ < 10000) {
        $start = $m[0][1];
        $q = $start + strlen($m[0][0]) - 1;          // the opening quote
        $j = $q + 1;
        $n = strlen($sql);
        while ($j < $n) {
            if ($sql[$j] === "'") {
                if (($sql[$j + 1] ?? '') === "'") { $j += 2; continue; }
                break;
            }
            $j++;
        }
        $body = str_replace("''", "'", substr($sql, $q + 1, $j - $q - 1));
        // EXEC('... DROP CONSTRAINT ' + @name): the name is only known at run
        // time, so the body ends in a marker the replay resolves from hints.
        if (preg_match('/^\s*\+/', substr($sql, $j + 1))) {
            $body = rtrim(rtrim($body), '[') . ' @dynamic';
        }
        $close = strpos($sql, ')', $j);
        if ($close === false) {
            break;
        }
        $sql = substr($sql, 0, $start) . "\n" . $body . "\n;\n" . substr($sql, $close + 1);
    }
    return $sql;
}

/** Tokens: [type, text, upper]. Types: id, str, num, op. Brackets unwrap to id. */
function tsql_tokens(string $sql): array
{
    $t = [];
    $n = strlen($sql);
    $i = 0;
    while ($i < $n) {
        $c = $sql[$i];
        if (ctype_space($c)) { $i++; continue; }
        if ($c === "'" || (($c === 'N' || $c === 'n') && ($sql[$i + 1] ?? '') === "'")) {
            $j = $c === "'" ? $i + 1 : $i + 2;
            $buf = '';
            while ($j < $n) {
                if ($sql[$j] === "'") {
                    if (($sql[$j + 1] ?? '') === "'") { $buf .= "'"; $j += 2; continue; }
                    break;
                }
                $buf .= $sql[$j++];
            }
            $t[] = ['str', $buf, $buf];
            $i = $j + 1;
            continue;
        }
        if ($c === '[') {
            $j = strpos($sql, ']', $i);
            $name = substr($sql, $i + 1, $j - $i - 1);
            $t[] = ['id', $name, strtoupper($name)];
            $i = $j + 1;
            continue;
        }
        if (ctype_alpha($c) || $c === '_' || $c === '@' || $c === '#') {
            $j = $i + 1;
            while ($j < $n && (ctype_alnum($sql[$j]) || $sql[$j] === '_' || $sql[$j] === '@' || $sql[$j] === '#' || $sql[$j] === '$')) { $j++; }
            $w = substr($sql, $i, $j - $i);
            $t[] = ['id', $w, strtoupper($w)];
            $i = $j;
            continue;
        }
        if (ctype_digit($c) || ($c === '.' && ctype_digit($sql[$i + 1] ?? ''))) {
            $j = $i + 1;
            while ($j < $n && (ctype_digit($sql[$j]) || $sql[$j] === '.')) { $j++; }
            $t[] = ['num', substr($sql, $i, $j - $i), substr($sql, $i, $j - $i)];
            $i = $j;
            continue;
        }
        $two = substr($sql, $i, 2);
        if (in_array($two, ['<=', '>=', '<>', '!=', '::'], true)) {
            $t[] = ['op', $two, $two];
            $i += 2;
            continue;
        }
        $t[] = ['op', $c, $c];
        $i++;
    }
    return $t;
}

/** Render tokens back to SQL text (strings re-quoted, N dropped). */
function tsql_render(array $toks): string
{
    $out = '';
    $prev = null;
    foreach ($toks as $tk) {
        $s = $tk[0] === 'str' ? "'" . str_replace("'", "''", $tk[1]) . "'" : $tk[1];
        if ($out !== '' && $prev !== null) {
            $glue = !($tk[1] === ')' || $tk[1] === ',' || $tk[1] === '.' || $prev[1] === '(' || $prev[1] === '.')
                 || ($tk[0] !== 'op' && $prev[0] !== 'op' && $prev[1] !== '.' && $tk[1] !== '.');
            if ($tk[1] === '(' && $prev[0] === 'id' && !in_array($prev[2], ['IN', 'AND', 'OR', 'NOT', 'CHECK', 'DEFAULT'], true)) {
                $glue = false;
            }
            $out .= $glue ? ' ' : '';
        }
        $out .= $s;
        $prev = $tk;
    }
    return $out;
}

/** Statement-start words that end a DDL statement when met at depth 0. */
const TSQL_STOP = ['IF', 'BEGIN', 'END', 'EXEC', 'EXECUTE', 'INSERT', 'UPDATE', 'DELETE', 'CREATE',
                   'ALTER', 'DROP', 'PRINT', 'DECLARE', 'SET', 'SELECT', 'WHILE', 'ELSE', 'RETURN', 'GO', 'MERGE', 'WITH'];

/** Index of the token after the statement that starts at $i (exclusive end). */
function tsql_statement_end(array $t, int $i, int $skipFirst): int
{
    $depth = 0;
    $n = count($t);
    for ($j = $i + $skipFirst; $j < $n; $j++) {
        $x = $t[$j];
        if ($x[1] === '(') { $depth++; continue; }
        if ($x[1] === ')') { $depth--; if ($depth < 0) { return $j; } continue; }
        if ($depth > 0) { continue; }
        if ($x[1] === ';') { return $j; }
        if ($x[0] === 'id' && in_array($x[2], TSQL_STOP, true)) {
            $p = $t[$j - 1][2] ?? '';
            // ON DELETE CASCADE, ON UPDATE ..., SET NULL / SET DEFAULT inside a key
            if (($x[2] === 'DELETE' || $x[2] === 'UPDATE') && $p === 'ON') { continue; }
            if ($x[2] === 'SET' && ($p === 'DELETE' || $p === 'UPDATE')) { continue; }
            if ($x[2] === 'ALTER' && ($t[$j + 1][2] ?? '') === 'COLUMN') { continue; }
            if ($x[2] === 'DROP' && in_array($t[$j + 1][2] ?? '', ['COLUMN', 'CONSTRAINT'], true)) { continue; }
            if ($x[2] === 'WITH' && in_array($t[$j + 1][2] ?? '', ['CHECK', 'NOCHECK'], true)) { continue; }
            return $j;
        }
    }
    return $n;
}

/** Split a token range on commas at depth 0. */
function tsql_split_commas(array $t): array
{
    $parts = [];
    $cur = [];
    $depth = 0;
    foreach ($t as $x) {
        if ($x[1] === '(') { $depth++; }
        if ($x[1] === ')') { $depth--; }
        if ($x[1] === ',' && $depth === 0) {
            $parts[] = $cur;
            $cur = [];
            continue;
        }
        $cur[] = $x;
    }
    if ($cur) {
        $parts[] = $cur;
    }
    return $parts;
}

/** Read a qualified name at $i: returns [name 'schema.Table', next index]. */
function tsql_qname(array $t, int $i): array
{
    $a = $t[$i][1];
    if (($t[$i + 1][1] ?? '') === '.') {
        $b = $t[$i + 2][1];
        if (($t[$i + 3][1] ?? '') === '.') {                 // db.schema.table
            return [$b . '.' . $t[$i + 4][1], $i + 5];
        }
        return [$a . '.' . $b, $i + 3];
    }
    return ['dbo.' . $a, $i + 1];
}

/** The contents of a parenthesised group starting at $i (t[$i] is '('). */
function tsql_group(array $t, int $i): array
{
    $depth = 0;
    $n = count($t);
    for ($j = $i; $j < $n; $j++) {
        if ($t[$j][1] === '(') { $depth++; }
        if ($t[$j][1] === ')') { $depth--; if ($depth === 0) { return [array_slice($t, $i + 1, $j - $i - 1), $j + 1]; } }
    }
    return [array_slice($t, $i + 1), $n];
}

/** Column names from a token list "a, b DESC, c". */
function tsql_colnames(array $t): array
{
    $cols = [];
    foreach (tsql_split_commas($t) as $p) {
        if ($p) {
            $cols[] = $p[0][1];
        }
    }
    return $cols;
}

/** Parse one column definition or table constraint into the table model. */
function tsql_apply_element(array &$tbl, array $e, string $table, array &$warn): void
{
    if (!$e) {
        return;
    }
    $cname = null;
    if ($e[0][2] === 'CONSTRAINT') {
        $cname = $e[1][1];
        $e = array_slice($e, 2);
    }
    $k = $e[0][2];
    if ($k === 'PRIMARY' || $k === 'UNIQUE') {
        $at = 1;
        while (isset($e[$at]) && $e[$at][1] !== '(') { $at++; }   // KEY, CLUSTERED...
        [$g] = tsql_group($e, $at);
        $cols = tsql_colnames($g);
        if ($k === 'PRIMARY') {
            $tbl['pk'] = $cols;
        } else {
            $tbl['uniques'][] = ['name' => $cname, 'cols' => $cols];
        }
        return;
    }
    if ($k === 'FOREIGN') {
        [$g, $next] = tsql_group($e, 2);
        $cols = tsql_colnames($g);
        tsql_parse_references($tbl, array_slice($e, $next), $cols, $cname);
        return;
    }
    if ($k === 'CHECK') {
        [$g] = tsql_group($e, 1);
        $tbl['checks'][] = ['name' => $cname, 'expr' => tsql_render($g)];
        return;
    }
    if ($k === 'DEFAULT' && $cname !== null) {        // CONSTRAINT x DEFAULT v FOR col
        $for = null;
        foreach ($e as $ix => $x) { if ($x[2] === 'FOR') { $for = $ix; } }
        if ($for !== null) {
            $col = $e[$for + 1][1];
            if (isset($tbl['columns'][$col])) {
                $tbl['columns'][$col]['default'] = tsql_render(array_slice($e, 1, $for - 1));
            }
        }
        return;
    }
    // A column.
    $col = $e[0][1];
    $i = 1;
    $type = strtoupper($e[$i][1] ?? '');
    $i++;
    if (($e[$i][1] ?? '') === '(') {
        [$g, $i] = tsql_group($e, $i);
        $type .= '(' . strtoupper(tsql_render($g)) . ')';
        $type = str_replace(' ', '', $type);
    }
    $def = ['type' => $type, 'null' => true, 'default' => null, 'identity' => false];
    $n = count($e);
    while ($i < $n) {
        $w = $e[$i][2];
        if ($w === 'IDENTITY') {
            $def['identity'] = true;
            $def['null'] = false;
            $i++;
            if (($e[$i][1] ?? '') === '(') { [, $i] = tsql_group($e, $i); }
            continue;
        }
        if ($w === 'NOT' && ($e[$i + 1][2] ?? '') === 'NULL') { $def['null'] = false; $i += 2; continue; }
        if ($w === 'NULL') { $i++; continue; }
        if ($w === 'CONSTRAINT') { $i += 2; continue; }
        if ($w === 'DEFAULT') {
            $j = $i + 1;
            $buf = [];
            $depth = 0;
            while ($j < $n) {
                $x = $e[$j];
                if ($depth === 0 && $x[0] === 'id' && in_array($x[2], ['NOT', 'NULL', 'PRIMARY', 'UNIQUE', 'REFERENCES', 'CHECK', 'CONSTRAINT', 'IDENTITY'], true)) { break; }
                if ($x[1] === '(') { $depth++; }
                if ($x[1] === ')') { $depth--; }
                $buf[] = $x;
                $j++;
            }
            $def['default'] = tsql_render($buf);
            $i = $j;
            continue;
        }
        if ($w === 'PRIMARY') {
            $tbl['pk'] = [$col];
            $def['null'] = false;
            $i += 2;
            while (isset($e[$i]) && in_array($e[$i][2], ['CLUSTERED', 'NONCLUSTERED'], true)) { $i++; }
            continue;
        }
        if ($w === 'UNIQUE') {
            $tbl['uniques'][] = ['name' => null, 'cols' => [$col]];
            $i++;
            while (isset($e[$i]) && in_array($e[$i][2], ['CLUSTERED', 'NONCLUSTERED'], true)) { $i++; }
            continue;
        }
        if ($w === 'CHECK') {
            [$g, $i] = tsql_group($e, $i + 1);
            $tbl['checks'][] = ['name' => null, 'expr' => tsql_render($g)];
            continue;
        }
        if ($w === 'REFERENCES' || $w === 'FOREIGN') {
            $rest = array_slice($e, $w === 'FOREIGN' ? $i + 2 : $i);
            $consumed = tsql_parse_references($tbl, $rest, [$col], null);
            $i += ($w === 'FOREIGN' ? 2 : 0) + $consumed;
            continue;
        }
        $warn[] = "$table.$col: ignored word {$e[$i][1]}";
        $i++;
    }
    if (isset($tbl['columns'][$col])) {
        return;                                    // ADD of a column that exists: the IF guard skipped it
    }
    $tbl['columns'][$col] = $def;
}

/** REFERENCES t(c) [ON DELETE x] [ON UPDATE x]; returns tokens consumed. */
function tsql_parse_references(array &$tbl, array $e, array $cols, ?string $name): int
{
    if (($e[0][2] ?? '') !== 'REFERENCES') {
        return 0;
    }
    [$ref, $i] = tsql_qname($e, 1);
    $refcols = [];
    if (($e[$i][1] ?? '') === '(') {
        [$g, $i] = tsql_group($e, $i);
        $refcols = tsql_colnames($g);
    }
    $onDelete = null;
    while (($e[$i][2] ?? '') === 'ON') {
        $what = $e[$i + 1][2];
        $act = $e[$i + 2][2];
        $len = 3;
        if ($act === 'NO' || $act === 'SET') { $act .= ' ' . $e[$i + 3][2]; $len = 4; }
        if ($what === 'DELETE') { $onDelete = $act; }
        $i += $len;
    }
    $tbl['fks'][] = ['name' => $name, 'cols' => $cols, 'ref' => $ref, 'refcols' => $refcols, 'onDelete' => $onDelete];
    return $i;
}

function tsql_new_table(string $q): array
{
    [$s, $n] = explode('.', $q, 2);
    return ['schema' => $s, 'name' => $n, 'columns' => [], 'pk' => [], 'uniques' => [], 'checks' => [], 'fks' => [], 'indexes' => []];
}

/** Find a table in the model case-insensitively; returns its key or null. */
function tsql_find(array $model, string $q): ?string
{
    foreach ($model as $k => $_) {
        if (strcasecmp($k, $q) === 0) {
            return $k;
        }
    }
    return null;
}

/** Replay one script's DDL into the model. */
function tsql_replay(array &$model, string $sql, string $file, array &$warn): void
{
    $sql = tsql_strip_comments(tsql_expand_exec(tsql_strip_comments($sql)));
    $t = tsql_tokens($sql);
    $n = count($t);
    for ($i = 0; $i < $n; $i++) {
        $w = $t[$i][2];
        if ($t[$i][0] !== 'id') {
            continue;
        }
        $w2 = $t[$i + 1][2] ?? '';
        if ($w === 'CREATE' && $w2 === 'TABLE') {
            [$q, $j] = tsql_qname($t, $i + 2);
            $key = tsql_find($model, $q) ?? $q;
            if (isset($model[$key])) {
                $i = $j;                           // IF OBJECT_ID IS NULL guarded a second CREATE
                continue;
            }
            [$g, $next] = tsql_group($t, $j);
            $tbl = tsql_new_table($q);
            foreach (tsql_split_commas($g) as $e) {
                tsql_apply_element($tbl, $e, $q, $warn);
            }
            $tbl['file'] = $file;
            $model[$q] = $tbl;
            $i = $next - 1;
            continue;
        }
        if ($w === 'ALTER' && $w2 === 'TABLE') {
            [$q, $j] = tsql_qname($t, $i + 2);
            $end = tsql_statement_end($t, $j, 0);
            $body = array_slice($t, $j, $end - $j);
            $key = tsql_find($model, $q);
            if ($key === null) {
                $warn[] = "$file: ALTER of unknown table $q";
                $i = $end - 1;
                continue;
            }
            while ($body && in_array($body[0][2], ['WITH', 'CHECK', 'NOCHECK'], true)) {
                array_shift($body);
            }
            $act = $body[0][2] ?? '';
            if ($act === 'ADD') {
                foreach (tsql_split_commas(array_slice($body, 1)) as $e) {
                    tsql_apply_element($model[$key], $e, $q, $warn);
                }
            } elseif ($act === 'ALTER' && ($body[1][2] ?? '') === 'COLUMN') {
                $col = $body[2][1];
                $ck = null;
                foreach ($model[$key]['columns'] as $c => $_) { if (strcasecmp($c, $col) === 0) { $ck = $c; } }
                if ($ck !== null) {
                    $tmp = tsql_new_table($q);
                    tsql_apply_element($tmp, array_slice($body, 2), $q, $warn);
                    $nd = $tmp['columns'][$col];
                    $model[$key]['columns'][$ck]['type'] = $nd['type'];
                    $model[$key]['columns'][$ck]['null'] = $nd['null'];
                } else {
                    $warn[] = "$file: ALTER COLUMN of unknown $q.$col";
                }
            } elseif ($act === 'DROP') {
                $what = $body[1][2] ?? '';
                if ($what === 'COLUMN') {
                    foreach (tsql_split_commas(array_slice($body, 2)) as $p) {
                        $col = $p[0][1];
                        foreach ($model[$key]['columns'] as $c => $_) {
                            if (strcasecmp($c, $col) === 0) { unset($model[$key]['columns'][$c]); }
                        }
                    }
                } elseif ($what === 'CONSTRAINT' && ($body[2][1] ?? '') === '@dynamic') {
                    // The script found the constraint by its definition or its
                    // column; drop the check whose expression names the hint.
                    $hint = null;
                    if (preg_match("/definition\s+LIKE\s+'%(\w+)%'/i", $sql, $hm)) {
                        $hint = $hm[1];
                    } elseif (preg_match("/col\.name\s*=\s*'(\w+)'/i", $sql, $hm)) {
                        $hint = $hm[1];
                    }
                    $before = count($model[$key]['checks']);
                    if ($hint !== null) {
                        $model[$key]['checks'] = array_values(array_filter($model[$key]['checks'],
                            fn($c) => stripos($c['expr'], $hint) === false));
                    }
                    $warn[] = "$file: dynamic DROP CONSTRAINT on $q dropped " . ($before - count($model[$key]['checks']))
                            . " check(s) naming " . ($hint ?? '(no hint)');
                } elseif ($what === 'CONSTRAINT') {
                    $cn = $body[2][1];
                    foreach (['uniques', 'checks', 'fks'] as $kind) {
                        $model[$key][$kind] = array_values(array_filter($model[$key][$kind],
                            fn($c) => strcasecmp((string)$c['name'], $cn) !== 0));
                    }
                    $model[$key]['dropped_constraints'][] = $cn;
                }
            } else {
                $warn[] = "$file: ALTER TABLE $q $act not modelled";
            }
            $i = $end - 1;
            continue;
        }
        if ($w === 'CREATE' && in_array($w2, ['INDEX', 'UNIQUE', 'NONCLUSTERED', 'CLUSTERED'], true)) {
            $j = $i + 1;
            $unique = false;
            while (in_array($t[$j][2], ['UNIQUE', 'NONCLUSTERED', 'CLUSTERED'], true)) {
                if ($t[$j][2] === 'UNIQUE') { $unique = true; }
                $j++;
            }
            if ($t[$j][2] !== 'INDEX') {
                continue;
            }
            $iname = $t[$j + 1][1];
            [$q, $k2] = tsql_qname($t, $j + 3);
            [$g, $after] = tsql_group($t, $k2);
            $cols = [];
            foreach (tsql_split_commas($g) as $p) {
                $cols[] = $p[0][1] . ((isset($p[1]) && $p[1][2] === 'DESC') ? ' DESC' : '');
            }
            $where = null;
            $end = tsql_statement_end($t, $after, 0);
            $rest = array_slice($t, $after, $end - $after);
            $include = [];
            for ($r = 0; $r < count($rest); $r++) {
                if ($rest[$r][2] === 'INCLUDE') {
                    [$ig, $r2] = tsql_group($rest, $r + 1);
                    $include = tsql_colnames($ig);
                    $r = $r2 - 1;
                } elseif ($rest[$r][2] === 'WHERE') {
                    $where = tsql_render(array_slice($rest, $r + 1));
                    break;
                }
            }
            $key = tsql_find($model, $q);
            if ($key === null) {
                $warn[] = "$file: index $iname on unknown $q";
            } else {
                $model[$key]['indexes'][$iname] = ['cols' => $cols, 'unique' => $unique, 'where' => $where, 'include' => $include];
            }
            $i = $end - 1;
            continue;
        }
        if ($w === 'DROP' && $w2 === 'TABLE') {
            $j = $i + 2;
            if (($t[$j][2] ?? '') === 'IF') { $j += 2; }
            [$q] = tsql_qname($t, $j);
            $key = tsql_find($model, $q);
            if ($key !== null) {
                unset($model[$key]);
                $warn[] = "$file: DROP TABLE $q";
            }
            continue;
        }
        if ($w === 'DROP' && $w2 === 'INDEX') {
            $iname = $t[$i + 2][1];
            if (($t[$i + 3][2] ?? '') === 'ON') {
                [$q] = tsql_qname($t, $i + 4);
                $key = tsql_find($model, $q);
                if ($key !== null) { unset($model[$key]['indexes'][$iname]); }
            }
            continue;
        }
        if (($w === 'EXEC' || $w === 'EXECUTE') && strcasecmp($w2, 'sp_rename') === 0) {
            $warn[] = "$file: sp_rename not modelled";
        }
    }
}

/** The whole model: every table the scripts create, in script order. */
function tsql_model(?string $sqlDir = null, ?array &$warnings = null): array
{
    $sqlDir = $sqlDir ?? dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'sql';
    $files = glob($sqlDir . DIRECTORY_SEPARATOR . '[0-9][0-9]_*.sql');
    sort($files);
    $model = [];
    $warn = [];
    foreach ($files as $f) {
        $sql = (string)file_get_contents($f);
        $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql);
        foreach (preg_split('/^\s*GO\s*;?\s*$/mi', $sql) as $batch) {
            tsql_replay($model, $batch, basename($f), $warn);
        }
    }
    foreach (TSQL_TOOL_COLUMNS as $q => $cols) {
        foreach ($cols as $c => $type) {
            if (isset($model[$q]) && !isset($model[$q]['columns'][$c])) {
                $model[$q]['columns'][$c] = ['type' => $type, 'null' => true, 'default' => null, 'identity' => false, 'tool' => true];
            }
        }
    }
    $warnings = $warn;
    return $model;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $warn = [];
    $m = tsql_model(null, $warn);
    if (in_array('--summary', $argv, true)) {
        foreach ($m as $q => $tbl) {
            printf("%-28s %3d cols pk=%s fks=%d checks=%d idx=%d (%s)\n", $q, count($tbl['columns']),
                implode('+', $tbl['pk']), count($tbl['fks']), count($tbl['checks']), count($tbl['indexes']), $tbl['file']);
        }
        echo count($m) . " tables\n";
        foreach ($warn as $w) { echo "note: $w\n"; }
    } else {
        echo json_encode(['tables' => $m, 'notes' => $warn], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    }
}
