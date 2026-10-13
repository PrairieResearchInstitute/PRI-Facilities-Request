<?php
declare(strict_types=1);

/**
 * The deck's visual pass, drawn native.
 *
 * Every device here emits real PowerPoint shapes and text boxes, so
 * anything with words on it can be edited afterwards. Images remain
 * only where the source is an image: application screenshots, the
 * database-layout drawings (the overview and the ten stacked
 * drawings), the single-row locator, photographs and icons.
 *
 * Included by make_presentation.php after the slide list is built;
 * runs in its scope ($S, $cov, $byCat, $comp, $gapIx, $fmt).
 */

require_once __DIR__ . '/../facilities/lib/pptxnative.php';
require_once __DIR__ . '/../facilities/lib/flowdefs.php';

$VP1 = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'reference' . DIRECTORY_SEPARATOR . 'Visual Pack' . DIRECTORY_SEPARATOR
     . 'Visual Pack 1' . DIRECTORY_SEPARATOR . 'unpacked' . DIRECTORY_SEPARATOR
     . 'assets' . DIRECTORY_SEPARATOR;
$VPR = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'reference' . DIRECTORY_SEPARATOR . 'Visual Pack' . DIRECTORY_SEPARATOR
     . 'regen' . DIRECTORY_SEPARATOR . 'png' . DIRECTORY_SEPARATOR;
$vicon = fn(string $f) => $VP1 . 'icons' . DIRECTORY_SEPARATOR . $f . '.png';
$vimg = fn(string $f) => is_file($VPR . $f . '.png') ? $VPR . $f . '.png'
      : $VP1 . 'figures' . DIRECTORY_SEPARATOR . $f . '.png';
$IN = 914400;

// ---- slide lookup: bind by title, nearest to a hint, never twice -----
$vpUsed = [];
$vpFind = function (int $hint, string $frag) use (&$S, &$vpUsed): ?int {
    $i = $hint - 1;
    if (isset($S[$i])
        && stripos((string)($S[$i]['title'] ?? ''), $frag) !== false) {
        return $i;
    }
    $best = null;
    foreach ($S as $k => $sl) {
        if (stripos((string)($sl['title'] ?? ''), $frag) !== false) {
            if ($best === null || abs($k - ($hint - 1)) < abs($best - ($hint - 1))) {
                $best = $k;
            }
        }
    }
    if ($best === null) {
        // A binding that matches nothing means a card grid, a flowchart
        // or a device has silently dropped off its slide. This used to
        // be a printed warning that the build walked straight past, and
        // three card grids went missing behind a clean gate because of
        // it. It stops the build now.
        fwrite(STDERR, 'FATAL: no slide titled like "' . $frag . '" near '
             . $hint . " - a device has lost its slide\n");
        exit(1);
    }
    return $best;
};

// ---- a slide's sections become device cards --------------------------
$nvSections = function (array $bullets): array {
    $cards = [];
    $cur = null;
    foreach ($bullets as $ln) {
        if (str_starts_with($ln, 'h:')) {
            if ($cur !== null) {
                $cards[] = $cur;
            }
            $cur = ['head' => substr($ln, 2), 'body' => []];
        } elseif ($cur !== null) {
            $cur['body'][] = $ln;
        }
    }
    if ($cur !== null) {
        $cards[] = $cur;
    }
    return $cards;
};
$nvFirstSentence = function (array $cards): array {
    foreach ($cards as &$c) {
        $joined = implode(' ', $c['body']);
        $dot = mb_strpos($joined, '. ');
        $c['body'] = [$dot !== false ? mb_substr($joined, 0, $dot + 1) : $joined];
    }
    return $cards;
};

/**
 * The height a slide's bullets need, split into two columns at the point
 * that balances them. The writer splits the same way, so this is what it
 * will actually produce, not an approximation of it.
 */
