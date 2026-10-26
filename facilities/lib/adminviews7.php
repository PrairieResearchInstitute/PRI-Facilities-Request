<?php
declare(strict_types=1);

require_once __DIR__ . '/purchase.php';

/**
 * Purchase administration.
 *
 * The front end asks the two people each account needs and then Finance.
 * That works right up until it does not: an investigator who has left the
 * university, a fund manager who was never in the directory, an order
 * raised weeks ago that nobody has marked as received. None of that is a
 * decision - it is administration - and this is where it is done.
 *
 * The list is not the point. The point is the four numbers at the top,
 * which say where everything is stuck, and the actions on one request,
 * which are how it gets unstuck.
 */
function av_purchases(): void
{
    $status = trim((string)($_GET['pstatus'] ?? ''));
    $survey = trim((string)($_GET['psurvey'] ?? ''));
    $q = trim((string)($_GET['pq'] ?? ''));
    $waiting = ($_GET['waiting'] ?? '') === '1';
    $open = (int)($_GET['pr'] ?? 0);

    av_purchase_summary();

    $where = [];
    $params = [];
    if ($status !== '') {
        $where[] = 'r.Status = ?';
        $params[] = $status;
    }
    if ($survey !== '') {
        $where[] = 'r.SurveyCode = ?';
        $params[] = $survey;
    }
    if ($q !== '') {
        $where[] = '(r.TicketNumber LIKE ? OR r.VendorName LIKE ? OR r.StaffRequesting LIKE ?
                     OR r.BusinessJustification LIKE ? OR r.LegacyReference LIKE ?
                     OR r.OrderReference LIKE ?)';
        array_push($params, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%", "%$q%");
    }
    if ($waiting) {
        $where[] = "r.Status IN ('submitted', 'fund_review', 'finance_review')";
    }
    $clause = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $sql = "SELECT r.*,
                (SELECT COUNT(*) * 2 FROM rq.PurchaseFunding f
                  WHERE f.PurchaseRequestID = r.PurchaseRequestID) AS SeatsNeeded,
                (SELECT COUNT(*) FROM rq.PurchaseFunding f
                  WHERE f.PurchaseRequestID = r.PurchaseRequestID
                    AND f.PiStatus = 'approved') +
                (SELECT COUNT(*) FROM rq.PurchaseFunding f
                  WHERE f.PurchaseRequestID = r.PurchaseRequestID
                    AND f.FmStatus = 'approved') AS SeatsIn,
                (SELECT COUNT(*) FROM rq.PurchaseFunding f
                  WHERE f.PurchaseRequestID = r.PurchaseRequestID
                    AND ((f.PiStatus = 'pending' AND ISNULL(f.PiNetID, '') = '')
                      OR (f.FmStatus = 'pending' AND ISNULL(f.FmNetID, '') = ''))) AS Unreachable,
                (SELECT COUNT(*) FROM rq.PurchaseItems i
                  WHERE i.PurchaseRequestID = r.PurchaseRequestID AND i.IsEquipment = 1
                    AND ISNULL(i.PropertyTag, '') = '') AS Untagged,
                DATEDIFF(day, r.UpdatedAt, SYSDATETIME()) AS DaysStill
             FROM rq.PurchaseRequests r" . $clause . ' ORDER BY r.CreatedAt DESC';
    $stmt = db('purchasing')->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $sum = db('purchasing')->prepare(
        "SELECT COUNT(*) AS n, ISNULL(SUM(TotalCost), 0) AS c FROM rq.PurchaseRequests r" . $clause);
    $sum->execute($params);
    $tot = $sum->fetch();

    echo '<div class="panel"><h2>Purchase Requests</h2>';
    echo '<form method="get" class="filters"><input type="hidden" name="section" value="purchases">';
    echo '<label>Search <input name="pq" value="' . h($q)
       . '" placeholder="ticket, vendor, buyer, order"></label>';
    echo '<label>Status ' . picklist_select('purchase_status', 'pstatus', $status,
            ['blank' => 'Any status']) . '</label>';
    echo '<label>Survey <input name="psurvey" size="6" value="' . h($survey) . '"></label>';
    echo '<label class="check"><input type="checkbox" name="waiting" value="1"'
       . ($waiting ? ' checked' : '') . '> Only ones still waiting</label>';
    echo '<button class="primary">Show</button> <a href="admin.php?section=purchases">Clear</a>';
    echo '</form>';
    echo '<p><strong>' . (int)$tot['n'] . '</strong> purchase' . ((int)$tot['n'] === 1 ? '' : 's')
       . ' shown, $' . number_format((float)$tot['c'], 2) . ' in total. Download as '
       . '<a href="export.php?tab=purchase">items</a> or '
       . '<a href="export.php?tab=purchasefunding">funding and approvals</a>.</p>';
    echo '</div>';

    if (!$rows) {
        echo '<div class="panel"><p>Nothing matches.</p></div>';
        return;
    }

    echo '<div class="panel scroll-x"><table>';
    echo '<tr><th>Ticket</th><th>Buyer</th><th>Vendor</th><th>Total</th><th>Approvals</th>'
       . '<th>Status</th><th>Still</th><th>Equipment</th><th></th></tr>';
    foreach ($rows as $r) {
        $need = (int)$r['SeatsNeeded'];
        $in = (int)$r['SeatsIn'];
        $stuck = (int)$r['DaysStill'];
        $waitingNow = in_array($r['Status'], ['submitted', 'fund_review', 'finance_review'], true);
        echo '<tr>';
        echo '<td><a href="purchase_view.php?id=' . (int)$r['PurchaseRequestID'] . '">'
           . h((string)$r['TicketNumber']) . '</a>'
           . ($r['OrderReference'] ? '<br><span class="muted">'
              . h((string)$r['OrderReference']) . '</span>' : '') . '</td>';
        echo '<td>' . h((string)$r['StaffRequesting'])
           . ($r['SurveyCode'] ? '<br><span class="muted">' . h((string)$r['SurveyCode'])
              . '</span>' : '') . '</td>';
        echo '<td>' . h((string)$r['VendorName']) . '</td>';
        echo '<td>$' . number_format((float)$r['TotalCost'], 2) . '</td>';
        echo '<td class="' . ($need > 0 && $in >= $need ? 'good' : '') . '">' . $in . ' of ' . $need
           . ((int)$r['Unreachable'] > 0
              ? '<br><span class="bad">' . (int)$r['Unreachable'] . ' with nobody to ask</span>'
              : '') . '</td>';
        echo '<td>' . h(picklist_label('purchase_status', (string)$r['Status'])) . '</td>';
        echo '<td class="' . ($waitingNow && $stuck > 14 ? 'bad' : 'muted') . '">'
           . $stuck . 'd</td>';
        echo '<td>' . ((int)$r['Untagged'] > 0
            ? '<a href="admin.php?section=expected">' . (int)$r['Untagged'] . ' untagged</a>'
            : ((int)$r['HasEquipment'] === 1 ? '<span class="good">tagged</span>' : '')) . '</td>';
        echo '<td><a href="admin.php?section=purchases&amp;pr='
           . (int)$r['PurchaseRequestID'] . '#pr">Act on it</a>'
           . '<br><a href="purchase_reconcile.php?id=' . (int)$r['PurchaseRequestID'] . '" '
           . 'target="_blank" rel="noopener">Reconcile</a></td>';
        echo '</tr>';
    }
    echo '</table></div>';

    if ($open > 0) {
        $req = purchase_get($open);
        if ($req) {
            av_purchase_actions($req);
        }
    }
}

