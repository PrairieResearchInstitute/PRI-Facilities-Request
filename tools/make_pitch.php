<?php
declare(strict_types=1);

/**
 * The leadership pitch: twenty slides, and the product is the pitch.
 *
 * Twelve of the twenty are real screens on the briefing's proven
 * pattern - explanation beside the screenshot, the pipeline strip
 * beneath, the register icon in the title band. The numbers are
 * computed live at build time. Same chrome and rules as the briefing:
 * brand blue #13294B, campus banner, Calibri, nothing under 12pt.
 *
 *   build:  php tools/make_pitch.php
 */

require_once __DIR__ . '/../facilities/lib/pptx.php';
require_once __DIR__ . '/../facilities/lib/pptxnative.php';
require_once __DIR__ . '/../facilities/lib/dataaudit.php';
require_once __DIR__ . '/../facilities/lib/completeness.php';
require_once __DIR__ . '/../facilities/lib/backlog.php';
require_once __DIR__ . '/../facilities/lib/demofill.php';
require_once __DIR__ . '/../facilities/lib/joinmap.php';

set_time_limit(600);
$SHOTS = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR
       . 'shots' . DIRECTORY_SEPARATOR;
$ICONDIR = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'reference' . DIRECTORY_SEPARATOR . 'Visual Pack' . DIRECTORY_SEPARATOR
         . 'Visual Pack 1' . DIRECTORY_SEPARATOR . 'unpacked' . DIRECTORY_SEPARATOR
         . 'assets' . DIRECTORY_SEPARATOR . 'icons' . DIRECTORY_SEPARATOR;
$IN = 914400;

// ---- the numbers, live ----------------------------------------------
$checks = audit_all();
$sev = ['blocker' => 0, 'important' => 0, 'tidy' => 0];
$rowsBySev = ['blocker' => 0, 'important' => 0, 'tidy' => 0];
$grand = 0;
$byCat = [];
foreach ($checks as $c) {
    if ($c['error'] || (int)$c['count'] === 0) {
        continue;
    }
    $sev[$c['severity']]++;
    $rowsBySev[$c['severity']] += (int)$c['count'];
    $grand += (int)$c['count'];
    $byCat[$c['category']] = ($byCat[$c['category']] ?? 0) + (int)$c['count'];
}
$backlogCount = 0;
foreach (APP_BACKLOG as $items) {
    $backlogCount += count($items);
}
$fmt = fn(int $x) => number_format($x);

// ---- slide builders --------------------------------------------------
$S = [];

$txt = function (string $t, array $b, int $cols = 1) use (&$S) {
    $S[] = ['kind' => 'content', 'title' => $t, 'bullets' => $b, 'cols' => $cols];
};

/** A screen slide on the briefing's pattern: text, shot, pipeline, icon. */
$screen = function (string $shot, string $t, array $b, ?array $pipe, string $icon)
           use (&$S, $SHOTS, $ICONDIR) {
    $est = 0.0;
    foreach ($b as $ln) {
        $line = str_starts_with($ln, 'h:') ? substr($ln, 2) : $ln;
        $est += (str_starts_with($ln, 'h:') ? 1.35 : 1.0)
              * max(1, ceil(mb_strlen($line) / 34));
    }
    if ($est > 18.5) {
        echo 'PITCH AUDIT: ' . $shot . ' text is ~' . round($est)
           . " column lines - reword to fit\n";
    }
    $figs = [];
    if ($icon !== '' && is_file($ICONDIR . $icon . '-white.png')) {
        $figs[] = [$ICONDIR . $icon . '-white.png', 10930320, 170000, 621792, 621792];
    }
    $S[] = ['kind' => 'image', 'title' => $t, 'image' => $SHOTS . $shot . '_1.png',
            'bullets' => $b, 'pipe' => $pipe, 'figs' => $figs];
};