$nvTwoColNeed = function (array $b, int $sz = 1600): float {
    $best = INF;
    $n = count($b);
    for ($cut = 1; $cut < $n; $cut++) {
        // Same rule the writer uses: never strand a heading at the foot
        // of a column with its body at the top of the next one.
        if (str_starts_with($b[$cut - 1] ?? '', 'h:')) {
            continue;
        }
        $best = min($best, max(pptx_fitneed(array_slice($b, 0, $cut), 5.99, $sz),
                               pptx_fitneed(array_slice($b, $cut), 5.99, $sz)));
    }
    return $best === INF ? pptx_fitneed($b, 5.99, $sz) : $best;
};

/**
 * Place a measured device under a slide's text.
 *
 * The text is MEASURED, not guessed at from a hand-written band height -
 * guessing is what left a slide needing 3.6in of prose in a 2in box and
 * called it fine. The device steps its own type down 16-14-12 until the
 * text it is sharing the slide with fits at 12pt or better, because when
 * something has to give it is the figure, never the words.
 */
$nvBand = function (int $hint, string $frag, float $bandY, callable $make)
        use (&$S, $vpFind, $IN, $nvTwoColNeed) {
    $i = $vpFind($hint, $frag);
    if ($i === null) {
        return;
    }
    // A job aid already holds a form, its steps and the pipeline strip;
    // there is no band left under it, and its layout ignores a shortened
    // text box, so a device attached here lands on top of the steps.
    if (!empty($S[$i]['jobaid'])) {
        echo 'VISUALS AUDIT: refusing to band a device onto job aid ' . $frag . "\n";
        return;
    }
    $b = $S[$i]['bullets'] ?? [];
    $chosen = null;
    foreach ([16, 14, 12] as $devPt) {
        $nid = 500;
        [, $hEmu] = $make($nid, (int)(0.5 * $IN), 0, (int)(12.33 * $IN), $i, $devPt);
        $devH = $hEmu / $IN;
        $th = 5.05 - $devH - 0.10;                   // inches left for text
        // 0.15, not 0.30: the text box already stops 0.6in above the
        // device, so a wider reserve just squeezed the words for nothing.
        if ($th < 0.55) {
            // Too tall to share the slide at this size - try a smaller
            // one, but keep it as the fallback. A figure that does not
            // fit gets redesigned, never dropped, and dropping it is
            // what the bare `continue` here used to do.
            $chosen = $chosen ?? [$devPt, $devH, max(0.55, $th), count($b) >= 4];
            continue;
        }
        // Asked at the floor size itself, not at 16pt scaled by 0.75.
        // Line counts step with size, so that proxy was wrong: it left
        // slides in one column that only fit in two, and the writer then
        // had to shrink them below the floor to cope.
        if ($b === [] || pptx_fitneed($b, 12.33, PPTX_FLOOR) <= $th) {
            $chosen = [$devPt, $devH, $th, false];
            break;
        }
        if (count($b) >= 4 && $nvTwoColNeed($b, PPTX_FLOOR) <= $th) {
            $chosen = [$devPt, $devH, $th, true];
            break;
        }
        $chosen = [$devPt, $devH, $th, count($b) >= 4];
    }
    if ($chosen === null) {
        echo 'VISUALS AUDIT: no device size fits slide ' . $hint . ' (' . $frag . ")\n";
        return;
    }
    [$devPt, $devH, $th, $twoCol] = $chosen;
    $needAt = $twoCol ? $nvTwoColNeed($b, PPTX_FLOOR)
                      : pptx_fitneed($b, 12.33, PPTX_FLOOR);
    if ($b !== [] && $needAt > $th) {
        echo 'VISUALS AUDIT: slide ' . $hint . ' (' . $frag . ') text needs '
           . round($needAt, 2) . '" at the 12pt floor but has '
           . round($th, 2) . "\"\n";
    }
    $nid = 500;
    [$xml] = $make($nid, (int)(0.5 * $IN), (int)((6.55 - $devH) * $IN),
                   (int)(12.33 * $IN), $i, $devPt);
    $S[$i]['xtra'] = ($S[$i]['xtra'] ?? '') . $xml;
    if ($twoCol) {
        $S[$i]['cols'] = 2;
        $S[$i]['colsplit'] = 'even';
    }
    if ($devH > 0.1) {
        // No explicit scale: the writer's own fill ladder sizes the text
        // to this box, up to 20pt and down to the 12pt floor.
        $S[$i]['texth'] = (int)($th * $IN);
    }
};

