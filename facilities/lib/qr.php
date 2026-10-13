<?php
declare(strict_types=1);

/**
 * A QR code, written out by hand, because there is no Composer here and
 * no image extension either - this PHP has neither GD nor Imagick, so
 * even drawing a PNG is off the table.
 *
 * The way round both problems is SVG. A QR code is a grid of black and
 * white squares, and an SVG is text describing squares, so the output is
 * a string this file builds directly. It scales to any label size, prints
 * sharp, and needs nothing installed.
 *
 * Byte mode only, which is all a URL needs. Versions 1 to 10, which holds
 * a comfortable campus URL at the error correction level that survives a
 * label being scuffed in a truck cab for three years.
 *
 * Everything here is the ISO 18004 specification: Reed-Solomon over
 * GF(256), the eight data masks with their penalty scores, and the
 * format and version information with their own BCH codes.
 */

/** Total codewords, and the error correction blocks, per version and level. */
const QR_ECC = [
    // version => [level => [ecCodewordsPerBlock, group1Blocks, group1Words,
    //                       group2Blocks, group2Words]]
    1  => ['L' => [7, 1, 19, 0, 0],   'M' => [10, 1, 16, 0, 0],
          'Q' => [13, 1, 13, 0, 0],   'H' => [17, 1, 9, 0, 0]],
    2  => ['L' => [10, 1, 34, 0, 0],  'M' => [16, 1, 28, 0, 0],
          'Q' => [22, 1, 22, 0, 0],   'H' => [28, 1, 16, 0, 0]],
    3  => ['L' => [15, 1, 55, 0, 0],  'M' => [26, 1, 44, 0, 0],
          'Q' => [18, 2, 17, 0, 0],   'H' => [22, 2, 13, 0, 0]],
    4  => ['L' => [20, 1, 80, 0, 0],  'M' => [18, 2, 32, 0, 0],
          'Q' => [26, 2, 24, 0, 0],   'H' => [16, 4, 9, 0, 0]],
    5  => ['L' => [26, 1, 108, 0, 0], 'M' => [24, 2, 43, 0, 0],
          'Q' => [18, 2, 15, 2, 16],  'H' => [22, 2, 11, 2, 12]],
    6  => ['L' => [18, 2, 68, 0, 0],  'M' => [16, 4, 27, 0, 0],
          'Q' => [24, 4, 19, 0, 0],   'H' => [28, 4, 15, 0, 0]],
    7  => ['L' => [20, 2, 78, 0, 0],  'M' => [18, 4, 31, 0, 0],
          'Q' => [18, 2, 14, 4, 15],  'H' => [26, 4, 13, 1, 14]],
    8  => ['L' => [24, 2, 97, 0, 0],  'M' => [22, 2, 38, 2, 39],
          'Q' => [22, 4, 18, 2, 19],  'H' => [26, 4, 14, 2, 15]],
    9  => ['L' => [30, 2, 116, 0, 0], 'M' => [22, 3, 36, 2, 37],
          'Q' => [20, 4, 16, 4, 17],  'H' => [24, 4, 12, 4, 13]],
    10 => ['L' => [18, 2, 68, 2, 69], 'M' => [26, 4, 43, 1, 44],
          'Q' => [24, 6, 19, 2, 20],  'H' => [28, 6, 15, 2, 16]],
];

/** Where the alignment patterns sit, per version. */
const QR_ALIGN = [
    1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
    6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46],
    10 => [6, 28, 50],
];

/** The GF(256) log and antilog tables, built once. */
function qr_gf(): array
{
    static $tables = null;
    if ($tables !== null) {
        return $tables;
    }
    $exp = array_fill(0, 512, 0);
    $log = array_fill(0, 256, 0);
    $x = 1;
    for ($i = 0; $i < 255; $i++) {
        $exp[$i] = $x;
        $log[$x] = $i;
        $x <<= 1;
        if ($x & 0x100) {
            $x ^= 0x11D;   // the QR generator polynomial
        }
    }
    for ($i = 255; $i < 512; $i++) {
        $exp[$i] = $exp[$i - 255];
    }
    return $tables = [$exp, $log];
}

