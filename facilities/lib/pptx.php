<?php
declare(strict_types=1);

/**
 * A PowerPoint deck, written by hand.
 *
 * Same situation as lib/docx.php: no ZipArchive, no Composer, so the
 * .pptx is assembled byte by byte as a stored zip holding the parts
 * PowerPoint insists on - presentation, one master, one layout, one
 * theme, the slides, and the images.
 *
 * The design echoes the application it presents: the navy header band
 * with the orange rule under it, serif titles, plain body text. That
 * lives on the slide master, so replacing the look later means
 * replacing one part, not sixty slides.
 *
 * Four kinds of slide: title, section, content, image. That is all a
 * briefing needs, and everything here is positioned explicitly so what
 * you see is what the file says.
 */

require_once __DIR__ . '/docx.php';   // for zip_parts_string()

const PPTX_W = 12192000;              // 16:9 in EMU
const PPTX_H = 6858000;

const PPTX_NAVY   = '13294B';         // University of Illinois brand blue, per the banner
const PPTX_UINAVY = '13294B';
const PPTX_ASSETS = __DIR__ . '/../../tools/deck_assets';
const PPTX_ORANGE = 'C7461F';
const PPTX_STEEL  = '457B9D';
const PPTX_INK    = '2B2B2B';
const PPTX_MUTE   = '6B7280';
const PPTX_PAPER  = 'F5F1E8';

function pptx_esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * Proper title case: minor words lose their capital unless they open
 * or close the title or a "·"- or ":"-separated segment of it.
 * Acronyms and anything oddly cased pass through untouched.
 */
function pptx_title_case(string $t): string
{
    static $minor = ['A', 'An', 'And', 'As', 'At', 'But', 'By', 'For', 'From',
                     'In', 'Into', 'Nor', 'Of', 'On', 'Onto', 'Or', 'Out',
                     'Over', 'Per', 'So', 'The', 'To', 'Up', 'Via', 'Vs', 'With', 'Yet'];
    $words = explode(' ', $t);
    $n = count($words);
    for ($i = 1; $i < $n - 1; $i++) {
        if (!preg_match('/^([A-Za-z]+)([,.]?)$/', $words[$i], $m)) {
            continue;
        }
        $opensSegment = $words[$i - 1] === '·' || $words[$i - 1] === '—'
                     || str_ends_with($words[$i - 1], ':');
        $closesSegment = $words[$i + 1] === '·' || $words[$i + 1] === '—'
                      || str_ends_with($words[$i], ':');
        if (!$opensSegment && !$closesSegment && in_array($m[1], $minor, true)) {
            $words[$i] = strtolower($m[1]) . $m[2];
        }
    }
    return implode(' ', $words);
}

/** One run of text. Sizes are in hundredths of a point (2800 = 28pt). */
function pptx_run(string $text, int $sz, string $color, bool $b = false, bool $i = false,
                  string $font = 'Calibri'): string
{
    return '<a:r><a:rPr lang="en-US" sz="' . $sz . '"' . ($b ? ' b="1"' : '')
         . ($i ? ' i="1"' : '') . ' dirty="0">'
         . '<a:solidFill><a:srgbClr val="' . $color . '"/></a:solidFill>'
         . '<a:latin typeface="' . $font . '"/></a:rPr>'
         . '<a:t>' . pptx_esc($text) . '</a:t></a:r>';
}

/**
 * One paragraph. $kind: '' plain, 'b' bullet, 's' sub-bullet,
 * 'h' un-bulleted bold heading line.
 */
function pptx_para(string $text, int $sz, string $color, string $kind = '',
                   bool $bold = false, string $font = 'Calibri', int $before = 0): string
{
    // Line spacing is stated in points, not left to the renderer's
    // percentage of the font. That makes a paragraph's height pure
    // arithmetic - lines x PPTX_LINE_RATIO x size - so the fill ladder
    // can size text to a box instead of estimating and hedging. The
    // hedging is why slides came out at 13.5pt in a box a third empty.
    $lnSpc = '<a:lnSpc><a:spcPts val="' . (int)round($sz * PPTX_LINE_RATIO)
           . '"/></a:lnSpc>';
    $spc = $before ? '<a:spcBef><a:spcPts val="' . $before . '"/></a:spcBef>' : '';
    $p = '';
    if ($kind === 'b' || $kind === 's') {
        // Bullets sit UNDER their heading, not beside it: the glyph is
        // indented from the heading's left edge and the text further
        // still, so the rank is visible rather than implied.
        $marL = $kind === 's' ? 857250 : 571500;
        $p = '<a:pPr marL="' . $marL . '" indent="-285750">' . $lnSpc . $spc
           . '<a:buFont typeface="Arial"/><a:buChar char="' . ($kind === 's' ? '–' : '•')
           . '"/></a:pPr>';
    } else {
        $p = '<a:pPr>' . $lnSpc . $spc . '<a:buNone/></a:pPr>';
    }
    return '<a:p>' . $p . pptx_run($text, $sz, $color, $bold, false, $font) . '</a:p>';
}

/** A text box at an explicit position, holding ready-made paragraphs. */
function pptx_textbox(int $id, int $x, int $y, int $w, int $h, string $paras,
                      string $anchor = 't', string $autofit = '<a:normAutofit/>'): string
{
    return '<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="text' . $id . '"/>'
         . '<p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr>'
         . '<p:spPr><a:xfrm><a:off x="' . $x . '" y="' . $y . '"/>'
         . '<a:ext cx="' . $w . '" cy="' . $h . '"/></a:xfrm>'
         . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/></p:spPr>'
         . '<p:txBody><a:bodyPr wrap="square" anchor="' . $anchor . '" lIns="0" tIns="0" '
         . 'rIns="0" bIns="0">' . $autofit . '</a:bodyPr><a:lstStyle/>'
         . $paras . '</p:txBody></p:sp>';
}

