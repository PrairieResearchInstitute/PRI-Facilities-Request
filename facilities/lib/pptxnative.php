<?php
declare(strict_types=1);

/**
 * Native PowerPoint devices for the briefing deck.
 *
 * Everything here draws with real shapes and real text boxes, so every
 * word on every visual can be edited in PowerPoint afterwards. Images
 * remain only where the source is genuinely an image: application
 * screenshots, the database-layout drawings, photographs and icons.
 *
 * All coordinates are EMU. All text is Calibri, 12pt or larger.
 */

require_once __DIR__ . '/pptx.php';

const NV_IN = 914400;

/** A paragraph set from lines; 'h:'-prefixed lines render bold. */
function nv_paras(array $lines, int $sz, string $color, string $headColor = ''): string
{
    $out = '';
    foreach ($lines as $k => $ln) {
        if (str_starts_with($ln, 'h:')) {
            $out .= pptx_para(substr($ln, 2), $sz, $headColor !== '' ? $headColor : $color,
                              '', true, 'Calibri', $k ? 400 : 0);
        } else {
            $out .= pptx_para($ln, $sz, $color, '', false, 'Calibri', $k ? 200 : 0);
        }
    }
    return $out;
}

/** A shape with a preset geometry, fill, outline and optional text. */
function nv_shape(int &$id, string $preset, int $x, int $y, int $w, int $h,
                  string $fill = '', string $line = '', int $lw = 12700,
                  string $paras = '', string $anchor = 't', bool $dash = false,
                  int $radius = 0): string
{
    $fillXml = $fill === '' ? '<a:noFill/>'
             : '<a:solidFill><a:srgbClr val="' . $fill . '"/></a:solidFill>';
    $lineXml = $line === '' ? '<a:ln><a:noFill/></a:ln>'
             : '<a:ln w="' . $lw . '"><a:solidFill><a:srgbClr val="' . $line . '"/></a:solidFill>'
               . ($dash ? '<a:prstDash val="dash"/>' : '') . '</a:ln>';
    $adj = $radius > 0 && $preset === 'roundRect'
         ? '<a:avLst><a:gd name="adj" fmla="val ' . $radius . '"/></a:avLst>'
         : '<a:avLst/>';
    return '<p:sp><p:nvSpPr><p:cNvPr id="' . $id++ . '" name="nv"/>'
         . '<p:cNvSpPr/><p:nvPr/></p:nvSpPr>'
         . '<p:spPr><a:xfrm><a:off x="' . $x . '" y="' . $y . '"/>'
         . '<a:ext cx="' . $w . '" cy="' . $h . '"/></a:xfrm>'
         . '<a:prstGeom prst="' . $preset . '">' . $adj . '</a:prstGeom>'
         . $fillXml . $lineXml . '</p:spPr>'
         . '<p:txBody><a:bodyPr wrap="square" anchor="' . $anchor . '" lIns="91440" '
         . 'tIns="45720" rIns="91440" bIns="45720"><a:normAutofit/></a:bodyPr>'
         . '<a:lstStyle/>' . ($paras !== '' ? $paras : '<a:p><a:endParaRPr lang="en-US"/></a:p>')
         . '</p:txBody></p:sp>';
}

/** A straight connector, optionally dashed, coloured, with an arrowhead. */
function nv_line(int &$id, int $x1, int $y1, int $x2, int $y2, string $color,
                 int $lw = 19050, bool $dash = false, bool $arrow = false): string
{
    $x = min($x1, $x2);
    $y = min($y1, $y2);
    $w = abs($x2 - $x1);
    $h = abs($y2 - $y1);
    $flipH = $x2 < $x1 ? ' flipH="1"' : '';
    $flipV = $y2 < $y1 ? ' flipV="1"' : '';
    return '<p:cxnSp><p:nvCxnSpPr><p:cNvPr id="' . $id++ . '" name="nvln"/>'
         . '<p:cNvCxnSpPr/><p:nvPr/></p:nvCxnSpPr>'
         . '<p:spPr><a:xfrm' . $flipH . $flipV . '><a:off x="' . $x . '" y="' . $y . '"/>'
         . '<a:ext cx="' . $w . '" cy="' . $h . '"/></a:xfrm>'
         . '<a:prstGeom prst="line"><a:avLst/></a:prstGeom>'
         . '<a:ln w="' . $lw . '"><a:solidFill><a:srgbClr val="' . $color . '"/></a:solidFill>'
         . ($dash ? '<a:prstDash val="dash"/>' : '')
         . ($arrow ? '<a:tailEnd type="triangle" w="med" len="med"/>' : '')
         . '</a:ln></p:spPr></p:cxnSp>';
}

/** Rough line count for sizing: chars per line at a size in a width. */
function nv_lines(string $text, int $wEmu, int $pt): int
{
    $cpl = max(8, (int)floor(($wEmu / NV_IN) / ($pt * 0.0072)));
    return max(1, (int)ceil(mb_strlen($text) / $cpl));
}

/**
 * A row (or grid) of cards: colour bar on top, bold heading, body text.
 * Card heights equalise to the tallest. Returns [xml, usedHeight].
 */