function qr_mul(int $a, int $b): int
{
    if ($a === 0 || $b === 0) {
        return 0;
    }
    [$exp, $log] = qr_gf();
    return $exp[$log[$a] + $log[$b]];
}

/** The generator polynomial for n error correction codewords. */
function qr_generator(int $n): array
{
    $poly = [1];
    [$exp] = qr_gf();
    for ($i = 0; $i < $n; $i++) {
        // Multiply by (x + alpha^i). Coefficients run highest degree
        // first, so the x term keeps its index and the constant term
        // moves one along - the other way round produces the reciprocal
        // polynomial, which looks entirely plausible and is wrong.
        $next = array_fill(0, count($poly) + 1, 0);
        foreach ($poly as $j => $c) {
            $next[$j] ^= $c;
            $next[$j + 1] ^= qr_mul($c, $exp[$i]);
        }
        $poly = $next;
    }
    return $poly;
}

/** The error correction codewords for one block. */
function qr_ec(array $data, int $n): array
{
    $gen = qr_generator($n);
    $buf = array_merge($data, array_fill(0, $n, 0));
    $len = count($data);
    for ($i = 0; $i < $len; $i++) {
        $factor = $buf[$i];
        if ($factor === 0) {
            continue;
        }
        foreach ($gen as $j => $g) {
            $buf[$i + $j] ^= qr_mul($g, $factor);
        }
    }
    return array_slice($buf, $len);
}

/** How many data codewords a version and level holds. */
function qr_capacity(int $version, string $level): int
{
    [$ec, $b1, $w1, $b2, $w2] = QR_ECC[$version][$level];
    return $b1 * $w1 + $b2 * $w2;
}

/** The smallest version that fits this text, or 0 if none of them do. */
function qr_version_for(string $text, string $level): int
{
    $len = strlen($text);
    foreach (array_keys(QR_ECC) as $v) {
        // 4 bits mode + 8 or 16 bits length + the data itself.
        $lenBits = $v < 10 ? 8 : 16;
        $needed = (int)ceil((4 + $lenBits + $len * 8) / 8);
        if ($needed <= qr_capacity($v, $level)) {
            return $v;
        }
    }
    return 0;
}

/**
 * The whole code as a square array of 0 and 1, or null if the text will
 * not fit in the versions this file handles.
 */
