<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Cached settings reader (spec rule 3: every weight, threshold, and limit
 * reads from app.Settings). The Work database is the canonical read source;
 * the copies in Move and Shop exist so each database stays self-describing
 * for export, and are kept aligned by the reference publish action.
 */

function settings_all(bool $reload = false): array
{
    static $cache = null;
    if ($reload) {
        $cache = null;
    }
    if ($cache === null) {
        $cache = [];
        $stmt = db('work')->query(
            'SELECT SettingKey, SettingValue, ValueType, Label, HelpText, SortOrder
             FROM app.Settings ORDER BY SortOrder'
        );
        foreach ($stmt as $row) {
            $cache[$row['SettingKey']] = $row;
        }
    }
    return $cache;
}

/** Drop the cache after a settings write, so the next read is the new value. */
function settings_forget(): void
{
    settings_all(true);
}

/**
 * Typed setting value. Numbers come back as int or float, everything else
 * as string. Missing key with no default is a hard error: a scoring
 * constant that silently defaults would violate rule 3.
 */
function setting(string $key, $default = null)
{
    $all = settings_all();
    if (!isset($all[$key])) {
        if (func_num_args() >= 2) {
            return $default;
        }
        throw new RuntimeException('Setting not seeded: ' . $key);
    }
    $row = $all[$key];
    if ($row['ValueType'] === 'number') {
        $v = $row['SettingValue'];
        return (str_contains($v, '.')) ? (float)$v : (int)$v;
    }
    return $row['SettingValue'];
}
