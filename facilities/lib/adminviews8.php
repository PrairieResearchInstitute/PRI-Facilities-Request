<?php
declare(strict_types=1);

require_once __DIR__ . '/property.php';

/**
 * The property office's own screen: what people have reported about the
 * inventory, and the two buttons that settle each one.
 *
 * The point of the queue is that it is short. Somebody who holds the
 * inventory files and applies in one motion, so what lands here is what
 * was noticed by everybody else - the people who actually watch equipment
 * move - and applying it is a read and a click.
 */
function av_property(): void
{
    $pending = property_pending();
    $may = has_function(['equipment', 'inventory']);
    $open = (int)($_GET['pu'] ?? 0);

    echo '<div class="panel"><h2>Property Updates</h2>';
    echo '<p class="muted">One request covers every change to a property record: something '
       . 'arrived, moved, changed hands, was recorded wrongly, or left. Applying it writes the '
       . 'inventory and adds a line to that item&rsquo;s history.</p>';

    if (!$pending) {
        echo '<p class="good">Nothing is waiting.</p>';
    } else {
        $oldest = strtotime((string)$pending[0]['CreatedAt']);
        $days = (int)floor((time() - $oldest) / 86400);
        echo '<p><strong>' . count($pending) . '</strong> waiting'
           . ($days > 0 ? ', the oldest for ' . $days . ' day' . ($days === 1 ? '' : 's') : '')
           . '.</p>';
    }
    if (!$may) {
        echo '<p class="muted">You can see what is waiting. Applying it belongs to the property '
           . 'inventory.</p>';
    }
    echo '<p><a href="new_property.php">File a property update</a> &middot; '
       . '<a href="admin.php?section=expected">Property awaiting a number</a></p>';
    echo '</div>';

    if ($pending) {
        echo '<div class="panel scroll-x"><table>';
        echo '<tr><th>Ticket</th><th>Filed</th><th>By</th><th>What happened</th><th>Item</th>'
           . '<th>Says</th><th></th></tr>';
        foreach ($pending as $r) {
            echo '<tr>';
            echo '<td>' . h((string)$r['TicketNumber']) . '</td>';
            echo '<td>' . h(date('M j', strtotime((string)$r['CreatedAt']))) . '</td>';
            echo '<td>' . h((string)$r['RequestedByNetID']) . '</td>';
            echo '<td>' . h(property_kind_label((string)$r['ChangeKind'])) . '</td>';
            echo '<td>' . h((string)($r['PropertyTag'] ?: '(no number yet)'))
               . '<br><span class="muted">'
               . h((string)($r['ItemDescription'] ?: $r['Description'])) . '</span></td>';
            echo '<td>' . h(av_property_says($r)) . '</td>';
            echo '<td><a href="admin.php?section=property&amp;pu='
               . (int)$r['PropertyUpdateID'] . '#pu">'
               . ($may ? 'Look at it' : 'Details') . '</a></td>';
            echo '</tr>';
        }
        echo '</table></div>';
    }

    // One open at a time, with everything about it on the screen, because
    // deciding needs the whole thing rather than a row of it.
    if ($open > 0) {
        $r = property_get($open);
        if ($r && $r['Status'] === 'submitted') {
            av_property_one($r, $may);
        }
    }

    $settled = property_settled(25);
    if ($settled) {
        echo '<div class="panel scroll-x"><h2>Recently Settled</h2><table>';
        echo '<tr><th>Ticket</th><th>When</th><th>What happened</th><th>Item</th>'
           . '<th>Outcome</th><th>By</th><th>Note</th></tr>';
        foreach ($settled as $r) {
            echo '<tr>';
            echo '<td>' . h((string)$r['TicketNumber']) . '</td>';
            echo '<td>' . h($r['AppliedAt']
                ? date('M j, Y', strtotime((string)$r['AppliedAt'])) : '') . '</td>';
            echo '<td>' . h(property_kind_label((string)$r['ChangeKind'])) . '</td>';
            echo '<td>' . h((string)($r['PropertyTag'] ?: $r['ItemDescription']
                                     ?: $r['Description'])) . '</td>';
            echo '<td class="' . ($r['Status'] === 'applied' ? 'good' : 'bad') . '">'
               . h((string)$r['Status']) . '</td>';
            echo '<td>' . h((string)$r['AppliedByNetID']) . '</td>';
            echo '<td>' . h((string)$r['DecisionNote']) . '</td>';
            echo '</tr>';
        }
        echo '</table></div>';
    }
}

/** The change in one phrase, for the row it sits on. */
function av_property_says(array $r): string
{
    return match ((string)$r['ChangeKind']) {
        'register' => 'number ' . (string)$r['PropertyTag']
                    . ((string)$r['ToRoomCode'] !== '' ? ', into ' . (string)$r['ToRoomCode'] : ''),
        'move'     => 'now in ' . (string)($r['ToRoomCode']
                        ?: trim((string)$r['ToBldgNumber'] . ' ' . (string)$r['ToRoomID'])),
        'reassign' => 'now held by ' . (string)$r['ToPerson'],
        'correct'  => 'the record is wrong',
        default    => picklist_label('removal_reason', (string)$r['RemovalReason'])
                    . ((string)$r['RemovedOn'] !== '' ? ' on ' . (string)$r['RemovedOn'] : ''),
    };
}

