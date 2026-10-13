<?php
declare(strict_types=1);

/**
 * The briefing: one long deck, grouped so it can be broken wherever the
 * room changes - Overall, Facilities, Property, Safety, Equipment,
 * Vehicles, then Cleanup and Launch.
 *
 * Nothing here is capped. A screen becomes as many slides as its page
 * has bands (tools/process_shots.ps1 cuts each page into readable
 * zoomed bands and writes manifest.txt); its explanation is split
 * across those bands section by section. Processes get flow diagrams,
 * each database gets its own stacked drawing, and every major process
 * gets a worked example with real values from the seeded system.
 *
 *   capture:  Edge headless loops (see session history / re-run blocks)
 *   process:  powershell -File tools\process_shots.ps1
 *   build:    php tools/make_presentation.php
 */

require_once __DIR__ . '/../facilities/lib/pptx.php';
require_once __DIR__ . '/../facilities/lib/dataaudit.php';
require_once __DIR__ . '/../facilities/lib/completeness.php';
require_once __DIR__ . '/../facilities/lib/backlog.php';
require_once __DIR__ . '/../facilities/lib/demofill.php';
require_once __DIR__ . '/../facilities/lib/joinmap.php';
require_once __DIR__ . '/../facilities/lib/pptxnative.php';

set_time_limit(1200);
$SHOTS = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'shots'
       . DIRECTORY_SEPARATOR;

// ---- how many band slides each screen became -------------------------
$BANDS = [];
foreach (file($SHOTS . 'manifest.txt') as $ln) {
    $ln = trim(str_replace("\u{FEFF}", '', $ln));
    if ($ln === '') {
        continue;
    }
    [$k, $n] = preg_split('/\s+/', $ln);
    $BANDS[$k] = (int)$n;
}

// ---- the numbers, live ----------------------------------------------
$checks = audit_all();
$comp = completeness_run();
$cov = audit_coverage();
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
$mustCells = 0;
foreach ($comp['gaps'] as $g) {
    if ($g['need'] === 'Must be filled') {
        $mustCells += $g['missing'];
    }
}
$namedRows = 0;
foreach (completeness_worklists() as $w) {
    $namedRows += count($w['rows']);
}
$backlogCount = 0;
foreach (APP_BACKLOG as $items) {
    $backlogCount += count($items);
}
$fmt = fn(int $x) => number_format($x);

// The register icons. Defined here, above the first closure that binds
// it: a `use ($ICONDIR)` further up the file captures nothing, and every
// job aid then loses its icon without a word about it.
$ICONDIR = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'reference' . DIRECTORY_SEPARATOR . 'Visual Pack' . DIRECTORY_SEPARATOR
         . 'Visual Pack 1' . DIRECTORY_SEPARATOR . 'unpacked' . DIRECTORY_SEPARATOR
         . 'assets' . DIRECTORY_SEPARATOR . 'icons' . DIRECTORY_SEPARATOR;

// ---- slide builders --------------------------------------------------
$S = [];
$title = function (string $t, string $sub) use (&$S, $SHOTS) {
    $S[] = ['kind' => 'title', 'title' => $t, 'subtitle' => $sub,
            'image' => $SHOTS . 'diagram_logo.png'];
};
$sect = function (string $t, string $sub = '', array $agenda = []) use (&$S) {
    $S[] = ['kind' => 'section', 'title' => $t, 'subtitle' => $sub, 'bullets' => $agenda];
};
$txt = function (string $t, array $b, int $cols = 1) use (&$S) {
    $S[] = ['kind' => 'content', 'title' => $t, 'bullets' => $b, 'cols' => $cols];
};
/**
 * A job aid: the form as it is actually filled, the numbered steps that
 * fill it, and the strip saying where the record lands. Steps are
 * grouped by the form's own sections, so slide and screen read in the
 * same order.
 */
$wex = function (string $title, string $shot, array $groups, ?array $pipe = null,
                 string $icon = '') use (&$S, $SHOTS, $ICONDIR) {
    $b = [];
    foreach ($groups as [$head, $lines]) {
        $b[] = 'h:' . $head;
        foreach ($lines as $l) {
            $b[] = $l;
        }
    }
    $figs = [];
    if ($icon !== '' && is_file($ICONDIR . $icon . '-white.png')) {
        $figs[] = [$ICONDIR . $icon . '-white.png', 10930320, 170000, 621792, 621792];
    }
    $S[] = ['kind' => 'image', 'title' => $title, 'image' => $SHOTS . $shot . '.png',
            'bullets' => $b, 'pipe' => $pipe, 'figs' => $figs, 'jobaid' => true];
};

/** The same, for a form whose fill and steps are defined in DEMO_FILLS. */
$wexDemo = function (string $key, string $title) use ($wex) {
    $f = DEMO_FILLS[$key];
    $wex($title, $f['shot'], $f['steps'], $f['pipe'] ?? null, $f['icon'] ?? '');
};

/**
 * A discussion slide: two ruled columns, one for what the room wants to
 * raise and one for what it wants built. Every cell is a real text box,
 * so notes can be typed straight into it during the session.
 */
$disc = function (string $title, array $rows) use (&$S) {
    $nid = 500;
    [$xml] = nv_table2($nid, (int)(0.5 * 914400), (int)(1.30 * 914400),
                       (int)(12.33 * 914400), (int)(4.60 * 914400),
                       'Questions and Concerns',
                       'Feature Wishlist and Ideas to Develop', $rows);
    $S[] = ['kind' => 'content', 'title' => $title, 'bullets' => [], 'xtra' => $xml];
};

/** A drawing: overview and flows full width, databases tall on the right. */
$art = function (string $shot, string $t, array $b, string $mode, string $cap = '')
        use (&$S, $SHOTS) {
    $S[] = ['kind' => 'image', 'title' => $t, 'image' => $SHOTS . $shot . '.png',
            'bullets' => $b, 'caption' => $cap,
            'wide' => $mode === 'wide', 'tall' => $mode === 'tall',
            'full' => $mode === 'full'];
};
/**
 * How many band slides each screen deserves. Judgment, not arithmetic:
 * a form gets a slide per distinct section; a register gets one or two,
 * because band five of the same table explains nothing - the columns
 * are explained in the bullets instead.
 */
$SHOW = [
    'fe_index' => 1, 'fe_work' => 1, 'fe_move' => 2, 'fe_shop' => 2, 'fe_space' => 3,
    'fe_key' => 1, 'fe_purchase' => 2, 'fe_property' => 1, 'fe_incident' => 3,
    'fe_board' => 1, 'fe_detail' => 2, 'fe_request' => 2, 'fe_purview' => 2,
    'fe_reconcile' => 2, 'fe_incidents' => 1, 'fe_incdetail' => 3, 'fe_plans' => 1,
    'fe_plandetail' => 2, 'fe_stages' => 1, 'fe_stickers' => 1, 'fe_prefs' => 1,
    'fe_digest' => 1,
    'ad_overview' => 1, 'ad_queue' => 1, 'ad_approvals' => 1, 'ad_requests2' => 1,
    'ad_stages' => 1, 'ad_transfer' => 1, 'ad_space' => 1,
    'ad_roomassign' => 1, 'ad_safety' => 1, 'ad_estate' => 1, 'ad_incidents' => 1,
    'ad_plans' => 1, 'ad_people' => 1, 'ad_access' => 1, 'ad_orgchart' => 1,
    'ad_keys' => 1, 'ad_equipment' => 1, 'ad_property' => 1, 'ad_expected' => 1,
    'ad_purchases' => 1, 'ad_fleet' => 1, 'ad_mileage' => 1, 'ad_accounts' => 1,
    'ad_audit' => 2, 'ad_datafeeds' => 1, 'ad_tables' => 1, 'ad_import' => 1,
    'ad_refdata' => 1, 'ad_examples' => 1, 'ad_lists' => 1, 'ad_settings' => 1,
];

/**
 * A one-breath worked example for every screen that does not carry a
 * full example slide nearby - appended to its first band automatically,
 * because "here is this screen doing its job once" beats abstraction.
 */
$WEX = [
    'fe_index' => 'Tess needs a repair: front door → Facilities card → the two-field '
        . 'short form. Filing takes under a minute.',
    'fe_board' => 'The cooler ticket shows aging; follow-up happens in place, no '
        . 'email.',
    'fe_stages' => 'A submitter wondering what "In Triage" means finds this chart, sees '
        . 'Assign comes next, and needs no phone call.',
    'fe_prefs' => 'The ISWS assistant switches from instant to daily digest during field '
        . 'season; nothing is missed, and nothing interrupts off hours.',
    'fe_digest' => 'Monday 7am: one email lists the four things that moved - two '
        . 'approved, one closed, one waiting on the reader.',
    'fe_detail' => 'The cooler ticket: Tess files, score 6, assigned Tuesday, '
        . 'seal replaced Thursday, closed with cost.',
    'fe_request' => 'The 1041 request shows committee "supported", director "approved", '
        . 'and the room assignment it produced - one screen, whole story.',
    'fe_purview' => 'PUR-FY27-00021: two lines totalling $7,950, one ISAS '
        . 'account, both approvals dated, reconcile live since the charge posted.',
    'fe_incidents' => 'Tess checks her meltwater report: still open, action due Friday. '
        . 'No inquiry is needed; the record states it.',
    'fe_plandetail' => 'The CHP page shows its one review line - "Revised, two reagents" '
        . '- and next August\'s due date computed from it.',
    'fe_key' => 'A supervisor files for their new tech in ninety seconds; the register '
        . 'shows the key three days later without another form.',
    'fe_reconcile' => 'The three-code charge from the worked example: three blocks, '
        . 'copy, paste, paste, paste - reconciled in minutes.',
    'ad_overview' => 'Monday: the Requests badge says 5, Safety says 2 - the '
        . 'morning\'s workload summarised before a click.',
    'ad_queue' => 'Storm response scores 9, jumps the line; a door scores 2, waits.',
    'ad_approvals' => 'The INHS proxy clears three waiting decisions in five minutes, '
        . 'each with a one-line comment, each recorded as "on behalf".',
    'ad_requests2' => 'An approved key request is worked: one click to the issue '
        . 'screen, key recorded, requester notified. Decision to register, no gap.',
    'ad_stages' => 'The office renames "In Triage" to "Being Reviewed" - one row '
        . 'edit, every screen and email says it the new way.',
    'ad_transfer' => 'A roof job goes to F&S as their WO 8841; months later either '
        . 'number finds it, and the requester never knew it left.',
    'ad_space' => 'Havana field office added off-campus, typed rooms; its lease '
        . 'joins the estate register the same day.',
    'ad_roomassign' => 'The safety officer takes contact for the two wet labs - '
        . 'two rows, and the audit count drops by two.',
    'ad_safety' => 'The annual eyewash test: the tester walks the list, dates each '
        . 'row, and the 108-missing-dates finding shrinks as they go.',
    'ad_estate' => 'The NIFS lease sits red at 1,667 days past - a record to '
        . 'escalate.',
    'ad_incidents' => 'The repeat-room flag on 1073_01_1027 sends the safety office '
        . 'to look at the cooler before the third report, not after.',
    'ad_plans' => 'The gaps panel names two plans with unreachable owners; both get '
        . 'NetIDs the same day, and reminders can finally land.',
    'ad_people' => 'ISGS gets a new proxy: one row, and every ISGS approval and '
        . 'notice routes to the new name at once.',
    'ad_access' => 'A new OED analyst gets back-end access with the equipment '
        . 'function - one row, and the 403s become screens.',
    'ad_orgchart' => 'The export proposes a new ISTC head; the admin confirms with '
        . 'one click, and routing follows the org chart\'s truth.',
    'ad_keys' => 'A retiree\'s three keys: two returned by export reconcile, one '
        . 'truly out - chased, recovered, recorded.',
    'ad_equipment' => 'Certification: one custodian\'s 41 items printed, the lab '
        . 'walked - current because moves kept it so.',
    'ad_property' => 'Friday\'s queue: three filed updates. The office applies two, '
        . 'kicks one back with a question - all three motions on the record.',
    'ad_expected' => 'The freezer arrives; registering it against its purchase line '
        . 'carries cost, account and room across untyped, and the line closes.',
    'ad_purchases' => 'A purchase stuck eight days on one approver: the admin '
        . 'reassigns the seat to the proxy, recorded as such, unstuck in a minute.',
    'ad_accounts' => 'Fiscal year rolls: the assistant pastes the new grants export; '
        . '19 new accounts appear, 12 expired ones close, typeaheads are current.',
    'ad_audit' => 'Monday routine: regenerate, watch blockers fall 850 → 620, '
        . 'screenshot the trend for the directors.',
    'ad_datafeeds' => 'The employee feed sits amber at 41 days; one paste and it '
        . 'is green, two new hires in the typeaheads.',
    'ad_tables' => 'An auditor asks for the key register: Download and Browse, '
        . 'gis.RoomKeys, CSV, sent - four clicks, no developer.',
    'ad_import' => 'Five years of old work orders: mapped, staged, reviewed, '
        . 'committed - and reversible right up until that last click.',
    'ad_refdata' => 'After adding the Havana building: publish pushes it to all ten '
        . 'databases and verify reports zero drift. One button.',
    'ad_examples' => 'Launch week: the sweep clears the seeded tickets; this page '
        . 'reads empty; the blocker disappears from the audit.',
    'fe_stickers' => 'The shop prints all 31 ISWS sheets in one run and laminates '
        . 'them onto dashboards during Friday fueling.',
    'ad_mileage' => 'Month end: 60 readings in, 3 flagged - two typos corrected, one '
        . 'genuine 4,000-mile field month confirmed with a note.',
];

/**
 * A screen: one slide per band, the explanation split across the bands
 * one h:-section at a time, the pipeline strip on every band so nobody
 * loses their place.
 */