function nv_cardrow(int &$id, int $x, int $y, int $w, array $cards,
                    int $cols = 0, bool $arrows = false, int $bodyPt = 12): array
{
    static $palette = [PPTX_NAVY, PPTX_STEEL, PPTX_ORANGE, 'E9C46A', '2A9D8F', PPTX_MUTE];
    $n = count($cards);
    if ($n === 0) {
        return ['', 0];
    }
    $cols = $cols > 0 ? min($cols, $n) : $n;
    $rows = (int)ceil($n / $cols);
    $gap = (int)(0.18 * NV_IN);
    $arrowW = $arrows ? (int)(0.26 * NV_IN) : 0;
    $cw = (int)(($w - ($cols - 1) * ($gap + $arrowW)) / $cols);

    $headPt = $bodyPt + 3;
    $rowH = [];
    foreach ($cards as $i => $c) {
        $bodyLines = 0;
        foreach ((array)$c['body'] as $bl) {
            $bodyLines += nv_lines($bl, $cw - (int)(0.24 * NV_IN), $bodyPt);
        }
        $headLines = nv_lines($c['head'], $cw - (int)(0.24 * NV_IN), $headPt);
        $need = (int)((0.14 + $headLines * $headPt * 0.0169
                     + $bodyLines * $bodyPt * 0.0158 + 0.18) * NV_IN);
        $r = intdiv($i, $cols);
        $rowH[$r] = max($rowH[$r] ?? 0, $need);
    }

    $xml = '';
    $cy = $y;
    foreach ($cards as $i => $c) {
        $r = intdiv($i, $cols);
        $col = $i % $cols;
        $cx = $x + $col * ($cw + $gap + $arrowW);
        $cyy = $y;
        for ($k = 0; $k < $r; $k++) {
            $cyy += $rowH[$k] + $gap;
        }
        $color = $c['color'] ?? $palette[$i % count($palette)];
        $h = $rowH[$r];
        // Light accents keep their bar but the head text darkens to read.
        $headColor = ['E9C46A' => 'A0761B', '2A9D8F' => '1D7168'][$color] ?? $color;
        $xml .= nv_shape($id, 'roundRect', $cx, $cyy, $cw, $h, PPTX_PAPER, '', 0, '', 't', false, 6000);
        $xml .= nv_shape($id, 'rect', $cx, $cyy, $cw, (int)(0.055 * NV_IN), $color);
        $paras = pptx_para($c['head'], $headPt * 100, $headColor, '', true);
        foreach ((array)$c['body'] as $bl) {
            $paras .= pptx_para($bl, $bodyPt * 100, PPTX_INK, '', false, 'Calibri', 200);
        }
        $xml .= pptx_textbox($id, (int)($cx + 0.12 * NV_IN), (int)($cyy + 0.14 * NV_IN),
                             (int)($cw - 0.24 * NV_IN), (int)($h - 0.24 * NV_IN), $paras, 't');
        $id++;
        if ($arrows && $col < $cols - 1 && $i < $n - 1) {
            $ax = $cx + $cw + (int)($gap / 2);
            $ay = $cyy + (int)($h / 2);
            $xml .= nv_line($id, $ax, $ay, $ax + $arrowW, $ay, 'B9BFC9', 19050, false, true);
        }
    }
    $used = array_sum($rowH) + ($rows - 1) * $gap;
    return [$xml, $used];
}

/**
 * The width a run of text needs on one line at a size, in EMU. Rows in
 * these devices are one line tall by construction, so a label that does
 * not fit on one line does not overflow quietly - it wraps into the row
 * beneath it and collides. Sizing the column from the longest label is
 * what stops that.
 */
function nv_textw(array $strings, int $pt): int
{
    $wide = 0.0;
    foreach ($strings as $s) {
        $wide = max($wide, mb_strlen((string)$s) * $pt * 0.0072);
    }
    return (int)($wide * NV_IN);
}

/** Horizontal bars with values; one row may be ringed. */
function nv_hbars(int &$id, int $x, int $y, int $w, array $rows, string $ring = '',
                  string $color = PPTX_STEEL, int $pt = 16): array
{
    $labels = array_map(fn($r) => $r[0], $rows);
    $vals = array_map(fn($r) => number_format($r[1]), $rows);
    // Scale to fill, but only as far as the labels still read on one
    // line in a column worth half the width.
    foreach ([$pt, 16, 14, 12] as $try) {
        $need = nv_textw($labels, $try) + (int)(0.22 * NV_IN);
        if ($need <= (int)($w * 0.42) || $try === 12) {
            $pt = $try;
            $labelW = (int)min($w * 0.42, max(2.0 * NV_IN, $need));
            break;
        }
    }
    $valW = max((int)(1.0 * NV_IN), nv_textw($vals, $pt + 1) + (int)(0.16 * NV_IN));
    $barW = $w - $labelW - $valW - (int)(0.3 * NV_IN);
    $wrap = 1;
    foreach ($labels as $lab) {
        $wrap = max($wrap, nv_lines((string)$lab, $labelW - (int)(0.10 * NV_IN), $pt));
    }
    $rowH = (int)max(0.40 * $pt / 14 * NV_IN, ($wrap * $pt * 0.0175 + 0.10) * NV_IN);
    $mx = 1;
    foreach ($rows as [$lab, $v]) {
        $mx = max($mx, $v);
    }
    $xml = '';
    foreach ($rows as $i => [$lab, $v]) {
        $on = $ring !== '' && $lab === $ring;
        $c = $on ? PPTX_ORANGE : $color;
        $ry = $y + $i * $rowH;
        $bw = max((int)(0.06 * NV_IN), (int)($barW * $v / $mx));
        $xml .= pptx_textbox($id++, $x, $ry, $labelW - (int)(0.12 * NV_IN), $rowH,
            '<a:p><a:pPr algn="r"><a:buNone/></a:pPr>'
            . pptx_run($lab, $pt * 100, $on ? PPTX_ORANGE : PPTX_INK, $on) . '</a:p>', 'ctr');
        $xml .= nv_shape($id, 'rect', $x + $labelW, $ry + (int)(0.05 * NV_IN),
                         $bw, $rowH - (int)(0.10 * NV_IN), $c);
        if ($on) {
            $xml .= nv_shape($id, 'roundRect', $x + $labelW - (int)(0.03 * NV_IN),
                             $ry + (int)(0.01 * NV_IN), $bw + (int)(0.09 * NV_IN),
                             $rowH - (int)(0.02 * NV_IN), '', PPTX_ORANGE, 19050, '', 't', false, 12000);
        }
        $xml .= pptx_textbox($id++, $x + $labelW + $bw + (int)(0.12 * NV_IN), $ry,
            $valW, $rowH,
            '<a:p><a:pPr><a:buNone/></a:pPr>' . pptx_run(number_format($v), ($pt + 1) * 100, $c, true)
            . '</a:p>', 'ctr');
    }
    return [$xml, count($rows) * $rowH];
}