/** A filled rectangle, for bands and backgrounds. */
function pptx_rect(int $id, int $x, int $y, int $w, int $h, string $color): string
{
    return '<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="rect' . $id . '"/>'
         . '<p:cNvSpPr/><p:nvPr/></p:nvSpPr>'
         . '<p:spPr><a:xfrm><a:off x="' . $x . '" y="' . $y . '"/>'
         . '<a:ext cx="' . $w . '" cy="' . $h . '"/></a:xfrm>'
         . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom>'
         . '<a:solidFill><a:srgbClr val="' . $color . '"/></a:solidFill>'
         . '<a:ln><a:noFill/></a:ln></p:spPr>'
         . '<p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:endParaRPr lang="en-US"/></a:p>'
         . '</p:txBody></p:sp>';
}

/**
 * A picture, scaled to fit a box and centred in it.
 *
 * A screenshot gets a hairline so the screen has an edge against the
 * page. A drawing does not: it is already on white, and a box round it
 * only adds a border the drawing never asked for.
 */
function pptx_pic(int $id, string $rId, int $boxX, int $boxY, int $boxW, int $boxH,
                  int $pxW, int $pxH, bool $border = true): string
{
    $scale = min($boxW / $pxW, $boxH / $pxH);
    $w = (int)($pxW * $scale);
    $h = (int)($pxH * $scale);
    $x = $boxX + (int)(($boxW - $w) / 2);
    $y = $boxY + (int)(($boxH - $h) / 2);
    return '<p:pic><p:nvPicPr><p:cNvPr id="' . $id . '" name="shot' . $id . '"/>'
         . '<p:cNvPicPr/><p:nvPr/></p:nvPicPr>'
         . '<p:blipFill><a:blip r:embed="' . $rId . '"/><a:stretch><a:fillRect/></a:stretch>'
         . '</p:blipFill>'
         . '<p:spPr><a:xfrm><a:off x="' . $x . '" y="' . $y . '"/>'
         . '<a:ext cx="' . $w . '" cy="' . $h . '"/></a:xfrm>'
         . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom>'
         . ($border
            ? '<a:ln w="9525"><a:solidFill><a:srgbClr val="C9C9C9"/></a:solidFill></a:ln>'
            : '')
         . '</p:spPr></p:pic>';
}

/**
 * A picture cut from a wider strip: the crop arrives as ready-made
 * srcRect XML because the percentages come straight from the source
 * deck the banner was lifted out of.
 */
function pptx_crop_pic(int $id, string $rId, string $srcRect,
                       int $x, int $y, int $w, int $h): string
{
    return '<p:pic><p:nvPicPr><p:cNvPr id="' . $id . '" name="crop' . $id . '"/>'
         . '<p:cNvPicPr/><p:nvPr/></p:nvPicPr>'
         . '<p:blipFill><a:blip r:embed="' . $rId . '"/>' . $srcRect
         . '<a:stretch/></p:blipFill>'
         . '<p:spPr><a:xfrm><a:off x="' . $x . '" y="' . $y . '"/>'
         . '<a:ext cx="' . $w . '" cy="' . $h . '"/></a:xfrm>'
         . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr></p:pic>';
}

/** A plain full-stretch picture at an explicit position, no border. */
function pptx_flat_pic(int $id, string $rId, int $x, int $y, int $w, int $h): string
{
    return pptx_crop_pic($id, $rId, '', $x, $y, $w, $h);
}

/**
 * The address block on the banner's right, as native text rather than
 * the source deck's baked image, so the institute can re-address it
 * without regenerating artwork. Right-aligned against the margin the
 * source art used.
 */
function pptx_banner_address(int $id): string
{
    $line = function (string $text, bool $bold) {
        return '<a:p><a:pPr algn="r"><a:lnSpc><a:spcPct val="102000"/></a:lnSpc>'
             . '<a:buNone/></a:pPr>'
             . '<a:r><a:rPr lang="en-US" sz="' . ($bold ? 1050 : 950) . '"'
             . ($bold ? ' b="1"' : '') . ' dirty="0">'
             . '<a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill>'
             . '<a:latin typeface="Calibri"/></a:rPr>'
             . '<a:t>' . pptx_esc($text) . '</a:t></a:r></a:p>';
    };
    $paras = $line('Prairie Research Institute', true)
           . $line('University of Illinois Urbana-Champaign', false)
           . $line('615 E. Peabody Dr., MC-650', false)
           . $line('Champaign, IL 61820', false);
    return pptx_textbox($id, 7600000, 6135000, 4475916, 680000, $paras, 'ctr');
}

/** Width and height out of a PNG header. */
function pptx_png_size(string $bytes): array
{
    $d = unpack('N2', substr($bytes, 16, 8));
    return [(int)$d[1], (int)$d[2]];
}

/**
 * A deterministic autofit: estimate the height the bullets need at
 * their full size and emit an explicit fontScale when they exceed the
 * box, because PowerPoint does not reliably re-run bare autofit before
 * a first render. Floor 75 keeps 16pt text at or above 12pt.
 */
/** Estimated height in inches the bullets need at a base size. */
/**
 * Line spacing, as a multiple of the type size, stated on every body
 * paragraph. 16pt text is set on 18pt, which is what a person setting
 * this by hand would choose.
 */
const PPTX_LINE_RATIO = 1.125;

/** Space before a paragraph, in hundredths of a point. */
const PPTX_SPC_HEAD = 600;
const PPTX_SPC_BULLET = 200;
const PPTX_SPC_SUB = 100;

/**
 * Average character width in inches per point of type size. Calibri's
 * mixed-case average, calibrated against the built file - the one part
 * of the height that is still a measurement rather than arithmetic.
 */
const PPTX_CHAR_W = 0.0063;

/** The ruled minimum body size, in hundredths of a point. */
const PPTX_FLOOR = 1200;

/**
 * The exact height a set of bullets occupies.
 *
 * Not an estimate with a safety margin bolted on: line spacing is fixed
 * in points and paragraph spacing is fixed in points, so the height is
 * lines x spacing + gaps. Only the LINE COUNT is estimated. The old
 * version padded every item and then added 8% on top, which is why
 * slides came out at 13.5pt in boxes a third empty.
 */