/**
 * A job aid: the form filled with faux data beside the steps that fill
 * it. Shot and steps both come from DEMO_FILLS, so the picture and the
 * words cannot drift apart.
 */
$wexDemo = function (string $key, string $title) use (&$S, $SHOTS, $ICONDIR) {
    $f = DEMO_FILLS[$key];
    $b = [];
    foreach ($f['steps'] as [$head, $lines]) {
        $b[] = 'h:' . $head;
        foreach ($lines as $l) {
            $b[] = $l;
        }
    }
    $figs = [];
    $icon = (string)($f['icon'] ?? '');
    if ($icon !== '' && is_file($ICONDIR . $icon . '-white.png')) {
        $figs[] = [$ICONDIR . $icon . '-white.png', 10930320, 170000, 621792, 621792];
    }
    $S[] = ['kind' => 'image', 'title' => $title, 'image' => $SHOTS . $f['shot'] . '.png',
            'bullets' => $b, 'pipe' => $f['pipe'] ?? null, 'figs' => $figs,
            'jobaid' => true];
};

/**
 * Two ruled columns. Where a slide asks the room for something, a table
 * beats a bullet list: it fills the page and it can be written on.
 */
$table = function (string $title, string $headL, string $headR, array $rows) use (&$S) {
    $nid = 500;
    [$xml] = nv_table2($nid, (int)(0.5 * 914400), (int)(1.30 * 914400),
                       (int)(12.33 * 914400), (int)(4.90 * 914400),
                       $headL, $headR, $rows);
    $S[] = ['kind' => 'content', 'title' => $title, 'bullets' => [], 'xtra' => $xml];
};

$art = function (string $shot, string $t, array $b, string $mode, string $cap = '')
        use (&$S, $SHOTS) {
    $S[] = ['kind' => 'image', 'title' => $t, 'image' => $SHOTS . $shot . '.png',
            'bullets' => $b, 'caption' => $cap,
            'wide' => $mode === 'wide', 'tall' => $mode === 'tall',
            'full' => $mode === 'full'];
};

// =====================================================================
// 1. Title
$S[] = ['kind' => 'title', 'title' => 'PRIOps',
        'subtitle' => 'One system for the operations of the institute · '
                    . 'Briefing for PRI leadership',
        'image' => $SHOTS . 'diagram_logo.png'];

// 2. The problem, with before and after beside it
$txt('Why PRIOps Exists', [
    'h:What today looks like',
    'Requests run through multiple separate, unlinked systems - some with forms, '
        . 'others handled entirely in email, none with a real approval chain - so '
        . 'every one of them trails an email thread.',
    'h:How the registers are kept',
    'Keys, property, vehicles, space, safety and leases are each curated by '
        . 'whichever individual keeps the file: one owner, no history, no way to '
        . 'ask it a question.',
    'h:The standing constraint',
    'No new campus integrations. Every data source is an export staff already '
        . 'pull themselves, loaded in one step.',
]);
$lastIx = count($S) - 1;
// The before/after column fills the height rather than floating at the
// top of it, and the prose beside it is left at its natural size.
$colW = 4.6;
$S[$lastIx]['textw'] = (int)((12.83 - $colW - 0.7) * $IN);
$cards2 = [
    ['head' => 'Before', 'color' => PPTX_ORANGE, 'body' => [
        'Several intake systems, each with its own owner and its own email thread.',
        'No approval chain worth the name.',
        'When that person is on leave, nobody else can answer a question about it.']],
    ['head' => 'After', 'color' => '2A9D8F', 'body' => [
        'One door, one record. Ten registers on one audit trail.',
        'Every decision dated and named, including "on behalf of".',
        'Data enters once, at its source, and flows everywhere it is needed.']],
];
$nid = 500;
[, $h2] = nv_cardrow($nid, 0, 0, (int)($colW * $IN), $cards2, 1, false, 16);
$top2 = 1.30 + max(0.0, (5.05 - $h2 / $IN) / 2);
$nid = 500;
[$xml] = nv_cardrow($nid, (int)((12.83 - $colW) * $IN), (int)($top2 * $IN),
                    (int)($colW * $IN), $cards2, 1, false, 16);
