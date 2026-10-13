<?php
declare(strict_types=1);

/**
 * A Word document, written by hand.
 *
 * There is no ZipArchive on this PHP and no Composer to bring one in, so
 * this builds the .docx the same way lib/xlsx.php builds a workbook: a
 * stored (uncompressed) zip assembled byte by byte, holding the three
 * parts Word insists on and nothing else.
 *
 * The vocabulary is deliberately small - headings, paragraphs, bullets,
 * tables and a key/value block - because a document nobody can regenerate
 * is a document that goes stale. Every one of these files is produced
 * from live data by a script, so it can be produced again tomorrow.
 */

require_once __DIR__ . '/xlsx.php';   // for zip_send_parts and its helpers

function docx_esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * Inline runs. A word wrapped in *asterisks* comes out bold, which is
 * enough emphasis for a report and saves threading a run structure
 * through every caller.
 */
function docx_runs(string $text, bool $bold = false, bool $italic = false, int $size = 0): string
{
    $out = '';
    foreach (preg_split('/(\*[^*]+\*)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text] as $bit) {
        if ($bit === '') {
            continue;
        }
        $b = $bold;
        if (strlen($bit) > 2 && $bit[0] === '*' && substr($bit, -1) === '*') {
            $b = true;
            $bit = substr($bit, 1, -1);
        }
        $props = '';
        if ($b) {
            $props .= '<w:b/>';
        }
        if ($italic) {
            $props .= '<w:i/>';
        }
        if ($size > 0) {
            $props .= '<w:sz w:val="' . ($size * 2) . '"/>';
        }
        $out .= '<w:r>' . ($props !== '' ? '<w:rPr>' . $props . '</w:rPr>' : '')
              . '<w:t xml:space="preserve">' . docx_esc($bit) . '</w:t></w:r>';
    }
    return $out;
}

function docx_p(string $text = '', string $style = '', string $extra = ''): string
{
    $props = '';
    if ($style !== '') {
        $props .= '<w:pStyle w:val="' . $style . '"/>';
    }
    $props .= $extra;
    return '<w:p>' . ($props !== '' ? '<w:pPr>' . $props . '</w:pPr>' : '')
         . docx_runs($text) . '</w:p>';
}

function docx_h(string $text, int $level = 1): string
{
    return docx_p($text, 'Heading' . max(1, min(4, $level)));
}

function docx_bullet(string $text): string
{
    return docx_p($text, 'ListParagraph',
        '<w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr>');
}

/** A two-column block for "label: value" facts, without table furniture. */
function docx_facts(array $pairs): string
{
    $rows = [];
    foreach ($pairs as $k => $v) {
        $rows[] = ['*' . $k . '*', (string)$v];
    }
    return docx_table([], $rows, [3200, 5800], false);
}

/**
 * A table. $head is a row of column names, or empty for no header.
 * $widths are in twentieths of a point; the page body is about 9,360.
 */
function docx_table(array $head, array $rows, array $widths = [], bool $borders = true): string
{
    $n = max(count($head), $rows ? count($rows[0]) : 0);
    if ($n === 0) {
        return '';
    }
    if (!$widths) {
        $widths = array_fill(0, $n, (int)floor(9360 / $n));
    }

    $edge = $borders
        ? '<w:tblBorders>'
          . '<w:top w:val="single" w:sz="4" w:color="C8C8C8"/>'
          . '<w:left w:val="none" w:sz="0" w:color="auto"/>'
          . '<w:bottom w:val="single" w:sz="4" w:color="C8C8C8"/>'
          . '<w:right w:val="none" w:sz="0" w:color="auto"/>'
          . '<w:insideH w:val="single" w:sz="4" w:color="E4E4E4"/>'
          . '<w:insideV w:val="none" w:sz="0" w:color="auto"/>'
          . '</w:tblBorders>'
        : '';

    $xml = '<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/>' . $edge
         . '<w:tblCellMar><w:top w:w="60" w:type="dxa"/><w:bottom w:w="60" w:type="dxa"/>'
         . '<w:left w:w="80" w:type="dxa"/><w:right w:w="80" w:type="dxa"/></w:tblCellMar>'
         . '</w:tblPr><w:tblGrid>';
    foreach ($widths as $w) {
        $xml .= '<w:gridCol w:w="' . (int)$w . '"/>';
    }
    $xml .= '</w:tblGrid>';

    $cell = function ($text, int $w, bool $bold, bool $shade): string {
        return '<w:tc><w:tcPr><w:tcW w:w="' . $w . '" w:type="dxa"/>'
             . ($shade ? '<w:shd w:val="clear" w:color="auto" w:fill="F2F2F2"/>' : '')
             . '</w:tcPr>'
             . '<w:p><w:pPr><w:spacing w:before="20" w:after="20"/></w:pPr>'
             . docx_runs((string)$text, $bold, false, 9) . '</w:p></w:tc>';
    };

    if ($head) {
        $xml .= '<w:tr><w:trPr><w:tblHeader/></w:trPr>';
        foreach (array_values($head) as $i => $h) {
            $xml .= $cell($h, (int)($widths[$i] ?? 1500), true, true);
        }
        $xml .= '</w:tr>';
    }
    foreach ($rows as $row) {
        $xml .= '<w:tr>';
        foreach (array_values($row) as $i => $v) {
            $xml .= $cell($v, (int)($widths[$i] ?? 1500), false, false);
        }
        $xml .= '</w:tr>';
    }
    return $xml . '</w:tbl>' . docx_p();
}

function docx_pagebreak(): string
{
    return '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
}

/**
 * Assemble the parts and return the .docx as a byte string.
 *
 * $title becomes the document title in the file's properties, which is
 * what shows in a document library rather than the filename.
 */
function docx_build(string $title, string $body): string
{
    $files = [];

    $files['[Content_Types].xml'] =
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
      . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
      . '<Default Extension="xml" ContentType="application/xml"/>'
      . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
      . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
      . '<Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>'
      . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
      . '</Types>';

    $files['_rels/.rels'] =
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
      . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
      . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
      . '</Relationships>';

    $files['word/_rels/document.xml.rels'] =
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
      . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
      . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/>'
      . '</Relationships>';

    $files['docProps/core.xml'] =
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
      . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
      . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
      . '<dc:title>' . docx_esc($title) . '</dc:title>'
      . '<dc:creator>PRI Facilities Request System</dc:creator>'
      . '<cp:lastModifiedBy>PRI Facilities Request System</cp:lastModifiedBy>'
      . '<dcterms:created xsi:type="dcterms:W3CDTF">' . date('c') . '</dcterms:created>'
      . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . date('c') . '</dcterms:modified>'
      . '</cp:coreProperties>';

    $files['word/styles.xml'] = docx_styles();
    $files['word/numbering.xml'] = docx_numbering();

    $files['word/document.xml'] =
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
      . '<w:body>' . $body
      . '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/>'
      . '<w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" '
      . 'w:header="720" w:footer="720" w:gutter="0"/></w:sectPr>'
      . '</w:body></w:document>';

    return zip_parts_string($files);
}

function docx_write(string $path, string $title, string $body): int
{
    $bytes = docx_build($title, $body);
    file_put_contents($path, $bytes);
    return strlen($bytes);
}

function docx_styles(): string
{
    $heading = function (int $n, int $pt, string $colour, int $before): string {
        return '<w:style w:type="paragraph" w:styleId="Heading' . $n . '">'
             . '<w:name w:val="heading ' . $n . '"/><w:basedOn w:val="Normal"/>'
             . '<w:pPr><w:keepNext/><w:spacing w:before="' . $before . '" w:after="120"/>'
             . '<w:outlineLvl w:val="' . ($n - 1) . '"/></w:pPr>'
             . '<w:rPr><w:b/><w:color w:val="' . $colour . '"/>'
             . '<w:sz w:val="' . ($pt * 2) . '"/></w:rPr></w:style>';
    };
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
      . '<w:docDefaults><w:rPrDefault><w:rPr>'
      . '<w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/><w:sz w:val="21"/>'
      . '</w:rPr></w:rPrDefault>'
      . '<w:pPrDefault><w:pPr><w:spacing w:after="140" w:line="276" w:lineRule="auto"/>'
      . '</w:pPr></w:pPrDefault></w:docDefaults>'
      . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal">'
      . '<w:name w:val="Normal"/></w:style>'
      . $heading(1, 20, '1D3557', 0)
      . $heading(2, 15, '1D3557', 320)
      . $heading(3, 12, '333333', 240)
      . $heading(4, 11, '555555', 200)
      . '<w:style w:type="paragraph" w:styleId="ListParagraph">'
      . '<w:name w:val="List Paragraph"/><w:basedOn w:val="Normal"/>'
      . '<w:pPr><w:ind w:left="720"/><w:spacing w:after="60"/></w:pPr></w:style>'
      . '<w:style w:type="paragraph" w:styleId="Subtitle">'
      . '<w:name w:val="Subtitle"/><w:basedOn w:val="Normal"/>'
      . '<w:pPr><w:spacing w:after="360"/></w:pPr>'
      . '<w:rPr><w:color w:val="666666"/><w:sz w:val="24"/></w:rPr></w:style>'
      . '</w:styles>';
}

function docx_numbering(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
      . '<w:abstractNum w:abstractNumId="0"><w:multiLevelType w:val="hybridMultilevel"/>'
      . '<w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="bullet"/>'
      . '<w:lvlText w:val="&#8226;"/><w:lvlJc w:val="left"/>'
      . '<w:pPr><w:ind w:left="720" w:hanging="360"/></w:pPr>'
      . '<w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:hint="default"/></w:rPr>'
      . '</w:lvl></w:abstractNum>'
      . '<w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num>'
      . '</w:numbering>';
}

/**
 * The same stored-zip format lib/xlsx.php streams, returned as a string
 * instead of echoed, because these files are written to disk rather than
 * downloaded.
 */
function zip_parts_string(array $files): string
{
    [$dosTime, $dosDate] = bulk_dos_time(time());
    $out = '';
    $entries = [];
    $offset = 0;
    foreach ($files as $name => $body) {
        $name = (string)$name;
        $crc = crc32($body);
        $size = strlen($body);
        $entries[] = ['name' => $name, 'offset' => $offset, 'crc' => $crc, 'size' => $size];
        $chunk = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $dosTime, $dosDate,
                      $crc, $size, $size, strlen($name), 0) . $name . $body;
        $out .= $chunk;
        $offset += strlen($chunk);
    }
    $start = $offset;
    $dir = '';
    foreach ($entries as $e) {
        $dir .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $dosTime, $dosDate,
                     $e['crc'], $e['size'], $e['size'], strlen($e['name']), 0, 0, 0, 0, 32,
                     $e['offset']) . $e['name'];
    }
    return $out . $dir . pack('VvvvvVVv', 0x06054b50, 0, 0, count($entries), count($entries),
                              strlen($dir), $start, 0);
}