function qr_matrix(string $text, string $level = 'M'): ?array
{
    $level = strtoupper($level);
    $version = qr_version_for($text, $level);
    if ($version === 0) {
        return null;
    }
    $size = 17 + $version * 4;
    [$ecWords, $b1, $w1, $b2, $w2] = QR_ECC[$version][$level];

    // ---- the bit stream ------------------------------------------------
    $bits = '';
    $bits .= '0100';                                    // byte mode
    $lenBits = $version < 10 ? 8 : 16;
    $bits .= str_pad(decbin(strlen($text)), $lenBits, '0', STR_PAD_LEFT);
    for ($i = 0, $n = strlen($text); $i < $n; $i++) {
        $bits .= str_pad(decbin(ord($text[$i])), 8, '0', STR_PAD_LEFT);
    }
    $capacityBits = qr_capacity($version, $level) * 8;
    $bits .= str_repeat('0', min(4, $capacityBits - strlen($bits)));   // terminator
    while (strlen($bits) % 8 !== 0) {
        $bits .= '0';
    }
    // Pad alternately with these two bytes until full - the spec says so.
    $pad = ['11101100', '00010001'];
    $p = 0;
    while (strlen($bits) < $capacityBits) {
        $bits .= $pad[$p++ % 2];
    }

    $codewords = [];
    for ($i = 0; $i < strlen($bits); $i += 8) {
        $codewords[] = bindec(substr($bits, $i, 8));
    }

    // ---- split into blocks, add error correction, interleave -----------
    $blocks = [];
    $pos = 0;
    for ($i = 0; $i < $b1; $i++) {
        $blocks[] = array_slice($codewords, $pos, $w1);
        $pos += $w1;
    }
    for ($i = 0; $i < $b2; $i++) {
        $blocks[] = array_slice($codewords, $pos, $w2);
        $pos += $w2;
    }
    $ecBlocks = array_map(fn($b) => qr_ec($b, $ecWords), $blocks);

    $final = [];
    $longest = max(array_map('count', $blocks));
    for ($i = 0; $i < $longest; $i++) {
        foreach ($blocks as $b) {
            if (isset($b[$i])) {
                $final[] = $b[$i];
            }
        }
    }
    for ($i = 0; $i < $ecWords; $i++) {
        foreach ($ecBlocks as $b) {
            if (isset($b[$i])) {
                $final[] = $b[$i];
            }
        }
    }

    $stream = '';
    foreach ($final as $c) {
        $stream .= str_pad(decbin($c), 8, '0', STR_PAD_LEFT);
    }

    // ---- the grid ------------------------------------------------------
    // null means "nothing placed here yet", which is how the data walk
    // knows which cells are free.
    $m = array_fill(0, $size, array_fill(0, $size, null));

    $finder = function (int $r, int $c) use (&$m, $size) {
        for ($i = -1; $i <= 7; $i++) {
            for ($j = -1; $j <= 7; $j++) {
                $rr = $r + $i;
                $cc = $c + $j;
                if ($rr < 0 || $cc < 0 || $rr >= $size || $cc >= $size) {
                    continue;
                }
                $on = ($i >= 0 && $i <= 6 && ($j === 0 || $j === 6))
                   || ($j >= 0 && $j <= 6 && ($i === 0 || $i === 6))
                   || ($i >= 2 && $i <= 4 && $j >= 2 && $j <= 4);
                $m[$rr][$cc] = $on ? 1 : 0;
            }
        }
    };
    $finder(0, 0);
    $finder(0, $size - 7);
    $finder($size - 7, 0);

    // Timing patterns: the dotted lines that tell a scanner the grid pitch.
    for ($i = 8; $i < $size - 8; $i++) {
        $m[6][$i] = $m[6][$i] ?? ($i % 2 === 0 ? 1 : 0);
        $m[$i][6] = $m[$i][6] ?? ($i % 2 === 0 ? 1 : 0);
    }

    // Alignment patterns, wherever they do not collide with a finder.
    $centres = QR_ALIGN[$version];
    foreach ($centres as $r) {
        foreach ($centres as $c) {
            if (($r <= 8 && $c <= 8) || ($r <= 8 && $c >= $size - 9)
                || ($r >= $size - 9 && $c <= 8)) {
                continue;
            }
            for ($i = -2; $i <= 2; $i++) {
                for ($j = -2; $j <= 2; $j++) {
                    $m[$r + $i][$c + $j] =
                        (max(abs($i), abs($j)) !== 1) ? 1 : 0;
                }
            }
        }
    }

    $m[$size - 8][8] = 1;   // the one cell that is always dark

    // Reserve the format areas so the data walk steps over them.
    for ($i = 0; $i < 9; $i++) {
        $m[8][$i] = $m[8][$i] ?? 0;
        $m[$i][8] = $m[$i][8] ?? 0;
    }
    for ($i = $size - 8; $i < $size; $i++) {
        $m[8][$i] = $m[8][$i] ?? 0;
        $m[$i][8] = $m[$i][8] ?? 0;
    }
    if ($version >= 7) {
        for ($i = 0; $i < 6; $i++) {
            for ($j = 0; $j < 3; $j++) {
                $m[$size - 11 + $j][$i] = 0;
                $m[$i][$size - 11 + $j] = 0;
            }
        }
    }

    // ---- the data walk: up two columns at a time, boustrophedon -------
    $bit = 0;
    $len = strlen($stream);
    $col = $size - 1;
    $upward = true;
    while ($col > 0) {
        if ($col === 6) {
            $col--;   // the vertical timing column is skipped entirely
        }
        for ($i = 0; $i < $size; $i++) {
            $row = $upward ? $size - 1 - $i : $i;
            foreach ([$col, $col - 1] as $c) {
                if ($m[$row][$c] !== null) {
                    continue;
                }
                $m[$row][$c] = $bit < $len ? (int)$stream[$bit] : 0;
                $bit++;
            }
        }
        $col -= 2;
        $upward = !$upward;
    }

    // ---- pick the mask that scans best --------------------------------
    $best = null;
    $bestScore = PHP_INT_MAX;
    for ($mask = 0; $mask < 8; $mask++) {
        $try = qr_apply_mask($m, $mask, $size);
        qr_place_format($try, $level, $mask, $size);
        if ($version >= 7) {
            qr_place_version($try, $version, $size);
        }
        $score = qr_penalty($try, $size);
        if ($score < $bestScore) {
            $bestScore = $score;
            $best = $try;
        }
    }
    return $best;
}