/** Coverage/contract bars: label, filled fraction, percent, count. */
function nv_coverage(int &$id, int $x, int $y, int $w, array $rows, int $pt = 16): array
{
    $two = count($rows) > 8;
    $cols = $two ? 2 : 1;
    $percol = (int)ceil(count($rows) / $cols);
    $colW = (int)($w / $cols);
    $labels = array_map(fn($r) => $r[0], $rows);
    $notes = array_map(function ($r) use ($two) {
        [$lab, $num, $den] = $r;
        $pct = $den > 0 ? 100.0 * $num / $den : 0;
        return round($pct) . '%' . ($two ? ''
            : '  ·  ' . number_format($num) . '/' . number_format($den));
    }, $rows);
    // The label column takes the width its longest label needs on one
    // line; the size steps down until that column fits beside its bar.
    // The label column may take up to 58% of its width. Capping it lower
    // forced the longest labels to wrap, which made every row taller,
    // which made the whole device too tall for the slide to hold at all.
    foreach ([$pt, 16, 14, 12] as $try) {
        $need = nv_textw($labels, $try) + (int)(0.20 * NV_IN);
        if ($need <= (int)($colW * 0.62) || $try === 12) {
            $pt = $try;
            $labelW = (int)min($colW * 0.62, max(1.8 * NV_IN, $need));
            break;
        }
    }
    $pctW = max((int)(0.9 * NV_IN), nv_textw($notes, $pt) + (int)(0.14 * NV_IN));
    $barW = $colW - $labelW - $pctW - (int)(0.35 * NV_IN);
    // If a label is still too long for its column at the 12pt floor, the
    // row grows to hold the wrap. A one-line row under a two-line label
    // does not hide the label - it drops it onto the row beneath.
    $wrap = 1;
    foreach ($labels as $lab) {
        $wrap = max($wrap, nv_lines((string)$lab, $labelW - (int)(0.10 * NV_IN), $pt));
    }
    $rowH = (int)max(0.40 * $pt / 14 * NV_IN, ($wrap * $pt * 0.0175 + 0.10) * NV_IN);
    $xml = '';
    foreach ($rows as $i => [$lab, $num, $den]) {
        $cx = $x + intdiv($i, $percol) * $colW;
        $ry = $y + ($i % $percol) * $rowH;
        $pct = $den > 0 ? 100.0 * $num / $den : 0;
        $c = $pct < 50 ? PPTX_ORANGE : ($pct < 90 ? 'E9C46A' : '2A9D8F');
        $xml .= pptx_textbox($id++, $cx, $ry, $labelW - (int)(0.1 * NV_IN), $rowH,
            '<a:p><a:pPr algn="r"><a:buNone/></a:pPr>'
            . pptx_run($lab, $pt * 100, PPTX_INK) . '</a:p>', 'ctr');
        $xml .= nv_shape($id, 'rect', $cx + $labelW, $ry + (int)(0.05 * NV_IN),
                         $barW, $rowH - (int)(0.10 * NV_IN), PPTX_PAPER);
        $xml .= nv_shape($id, 'rect', $cx + $labelW, $ry + (int)(0.05 * NV_IN),
                         max((int)(0.03 * NV_IN), (int)($barW * $pct / 100)),
                         $rowH - (int)(0.10 * NV_IN), $c);
        $note = $notes[$i];
        $xml .= pptx_textbox($id++, $cx + $labelW + $barW + (int)(0.1 * NV_IN), $ry,
            $pctW + (int)(0.2 * NV_IN), $rowH,
            '<a:p><a:pPr><a:buNone/></a:pPr>' . pptx_run($note, $pt * 100, $c, true) . '</a:p>', 'ctr');
    }
    $legendY = $y + $percol * $rowH + (int)(0.08 * NV_IN);
    $lx = $x + $labelW;
    foreach ([[PPTX_ORANGE, 'Under 50%'], ['E9C46A', '50 to 89%'], ['2A9D8F', '90% and over']] as [$c, $t]) {
        $xml .= nv_shape($id, 'rect', $lx, $legendY + (int)(0.05 * NV_IN),
                         (int)(0.14 * NV_IN), (int)(0.14 * NV_IN), $c);
        $xml .= pptx_textbox($id++, $lx + (int)(0.2 * NV_IN), $legendY,
            (int)(1.4 * NV_IN), (int)(0.28 * NV_IN),
            '<a:p><a:pPr><a:buNone/></a:pPr>' . pptx_run($t, $pt * 100, PPTX_MUTE) . '</a:p>', 'ctr');
        $lx += (int)(2.0 * NV_IN);
    }
    return [$xml, $percol * $rowH + (int)(0.4 * NV_IN)];
}