// A card-row device from the slide's own sections, with a trim ladder.
$nvStrip = function (int $cols = 0, bool $arrows = false, bool $trim = true)
        use ($nvSections, $nvFirstSentence, &$S) {
    return function (int &$nid, int $x, int $y, int $w, int $i)
            use ($cols, $arrows, $trim, $nvSections, $nvFirstSentence, &$S) {
        $cards = $nvSections($S[$i]['bullets'] ?? []);
        if ($trim) {
            $cards = $nvFirstSentence($cards);
        }
        return nv_cardrow($nid, $x, $y, $w, $cards, $cols, $arrows);
    };
};

/**
 * Sections become the slide: the card row replaces the bullet text
 * entirely - full bodies, nothing duplicated, nothing squeezed
 * underneath - centred in the working area.
 */
$nvSolo = function (int $hint, string $frag, int $cols = 0, bool $arrows = false)
        use (&$S, $vpFind, $nvSections, $IN) {
    $i = $vpFind($hint, $frag);
    if ($i === null) {
        return;
    }
    // Lines before the first section head stay on the slide as bullets;
    // only the sections themselves become cards.
    $pre = [];
    foreach ($S[$i]['bullets'] ?? [] as $ln) {
        if (str_starts_with($ln, 'h:')) {
            break;
        }
        $pre[] = $ln;
    }
    $cards = $nvSections($S[$i]['bullets'] ?? []);
    if ($cards === []) {
        return;
    }
    // Cards take the largest type that still fits the slide: sparse
    // slides fill their space (20pt cap), stepping down as content
    // demands, to the 12pt floor.
    $limit = $pre === [] ? 5.25 : 3.20;
    $devH = 0.0;
    $bodyPt = 12;
    foreach ([20, 18, 16, 14, 12] as $try) {
        $nid = 500;
        [, $hEmu] = nv_cardrow($nid, (int)(0.5 * $IN), 0, (int)(12.33 * $IN),
                               $cards, $cols, $arrows, $try);
        $devH = $hEmu / $IN;
        $bodyPt = $try;
        if ($devH <= $limit) {
            break;
        }
    }
    if ($devH > 5.30) {
        echo 'VISUALS AUDIT: card row on slide ' . $hint . ' (' . $frag . ') runs '
           . round($devH, 2) . "\" tall\n";
    }
    $top = $pre === [] ? 1.30 + max(0.0, (5.25 - $devH) / 2) : 6.55 - $devH;
    $nid = 500;
    [$xml] = nv_cardrow($nid, (int)(0.5 * $IN), (int)($top * $IN),
                        (int)(12.33 * $IN), $cards, $cols, $arrows, $bodyPt);
    $S[$i]['xtra'] = ($S[$i]['xtra'] ?? '') . $xml;
    $S[$i]['bullets'] = $pre;
    unset($S[$i]['cols'], $S[$i]['colsplit'], $S[$i]['textscale'], $S[$i]['texth']);
    if ($pre !== []) {
        $S[$i]['texth'] = (int)((6.55 - $devH - 0.3 - 1.2) * $IN);
    }
};

// Worked examples are job aids now - a filled form beside the steps
// that fill it, built in make_presentation.php. Nothing to add here.

// ---- card-row one-offs from their sections ---------------------------
foreach ([[17, 'Notification Catalogue', 4.30], [40, 'Day One', 4.60],
          [21, 'Notifications Not Yet Built', 4.60], [9, 'New Plans, New Buildings', 4.60],
          [43, 'Deployment and Continuity', 4.60], [19, 'Scheduled Reminders', 4.60],
          [31, 'The Data Refresh Schedule', 4.60], [71, 'How Triage Scoring Works', 4.60],
          [77, 'The Duplicate Check', 4.60], [233, 'Decisions Needed from PRI', 4.10],
          [234, 'Assignments and Owners', 4.30], [89, 'Room Detail Tabs', 4.30]]
         as [$n, $frag, $bandY]) {
    $nvSolo($n, $frag, 0, false);
}