/** The eight masks, applied to the data cells only. */
function qr_apply_mask(array $m, int $mask, int $size): array
{
    // A cell belongs to the function patterns if it was placed before the
    // data walk. Rebuilding that map here would be fragile, so the mask
    // is decided by the same rule the walk used: everything except the
    // finders, timing, alignment, format and version areas.
    for ($r = 0; $r < $size; $r++) {
        for ($c = 0; $c < $size; $c++) {
            if (qr_is_function($r, $c, $size)) {
                continue;
            }
            $flip = match ($mask) {
                0 => ($r + $c) % 2 === 0,
                1 => $r % 2 === 0,
                2 => $c % 3 === 0,
                3 => ($r + $c) % 3 === 0,
                4 => ((int)($r / 2) + (int)($c / 3)) % 2 === 0,
                5 => (($r * $c) % 2) + (($r * $c) % 3) === 0,
                6 => ((($r * $c) % 2) + (($r * $c) % 3)) % 2 === 0,
                default => ((($r + $c) % 2) + (($r * $c) % 3)) % 2 === 0,
            };
            if ($flip) {
                $m[$r][$c] ^= 1;
            }
        }
    }
    return $m;
}

/** Is this cell part of the fixed patterns rather than the data? */
function qr_is_function(int $r, int $c, int $size): bool
{
    $version = ($size - 17) / 4;
    if ($r <= 8 && $c <= 8) {
        return true;                       // top left finder and format
    }
    if ($r <= 8 && $c >= $size - 8) {
        return true;                       // top right finder and format
    }
    if ($r >= $size - 8 && $c <= 8) {
        return true;                       // bottom left finder and format
    }
    if ($r === 6 || $c === 6) {
        return true;                       // timing
    }
    if ($version >= 7 && (($r < 6 && $c >= $size - 11) || ($c < 6 && $r >= $size - 11))) {
        return true;                       // version information
    }
    $centres = QR_ALIGN[(int)$version] ?? [];
    foreach ($centres as $ar) {
        foreach ($centres as $ac) {
            if (($ar <= 8 && $ac <= 8) || ($ar <= 8 && $ac >= $size - 9)
                || ($ar >= $size - 9 && $ac <= 8)) {
                continue;
            }
            if (abs($r - $ar) <= 2 && abs($c - $ac) <= 2) {
                return true;
            }
        }
    }
    return false;
}

/** The 15 format bits, with their BCH code, in both of their places. */
function qr_place_format(array &$m, string $level, int $mask, int $size): void
{
    $levelBits = ['L' => 1, 'M' => 0, 'Q' => 3, 'H' => 2][$level];
    $data = ($levelBits << 3) | $mask;
    $rem = $data;
    for ($i = 0; $i < 10; $i++) {
        $rem = ($rem << 1) ^ ((($rem >> 9) & 1) * 0x537);
    }
    $bits = (($data << 10) | $rem) ^ 0x5412;

    for ($i = 0; $i < 15; $i++) {
        $b = ($bits >> $i) & 1;
        // Around the top left finder.
        if ($i < 6) {
            $m[8][$i] = $b;
        } elseif ($i === 6) {
            $m[8][7] = $b;
        } elseif ($i === 7) {
            $m[8][8] = $b;
        } elseif ($i === 8) {
            $m[7][8] = $b;
        } else {
            $m[14 - $i][8] = $b;
        }
        // And the copy split between the other two finders.
        if ($i < 8) {
            $m[$size - 1 - $i][8] = $b;
        } else {
            $m[8][$size - 15 + $i] = $b;
        }
    }
    $m[$size - 8][8] = 1;
}