$S[$lastIx]['xtra'] = $xml;

// 3. The front door - the sell starts on the screen
$screen('fe_index', 'The Front Door', [
    'h:One address for everything',
    'Work orders, moves, shop jobs, space, keys, purchases, incidents - one '
        . 'page, on any browser. NetID is the account; nothing to sign up for.',
    'h:The hallway question, retired',
    '"Where do I ask for this?" stops being a question - every card is a request '
        . 'type, filed in about a minute.',
    'h:Worked example',
    'Tess needs a repair: front door, Facilities card, the two-field short form. '
        . 'Filing takes under a minute.',
], [['File', 'Watch', 'Decide', 'Done'], 0, 'the register each type feeds'], 'sys-door');

// 4. Filing, as a job aid
$wexDemo('work_order', 'Filing a Work Request');

// 5. The Board
$screen('fe_board', 'All Requests on One Board', [
    'h:What leadership sees',
    'Every request across every survey, filterable by type, status and survey, '
        . 'with a status chart on top.',
    'h:What staff see',
    'Their own filings, end to end - no phone calls to learn where a request '
        . 'stands.',
    'h:Why it is new',
    'This view has never existed: the first time anyone has seen all of PRI\'s '
        . 'requests in one place.',
], [['File', 'Watch', 'Decide', 'Done'], 1, 'reads all ten databases'], 'sys-board');

// 6. One ticket whole
$screen('fe_detail', 'One Work Order, End to End', [
    'h:What it shows',
    'One work order whole: triage, room, attachments, notes, history - who did '
        . 'what, when.',
    'h:New here',
    'Every action is recorded as it happens - nothing to reconstruct at year end.',
    'h:Worked example',
    'The cooler ticket: Tess files, score 6, assigned Tuesday, seal replaced '
        . 'Thursday, closed with the cost on it.',
], [['File', 'Triage', 'Assign', 'Close'], 1, 'Work · wr.WorkRequests'], 'reg-work');

// 7. Space
$screen('fe_space', 'Space, Requested and Decided', [
    'h:The six-page form, retired',
    'Current space and requested space from the floor-plan pickers, with the '
        . 'justification the committee actually reads.',
    'h:Approval feeds the register',
    'A yes becomes a room assignment directly - decision to record, no gap.',
    'h:Worked example',
    'The 1041 request shows committee "supported", director "approved", and the '
        . 'room assignment it produced - one screen, whole story.',
], [['File', 'Committee', 'Director', 'Room assigned'], 0, 'Space · rq.SpaceRequests'],
   'reg-space');

// 8. The map
$art('diagram_map_plan', 'The Floor Plan, Room by Room', [
    'h:Every room, answerable',
    'The full floor plan, drawn from the geodatabase in the official category '
        . 'colours. Click any room for its people, work orders, inventory, keys '
        . 'and safety record.',
    'h:Why it is new',
    'The geodatabase, readable by anyone with a browser - no GIS software '
        . 'involved.',
], 'tall');

// 9. Keys
$screen('fe_key', 'Key Requests and Issuing', [
    'h:Request to register',
    'Filed on the front end, decided by the director or a proxy, issued against '
        . 'the register the audit reads.',
    'h:The register answers',
    'Who holds what, per room and per person - "Terminated + Active" is one '
        . 'filter, not a hunt.',
    'h:Worked example',
    'A supervisor files for a new tech in ninety seconds; the key shows on the '
        . 'register three days later, no second form.',
], [['Request', 'Issue', 'Return', 'Audit'], 0, 'Keys · gis.RoomKeys'], 'reg-keys');

// 10. Reporting an incident, as a job aid
$wexDemo('incident_slip', 'Reporting an Incident');