// FAQ and vocabulary: two-by-two card grids.
foreach ([[45, 'Part 1: Common'], [75, 'Part 2: Common'], [118, 'Part 3: Common'],
          [147, 'Part 4: Common'], [174, 'Part 5: Common'], [190, 'Part 6: Common'],
          [235, 'Part 7: Common'], [46, 'PRIOps Vocabulary'], [117, 'Lease Vocabulary'],
          [146, "Safety Vocabulary"], [173, "Equipment Vocabulary"]]
         as [$n, $frag]) {
    $nvSolo($n, $frag, 2, false);
}

// Fields-and-rules: their sections as a two-column card grid.
foreach ([[73, 'Fields and Rules · Work Request'], [74, 'Fields and Rules · Move'],
          [111, 'Fields and Rules · Space'], [144, 'Fields and Rules · Incident'],
          [170, 'Fields and Rules · Purchase']] as [$n, $frag]) {
    $nvSolo($n, $frag, 2, false);
}

// ---- live-data devices -----------------------------------------------
$cleanRows = [
    ['Keys', (int)($byCat['Keys and access'] ?? 0)],
    ['Vehicles', (int)($byCat['Vehicles'] ?? 0)],
    ['Safety', (int)($byCat['Safety'] ?? 0)],
    ['Space and estate', (int)(($byCat['Space'] ?? 0) + ($byCat['Estate'] ?? 0))],
    ['Accounts', (int)($byCat['Accounts'] ?? 0)],
    ['Requests and people', (int)(($byCat['Requests'] ?? 0)
        + ($byCat['People and routing'] ?? 0) + ($byCat['Delivery'] ?? 0)
        + ($byCat['Data sources'] ?? 0))],
];
foreach ([[197, 'Cleanup · Keys', 'Keys'],
          [198, 'Cleanup · Vehicles', 'Vehicles'],
          [199, 'Cleanup · Safety', 'Safety'],
          [200, 'Cleanup · Space', 'Space and estate'],
          [201, 'Cleanup · Accounts', 'Accounts']] as [$n, $frag, $ring]) {
    $nvBand($n, $frag, 4.60, function (int &$nid, int $x, int $y, int $w, int $i, int $pt = 16)
            use ($cleanRows, $ring) {
        return nv_hbars($nid, $x, $y, $w, $cleanRows, $ring, PPTX_NAVY, $pt);
    });
}

$wlRows = [];
foreach (completeness_worklists() as $w) {
    $short = preg_replace('/^.*·\s*/', '', $w['label']);
    $wlRows[] = [$short, count($w['rows'])];
}
usort($wlRows, fn($a, $b) => $b[1] <=> $a[1]);
$wlRing = function (string $part) use ($wlRows): string {
    foreach ($wlRows as [$lab, $c]) {
        if (stripos($lab, $part) !== false) {
            return $lab;
        }
    }
    return '';
};
foreach ([[206, 'Worklist · Keys', 'Key'], [207, 'Worklist · The Fleet', 'fleet'],
          [208, 'Worklist · Safety Equipment', 'Safety'],
          [209, 'Worklist · Agreements', 'greement'],
          [210, 'Worklist · Accounts', 'ccount'],
          [211, 'Worklist · Property', 'nventory'],
          [212, 'Worklist · Buildings', 'uilding']] as [$n, $frag, $part]) {
    $nvBand($n, $frag, 4.40, function (int &$nid, int $x, int $y, int $w, int $i, int $pt = 16)
            use ($wlRows, $wlRing, $part) {
        return nv_hbars($nid, $x, $y, $w, $wlRows, $wlRing($part), PPTX_STEEL, $pt);
    });
}