function pptx_fitneed(array $items, float $wIn, int $sz): float
{
    $need = 0.0;
    foreach ($items as $ln) {
        $isHead = str_starts_with($ln, 'h:');
        $isSub = str_starts_with($ln, 's:');
        $text = ($isHead || $isSub) ? substr($ln, 2) : $ln;
        $psz = $isHead ? $sz + 200 : ($isSub ? $sz - 200 : $sz);
        // Bulleted text starts at its indent, so it has less width than
        // the box; a heading has the whole of it.
        $indent = $isHead ? 0.0 : ($isSub ? 0.9375 : 0.625);
        $avail = max(0.6, $wIn - $indent);
        $cpl = max(6, (int)floor($avail / ($psz / 100 * PPTX_CHAR_W)));
        $lines = max(1, (int)ceil(mb_strlen($text) / $cpl));
        $before = $isHead ? PPTX_SPC_HEAD : ($isSub ? PPTX_SPC_SUB : PPTX_SPC_BULLET);
        $need += ($lines * $psz * PPTX_LINE_RATIO + $before) / 7200;
    }
    return $need;
}

/**
 * The floor is 75, which is 12pt from a 16pt base - the ruled minimum
 * and not a point below it. It was 88 while the estimator over-predicted
 * and shrank text that fitted; now that it is calibrated, a slide that
 * genuinely needs 13pt should get 13pt rather than overflow its box,
 * because text running off a slide is worse than text set at the floor.
 */
function pptx_fitpct(array $items, float $wIn, float $hIn, int $sz = 1600): int
{
    $need = pptx_fitneed($items, $wIn, $sz);
    return (int)min(100, max(75, floor(100 * $hIn / max(0.1, $need))));
}

/**
 * Pick the largest base size, 16..20pt, whose text still fits the box -
 * sparse slides fill their space instead of floating in it. If even
 * 16pt overflows, shrink deterministically (floor 75% = 12pt).
 * Returns [baseSize, autofitXml].
 */
function pptx_fitsize(array $items, float $wIn, float $hIn): array
{
    foreach ([2000, 1900, 1800, 1700, 1600, 1500, 1400, 1300, 1200] as $sz) {
        if (pptx_fitneed($items, $wIn, $sz) <= $hIn) {
            return [$sz, '<a:normAutofit/>'];
        }
    }
    // Nothing fits even at the floor: shrink, and let the gate say so.
    return [1200, pptx_fitscale($items, $wIn, $hIn, 1200)];
}

function pptx_fitscale(array $items, float $wIn, float $hIn, int $sz = 1600): string
{
    $scale = pptx_fitpct($items, $wIn, $hIn, $sz);
    return $scale >= 100 ? '<a:normAutofit/>'
        : '<a:normAutofit fontScale="' . ($scale * 1000) . '" lnSpcReduction="10000"/>';
}

/**
 * The size a slide title is set at.
 *
 * 48pt is the standard, and the median title takes it on one line. A
 * title too long for one line at 48 steps down rather than wrapping into
 * the body - the band is a fixed height and a second line at 48pt would
 * be 1.7in of it. The floor is 28pt, which holds two lines.
 */
const PPTX_TITLE_W = 11277600;
const PPTX_TITLE_H = 900000;

function pptx_title_size(string $t, int $wEmu = PPTX_TITLE_W): int
{
    $wIn = $wEmu / 914400;
    $hIn = PPTX_TITLE_H / 914400 - 0.08;
    foreach ([4800, 4200, 3600, 3200, 2800] as $sz) {
        $pt = $sz / 100;
        $cpl = max(8, (int)floor(($wIn - 0.10) / ($pt * 0.0063)));
        $lines = max(1, (int)ceil(mb_strlen($t) / $cpl));
        if ($lines * $pt * 0.0170 <= $hIn) {
            return $sz;
        }
    }
    return 2800;
}

/** The title band, sized to its own words, in the master's navy. */
function pptx_title_bar(int &$id, string $title, int $wEmu = PPTX_TITLE_W): string
{
    return pptx_textbox($id++, 457200, 60000, $wEmu, PPTX_TITLE_H,
        pptx_para($title, pptx_title_size($title, $wEmu), 'FFFFFF', '', true, 'Calibri'),
        'ctr');
}

function pptx_bullets(array $items, int $sz = 1600): string
{
    $out = '';
    foreach ($items as $item) {
        if (str_starts_with($item, 'h:')) {
            $out .= pptx_para(substr($item, 2), $sz + 200, PPTX_NAVY, '', true, 'Calibri',
                              PPTX_SPC_HEAD);
        } elseif (str_starts_with($item, 's:')) {
            $out .= pptx_para(substr($item, 2), $sz - 200, PPTX_MUTE, 's', false, 'Calibri',
                              PPTX_SPC_SUB);
        } else {
            $out .= pptx_para($item, $sz, PPTX_INK, 'b', false, 'Calibri', PPTX_SPC_BULLET);
        }
    }
    return $out;
}

/**
 * The pipeline strip along the foot of a screen slide: the stages a
 * request walks, the one this screen serves highlighted, and where the
 * data finally lands. The same shape on every slide, so by the tenth
 * screen the audience reads it without being told to.
 */
