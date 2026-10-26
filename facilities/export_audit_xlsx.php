<?php
declare(strict_types=1);

/**
 * The whole audit as one workbook: the thing staff work through.
 *
 * Four kinds of gap live here, and each gets its own kind of tab.
 *
 *   Findings   - things that are wrong, one tab per area, every row
 *                named so the list can be split between people.
 *   Cells      - things that are empty, graded register by register:
 *                every must-fill and wanted column that is short, and
 *                behind it the full matrix of every column of every
 *                table in all ten databases.
 *   Columns    - things the schema itself still lacks.
 *   Systems    - things not yet built at all.
 *
 * The last tab accounts for every table, so "did the audit even look at
 * X" has an answer that is not somebody's memory. If the audit itself is
 * broken - an expectation naming a column that does not exist, a sweep
 * that failed - that appears as the FIRST tab, because a broken audit
 * that keeps quiet is how data got lost in the first place.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/dataaudit.php';
require_once __DIR__ . '/lib/completeness.php';
require_once __DIR__ . '/lib/backlog.php';
require_once __DIR__ . '/lib/xlsx.php';

if (!app_installed()) {
    header('Location: install.php');
    exit;
}
current_user();
require_role('coordinator');
set_time_limit(900);

$checks = audit_all();
$coverage = audit_coverage();
$comp = completeness_run();

$severityWord = ['blocker' => 'Stops something working',
                 'important' => 'Makes something unreliable',
                 'tidy' => 'Worth correcting'];

$sheets = [];

// ---- if the audit itself is broken, that is the headline ------------
$auditFaults = $comp['faults'];
foreach ($checks as $c) {
    if ($c['error']) {
        $auditFaults[] = $c['title'] . ' - ' . $c['error'];
    }
}
if ($auditFaults) {
    $rows = [['The audit is not fully running. Fix these before trusting anything else here.']];
    foreach ($auditFaults as $f) {
        $rows[] = [$f];
    }
    $sheets['AUDIT FAULTS'] = ['rows' => $rows, 'widths' => [130]];
}

// ---- summary: every finding and every graded shortfall, one list ----
$totals = ['blocker' => 0, 'important' => 0, 'tidy' => 0];
$grand = 0;
$sum = [['Kind', 'Area', 'Finding', 'How serious', 'How many', 'Where it gets fixed']];
foreach ($checks as $c) {
    if ($c['error'] || (int)$c['count'] === 0) {
        continue;
    }
    $totals[$c['severity']]++;
    $grand += (int)$c['count'];
    $sum[] = ['Fix', $c['category'], $c['title'], $severityWord[$c['severity']],
              (int)$c['count'], $c['fix_where']];
}
$mustCells = $wantCells = 0;
foreach ($comp['gaps'] as $g) {
    if ($g['need'] === 'Must be filled') {
        $mustCells += $g['missing'];
    } else {
        $wantCells += $g['missing'];
    }
    $sum[] = ['Fill', $g['area'], $g['register'] . ': ' . $g['column'] . ' is empty',
              $g['need'], $g['missing'], $g['where']];
}
foreach ($comp['to_add'] as $t) {
    $sum[] = ['Add', 'Schema', $t['table'] . ' needs ' . $t['column'], 'Column to add', '',
              'Build work, not data entry'];
}
$backlogCount = 0;
foreach (APP_BACKLOG as $section => $items) {
    foreach ($items as $it) {
        $backlogCount++;
        $sum[] = ['Build', $section, $it[0], APP_BACKLOG_SIZE[$it[3]] ?? $it[3], '', $it[4]];
    }
}

// ---- read me, built once the numbers above exist --------------------
$readme = [
    ['PRIOps - the audit, whole'],
    ['Produced', date('F j, Y, g:ia')],
    [],
    ['This workbook is the working copy. Sort it, filter it, split the tabs between people,'],
    ['and mark rows off as they are fixed. It regenerates from the live databases, so pull a'],
    ['fresh one before each session rather than reconciling an old one.'],
    [],
    ['WHAT IS IN IT'],
    ['Summary', 'Everything, one line each: fixes, cells to fill, columns to add, systems to build'],
    ['The area tabs', 'Findings with every affected row named - the keys to chase back, the vehicles to complete'],
    ['Cells To Fill', 'Register by register, every graded column that is short, worst first'],
    ['Rows To Fill', 'The rows behind those counts: each record named, with exactly which columns it needs'],
    ['Columns To Add', 'What the schema itself still lacks - build work'],
    ['Systems To Build', 'What is not built at all - build work'],
    ['Every Table', 'All ' . count($comp['accounting']) . ' tables in all ' . count(APP_DB_NAMES)
        . ' databases, each classified, so nothing sits outside the audit unlabelled'],
    ['Full Matrix', 'Every column of every table - ' . number_format(count($comp['matrix']))
        . ' of them - and how full each one is'],
    ['Coverage', 'The same registers as percentages, for watching progress week to week'],
    [],
    ['THE NUMBERS RIGHT NOW'],
    ['Findings to fix', number_format($grand) . ' rows across ' . array_sum($totals)
        . ' findings'],
    ['Cells that must be filled', number_format($mustCells)],
    ['Cells that are wanted', number_format($wantCells)],
    ['Columns to add', (string)count($comp['to_add'])],
    ['Systems to build', (string)$backlogCount],
    [],
    ['WHAT THIS CANNOT SEE'],
    ['A value that is present but wrong - a mistyped serial number reads as filled.'],
    ['Facts that exist only on paper - a lease end date is on the lease, not in any export.'],
    ['Decisions not yet made - hazard classes, the real driver form names.'],
    ['Other systems\' truth - Banner, iCard and C3PO are only as current as the last export.'],
];
$sheets = array_merge(['Read Me' => ['rows' => $readme, 'widths' => [30, 110]]], $sheets);
$sheets['Summary'] = ['rows' => $sum, 'widths' => [8, 18, 62, 24, 10, 44]];

// ---- one tab per area, every row named ------------------------------
$byArea = [];
foreach ($checks as $c) {
    if ($c['error'] || (int)$c['count'] === 0) {
        continue;
    }
    $byArea[$c['category']][] = $c;
}
foreach ($byArea as $area => $items) {
    // Every finding in the area, stacked, with its own heading row above
    // its rows so a reader can tell where one ends and the next begins.
    $out = [['Finding', 'How serious', 'Detail', '', '', '', '', '']];
    foreach ($items as $c) {
        $out[] = [];
        $out[] = [$c['title'], $severityWord[$c['severity']],
                  (int)$c['count'] . ' to work through', $c['fix_where']];
        if ($c['columns']) {
            $out[] = array_merge(['', ''], array_map('strval', $c['columns']));
        }
        foreach ($c['rows'] as $r) {
            $out[] = array_merge(['', ''], array_map(
                fn($v) => $v === null ? '' : (string)$v, array_values($r)));
        }
    }
    $sheets[$area] = ['rows' => $out, 'widths' => [46, 26, 26, 26, 26, 22, 22, 22, 22]];
}

// ---- the graded shortfalls ------------------------------------------
$rows = [['Area', 'Register', 'Table', 'Column', 'Requirement', 'Rows short', 'Of',
          'Filled %', 'Where it gets fixed']];
foreach ($comp['gaps'] as $g) {
    $rows[] = [$g['area'], $g['register'], $g['table'], $g['column'], $g['need'],
               $g['missing'], $g['of'], $g['pct'], $g['where']];
}
$sheets['Cells To Fill'] = ['rows' => $rows, 'widths' => [16, 32, 38, 18, 14, 10, 10, 9, 40]];

// ---- the rows behind those counts -----------------------------------
// A count is a fact; a named row is a job. Stacked by register, the
// same way the area tabs stack their findings.
$out = [['Every register row with at least one must-fill column blank. Fix the columns '
         . 'named on each row.']];
foreach (completeness_worklists() as $reg) {
    $out[] = [];
    $out[] = [$reg['label'], count($reg['rows']) . ' rows', $reg['where']];
    $out[] = $reg['columns'];
    foreach ($reg['rows'] as $r) {
        $out[] = array_values($r);
    }
}
$sheets['Rows To Fill'] = ['rows' => $out, 'widths' => [30, 34, 22, 18, 24, 24]];

// ---- schema and system gaps -----------------------------------------
$rows = [['Table', 'Column to add', 'Why']];
foreach ($comp['to_add'] as $t) {
    $rows[] = [$t['table'], $t['column'], $t['why']];
}
$sheets['Columns To Add'] = ['rows' => $rows, 'widths' => [40, 22, 90]];

$rows = [['Section', 'What', 'Description', 'Why', 'Roughly', 'Waiting on']];
foreach (APP_BACKLOG as $section => $items) {
    foreach ($items as [$title, $what, $why, $size, $depends]) {
        $rows[] = [$section, $title, $what, $why, APP_BACKLOG_SIZE[$size] ?? $size, $depends];
    }
}
$sheets['Systems To Build'] = ['rows' => $rows, 'widths' => [20, 34, 60, 60, 16, 40]];

// ---- every table, accounted for -------------------------------------
$rows = [['Database', 'Table', 'Rows', 'Columns', 'Kind', 'What that means']];
foreach ($comp['accounting'] as $a) {
    $rows[] = [$a['database'], $a['table'], $a['rows'], $a['columns'],
               $a['class'], $a['note']];
}
$sheets['Every Table'] = ['rows' => $rows, 'widths' => [26, 30, 9, 9, 13, 68]];

// ---- the full matrix ------------------------------------------------
$rows = [['Database', 'Table', 'Column', 'Type', 'Rows', 'Filled', 'Filled %', 'Kind']];
foreach ($comp['matrix'] as $m) {
    $rows[] = [$m['database'], $m['table'], $m['column'], $m['type'], $m['rows'],
               $m['filled'], $m['pct'] === null ? '' : $m['pct'], $m['class']];
}
$sheets['Full Matrix'] = ['rows' => $rows, 'widths' => [26, 30, 24, 12, 9, 9, 9, 13]];

// ---- coverage -------------------------------------------------------
$cov = [['What is being measured', 'Filled in', 'Out of', 'Coverage %']];
foreach ($coverage as $label => $m) {
    if ($label === '_errors') {
        continue;
    }
    $cov[] = [$label, $m['have'], $m['total'],
              !empty($m['failed']) ? 'could not be measured' : $m['pct']];
}
$sheets['Coverage'] = ['rows' => $cov, 'widths' => [52, 12, 12, 20]];

xlsx_send('PRI-facilities-data-audit-' . date('Y-m-d') . '.xlsx', $sheets);
