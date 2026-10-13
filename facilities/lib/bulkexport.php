<?php
declare(strict_types=1);

require_once __DIR__ . '/csvio.php';

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/tableexport.php';

/**
 * Every table, as CSVs, in one download.
 *
 * The count used to be ninety-seven, which flattered the schema: there
 * are fifty-three tables, and twenty-two of them exist in all three
 * databases because reference data is replicated and foreign keys may not
 * cross a database. Exporting each copy separately turned one buildings
 * list into three files.
 *
 * So copies that are genuinely identical are written once, at the top
 * level, and only tables that actually differ between databases get a
 * file each under their database's folder. A manifest says which is
 * which, so nothing is hidden by being deduplicated.
 *
 * PHP here has no zip extension and Composer is not allowed, so the
 * archive is written by hand. That is less alarming than it sounds: a ZIP
 * of stored (uncompressed) entries is a local header and the bytes for
 * each file, then a central directory, then a twenty-two byte end record.
 * No compression means no deflate implementation to get wrong.
 */

final class BulkEntry
{
    public function __construct(
        public string $name,
        public int $offset,
        public int $crc,
        public int $size
    ) {}
}

function bulk_dos_time(int $ts): array
{
    // MS-DOS packed date and time: seconds in units of two, year from 1980.
    $d = getdate($ts);
    return [
        (($d['hours'] << 11) | ($d['minutes'] << 5) | (int)($d['seconds'] / 2)) & 0xFFFF,
        ((($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday']) & 0xFFFF,
    ];
}

/** One table as CSV text, always with a heading row. */
function bulk_table_csv(string $dbName, string $table): string
{
    $tmp = fopen('php://temp/maxmemory:4194304', 'w+');
    fwrite($tmp, "\xEF\xBB\xBF");
    [$schema, $bare] = explode('.', $table, 2);
    $quoted = '[' . str_replace(']', ']]', $schema) . '].['
            . str_replace(']', ']]', $bare) . ']';
    try {
        $stmt = db($dbName)->query("SELECT * FROM $quoted");
        $first = true;
        foreach ($stmt as $row) {
            if ($first) {
                fputcsv($tmp, csv_row(array_keys($row)));
                $first = false;
            }
            fputcsv($tmp, csv_row(array_values($row)));
        }
        if ($first) {
            // An empty table still gets its heading row, so its shape is
            // not lost by having no data in it yet.
            $cols = array_column(db_columns($dbName, $table), 'name');
            if ($cols) {
                fputcsv($tmp, csv_row($cols));
            }
        }
    } catch (Throwable $e) {
        fwrite($tmp, 'could not be read: ' . $e->getMessage() . "\n");
    }
    rewind($tmp);
    $body = (string)stream_get_contents($tmp);
    fclose($tmp);
    return $body;
}

function bulk_export_stream(): void
{
    $catalogue = export_catalogue();

    // Gather by table name rather than by database, so the three copies of
    // a replicated table can be compared before any of them is written.
    $byName = [];
    foreach ($catalogue as $dbName => $tables) {
        foreach (array_keys($tables) as $table) {
            $byName[$table][$dbName] = true;
        }
    }
    ksort($byName);

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="pri-facilities-tables-'
        . date('Y-m-d') . '.zip"');
    header('X-Content-Type-Options: nosniff');
    while (ob_get_level() > 0) {
        ob_end_flush();
    }

    [$dosTime, $dosDate] = bulk_dos_time(time());
    $entries = [];
    $offset = 0;
    $emit = function (string $s) use (&$offset) {
        echo $s;
        $offset += strlen($s);
    };
    $add = function (string $file, string $body)
            use (&$entries, &$offset, $emit, $dosTime, $dosDate) {
        $crc = crc32($body);
        $size = strlen($body);
        $entries[] = new BulkEntry($file, $offset, $crc, $size);
        $emit(pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $dosTime, $dosDate,
                   $crc, $size, $size, strlen($file), 0));
        $emit($file);
        $emit($body);
        flush();
    };

    $manifest = [['Table', 'Where it lives', 'Rows', 'File in this archive', 'Note']];
    foreach ($byName as $table => $dbs) {
        $bodies = [];
        foreach (array_keys($dbs) as $dbName) {
            $bodies[$dbName] = bulk_table_csv($dbName, $table);
        }
        $flat = str_replace('.', '_', $table) . '.csv';
        $distinct = array_unique($bodies);

        if (count($bodies) > 1 && count($distinct) === 1) {
            // Same bytes in every database: one file, said once.
            $add($flat, reset($bodies));
            $rows = max(0, substr_count(reset($bodies), "\n") - 1);
            $manifest[] = [$table, implode(', ', array_map(
                    fn($d) => APP_DB_LABELS[$d], array_keys($bodies))),
                $rows, $flat,
                'Replicated reference data, identical in all ' . count($bodies)
                . ' databases, so written once.'];
        } else {
            foreach ($bodies as $dbName => $body) {
                $file = (count($bodies) > 1 ? APP_DB_LABELS[$dbName] . '/' : '') . $flat;
                $add($file, $body);
                $rows = max(0, substr_count($body, "\n") - 1);
                $manifest[] = [$table, APP_DB_LABELS[$dbName], $rows, $file,
                    count($bodies) > 1
                        ? 'The copies differ between databases, so each is kept.'
                        : 'Only in this database.'];
            }
        }
    }

    // The manifest goes in last, when the counts are known.
    $tmp = fopen('php://temp', 'w+');
    fwrite($tmp, "\xEF\xBB\xBF");
    foreach ($manifest as $row) {
        fputcsv($tmp, csv_row((array)$row));
    }
    rewind($tmp);
    $add('_manifest.csv', (string)stream_get_contents($tmp));
    fclose($tmp);

    $start = $offset;
    foreach ($entries as $e) {
        $emit(pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $dosTime, $dosDate,
                   $e->crc, $e->size, $e->size, strlen($e->name), 0, 0, 0, 0, 32, $e->offset));
        $emit($e->name);
    }
    $emit(pack('VvvvvVVv', 0x06054b50, 0, 0, count($entries), count($entries),
               $offset - $start, $start, 0));
}

/** What the download will contain, for the screen that offers it. */
function bulk_export_manifest(): array
{
    $out = [];
    foreach (export_catalogue() as $dbName => $tables) {
        foreach ($tables as $table => $rows) {
            $out[] = ['db' => $dbName, 'label' => APP_DB_LABELS[$dbName],
                      'table' => $table, 'rows' => (int)$rows];
        }
    }
    usort($out, fn($a, $b) => [$a['label'], $a['table']] <=> [$b['label'], $b['table']]);
    return $out;
}

/**
 * How many distinct tables there really are, and how many are replicated.
 * Used to describe the download honestly rather than by file count.
 */
function bulk_export_shape(): array
{
    $names = [];
    foreach (export_catalogue() as $dbName => $tables) {
        foreach ($tables as $table => $rows) {
            $names[$table][$dbName] = (int)$rows;
        }
    }
    $replicated = count(array_filter($names, fn($d) => count($d) > 1));
    return ['distinct' => count($names), 'replicated' => $replicated,
            'files' => array_sum(array_map('count', $names))];
}
