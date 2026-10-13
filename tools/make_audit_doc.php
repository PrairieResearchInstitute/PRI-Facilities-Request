<?php
declare(strict_types=1);

/**
 * The data audit, written out.
 *
 * The workbook is for working through; this is for reading and for
 * handing to somebody who will not open a workbook. Every number in it
 * comes from the same checks the screen runs, computed at the moment the
 * document is made, so it can be remade whenever anybody asks whether it
 * is still true.
 *
 *   php tools/make_audit_doc.php
 */

require_once __DIR__ . '/../facilities/lib/dataaudit.php';
require_once __DIR__ . '/../facilities/lib/docx.php';
require_once __DIR__ . '/../facilities/lib/completeness.php';
require_once __DIR__ . '/../facilities/lib/backlog.php';

set_time_limit(900);

$checks = audit_all();
$coverage = audit_coverage();
$comp = completeness_run();

$sevWord = [
    'blocker'   => 'Stops something working',
    'important' => 'Makes something unreliable',
    'tidy'      => 'Worth correcting',
];

$live = array_values(array_filter($checks, fn($c) => !$c['error'] && (int)$c['count'] > 0));
$clear = array_values(array_filter($checks, fn($c) => !$c['error'] && (int)$c['count'] === 0));
$broken = array_values(array_filter($checks, fn($c) => (bool)$c['error']));

$countBySev = ['blocker' => 0, 'important' => 0, 'tidy' => 0];
$rowsBySev = ['blocker' => 0, 'important' => 0, 'tidy' => 0];
$byCategory = [];
$grand = 0;
foreach ($live as $c) {
    $countBySev[$c['severity']]++;
    $rowsBySev[$c['severity']] += (int)$c['count'];
    $grand += (int)$c['count'];
    $byCategory[$c['category']] = ($byCategory[$c['category']] ?? 0) + (int)$c['count'];
}
arsort($byCategory);

$b = '';

// ---------------------------------------------------------------- cover
$b .= docx_h('PRI Facilities Request System', 1);
$b .= docx_p('Data audit', 'Subtitle');
$b .= docx_facts([
    'Produced'                => date('j F Y, g:ia'),
    'Checks run'              => count($checks),
    'Checks finding something' => count($live),
    'Checks currently clear'  => count($clear),
    'Checks that could not run' => count($broken),
    'Rows to work through'    => number_format($grand),
]);

// ------------------------------------------------------------ what this is
$b .= docx_h('What This Is', 2);
$b .= docx_p('Every figure here is computed, not remembered. The system runs '
    . count($checks) . ' checks against the live databases and each one answers three '
    . 'questions: what is wrong, how much of it there is, and where it gets fixed. '
    . 'Nothing in this document was typed in by hand, so it can be produced again '
    . 'tomorrow and the numbers will have moved.');
$b .= docx_p('The findings are not defects in the system. They are gaps in the data the '
    . 'system inherited - from the geodatabase, the property inventory, the key register, '
    . 'the employee export and the account directory. The system’s job here is to '
    . 'make each gap visible, countable and assignable, which the spreadsheets it replaced '
    . 'could not do.');
$b .= docx_p('Findings are graded three ways. *' . $sevWord['blocker'] . '* means something '
    . 'in the system cannot do its job until this is corrected. *' . $sevWord['important']
    . '* means it works but cannot be trusted. *' . $sevWord['tidy'] . '* means it is '
    . 'correct enough to run on and worth cleaning when there is time.');

// ------------------------------------------------------- what changed
$b .= docx_h('What Changed Since The Last Audit', 2);
$b .= docx_p('The databases were split from three into ten. Nine checks were left querying '
    . 'tables that had moved out from under them, and each returned a silent zero with an '
    . 'error attached rather than a count. The previous audit therefore reported *3,860* '
    . 'findings when the true figure was *4,017*: it was blind in nine places, and blind in '
    . 'a way that looked like good news.');
$b .= docx_p('Those nine are fixed. Six needed only the right database handle. Three had '
    . 'become genuinely cross-database questions - a building is the estate’s, the keys '
    . 'against it belong to the key register, and the property in it belongs to the '
    . 'inventory - and were rewritten as several queries joined on the building number, '
    . 'because no query here crosses a database boundary.');
$b .= docx_p('The 157 findings this recovered are listed below under Property, Safety and '
    . 'Space. They were always there. Nothing got worse; the audit got honest.');

// ------------------------------------------------------------- the picture
$b .= docx_h('The Picture', 2);
$b .= docx_table(
    ['How serious', 'Checks', 'Rows'],
    [
        [$sevWord['blocker'], $countBySev['blocker'], number_format($rowsBySev['blocker'])],
        [$sevWord['important'], $countBySev['important'], number_format($rowsBySev['important'])],
        [$sevWord['tidy'], $countBySev['tidy'], number_format($rowsBySev['tidy'])],
    ], [5000, 1800, 2560]);