/** Big-number blocks: number above, label beneath. */
function nv_bignums(int &$id, int $x, int $y, int $w, array $blocks, int $pt = 16): array
{
    static $palette = [PPTX_NAVY, PPTX_STEEL, PPTX_ORANGE, '2A9D8F'];
    $n = count($blocks);
    $gap = (int)(0.2 * NV_IN);
    $bw = (int)(($w - ($n - 1) * $gap) / $n);
    $h = (int)(1.5 * NV_IN);
    $xml = '';
    foreach ($blocks as $i => [$num, $lab]) {
        $bx = $x + $i * ($bw + $gap);
        $c = $palette[$i % 4];
        $xml .= nv_shape($id, 'roundRect', $bx, $y, $bw, $h, PPTX_PAPER, '', 0, '', 't', false, 6000);
        $xml .= nv_shape($id, 'rect', $bx, $y, $bw, (int)(0.055 * NV_IN), $c);
        $xml .= pptx_textbox($id++, $bx, $y + (int)(0.14 * NV_IN), $bw, (int)(0.7 * NV_IN),
            '<a:p><a:pPr algn="ctr"><a:buNone/></a:pPr>' . pptx_run($num, 3200, $c, true) . '</a:p>', 'ctr');
        $xml .= pptx_textbox($id++, $bx + (int)(0.1 * NV_IN), $y + (int)(0.88 * NV_IN),
            $bw - (int)(0.2 * NV_IN), (int)(0.55 * NV_IN),
            '<a:p><a:pPr algn="ctr"><a:buNone/></a:pPr>' . pptx_run($lab, $pt * 100, PPTX_INK) . '</a:p>', 't');
    }
    return [$xml, $h];
}

/** The SIZE dial: three swatches, the active one lit. */
function nv_dial(int &$id, int $x, int $y, int $active): string
{
    $labels = ['An afternoon', 'A few days', 'About a week'];
    $xml = pptx_textbox($id++, $x, $y, (int)(2.2 * NV_IN), (int)(0.26 * NV_IN),
        '<a:p><a:pPr><a:buNone/></a:pPr>' . pptx_run('SIZE', 1200, PPTX_MUTE, true) . '</a:p>', 't');
    foreach ($labels as $i => $lab) {
        $ry = $y + (int)((0.30 + $i * 0.30) * NV_IN);
        $on = $i === $active;
        $xml .= nv_shape($id, 'roundRect', $x, $ry + (int)(0.03 * NV_IN),
                         (int)(0.18 * NV_IN), (int)(0.18 * NV_IN),
                         $on ? PPTX_NAVY : 'E5E1D8', '', 0, '', 't', false, 30000);
        $xml .= pptx_textbox($id++, $x + (int)(0.3 * NV_IN), $ry, (int)(1.9 * NV_IN),
            (int)(0.28 * NV_IN),
            '<a:p><a:pPr><a:buNone/></a:pPr>'
            . pptx_run($lab, 1200, $on ? PPTX_NAVY : PPTX_MUTE, $on) . '</a:p>', 'ctr');
    }
    return $xml;
}

/** The part rail: seven chips, the active part lit. */
function nv_rail(int &$id, int $x, int $y, int $w, int $active): string
{
    $parts = ['1 · Overall', '2 · Facilities', '3 · Space', '4 · Safety',
              '5 · Property', '6 · Vehicles', '7 · Launch'];
    $gap = (int)(0.08 * NV_IN);
    $cw = (int)(($w - 6 * $gap) / 7);
    $h = (int)(0.32 * NV_IN);
    $xml = '';
    foreach ($parts as $i => $p) {
        $cx = $x + $i * ($cw + $gap);
        $on = ($i + 1) === $active;
        $xml .= nv_shape($id, 'roundRect', $cx, $y, $cw, $h,
                         $on ? PPTX_ORANGE : 'EDEAE2', '', 0,
            '<a:p><a:pPr algn="ctr"><a:buNone/></a:pPr>'
            . pptx_run($p, 1200, $on ? 'FFFFFF' : PPTX_MUTE, $on) . '</a:p>', 'ctr', false, 15000);
    }
    return $xml;
}