function pptx_pipe(int &$id, array $steps, int $active, string $lands): string
{
    $y = 5710000;   // clear of the campus banner below
    $h = 300000;
    $x = 457200;
    $chipW = (int)min(1500000, (7000000 - (count($steps) - 1) * 300000) / max(1, count($steps)));
    $out = '';
    foreach ($steps as $i => $step) {
        $on = $i === $active;
        $fill = $on ? PPTX_NAVY : 'E8EDF3';
        $ink = $on ? 'FFFFFF' : '44536A';
        $out .= '<p:sp><p:nvSpPr><p:cNvPr id="' . $id++ . '" name="chip"/>'
              . '<p:cNvSpPr/><p:nvPr/></p:nvSpPr>'
              . '<p:spPr><a:xfrm><a:off x="' . $x . '" y="' . $y . '"/>'
              . '<a:ext cx="' . $chipW . '" cy="' . $h . '"/></a:xfrm>'
              . '<a:prstGeom prst="roundRect"><a:avLst><a:gd name="adj" fmla="val 28000"/>'
              . '</a:avLst></a:prstGeom>'
              . '<a:solidFill><a:srgbClr val="' . $fill . '"/></a:solidFill>'
              . '<a:ln><a:noFill/></a:ln></p:spPr>'
              . '<p:txBody><a:bodyPr anchor="ctr" lIns="20000" rIns="20000" tIns="0" bIns="0"/>'
              . '<a:lstStyle/><a:p><a:pPr algn="ctr"><a:buNone/></a:pPr>'
              . pptx_run($step, 1200, $ink, $on) . '</a:p></p:txBody></p:sp>';
        $x += $chipW;
        if ($i < count($steps) - 1) {
            $out .= '<p:sp><p:nvSpPr><p:cNvPr id="' . $id++ . '" name="arrow"/>'
                  . '<p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr>'
                  . '<p:spPr><a:xfrm><a:off x="' . $x . '" y="' . $y . '"/>'
                  . '<a:ext cx="300000" cy="' . $h . '"/></a:xfrm>'
                  . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/></p:spPr>'
                  . '<p:txBody><a:bodyPr anchor="ctr" lIns="0" rIns="0" tIns="0" bIns="0"/>'
                  . '<a:lstStyle/><a:p><a:pPr algn="ctr"><a:buNone/></a:pPr>'
                  . pptx_run('→', 1200, PPTX_ORANGE, true) . '</a:p></p:txBody></p:sp>';
            $x += 300000;
        }
    }
    $out .= pptx_textbox($id++, $x + 250000, $y, PPTX_W - $x - 700000, $h,
        '<a:p><a:pPr><a:buNone/></a:pPr>'
        . pptx_run('Data lands in ', 1200, PPTX_MUTE)
        . pptx_run($lands, 1200, PPTX_NAVY, true) . '</a:p>', 'ctr');
    return $out;
}

/**
 * Build one slide's XML. Returns [xml, [rId => media filename]].
 * $media collects image bytes by filename across the whole deck.
 */