$SCREEN_ICON = [
    'fe_index' => 'sys-door', 'fe_board' => 'sys-board', 'fe_stages' => 'sys-flow',
    'fe_prefs' => 'sys-bell', 'fe_digest' => 'sys-digest',
    'fe_work' => 'reg-work', 'fe_detail' => 'reg-work',
    'fe_move' => 'reg-move', 'fe_shop' => 'reg-shop',
    'fe_space' => 'reg-space', 'fe_request' => 'reg-space',
    'fe_key' => 'reg-keys', 'fe_purchase' => 'reg-purchasing',
    'fe_purview' => 'reg-purchasing', 'fe_reconcile' => 'reg-purchasing',
    'fe_property' => 'reg-equipment', 'fe_incident' => 'reg-incidents',
    'fe_incidents' => 'reg-incidents', 'fe_incdetail' => 'reg-incidents',
    'fe_plans' => 'reg-plans', 'fe_plandetail' => 'reg-plans',
    'fe_stickers' => 'reg-fleet',
    'ad_overview' => 'sys-config', 'ad_queue' => 'reg-work',
    'ad_approvals' => 'sys-check', 'ad_requests2' => 'reg-space',
    'ad_stages' => 'sys-flow', 'ad_transfer' => 'reg-work',
    'ad_space' => 'reg-space', 'ad_roomassign' => 'reg-space',
    'ad_safety' => 'reg-incidents', 'ad_estate' => 'sys-lease',
    'ad_incidents' => 'reg-incidents', 'ad_plans' => 'reg-plans',
    'ad_people' => 'sys-people', 'ad_access' => 'sys-lock',
    'ad_orgchart' => 'sys-people', 'ad_keys' => 'reg-keys',
    'ad_equipment' => 'reg-equipment', 'ad_property' => 'reg-equipment',
    'ad_expected' => 'reg-equipment', 'ad_purchases' => 'reg-purchasing',
    'ad_fleet' => 'reg-fleet', 'ad_mileage' => 'reg-fleet',
    'ad_accounts' => 'sys-data', 'ad_audit' => 'sys-audit',
    'ad_datafeeds' => 'sys-upload', 'ad_tables' => 'sys-data',
    'ad_import' => 'sys-upload', 'ad_refdata' => 'sys-data',
    'ad_examples' => 'sys-config', 'ad_lists' => 'sys-config',
    'ad_settings' => 'sys-config',
];
$screen = function (string $shot, string $t, array $b, ?array $pipe = null, string $cap = '')
           use (&$S, $SHOTS, $WEX, $SCREEN_ICON, $ICONDIR) {
    if (isset($WEX[$shot])) {
        $b[] = 'h:Worked example';
        $b[] = $WEX[$shot];
    }
    // One slide per screen: text beside the screenshot, the pipeline
    // strip beneath, the register icon in the title band. The build
    // flags any screen whose text cannot fit the column at 12pt.
    $est = 0.0;
    foreach ($b as $ln) {
        $line = str_starts_with($ln, 'h:') ? substr($ln, 2) : $ln;
        $est += (str_starts_with($ln, 'h:') ? 1.35 : 1.0)
              * max(1, ceil(mb_strlen($line) / 34));
    }
    if ($est > 18.5) {
        echo 'SCREEN AUDIT: ' . $shot . ' text is ~' . round($est)
           . " column lines - reword to fit\n";
    }
    $figs = [];
    $icon = $SCREEN_ICON[$shot] ?? '';
    if ($icon !== '' && is_file($ICONDIR . $icon . '-white.png')) {
        $figs[] = [$ICONDIR . $icon . '-white.png', 10930320, 170000, 621792, 621792];
    }
    $S[] = ['kind' => 'image', 'title' => $t,
            'image' => $SHOTS . $shot . '_1.png',
            'bullets' => $b, 'pipe' => $pipe, 'caption' => $cap, 'figs' => $figs];
};

// ---- computed explainers ---------------------------------------------
require_once __DIR__ . '/../facilities/lib/picklists.php';

$gapIx = [];
foreach ($comp['gaps'] as $g) {
    $gapIx[$g['register'] . '|' . $g['column']] = $g;
}
/**
 * The data contract for one graded register, straight from the audit's
 * expectations: which columns must be filled, which are wanted, and
 * exactly how short each stands today.
 */
$contract = function (string $key, string $title) use (&$S, $gapIx, $fmt) {
    $cfg = COMP_EXPECT[$key];
    $label = $cfg['label'];
    $b = ['h:Must be filled on every row'];
    foreach ($cfg['cols'] as $col => $need) {
        if ($need !== 'must') {
            continue;
        }
        $g = $gapIx[$label . '|' . $col] ?? null;
        $b[] = $col . ($g ? '  —  short ' . $fmt($g['missing']) . ' of ' . $fmt($g['of'])
                          : '  —  filled');
    }
    $b[] = 'h:Wanted, counted, not blocking';
    foreach ($cfg['cols'] as $col => $need) {
        if ($need !== 'should') {
            continue;
        }
        $g = $gapIx[$label . '|' . $col] ?? null;
        $b[] = $col . ($g ? '  —  short ' . $fmt($g['missing']) . ' of ' . $fmt($g['of'])
                          : '  —  filled');
    }
    $b[] = 'h:Where this comes from';
    $b[] = 'The audit\'s written expectations for this register - the same grading the '
         . 'workbook\'s Cells To Fill tab works from, computed the day this deck was built.';
    $S[] = ['kind' => 'content', 'title' => 'Data Contract · ' . $title, 'bullets' => $b];
};

/** The words a set of pick lists offer, straight from the database. */
$vocab = function (string $title, array $lists) use (&$S) {
    $b = [];
    foreach ($lists as $lkey => $ltitle) {
        $b[] = 'h:' . $ltitle;
        $words = [];
        try {
            foreach (picklist($lkey) as $label) {
                $words[] = $label;
            }
        } catch (Throwable $e) {
        }
        $b[] = $words ? implode('  ·  ', $words) : '(list not seeded)';
    }
    $b[] = 'h:All of it editable';
    $b[] = 'These are rows in Lists the Forms Offer, not code. The office changes its own '
         . 'vocabulary.';
    $S[] = ['kind' => 'content', 'title' => $title, 'bullets' => $b];
};

$worklists = completeness_worklists();
/**
 * The first rows of a register's real worklist, on a slide - because an
 * assignment lands differently when the room can read actual rows.
 */
$sampleRows = function (string $labelPart, string $title) use (&$S, $worklists, $fmt) {
    foreach ($worklists as $w) {
        if (stripos($w['label'], $labelPart) === false) {
            continue;
        }
        $b = ['h:' . $fmt(count($w['rows'])) . ' rows in this worklist. The first five, '
                . 'verbatim:'];
        foreach (array_slice($w['rows'], 0, 5) as $r) {
            $ident = array_slice(array_values($r), 0, count($r) - 2);
            $b[] = implode('  ·  ', array_filter($ident)) . '   →   needs: '
                 . $r['Must fill'];
        }
        $b[] = 'h:Where the rest live';
        $b[] = 'The workbook\'s Rows To Fill tab - regenerate it and fixed rows are '
             . 'simply gone. Fixed at: ' . $w['where'] . '.';
        $S[] = ['kind' => 'content', 'title' => 'Worklist · ' . $title,
                'bullets' => $b];
        return;
    }
};

/** Every backlog item gets its own slide - the build list, in full. */
$buildSlides = function () use (&$S) {
    foreach (APP_BACKLOG as $section => $items) {
        foreach ($items as [$title, $what, $why, $size, $depends]) {
            $S[] = ['kind' => 'content', 'title' => 'To Build · ' . $title, 'bullets' => [
                'h:What it is',
                $what,
                'h:Why it is worth building',
                $why,
                'h:Size',
                (APP_BACKLOG_SIZE[$size] ?? $size) . '.',
                'h:Waiting on',
                $depends,
                'h:Area',
                $section,
            ]];
        }
    }
};

// #####################################################################
$title('PRIOps',
    'The Prairie Research Institute\'s operating record - born the Facilities Request '
    . 'System, grown into requests, registers, safety, leases, fleet and purchasing. '
    . 'Grouped to be taken one room at a time.  ·  ' . date('j F Y'));

// ################################################### PART 1 - OVERALL
$sect('Part 1. Overall', 'For every audience - repeat before any group session', [
    'h:In this part',
    'Why the separate, unlinked request systems retire, and what replaces them.',
    'The ten-database shape, the overview drawing, and how the geodatabase works.',
    'The screens everybody shares: the front door, the Board, digests, and the '
        . 'whole Data group.',
    'h:You will leave able to',
    'File anything, find anything you filed, and explain why no key ever crosses '
        . 'a database.',
]);

$txt('Why This Exists', [
    'h:What today looks like',
    'Requests run through multiple separate, unlinked systems, spread across '
        . 'offices and never consolidated - some with forms, others handled '
        . 'entirely in email, and none with a real approval chain, so every one '
        . 'of them trails an email thread.',
    'Every register that matters - keys, property, vehicles, space, safety, leases - '
        . 'is curated separately by whichever individual keeps it: one owner, no '
        . 'history, and no way to ask it a question.',
    'When that person is on leave, nobody else can answer a question about their '
        . 'register, or change anything in it.',
    'h:What this system is',
    'One door in, one record per request, one audit trail, and every register '
        . 'consolidated in one place - shared, searchable and exportable.',
    'Nobody retypes anything the system already knows - the move form knows the room\'s '
        . 'contents, the arrival carries its purchase, and the reconcile block is filled '
        . 'in from the purchase record.',
    'h:The standing constraint',
    'No new campus integrations. Every data source is an export staff already pull '
        . 'themselves, loaded in one step.',
]);

$txt('Why One System, Not Several', [
    'h:One system, not several',
    'Consolidation ends the spread: every request type and every register in one '
        . 'place, under one access rule, on one audit trail - instead of curated '
        . 'separately by whoever happens to keep each file.',
    'h:Connected data is more powerful and more robust',
    'Joined on shared names, the registers answer questions no single system '
        . 'could - one click on a room shows its work orders, inventory, keys and '
        . 'safety record together.',
    'Each register corroborates the others: the inventory checks the rooms, the '
        . 'keys check the directory, the purchases check the accounts. Errors that '
        . 'would sit unnoticed in a standalone file surface as audit findings here.',
    'h:What it does to manual work',
    'Data enters once, at its source, and flows everywhere it is needed - manual '
        . 'input reduces to a few insertion points plus QA/QC review.',
    'The move form carries the inventory, the arrival carries its purchase, the '
        . 'exports carry the people and accounts: staff time shifts from retyping '
        . 'to reviewing.',
]);

$txt('The Name: PRIOps', [
    'h:What it was scoped as',
    'One place to file facilities requests, replacing several unlinked intake '
        . 'systems and the email threads that held them together.',
    'h:What it grew into',
    'The institute\'s operating record: requests and work, the property inventory, '
        . 'the key register, the estate and its leases, safety incidents and plans, '
        . 'the fleet, purchasing and its reconciliation - ten registers, one door.',
    'h:So it is named for what it is',
    'PRIOps - PRI Operations. The name is a system setting, carried on every '
        . 'page footer, every export, and this deck.',
    'h:And for where it lives',
    'Pyrops is a genus of lanternflies - a deliberate nod to the institute\'s own '
        . 'science in the name of its operations system.',
]);

$txt('Why Ten Databases, Not One', [
    'h:One register, one database',
    'Each database owns one register - the thing an office is responsible for - and the '
        . 'requests that change it. A key request lives beside the key register; a '
        . 'purchase beside the accounts that fund it and the Banner codes that '
        . 'classify what was bought.',
    'h:No key ever crosses a boundary',
    'When a record points at something in another register it carries the thing\'s '
        . 'real-world name: a room code like 1073_01_1027, a NetID, a fleet number, a '
        . 'property tag, a PO number.',
    'h:What that one rule buys',
    'Any register can be handed to a new owner whole; a failure in one cannot corrupt '
        . 'another; every join is readable by a person, because the keys mean something.',
    'h:Shared reference, published',
    'Buildings, rooms, people and pick lists are mastered once and copied into all ten, '
        . 'so every form fills its dropdowns locally and no page waits on a second '
        . 'database being up.',
    'h:And the stack',
    'PHP and SQL Server, no frameworks, no build step, every asset local - because it '
        . 'deploys to PRI shared hosting where nothing can be installed.',
]);

$art('diagram_dbs', 'Ten Databases and What They Hold', [], 'wide');

// The join map, read from the catalogue at build time so it cannot claim
// a join that has gone or miss one that was added. It is drawn first as
// wheels - the rule as a picture - and then tabulated in full.
$joinRows = [];
$joinColors = [];
foreach (join_map() as $name => $e) {
    $joinRows[] = [$name, $e['owner'], join_carrier_names($name)];
    $joinColors[] = JOIN_COLORS[$name] ?? PPTX_INK;
}
$nid = 500;
[$joinXml] = nv_tablen($nid, (int)(0.5 * 914400), (int)(1.28 * 914400),
    (int)(12.33 * 914400), (int)(4.55 * 914400),
    ['The shared name', 'The register that owns it', 'Every database that carries it'],
    [2.1, 3.3, 6.9], $joinRows, $joinColors);
$joinXml .= pptx_textbox($nid++, (int)(0.5 * 914400), (int)(5.95 * 914400),
    (int)(12.33 * 914400), (int)(0.5 * 914400),
    pptx_para('Hub and spoke, never a mesh: a register carrying a name joins to '
        . 'the register that owns it. Keys and Incidents both carry RoomCode, so '
        . 'each joins the room register - not each other.', 1400, PPTX_MUTE),
    't');
$S[] = ['kind' => 'content', 'title' => 'How the Ten Databases Join',
        'bullets' => [], 'xtra' => $joinXml];

$txt('The Geodatabase: How Space Is Held', [
    'h:Where it comes from',
    'The ISGS geodatabase is the authority on buildings, floors and rooms. It exports; '
        . 'this system loads. Nobody here edits geometry.',
    'h:What the load carries',
    'gis.Rooms with each room\'s polygon as a drawing path; gis.Floors with walls and '
        . 'doors; gis.BuildingFootprints; and the room code 1073_01_1027 - building, '
        . 'floor, room - that every other register joins on.',
    'h:How it renders',
    'Floor plans draw straight to SVG in the browser. No GIS software, no image '
        . 'libraries, nothing to install to see a floor.',
    'h:What PRI adds on top',
    'Who is answerable, safety contacts, occupants - in separate tables keyed by room '
        . 'code, so a plan reload never overwrites a decision.',
]);

$txt('New Plans, New Buildings, the Wizard', [
    'h:New floor plans arrive',
    'ISGS re-exports, Data → Where Data Comes From reloads. Rows upsert by room code: '
        . 'geometry refreshes, assignments survive untouched.',
    'h:A building on campus',
    'Arrives through the GIS and C3PO exports like everything else.',
    'h:A building off campus',
    'No export will ever carry it - a field station or a new lease is added by hand in '
        . 'Buildings and Rooms today, with free-text rooms where no plan exists.',
    'h:The Building Wizard - on the build list',
    'One guided chain: what it is → who owns it → the lease or MOU if PRI does not own '
        . 'it → plans if drawn, typed rooms if not. Nothing forgotten at the door.',
]);

$art('diagram_flow_dataload', 'How Any File Becomes A Register', [
    'h:The path',
    'A self-service export is loaded, staged and reviewed, then committed; '
        . 'publish pushes reference data everywhere; verify reports drift.',
    'h:What to notice',
    'Reversible until the last click, and a column nobody mapped is a named '
        . 'finding.',
], 'wide',
    'The same five steps for every source: C3PO, GIS, employees, Banner codes, grants.');