/** Versions 7 and up carry their number in two 18 bit blocks. */
function qr_place_version(array &$m, int $version, int $size): void
{
    $rem = $version;
    for ($i = 0; $i < 12; $i++) {
        $rem = ($rem << 1) ^ ((($rem >> 11) & 1) * 0x1F25);
    }
    $bits = ($version << 12) | $rem;
    for ($i = 0; $i < 18; $i++) {
        $b = ($bits >> $i) & 1;
        $a = (int)($i / 3);
        $c = $i % 3;
        $m[$size - 11 + $c][$a] = $b;
        $m[$a][$size - 11 + $c] = $b;
    }
}

/** The four penalty rules a scanner cares about. Lower is better. */
function qr_penalty(array $m, int $size): int
{
    $score = 0;

    // Rule 1: runs of five or more of the same colour.
    for ($pass = 0; $pass < 2; $pass++) {
        for ($i = 0; $i < $size; $i++) {
            $run = 1;
            for ($j = 1; $j < $size; $j++) {
                $a = $pass === 0 ? $m[$i][$j] : $m[$j][$i];
                $b = $pass === 0 ? $m[$i][$j - 1] : $m[$j - 1][$i];
                if ($a === $b) {
                    $run++;
                } else {
                    if ($run >= 5) {
                        $score += 3 + ($run - 5);
                    }
                    $run = 1;
                }
            }
            if ($run >= 5) {
                $score += 3 + ($run - 5);
            }
        }
    }

    // Rule 2: solid two by two blocks.
    for ($r = 0; $r < $size - 1; $r++) {
        for ($c = 0; $c < $size - 1; $c++) {
            $v = $m[$r][$c];
            if ($v === $m[$r][$c + 1] && $v === $m[$r + 1][$c] && $v === $m[$r + 1][$c + 1]) {
                $score += 3;
            }
        }
    }

    // Rule 3: the finder-like pattern appearing in the data.
    $needles = ['10111010000', '00001011101'];
    for ($pass = 0; $pass < 2; $pass++) {
        for ($i = 0; $i < $size; $i++) {
            $line = '';
            for ($j = 0; $j < $size; $j++) {
                $line .= $pass === 0 ? $m[$i][$j] : $m[$j][$i];
            }
            foreach ($needles as $needle) {
                $score += 40 * substr_count($line, $needle);
            }
        }
    }

    // Rule 4: too much of one colour overall.
    $dark = 0;
    foreach ($m as $row) {
        $dark += array_sum($row);
    }
    $percent = $dark * 100 / ($size * $size);
    $score += (int)(abs($percent - 50) / 5) * 10;

    return $score;
}

/**
 * The code as an SVG. No image extension involved: an SVG is text, and
 * this is the text.
 *
 * $px is the size of one module in the output units. The quiet zone of
 * four modules is part of the specification and part of why a code scans
 * from across a cab, so it is not optional here.
 */
function qr_svg(string $text, int $px = 4, string $level = 'M', string $alt = ''): string
{
    $m = qr_matrix($text, $level);
    if ($m === null) {
        return '';
    }
    $size = count($m);
    $quiet = 4;
    $side = ($size + $quiet * 2) * $px;

    // One path for every dark module beats one rect each: the file is
    // smaller, and a printer treats it as a single object.
    $d = '';
    for ($r = 0; $r < $size; $r++) {
        for ($c = 0; $c < $size; $c++) {
            if ($m[$r][$c] === 1) {
                $x = ($c + $quiet) * $px;
                $y = ($r + $quiet) * $px;
                $d .= 'M' . $x . ' ' . $y . 'h' . $px . 'v' . $px . 'h-' . $px . 'z';
            }
        }
    }

    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $side . '" height="' . $side
         . '" viewBox="0 0 ' . $side . ' ' . $side . '" role="img"'
         . ($alt !== '' ? ' aria-label="' . htmlspecialchars($alt, ENT_QUOTES) . '"' : '')
         . '><rect width="' . $side . '" height="' . $side . '" fill="#fff"/>'
         . '<path d="' . $d . '" fill="#000"/></svg>';
}