// Data contracts, live from the audit's expectations.
$nvContractRows = function (string $key) use ($gapIx): array {
    $cfg = COMP_EXPECT[$key];
    $rows = [];
    foreach ($cfg['cols'] as $col => $need) {
        $g = $gapIx[$cfg['label'] . '|' . $col] ?? null;
        $rows[] = $g ? [$col, (int)$g['of'] - (int)$g['missing'], (int)$g['of']]
                     : [$col, 1, 1];
    }
    return $rows;
};
foreach ([[44, 'Data Contract · The Staff Directory', 'work:ref.People'],
          [112, 'Data Contract · Keys', 'keys:gis.RoomKeys'],
          [113, 'Data Contract · Rooms on the Floor', 'space:gis.Rooms'],
          [114, 'Data Contract · The Buildings List', 'space:ref.Buildings'],
          [115, 'Data Contract · Leases', 'space:est.Agreements'],
          [116, 'Data Contract · Who Sits Where', 'space:gis.Occupants'],
          [145, 'Data Contract · Safety Equipment', 'space:gis.Safety'],
          [171, 'Data Contract · The Property Inventory', 'equipment:gis.Inventory'],
          [172, 'Data Contract · The Accounts Directory', 'shop:ref.CfopAccounts'],
          [189, 'Data Contract · The Vehicle Fleet', 'fleet:ref.FleetVehicles']]
         as [$n, $frag, $key]) {
    $nvBand($n, $frag, 4.40, function (int &$nid, int $x, int $y, int $w, int $i, int $pt = 16)
            use ($nvContractRows, $key) {
        return nv_coverage($nid, $x, $y, $w, $nvContractRows($key), $pt);
    });
}

// The coverage picture, live. Labels longer than two lines collide
// with their neighbours, so the long ones carry short forms.
$covShort = [
    'Rooms that can hurt somebody, with a safety contact'
        => 'Hazard rooms with a safety contact',
];
$covRows = [];
foreach ($cov as $label => $m) {
    if ($label === '_errors' || !empty($m['failed'])) {
        continue;
    }
    $covRows[] = [$covShort[$label] ?? $label, (int)$m['have'], (int)$m['total']];
}
usort($covRows, fn($a, $b) => ($a[1] / max(1, $a[2])) <=> ($b[1] / max(1, $b[2])));
$nvBand(196, 'Register Coverage', 2.20, function (int &$nid, int $x, int $y, int $w, int $i, int $pt = 16)
        use ($covRows) {
    return nv_coverage($nid, $x, $y, $w, $covRows, $pt);
});

// Headline numbers, live.
$nvBand(194, 'What the Audit Sees', 4.60,
    function (int &$nid, int $x, int $y, int $w, int $i, int $pt = 16) use ($comp, $sev) {
        return nv_bignums($nid, $x, $y, $w, [
            [number_format(count($comp['accounting'])), 'Tables classified'],
            [number_format(count($comp['matrix'])), 'Columns measured'],
            [number_format(array_sum($sev)), 'Findings open'],
            ['0', 'Checks allowed to fail silently'],
        ], $pt);
    });
$nvBand(195, 'Audit Findings', 4.60,
    function (int &$nid, int $x, int $y, int $w, int $i, int $pt = 16) use ($rowsBySev) {
        return nv_hbars($nid, $x, $y, $w, [
            ['Stop something working', (int)$rowsBySev['blocker']],
            ['Make something unreliable', (int)$rowsBySev['important']],
            ['Worth correcting', (int)$rowsBySev['tidy']],
        ], 'Stop something working', PPTX_NAVY, $pt);
    });
$nvBand(13, 'What the Grants File Load Recovered', 4.60,
    function (int &$nid, int $x, int $y, int $w, int $i, int $pt = 16) {
        return nv_bignums($nid, $x, $y, $w, [
            ['1,399', 'Lines in the grants file'],
            ['1,400', 'Accounts parsed from it'],
            ['63', 'Accounts an earlier load missed'],
            ['1,635', 'Purchase documents recovered'],
        ], $pt);
    });