$wex('Worked Example · The Grants File', 'ad_accounts_1',
[
    ['1. Open the directory', [
            'Property → Accounts Directory, in the back end',
            'The file: activeGrants.csv, 1,399 lines, no header row',
    ]],
    ['2. Paste and commit', [
            'Paste the export straight in; the parser reads all 1,400 accounts',
            'Commit — the directory gained the 63 an earlier load had missed',
    ]],
    ['3. Prove it landed', [
            'tools/source_audit.php compares every column against the databases',
            'The same check recovered 1,635 purchase document numbers that week',
    ]],
    ['The rule it enforces', [
            'A column nobody mapped is a named finding, never a silent zero',
    ]],
],
    [['Pull export', 'Paste', 'Commit', 'Typeaheads current'], 2, 'Shop · ref.CfopAccounts'], 'sys-data');

// What that one load actually recovered. These figures belong beside the
// job aid, but a job aid is already a full page - form, steps and strip -
// so they get the slide after it rather than being laid over the steps.
$txt('What the Grants File Load Recovered', [
    'h:Read from the file itself',
    'The export is 1,399 lines with no header row; the parser reads 1,400 '
        . 'accounts out of it, because one line carries two.',
    'h:What the directory had been missing',
    'Sixty-three accounts an earlier load had dropped without saying so - '
        . 'the reason a column nobody mapped is now a named finding rather '
        . 'than a silent zero.',
    'h:And what the same check found next',
    '1,635 purchase document numbers recovered in the same week, by comparing '
        . 'every column against the databases instead of trusting the loader.',
]);

$screen('fe_index', 'The Front Door', [
    'h:What it does',
    'Every kind of request as a card, grouped, closed by default, one line each.',
    'h:New here',
    'Replaces knowing which system or inbox handles which request - and cards marked "in preparation" '
        . 'are honest about what is not built yet.',
    'h:Linked',
    'Each card opens a form that writes to its own database.',
], [['Choose', 'File', 'Decide', 'Register'], 0, 'all ten databases']);

$screen('fe_board', 'The Board', [
    'h:What it does',
    'All requests, filtered by type, status, survey; status chart on top.',
    'h:Who sees what',
    'Staff see their filings; directors, assistants and OED see everything in '
        . 'one place.',
    'h:The columns',
    'Ticket, type, summary, location, filer, last moved - click through.',
    'h:Linked',
    'Merges all request databases on ticket number; CSV, natural keys.',
], [['File', 'Watch', 'Decide', 'Done'], 1, 'reads all ten databases']);

$screen('fe_stages', 'Request Status and History', [
    'h:What it does',
    'The stages each request type walks, drawn exactly as the submitter sees them.',
    'h:Why it matters',
    'Nobody should need a phone call to learn what "In Triage" means or what comes '
        . 'after it.',
    'h:Linked',
    'Names come from the editable status flow - the words are the office\'s own.',
], [['File', 'Route', 'Decide', 'Done'], 1, 'ref.StatusFlow']);

$screen('fe_prefs', 'Preferences', [
    'h:What it does',
    'Per-person notification and digest frequency: instant, daily, weekly, none.',
    'h:New here',
    'The reader chooses the volume; the system stops guessing.',
], [['Event', 'Rules', 'Digest', 'Inbox'], 2, 'Work · app.DigestPreferences']);

$screen('fe_digest', 'The Digest', [
    'h:What it does',
    'Everything that moved since the last one, grouped the way the reader asked.',
    'h:Waiting on one thing',
    'Written to the outbox today; sends the moment the Outlook relay details arrive - '
        . 'the outstanding dependency with the largest immediate return.',
], [['Event', 'Rules', 'Digest', 'Inbox'], 2, 'app.Outbox → your inbox']);

$txt('The Notification Catalogue', [
    'h:Five kinds of message, one delivery system',
    'Your request moved - every stage change tells the person who filed it.',
    'You must decide - approvals land on the decider the moment they open, and on '
        . 'the proxy when a seat is reassigned.',
    'Something needs you - an incident files and the safety office knows before '
        . 'the reporter closes the tab; a corrective action lands on its owner '
        . 'with its due date.',
    'A date is approaching - plans coming due, leases in their renewal window, '
        . 'actions going overdue: the registers watch their own calendars.',
    'The data is going stale - a feed past its refresh interval ambers on the '
        . 'screen and counts as an audit finding.',
    'h:One standing caveat',
    'Every message is written and queued today; delivery starts the day campus IT '
        . 'supplies the relay details. The system is ready ahead of the connection.',
]);

