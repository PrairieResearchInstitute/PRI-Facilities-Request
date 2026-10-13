<?php
declare(strict_types=1);

/**
 * Read an .xlsx without a library.
 *
 * The exports that keep arriving are spreadsheets, and asking somebody to
 * "save as CSV first" is a step that will one day be forgotten, producing
 * a file that looks loaded and is not. This PHP has no zip extension and
 * Composer is not allowed, so the workbook is opened by hand.
 *
 * That is less alarming than it sounds. An .xlsx is a zip of XML: read
 * the central directory to find the parts, inflate them with gzinflate,
 * and parse two files - the shared string table and the sheet. Nothing
 * here has to understand formatting, formulas or styles, because a
 * report only ever carries values.
 */

/** Every member of the zip, as name => bytes. Only what is asked for. */
function xlsx_zip_parts(string $path, array $wanted): array
{
    $raw = @file_get_contents($path);
    if ($raw === false || strlen($raw) < 22) {
        return [];
    }

    // The end-of-central-directory record is the last thing in the file,
    // give or take a comment, so it is found by searching backwards.
    $eocd = strrpos($raw, "PK\x05\x06");
    if ($eocd === false) {
        return [];
    }
    $count = unpack('v', substr($raw, $eocd + 10, 2))[1];
    $cdOffset = unpack('V', substr($raw, $eocd + 16, 4))[1];

    $out = [];
    $p = $cdOffset;
    for ($i = 0; $i < $count; $i++) {
        if (substr($raw, $p, 4) !== "PK\x01\x02") {
            break;
        }
        $method = unpack('v', substr($raw, $p + 10, 2))[1];
        $compSize = unpack('V', substr($raw, $p + 20, 4))[1];
        $nameLen = unpack('v', substr($raw, $p + 28, 2))[1];
        $extraLen = unpack('v', substr($raw, $p + 30, 2))[1];
        $commentLen = unpack('v', substr($raw, $p + 32, 2))[1];
        $localOffset = unpack('V', substr($raw, $p + 42, 4))[1];
        $name = substr($raw, $p + 46, $nameLen);
        $p += 46 + $nameLen + $extraLen + $commentLen;

        if (!in_array($name, $wanted, true)) {
            continue;
        }
        // The local header repeats the name and extra field, and its
        // extra field is often a different length from the central one,
        // so it has to be read rather than assumed.
        $lnLen = unpack('v', substr($raw, $localOffset + 26, 2))[1];
        $leLen = unpack('v', substr($raw, $localOffset + 28, 2))[1];
        $data = substr($raw, $localOffset + 30 + $lnLen + $leLen, $compSize);
        $out[$name] = $method === 8 ? (string)@gzinflate($data) : $data;
    }
    return $out;
}

/**
 * The first worksheet as a grid of strings.
 *
 * Rows come back in order with blank cells preserved, so a column index
 * means the same thing on every row.
 */
function xlsx_read(string $path, int $maxRows = 0): array
{
    $parts = xlsx_zip_parts($path, [
        'xl/sharedStrings.xml',
        'xl/worksheets/sheet1.xml',
        'xl/workbook.xml',
    ]);
    if (empty($parts['xl/worksheets/sheet1.xml'])) {
        return [];
    }

    // Shared strings: every repeated piece of text in the workbook, by
    // index. A cell of type "s" holds a number pointing in here.
    $shared = [];
    if (!empty($parts['xl/sharedStrings.xml'])) {
        $prev = libxml_use_internal_errors(true);
        $sx = simplexml_load_string($parts['xl/sharedStrings.xml']);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($sx !== false) {
            foreach ($sx->si as $si) {
                // A string can be split into runs when part of it is
                // formatted differently; the runs are joined back up.
                $text = '';
                if (isset($si->t)) {
                    $text = (string)$si->t;
                }
                foreach ($si->r as $r) {
                    $text .= (string)$r->t;
                }
                $shared[] = $text;
            }
        }
    }

    $prev = libxml_use_internal_errors(true);
    $sheet = simplexml_load_string($parts['xl/worksheets/sheet1.xml']);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if ($sheet === false || !isset($sheet->sheetData)) {
        return [];
    }

    $grid = [];
    foreach ($sheet->sheetData->row as $row) {
        $cells = [];
        $max = -1;
        foreach ($row->c as $c) {
            $ref = (string)$c['r'];
            $col = xlsx_col_index($ref);
            $type = (string)$c['t'];
            if ($type === 'inlineStr') {
                $v = isset($c->is->t) ? (string)$c->is->t : '';
            } elseif ($type === 's') {
                $v = $shared[(int)(string)$c->v] ?? '';
            } else {
                $v = isset($c->v) ? (string)$c->v : '';
            }
            $cells[$col] = $v;
            $max = max($max, $col);
        }
        $line = [];
        for ($i = 0; $i <= $max; $i++) {
            $line[] = $cells[$i] ?? '';
        }
        $grid[] = $line;
        if ($maxRows > 0 && count($grid) >= $maxRows) {
            break;
        }
    }
    return $grid;
}

/** "BC12" is column 54. */
function xlsx_col_index(string $ref): int
{
    $n = 0;
    $len = strlen($ref);
    for ($i = 0; $i < $len; $i++) {
        $ch = strtoupper($ref[$i]);
        if ($ch < 'A' || $ch > 'Z') {
            break;
        }
        $n = $n * 26 + (ord($ch) - 64);
    }
    return $n - 1;
}

/** Does this look like a workbook rather than a CSV? */
function xlsx_is_xlsx(string $path): bool
{
    $fh = @fopen($path, 'rb');
    if (!$fh) {
        return false;
    }
    $magic = (string)fread($fh, 4);
    fclose($fh);
    if ($magic !== "PK\x03\x04") {
        return false;
    }
    return xlsx_zip_parts($path, ['xl/workbook.xml']) !== [];
}