// 11. Purchasing
$screen('fe_purview', 'One Purchase, End to End', [
    'h:What it shows',
    'Lines with Banner codes, the CFOP funding split, approval seats and their '
        . 'decisions, the running event log - one page.',
    'h:Linked forward',
    'Equipment lines link to the property that arrives; reconcile appears once '
        . 'the card is charged.',
    'h:Worked example',
    'PUR-FY27-00021: two lines totalling $7,950, one ISAS account, both '
        . 'approvals dated, and reconcile live since the charge posted.',
], [['File', 'Approve', 'Buy', 'Reconcile', 'Tag'], 1, 'Purchasing · rq.PurchaseItems'],
   'reg-purchasing');

// 12. The digest
$screen('fe_digest', 'Digest Emails and Frequency', [
    'h:One email, chosen rhythm',
    'Instant, daily or weekly, per person - listing exactly what moved and what '
        . 'waits on the reader.',
    'h:Why it matters',
    'Nobody is copied unnecessarily, and nobody who chose weekly hears about '
        . 'anything twice.',
    'h:Worked example',
    'Monday 7am: one email lists the four things that moved - two approved, one '
        . 'closed, one waiting on the reader.',
], null, 'sys-digest');

// 13. The back end
$screen('ad_overview', 'The Back End, Grouped', [
    'h:What it does',
    'Seven groups with live counts: Requests, Space, Safety, People and Access, '
        . 'Property, Data, Configuration.',
    'h:Access',
    'By NetID list per survey; a refusal is a 403, never a redirect.',
    'h:New here',
    'What assistants kept in bookmarks and private spreadsheets, behind one '
        . 'door and one access list.',
    'h:Worked example',
    'Monday: the Requests badge says 5, Safety says 2 - the morning\'s workload '
        . 'summarised before a click.',
], [['File', 'Decide', 'Register', 'Report'], 1, 'all ten databases'], 'sys-config');

// 14. Queue and triage
$screen('ad_queue', 'Triage and Approval Routing', [
    'h:Scored, not argued',
    'New work scored by severity and cost (weights in Settings), then advanced, '
        . 'closed or handed to F&S. Ordered by the same arithmetic every time, '
        . 'never the loudest email.',
    'h:Nothing off the record',
    'Every decision records its decider - including "on behalf of", recorded as '
        . 'exactly that.',
    'h:Worked example',
    'Storm response scores 9, jumps the line; a door scores 2, waits.',
], [['File', 'Triage', 'Assign', 'Close'], 1, 'Work · wr.WorkRequests'], 'reg-work');

// 15. The shape of it
$art('diagram_dbs', 'Ten Databases, One Register Each', [], 'wide',
    'One database per register, joined on shared names, never a cross-database key - '
    . 'so any register can be rebuilt or replaced without touching the others.');

$screen('ad_audit', 'The Built-In Data Audit', [
    'h:The audit lives in the app',
    'Every check, its live count, and the screen where the finding is fixed. '
        . 'Blockers first; a zero row is stated, not hidden.',
    'h:The numbers today',
    $fmt($grand) . ' rows to work through; ' . $fmt($rowsBySev['blocker'])
        . ' stop something working. Every row is named in the workbook with '
        . 'exactly the columns it needs.',
    'h:The rhythm',
    'Regenerate before every session; launch when the blockers read zero.',
], [['Workbook', 'Assign', 'Fix', 'Re-run'], 2, 'reads all ten databases'], 'sys-audit');