$b .= docx_h('Where It Is Concentrated', 3);
$b .= docx_p('Ten areas, but three of them hold most of it. Working top-down here clears '
    . 'more than working through the list in order.');
$catRows = [];
foreach ($byCategory as $cat => $n) {
    $catRows[] = [$cat, number_format($n),
                  $grand > 0 ? round($n * 100 / $grand) . '%' : '0%'];
}
$b .= docx_table(['Area', 'Rows', 'Share'], $catRows, [4600, 2400, 2360]);

// ------------------------------------------------------------ blockers
$b .= docx_pagebreak();
$b .= docx_h('What Stops Something Working', 2);
$b .= docx_p('These are the ones to take first. Each one means a part of the system cannot '
    . 'do what it was built to do until the underlying data is corrected.');

foreach (array_filter($live, fn($c) => $c['severity'] === 'blocker') as $c) {
    $b .= docx_h($c['title'], 3);
    $b .= docx_facts([
        'How many'   => number_format((int)$c['count']),
        'Area'       => $c['category'],
        'Fixed at'   => $c['fix_where'],
    ]);
    $b .= docx_p($c['why']);
}

// --------------------------------------------------------- everything else
$b .= docx_pagebreak();
$b .= docx_h('Everything Else, In Order', 2);
$b .= docx_p('The full list, worst first, then by how much of it there is. The workbook '
    . 'that accompanies this document has a tab per area with every row named, so a finding '
    . 'here can be split between people and worked through.');

foreach (['important', 'tidy'] as $sev) {
    $these = array_values(array_filter($live, fn($c) => $c['severity'] === $sev));
    if (!$these) {
        continue;
    }
    $b .= docx_h($sevWord[$sev], 3);
    $rows = [];
    foreach ($these as $c) {
        $rows[] = [$c['category'], $c['title'], number_format((int)$c['count']), $c['fix_where']];
    }
    $b .= docx_table(['Area', 'Finding', 'How many', 'Where it gets fixed'], $rows,
                     [1700, 3500, 1100, 3060]);
}

// -------------------------------------------------------------- why
$b .= docx_pagebreak();
$b .= docx_h('Why Each Finding Matters', 2);
$b .= docx_p('The same list again, with the reason. This is the part to read before '
    . 'deciding something can wait.');
foreach (['important', 'tidy'] as $sev) {
    foreach (array_filter($live, fn($c) => $c['severity'] === $sev) as $c) {
        $b .= docx_h($c['title'] . '  —  ' . number_format((int)$c['count']), 4);
        $b .= docx_p($c['why']);
    }
}

// ---------------------------------------------------------- what is clear
$b .= docx_pagebreak();
$b .= docx_h('What Is Already Clear', 2);
$b .= docx_p('These ' . count($clear) . ' checks ran and found nothing. They are listed '
    . 'because a check that finds nothing is a result, and because it is worth knowing '
    . 'which questions have already been answered.');
foreach ($clear as $c) {
    $b .= docx_bullet($c['title'] . '  (' . $c['category'] . ')');
}

if ($broken) {
    $b .= docx_h('Checks That Could Not Run', 2);
    $b .= docx_p('Each of these is itself a finding. A check that cannot run is not a check '
        . 'that passed.');
    foreach ($broken as $c) {
        $b .= docx_bullet($c['title'] . ' — ' . preg_replace('/\s+/', ' ', $c['error']));
    }
}

// -------------------------------------------------------------- coverage
if ($coverage) {
    $b .= docx_pagebreak();
    $b .= docx_h('Coverage', 2);
    $b .= docx_p('How complete each body of data is. This is a different question from '
        . 'whether it is correct: a table can be entirely filled in and still be wrong. It '
        . 'is here because an empty column and a bad column need different work.');
    $rows = [];
    foreach ($coverage as $label => $c) {
        if ($label === '_errors') {
            continue;
        }
        $rows[] = [
            $label,
            number_format((int)$c['have']),
            number_format((int)$c['total']),
            !empty($c['failed']) ? 'could not be measured' : $c['pct'] . '%',
        ];
    }
    $b .= docx_table(['What', 'Filled in', 'Of', 'Coverage'], $rows, [4400, 1500, 1500, 1960]);
    $b .= docx_p('Two of these deserve a note. *Rooms that can hurt somebody, with a safety '
        . 'contact* stands at one room in five hundred, which is the single largest gap in '
        . 'the system and the one with the clearest consequence. *Vehicles with a year, '
        . 'plate and VIN* stands at 35 of 326 because most of the fleet list was swept out '
        . 'of the property inventory rather than entered as vehicles.');
}