/** A flowchart: rounded steps, diamond questions, dashed drop boxes. */
function nv_flow(int &$id, int $x, int $y, int $w, array $steps, string $lands): string
{
    $n = count($steps);
    $gap = (int)(0.32 * NV_IN);
    $bw = (int)(($w - ($n - 1) * $gap) / $n);
    $bh = (int)(1.5 * NV_IN);
    $hasDrop = false;
    foreach ($steps as $st) {
        if ($st[2] !== null) {
            $hasDrop = true;
        }
    }
    $xml = '';
    foreach ($steps as $i => [$label, $sub, $drop]) {
        $bx = $x + $i * ($bw + $gap);
        $isQ = str_starts_with($label, '?');
        $text = trim($label, '?');
        $paras = '<a:p><a:pPr algn="ctr"><a:buNone/></a:pPr>'
               . pptx_run($text, 1800, $isQ ? PPTX_ORANGE : PPTX_NAVY, true) . '</a:p>'
               . ($sub !== '' ? '<a:p><a:pPr algn="ctr"><a:buNone/></a:pPr>'
                 . pptx_run($sub, 1400, '444444') . '</a:p>' : '');
        if ($isQ) {
            $xml .= nv_shape($id, 'diamond', $bx - (int)(0.12 * NV_IN), $y - (int)(0.12 * NV_IN),
                             $bw + (int)(0.24 * NV_IN), $bh + (int)(0.24 * NV_IN),
                             'FDF6F2', PPTX_ORANGE, 28575);
            $xml .= pptx_textbox($id++, $bx, $y + (int)(0.22 * NV_IN), $bw, $bh - (int)(0.44 * NV_IN),
                                 $paras, 'ctr');
        } else {
            $xml .= nv_shape($id, 'roundRect', $bx, $y, $bw, $bh, 'EAF0F6', PPTX_NAVY,
                             28575, $paras, 'ctr', false, 10000);
        }
        if ($i > 0) {
            $xml .= nv_line($id, $bx - $gap + (int)(0.04 * NV_IN), $y + (int)($bh / 2),
                            $bx - (int)(0.04 * NV_IN), $y + (int)($bh / 2), PPTX_NAVY,
                            28575, false, true);
        }
        if ($drop !== null) {
            $dy = $y + $bh + (int)(0.2 * NV_IN);
            $dh = (int)(0.9 * NV_IN);
            $xml .= nv_line($id, $bx + (int)($bw / 2), $y + $bh, $bx + (int)($bw / 2),
                            $dy + (int)(0.02 * NV_IN), PPTX_ORANGE, 22225, true, true);
            $xml .= nv_shape($id, 'roundRect', $bx - (int)(0.15 * NV_IN), $dy + (int)(0.06 * NV_IN),
                             $bw + (int)(0.3 * NV_IN), $dh, '', PPTX_ORANGE, 19050,
                '<a:p><a:pPr algn="ctr"><a:buNone/></a:pPr>'
                . pptx_run($drop, 1400, '8A3A12') . '</a:p>', 'ctr', true, 10000);
        }
    }
    $py = $y + $bh + ($hasDrop ? (int)(1.35 * NV_IN) : (int)(0.3 * NV_IN));
    $xml .= nv_shape($id, 'roundRect', $x + $w - (int)(4.6 * NV_IN), $py,
                     (int)(4.6 * NV_IN), (int)(0.5 * NV_IN), '', PPTX_NAVY, 19050,
        '<a:p><a:pPr algn="ctr"><a:buNone/></a:pPr>'
        . pptx_run('Data lands in ', 1400, PPTX_MUTE)
        . pptx_run($lands, 1400, PPTX_NAVY, true) . '</a:p>', 'ctr', false, 50000);
    return $xml;
}

/**
 * A two-column discussion table: a headed column of questions beside a
 * headed column of wishes, ruled like a table so the room can read down
 * either side - and type into it, because every cell is a real text box.
 */
function nv_table2(int &$id, int $x, int $y, int $w, int $h,
                   string $headL, string $headR, array $rows): array
{
    $colW = (int)(($w - (int)(0.16 * NV_IN)) / 2);
    $gap = (int)(0.16 * NV_IN);
    $headH = (int)(0.46 * NV_IN);
    $n = max(1, count($rows));
    $rowH = (int)(($h - $headH) / $n);
    // Scale to fill: the largest size whose tallest cell still fits.
    $bodyPt = 14;
    foreach ([20, 18, 16, 14] as $try) {
        $tallest = 1;
        foreach ($rows as $pair) {
            foreach ([0, 1] as $c) {
                $tallest = max($tallest, nv_lines((string)($pair[$c] ?? ''),
                                                  $colW - (int)(0.24 * NV_IN), $try));
            }
        }
        if ($tallest * $try * 0.0163 * NV_IN <= $rowH - (int)(0.12 * NV_IN)) {
            $bodyPt = $try;
            break;
        }
    }

    $xml = '';
    foreach ([[0, $headL], [1, $headR]] as [$c, $head]) {
        $cx = $x + $c * ($colW + $gap);
        $xml .= nv_shape($id, 'rect', $cx, $y, $colW, $headH, PPTX_NAVY, '', 0,
            '<a:p><a:pPr><a:buNone/></a:pPr>'
            . pptx_run($head, ($bodyPt + 2) * 100, 'FFFFFF', true) . '</a:p>', 'ctr');
    }
    foreach ($rows as $r => $pair) {
        $ry = $y + $headH + $r * $rowH;
        foreach ([0, 1] as $c) {
            $cx = $x + $c * ($colW + $gap);
            $fill = $r % 2 === 0 ? 'FFFFFF' : PPTX_PAPER;
            $xml .= nv_shape($id, 'rect', $cx, $ry, $colW, $rowH, $fill, 'D6D9DE', 9525);
            $text = (string)($pair[$c] ?? '');
            if ($text !== '') {
                $xml .= pptx_textbox($id++, $cx + (int)(0.12 * NV_IN),
                    $ry + (int)(0.06 * NV_IN), $colW - (int)(0.24 * NV_IN),
                    $rowH - (int)(0.12 * NV_IN),
                    '<a:p><a:pPr><a:buNone/></a:pPr>'
                    . pptx_run($text, $bodyPt * 100, PPTX_INK) . '</a:p>', 'ctr');
            }
        }
    }
    return [$xml, $headH + $n * $rowH];
}