/** Where everything is stuck, in four numbers and one list. */
function av_purchase_summary(): void
{
    $pdo = db('purchasing');
    $one = fn(string $sql) => (int)$pdo->query($sql)->fetchColumn();

    $withFunds = $one("SELECT COUNT(*) FROM rq.PurchaseRequests WHERE Status IN
                       ('submitted', 'fund_review')");
    $withFinance = $one("SELECT COUNT(*) FROM rq.PurchaseRequests WHERE Status = 'finance_review'");
    $notOrdered = $one("SELECT COUNT(*) FROM rq.PurchaseRequests WHERE Status = 'approved'");
    $notReceived = $one("SELECT COUNT(*) FROM rq.PurchaseRequests WHERE Status = 'ordered'");
    $untagged = $one("SELECT COUNT(*) FROM rq.PurchaseItems i
                       JOIN rq.PurchaseRequests r ON r.PurchaseRequestID = i.PurchaseRequestID
                      WHERE i.IsEquipment = 1 AND ISNULL(i.PropertyTag, '') = ''
                        AND r.Status IN ('approved', 'ordered', 'received')");

    // A seat with no NetID is a seat nobody will ever answer. It is the
    // single most common way a purchase dies quietly, so it is counted
    // separately and named.
    $unreachable = $pdo->query(
        "SELECT r.PurchaseRequestID, r.TicketNumber, f.FundNo, f.CfopCode,
                f.PiName, f.PiNetID, f.PiStatus, f.FmName, f.FmNetID, f.FmStatus
         FROM rq.PurchaseFunding f
         JOIN rq.PurchaseRequests r ON r.PurchaseRequestID = f.PurchaseRequestID
         WHERE r.Status IN ('submitted', 'fund_review')
           AND ((f.PiStatus = 'pending' AND ISNULL(f.PiNetID, '') = '')
             OR (f.FmStatus = 'pending' AND ISNULL(f.FmNetID, '') = ''))
         ORDER BY r.TicketNumber, f.FundNo")->fetchAll();

    echo '<div class="panel"><h2>Where Purchases Are Sitting</h2>';
    echo '<div class="statrow">';
    foreach ([
        ['With the fund approvers', $withFunds, 'admin.php?section=purchases&pstatus=fund_review'],
        ['With Finance', $withFinance, 'admin.php?section=purchases&pstatus=finance_review'],
        ['Approved, not yet ordered', $notOrdered, 'admin.php?section=purchases&pstatus=approved'],
        ['Ordered, not yet received', $notReceived, 'admin.php?section=purchases&pstatus=ordered'],
        ['Equipment without a number', $untagged, 'admin.php?section=expected'],
    ] as [$label, $n, $href]) {
        echo '<a class="stat" href="' . h($href) . '">'
           . '<span class="statn">' . $n . '</span>'
           . '<span class="statl">' . h($label) . '</span></a>';
    }
    echo '</div>';

    if ($unreachable) {
        echo '<h3 class="bad">' . count($unreachable) . ' fund line'
           . (count($unreachable) === 1 ? '' : 's') . ' with nobody to ask</h3>';
        echo '<p class="muted">The account directory has a name but no NetID for these, so the '
           . 'request is waiting on somebody it cannot reach. Either load a fresher grants '
           . 'export, or record the decision here on their behalf.</p>';
        echo '<div class="scroll-x"><table>';
        echo '<tr><th>Ticket</th><th>Account</th><th>Who is missing</th><th></th></tr>';
        foreach ($unreachable as $u) {
            $missing = [];
            if ($u['PiStatus'] === 'pending' && trim((string)$u['PiNetID']) === '') {
                $missing[] = 'principal investigator'
                    . ($u['PiName'] ? ' (' . h((string)$u['PiName']) . ')' : ', unnamed');
            }
            if ($u['FmStatus'] === 'pending' && trim((string)$u['FmNetID']) === '') {
                $missing[] = 'fund manager'
                    . ($u['FmName'] ? ' (' . h((string)$u['FmName']) . ')' : ', unnamed');
            }
            echo '<tr><td><a href="purchase_view.php?id=' . (int)$u['PurchaseRequestID'] . '">'
               . h((string)$u['TicketNumber']) . '</a></td>'
               . '<td>' . h((string)$u['CfopCode']) . '</td>'
               . '<td>' . implode(', ', $missing) . '</td>'
               . '<td><a href="admin.php?section=purchases&amp;pr='
               . (int)$u['PurchaseRequestID'] . '#pr">Act on it</a></td></tr>';
        }
        echo '</table></div>';
    }
    echo '</div>';
}

/**
 * Everything an administrator can do to one purchase without pretending
 * to be one of its approvers.
 */
function av_purchase_actions(array $req): void
{
    $id = (int)$req['PurchaseRequestID'];
    $funds = purchase_funds($id);
    $may = has_function(['finance', 'facilities', 'executive', 'deputy']);

    echo '<div class="panel" id="pr">';
    echo '<h2>' . h((string)$req['TicketNumber']) . '</h2>';
    echo '<p class="muted">' . h((string)$req['StaffRequesting'])
       . ' &middot; ' . h((string)$req['VendorName'])
       . ' &middot; $' . number_format((float)$req['TotalCost'], 2)
       . ' &middot; ' . h(picklist_label('purchase_status', (string)$req['Status']))
       . ' &middot; <a href="purchase_view.php?id=' . $id . '">the whole request</a>'
       . ' &middot; <a href="purchase_reconcile.php?id=' . $id . '" target="_blank" '
       . 'rel="noopener">reconciliation blocks</a></p>';

    if (!$may) {
        echo '<p class="muted">Acting on a purchase belongs to Finance or facilities.</p>';
        echo '</div>';
        return;
    }

    // 1. Move it along.
    echo '<h3>Move It Along</h3>';
    echo '<div class="grid">';
    if (in_array($req['Status'], ['approved', 'ordered'], true)) {
        echo '<form method="post">' . csrf_field()
           . '<input type="hidden" name="action" value="pur_order">'
           . '<input type="hidden" name="pr_id" value="' . $id . '">'
           . '<label>Order or requisition reference <input name="order_reference" value="'
           . h((string)$req['OrderReference']) . '" placeholder="PO or I-Buy number"></label>'
           . '<div class="actions"><button class="primary">Record the Order</button></div>'
           . '</form>';
    }
    if (in_array($req['Status'], ['ordered', 'approved'], true)) {
        echo '<form method="post">' . csrf_field()
           . '<input type="hidden" name="action" value="pur_status">'
           . '<input type="hidden" name="pr_id" value="' . $id . '">'
           . '<input type="hidden" name="to" value="received">'
           . '<label>Note <input name="note" placeholder="who signed for it"></label>'
           . '<div class="actions"><button>Everything Has Arrived</button></div>'
           . '</form>';
    }
    if (!in_array($req['Status'], ['closed', 'denied'], true)) {
        echo '<form method="post">' . csrf_field()
           . '<input type="hidden" name="action" value="pur_status">'
           . '<input type="hidden" name="pr_id" value="' . $id . '">'
           . '<input type="hidden" name="to" value="closed">'
           . '<label>Note <input name="note" placeholder="why it is finished"></label>'
           . '<div class="actions"><button>Close It</button></div>'
           . '</form>';
        echo '<form method="post">' . csrf_field()
           . '<input type="hidden" name="action" value="pur_status">'
           . '<input type="hidden" name="pr_id" value="' . $id . '">'
           . '<input type="hidden" name="to" value="denied">'
           . '<label>Why <input name="note" required placeholder="required"></label>'
           . '<div class="actions"><button class="danger">Cancel It</button></div>'
           . '</form>';
    }
    echo '</div>';

    // 2. Nudge, when it is only waiting on somebody reading their email.
    if (in_array($req['Status'], ['submitted', 'fund_review', 'finance_review'], true)) {
        echo '<h3>Nobody Has Answered</h3>';
        echo '<p class="muted">Sends the same notice again to whoever is still being waited on. '
           . 'It does not change anything.</p>';
        echo '<form method="post">' . csrf_field()
           . '<input type="hidden" name="action" value="pur_nudge">'
           . '<input type="hidden" name="pr_id" value="' . $id . '">'
           . '<div class="actions"><button>Ask Them Again</button></div></form>';
    }

    // 3. Repair the seats. This is the part that actually unsticks things.
    $repairable = array_values(array_filter($funds, fn($f) =>
        $f['PiStatus'] === 'pending' || $f['FmStatus'] === 'pending'));
    if ($repairable) {
        echo '<h3>Who Is Being Waited On</h3>';
        echo '<p class="muted">Where the directory has no NetID, or the person has left, put the '
           . 'right one in. Changing it re-asks that seat. Recording a decision on somebody&rsquo;s '
           . 'behalf is written down as exactly that, with your name on it.</p>';
        echo '<div class="scroll-x"><table>';
        echo '<tr><th>#</th><th>Account</th><th>Seat</th><th>Name</th><th>NetID</th>'
           . '<th>Put it right</th><th>Or answer for them</th></tr>';
        foreach ($repairable as $f) {
            foreach ([['pi', 'Principal investigator', 'PiName', 'PiNetID', 'PiStatus'],
                      ['fm', 'Fund manager', 'FmName', 'FmNetID', 'FmStatus']] as
                     [$seat, $label, $nameCol, $netCol, $statusCol]) {
                if ($f[$statusCol] !== 'pending') {
                    continue;
                }
                $fid = (int)$f['PurchaseFundingID'];
                echo '<tr>';
                echo '<td>' . (int)$f['FundNo'] . '</td>';
                echo '<td>' . h((string)$f['CfopCode']) . '</td>';
                echo '<td>' . h($label) . '</td>';
                echo '<td>' . h((string)($f[$nameCol] ?: '—')) . '</td>';
                echo '<td' . (trim((string)$f[$netCol]) === '' ? ' class="bad"' : '') . '>'
                   . h((string)($f[$netCol] ?: 'nobody')) . '</td>';
                echo '<td><form method="post" class="inline">' . csrf_field()
                   . '<input type="hidden" name="action" value="pur_seat">'
                   . '<input type="hidden" name="pr_id" value="' . $id . '">'
                   . '<input type="hidden" name="funding_id" value="' . $fid . '">'
                   . '<input type="hidden" name="seat" value="' . $seat . '">'
                   . '<input name="person" size="18" data-typeahead="person" '
                   . 'data-fill="netid:seatnet_' . $fid . '_' . $seat . '" '
                   . 'placeholder="the right person" autocomplete="off">'
                   . '<input type="hidden" name="person_netid" id="seatnet_' . $fid . '_'
                   . $seat . '">'
                   . '<button>Set</button></form></td>';
                echo '<td><form method="post" class="inline">' . csrf_field()
                   . '<input type="hidden" name="action" value="pur_onbehalf">'
                   . '<input type="hidden" name="pr_id" value="' . $id . '">'
                   . '<input type="hidden" name="funding_id" value="' . $fid . '">'
                   . '<input type="hidden" name="seat" value="' . $seat . '">'
                   . picklist_select('approval_status', 'decision', 'approved')
                   . '<input name="comment" size="16" required '
                   . 'placeholder="how you know" autocomplete="off">'
                   . '<button>Record</button></form></td>';
                echo '</tr>';
            }
        }
        echo '</table></div>';
    }

    echo '<p><a href="admin.php?section=purchases">Close this one</a></p>';
    echo '</div>';

    echo '<div class="panel scroll-x"><h2>Timeline</h2><table>';
    echo '<tr><th>When</th><th>What</th><th>Who</th></tr>';
    foreach (purchase_timeline($id) as $e) {
        echo '<tr><td>' . h(date('M j, Y g:ia', strtotime((string)$e['CreatedAt']))) . '</td>'
           . '<td>' . h((string)$e['Detail']) . '</td>'
           . '<td>' . h((string)($e['ByName'] ?: $e['ByNetID'])) . '</td></tr>';
    }
    echo '</table></div>';
}