$rules = [];
try {
    foreach (db('work')->query(
        "SELECT EventCode, MatchType, DisplayName, Email, ISNULL(Note, '') AS Note
           FROM app.NotifyRules WHERE IsActive = 1 ORDER BY EventCode") as $r) {
        $rules[] = $r;
    }
} catch (Throwable $e) {
}
$ruleLines = ['h:The standing rules, read from app.NotifyRules today'];
foreach ($rules as $r) {
    $who = (string)($r['DisplayName'] ?: $r['Email']);
    $note = (string)$r['Note'];
    if (mb_strlen($note) > 52) {
        $note = mb_substr($note, 0, 49) . '...';
    }
    $ruleLines[] = $r['EventCode'] . '  →  ' . $who
        . ($r['MatchType'] !== 'always' ? '  (' . $r['MatchType'] . ')' : '')
        . ($note !== '' ? '  —  ' . $note : '');
}
$ruleLines[] = 'h:Beyond the standing rules';
$ruleLines[] = 'Submitters are always told about their own requests, deciders about '
    . 'their own queues, and survey routing sends each survey\'s events to its own '
    . 'director and assistant - conditional, from the survey record, never hard-coded.';
$ruleLines[] = 'h:Who edits this';
$ruleLines[] = 'The office does, on People, Roles and Notifications. A new rule is a '
    . 'row: event, condition, recipient.';
$txt('Notification Rules, Live', $ruleLines, 2);

$txt('Scheduled Reminders', [
    'h:Already watching',
    'Safety plans: flagged ' . '60 days before review is due, computed from each '
        . 'plan\'s own kind - the overdue CHP in Part 4 is this engine working.',
    'Leases and agreements: flagged 180 days before their end date - wide enough '
        . 'that a renewal is a decision, not an emergency.',
    'Corrective actions: overdue the day after their due date, on the report and '
        . 'on the incidents screen.',
    'Data feeds: each source ambers past its own refresh interval.',
    'h:Watching as soon as the columns land',
    'Warranty ends, calibration due, expected replacement - the equipment columns '
        . 'in Part 7\'s build list exist precisely to give this engine more dates.',
    'h:The design rule',
    'A date the system holds is a date the system raises reminders for. Nothing '
        . 'relies on individual memory.',
]);

$wex('Worked Example · One Event\'s Fan-Out', 'fe_digest_1',
[
    ['Tuesday 09:04 — filed', [
            'Tess files the meltwater incident from her phone',
    ]],
    ['09:04 — instantly', [
            'The safety office\'s notice is written: ticket, kind, where, her words',
            'Tess\'s confirmation is written, with her ticket number and a link',
    ]],
    ['That evening — the digests', [
            'The INHS director reads one line: a new injury report in Forbes, open',
            'They chose daily, and the system respected it',
    ]],
    ['Thursday — the reminder engine', [
            'The seal action hits its due date still open; its owner is reminded',
    ]],
    ['What never happened', [
            'Nothing forwarded by hand; nobody copied twice; no weekly reader disturbed',
    ]],
],
    null, 'sys-digest');

$txt('Notifications Not Yet Built', [
    'h:Switched on by one answer',
    'The Outlook relay: host, port, credentials. The day it arrives, every queued '
        . 'message sends and every rule below starts delivering.',
    'h:Arriving with their systems',
    'Inspection rounds: a route falls due, the inspector is told.',
    'Driver forms: clearance expiring, the driver and the fleet lead are told '
        . 'before the keys stop.',
    'Bookings: reminders before pickup, follow-ups past return.',
    'Certification: each custodian told when their round opens, reminded as it '
        . 'closes.',
    'h:The pattern',
    'Every future register arrives with its dates, and every date joins the '
        . 'engine. Alerts are not a feature bolted on - they are what the '
        . 'registers are for.',
]);

$screen('ad_overview', 'The Back End, Grouped', [
    'h:What it does',
    'Seven groups with live counts: Requests, Space, Safety, People and Access, '
        . 'Property, Data, Configuration.',
    'h:Access',
    'By NetID list per survey; a refusal is a 403, never a redirect.',
    'h:New here',
    'What assistants kept in bookmarks and private spreadsheets, behind one door '
        . 'and one access list.',
], [['File', 'Decide', 'Register', 'Report'], 1, 'all ten databases']);

$screen('ad_people', 'People, Roles and Notifications', [
    'h:What it does',
    'Directors and administrative assistants per survey; notification rules with '
        . 'conditional routing; digest membership.',
    'h:Why one screen',
    'Who acts and who is told are the same question; one screen.',
    'h:What the sections are',
    'Survey leadership; notification rules - event, condition, recipients; digest '
        . 'membership and rhythm.',
], [['Confirm', 'Route', 'Notify'], 1, 'Work · ref.Surveys, app.NotifyRules']);

$screen('ad_access', 'Back End Access', [
    'h:What it does',
    'Who enters the back end, per survey, with per-person functions: safety, '
        . 'fleet, equipment, executive.',
    'h:What the columns mean',
    'One row per person: NetID, survey or all, functions held. Incident '
        . 'visibility reads this list.',
    'h:Linked',
    'Every 403 in the system is this table saying no.',
], [['Grant', 'Check', '403'], 0, 'Work · app.AdminAccess']);

$screen('ad_orgchart', 'Leadership from the Org Chart', [
    'h:What it does',
    'What the org-chart export says each unit\'s leadership is, proposed for a person '
        . 'to confirm - never silently applied.',
    'h:Why proposal, not import',
    'The org chart is right about structure and stale about people often enough that '
        . 'a human click is worth the ten seconds.',
], [['Import', 'Propose', 'Confirm'], 1, 'Work · ref.Surveys']);

$screen('ad_lists', 'Lists the Forms Offer', [
    'h:What it does',
    'Every pick list on every form, editable: natures of work, conditions, incident '
        . 'kinds, severities, agreement kinds, plan review outcomes.',
    'h:New here',
    'The office owns its vocabulary. A new category is a row, not a request to a '
        . 'developer.',
    'h:What each list shows',
    'The code the database stores, the label the form offers, the order, and whether '
        . 'it is active. Protected rows are the ones code depends on.',
], [['Edit words', 'Forms offer'], 0, 'Work · ref.PickLists']);

$screen('ad_settings', 'Settings', [
    'h:What it does',
    'Every constant the system scores or routes by: ticket prefixes, triage weights, '
        . 'warning windows, the mail relay.',
    'h:New here',
    'Changing behaviour is editing a row. Nothing an office might tune is hard-coded.',
    'h:Worth noticing',
    'The relay fields are here - the ones waiting on campus IT. The day they are '
        . 'filled in, every queued notification sends.',
], [['Edit row', 'Behaviour changes'], 0, 'Work · app.Settings']);

$txt('The Data Refresh Schedule', [
    'h:Monthly',
    'The employee export - hires appear in typeaheads, terminations start the '
        . 'clock on their keys.',
    'h:Each semester',
    'The C3PO assigned-space report - occupants and their confirmation dates.',
    'h:Each fiscal year, and on award',
    'The grants export - accounts open and close; the directory follows the '
        . 'money.',
    'h:When the source moves',
    'Banner codes when campus reissues the workbook; floor plans when ISGS '
        . 're-exports; the property file before each certification.',
    'h:Who is reminded',
    'The datafeeds screen ambers past each interval, and the audit counts stale '
        . 'feeds as findings - freshness is watched, not remembered.',
]);

$screen('ad_datafeeds', 'Where Data Comes From', [
    'h:What it does',
    'Every source is a self-service export: C3PO, GIS, employees, Banner codes, '
        . 'grants. Loaded here - staged, then committed.',
    'h:What each card shows',
    'Source, what it feeds, last load, staleness against its interval.',
    'h:The discipline behind it',
    'tools/source_audit.php proves every column landed - the check that caught '
        . '8,000 dropped values.',
], [['Pull export', 'Load', 'Commit', 'Publish'], 1, 'the register each file feeds']);

$screen('ad_tables', 'Download and Browse', [
    'h:What it does',
    'Every table in every database, browsable, and CSV out - one at a time or all '
        . '259 at once.',
    'h:Why it exists',
    'Nothing in this system is locked in. Every register can be exported in one '
        . 'step - a deliberate commitment, made so adoption never becomes dependence.',
], [['Browse', 'Filter', 'CSV'], 2, 'any of the 259 tables']);

$screen('ad_import', 'Import Past Data', [
    'h:What it does',
    'Old spreadsheets brought in with a mapping step, staged for review, committed '
        . 'explicitly, reversible until then.',
], [['Map', 'Stage', 'Commit'], 1, 'app.ImportBatches → the target register']);

$screen('ad_refdata', 'Publish and Check', [
    'h:What it does',
    'Pushes shared reference - buildings, rooms, people, pick lists - from its master '
        . 'database into the other nine, and reports drift instead of hiding it.',
    'h:Why it matters',
    'This is the machinery that lets ten databases feel like one system.',
], [['Master', 'Publish', 'Verify'], 1, 'ref.* in all ten databases']);

$screen('ad_examples', 'Example Data', [
    'h:What it does',
    'The seeded demonstration records, listed so they can be swept before launch.',
    'h:The audit watches it',
    'Example data still in place is a blocker finding until this page reads empty.',
], [['Seeded', 'Review', 'Sweep'], 2, 'a blocker until empty']);

$wex('Worked Example · A First Week', 'ad_datafeeds_1',
[
    ['Day one — the export', [
            'The employee export refreshes; paste it on Where Data Comes From',
            'The directory gains their NetID and email',
    ]],
    ['Day two — a key', [
            'Their supervisor files a key request; the typeahead already knows them',
            'Director approves, key office issues, the register holds their name',
    ]],
    ['Day three — first request', [
            'They file a work request; gate, room pickers and notices treat them '
                . 'exactly like a twenty-year veteran',
    ]],
    ['The point', [
            'Onboarding is appearing in one export — NetID is the account',
    ]],
],
    [['Export', 'Load', 'Commit', 'Available'], 2, 'the register each file feeds'], 'sys-upload');

$wex('Worked Example · A Stale Export', 'ad_datafeeds_1',
[
    ['1. Read the card', [
            'Employee file → 41 days old against a 30-day interval',
            'Amber, named and dated, on a screen rather than in an audit',
    ]],
    ['2. Why it matters this week', [
            'Two hires are invisible to typeaheads; one termination still shows active',
            'That is exactly how 842 stale keys accumulated over the years',
    ]],
    ['3. Fix it', [
            'Pull the export from the campus system and paste it — minutes of work',
            'The audit line for exports past their interval returns to zero',
    ]],
    ['The principle', [
            'Staleness is information on a screen, not a surprise in an audit',
    ]],
],
    [['Watch', 'Pull export', 'Paste', 'Green'], 0, 'the register each file feeds'], 'sys-upload');

$txt('Day One: What Changes For Staff', [
    'h:The morning PRIOps goes live',
    'The front door replaces the separate intake systems. Work orders, moves, '
        . 'shop jobs, purchases, keys, space, incidents - all file at one address, '
        . 'on any browser, no account to create because NetID is the account.',
    'h:What stops',
    'The old intake routes - the other systems, the inboxes - stop being answered '
        . 'after a grace period, stated up front with the link, so adoption is '
        . 'complete rather than split.',
    'h:What does not change',
    'An emergency is still a phone call first - the form says so at the top of the '
        . 'page. Call it in, deal with it, and file the ticket afterwards.',
    'h:What staff get back',
    'The Board answers "where is my request" without a phone call; the digest '
        . 'arrives at the rhythm each person chooses.',
]);

$txt('Access by Role', [
    'h:Front end - anyone with a NetID',
    'File anything; see and track what you filed. Nothing of anyone else\'s.',
    'h:Back end - the named list',
    'Directors, administrative assistants and the OED point people, granted by '
        . 'NetID per survey in app.AdminAccess. They see everything operational.',
    'h:The one tighter circle',
    'Incident reports name people and injuries, so they are readable by the '
        . 'reporter and the named list only - and closeable only by the safety '
        . 'function.',
    'h:The enforcement',
    'Every rule is a table lookup, every refusal is a 403, and there are no '
        . 'silent redirects - being told no is itself information.',
]);

$txt('The Audit Trail', [
    'h:Every action has a who, a when, and a what',
    'Stage changes write status history; register changes write events; decisions '
        . 'record their decider - including "on behalf of", recorded as exactly '
        . 'that.',
    'h:Why this matters',
    'The year-end questions - who approved this, when did that key move, what did '
        . 'we certify in 2026 - no longer require reconstruction. The answer is on the row.',
    'h:And nothing deletes',
    'Plans withdraw, keys mark lost, items remove - all with dates and reasons. A '
        . 'register that deletes cannot answer "what was true last year", and that '
        . 'is a question that gets asked after incidents.',
]);

$txt('Deployment and Continuity', [
    'h:Deployment',
    'PHP and SQL Server, no frameworks, no build step, every asset local - '
        . 'because PRI shared hosting offers no shell and no installs. Copy the '
        . 'folder, run the web installer, done.',
    'h:Backups',
    'Ten databases on the institute\'s SQL Server, covered by its backup cycle - '
        . 'and independently, every table exports to CSV with natural keys, so the '
        . 'worst day still ends with the data in hand.',
    'h:No lock-in, stated as policy',
    'If PRIOps were retired tomorrow, Download and Browse hands back every '
        . 'register in open formats. That is deliberate: systems that make leaving '
        . 'easy are the ones worth staying in.',
]);

$contract('work:ref.People', 'The Staff Directory');

$txt('Part 1: Common Questions', [
    'h:"Who can see what I file?"',
    'You, the people who must decide it, and the back-end list. Incidents are tighter: '
        . 'reporter plus the named list, nobody else.',
    'h:"What if the data in a dropdown is wrong?"',
    'The dropdown is a register. Fix the register - Lists, Buildings and Rooms, the '
        . 'directory - and every form is right from then on.',
    'h:"What happens when ISGS re-exports the floor plans?"',
    'Geometry refreshes; assignments, contacts and history survive. Reload is routine, '
        . 'not surgery.',
    'h:"Can we get our data out?"',
    'Any table, any time, CSV, natural keys. That is a design promise, not a feature.',
]);

$txt('PRIOps Vocabulary', [
    'h:Register',
    'The authoritative list an office is responsible for - keys out, items held, '
        . 'rooms, agreements. Registers replace personal spreadsheets.',
    'h:Ticket',
    'One request\'s record, named like WO-FY27-00042: type, fiscal year, number. The '
        . 'name never changes, whatever happens to the request.',
    'h:Natural key',
    'A real-world name used to point across databases: RoomCode, NetID, FleetNumber, '
        . 'PropertyTag, PoNumber, CfopCode. Readable by people, meaningful forever.',
    'h:Pipeline',
    'The strip at the foot of screen slides: the stages a request walks, this '
        . 'screen\'s stage highlighted, and the table where the data lands.',
], 2);

$disc('Discussion · Part 1',
[
    ['Is one system genuinely safer than the files we keep now?',
     'A read-only public view of a room: who to call, what is in it'],
    ['What happens when a survey wants a field the form does not have?',
     'Saved filters on the Board, per person'],
    ['Who answers when the ten databases disagree?',
     'A "my survey" landing page rather than one shared front door'],
    ['How much of our time does the first year of cleanup actually cost?',
     'Digest content chosen per person, not just its rhythm'],
    ['What is the succession plan if the person who built it leaves?',
     'Print-friendly versions of every register for meetings'],
]);

// ################################################## PART 2 - FACILITIES
$sect('Part 2. Facilities', 'Work orders, moves, and shop jobs', [
    'h:In this part',
    'The Work, Move and Shop databases, table by table.',
    'The work-order flow with the duplicate gate; the move flow that carries the '
        . 'inventory with it; charged shop jobs that route themselves.',
    'Worked examples: the basement leak, the gate saying no, storm response, a '
        . 'fabrication job, a lab move.',
    'h:You will leave able to',
    'File all three request types and run the queue, approvals and F&S handoffs.',
]);

$art('diagram_db_work', 'The Work Database', [
    'h:Purpose',
    'Work orders end to end - and, for now, the shared reference structure every database copies.',
    'h:Its tables',
    'wr.WorkRequests — every work order: what, where, score, status, who.',
    'wr.Approvals — the decisions on chargeable work.',
    'wr.DedupDecisions — every time the duplicate gate asked, and the answer.',
    'ref.People — the staff directory everything looks people up in.',
    'ref.OrgUnits — the org chart, from the export.',
    'ref.Natures — the kinds of work the form offers.',
    'h:Links',
    'RoomCode to Space; CfopCode to the accounts; NetID everywhere.',
    'h:On the horizon',
    'The directory moves out next, leaving Work purely operational.',
], 'tall', 'diagram.php?db=work');

$art('diagram_db_move', 'The Move Database', [
    'h:Purpose',
    'Moves between rooms, item by item, with a review at the end.',
    'h:Its tables',
    'mv.MoveRequests — the move: from, to, when, status.',
    'mv.MoveItems — each item - tagged rows link to the inventory.',
    'mv.Reviews — how it went, recorded after.',
    'h:Links',
    'PropertyTag to the inventory; RoomCode at both ends.',
    'h:The benefit',
    'Completing a move updates item locations - the inventory follows the furniture.',
], 'tall', 'diagram.php?db=move');

$art('diagram_db_shop', 'The Shop Database', [
    'h:Purpose',
    'Fabrication, repair and vehicle maintenance jobs, and the account directory behind every charged form.',
    'h:Its tables',
    'sh.ShopRequests — the jobs, each with its charge account.',
    'sh.Approvals — the funding decisions.',
    'ref.CfopAccounts — PI, fund manager and end date per CFOP.',
    'h:Links',
    'FleetNumber to Fleet; CfopCode out to Purchasing and Work.',
    'h:Kept fresh by',
    'Pasting the grants export - the 63 recovered accounts came in that door.',
], 'tall', 'diagram.php?db=shop');

$art('diagram_flow_work', 'How A Work Order Moves', [
    'h:The path',
    'File, the duplicate gate, triage, assign - or hand to F&S with the '
        . 'reference kept - then close, the submitter told at every stage change.',
    'h:What to notice',
    'Severity and cost come from Settings, so the queue orders itself the same '
        . 'way every time.',
], 'wide',
    'The duplicate gate is the step nobody else has: similar open tickets challenge '
    . 'the filing before it lands.');

$wexDemo('work_order', 'Worked Example · A Work Order');

$wexDemo('work_gate', 'Worked Example · A Wrong Gate Match');

$screen('fe_work', 'Work Request', [
    'h:What it does',
    'Two-step intake: building, work type and one line first - then the full form '
        . 'only if it is genuinely new.',
    'h:New here',
    'The duplicate gate compares against open tickets and challenges a match before '
        . 'filing. The emergency path - call first - is stated up front instead of '
        . 'learned by mistake.',
    'h:Data it needs',
    'Rooms from the estate; natures and costs feed the triage score; the account '
        . 'code, when the job is charged, is checked against the directory.',
], [['File', 'Triage', 'Assign', 'Close'], 0, 'Work · wr.WorkRequests']);

$screen('fe_detail', 'One Work Order, End to End', [
    'h:What it does',
    'One work order whole: triage, room, attachments, notes, history.',
    'h:New here',
    'Every action is recorded as it happens, so there is nothing to reconstruct '
        . 'at year end.',
    'h:What the sections are',
    'Request, triage panel, room on the map, dated and named history.',
    'h:Linked',
    'Account, status and room link to the directory, chart and map.',
], [['File', 'Triage', 'Assign', 'Close'], 1, 'Work · wr.WorkRequests']);

$screen('ad_queue', 'Queue and Triage', [
    'h:What it does',
    'New work scored by severity and cost (weights in Settings), then advanced, '
        . 'closed or handed to F&S.',
    'h:What the columns mean',
    'Ticket, summary, location, score, age, actions; score gates charges.',
    'h:New here',
    'Ordered by the same arithmetic every time, never the loudest email.',
    'h:Linked',
    'A handed-off job keeps its F&S reference for both sides.',
], [['File', 'Triage', 'Assign', 'Close'], 1, 'Work · wr.WorkRequests']);

$screen('ad_approvals', 'Approvals', [
    'h:What it does',
    'Everything waiting on a decision, oldest first, decidable on the spot with a '
        . 'comment.',
    'h:New here',
    'Deciding on somebody\'s behalf is allowed and recorded as exactly that - '
        . 'standard practice for assistants, now on the record rather than informal.',
], [['File', 'Decide', 'Apply', 'Notify'], 1, 'rq.Approvals, beside each request']);

$screen('ad_transfer', 'Transfers To F&S', [
    'h:What it does',
    'Work handed to the campus system, keeping their reference against our ticket.',
    'h:Why',
    'Both sides can find the same job by either number, and the requester still '
        . 'watches it here.',
], [['Triage', 'Hand to F&S', 'Track', 'Close'], 1, 'Work · wr.WorkRequests']);

$screen('ad_stages', 'Stages', [
    'h:What it does',
    'The status flow per request type, editable: names, order, and who is told at '
        . 'each step.',
    'h:New here',
    'Renaming a stage or adding one is editing a row - the office owns its own '
        . 'process vocabulary.',
], [['Define', 'Route', 'Notify'], 0, 'Work · ref.StatusFlow']);

$art('diagram_flow_move', 'How A Move Runs', [
    'h:The path',
    'Filed with rooms from the plans; the item rows arrive prefilled from the '
        . 'inventory; approval moves the property records with the furniture.',
    'h:What to notice',
    'Nobody retypes an inventory - the form already knows what is in the room.',
], 'wide',
    'The inventory follows the furniture: completing the move updates item locations.');

$wexDemo('move', 'Worked Example · A Move');

$screen('fe_move', 'Move Request', [
    'h:What it does',
    'From-room and to-room first, then item rows prefilled from the property '
        . 'inventory of the origin room.',
    'h:New here',
    'The move card knows what is in the room. Free rows cover the untagged.',
    'h:Linked',
    'Reads gis.Inventory by room code; a completed move writes locations back.',
], [['File', 'Items', 'Schedule', 'Complete'], 0, 'Move · mv.MoveItems']);

$screen('fe_shop', 'Shop and Vehicle Work', [
    'h:What it does',
    'Fabrication, repair and vehicle maintenance; vehicles picked by fleet number; '
        . 'the CFOP account picked with its approvers attached.',
    'h:New here',
    'A charged job routes itself - the account knows its PI and fund manager.',
    'h:Data it needs',
    'The fleet register and the accounts directory.',
    'h:Cleanup tied to it',
    '295 vehicles still carry a property tag as their number; until fixed, jobs '
        . 'may miss the number on the door.',
], [['File', 'Account check', 'Shop floor', 'Close'], 0, 'Shop · sh.ShopRequests']);

$wexDemo('work_storm', 'Worked Example · Storm Response');

$wexDemo('shop_fab', 'Worked Example · A Fabrication Job');

$txt('How Triage Scoring Works', [
    'h:Two questions, one score',
    'How bad is it (severity, from the nature of work) and what will it cost '
        . '(the estimate band). Each maps to points; the product orders the queue.',
    'h:Worked through',
    'Storm response carries maximum severity - it scores 9 and moves to the head '
        . 'of the queue the moment it files. A sticking office door scores 2 and '
        . 'is worked in order.',
    'h:Why a formula and not judgment',
    'The same ticket scores the same on a Tuesday as on a Friday, whoever is '
        . 'triaging - and when the office disagrees with the ordering, the fix is '
        . 'editing the weights in Settings, not arguing per ticket.',
    'h:The threshold that matters',
    'Past the cost line, a job needs a funding approval before work starts - '
        . 'the score decides who must say yes.',
]);

$txt('The Duplicate Check', [
    'h:The comparison',
    'Same building, open status, and overlapping words in the one-line summary - '
        . 'checked before the full form ever opens.',
    'h:What a match does',
    'Shows you the existing ticket and asks one question: same problem? Yes adds '
        . 'your report as a corroboration - the ticket gains supporting evidence '
        . 'and the queue stays clean. No files yours fresh, and both tickets record that the '
        . 'gate asked.',
    'h:What it never does',
    'Block a filing. The gate challenges; the person decides. A wrongly merged '
        . 'report can be split later precisely because the gate\'s question and '
        . 'answer are on the record.',
]);

$txt('Fields And Rules · Work Request', [
    'h:Required',
    'Building; work type; one-line description. That is the whole first screen.',
    'h:The full form adds',
    'Nature of work from the pick list; floor and room from the cascading pickers; the '
        . 'longer description; attachments; cost estimate and CFOP when chargeable.',
    'h:Rules that fire',
    'The duplicate gate before filing; account codes validated against the directory; '
        . 'severity and cost scored from Settings on arrival; every stage change '
        . 'notifies the submitter.',
    'h:What you cannot get wrong',
    'Rooms come from the register, so a ticket can never name a room the map cannot '
        . 'find.',
], 2);

$txt('Fields And Rules · Move And Shop', [
    'h:Move - required',
    'From-building and room; to-building and room. The item rows appear after, '
        . 'prefilled from the origin room\'s inventory.',
    'h:Move - rules',
    'Ticked items update their location on completion; free-text rows cover the '
        . 'untagged; the destination room must exist on the register.',
    'h:Shop - required',
    'What the job is; the vehicle by fleet number when it is vehicle work; the CFOP '
        . 'account, because shop work is charged.',
    'h:Shop - rules',
    'The account brings its PI and fund manager with it - the approval routes itself '
        . 'from the account record, not from a guess.',
], 2);

$txt('Part 2: Common Questions', [
    'h:"What if it is an emergency?"',
    'Call first - the form says so at the top of the page. File the ticket once the '
        . 'emergency has been dealt with.',
    'h:"The gate matched me to the wrong ticket."',
    'Say no and file. Both tickets record that the gate asked - merging later keeps '
        . 'both histories.',
    'h:"Who decides what is urgent?"',
    'The triage score - severity times cost, both weights sitting in Settings where '
        . 'the office can tune them.',
    'h:"Where did my ticket go?"',
    'The Board, or the Stages page for what the words mean. Nobody should need to '
        . 'email to ask.',
]);

$disc('Discussion · Part 2',
[
    ['Does the duplicate gate slow down urgent filings?',
     'Bulk triage: score and assign several tickets in one pass'],
    ['Should the triage weights differ by survey?',
     'A crew view: what is assigned to me today, on a phone'],
    ['Who owns work orders that cross two surveys?',
     'Estimate ranges rather than a single number'],
    ['When should a job go to campus F&S rather than our shop?',
     'Recurring work: quarterly filters, annual inspections'],
    ['How do we handle after-hours emergencies inside this?',
     'Parts and materials drawn from a stores register'],
]);

// ################################################## PART 3 - PROPERTY
$sect('Part 3. Property', 'The estate, and who may enter it', [
    'h:In this part',
    'The Space and Keys databases; the map clicked room by room - responsibility, '
        . 'work, inventory, keys, safety.',
    'Space requests to room assignments; key requests to the register; the lease '
        . 'register that caught five expired agreements.',
    'Worked examples: the 1041 request, a denial that holds up, a lost master, '
        . 'the field station, the NIFS lease.',
    'h:You will leave able to',
    'Read any room\'s whole story in two clicks, and keep the registers that '
        . 'make it true.',
]);

$art('diagram_db_space', 'The Space Database', [
    'h:Purpose',
    'The estate: the geodatabase load plus everything PRI decides on top of it. It masters RoomCode - five databases join back here.',
    'h:Its tables',
    'gis.Rooms — 2,157 rooms with drawing paths - the RoomCode master.',
    'ref.Buildings — the buildings list with ownership and condition.',
    'est.Agreements — the leases, MOUs and sponsor arrangements.',
    'gis.Safety — every eyewash, hood, extinguisher and AED.',
    'gis.Occupants — who sits where, from the C3PO export.',
    'rq.SpaceRequests — the asks that become assignments.',
    'h:Links',
    'Everything, by RoomCode.',
    'h:The protecting rule',
    'Plan reloads refresh geometry; decisions keyed by RoomCode survive untouched.',
], 'tall', 'diagram.php?db=space');

$art('diagram_db_keys', 'The Keys Database', [
    'h:Purpose',
    'The key register, its history, and the requests that change it.',
    'h:Its tables',
    'gis.RoomKeys — the register: key, holder, room, state, dates.',
    'gis.KeyEvents — issues, returns, losses - the key\'s biography.',
    'rq.KeyRequests — the front-end asks.',
    'rq.Approvals — the director\'s decision, beside the request.',
    'h:Links',
    'RoomCode to Space; NetID to the directory.',
    'h:Cleanup',
    '842 active keys sit with Terminated holders - Part 7 divides that work.',
], 'tall', 'diagram.php?db=keys');

$art('diagram_flow_space', 'Space Request to Room Assignment', [
    'h:The path',
    'Filed on the front end; the committee weighs in; the director decides; a '
        . 'yes writes the room assignment register directly.',
    'h:What to notice',
    'Decision to record with no gap - the register the reports read is the one '
        . 'the approval wrote.',
], 'wide',
    'The decision feeds the register directly - no retyping between yes and moved-in.');

$wexDemo('space', 'Worked Example · A Space Request');

$wex('Worked Example · A Sound Denial', 'fe_request_1',
[
    ['The request', [
            'A unit asks for a second cold room; the committee passes it up',
    ]],
    ['The decision, recorded', [
            'The director denies it and types the reason:',
            '"Shared cold storage in 1041 has capacity — revisit if the Fisheries grant lands"',
            'Dated, named, on the request, visible to the requester',
    ]],
    ['Six months later', [
            'The grant lands; the new request cites the old one',
            'The director sees their own words and the changed condition',
    ]],
    ['Why it matters', [
            'No decision is revisited from memory, and no request dies unanswered',
    ]],
],
    [['File', 'Committee', 'Director', 'Decided'], 3, 'Space · rq.SpaceRequests'], 'reg-space');

$screen('fe_space', 'Space Request', [
    'h:What it does',
    'Space held now and space asked for, both from the floor-plan pickers, with '
        . 'justification and potential costs.',
    'h:New here',
    'Replaces the six-page INHS form; approval feeds room assignment directly.',
    'h:What the sections are',
    'Requester and survey; current space; requested space; the justification the '
        . 'committee reads.',
    'h:Data it needs',
    'Rooms from the estate; committee and director from the survey record.',
], [['File', 'Committee', 'Director', 'Room assigned'], 0, 'Space · rq.SpaceRequests']);

$screen('fe_request', 'Space Request Decisions', [
    'h:What it does',
    'The request beside its approval chain: each stage, who held it, what they said.',
    'h:New here',
    'A denial carries its reason on the record. No decisions by silence.',
], [['File', 'Committee', 'Director', 'Room assigned'], 2, 'Space · rq.Approvals']);

$art('diagram_map_plan', 'The Building Floor Plan', [
    'h:What it does',
    'The full floor plan, drawn from the geodatabase in the official category '
        . 'colours - every room, uncut. Click any room for everything known '
        . 'about it, in tabs.',
    'h:How to read it',
    'Colour is the space category; a dashed outline marks an open work order; '
        . 'the dot marks safety equipment in the room.',
    'h:The header numbers',
    'Rooms, mapped area, people assigned, tagged property and its value, open '
        . 'work orders and estimated spend - the building summarised before '
        . 'anything is clicked.',
    'h:New here',
    'The geodatabase, readable by anyone with a browser - no GIS software '
        . 'involved.',
], 'tall', 'Forbes Natural History Building, floor 1 - live from gis.Rooms.');

$txt('The Room Detail Tabs', [
    'h:One click, five registers',
    'Selecting a room opens a card with a tab per register, each answering '
        . 'from its own database, all joined on the room code.',
    'h:Responsible',
    'Survey, principal investigator, safety contact, assigned occupants - the '
        . 'answer to "who is accountable for this room".',
    'h:Work orders',
    'Every open and recent ticket naming the room - a room\'s repair history '
        . 'answers "has this happened before" immediately.',
    'h:Inventory',
    'Every tagged item with model, serial, holder and value, kept current by '
        . 'moves and property updates.',
    'h:Keys',
    'Every key that opens the door and who holds each one - the first question '
        . 'after any incident, and before any rekey.',
    'h:Safety',
    'The safety equipment in the room with status and test dates - where an '
        . 'inspection round starts.',
    'h:Deep-linkable',
    'Each view is a URL. Sending someone the room sends them the map with the '
        . 'room already selected and the tab already open.',
], 2);

$art('diagram_map_resp', 'Room 1027. Responsible', [], 'full');
$art('diagram_map_work', 'Room 1027. Work Orders', [], 'full');
$art('diagram_map_inv', 'Room 1027 · 42 Items, $143,585', [], 'full');
$art('diagram_map_keys', 'Room 1027. Keys', [], 'full');
$art('diagram_map_safety', 'Room 1031 · Safety Equipment', [], 'full');

$screen('ad_space', 'Buildings and Rooms', [
    'h:What it does',
    'Add, edit, retire; move rooms between buildings; aliases for every name a '
        . 'building goes by.',
    'h:The guard rails',
    'A room holding property refuses removal until the inventory settles; '
        . 'unmapped buildings take typed rooms, so field stations work.',
    'h:Linked',
    'Publishes to ref.Buildings and ref.Rooms in all ten databases.',
], [['Add', 'Plans or not', 'Publish', 'Retire'], 0, 'Space · ref.Buildings, gis.Rooms']);

$screen('ad_roomassign', 'Room Assignment', [
    'h:What it does',
    'Per room: survey, principal investigator, safety contact, occupants.',
    'h:Why it matters',
    'Space reporting, approval routing and safety lookup stand on this '
        . 'register - "who do I call".',
    'h:Cleanup tied to it',
    '235 assignable rooms lack an answerable party; 499 hazard rooms lack a '
        . 'safety contact - divided by survey in Part 7.',
], [['Assign', 'Publish', 'Route'], 0, 'Space · gis.RoomResponsibility']);

$art('diagram_flow_key', 'How A Key Moves', [
    'h:The path',
    'Requested, approved by the director or a proxy, issued, returned or marked '
        . 'lost - every state lands on the register the audit reads.',
    'h:What to notice',
    '"Terminated + Active" becomes a filter, not a hunt through a drawer.',
], 'wide',
    'One decision, then the register carries the key for the rest of its life.');

$wexDemo('key', 'Worked Example · A Key');

$screen('fe_key', 'Key Request', [
    'h:What it does',
    'A person by typeahead, a room from the register, a reason, one decision.',
    'h:New here',
    'The request and the key register are the same system - issue happens against '
        . 'the row the audit reads.',
], [['File', 'Decide', 'Issue', 'Register'], 0, 'Keys · gis.RoomKeys']);

$screen('ad_requests2', 'Space and Key Request Queues', [
    'h:What it does',
    'The two request types that end in a register change, worked as one list.',
    'h:Linked',
    'An approved space request feeds room assignment; an approved key request feeds '
        . 'the issue screen. No retyping between decision and register.',
], [['File', 'Decide', 'Apply', 'Register'], 2, 'the Space and Keys databases']);

$screen('ad_keys', 'Key Assignment', [
    'h:What it does',
    'Issue, return, mark lost, rekey - on the register the audit reads.',
    'h:What the columns mean',
    'Holder and iCard status, key, room, state, dates; "Terminated + Active" is '
        . 'the pair to watch.',
    'h:Cleanup tied to it',
    '842 active keys with Terminated holders. One reconciled export clears most; '
        . 'the rest are worked from the workbook.',
], [['Request', 'Issue', 'Return', 'Audit'], 1, 'Keys · gis.RoomKeys']);

$art('diagram_flow_estate', 'How An Agreement Lives', [
    'h:The path',
    'Recorded with terms and dates; watched as the end date nears; renewed or '
        . 'escalated with the paper trail attached.',
    'h:What to notice',
    'An expired lease shows red on its own - five surfaced the day the register '
        . 'was built.',
], 'wide',
    'Recorded once, watched automatically - the opposite of five leases expiring unseen.');

$wex('Worked Example · A Lease', 'ad_estate_1',
[
    ['What the register caught on day one', [
            'ISAS NIFS, lease BOT CN-22287-01, ended 14 January 2022',
            'Occupied 1,667 days past its end date, invisible in a spreadsheet cell',
    ]],
    ['What a row holds', [
            'Building, kind, counterparty, reference, cost basis',
            'The contract/ICR split, and real dates rather than text',
    ]],
    ['How it warns', [
            'Expired shows red at the top; renewals flag 180 days out',
    ]],
    ['What it still needs', [
            '28 of the 33 agreements have no end date — they exist only on paper',
            'One person, one folder, one afternoon',
    ]],
],
    [['Record', 'Watch dates', 'Renew'], 1, 'Space · est.Agreements'], 'sys-lease');

$wex('Worked Example · A Field Station', 'ad_space_1',
[
    ['1. Add the building', [
            'Buildings and Rooms → add; a new leased field office in Havana, IL',
            'Mark it off campus; owner → Private',
    ]],
    ['2. Give it rooms', [
            'Typed as free text, because no floor plan will ever exist',
    ]],
    ['3. Record the agreement', [
            'Separately, on Leases and Agreements: dates and cost split',
    ]],
    ['The gap that leaves', [
            'Two screens, two habits — and the audit already shows the result: '
                . 'buildings occupied with nothing on file, agreements with no dates',
    ]],
    ['The Building Wizard, on the build list', [
            'One guided chain that cannot finish with the lease unrecorded',
    ]],
],
    [['Add building', 'Rooms', 'Publish', 'Lease recorded'], 0, 'Space · ref.Buildings'], 'reg-space');

$screen('ad_estate', 'Leases and Agreements', [
    'h:What it does',
    'What PRI occupies but does not own: 33 agreements - leases, ground leases, '
        . 'sponsor arrangements, MOUs.',
    'h:New here',
    'Rebuilt from a GIS workbook; five expired leases surfaced day one.',
    'h:What the columns mean',
    'Building, kind, reference, owner, terms, cost basis, contract/ICR split, '
        . 'end date - red once past.',
    'h:Cleanup tied to it',
    '28 end dates off the paper leases.',
], [['Record', 'Watch dates', 'Renew'], 1, 'Space · est.Agreements']);

$wex('Worked Example · Who Sits Where', 'ad_roomassign_1',
[
    ['Twice a year', [
            'Pull C3PO\'s assigned-space report and load it',
            'Occupants upsert by room code; confirmation dates come across',
    ]],
    ['What the map gains', [
            'Click any room: who sits there, confirmed when',
            '1,062 of the current 1,599 assignments carry confirmations today',
    ]],
    ['What falls out for free', [
            'The space survey stops being an annual scramble',
            'The register is always the draft; only the changes need chasing',
    ]],
],
    [['Pull report', 'Load', 'Upsert', 'Map current'], 1, 'Space · gis.Occupants'], 'reg-space');

$wex('Worked Example · A Lost Master Key', 'ad_keys_1',
[
    ['1. Mark it on the register', [
            'Key Assignment → the key → Mark lost',
            'Dated, with a note saying what happened',
    ]],
    ['2. Raise the rekey', [
            'File the rekey work order from the same screen',
    ]],
    ['What the register answers instantly', [
            'Which rooms that key opened',
            'Who else holds keys to them, and which of those are still employed',
    ]],
    ['The decision it supports', [
            'Rekey the wing or accept the risk — decided on facts, recorded on the rooms',
            'On paper that answer took an afternoon and was frequently wrong',
    ]],
],
    [['Mark lost', 'Assess', 'Rekey', 'Recorded'], 0, 'Keys · gis.RoomKeys'], 'reg-keys');

$txt('Fields And Rules · Space and Keys', [
    'h:Space - required',
    'The survey; current space if any; the ask - a named room or a described need; '
        . 'the justification.',
    'h:Space - rules',
    'Committee before director, both dated and named; approval feeds room '
        . 'assignment directly.',
    'h:Key - required',
    'The person (directory typeahead); the room (register); the reason.',
    'h:Key - rules',
    'Director or proxy decides once; issue happens in the back end against the '
        . 'register; every later state change - return, lost, rekey - is an event on '
        . 'the same row.',
], 2);

$contract('keys:gis.RoomKeys', 'Keys Currently Out');
$contract('space:gis.Rooms', 'Rooms On The Floor Plans');
$contract('space:ref.Buildings', 'The Buildings List');
$contract('space:est.Agreements', 'Leases and Agreements');
$contract('space:gis.Occupants', 'Who Sits Where');

$vocab('Space and Lease Vocabulary', [
    'agreement_kind' => 'Kinds of agreement',
    'building_ownership' => 'Who can own a building',
    'building_type' => 'What a building is for',
]);

$txt('Part 3: Common Questions', [
    'h:"Our field station is not on the map."',
    'It never will be - no plan exists. It is on the register with typed rooms, and '
        . 'every form still works. The Building Wizard will make adding one a guided '
        . 'chain.',
    'h:"Who approved this person\'s key?"',
    'The row knows: request, decision, decider, date. Ten years of paper key cards '
        . 'never could.',
    'h:"When does our lease end?"',
    'The register answers in one look - and 28 agreements are waiting on exactly '
        . 'that date being read off the paper. That is Part 7 work.',
    'h:"Can two surveys share a room?"',
    'Responsibility names one answerable survey; occupants can be anyone. Shared use '
        . 'is normal, shared accountability is not.',
]);

$disc('Discussion · Part 3',
[
    ['Who sits on the space committee for ISAS, ISGS, ISTC and ISWS?',
     'Room booking for shared instruments and meeting space'],
    ['Should space requests expire if undecided?',
     'A floor-plan heat map: occupancy, hazards, open work'],
    ['Who may see the key register, and at what level of detail?',
     'Key audit letters generated per holder'],
    ['How often should occupants be reconfirmed?',
     'Door cards printed straight from the room record'],
    ['What is our rule for keys held by affiliates and students?',
     'Lease renewal reminders routed to the fiscal office'],
]);

// ##################################################### PART 4 - SAFETY
$sect('Part 4. Safety', 'Incidents and plans', [
    'h:In this part',
    'The Incidents and Plans databases; reporting in four questions; grading, '
        . 'actions and closure rules; the plan register that computes its own '
        . 'due dates.',
    'Worked examples: the meltwater slip and its repeat-room flag, the spill '
        . 'with photographs, the overdue CHP, a withdrawn plan, failed equipment.',
    'h:You will leave able to',
    'Report anything in under two minutes, and run a report from grade to '
        . 'closed-with-findings.',
]);

$art('diagram_db_incidents', 'The Incidents Database', [
    'h:Purpose',
    'Injuries, near misses, spills and exposures - reported in a minute, worked to closure, visible only to the named list.',
    'h:Its tables',
    'rq.Incidents — the report: kind, when, where, narrative, status.',
    'rq.IncidentPeople — who was there, injuries, treatment.',
    'rq.IncidentActions — corrective actions with owners and due dates.',
    'rq.IncidentEvents — the timeline nobody reconstructs.',
    'h:Links',
    'RoomCode, FleetNumber and NetID - places and people, never keys.',
    'h:The rules',
    'Cannot close over an open action, never without findings.',
], 'tall', 'diagram.php?db=incidents');

$art('diagram_db_plans', 'The Plans Database', [
    'h:Purpose',
    'Safety plans and procedures, each with one owner and one computed review date.',
    'h:Its tables',
    'rq.SafetyPlans — the plan: kind, title, owner, dates, status.',
    'rq.PlanRooms — the rooms each plan covers, by RoomCode.',
    'rq.PlanReviews — every dated review line - the whole point.',
    'ref.PlanTypes — the kinds of plan and their review intervals.',
    'h:Links',
    'RoomCode coverage lets a room answer "what applies to me".',
], 'tall', 'diagram.php?db=plans');

$art('diagram_flow_incident', 'How An Incident Runs', [
    'h:The path',
    'Reported in plain words; the right people notified by the standing rules; '
        . 'graded by the safety office; corrective actions tracked to closure.',
    'h:What to notice',
    'A report cannot close over an open action, nor without written findings.',
], 'wide',
    'The reporter is never asked how bad it was - grading is the safety office\'s call.');

$wexDemo('incident_slip', 'Worked Example · The Meltwater Slip');

$screen('fe_incident', 'Report An Incident', [
    'h:What it does',
    'Four required questions - what, when, where, your own words. Everything else '
        . 'appears only when relevant.',
    'h:New here',
    'A near miss files as easily as an injury, deliberately. Severity is never '
        . 'asked of the reporter.',
    'h:The people rows',
    'Per person: name, involvement, injury, body part, treatment. Blank rows '
        . 'drop, never reject.',
    'h:Data it needs',
    'Rooms, people, the fleet list for vehicle accidents, editable pick lists.',
], [['Report', 'Notify', 'Grade', 'Actions', 'Close'], 0, 'Incidents · rq.Incidents']);

$screen('fe_incidents', 'Incident List', [
    'h:What it does',
    'Reporters see their own; the named back-end list sees all.',
    'h:Why that boundary',
    'Reports name people and describe injuries, so visibility is exactly the list of '
        . 'directors, assistants and OED point people - no wider.',
], [['Report', 'Notify', 'Grade', 'Actions', 'Close'], 1, 'Incidents · rq.Incidents']);

$screen('fe_incdetail', 'One Incident, End to End', [
    'h:What it does',
    'The report, who was there, corrective actions with owners and due dates, the '
        . 'grading, the timeline.',
    'h:The rules that hold',
    'Cannot close over an open action, nor without written findings - no report '
        . 'closes over its own unfinished fix.',
    'h:What each panel is',
    'Header facts, narrative, people with injuries, actions with work orders, '
        . 'the grading, the history.',
    'h:Linked',
    'A corrective action can carry the work order it raised.',
], [['Report', 'Notify', 'Grade', 'Actions', 'Close'], 3, 'Incidents · rq.IncidentActions']);

$screen('ad_incidents', 'Incidents, Administered', [
    'h:What it does',
    'Open reports, outstanding corrective actions, and rooms with repeat reports '
        . 'flagged - the pattern a list cannot show.',
    'h:What the flags mean',
    'A room with more than one report in two years is where inspections start. Today '
        . 'that flag shows 1073_01_1027, from the worked example.',
], [['Report', 'Notify', 'Grade', 'Actions', 'Close'], 2, 'the Incidents database']);

$screen('ad_safety', 'Safety Manifest', [
    'h:What it does',
    'Every eyewash, shower, extinguisher, hood and AED, on its room, with test dates.',
    'h:What the columns mean',
    'Kind, location, description, status, last tested, next due - the testing '
        . 'cycle made visible.',
    'h:Cleanup tied to it',
    '108 items have no test date; the first inspection round produces them as a '
        . 'by-product.',
], [['Record', 'Test', 'Due'], 1, 'Space · gis.Safety']);

$art('diagram_flow_plan', 'How A Plan Stays Alive', [
    'h:The path',
    'Every plan carries an owner and a review rhythm; the due date is computed; '
        . 'reminders land before it lapses; the review is recorded.',
    'h:What to notice',
    'Lapses are loud: an overdue plan is a named finding, never a quiet file.',
], 'wide',
    'The review date is computed from the plan\'s kind - nobody types a convenient one.');

$wex('Worked Example · The Overdue Plan', 'fe_plandetail_1',
[
    ['What the register caught', [
            'PLN-FY27-00003, Chemical Hygiene Plan, INHS Aquatic Ecology',
            'Effective fourteen months ago; these plans review every twelve',
            'Top of the list, red, "60 days late"',
    ]],
    ['1. Record the review', [
            'One button; outcome → "Revised — two new reagents added"',
    ]],
    ['2. What the system does', [
            'Writes a dated line, and computes the next due date a year from the day '
                . 'it was actually read — not from the old due date',
    ]],
    ['Why a line and never an edit', [
            'The question is "when was this last actually read, and by whom"',
            'An overwritable field cannot answer it; a dated line always can',
    ]],
],
    [['Register', 'Cover', 'Review', 'Next due'], 2, 'Plans · rq.SafetyPlans'], 'reg-plans');

$wex('Worked Example · A Withdrawn Plan', 'fe_plandetail_2',
[
    ['The case', [
            'The Forbes Building Emergency Action Plan is superseded by the campus plan',
    ]],
    ['1. Review it', [
            'Outcome → "Replaced by a new plan"',
    ]],
    ['2. What the register does', [
            'Writes a dated review line recording who said so and when',
            'Status becomes withdrawn; the due date clears',
            'It stops appearing in the reminder window',
    ]],
    ['What never happens', [
            'Nobody deletes a plan',
            'A register that deletes cannot answer "what covered this room in 2025"',
    ]],
],
    [['Register', 'Review', 'Withdrawn', 'History kept'], 2, 'Plans · rq.SafetyPlans'], 'reg-plans');

$screen('fe_plans', 'Safety Plan Register', [
    'h:What it does',
    'Every plan worst-first - the overdue one at the top in red with days late. '
        . 'Adding a plan lives behind a disclosure on the same page.',
    'h:New here',
    'The review date comes from the plan\'s kind and its effective date. A plan '
        . 'cannot be mis-scheduled years out by a typo.',
    'h:Data it needs',
    'Plan types with intervals; owners with NetIDs so reminders can reach them; the '
        . 'rooms each plan covers.',
], [['Register', 'Cover', 'Review', 'Next due'], 0, 'Plans · rq.SafetyPlans']);

$screen('fe_plandetail', 'One Safety Plan, Reviewed', [
    'h:What it does',
    'Owner, coverage, dates, and the review history - every time somebody read it '
        . 'and said it still stands.',
    'h:The rule',
    'A review adds a dated line. Nothing overwrites.',
], [['Register', 'Cover', 'Review', 'Next due'], 2, 'Plans · rq.PlanReviews']);

$screen('ad_plans', 'Safety Plans, Administered', [
    'h:What it does',
    'The register with its gaps said out loud: plans whose owner has no NetID so no '
        . 'reminder can reach them, plans covering no rooms so no room can claim them.',
], [['Register', 'Cover', 'Review', 'Next due'], 3, 'the Plans database']);

$wexDemo('incident_spill', 'Worked Example · A Spill Report');

$wexDemo('work_eyewash', 'Worked Example · A Failed Inspection');

$txt('Fields And Rules · Incident And Plan', [
    'h:Incident - required',
    'What kind; when (future dates refused); where - room or written place; the '
        . 'narrative. An injury or exposure additionally requires at least one named '
        . 'person.',
    'h:Incident - rules',
    'Safety office notified on filing; severity graded later by them; closure needs '
        . 'every action done plus written findings.',
    'h:Plan - required',
    'Kind; title; one named owner. The review date is computed, never typed.',
    'h:Plan - rules',
    'Reviews append dated lines; withdrawn plans keep their history; coverage is '
        . 'rooms by RoomCode so a room can answer "what applies to me".',
], 2);

$contract('space:gis.Safety', 'Safety Equipment');

$vocab('Safety Vocabulary', [
    'incident_kind' => 'Kinds of incident',
    'incident_severity' => 'How serious - the safety office\'s scale',
    'incident_treatment' => 'Treatment levels',
    'incident_involvement' => 'How somebody was involved',
    'plan_review_outcome' => 'What a plan review can conclude',
]);

$txt('Part 4: Common Questions', [
    'h:"Will reporting a near miss get someone in trouble?"',
    'The form asks what happened, not whose fault. Near misses are wanted - they are '
        . 'the cheapest safety data there is.',
    'h:"Who sees the injury details?"',
    'The reporter and the named back-end list. The visibility rule is a table, not a '
        . 'custom.',
    'h:"What if the fix takes months?"',
    'The action carries its due date and its work order; the report stays honestly '
        . 'open. Open is information.',
    'h:"Our plan is a binder on a shelf."',
    'Register it with its real effective date - the system will tell you it is '
        . 'overdue, which is precisely the point.',
]);

$disc('Discussion · Part 4',
[
    ['Which hazard classes should rooms be profiled under?',
     'Inspection rounds driven by the QR stickers'],
    ['Is property certification annual, and who runs it?',
     'A safety dashboard per survey, not just per room'],
    ['Who grades incidents when the safety officer is away?',
     'Equipment problem reports that raise the work order themselves'],
    ['What must be reported outside PRI, and inside what deadline?',
     'Training records attached to the person, not a spreadsheet'],
    ['Should near misses be visible to the whole survey?',
     'Chemical inventory tied to the room hazard profile'],
]);

// ################################################## PART 5 - EQUIPMENT
$sect('Part 5. Equipment', 'Purchasing and the property inventory', [
    'h:In this part',
    'The Purchasing and Equipment databases; the one-page purchase sheet; the '
        . 'Emburse reconcile filled in from the purchase record; and the single '
        . 'route by which property enters and leaves the inventory.',
    'Worked examples: the freezer end to end, three Banner codes at once, P-Card '
        . 'versus PO, fixing a wrong serial.',
    'h:You will leave able to',
    'Take a purchase from data sheet to tagged property without typing anything '
        . 'twice.',
]);

$art('diagram_db_purchasing', 'The Purchasing Database', [
    'h:Purpose',
    'Purchases from request to reconciliation to the property that arrives.',
    'h:Its tables',
    'rq.PurchaseRequests — the request: vendor, status, who holds it.',
    'rq.PurchaseItems — the lines: quantity, cost, Banner code.',
    'rq.PurchaseFunding — which CFOPs pay, in what split.',
    'rq.PurchaseEvents — everything that happened, in order.',
    'ref.BannerAccountCodes — all 3,193 campus codes with their guidance.',
    'h:Links',
    'PoNumber forward to the inventory; CfopCode to the accounts.',
    'h:The benefit',
    'The Emburse block builds from these rows - nothing typed twice.',
], 'tall', 'diagram.php?db=purchasing');

$art('diagram_db_equipment', 'The Equipment Database', [
    'h:Purpose',
    'The property inventory and the one form that changes it.',
    'h:Its tables',
    'gis.Inventory — 2,342 items: tag, holder, room, cost, purchase lineage.',
    'gis.InventoryEvents — every move, handover, correction - dated.',
    'rq.PropertyUpdates — filed changes awaiting the office.',
    'h:Links',
    'RoomCode to Space; PoNumber back to the purchase; PropertyTag to moves.',
    'h:Recovered',
    'PO numbers and \$16.4M of depreciation, from the C3PO export.',
], 'tall', 'diagram.php?db=equipment');

$art('diagram_flow_purchase', 'How A Purchase Becomes Property', [
    'h:The path',
    'Lines with Banner codes; funding split by CFOP; approvals by seat; buy; '
        . 'reconcile when the card is charged; tag what arrives.',
    'h:What to notice',
    'The equipment line links forward to the tagged item - purchase to property '
        . 'with nothing retyped.',
], 'wide',
    'One chain from data sheet to inventory tag, with the reconcile filled in from '
    . 'the purchase record.');

$wexDemo('purchase_freezer', 'Worked Example · The Freezer');

$wex('Worked Example · Three Codes at Once', 'fe_reconcile_1',
[
    ['1. Open the purchase', [
            'The reconcile button appears once the card charge has posted',
    ]],
    ['2. What the button produces', [
            'Three blocks, largest first: code 121300 totalling $674.89, '
                . 'the next code at $949.96, and $1,624.85',
            'Each already in the quantity, description, unit-cost format Emburse wants',
    ]],
    ['3. Copy, paste per code', [
            'One paste into the notes field per Banner code',
    ]],
    ['Checked how', [
            'Character for character against the office\'s own spreadsheet, to the '
                . 'cent, including the grand total - the manual retype loop is gone',
    ]],
],
    [['Charge posts', 'Reconcile', 'Copy', 'Paste per code'], 1, 'Purchasing · rq.PurchaseItems'], 'reg-purchasing');

$screen('fe_purchase', 'Purchase Request', [
    'h:What it does',
    'A one-page data sheet: product lines with Banner code typeahead, vendor, '
        . 'funding CFOP with the requester\'s activity code.',
    'h:New here',
    'One page; approval is a grid, not an email relay.',
    'h:Data it needs',
    '3,193 Banner codes and 1,408 accounts behind the typeaheads.',
], [['File', 'Approve', 'Buy', 'Reconcile', 'Tag'], 0, 'Purchasing · rq.PurchaseRequests']);

$screen('fe_purview', 'One Purchase, End to End', [
    'h:What it does',
    'Lines, funding split, approval grid, and every event on one page.',
    'h:What the sections are',
    'Quantities and Banner codes per line; the CFOP split; approval seats and '
        . 'decisions; the event log.',
    'h:Linked',
    'Equipment lines link to the property that arrives; reconcile appears once '
        . 'the card is charged.',
], [['File', 'Approve', 'Buy', 'Reconcile', 'Tag'], 1, 'Purchasing · rq.PurchaseItems']);

$screen('fe_reconcile', 'Emburse Reconciliation', [
    'h:What it does',
    'Groups the purchase\'s lines by Banner code, largest first, and formats the '
        . 'exact copy-paste block Emburse\'s notes field wants.',
    'h:New here',
    'Replaces the manual retype-into-Excel step. Verified against the real '
        . 'reconciliation spreadsheet to the cent.',
], [['File', 'Approve', 'Buy', 'Reconcile', 'Tag'], 3, 'the Emburse notes field']);

$screen('fe_property', 'Property Update', [
    'h:What it does',
    'One form for every change to a property record: register, move, hand over, '
        . 'correct, remove. Find the item; only the boxes that matter appear.',
    'h:New here',
    'Four front-page cards became one door, because they were four sentences about '
        . 'the same object. Staff file; the property office applies - a clear '
        . 'division of duty.',
    'h:Linked',
    'The one write path into gis.Inventory; arrivals prefill from the purchase line.',
], [['File', 'Office applies', 'Register'], 0, 'Equipment · gis.Inventory']);

$screen('ad_equipment', 'Equipment', [
    'h:What it does',
    'Search 2,342 items by tag, serial, description, holder or room; transfer '
        . 'custody from here.',
    'h:What the columns mean',
    'Tag, description, model, serial, holder, room, cost - and now the purchase '
        . 'document behind it.',
    'h:Recovered this month',
    'PO numbers on 1,635 items, P-Card flags, $16.4M depreciation - dropped on '
        . 'first import, recovered by the source audit.',
], [['Search', 'Transfer', 'Certify'], 0, 'Equipment · gis.Inventory']);

$screen('ad_property', 'Property Updates', [
    'h:What it does',
    'Filed changes waiting for the property office to apply; applying updates the '
        . 'register and writes the event.',
    'h:Why the two-step',
    'The person who watched the microscope leave files it; the person allowed to '
        . 'say so officially applies it.',
], [['Filed', 'Apply', 'Register'], 1, 'Equipment · gis.Inventory']);

$screen('ad_expected', 'Property Awaiting a Number', [
    'h:What it does',
    'Equipment bought and not yet tagged - the bridge between purchasing and the '
        . 'inventory.',
    'h:Linked',
    'Registering the arrival closes the purchase line\'s expectation; cost, account '
        . 'and room cross over untyped.',
], [['Bought', 'Arrives', 'Tag', 'Register'], 2, 'Equipment · gis.Inventory']);

$screen('ad_purchases', 'Purchase Requests, Administered', [
    'h:What it does',
    'Every purchase as a grid: where it sits, who holds it, the action that '
        . 'unsticks it.',
    'h:New here',
    'Seats reassigned and decisions made on behalf are recorded as such, never '
        . 'disguised.',
], [['File', 'Approve', 'Buy', 'Reconcile'], 1, 'the Purchasing database']);

$screen('ad_accounts', 'Accounts Directory', [
    'h:What it does',
    'The CFOP directory behind every funding typeahead: PI and fund manager per '
        . 'account, with end dates.',
    'h:Kept fresh by',
    'Pasting the grants export. The 63 accounts an earlier load missed were '
        . 'recovered this week; the audit now counts approver NetIDs still missing.',
], [['Paste export', 'Refresh', 'Typeahead'], 1, 'Shop · ref.CfopAccounts']);

$wex('Worked Example · P-Card or PO', 'ad_purchases_1',
[
    ['Same sheet, two roads', [
            'Lab consumables under the card limit go P-Card: '
                . 'file, approve, buy the same week, reconcile the charge',
    ]],
    ['The other road', [
            'The freezer went PO: file, approve, requisition, '
                . 'weeks of procurement, then arrival and tagging',
    ]],
    ['The register keeps the distinction', [
            'The recovered history marks 1,534 items bought on POs and 62 on P-Card',
    ]],
    ['Why it matters', [
            'Audit questions differ by road — the register answers, not memory',
    ]],
],
    [['File', 'Approve', 'Buy', 'Reconcile', 'Tag'], 2, 'Purchasing · rq.PurchaseRequests'], 'reg-purchasing');

$wexDemo('property_serial', 'Worked Example · A Wrong Serial');

$txt('Fields And Rules · Purchase And Property', [
    'h:Purchase - required',
    'Vendor; at least one line with quantity, description, unit cost and a Banner '
        . 'code - the classification of what is bought, never the funding; the CFOP '
        . 'account, which is what actually pays, with the requester\'s activity code.',
    'h:Purchase - rules',
    'The grid approves; the reconcile builds itself from the lines; equipment lines '
        . 'become expected property automatically.',
    'h:Property - required',
    'Find the item first (or arrive from a purchase); then only what the chosen '
        . 'change needs - a destination for a move, a person for a handover, a reason '
        . 'and date for a removal.',
    'h:Property - rules',
    'Staff file, the office applies; every change is an event on the item\'s '
        . 'history; corrections leave blanks alone.',
], 2);

$contract('equipment:gis.Inventory', 'The Property Inventory');
$contract('shop:ref.CfopAccounts', 'The Accounts Directory');

$vocab('Equipment Vocabulary', [
    'property_change' => 'What can happen to a property record',
]);

$txt('Part 5: Common Questions', [
    'h:"The Banner code list is huge - how do I find mine?"',
    'Type what you are buying; the typeahead searches titles, examples and the '
        . 'do-not-use notes, not just numbers.',
    'h:"What about P-Card purchases?"',
    'Same sheet, same approval; the register even distinguishes PO from P-Card on '
        . 'the recovered historical data.',
    'h:"An item on my list is not mine anymore."',
    'File a property update - handover or correction. At certification time the '
        . 'record already says who holds it, so there is nothing to reconstruct.',
    'h:"Why can\'t I just edit the inventory?"',
    'Because the register is only trustworthy if every change has a who, when and '
        . 'why. Forcing every change through one route is what makes that possible.',
]);

$disc('Discussion · Part 5',
[
    ['Where does the approval threshold actually sit?',
     'Vendor history: what we paid last time, and to whom'],
    ['Who reconciles when the buyer has left?',
     'Replacement and calibration due lists per custodian'],
    ['How long should we keep purchase documents on the item?',
     'Surplus and disposal route with its own approval'],
    ['Should equipment custody transfer need the receiver to accept?',
     'Barcode or QR scanning for certification rounds'],
    ['What is the policy on items bought on grants that end?',
     'Depreciation and replacement forecasting from the register'],
]);

// ################################################### PART 6 - VEHICLES
$sect('Part 6. Vehicles', 'The fleet and its paperwork', [
    'h:In this part',
    'The Fleet database; QR stickers and the phone mileage flow with its honest '
        . 'reader; what booking and driver forms will add.',
    'Worked examples: a reading end to end, an anomaly flagged, the sticker '
        . 'lifecycle, the vehicle accident that fed two registers.',
    'h:You will leave able to',
    'Issue vehicle stickers and operate the mileage workflow end to end.',
]);

$art('diagram_db_fleet', 'The Fleet Database', [
    'h:Purpose',
    'Vehicles, odometer readings, bookings and driver paperwork.',
    'h:Its tables',
    'ref.FleetVehicles — the registry: number, year, plate, VIN, home.',
    'sh.VehicleReadings — odometer readings from the QR flow.',
    'sh.VehicleBookings — who has which vehicle when - screens to come.',
    'sh.DriverForms — driver paperwork with expiry - screens to come.',
    'h:Links',
    'FleetNumber to Shop jobs and to incident reports.',
    'h:Cleanup',
    '279 plates, 117 years, 8 VINs missing; 295 numbers are property tags.',
], 'tall', 'diagram.php?db=fleet');

$art('diagram_flow_mileage', 'How A Mileage Reading Happens', [
    'h:The path',
    'The QR sticker on the dashboard opens the form; the reading files against '
        . 'the vehicle; anomalies are flagged for a second look.',
    'h:What to notice',
    'Two typos and one genuine 4,000-mile field month - the flags sort them '
        . 'apart.',
], 'wide',
    'A sticker, a phone, and a reader that declines rather than guesses.');

$wex('Worked Example · A Reading', 'ad_mileage_2',
[
    ['1. Scan the sticker', [
            'The silver Ford F-150, fleet 35695-22, carries a printed QR sticker',
            'The phone opens that vehicle\'s mileage page — no app, no login',
    ]],
    ['2. Photograph the dashboard', [
            'The seven-segment digits are read on the phone itself',
            'Multi-attempt and agreement-voted; typing it in is always allowed',
    ]],
    ['3. It declines rather than guesses', [
            'Bench test, fifteen displays: eleven read, four declined, zero read wrong',
            'A wrong odometer poisons maintenance scheduling silently',
    ]],
    ['What lands', [
            'The reading on the vehicle, dated; anomalies flagged, not silently kept',
    ]],
],
    [['Scan', 'Read', 'Confirm', 'Recorded'], 1, 'Fleet · ref.FleetVehicles'], 'reg-fleet');

$wexDemo('incident_vehicle', 'Worked Example · A Vehicle Accident');

$screen('fe_stickers', 'QR Stickers For The Fleet', [
    'h:What it does',
    'A printable sticker sheet per vehicle, QR encoded and rendered entirely '
        . 'in-house.',
    'h:Proof it is right',
    'The encoder was verified against the ISO format tables and by full round-trip '
        . 'at all four correction levels - every scanner reads these.',
], [['Sticker', 'Scan', 'Read', 'Record'], 0, 'Fleet · sh.VehicleReadings']);

$screen('ad_fleet', 'Fleet Vehicles', [
    'h:What it does',
    'The registry behind shop requests, mileage, and the booking system to come.',
    'h:What the columns mean',
    'Fleet number, description, year, make and model, plate, VIN, home building, '
        . 'survey, last reading.',
    'h:Cleanup tied to it',
    '279 missing plates, 117 missing years, 8 missing VINs - and 295 fleet numbers '
        . 'that are really property tags from the original sweep. Titles-folder '
        . 'work, divided in Part 7.',
], [['Register', 'Book', 'Maintain'], 0, 'Fleet · ref.FleetVehicles']);

$screen('ad_mileage', 'Mileage', [
    'h:What it does',
    'Readings from QR scans and manual entry; the sticker sheets; odd readings '
        . 'flagged rather than silently kept.',
    'h:Linked',
    'Feeds service scheduling once maintenance intervals are set.',
], [['Sticker', 'Scan', 'Read', 'Record'], 3, 'Fleet · sh.VehicleReadings']);

$txt('Driver Forms and Booking', [
    'h:What already exists',
    'The schema and library for driver forms and vehicle bookings are built; '
        . 'sh.DriverForms, sh.VehicleBookings and ref.DriverFormTypes are live '
        . 'tables.',
    'h:What is missing',
    'The screens - and one answer: the real names of the two required driver forms. '
        . 'The seeded placeholders are "mvr" and "certification", with "defensive" '
        . 'as the optional third.',
    'h:What it will do',
    'A booking checks the driver is cleared before the keys move; forms carry '
        . 'expiry dates so clearance lapses honestly.',
]);

$wex('Worked Example · An Odd Reading', 'ad_mileage_1',
[
    ['The entry', [
            'A reading arrives 40,000 miles below last month\'s',
            'The system records it — and flags it clearly',
    ]],
    ['The likely truth', [
            'A transposed digit, or the wrong truck\'s sticker scanned',
    ]],
    ['What the flag shows', [
            'Both readings and both dates, side by side',
            'Fixing it is one correction with a note',
    ]],
    ['Why flags beat validation', [
            'Refusing the entry loses the information that something is off',
            'Recording and flagging keeps the entry honest and the trail complete',
    ]],
],
    [['Reading', 'Flagged', 'Checked', 'Corrected'], 1, 'Fleet · ref.FleetVehicles'], 'reg-fleet');

$wex('Worked Example · Sticker Lifecycle', 'fe_stickers_1',
[
    ['1. Print', [
            'Stickers → a sheet per vehicle',
            'QR plus the fleet number in printed digits, for when no phone is to hand',
    ]],
    ['2. Fit them', [
            'The shop prints all 31 ISWS sheets in one run',
            'Laminated onto dashboards during Friday fueling',
    ]],
    ['3. Replace', [
            'Windshield swapped, sticker gone: reprint the same sheet',
            'The QR encodes the fleet number, not a serial — nothing to re-register',
    ]],
    ['The design choice', [
            'A sticker carries the fleet number only - no serial, no owner, nothing private',
            'Everything durable lives in the register, so the world reprints freely',
    ]],
],
    [['Print', 'Fit', 'Scan', 'Reprint'], 0, 'Fleet · ref.FleetVehicles'], 'reg-fleet');

$contract('fleet:ref.FleetVehicles', 'The Vehicle Fleet');

$txt('Part 6: Common Questions', [
    'h:"What if the photo reader gets it wrong?"',
    'Its design goal is refusing: on the bench it read eleven of fifteen, declined '
        . 'four, and was wrong zero times. Typing is always allowed.',
    'h:"Do drivers need the app?"',
    'No app. The sticker opens a web page; any phone camera works.',
    'h:"When does booking arrive?"',
    'The tables are live; the screens wait on one answer - the real names of the '
        . 'two required driver forms.',
    'h:"Why does my truck show a property tag?"',
    'It was swept in from the inventory years ago. The fleet-number fix in Part 7 '
        . 'is exactly this.',
]);

$disc('Discussion · Part 6',
[
    ['What are the real names of the two required driver forms?',
     'Vehicle booking with driver clearance checked at booking'],
    ['Who approves a booking when a vehicle is double-requested?',
     'Fuel and maintenance cost per vehicle, per year'],
    ['Should mileage be required monthly, or on use?',
     'Service intervals that raise their own shop requests'],
    ['Who owns the fleet number correction for the 295 vehicles?',
     'Trip logs attached to the booking'],
    ['What happens to a vehicle incident that involves the public?',
     'Photographs of damage carried on the vehicle record'],
]);

// ########################################### PART 7 - CLEANUP AND LAUNCH
$sect('Part 7. Cleanup and Launch', 'The working session - bring the workbook', [
    'h:In this part',
    'What the audit sees and what it found, with the real first rows of every '
        . 'worklist on the slides.',
    'Every build item, one slide each; the five answers only PRI can give.',
    'h:This part ends with',
    'Names against tabs, and a date to regenerate and compare.',
]);

$art('diagram_flow_launch', 'The Path to Launch', [
    'h:The path',
    'Regenerate the workbook, assign tabs, fix rows through the screens, '
        . 're-run - the blocker count falls until it reads zero, and launch '
        . 'opens.',
    'h:What to notice',
    'Fixed rows simply stop appearing; nobody maintains the worklist by hand.',
], 'wide',
    'Regenerate, assign, fix, re-run - the blocker count only moves one way.');

$txt('What the Audit Sees, and Its Limits', [
    'h:Total coverage, stated precisely',
    'All ' . count($comp['accounting']) . ' tables in all ten databases classified; '
        . 'all ' . $fmt(count($comp['matrix'])) . ' columns measured for fullness; '
        . 'the working registers graded cell by cell against written expectations.',
    'h:It checks itself',
    'A broken check is a loud fault on the first tab, never a silent zero. It is '
        . 'reporting none. That rule exists because its absence once hid nine blind '
        . 'checks and a coverage panel reading zero percent of zero.',
    'h:What it cannot see',
    'Values present but wrong - a mistyped serial reads as filled. Facts that live '
        . 'on paper - lease end dates. Decisions not yet made - hazard classes, '
        . 'driver form names. Other systems\' truth between exports.',
]);

$txt('Audit Findings', [
    'h:The headline numbers, computed today',
    $fmt($grand) . ' rows to work through across ' . array_sum($sev) . ' findings: '
        . $fmt($rowsBySev['blocker']) . ' stop something working, '
        . $fmt($rowsBySev['important']) . ' make something unreliable, '
        . $fmt($rowsBySev['tidy']) . ' worth correcting.',
    $fmt($mustCells) . ' individual cells must be filled; ' . $fmt($namedRows)
        . ' records are named row by row with exactly which columns they need.',
    'h:Where it concentrates',
    'Keys and access ' . $fmt($byCat['Keys and access'] ?? 0) . ' · Vehicles '
        . $fmt($byCat['Vehicles'] ?? 0) . ' · Safety ' . $fmt($byCat['Safety'] ?? 0)
        . ' · Space ' . $fmt($byCat['Space'] ?? 0) . '. Four areas hold most of it, '
        . 'and each has a named clearing move.',
    'h:The tool',
    'The workbook regenerates from live data - 20 tabs, every row a job. Pull it '
        . 'fresh each session; fixed rows simply stop appearing.',
]);

// The register-by-register numbers live in the coverage device below;
// the text carries only how to read it.
$covLines = [
    'h:How to read it',
    'How full each register is, computed today. These are the numbers that move '
        . 'week to week as the worklists get worked - regenerate this deck and '
        . 'watch them climb. Launch is the blockers at zero, but health is these '
        . 'percentages.',
];
$txt('Register Coverage', $covLines, 1);

$txt('Cleanup · Keys (' . $fmt($byCat['Keys and access'] ?? 0) . ' rows)', [
    'h:What is wrong',
    '842 active keys held by people marked Terminated; 837 keys with no survey; 94 '
        . 'naming rooms the plans do not know; 3 with nobody named at all.',
    'h:The clearing move',
    'One fresh iCard export reconciled against the employee file resolves the bulk '
        . 'mechanically: access already revoked at termination gets marked Returned '
        . 'with a note.',
    'h:What remains by hand',
    'Physical keys genuinely out with departed staff - chased or marked Lost, '
        . 'honestly, row by row from the workbook\'s Keys tab.',
    'h:Who',
    'One name owns the export; each survey\'s assistant works their own rows.',
]);

$txt('Cleanup · Vehicles (' . $fmt($byCat['Vehicles'] ?? 0) . ' rows)', [
    'h:What is wrong',
    '295 fleet numbers are really property tags from the original inventory sweep; '
        . '279 missing plates; 117 missing years; 8 missing VINs; 318 with no home '
        . 'building or survey.',
    'h:The clearing move',
    'The titles folder holds plate, VIN and year for every owned vehicle - one pass '
        . 'through it fills three columns at once. Fleet numbers come off the doors '
        . 'themselves.',
    'h:Why it is worth an afternoon',
    'Until the numbers match the paint, a shop request cannot reliably find the '
        . 'vehicle it is about - and booking, when it lands, inherits the same '
        . 'confusion.',
]);

$txt('Cleanup · Safety (' . $fmt($byCat['Safety'] ?? 0) . ' rows)', [
    'h:What is wrong',
    '499 rooms that can hurt somebody - laboratories, greenhouses, shops, anywhere '
        . 'already holding safety equipment - have no safety contact named. 108 '
        . 'pieces of equipment carry no test date. 53 buildings show no safety '
        . 'equipment recorded at all.',
    'h:The clearing move',
    'Contacts: five lists, one per survey, filled by people who already know the '
        . 'answer. Test dates: the first inspection round produces them as a '
        . 'by-product of the walk.',
    'h:The stakes stated plainly',
    'An inspection finding or a spill in one of those 499 rooms currently has '
        . 'nobody to route to.',
]);

$txt('Cleanup · Space and Leases ('
        . $fmt(($byCat['Space'] ?? 0) + ($byCat['Estate'] ?? 0)) . ' rows)', [
    'h:What is wrong',
    '235 assignable rooms with nobody answerable; 232 rooms with no use code; 28 '
        . 'agreements with no end date; buildings off campus with no owner recorded.',
    'h:The clearing move',
    'Room responsibility is committee knowledge - each survey assigns its own. The '
        . 'lease dates live in one folder of paper agreements; one person, one '
        . 'afternoon.',
    'h:Already proven worth it',
    'The first pass over the estate register found five leases years expired. The '
        . '28 undated ones are where the next such finding is most likely.',
]);

$txt('Cleanup · Accounts and the Rest', [
    'h:Accounts (' . $fmt($byCat['Accounts'] ?? 0) . ' rows)',
    'Approver NetIDs missing on recovered accounts - until filled, a funding '
        . 'approval on those accounts has nowhere to route. The PI names are known; '
        . 'the NetIDs are a directory lookup.',
    'h:Requests, people, delivery (' . $fmt(($byCat['Requests'] ?? 0)
        + ($byCat['People and routing'] ?? 0) + ($byCat['Delivery'] ?? 0)
        + ($byCat['Data sources'] ?? 0)) . ' rows)',
    'Small, named lists: open requests missing a survey, org-chart proposals '
        . 'unconfirmed, notification events with nobody listening, and the example '
        . 'data sweep before launch.',
    'h:And the one-line blocker',
    'The Outlook relay. Every notification in this deck is written and waiting; '
        . 'none is delivered. Host, port, credentials - then it all sends.',
]);

$screen('ad_audit', 'The Cleanup Workbook', [
    'h:What it does',
    'The audit inside the application: every check, its count, and the screen '
        . 'where the finding is fixed.',
    'h:How to read it',
    'Blockers first. A zero row is a result - fifteen checks find nothing, '
        . 'stated rather than hidden.',
    'h:The workbook lives beside it',
    'The staff working copy: area tabs, Rows to Fill, Cells to Fill, Columns to '
        . 'Add, Systems to Build.',
], [['Workbook', 'Assign', 'Fix', 'Re-run'], 0, 'reads all ten databases']);

$wex('Worked Example · One Cleanup Row', 'ad_audit_1',
[
    ['The row', [
            'Keys tab, line one: a Terminated holder, ISTC, Dock Door, iCard',
            'Still Active on the register',
    ]],
    ['1. Check the export', [
            'The ISTC assistant reads the iCard export: access was revoked '
                . 'at termination, so the physical key is not the question',
    ]],
    ['2. Mark it honestly', [
            'The register row is marked Returned with a note',
            'Where a physical key is genuinely out, it is chased or marked Lost',
    ]],
    ['3. Re-run', [
            'Next regeneration that row is gone from the workbook, and 842 reads 841',
            'Nobody updates a tracking spreadsheet — the register is the spreadsheet',
    ]],
],
    [['Workbook', 'Assign', 'Fix', 'Re-run'], 2, 'reads all ten databases'], 'sys-audit');

$sampleRows('Keys', 'Keys');
$sampleRows('vehicle fleet', 'The Fleet');
$sampleRows('Safety equipment', 'Safety Equipment');
$sampleRows('agreements', 'Agreements');
$sampleRows('accounts', 'Accounts');
$sampleRows('inventory', 'Property');
$sampleRows('buildings', 'Buildings');

$txt('Columns to Add (' . count($comp['to_add']) . ')', [
    'h:Custody and certification',
    'Custodian held apart from the user; LastCertifiedOn; CertifiedByNetID - the '
        . 'columns the annual certification round stands on.',
    'h:Money and contracts',
    'FundingCfop; ContractNumber and ContractExpires; ReplacementCost.',
    'h:Lifecycle',
    'Manufacturer; WarrantyUntil; ExpectedLifeYears; ReplacementDue; CalibrationDue; '
        . 'PurchaseRequestID to tie an item to the purchase that bought it.',
    'h:And one conversion',
    'AcquiredOn from text to a real date - every row has a value, so age, '
        . 'depreciation and replacement planning unlock the day it converts.',
]);

$txt('Systems To Build (' . $backlogCount . ')', [
    'h:Data and structure',
    'The directory database; loaders that refuse an unmapped column; the lease '
        . 'dates; the Building Wizard.',
    'h:Safety',
    'Equipment problem reports that raise work orders; room hazard profiles and '
        . 'door cards; inspection rounds on the QR stickers; the contacts lookup.',
    'h:Vehicles',
    'Driver form screens; vehicle booking; the fleet number fix.',
    'h:Equipment and delivery',
    'The certification round; replacement and calibration due lists; the surplus '
        . 'route; the Outlook relay and the four surveys\' contacts.',
]);

$buildSlides();

$txt('Decisions Needed from PRI', [
    'The Outlook relay: host, port, credentials. Until then every notification '
        . 'sits in the outbox, read by nobody.',
    'The real names of the two required driver forms.',
    'Space committee and safety contacts for ISAS, ISGS, ISTC and ISWS.',
    'The hazard classes the safety office wants rooms profiled under.',
    'Whether property certification is annual, and who runs it.',
]);

$txt('Assignments and Owners', [
    'The key register export and reconciliation - one name.',
    'Fleet plates, years and VINs from the titles folder - one name.',
    'Safety contacts on the 499 hazard rooms - five names, one per survey.',
    'The 28 lease end dates off the paper leases - one name.',
    'The five answers above - one name to chase each.',
    'h:Then the loop',
    'Regenerate the workbook before every session; fixed rows disappear; launch '
        . 'when the blockers read zero. Then keep building on the same rails - every '
        . 'new register gets a database, an audit expectation, and a card on the '
        . 'front door.',
]);

$txt('Part 7: Common Questions', [
    'h:"Who owns the workbook?"',
    'Nobody owns the workbook - it regenerates. People own tabs, and only until the '
        . 'rows are gone.',
    'h:"What if we find data that is wrong, not just missing?"',
    'Fix the register and the audit recomputes. If it is a kind of wrongness the '
        . 'audit cannot see, say so - a new check is an afternoon.',
    'h:"When is launch?"',
    'When the blocker count reads zero. That is a figure the audit computes and '
        . 'anyone can check, so nobody has to judge readiness in a meeting.',
    'h:"What happens to the old spreadsheets?"',
    'They become what they always should have been: backups. The registers are the '
        . 'truth now, and they can prove it - one click to CSV, any time.',
]);

$disc('Discussion · Part 7',
[
    ['Which of the five open answers can be settled today?',
     'A launch dashboard: blockers remaining, by survey'],
    ['Who owns each cleanup tab, by name, with a date?',
     'Scheduled export refresh reminders to the right person'],
    ['What is our definition of "ready to launch"?',
     'A public status page for the institute'],
    ['How long do the old intake routes stay answered?',
     'Onboarding guide generated from these job aids'],
    ['Who maintains this after launch, and with what time?',
     'Annual report figures pulled straight from the registers'],
]);

require __DIR__ . '/deck_visuals.php';

// =====================================================================
// The name the deck is actually opened under. It used to write
// PRI-Facilities-Briefing.pptx and somebody renamed the result by hand,
// which means a build could report success while the file being read was
// the one before it.
$path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'PRIOps-Briefing.pptx';
$n = pptx_write($path, 'PRIOps Briefing', $S);
echo 'Wrote ' . $path . ' (' . number_format($n) . " bytes)\n";
echo count($S) . " slides\n";
$missing = 0;
foreach ($S as $s) {
    if (($s['kind'] ?? '') === 'image' && !is_file($s['image'])) {
        $missing++;
        echo '  MISSING SHOT: ' . $s['image'] . "\n";
    }
}
echo $missing ? "$missing screenshot(s) missing\n" : "every screenshot present\n";