// The permission matrix and the estate calendar.
$nvBand(41, 'Access by Role', 2.60, function (int &$nid, int $x, int $y, int $w, int $i, int $pt = 16) {
    return nv_matrix($nid, $x, $y, $w,
        ['Staff with a NetID', 'Survey director', 'Administrative assistant',
         'OED point person', 'Safety office', 'Facilities office'],
        ['File a request', 'See what they filed', 'See every request',
         'Decide an approval', 'Edit a register', 'Change configuration'],
        [[2, 2, 0, 0, 0, 0], [2, 2, 2, 2, 1, 0], [2, 2, 2, 1, 2, 1],
         [2, 2, 2, 1, 1, 0], [2, 2, 1, 1, 2, 0], [2, 2, 1, 2, 2, 1]], $pt);
});
$nvBand(217, 'Lease End Dates', 4.60, function (int &$nid, int $x, int $y, int $w, int $i, int $pt = 16) {
    return nv_gantt($nid, $x, $y, $w);
});

// ---- before and after: two editable cards beside the text ------------
foreach ([[3, 'Why This Exists'], [4, 'Why One System']] as [$n, $frag]) {
    $i = $vpFind($n, $frag);
    if ($i === null) {
        continue;
    }
    // No fixed scale: the prose takes the size that fills its column,
    // and the card pair is measured so it sits centred beside it.
    $colW = 4.6;
    $x = (int)((12.83 - $colW) * $IN);
    $S[$i]['textw'] = (int)((12.83 - $colW - 0.7) * $IN);
    $nid = 500;
    $baCards = [
        ['head' => 'Before', 'color' => PPTX_ORANGE, 'body' => [
            'Several intake systems, each with its own owner and its own email thread.',
            'No approval chain, and when that person is on leave nobody else can answer a question about the register.']],
        ['head' => 'After', 'color' => '2A9D8F', 'body' => [
            'One door, one record. Ten registers on one audit trail.',
            'Data enters once, at its source, and flows everywhere it is needed.']],
    ];
    $nid = 500;
    [, $baH] = nv_cardrow($nid, $x, 0, (int)($colW * $IN), $baCards, 1, false, 16);
    $baTop = 1.30 + max(0.0, (5.05 - $baH / $IN) / 2);
    $nid = 500;
    [$xml] = nv_cardrow($nid, $x, (int)($baTop * $IN), (int)($colW * $IN),
                        $baCards, 1, false, 16);
    $S[$i]['xtra'] = ($S[$i]['xtra'] ?? '') . $xml;
}

// ---- flowcharts, native ----------------------------------------------
foreach ([['work', 'How a Work Order Moves'], ['move', 'How a Move Runs'],
          ['space', 'Space Request to Room'], ['key', 'How a Key Moves'],
          ['estate', 'How an Agreement Lives'], ['incident', 'How an Incident Runs'],
          ['plan', 'How a Plan Stays Alive'], ['purchase', 'Purchase Becomes'],
          ['mileage', 'Mileage Reading Happens'], ['dataload', 'How Any File'],
          ['launch', 'Path to Launch']] as [$key, $frag]) {
    $i = $vpFind(0, $frag);
    if ($i === null || !isset(FLOWS[$key])) {
        continue;
    }
    [$steps, $lands] = FLOWS[$key];
    $cap = (string)($S[$i]['caption'] ?? '');
    $hasDrop = false;
    foreach ($steps as $st) {
        if ($st[2] !== null) {
            $hasDrop = true;
        }
    }
    $flowH = 1.5 + ($hasDrop ? 1.35 : 0.3) + 0.5;
    // The chart stops high enough that its caption still clears the
    // campus banner underneath with room to spare.
    $fy = 6.12 - $flowH;
    $nid = 500;
    $xml = nv_flow($nid, (int)(0.5 * $IN), (int)($fy * $IN), (int)(12.33 * $IN),
                   $steps, $lands);
    if ($cap !== '') {
        $xml .= pptx_textbox($nid++, (int)(0.5 * $IN), (int)(6.18 * $IN),
            (int)(12.33 * $IN), (int)(0.28 * $IN),
            '<a:p><a:pPr><a:buNone/></a:pPr>' . pptx_run($cap, 1200, PPTX_MUTE) . '</a:p>', 't');
    }
    $S[$i] = ['kind' => 'content', 'title' => $S[$i]['title'],
              'bullets' => $S[$i]['bullets'] ?? [], 'xtra' => $xml,
              'texth' => (int)(($fy - 0.25 - 1.2) * $IN)];
}

