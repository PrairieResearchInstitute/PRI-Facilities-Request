<?php
declare(strict_types=1);

/**
 * How tools/pg/load.php compares a source value with the value PostgreSQL
 * stored, by the target column's type. Kept apart so tests/pg-load.php can
 * check the rules without running a load.
 *
 * pdo_sqlsrv returns every value as text: datetime2 with seven fractional
 * digits (PostgreSQL keeps six and rounds), decimals below one without their
 * leading zero (".5000"), bits as "1" and "0". Text is compared exactly,
 * case included: citext compares without case, but it stores what it was
 * given, and the copy must be what LocalDB held.
 */

function norm_value(?string $v, string $type): ?string
{
    if ($v === null) {
        return null;
    }
    if (in_array($type, ['integer', 'smallint', 'bigint'], true)) {
        return (string)(int)$v;
    }
    if (in_array($type, ['numeric', 'real', 'double precision'], true)) {
        $s = number_format((float)$v, 6, '.', '');
        return rtrim(rtrim($s, '0'), '.');
    }
    if ($type === 'timestamp without time zone') {
        if (!preg_match('/^(\d{4}-\d\d-\d\d)[ T](\d\d:\d\d:\d\d)(?:\.(\d+))?/', $v, $m)) {
            return $v;
        }
        $digits = substr(str_pad($m[3] ?? '', 7, '0'), 0, 7);
        $micro = intdiv((int)$digits + 5, 10);              // seven digits to six, half up
        $base = strtotime($m[1] . ' ' . $m[2] . ' UTC');
        if ($micro >= 1000000) {
            $base++;
            $micro -= 1000000;
        }
        return gmdate('Y-m-d H:i:s', $base) . '.' . str_pad((string)$micro, 6, '0', STR_PAD_LEFT);
    }
    if ($type === 'date') {
        return substr($v, 0, 10);
    }
    return $v;
}