/**
 * A ruled table of any width: headers, column widths in inches, rows.
 * The first cell of each row may carry a colour, which tints its text -
 * that is how the join map shows which name a row is about.
 */
function nv_tablen(int &$id, int $x, int $y, int $w, int $h, array $heads,
                   array $widths, array $rows, array $rowColors = []): array
{
    $n = max(1, count($rows));
    $headH = (int)(0.44 * NV_IN);
    $rowH = (int)(($h - $headH) / $n);
    $total = array_sum($widths);
    $cols = [];
    $cx = $x;
    foreach ($widths as $frac) {
        $cw = (int)($w * $frac / $total);
        $cols[] = [$cx, $cw];
        $cx += $cw;
    }

    // Scale to fill: the largest body size whose tallest cell still fits.
    $pt = 12;
    foreach ([18, 16, 14, 12] as $try) {
        $tallest = 1;
        foreach ($rows as $r) {
            foreach ($r as $c => $text) {
                $tallest = max($tallest, nv_lines((string)$text,
                    $cols[$c][1] - (int)(0.24 * NV_IN), $try));
            }
        }
        if ($tallest * $try * 0.0163 * NV_IN <= $rowH - (int)(0.10 * NV_IN)) {
            $pt = $try;
            break;
        }
    }

    $xml = '';
    foreach ($heads as $c => $head) {
        $xml .= nv_shape($id, 'rect', $cols[$c][0], $y, $cols[$c][1], $headH,
            PPTX_NAVY, '', 0,
            '<a:p><a:pPr><a:buNone/></a:pPr>'
            . pptx_run($head, ($pt + 2) * 100, 'FFFFFF', true) . '</a:p>', 'ctr');
    }
    foreach ($rows as $r => $cells) {
        $ry = $y + $headH + $r * $rowH;
        foreach ($cells as $c => $text) {
            $fill = $r % 2 === 0 ? 'FFFFFF' : PPTX_PAPER;
            $xml .= nv_shape($id, 'rect', $cols[$c][0], $ry, $cols[$c][1], $rowH,
                             $fill, 'D6D9DE', 9525);
            if ((string)$text === '') {
                continue;
            }
            $ink = ($c === 0 && isset($rowColors[$r])) ? $rowColors[$r] : PPTX_INK;
            $xml .= pptx_textbox($id++, $cols[$c][0] + (int)(0.12 * NV_IN),
                $ry + (int)(0.05 * NV_IN), $cols[$c][1] - (int)(0.24 * NV_IN),
                $rowH - (int)(0.10 * NV_IN),
                '<a:p><a:pPr><a:buNone/></a:pPr>'
                . pptx_run((string)$text, $pt * 100, $ink, $c === 0) . '</a:p>', 'ctr');
        }
    }
    return [$xml, $headH + $n * $rowH];
}

/**
 * Wheels and spokes: one wheel per shared name.
 *
 * The hub is the name; the spokes run out to every database that carries
 * it; the register that owns it is named underneath. That is the whole
 * rule in a picture - a database joins TO the owner of a name, never to
 * another database that happens to carry the same one. Draw it as a mesh
 * and the room reads a join between Keys and Incidents that does not
 * exist; draw it as wheels and there is nothing to misread.
 *
 * $wheels: [['name' =>, 'owner' =>, 'color' =>, 'spokes' => [label, ...]], ...]
 * Returns [xml, usedHeight].
 */
