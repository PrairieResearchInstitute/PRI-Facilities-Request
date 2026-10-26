<?php
declare(strict_types=1);

require_once __DIR__ . '/mileage.php';

/**
 * Mileage, from the fleet office's side: which vehicles have gone quiet,
 * which readings look wrong, and whether reading the number off the
 * photograph is worth keeping.
 */
function av_mileage(): void
{
    $q = trim((string)($_GET['mq'] ?? ''));
    $survey = trim((string)($_GET['msurvey'] ?? ''));
    $stale = (int)setting('mileage_stale_days', '90');
    $vehicles = mileage_fleet($q, $survey);

    $never = 0;
    $old = 0;
    foreach ($vehicles as $v) {
        if ($v['LastMilesOn'] === null) {
            $never++;
        } elseif ((int)$v['DaysSince'] > $stale) {
            $old++;
        }
    }
    $queried = mileage_queried(20);

    echo '<div class="panel"><h2>Mileage</h2>';
    echo '<div class="statrow">';
    foreach ([
        ['Vehicles', count($vehicles), ''],
        ['Never read', $never, ''],
        ['Not read in ' . $stale . ' days', $old, ''],
        ['Readings queried', count($queried), ''],
    ] as [$label, $n, $href]) {
        echo '<span class="stat"><span class="statn">' . $n . '</span>'
           . '<span class="statl">' . h($label) . '</span></span>';
    }
    echo '</div>';
    echo '<p><a class="btn primary" href="stickers.php">Print the Vehicle Stickers</a> '
       . '<span class="muted">one per cab; scanning it opens that vehicle&rsquo;s page</span></p>';

    // Whether the suggestion is worth offering, which is a question only
    // the record can answer.
    $rec = mileage_suggestion_record();
    if ($rec['offered'] > 0) {
        echo '<p class="muted">The photograph offered a number on <strong>' . $rec['offered']
           . '</strong> reading' . ($rec['offered'] === 1 ? '' : 's') . ' and had it exactly right '
           . '<strong>' . $rec['matched'] . '</strong> time' . ($rec['matched'] === 1 ? '' : 's')
           . ($rec['percent'] !== null ? ' (' . $rec['percent'] . '%)' : '')
           . '. It is only ever a suggestion; somebody presses the button either way.</p>';
    } else {
        echo '<p class="muted">Nobody has used the read-from-photograph button yet, so there is '
           . 'nothing to say about whether it works. It only ever suggests a number.</p>';
    }
    echo '</div>';

    if ($queried) {
        echo '<div class="panel scroll-x"><h2>Readings Worth A Look</h2>';
        echo '<p class="muted">Accepted and flagged, not refused. Somebody drove a long way, or '
           . 'somebody typed a digit twice.</p><table>';
        echo '<tr><th>Vehicle</th><th>Miles</th><th>When</th><th>Who</th><th>Why</th></tr>';
        foreach ($queried as $r) {
            echo '<tr class="warn">';
            echo '<td><a href="m.php?v=' . h(urlencode((string)$r['FleetNumber'])) . '">'
               . h((string)$r['FleetNumber']) . '</a></td>';
            echo '<td>' . number_format((int)$r['Miles']) . '</td>';
            echo '<td>' . h(date('M j, Y', strtotime((string)$r['ReadOn']))) . '</td>';
            echo '<td>' . h((string)($r['ReadByName'] ?: $r['ReadByNetID'])) . '</td>';
            echo '<td>' . h((string)$r['QueryNote']) . '</td>';
            echo '</tr>';
        }
        echo '</table></div>';
    }

    echo '<div class="panel"><h2>The Fleet</h2>';
    echo '<form method="get" class="filters"><input type="hidden" name="section" value="mileage">';
    echo '<label>Search <input name="mq" value="' . h($q) . '" placeholder="number, plate, make"></label>';
    echo '<label>Survey <input name="msurvey" size="6" value="' . h($survey) . '"></label>';
    echo '<button class="primary">Show</button> <a href="admin.php?section=mileage">Clear</a>';
    echo '</form></div>';

    echo '<div class="panel scroll-x"><table>';
    echo '<tr><th>Fleet number</th><th>Vehicle</th><th>Survey</th><th>Last reading</th>'
       . '<th>When</th><th></th></tr>';
    foreach ($vehicles as $v) {
        $days = $v['LastMilesOn'] !== null ? (int)$v['DaysSince'] : null;
        echo '<tr' . ($days === null || $days > $stale ? ' class="warn"' : '') . '>';
        echo '<td><strong>' . h((string)$v['FleetNumber']) . '</strong></td>';
        echo '<td>' . h(trim((string)$v['VehicleYear'] . ' ' . (string)$v['Make'] . ' '
            . (string)$v['Model']) ?: (string)$v['Description']) . '</td>';
        echo '<td>' . h((string)$v['SurveyCode']) . '</td>';
        echo '<td>' . ($v['LastMiles'] !== null
            ? number_format((int)$v['LastMiles'])
            : '<span class="muted">none</span>') . '</td>';
        echo '<td>' . ($days === null
            ? '<span class="muted">never</span>'
            : h(date('M j, Y', strtotime((string)$v['LastMilesOn']))) . ' <span class="muted">('
              . $days . 'd)</span>') . '</td>';
        echo '<td><a href="m.php?v=' . h(urlencode((string)$v['FleetNumber']))
           . '">Record one</a></td>';
        echo '</tr>';
    }
    echo '</table></div>';
}