function pptx_slide(array $s, array &$media, int $slideNo): array
{
    $shapes = '';
    $rels = [];
    $id = 2;

    $kind = $s['kind'] ?? 'content';
    if (!empty($s['title'])) {
        $s['title'] = pptx_title_case((string)$s['title']);
    }

    if ($kind === 'title') {
        $shapes .= pptx_rect($id++, 0, 0, PPTX_W, PPTX_H, PPTX_NAVY);
        // The lockup IS the title when it is present - it already says
        // the name, so no text line repeats it. Words only as fallback.
        $hasLogo = !empty($s['image']) && is_file($s['image']);
        if ($hasLogo) {
            $bytes = (string)file_get_contents($s['image']);
            [$pw, $ph] = pptx_png_size($bytes);
            $name = 'image' . (count($media) + 1) . '.png';
            $media[$name] = $bytes;
            $rId = 'rId' . (count($rels) + 2);
            $rels[$rId] = $name;
            $scale = min(7000000 / $pw, 2600000 / $ph);
            $iw = (int)($pw * $scale);
            $ih = (int)($ph * $scale);
            $shapes .= '<p:pic><p:nvPicPr><p:cNvPr id="' . $id++ . '" name="logo"/>'
                . '<p:cNvPicPr/><p:nvPr/></p:nvPicPr>'
                . '<p:blipFill><a:blip r:embed="' . $rId . '"/>'
                . '<a:stretch><a:fillRect/></a:stretch></p:blipFill>'
                . '<p:spPr><a:xfrm><a:off x="' . (int)((PPTX_W - $iw) / 2)
                . '" y="' . (int)((4400000 - $ih) / 2)
                . '"/><a:ext cx="' . $iw . '" cy="' . $ih . '"/></a:xfrm>'
                . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr></p:pic>';
        }
        $shapes .= pptx_rect($id++, 0, 4400000, PPTX_W, 45720, PPTX_ORANGE);
        if (!$hasLogo) {
            $shapes .= pptx_textbox($id++, 914400, 2450000, 10363200, 1600000,
                pptx_para($s['title'], 4400, 'FFFFFF', '', true, 'Calibri'), 'b');
        }
        if (!empty($s['subtitle'])) {
            $shapes .= pptx_textbox($id++, 914400, 4650000, 10363200, 1200000,
                pptx_para($s['subtitle'], 1600, 'BFD3E6'), 't');
        }
    } elseif ($kind === 'section') {
        // The transition look, matched to the drone deck's
        // Cross-Survey Applications slide as it actually renders: a
        // white page; the navy lattice as a panel over the top
        // two-thirds, starting at a hairline that runs the full
        // height; the institute name reading up the white left
        // margin with the Block I at its foot; a tall white Bebas
        // title on the panel; and the kicker in quiet grey on the
        // white below. Where that slide carries its photograph, a
        // part's agenda rides on a full-height navy block instead.
        foreach (['section_bg.png', 'blocki.png'] as $nm) {
            if (!isset($media[$nm])) {
                $media[$nm] = (string)file_get_contents(PPTX_ASSETS . '/' . $nm);
            }
        }
        $bgId = 'rId' . (count($rels) + 2);
        $rels[$bgId] = 'section_bg.png';
        $logoId = 'rId' . (count($rels) + 2);
        $rels[$logoId] = 'blocki.png';

        $agenda = $s['bullets'] ?? [];
        $shapes .= pptx_rect($id++, 0, 0, PPTX_W, PPTX_H, 'FFFFFF');
        $shapes .= pptx_crop_pic($id++, $bgId, '<a:srcRect t="13586" b="13586"/>',
                                 981864, 0, 11210136, 4592195);
        $shapes .= pptx_rect($id++, 981856, 0, 6350, PPTX_H, '404040');
        $shapes .= '<p:sp><p:nvSpPr><p:cNvPr id="' . $id++ . '" name="rail"/>'
            . '<p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr>'
            . '<p:spPr><a:xfrm rot="16200000"><a:off x="-983538" y="4163362"/>'
            . '<a:ext cx="2945037" cy="261610"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/></p:spPr>'
            . '<p:txBody><a:bodyPr wrap="none" rtlCol="0"/><a:lstStyle/>'
            . '<a:p><a:r><a:rPr lang="en-US" sz="1200" b="1" spc="150" dirty="0">'
            . '<a:solidFill><a:srgbClr val="404040"/></a:solidFill>'
            . '<a:latin typeface="Calibri"/></a:rPr>'
            . '<a:t>PRAIRIE RESEARCH INSTITUTE</a:t></a:r></a:p></p:txBody></p:sp>';
        $shapes .= pptx_flat_pic($id++, $logoId, 356475, 6117535, 256314, 370231);

        if ($agenda) {
            // The agenda takes the photograph's footprint: a
            // full-height navy block on the right. It fills that block
            // like every other body text on the deck - a fixed 13pt left
            // half of it empty and read as an afterthought.
            $shapes .= pptx_rect($id++, 7316391, 0, 4875609, PPTX_H, PPTX_UINAVY);
            $agW = 4075609;
            $agH = 5658000;
            [$agSz, $agFit] = pptx_fitsize($agenda, $agW / 914400, $agH / 914400);
            $ag = '';
            foreach ($agenda as $line) {
                if (str_starts_with($line, 'h:')) {
                    $ag .= pptx_para(substr($line, 2), $agSz + 200, 'F0A583', '', true,
                                     'Calibri', 700);
                } else {
                    $ag .= pptx_para($line, $agSz, 'E8EEF4', 'b', false, 'Calibri', 250);
                }
            }
            $shapes .= pptx_textbox($id++, 7716391, 600000, $agW, $agH, $ag, 'ctr', $agFit);
        }

        $titleXml = '<a:p><a:pPr><a:lnSpc><a:spcPts val="8600"/></a:lnSpc><a:buNone/></a:pPr>'
            . '<a:r><a:rPr lang="en-US" sz="6600" b="1" dirty="0">'
            . '<a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill>'
            . '<a:effectLst><a:outerShdw blurRad="38100" dist="38100" dir="2700000" algn="tl">'
            . '<a:srgbClr val="000000"><a:alpha val="43137"/></a:srgbClr>'
            . '</a:outerShdw></a:effectLst>'
            . '<a:latin typeface="Calibri"/></a:rPr>'
            . '<a:t>' . pptx_esc($s['title']) . '</a:t></a:r></a:p>';
        $titleW = $agenda ? 5300000 : 9547123;
        $shapes .= pptx_textbox($id++, 1801368, 1900000, $titleW, 2300000, $titleXml, 'ctr');
        if (!empty($s['subtitle'])) {
            $shapes .= pptx_textbox($id++, 1801368, 5504688,
                $agenda ? 5300000 : 9546336, 429768,
                pptx_para($s['subtitle'], 1600, '404040'), 't');
        }
    } elseif ($kind === 'image') {
        // Title sits in the master's navy band.
        $shapes .= pptx_title_bar($id, $s['title'], 10300000);

        $img = $s['image'] ?? null;
        $haveImage = $img !== null && is_file($img);
        if ($haveImage) {
            $bytes = (string)file_get_contents($img);
            [$pw, $ph] = pptx_png_size($bytes);
            $name = 'image' . (count($media) + 1) . '.png';
            $media[$name] = $bytes;
            $rId = 'rId' . (count($rels) + 2);   // rId1 is the layout
            $rels[$rId] = $name;
            $hasPipe = !empty($s['pipe']);
            if (!empty($s['tall'])) {
                // A stacked database drawing: tall image right, room for
                // as many bullets as the story needs on the left. The
                // drawing box shortens when a locator rides beneath it.
                $artH = (int)($s['arth'] ?? 5050000);
                $tallTh = (int)($s['texth'] ?? 4900000);
                $tallSz = 1600;
                if (empty($s['textscale'])) {
                    [$tallSz, $tallFit] = pptx_fitsize($s['bullets'] ?? [], 6.45,
                                                       $tallTh / 914400);
                } else {
                    $tallFit = '<a:normAutofit fontScale="' . ((int)$s['textscale'] * 1000)
                             . '" lnSpcReduction="10000"/>';
                }
                $shapes .= pptx_pic($id++, $rId, 6700000, 1040000, 5100000, $artH,
                                    $pw, $ph, false);
                $shapes .= pptx_textbox($id++, 457200, 1060000, 5900000, $tallTh,
                    pptx_bullets($s['bullets'] ?? [], $tallSz), 't', $tallFit);
                $xml = '';
                goto assemble;
            }
            if (!empty($s['full'])) {
                // A screen that must be seen whole - the building map
                // with its pop-out room card. The picture takes the
                // entire body; its explanation travels on the slide
                // before it.
                $shapes .= pptx_pic($id++, $rId, 300000, 1040000, 11592000, 4970000, $pw, $ph);
                $xml = '';
                goto assemble;
            }
            if (!empty($s['wide'])) {
                // A diagram slide: the picture takes the whole body,
                // because it carries its own legend and labels. There is
                // nowhere for bullets to go here, so being handed some
                // means they would vanish off the deck without a word.
                if (!empty($s['bullets'])) {
                    fwrite(STDERR, 'FATAL: slide ' . $slideNo . ' ("' . $s['title']
                        . '") is a wide diagram carrying ' . count($s['bullets'])
                        . " bullets that the layout cannot show. Move them to a"
                        . " caption or a slide of their own.\n");
                    exit(1);
                }
                // The caption needs a line of its own clear of the campus
                // banner, so the drawing stops short of it rather than
                // pushing the caption onto the banner's top edge. Without
                // one the drawing takes the whole body - and on a
                // height-bound drawing every EMU of that is type size.
                $capH = empty($s['caption']) ? -130000 : 220000;
                $shapes .= pptx_pic($id++, $rId, 300000, 1040000, 11592000,
                                    4850000 - $capH, $pw, $ph, false);
                if ($capH > 0) {
                    $shapes .= pptx_textbox($id++, 457200, 5790000, 11277600, 220000,
                        pptx_para($s['caption'], 1200, PPTX_MUTE, '', false, 'Calibri'), 't');
                }
                $xml = '';
                goto assemble;
            }
            if (!empty($s['jobaid'])) {
                // A job aid: the steps take the page, the filled form
                // stands tall beside them, the strip says where it lands.
                // Portrait captures would shrink to nothing in the wide
                // screenshot box, so they get a box shaped like a form.
                // 5.6in x 4.9in - the shape tools/capture_wex.py crops to.
                // The form gets 7.5in of the width, because that is what
                // it takes for its smallest label to land at 12pt. It had
                // 5.6in, which put the label at 9pt, and the steps had the
                // larger half of the slide - exactly backwards, since the
                // steps are native text that can be set at any size and
                // the screenshot cannot.
                $jaH = $hasPipe ? 4440000 : 4780000;
                $shapes .= pptx_pic($id++, $rId, 4600000, 1060000, 7131000, $jaH,
                                    $pw, $ph);
                if ($hasPipe) {
                    $shapes .= pptx_pipe($id, (array)$s['pipe'][0], (int)$s['pipe'][1],
                                         (string)$s['pipe'][2]);
                }
                $jaW = 3960000;
                [$jsz, $jfit] = pptx_fitsize($s['bullets'] ?? [], $jaW / 914400,
                                             $jaH / 914400);
                $shapes .= pptx_textbox($id++, 457200, 1060000, $jaW, $jaH,
                    pptx_bullets($s['bullets'] ?? [], $jsz), 't', $jfit);
                $xml = '';
                goto assemble;
            }
            // The image gets the width; the pipeline strip gets the foot.
            $imgH = $hasPipe ? 4560000 : 4660000;
            $shapes .= pptx_pic($id++, $rId, 3940000, 1060000, 7880000, $imgH, $pw, $ph);
            if ($hasPipe) {
                $shapes .= pptx_pipe($id, (array)$s['pipe'][0], (int)$s['pipe'][1],
                                     (string)$s['pipe'][2]);
            } elseif (!empty($s['caption'])) {
                $shapes .= pptx_textbox($id++, 3940000, 5760000, 7880000, 320000,
                    pptx_para($s['caption'], 1200, PPTX_MUTE, '', false, 'Calibri'), 't');
            }
            $bulH = !empty($s['pipe']) ? 4520000 : 4660000;
            [$bsz, $bfit] = pptx_fitsize($s['bullets'] ?? [], 3.6, $bulH / 914400);
            $shapes .= pptx_textbox($id++, 457200, 1060000, 3300000, $bulH,
                pptx_bullets($s['bullets'] ?? [], $bsz), 't', $bfit);
        } else {
            // The screenshot is missing: say so rather than leaving a
            // hole nobody can explain.
            $shapes .= pptx_textbox($id++, 457200, 1060000, 11277600, 4920000,
                pptx_bullets(array_merge($s['bullets'] ?? [],
                    ['h:Screenshot missing', 'Recapture with tools/make_presentation.php']),
                    1500), 't');
        }
    } else {   // content
        $shapes .= pptx_title_bar($id, $s['title']);
        $b = $s['bullets'] ?? [];
        if (!empty($s['cols']) && (int)$s['cols'] === 2) {
            // Two columns, split at a section boundary, so list-shaped
            // slides fill the width instead of leaving half a page blank.
            // Split where the two columns come out the same HEIGHT, not
            // where they hold the same number of entries: one long
            // paragraph outweighs three short ones, and splitting by
            // count is what left column one overflowing while column two
            // sat half empty. Section boundaries are preferred; any line
            // will do if no boundary balances.
            $cost = function (int $cut) use ($b): float {
                return max(pptx_fitneed(array_slice($b, 0, $cut), 5.99, 1600),
                           pptx_fitneed(array_slice($b, $cut), 5.99, 1600));
            };
            $heads = [];
            foreach ($b as $i => $line) {
                if ($i > 0 && str_starts_with($line, 'h:')) {
                    $heads[] = $i;
                }
            }
            // A heading must never be the last thing in a column with its
            // body in the next one, so those positions are not candidates
            // at all - not in the preferred pass and not in the fallback.
            $legal = array_values(array_filter(
                range(1, max(1, count($b) - 1)),
                fn($i) => !str_starts_with($b[$i - 1] ?? '', 'h:')));
            $cut = count($b);
            $best = INF;
            foreach ($heads ?: $legal as $i) {
                $c = $cost((int)$i);
                if ($c < $best) {
                    $best = $c;
                    $cut = (int)$i;
                }
            }
            // If the best heading boundary still leaves a column over
            // its box, split anywhere legal rather than overflow.
            $th0 = (int)($s['texth'] ?? 4900000) / 914400;
            if ($best > $th0) {
                foreach ($legal as $i) {
                    $c = $cost((int)$i);
                    if ($c < $best) {
                        $best = $c;
                        $cut = (int)$i;
                    }
                }
            }
            $th = (int)($s['texth'] ?? 4900000);
            $csz = 1600;
            if (!empty($s['textscale'])) {
                $autofit = '<a:normAutofit fontScale="' . ((int)$s['textscale'] * 1000)
                         . '" lnSpcReduction="10000"/>';
            } else {
                // Both columns share one size and scale so they match,
                // and the ladder runs to the 12pt floor at full size
                // rather than stopping at 16 and shrinking from there.
                $autofit = '';
                $csz = 1200;
                foreach ([2000, 1900, 1800, 1700, 1600, 1500, 1400, 1300, 1200] as $trySz) {
                    if (pptx_fitneed(array_slice($b, 0, $cut), 5.99, $trySz) <= $th / 914400
                        && pptx_fitneed(array_slice($b, $cut), 5.99, $trySz) <= $th / 914400) {
                        $csz = $trySz;
                        $autofit = '<a:normAutofit/>';
                        break;
                    }
                }
                if ($autofit === '') {
                    $pct = min(pptx_fitpct(array_slice($b, 0, $cut), 5.99, $th / 914400, 1200),
                               pptx_fitpct(array_slice($b, $cut), 5.99, $th / 914400, 1200));
                    $autofit = $pct >= 100 ? '<a:normAutofit/>'
                        : '<a:normAutofit fontScale="' . ($pct * 1000)
                          . '" lnSpcReduction="10000"/>';
                }
            }
            $left = pptx_bullets(array_slice($b, 0, $cut), $csz);
            $right = pptx_bullets(array_slice($b, $cut), $csz);
            // A text body with no paragraph at all is schema-invalid,
            // so an emptied column simply emits no box.
            if ($left !== '') {
                $shapes .= pptx_textbox($id++, 457200, 1100000, 5480000, $th,
                    $left, 't', $autofit);
            }
            if ($right !== '') {
                $shapes .= pptx_textbox($id++, 6250000, 1100000, 5480000, $th,
                    $right, 't', $autofit);
            }
            if (!empty($s['pipe'])) {
                $shapes .= pptx_pipe($id, (array)$s['pipe'][0], (int)$s['pipe'][1],
                                     (string)$s['pipe'][2]);
            }
        } else {
            // The visual pack squeezes some slides' text upward to make
            // room for a figure: an explicit font scale keeps the text
            // inside its shortened box in every renderer.
            $tw = (int)($s['textw'] ?? 11277600);
            $th = (int)($s['texth'] ?? 4900000);
            $dsz = 1600;
            if (!empty($s['textscale'])) {
                $autofit = '<a:normAutofit fontScale="' . ((int)$s['textscale'] * 1000)
                         . '" lnSpcReduction="10000"/>';
            } else {
                [$dsz, $autofit] = pptx_fitsize($b, $tw / 914400, $th / 914400);
            }
            $shapes .= pptx_bullets($b, $dsz) !== ''
                ? pptx_textbox($id++, 457200, 1100000, $tw, $th,
                    pptx_bullets($b, $dsz), 't', $autofit)
                : '';
            if (!empty($s['pipe'])) {
                $shapes .= pptx_pipe($id, (array)$s['pipe'][0], (int)$s['pipe'][1],
                                     (string)$s['pipe'][2]);
            }
        }
    }

    assemble:
    if (!empty($s['xtra'])) {
        $shapes .= (string)$s['xtra'];
    }
    // Overlay figures from the visual pack: each entry is a file path,
    // a box in EMU, and an optional alpha (per-mille of opaque) for
    // screened watermark marks. The image is contain-fit and centred.
    if (!empty($s['figs'])) {
        static $figCache = [];
        foreach ($s['figs'] as $f) {
            [$fp, $bx, $by, $bw, $bh] = $f;
            $alpha = (int)($f[5] ?? 0);
            if (!isset($figCache[$fp])) {
                if (!is_file($fp)) {
                    continue;
                }
                $bytes = (string)file_get_contents($fp);
                [$pw2, $ph2] = pptx_png_size($bytes);
                $name = 'vfig' . (count($figCache) + 1) . '.png';
                $media[$name] = $bytes;
                $figCache[$fp] = [$name, $pw2, $ph2];
            }
            [$name, $pw2, $ph2] = $figCache[$fp];
            if (!isset($media[$name])) {
                $media[$name] = (string)file_get_contents($fp);
            }
            $rId = 'rId' . (count($rels) + 2);
            $rels[$rId] = $name;
            $sc = min($bw / $pw2, $bh / $ph2);
            $fw = (int)($pw2 * $sc);
            $fh = (int)($ph2 * $sc);
            $fx = $bx + (int)(($bw - $fw) / 2);
            $fy = $by + (int)(($bh - $fh) / 2);
            $shapes .= '<p:pic><p:nvPicPr><p:cNvPr id="' . $id++ . '" name="vfig"/>'
                . '<p:cNvPicPr/><p:nvPr/></p:nvPicPr>'
                . '<p:blipFill><a:blip r:embed="' . $rId . '">'
                . ($alpha > 0 ? '<a:alphaModFix amt="' . $alpha . '"/>' : '')
                . '</a:blip><a:stretch><a:fillRect/></a:stretch></p:blipFill>'
                . '<p:spPr><a:xfrm><a:off x="' . $fx . '" y="' . $fy . '"/>'
                . '<a:ext cx="' . $fw . '" cy="' . $fh . '"/></a:xfrm>'
                . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr></p:pic>';
        }
    }
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
        . 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
        . ($kind === 'title' || $kind === 'section'
            ? '<p:cSld><p:spTree>'
            : '<p:cSld><p:spTree>')
        . '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
        . '<p:grpSpPr/>' . $shapes . '</p:spTree></p:cSld>'
        . '<p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>';

    return [$xml, $rels];
}