function nv_hubspoke(int &$id, int $x, int $y, int $w, int $h, array $wheels,
                     int $cols, int $pt = 12): array
{
    $n = count($wheels);
    if ($n === 0) {
        return ['', 0];
    }
    $cols = max(1, min($cols, $n));
    $rows = (int)ceil($n / $cols);
    $cellW = (int)($w / $cols);
    $cellH = (int)($h / $rows);

    // Everything is sized from what it has to hold: the widest spoke
    // label sets the label box, the label box and the cell set the ring,
    // and the longest name sets the hub. Nothing is a magic radius, so a
    // wheel of three spokes and a wheel of ten both come out right.
    $capH = (int)(0.44 * NV_IN);            // the owner line under the wheel
    $labH = (int)(($pt * 0.019 + 0.08) * NV_IN);
    $wide = 0.0;
    $longest = 0.0;
    foreach ($wheels as $wh) {
        foreach ((array)$wh['spokes'] as $label) {
            $wide = max($wide, mb_strlen((string)$label) * $pt * 0.0074);
        }
        $longest = max($longest, mb_strlen((string)$wh['name']) * $pt * 0.0080);
    }
    $labW = (int)(($wide + 0.10) * NV_IN);
    $ringX = (int)($cellW / 2 - $labW / 2 - 0.10 * NV_IN);
    $ringY = (int)(($cellH - $capH) / 2 - $labH / 2 - 0.06 * NV_IN);
    // An ellipse lays its text in the rectangle INSCRIBED in it, which
    // is a factor of 1/sqrt(2) narrower than the shape, plus the shape's
    // own left and right insets. Size the hub from its bounding box and
    // "BldgNumber" breaks across two lines inside it.
    $hubW = (int)min((($longest + 0.20) / 0.707 + 0.10) * NV_IN, $ringX * 1.30);
    $hubH = (int)(($pt * 0.030 + 0.30) * NV_IN);

    // A ring too small for its own labels is a collision waiting to be
    // rendered, so the build says so rather than shipping it.
    foreach ($wheels as $wh) {
        $m = max(1, count((array)$wh['spokes']));
        $a = $ringX / NV_IN;
        $b = $ringY / NV_IN;
        $circ = M_PI * (3 * ($a + $b) - sqrt((3 * $a + $b) * ($a + 3 * $b)));
        if ($m > 2 && $circ / $m < $labW / NV_IN) {
            echo 'VISUALS AUDIT: hub-and-spoke "' . $wh['name'] . '" has '
               . $m . ' spokes with only ' . round($circ / $m, 2)
               . '" of ring each for ' . round($labW / NV_IN, 2) . "\" labels\n";
        }
    }

    $xml = '';
    foreach ($wheels as $k => $wh) {
        $r = intdiv($k, $cols);
        $c = $k % $cols;
        $cx = $x + $c * $cellW + (int)($cellW / 2);
        $cy = $y + $r * $cellH + (int)(($cellH - $capH) / 2);
        $color = (string)($wh['color'] ?? PPTX_NAVY);
        $spokes = array_values((array)$wh['spokes']);
        $m = max(1, count($spokes));

        // Spokes first, so the hub and the labels sit over their ends.
        foreach ($spokes as $j => $label) {
            $ang = -M_PI / 2 + 2 * M_PI * $j / $m;
            $ux = cos($ang);
            $uy = sin($ang);
            $x1 = $cx + (int)($hubW / 2 * $ux * 1.02);
            $y1 = $cy + (int)($hubH / 2 * $uy * 1.02);
            $x2 = $cx + (int)($ringX * $ux) - (int)($labW / 2 * $ux * 0.62);
            $y2 = $cy + (int)($ringY * $uy) - (int)($labH / 2 * $uy * 1.15);
            $xml .= nv_line($id, $x1, $y1, $x2, $y2, $color, 12700);
        }
        foreach ($spokes as $j => $label) {
            $ang = -M_PI / 2 + 2 * M_PI * $j / $m;
            $lx = $cx + (int)($ringX * cos($ang)) - (int)($labW / 2);
            $ly = $cy + (int)($ringY * sin($ang)) - (int)($labH / 2);
            $xml .= pptx_textbox($id++, $lx, $ly, $labW, $labH,
                '<a:p><a:pPr algn="ctr"><a:buNone/></a:pPr>'
                . pptx_run((string)$label, $pt * 100, PPTX_INK) . '</a:p>', 'ctr');
        }
        $xml .= nv_shape($id, 'ellipse', $cx - (int)($hubW / 2), $cy - (int)($hubH / 2),
            $hubW, $hubH, $color, '', 0,
            '<a:p><a:pPr algn="ctr"><a:buNone/></a:pPr>'
            . pptx_run((string)$wh['name'], $pt * 100, 'FFFFFF', true) . '</a:p>', 'ctr');
        $xml .= pptx_textbox($id++, $x + $c * $cellW + (int)(0.06 * NV_IN),
            $y + $r * $cellH + $cellH - $capH, $cellW - (int)(0.12 * NV_IN), $capH,
            '<a:p><a:pPr algn="ctr"><a:buNone/></a:pPr>'
            . pptx_run('owned by ', $pt * 100, PPTX_MUTE)
            . pptx_run((string)$wh['owner'], $pt * 100, $color, true) . '</a:p>', 'ctr');
    }
    return [$xml, $rows * $cellH];
}

/** The permission matrix: roles by capabilities. */
function nv_matrix(int &$id, int $x, int $y, int $w, array $roles, array $caps,
                   array $grid, int $pt = 16): array
{
    // The text in a matrix is already at the 12pt floor and cannot give
    // any more, so what compresses is the spacing around it: asked for a
    // smaller device, it tightens its rows, its header band and its
    // legend rather than refusing.
    $lw = (int)(2.6 * NV_IN);
    $cw = (int)(($w - $lw) / count($caps));
    $rh = (int)((0.24 + 0.005 * $pt) * NV_IN);
    $top = (int)((0.28 + 0.015 * $pt) * NV_IN);
    $legH = (0.24 + 0.0075 * $pt);
    $xml = '';
    foreach ($caps as $j => $c) {
        $xml .= pptx_textbox($id++, $x + $lw + $j * $cw, $y, $cw, $top,
            '<a:p><a:pPr algn="ctr"><a:buNone/></a:pPr>'
            . pptx_run(str_replace("\n", ' ', $c), 1200, PPTX_NAVY, true) . '</a:p>', 'b');
    }
    foreach ($roles as $i => $r) {
        $ry = $y + $top + $i * $rh;
        if ($i % 2 === 0) {
            $xml .= nv_shape($id, 'rect', $x, $ry, $w, $rh, PPTX_PAPER);
        }
        $xml .= pptx_textbox($id++, $x + (int)(0.06 * NV_IN), $ry, $lw, $rh,
            '<a:p><a:pPr><a:buNone/></a:pPr>' . pptx_run($r, 1200, PPTX_INK) . '</a:p>', 'ctr');
        foreach ($caps as $j => $c) {
            $cx = $x + $lw + $j * $cw + (int)($cw / 2);
            $v = $grid[$i][$j];
            $r2 = (int)(0.09 * NV_IN);
            if ($v === 2) {
                $xml .= nv_shape($id, 'ellipse', $cx - $r2, $ry + (int)($rh / 2) - $r2,
                                 2 * $r2, 2 * $r2, '2A9D8F');
            } elseif ($v === 1) {
                $xml .= nv_shape($id, 'ellipse', $cx - $r2, $ry + (int)($rh / 2) - $r2,
                                 2 * $r2, 2 * $r2, '', 'E9C46A', 22225);
            } else {
                $xml .= nv_line($id, $cx - (int)(0.06 * NV_IN), $ry + (int)($rh / 2),
                                $cx + (int)(0.06 * NV_IN), $ry + (int)($rh / 2), 'C9CDD4', 22225);
            }
        }
    }
    $ly = $y + $top + count($roles) * $rh + (int)(0.08 * NV_IN);
    $lx = $x;
    foreach ([['2A9D8F', 'full', 'Granted'], ['E9C46A', 'part', 'Granted per survey'],
              ['C9CDD4', 'none', 'Not granted']] as [$c, $kind, $t]) {
        $r2 = (int)(0.08 * NV_IN);
        if ($kind === 'none') {
            $xml .= nv_line($id, $lx, $ly + (int)(0.14 * NV_IN), $lx + (int)(0.16 * NV_IN),
                            $ly + (int)(0.14 * NV_IN), $c, 22225);
        } elseif ($kind === 'part') {
            $xml .= nv_shape($id, 'ellipse', $lx, $ly + (int)(0.06 * NV_IN), 2 * $r2, 2 * $r2,
                             '', $c, 22225);
        } else {
            $xml .= nv_shape($id, 'ellipse', $lx, $ly + (int)(0.06 * NV_IN), 2 * $r2, 2 * $r2, $c);
        }
        $xml .= pptx_textbox($id++, $lx + (int)(0.26 * NV_IN), $ly, (int)(2.0 * NV_IN),
            (int)(0.3 * NV_IN),
            '<a:p><a:pPr><a:buNone/></a:pPr>' . pptx_run($t, 1200, PPTX_MUTE) . '</a:p>', 'ctr');
        $lx += (int)(2.4 * NV_IN);
    }
    return [$xml, $top + count($roles) * $rh + (int)($legH * NV_IN)];
}