// ---- the SIZE dial on every To Build slide ---------------------------
$dialIdx = ['small' => 0, 'medium' => 1, 'large' => 2];
$tbSizes = [];
$tbTitles = [];
foreach (APP_BACKLOG as $tbItems) {
    foreach ($tbItems as [$tbT, $tbW, $tbY, $tbSize, $tbD]) {
        $tbSizes[] = $tbSize;
        $tbTitles[] = $tbT;
    }
}
foreach ($tbSizes as $k => $size) {
    if (stripos($tbTitles[$k], 'Lease end dates') !== false) {
        continue;
    }
    $i = $vpFind(0, 'To Build · ' . substr($tbTitles[$k], 0, 24));
    if ($i === null) {
        continue;
    }
    $nid = 500;
    $S[$i]['xtra'] = ($S[$i]['xtra'] ?? '')
        . nv_dial($nid, (int)(10.5 * $IN), (int)(5.35 * $IN), $dialIdx[$size] ?? 1);
    // The dial stands in the bottom-right corner, so the text stops
    // short of it. Without this the body simply runs across the dial.
    $S[$i]['textw'] = (int)(9.75 * $IN);
}

// ---- the part rail on the discussion slides --------------------------
foreach ([['The Audit Trail', 1], ['Discussion · Part 1', 1],
          ['Discussion · Part 2', 2], ['Discussion · Part 3', 3],
          ['Discussion · Part 4', 4], ['Discussion · Part 5', 5],
          ['Driver Forms', 6], ['Discussion · Part 6', 6], ['Discussion · Part 7', 7]]
         as [$frag, $part]) {
    $i = $vpFind(0, $frag);
    if ($i === null) {
        continue;
    }
    $nid = 500;
    $S[$i]['xtra'] = ($S[$i]['xtra'] ?? '')
        . nv_rail($nid, (int)(0.5 * $IN), (int)(6.0 * $IN), (int)(12.33 * $IN), $part);
}

// ---- images that stay images -----------------------------------------
// The single-row locator and the register icon on the database slides.
foreach ([['Work Database', 'work'], ['Move Database', 'move'], ['Shop Database', 'shop'],
          ['Space Database', 'space'], ['Keys Database', 'keys'],
          ['Incidents Database', 'incidents'], ['Plans Database', 'plans'],
          ['Purchasing Database', 'purchasing'], ['Equipment Database', 'equipment'],
          ['Fleet Database', 'fleet']] as [$frag, $reg]) {
    $i = $vpFind(0, $frag);
    if ($i === null) {
        continue;
    }
    $S[$i]['arth'] = 4170000;
    // The locator strip starts at 5.72in and the text starts at 1.16in,
    // so the box has to stop short of it. It always overlapped by 0.08in;
    // the ink simply never reached that far until the spacing tightened.
    $S[$i]['texth'] = 4060000;
    $S[$i]['figs'][] = [$vimg('fig-locator-' . $reg), (int)(1.60 * $IN),
                        (int)(5.72 * $IN), (int)(9.60 * $IN), (int)(0.86 * $IN)];
    $S[$i]['figs'][] = [$vicon('reg-' . $reg . '-white'), (int)(11.95 * $IN),
                        (int)(0.19 * $IN), (int)(0.68 * $IN), (int)(0.68 * $IN)];
}

// Screened watermark marks the worklist asked for.
foreach ([['The Audit Trail', 'sys-data-blue'],
          ['Driver Forms', 'reg-fleet-blue']] as [$frag, $icon]) {
    $i = $vpFind(0, $frag);
    if ($i !== null) {
        $S[$i]['figs'][] = [$vicon($icon), (int)(10.00 * $IN), (int)(3.60 * $IN),
                            (int)(2.20 * $IN), (int)(2.20 * $IN), 8000];
    }
}

// ---- the standing-rules slide fits its box ---------------------------
$i = $vpFind(0, 'Notification Rules, Live');
if ($i !== null) {
    $S[$i]['colsplit'] = 'even';
}