/** One request, laid out beside the record it wants to change. */
function av_property_one(array $r, bool $may): void
{
    $item = $r['InventoryID'] ? property_item((int)$r['InventoryID']) : null;

    echo '<div class="panel" id="pu">';
    echo '<h2>' . h((string)$r['TicketNumber']) . ' &mdash; '
       . h(property_kind_label((string)$r['ChangeKind'])) . '</h2>';
    echo '<p class="muted">Filed by ' . h((string)$r['RequestedByNetID'])
       . ' on ' . h(date('M j, Y', strtotime((string)$r['CreatedAt'])))
       . ((string)$r['SurveyCode'] !== '' ? ' &middot; ' . h((string)$r['SurveyCode']) : '')
       . '</p>';
    if ($r['Reason']) {
        echo '<p>' . nl2br(h((string)$r['Reason'])) . '</p>';
    }

    // Now against proposed, side by side, because the whole judgement is
    // "is the new one righter than the old one".
    $rows = [];
    $add = function (string $label, $now, $then) use (&$rows) {
        $now = trim((string)$now);
        $then = trim((string)$then);
        if ($then !== '' && $then !== $now) {
            $rows[] = [$label, $now, $then];
        }
    };
    $add('Inventory number', $item['PropertyTag'] ?? '', $r['PropertyTag']);
    $add('What it is', $item['Description'] ?? '', $r['Description']);
    $add('Make and model', $item['Model'] ?? '', $r['Model']);
    $add('Serial number', $item['SerialNumber'] ?? '', $r['SerialNumber']);
    $add('Kind of thing', picklist_label('equip_class', (string)($item['EquipClass'] ?? '')),
         $r['EquipClass'] ? picklist_label('equip_class', (string)$r['EquipClass']) : '');
    $add('Condition', $item['Condition'] ?? '',
         $r['ConditionCode'] ? picklist_label('equip_condition', (string)$r['ConditionCode']) : '');
    $add('Cost', $item && $item['Cost'] !== null ? number_format((float)$item['Cost'], 2) : '',
         $r['Cost'] !== null ? number_format((float)$r['Cost'], 2) : '');
    $add('Acquired', $item['AcquiredOn'] ?? '', $r['AcquiredOn']);
    $add('Bought on', $item['FundingSource'] ?? '', $r['FundingSource']);
    $add('Room', $item['RoomCode'] ?? '', $r['ToRoomCode']
         ?: trim((string)$r['ToBldgNumber'] . ' ' . (string)$r['ToRoomID']));
    $add('Held by', $item['Person'] ?? '', $r['ToPerson']);
    $add('Survey', $item['SurveyCode'] ?? '', $r['SurveyCode']);
    if ((string)$r['ChangeKind'] === 'remove') {
        $add('Status', $item['Status'] ?? '',
             picklist_label('removal_reason', (string)$r['RemovalReason']));
        $add('Left on', $item['RemovedOn'] ?? '', $r['RemovedOn']);
        $add('Salvage value', '',
             $r['SalvageValue'] !== null ? number_format((float)$r['SalvageValue'], 2) : '');
    }

    if ($rows) {
        echo '<div class="scroll-x"><table>';
        echo '<tr><th></th><th>The record says</th><th>This request says</th></tr>';
        foreach ($rows as [$label, $now, $then]) {
            echo '<tr><th>' . h($label) . '</th>'
               . '<td class="muted">' . h($now !== '' ? $now : '(blank)') . '</td>'
               . '<td><strong>' . h($then) . '</strong></td></tr>';
        }
        echo '</table></div>';
    } else {
        echo '<p class="bad">This request does not change anything on the record.</p>';
    }

    if ($r['PurchaseItemID']) {
        echo '<p class="muted">Applying this also closes the equipment expectation on the '
           . 'purchase it came from.</p>';
    }

    if ($may) {
        echo '<div class="grid">';
        echo '<form method="post">' . csrf_field()
           . '<input type="hidden" name="action" value="property_apply">'
           . '<input type="hidden" name="pu_id" value="' . (int)$r['PropertyUpdateID'] . '">'
           . '<label>Note <input name="note" placeholder="optional"></label>'
           . '<div class="actions"><button class="primary">Apply It to the Inventory</button></div>'
           . '</form>';
        echo '<form method="post">' . csrf_field()
           . '<input type="hidden" name="action" value="property_decline">'
           . '<input type="hidden" name="pu_id" value="' . (int)$r['PropertyUpdateID'] . '">'
           . '<label>Why not <input name="note" required '
           . 'placeholder="so they know what to do instead"></label>'
           . '<div class="actions"><button class="danger">Decline It</button></div>'
           . '</form>';
        echo '</div>';
    }
    echo '<p><a href="admin.php?section=property">Close this one</a></p>';
    echo '</div>';

    if ($item) {
        $hist = property_history((int)$item['InventoryID']);
        echo '<div class="panel scroll-x"><h2>What Has Happened to This Item</h2>';
        if (!$hist) {
            echo '<p class="muted">Nothing recorded yet.</p>';
        } else {
            echo '<table><tr><th>When</th><th>What</th><th>Detail</th><th>Who</th></tr>';
            foreach ($hist as $e) {
                echo '<tr><td>' . h(date('M j, Y', strtotime((string)$e['CreatedAt']))) . '</td>'
                   . '<td>' . h((string)$e['EventCode']) . '</td>'
                   . '<td>' . h((string)$e['Detail']) . '</td>'
                   . '<td>' . h((string)$e['ByNetID']) . '</td></tr>';
            }
            echo '</table>';
        }
        echo '</div>';
    }
}