function pptx_master(): string
{
    // The application's header, carried onto every working slide: navy
    // band and the orange rule. The foot carries the campus banner,
    // lifted intact from the INHS deck: Illinois navy behind two crops
    // of one wordmark strip - Block I + ILLINOIS on the left, the
    // institute address block on the right, both bleeding past the
    // bottom edge exactly as the source has them. Title and section
    // slides paint over all of it with their own full-bleed art.
    $shapes = pptx_rect(2, 0, 0, PPTX_W, 960120, PPTX_NAVY)
            . pptx_rect(3, 0, 960120, PPTX_W, 45720, PPTX_ORANGE)
            . pptx_rect(4, 0, 6085840, PPTX_W, 772160, PPTX_UINAVY)
            . pptx_crop_pic(5, 'rId3', '<a:srcRect r="59773"/>',
                            116084, 6210245, 2834641, 1117989)
            . pptx_banner_address(6);
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<p:sldMaster xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
        . 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
        . '<p:cSld><p:bg><p:bgPr><a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill>'
        . '<a:effectLst/></p:bgPr></p:bg><p:spTree>'
        . '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
        . '<p:grpSpPr/>' . $shapes . '</p:spTree></p:cSld>'
        . '<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" '
        . 'accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" '
        . 'accent6="accent6" hlink="hlink" folHlink="folHlink"/>'
        . '<p:sldLayoutIdLst><p:sldLayoutId id="2147483649" r:id="rId1"/></p:sldLayoutIdLst>'
        . '</p:sldMaster>';
}

