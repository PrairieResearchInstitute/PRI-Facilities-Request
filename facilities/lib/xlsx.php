<?php
declare(strict_types=1);

require_once __DIR__ . '/bulkexport.php';   // the store-only zip writer

/**
 * A workbook with a tab per section, written by hand.
 *
 * There is no spreadsheet library here and Composer is not allowed, so
 * the file is assembled directly. An .xlsx is a zip of XML: a content
 * type map, a relationship graph, a workbook naming its sheets, and one
 * XML file per sheet. Strings go inline rather than through a shared
 * table, which costs a few bytes and removes a whole class of mistake.
 *
 * Only what a report needs is implemented: text, numbers, a bold heading
 * row that stays put when you scroll, and sensible column widths. No
 * formulas, no formatting beyond that.
 */

function xlsx_col(int $i): string
{
    // 0 => A, 25 => Z, 26 => AA
    $s = '';
    $i++;
    while ($i > 0) {
        $m = ($i - 1) % 26;
        $s = chr(65 + $m) . $s;
        $i = (int)(($i - $m) / 26);
    }
    return $s;
}

function xlsx_esc(string $s): string
{
    // Excel rejects the control characters that a database happily holds.
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? '';
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** One worksheet's XML. $rows is a list of lists; the first is the heading. */
function xlsx_sheet(array $rows, array $widths = []): string
{
    $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
       . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

    if ($widths) {
        $x .= '<cols>';
        foreach ($widths as $i => $w) {
            $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="'
                . max(6, min(90, (float)$w)) . '" customWidth="1"/>';
        }
        $x .= '</cols>';
    }

    // Freeze the heading row: a report of five thousand rows is unusable
    // without it.
    $x .= '<sheetViews><sheetView workbookViewId="0">'
        . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
        . '</sheetView></sheetViews>';

    $x .= '<sheetData>';
    foreach ($rows as $r => $row) {
        $x .= '<row r="' . ($r + 1) . '">';
        foreach (array_values($row) as $c => $v) {
            $ref = xlsx_col($c) . ($r + 1);
            $style = $r === 0 ? ' s="1"' : '';
            if ($v === null || $v === '') {
                $x .= '<c r="' . $ref . '"' . $style . '/>';
            } elseif (is_int($v) || is_float($v)
                      || (is_string($v) && preg_match('/^-?\d{1,15}(\.\d+)?$/', $v))) {
                $x .= '<c r="' . $ref . '"' . $style . '><v>' . $v . '</v></c>';
            } else {
                $x .= '<c r="' . $ref . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">'
                    . xlsx_esc((string)$v) . '</t></is></c>';
            }
        }
        $x .= '</row>';
    }
    return $x . '</sheetData></worksheet>';
}

/**
 * Build and send the workbook. $sheets is [tab name => ['rows' => [...],
 * 'widths' => [...]]]. Tab names are cleaned: Excel refuses some
 * characters and anything over 31 letters.
 */
function xlsx_send(string $filename, array $sheets): void
{
    $names = [];
    foreach (array_keys($sheets) as $n) {
        $clean = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/', ' ', (string)$n) ?? 'Sheet';
        $clean = trim(mb_substr($clean, 0, 31)) ?: 'Sheet';
        $try = $clean;
        $i = 2;
        while (in_array($try, $names, true)) {
            $try = mb_substr($clean, 0, 28) . ' ' . $i++;
        }
        $names[] = $try;
    }

    $files = [];
    $n = count($sheets);

    $types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    for ($i = 1; $i <= $n; $i++) {
        $types .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" '
                . 'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    $files['[Content_Types].xml'] = $types . '</Types>';

    $files['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    $i = 1;
    foreach ($names as $name) {
        $wb .= '<sheet name="' . xlsx_esc($name) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
        $rels .= '<Relationship Id="rId' . $i . '" '
               . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" '
               . 'Target="worksheets/sheet' . $i . '.xml"/>';
        $i++;
    }
    $files['xl/workbook.xml'] = $wb . '</sheets></workbook>';
    $files['xl/_rels/workbook.xml.rels'] = $rels
        . '<Relationship Id="rId' . $i . '" '
        . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" '
        . 'Target="styles.xml"/></Relationships>';

    // Two styles: ordinary, and the bold heading row.
    $files['xl/styles.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="2">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '</cellXfs></styleSheet>';

    $i = 1;
    foreach ($sheets as $sheet) {
        $files['xl/worksheets/sheet' . $i . '.xml'] =
            xlsx_sheet($sheet['rows'] ?? [], $sheet['widths'] ?? []);
        $i++;
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    zip_send_parts($files);
}

/**
 * Write a set of named byte strings as a stored zip. Shares its format
 * with the table export; kept here as a function so both can use it.
 */
function zip_send_parts(array $files): void
{
    [$dosTime, $dosDate] = bulk_dos_time(time());
    $entries = [];
    $offset = 0;
    $emit = function (string $s) use (&$offset) {
        echo $s;
        $offset += strlen($s);
    };
    foreach ($files as $name => $body) {
        $crc = crc32($body);
        $size = strlen($body);
        $entries[] = new BulkEntry((string)$name, $offset, $crc, $size);
        $emit(pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $dosTime, $dosDate,
                   $crc, $size, $size, strlen((string)$name), 0));
        $emit((string)$name);
        $emit($body);
    }
    $start = $offset;
    foreach ($entries as $e) {
        $emit(pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $dosTime, $dosDate,
                   $e->crc, $e->size, $e->size, strlen($e->name), 0, 0, 0, 0, 32, $e->offset));
        $emit($e->name);
    }
    $emit(pack('VvvvvVVv', 0x06054b50, 0, 0, count($entries), count($entries),
               $offset - $start, $start, 0));
}