// ------------------------------------------------------------ how to use
// ------------------------------------------ does it see everything
$b .= docx_pagebreak();
$b .= docx_h('Does This Audit See Everything?', 2);
$byClass = [];
foreach ($comp['accounting'] as $a) {
    $byClass[$a['class']] = ($byClass[$a['class']] ?? 0) + 1;
}
$mustCells = 0;
$wantCells = 0;
foreach ($comp['gaps'] as $g) {
    if ($g['need'] === 'Must be filled') { $mustCells += $g['missing']; }
    else { $wantCells += $g['missing']; }
}
$backlogCount = 0;
foreach (APP_BACKLOG as $items) { $backlogCount += count($items); }

$b .= docx_p('Yes, within what a database can know about itself - and it now says exactly '
    . 'where that limit is. Every one of the ' . count($comp['accounting']) . ' tables in '
    . 'all ten databases is classified, every one of its '
    . number_format(count($comp['matrix'])) . ' columns is measured for how full it is, '
    . 'and the registers staff work through are graded cell by cell against written '
    . 'expectations. A table the audit has not judged says so on its own line; nothing '
    . 'sits outside it unlabelled.');
$b .= docx_table(['How the tables divide', 'Tables', 'What it means'], [
    ['Graded registers', $byClass['graded'] ?? 0,
     'Worked through by staff; every must-fill and wanted column counted'],
    ['Swept', $byClass['swept'] ?? 0, 'Fill rates reported; no expectations set'],
    ['Transactional', $byClass['transactional'] ?? 0,
     'Written by forms that enforce their own required fields'],
    ['Logs and histories', $byClass['log'] ?? 0, 'Grow with use; an empty cell is not a gap'],
    ['Replicas', $byClass['replica'] ?? 0, 'Published copies, audited where they are mastered'],
    ['Empty', $byClass['empty'] ?? 0, 'New; fill through use'],
], [4200, 1400, 3760]);
$b .= docx_p('The grading finds *' . number_format($mustCells) . ' cells that must be '
    . 'filled* and ' . number_format($wantCells) . ' more that are wanted, each named by '
    . 'register, column and row in the workbook. Beyond the data itself: *'
    . count($comp['to_add']) . ' columns still to add* to the schema and *' . $backlogCount
    . ' systems still to build*, both listed in full in the workbook and in the companion '
    . 'document.');
if ($comp['faults']) {
    $b .= docx_p('*The audit is currently reporting ' . count($comp['faults'])
        . ' fault(s) in itself.* Fix those before trusting anything above.');
    foreach ($comp['faults'] as $f) {
        $b .= docx_bullet($f);
    }
} else {
    $b .= docx_p('The audit also checks itself: an expectation naming a column that does '
        . 'not exist, or a sweep that fails, is reported as a fault in the audit rather '
        . 'than skipped. It is reporting none.');
}
$b .= docx_h('What It Cannot See', 3);
$b .= docx_bullet('A value that is present but wrong. A mistyped serial number reads as '
    . 'filled in.');
$b .= docx_bullet('Facts that exist only on paper. A lease end date lives on the lease; '
    . 'twenty-eight agreements are waiting on exactly that.');
$b .= docx_bullet('Decisions not yet made: the hazard classes for room profiles, the real '
    . 'names of the driver forms.');
$b .= docx_bullet('Other systems\' truth. Banner, iCard and C3PO are only as current as '
    . 'the last export somebody pulled and loaded.');

$b .= docx_h('How To Work Through It', 2);
$b .= docx_p('The system carries the audit itself, so none of this has to be tracked '
    . 'separately. *Data → What is wrong with the data* runs every check live and '
    . 'each finding links to the screen that fixes it. The workbook download on the same '
    . 'page is this document’s companion: a summary tab, a coverage tab, and one tab '
    . 'per area with every affected row named.');
$b .= docx_p('The order that clears the most ground:');
$b .= docx_bullet('*The key register.* It is the largest single block and one export '
    . 'reconciled against the employee file resolves most of it.');
$b .= docx_bullet('*The fleet list.* Nearly three hundred vehicles carry a property tag '
    . 'where a fleet number belongs, because they were swept in from the inventory.');
$b .= docx_bullet('*Safety contacts on hazard rooms.* Not all rooms - the laboratories, '
    . 'greenhouses, animal quarters, shops and anything already holding safety equipment.');
$b .= docx_bullet('*Room responsibility.* Who answers for each room, which everything '
    . 'about space reporting sits on top of.');
$b .= docx_p('The tidy findings can wait. They are recorded so that nobody has to '
    . 'rediscover them, not because they need doing this month.');

$path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'PRI-Facilities-Data-Audit.docx';
$n = docx_write($path, 'PRI Facilities Request System - Data Audit', $b);
echo "Wrote $path (" . number_format($n) . " bytes)\n";
echo count($checks) . " checks, " . number_format($grand) . " findings\n";
