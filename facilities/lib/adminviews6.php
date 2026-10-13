<?php
declare(strict_types=1);

require_once __DIR__ . '/purchase.php';

/**
 * Property awaiting a number.
 *
 * When a purchase line is ticked as equipment, the university will want
 * an inventory number on it the moment it arrives. Today that is a person
 * being told by another person that a box turned up, and a spreadsheet
 * somewhere. Here the tick on the purchase form makes the expectation
 * itself: the item is listed from the day it is approved, with what it
 * is, what it cost, who asked for it and where it was delivered, and the
 * one thing missing is the number.
 *
 * Typing that number is the whole job. It writes the inventory record,
 * puts it in the room the purchase was delivered to, holds it against the
 * person it was bought for, and links it back to the purchase that paid
 * for it - so the account it came off is never guessed at later.
 */
function av_expected(): void
{
    $rows = purchase_expected_equipment();
    $may = has_function(['equipment', 'inventory']);

    echo '<div class="panel"><h2>Property Awaiting a Number</h2>';
    echo '<p class="muted">Every purchase line ticked as equipment, from the day it is approved '
       . 'until the day it is given an inventory number. "It arrived" opens the property update '
       . 'form with the purchase already filled in: the cost, the account it came off and the '
       . 'room it was delivered to all travel with it.</p>';

    if (!$rows) {
        echo '<p class="good">Nothing is waiting. Every piece of equipment bought through a '
           . 'purchase request has its number.</p></div>';
        return;
    }

    // Anything approved a while ago and still untagged is either late or
    // forgotten, and the difference matters to the person chasing it.
    $stale = 0;
    foreach ($rows as $r) {
        if ($r['OrderedAt'] && strtotime((string)$r['OrderedAt']) < strtotime('-45 days')) {
            $stale++;
        }
    }
    $value = 0.0;
    foreach ($rows as $r) {
        $value += (float)$r['UnitCost'] * (float)$r['Quantity'];
    }
    echo '<p><strong>' . count($rows) . '</strong> item' . (count($rows) === 1 ? '' : 's')
       . ' expected, worth $' . number_format($value, 2) . '.';
    if ($stale) {
        echo ' <span class="bad">' . $stale . ' ordered more than 45 days ago and still '
           . 'untagged.</span>';
    }
    echo '</p>';
    if (!$may) {
        echo '<p class="muted">You can see what is expected. Assigning numbers belongs to the '
           . 'property inventory.</p>';
    }
    echo '</div>';

    $pick = (int)($_GET['item'] ?? 0);

    echo '<div class="panel scroll-x"><table>';
    echo '<tr><th>Purchase</th><th>Item</th><th>Qty</th><th>Cost</th><th>For</th>'
       . '<th>Delivered to</th><th>Vendor</th><th>Order</th><th></th></tr>';
    foreach ($rows as $r) {
        $old = $r['OrderedAt'] && strtotime((string)$r['OrderedAt']) < strtotime('-45 days');
        echo '<tr' . ($old ? ' class="warn"' : '') . '>';
        echo '<td><a href="purchase_view.php?id=' . (int)$r['PurchaseRequestID'] . '">'
           . h((string)$r['TicketNumber']) . '</a><br><span class="muted">'
           . h(picklist_label('purchase_status', (string)$r['Status'])) . '</span></td>';
        echo '<td>' . h((string)$r['Description'])
           . ($r['CatalogNumber'] ? '<br><span class="muted">' . h((string)$r['CatalogNumber'])
              . '</span>' : '') . '</td>';
        echo '<td>' . rtrim(rtrim(number_format((float)$r['Quantity'], 2), '0'), '.') . '</td>';
        echo '<td>$' . number_format((float)$r['UnitCost'], 2) . '</td>';
        echo '<td>' . h((string)($r['ExpectedFor'] ?: $r['StaffRequesting'])) . '</td>';
        echo '<td>' . h(trim((string)$r['DeliverBldgNumber'] . ' ' . (string)$r['DeliverRoomID']))
           . '<br><span class="muted">' . h((string)$r['Destination']) . '</span></td>';
        echo '<td>' . h((string)$r['VendorName']) . '</td>';
        echo '<td>' . h((string)$r['OrderReference'])
           . ($r['OrderedAt'] ? '<br><span class="muted">'
              . h(date('M j, Y', strtotime((string)$r['OrderedAt']))) . '</span>' : '') . '</td>';
        echo '<td>' . ($may
            ? '<a href="new_property.php?expected=' . (int)$r['PurchaseItemID']
              . '">It arrived</a>'
            : '') . '</td>';
        echo '</tr>';
    }
    echo '</table></div>';

    if (!$may || $pick <= 0) {
        return;
    }
    $item = null;
    foreach ($rows as $r) {
        if ((int)$r['PurchaseItemID'] === $pick) {
            $item = $r;
        }
    }
    if ($item) {
        header('Location: new_property.php?expected=' . $pick);
        exit;
    }
}