function pptx_layout(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<p:sldLayout xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
        . 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" type="blank">'
        . '<p:cSld name="Blank"><p:spTree>'
        . '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
        . '<p:grpSpPr/></p:spTree></p:cSld>'
        . '<p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sldLayout>';
}

function pptx_theme(): string
{
    $accents = ['457B9D', 'C7461F', '1D3557', 'E9C46A', '2A9D8F', '6B7280'];
    $a = '';
    foreach ($accents as $i => $c) {
        $a .= '<a:accent' . ($i + 1) . '><a:srgbClr val="' . $c . '"/></a:accent' . ($i + 1) . '>';
    }
    $fill = '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>';
    $ln = '<a:ln w="9525" cap="flat"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill>'
        . '<a:prstDash val="solid"/></a:ln>';
    $eff = '<a:effectStyle><a:effectLst/></a:effectStyle>';
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
        . 'name="PRI"><a:themeElements>'
        . '<a:clrScheme name="PRI">'
        . '<a:dk1><a:sysClr val="windowText" lastClr="000000"/></a:dk1>'
        . '<a:lt1><a:sysClr val="window" lastClr="FFFFFF"/></a:lt1>'
        . '<a:dk2><a:srgbClr val="' . PPTX_NAVY . '"/></a:dk2>'
        . '<a:lt2><a:srgbClr val="' . PPTX_PAPER . '"/></a:lt2>'
        . $a
        . '<a:hlink><a:srgbClr val="' . PPTX_STEEL . '"/></a:hlink>'
        . '<a:folHlink><a:srgbClr val="' . PPTX_MUTE . '"/></a:folHlink>'
        . '</a:clrScheme>'
        . '<a:fontScheme name="PRI">'
        . '<a:majorFont><a:latin typeface="Georgia"/><a:ea typeface=""/><a:cs typeface=""/></a:majorFont>'
        . '<a:minorFont><a:latin typeface="Calibri"/><a:ea typeface=""/><a:cs typeface=""/></a:minorFont>'
        . '</a:fontScheme>'
        . '<a:fmtScheme name="Office">'
        . '<a:fillStyleLst>' . $fill . $fill . $fill . '</a:fillStyleLst>'
        . '<a:lnStyleLst>' . $ln . $ln . $ln . '</a:lnStyleLst>'
        . '<a:effectStyleLst>' . $eff . $eff . $eff . '</a:effectStyleLst>'
        . '<a:bgFillStyleLst>' . $fill . $fill . $fill . '</a:bgFillStyleLst>'
        . '</a:fmtScheme></a:themeElements></a:theme>';
}