/** The estate calendar bars. */
function nv_gantt(int &$id, int $x, int $y, int $w): array
{
    $rows = [
        ['5 leases already expired', '5', PPTX_ORANGE, 0.00, 0.30],
        ['Inside the 180-day renewal window', '3', 'E9C46A', 0.28, 0.46],
        ['Running, end date known', '20', '2A9D8F', 0.42, 0.94],
        ['28 agreements with no end date at all', '28', PPTX_MUTE, -1, -1],
    ];
    $labelW = (int)(3.6 * NV_IN);
    $numW = (int)(0.6 * NV_IN);
    $barW = $w - $labelW - $numW - (int)(0.3 * NV_IN);
    $rh = (int)(0.42 * NV_IN);
    $xml = '';
    foreach ($rows as $i => [$lab, $n, $c, $a, $b]) {
        $ry = $y + $i * $rh;
        $xml .= pptx_textbox($id++, $x, $ry, $labelW - (int)(0.12 * NV_IN), $rh,
            '<a:p><a:pPr algn="r"><a:buNone/></a:pPr>' . pptx_run($lab, 1200, PPTX_INK) . '</a:p>', 'ctr');
        if ($a < 0) {
            $xml .= nv_shape($id, 'rect', $x + $labelW, $ry + (int)(0.06 * NV_IN), $barW,
                             $rh - (int)(0.12 * NV_IN), '', PPTX_MUTE, 19050,
                '<a:p><a:pPr algn="ctr"><a:buNone/></a:pPr>'
                . pptx_run('No date to watch', 1200, PPTX_MUTE) . '</a:p>', 'ctr', true);
        } else {
            $xml .= nv_shape($id, 'rect', $x + $labelW, $ry + (int)(0.06 * NV_IN), $barW,
                             $rh - (int)(0.12 * NV_IN), PPTX_PAPER);
            $xml .= nv_shape($id, 'rect', $x + $labelW + (int)($barW * $a),
                             $ry + (int)(0.06 * NV_IN), (int)($barW * ($b - $a)),
                             $rh - (int)(0.12 * NV_IN), $c);
        }
        $xml .= pptx_textbox($id++, $x + $labelW + $barW + (int)(0.12 * NV_IN), $ry, $numW, $rh,
            '<a:p><a:pPr><a:buNone/></a:pPr>' . pptx_run($n, 1300, $c, true) . '</a:p>', 'ctr');
    }
    $ty = $y + 4 * $rh + (int)(0.06 * NV_IN);
    $xml .= nv_line($id, $x + $labelW, $ty, $x + $labelW + $barW, $ty, 'D9D4C7', 19050);
    foreach ([[0.0, 'Past'], [0.30, 'Today'], [0.62, '+1 year'], [1.0, '+3 years']] as [$f, $lab]) {
        $tx = $x + $labelW + (int)($barW * $f);
        $xml .= nv_line($id, $tx, $ty - (int)(0.05 * NV_IN), $tx, $ty + (int)(0.05 * NV_IN),
                        PPTX_MUTE, 19050);
        $xml .= pptx_textbox($id++, $tx - (int)(0.5 * NV_IN), $ty + (int)(0.07 * NV_IN),
            (int)(1.0 * NV_IN), (int)(0.26 * NV_IN),
            '<a:p><a:pPr algn="ctr"><a:buNone/></a:pPr>' . pptx_run($lab, 1200, PPTX_MUTE) . '</a:p>', 't');
    }
    return [$xml, 4 * $rh + (int)(0.45 * NV_IN)];
}
