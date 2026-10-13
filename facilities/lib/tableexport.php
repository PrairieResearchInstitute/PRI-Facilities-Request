<?php
declare(strict_types=1);

require_once __DIR__ . '/csvio.php';

require_once __DIR__ . '/db.php';

/**
 * Every table in every database, exportable. The list is read from the
 * catalogue rather than hand maintained, so a table added later is
 * exportable the day it exists, and a request naming anything outside
 * that list is refused rather than run.
 */

/**
 * Schemas that are not ours. Everything else is exported.
 *
 * This was a list of the schemas TO include, and it was written before
 * the rq schema existed. Space requests, key requests, purchase requests
 * and property updates were therefore left out of "download every table"
 * - out of the backup, out of the handover - and nothing said so, because
 * a whitelist that is missing an entry looks exactly like a whitelist
 * that is complete.
 *
 * Naming what to leave out fails the other way: a schema added next year
 * turns up in the export without anybody remembering to add it.
 */
const EXPORT_SKIP_SCHEMAS = ['sys', 'INFORMATION_SCHEMA', 'guest', 'db_owner',
                             'db_accessadmin', 'db_securityadmin', 'db_ddladmin',
                             'db_backupoperator', 'db_datareader', 'db_datawriter',
                             'db_denydatareader', 'db_denydatawriter'];

/** Tables we own in one database: 'schema.table' => row count. */
function export_tables(string $dbName): array
{
    static $cache = [];
    if (isset($cache[$dbName])) {
        return $cache[$dbName];
    }
    $out = [];
    $skip = array_map('strtolower', EXPORT_SKIP_SCHEMAS);
    foreach (db_tables($dbName) as $table => $rows) {
        if (!in_array(strtolower(explode('.', $table, 2)[0]), $skip, true)) {
            $out[$table] = $rows;
        }
    }
    return $cache[$dbName] = $out;
}

/** All three databases at once, for the picker. */
function export_catalogue(): array
{
    $out = [];
    foreach (APP_DB_NAMES as $n) {
        try {
            $out[$n] = export_tables($n);
        } catch (Throwable $e) {
            $out[$n] = [];
        }
    }
    return $out;
}

function export_table_allowed(string $dbName, string $table): bool
{
    return in_array($dbName, APP_DB_NAMES, true)
        && array_key_exists($table, export_tables($dbName));
}

/**
 * Stream one table as CSV. The name is checked against the catalogue
 * before it reaches the query, and quoted as an identifier.
 */
/**
 * Columns whose VALUES are never exported, whatever table they sit in.
 *
 * app.DigestPreferences.Token opens a person's page with no sign-in. This
 * export streams any table in any register to anyone holding the
 * coordinator role, so that token was one download away from being a key
 * to somebody else's account. The column still appears, so the shape of
 * the table stays honest; the value is withheld.
 */
function tableexport_secret_column(string $column): bool
{
    foreach (['token', 'password', 'secret', 'apikey', 'api_key', 'privatekey'] as $needle) {
        if (str_contains(strtolower($column), $needle)) {
            return true;
        }
    }
    return false;
}

function export_table_stream(string $dbName, string $table): void
{
    if (!export_table_allowed($dbName, $table)) {
        http_response_code(400);
        header('Content-Type: text/plain');
        echo 'Unknown table.';
        exit;
    }
    [$schema, $name] = explode('.', $table, 2);
    $quoted = '[' . str_replace(']', ']]', $schema) . '].[' . str_replace(']', ']]', $name) . ']';

    $file = strtolower(APP_DB_LABELS[$dbName] . '_' . str_replace('.', '_', $table)) . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $file . '"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");

    $stmt = db($dbName)->query("SELECT * FROM $quoted");
    $first = true;
    $withheld = [];
    foreach ($stmt as $row) {
        if ($first) {
            foreach (array_keys($row) as $col) {
                if (tableexport_secret_column((string)$col)) {
                    $withheld[(string)$col] = true;
                }
            }
            fputcsv($out, csv_row(array_keys($row)));
            $first = false;
        }
        // Geometry paths and note bodies are long; they still belong in
        // the extract, because the point of an export is completeness.
        $cells = [];
        foreach ($row as $col => $v) {
            $cells[] = isset($withheld[(string)$col])
                ? '(withheld)'
                : ($v === null ? '' : (is_bool($v) ? ($v ? '1' : '0') : (string)$v));
        }
        fputcsv($out, csv_row($cells));
    }
    if ($first) {
        // An empty table still deserves its header row.
        fputcsv($out, csv_row(array_column(db_columns($dbName, $schema . '.' . $name), 'name')));
    }
    fclose($out);
}