/** Assemble the whole deck and return it as bytes. */
function pptx_build(string $title, array $slides): string
{
    $files = [];
    $media = [];
    $slideXml = [];
    $slideRels = [];

    foreach ($slides as $i => $s) {
        [$xml, $rels] = pptx_slide($s, $media, $i + 1);
        $slideXml[$i + 1] = $xml;
        $slideRels[$i + 1] = $rels;
    }

    $n = count($slideXml);

    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Default Extension="png" ContentType="image/png"/>'
        . '<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>'
        . '<Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/>'
        . '<Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/>'
        . '<Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
    for ($i = 1; $i <= $n; $i++) {
        $ct .= '<Override PartName="/ppt/slides/slide' . $i . '.xml" '
             . 'ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>';
    }
    $files['[Content_Types].xml'] = $ct . '</Types>';

    $files['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '</Relationships>';

    $files['docProps/core.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
        . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
        . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title>' . pptx_esc($title) . '</dc:title>'
        . '<dc:creator>PRIOps</dc:creator>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . date('c') . '</dcterms:created>'
        . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . date('c') . '</dcterms:modified>'
        . '</cp:coreProperties>';

    $pres = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<p:presentation xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
        . 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
        . '<p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst>'
        . '<p:sldIdLst>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>';
    for ($i = 1; $i <= $n; $i++) {
        $pres .= '<p:sldId id="' . (255 + $i) . '" r:id="rId' . ($i + 1) . '"/>';
        $rels .= '<Relationship Id="rId' . ($i + 1) . '" '
               . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" '
               . 'Target="slides/slide' . $i . '.xml"/>';
    }
    $files['ppt/presentation.xml'] = $pres . '</p:sldIdLst>'
        . '<p:sldSz cx="' . PPTX_W . '" cy="' . PPTX_H . '"/>'
        . '<p:notesSz cx="6858000" cy="9144000"/></p:presentation>';
    $files['ppt/_rels/presentation.xml.rels'] = $rels . '</Relationships>';

    $files['ppt/slideMasters/slideMaster1.xml'] = pptx_master();
    $files['ppt/slideMasters/_rels/slideMaster1.xml.rels'] =
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="../theme/theme1.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/banner.png"/>'
        . '</Relationships>';
    $media['banner.png'] = (string)file_get_contents(PPTX_ASSETS . '/banner.png');
    $files['ppt/slideLayouts/slideLayout1.xml'] = pptx_layout();
    $files['ppt/slideLayouts/_rels/slideLayout1.xml.rels'] =
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster1.xml"/>'
        . '</Relationships>';
    $files['ppt/theme/theme1.xml'] = pptx_theme();

    foreach ($slideXml as $i => $xml) {
        $files['ppt/slides/slide' . $i . '.xml'] = $xml;
        $r = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
           . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
           . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>';
        foreach ($slideRels[$i] as $rId => $name) {
            $r .= '<Relationship Id="' . $rId . '" '
                . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" '
                . 'Target="../media/' . $name . '"/>';
        }
        $files['ppt/slides/_rels/slide' . $i . '.xml.rels'] = $r . '</Relationships>';
    }

    foreach ($media as $name => $bytes) {
        $files['ppt/media/' . $name] = $bytes;
    }

    return zip_parts_string($files);
}

function pptx_write(string $path, string $title, array $slides): int
{
    $bytes = pptx_build($title, $slides);
    // A locked target (the deck open in PowerPoint) must fail the build
    // loudly - a stale file that claims to be fresh is worse than none.
    for ($try = 0; $try < 3; $try++) {
        if (@file_put_contents($path, $bytes) === strlen($bytes)) {
            return strlen($bytes);
        }
        sleep(2);
    }
    fwrite(STDERR, "FATAL: cannot write $path - close it in PowerPoint and rebuild\n");
    exit(1);
}