// 17. Where the cleanup concentrates (live bars under the text)
$cleanRows = [
    ['Keys and access', (int)($byCat['Keys and access'] ?? 0)],
    ['Vehicles', (int)($byCat['Vehicles'] ?? 0)],
    ['Safety', (int)($byCat['Safety'] ?? 0)],
    ['Space and estate', (int)(($byCat['Space'] ?? 0) + ($byCat['Estate'] ?? 0))],
    ['Accounts', (int)($byCat['Accounts'] ?? 0)],
    ['Requests and people', (int)(($byCat['Requests'] ?? 0)
        + ($byCat['People and routing'] ?? 0) + ($byCat['Delivery'] ?? 0)
        + ($byCat['Data sources'] ?? 0))],
];
$nid = 500;
[, $hEmu] = nv_hbars($nid, (int)(0.5 * $IN), 0, (int)(12.33 * $IN), $cleanRows, '', PPTX_NAVY);
$devH = $hEmu / $IN;
$nid = 500;
[$xml] = nv_hbars($nid, (int)(0.5 * $IN), (int)((6.55 - $devH) * $IN),
                  (int)(12.33 * $IN), $cleanRows, '', PPTX_NAVY);
$S[] = ['kind' => 'content', 'title' => 'Where the Cleanup Concentrates',
        'bullets' => [
            'h:Four areas hold most of it',
            'Each has a named clearing move: one reconciled iCard export for the '
                . 'keys, one titles folder for the fleet, five safety-contact '
                . 'names, and 28 dates read off the paper leases.',
            'h:It is data work, not build work',
            'The system is running. These rows are filled through the same '
                . 'screens staff will use every day.',
        ],
        'xtra' => $xml, 'texth' => (int)((6.55 - $devH - 0.3 - 1.2) * $IN)];

// 18. Day one, as a table
$table('What Changes on Day One', 'What stops', 'What starts', [
    ['The separate intake systems stop being answered, after a stated grace period',
     'One address for work, moves, shop jobs, space, keys, purchases and incidents'],
    ['No more asking which office owns a request type',
     'NetID is the account - there is nothing to sign up for'],
    ['No more "where is my request?" by phone or email',
     'The Board answers it, and the digest arrives at each person\'s chosen rhythm'],
    ['No more retyping what the system already knows',
     'Rooms come from the floor plans, accounts from the directory'],
    ['What does not change: an emergency is still a phone call first',
     'The form says so at the top: call it in, deal with it, and file the ticket '
     . 'afterwards'],
]);

// 19. The asks, as a table the room can fill in
$table('What We Need from Leadership', 'What we need',
       'Who owns it, and by when', [
    ['The Outlook relay: host, port, credentials', ''],
    ['The real names of the two required driver forms', ''],
    ['Space committee and safety contacts for ISAS, ISGS, ISTC and ISWS', ''],
    ['The hazard classes rooms should be profiled under', ''],
    ['Whether property certification is annual, and who runs it', ''],
    ['The key register export, reconciled against iCard', ''],
    ['Fleet plates, years and VINs from the titles folder', ''],
    ['Safety contacts for the 499 hazard rooms - one name per survey', ''],
    ['The 28 lease end dates, off the paper leases', ''],
]);

// 20. Discussion
$table('Discussion', 'Questions and Concerns',
       'Feature Wishlist and Ideas to Develop', [
    ['Is one system genuinely safer than the files we keep now?',
     'A read-only public view of a room: who to call, what is in it'],
    ['Who answers when the ten databases disagree?',
     'Saved filters on the Board, and a "my survey" landing page'],
    ['How much of our time does the first year of cleanup actually cost?',
     'Booking for shared instruments, vehicles and meeting space'],
    ['What is the succession plan if the person who built it leaves?',
     'Inspection rounds driven by the QR stickers'],
    ['How long do the old intake routes stay answered?',
     'Annual report figures pulled straight from the registers'],
]);

// =====================================================================
$path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'PRIOps-Pitch.pptx';
$n = pptx_write($path, 'PRIOps Leadership Pitch', $S);
echo 'Wrote ' . $path . ' (' . number_format($n) . " bytes)\n";
echo count($S) . " slides\n";
foreach ($S as $s) {
    if (($s['kind'] ?? '') === 'image' && !is_file($s['image'])) {
        echo '  MISSING SHOT: ' . $s['image'] . "\n";
    }
}
