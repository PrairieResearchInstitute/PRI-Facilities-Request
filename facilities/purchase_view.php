<?php
declare(strict_types=1);

/**
 * A purchase request, and the place its approvals are given.
 *
 * The funding table is the whole of it. Each account carries two
 * decisions - the principal investigator's and the fund manager's - and
 * the request moves only when every one of them is an approval. Anybody
 * looking at this page sees the grid; the rows they are allowed to
 * answer are the ones with a form on them.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/purchase.php';
require_once __DIR__ . '/lib/attachments.php';
require_once __DIR__ . '/lib/access.php';

if (!app_installed()) {
    header('Location: install.php');
    exit;
}
handle_user_switch();
$user = current_user();

$id = (int)($_GET['id'] ?? 0);
$req = purchase_get($id);
if (!$req) {
    ui_top('Purchase Request');
    echo '<div class="panel"><p>No such purchase request.</p>'
       . '<p><a href="index.php">Back to the request types</a></p></div>';
    ui_bottom();
    exit;
}

/* A purchase is yours if you filed it, if it was bought for you, or if
   it is waiting on your decision - and everybody who sees the whole board
   sees it too. Anyone else gets a plain refusal. */
$mineToSee = may_see_all_requests($user)
    || strcasecmp((string)$req['RequestedByNetID'], (string)$user['NetID']) === 0
    || strcasecmp((string)$req['StaffRequestingNetID'], (string)$user['NetID']) === 0;
if (!$mineToSee) {
    foreach (purchase_funds($id) as $f) {
        if (purchase_seat($f, $user) !== '') {
            $mineToSee = true;
        }
    }
}
if (!$mineToSee) {
    http_response_code(403);
    ui_top('Not yours');
    echo '<div class="panel err"><p>That purchase request belongs to somebody else. The ones '
       . 'you filed, and the ones waiting on you, are on '
       . '<a href="board.php?tab=purchase">Open Requests</a>.</p></div>';
    ui_bottom();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'decide') {
        $fundingId = (int)($_POST['funding_id'] ?? 0);
        $fund = null;
        foreach (purchase_funds($id) as $f) {
            if ((int)$f['PurchaseFundingID'] === $fundingId) {
                $fund = $f;
            }
        }
        $seat = $fund ? purchase_seat($fund, $user) : '';
        if (!$fund || $seat === '') {
            flash_set('err', 'That decision is not yours to make.');
        } else {
            $err = purchase_decide($id, $fundingId, $seat,
                (string)($_POST['decision'] ?? ''), (string)($_POST['comment'] ?? ''), $user);
            flash_set($err ? 'err' : 'ok', $err ?: 'Recorded. Thank you.');
        }
        header('Location: purchase_view.php?id=' . $id);
        exit;
    }

    if ($action === 'finance' && has_function(['finance'])) {
        $err = purchase_finance_decide($id, (string)($_POST['decision'] ?? ''),
                                       (string)($_POST['comment'] ?? ''), $user);
        flash_set($err ? 'err' : 'ok', $err ?: 'Recorded. Thank you.');
        header('Location: purchase_view.php?id=' . $id);
        exit;
    }

    if ($action === 'replicate') {
        $made = purchase_replicate($id, $user);
        if (isset($made['error'])) {
            flash_set('err', $made['error']);
            header('Location: purchase_view.php?id=' . $id);
        } else {
            flash_set('ok', 'Copied to ' . $made['ticket'] . '. Its approvals start again.');
            header('Location: purchase_view.php?id=' . $made['id']);
        }
        exit;
    }

    if ($action === 'ordered' && has_function(['finance', 'facilities'])) {
        db('purchasing')->prepare("UPDATE rq.PurchaseRequests SET Status = 'ordered',
                             OrderReference = ?, OrderedAt = SYSDATETIME(), UpdatedAt = SYSDATETIME()
                             WHERE PurchaseRequestID = ?")
            ->execute([trim((string)($_POST['order_reference'] ?? '')) ?: null, $id]);
        purchase_event($id, null, 'ordered',
            'Order raised: ' . (string)($_POST['order_reference'] ?? ''), $user);
        purchase_notify_submitter($id, 'ordered');
        flash_set('ok', 'Marked as ordered.');
        header('Location: purchase_view.php?id=' . $id);
        exit;
    }
}

$items = purchase_items($id);
$funds = purchase_funds($id);
$events = purchase_timeline($id);
$files = attach_list('work', $id, 'purchase');
$statusLabel = picklist_label('purchase_status', (string)$req['Status']);

ui_top('Purchase Request ' . h((string)$req['TicketNumber']),
       '<a href="index.php">&larr; request types</a>');
flash_show();

/** How a decision reads, and how it should look. */
function pv_state(string $s): array
{
    return match ($s) {
        'approved' => ['Approved', 'good'],
        'changes_required' => ['Changes Required', 'bad'],
        default => ['Pending', 'muted'],
    };
}
?>

<div class="panel">
    <h2><?= h((string)$req['TicketNumber']) ?>
        <span class="band band-<?= in_array($req['Status'], ['approved','ordered','received','closed'], true)
            ? 'low' : ($req['Status'] === 'changes_required' ? 'critical' : 'high') ?>">
            <?= h($statusLabel) ?></span></h2>
    <p class="muted">Filed by <?= h((string)$req['RequestedByNetID']) ?>
        on <?= h((string)$req['PurchaseReqDate']) ?>
        &middot; total <strong>$<?= number_format((float)$req['TotalCost'], 2) ?></strong>
        <?= $req['OrderReference'] ? ' &middot; order ' . h((string)$req['OrderReference']) : '' ?></p>
</div>

<div class="panel scroll-x">
    <h2>Buyer</h2>
    <table>
        <tr><th>Deliver to</th><td><?= h((string)$req['StaffRequesting']) ?>
            <?= $req['SurveyCode'] ? ' (' . h((string)$req['SurveyCode']) . ')' : '' ?></td></tr>
        <tr><th>Purchase method</th><td><?= h(picklist_label('purchase_type', (string)$req['PurchaseType'])) ?></td></tr>
        <tr><th>Destination</th><td><?= h((string)$req['Destination']) ?></td></tr>
        <?php if ($req['DeliverBldgNumber']): ?>
        <tr><th>Building and room</th><td><?= h((string)$req['DeliverBldgNumber'] . ' ' . (string)$req['DeliverRoomID']) ?>
            <?= $req['DeliverRoomCode'] ? '<span class="muted"> &middot; ' . h((string)$req['DeliverRoomCode']) . '</span>' : '' ?></td></tr>
        <?php endif; ?>
        <tr><th>Business justification</th><td><?= nl2br(h((string)$req['BusinessJustification'])) ?></td></tr>
        <?php if ($req['AdditionalNotes']): ?>
        <tr><th>Notes</th><td><?= nl2br(h((string)$req['AdditionalNotes'])) ?></td></tr>
        <?php endif; ?>
    </table>

    <h2>Vendor</h2>
    <table>
        <tr><th>Vendor</th><td><?= h((string)$req['VendorName']) ?></td>
            <th>Website</th><td><?= h((string)$req['VendorWeb']) ?></td></tr>
        <tr><th>Address</th><td><?= h(trim((string)$req['VendorAddress1'] . ' ' . (string)$req['VendorAddress2'])) ?></td>
            <th>Phone</th><td><?= h((string)$req['VendorPhone']) ?></td></tr>
        <tr><th>City, state</th><td><?= h(trim((string)$req['VendorCity'] . ' ' . (string)$req['VendorState']
            . ' ' . (string)$req['VendorZip'])) ?></td>
            <th>Notes</th><td><?= h((string)$req['VendorNotes']) ?></td></tr>
    </table>
</div>

<div class="panel">
    <h2>Reconciling the Card Charge</h2>
    <p class="muted">The charge has to be entered again in Emburse Enterprise, broken down by
    Banner account code, with the items typed into a notes box. This purchase already knows every
    line, so it writes those blocks for you &mdash; one per code, in the format the box wants,
    ready to copy.</p>
    <p><a class="btn primary" href="purchase_reconcile.php?id=<?= $id ?>"
          target="_blank" rel="noopener">Reconciliation blocks for Emburse</a>
       <span class="muted">opens in its own window, so it can sit beside Emburse</span></p>
</div>

<div class="panel scroll-x">
    <h2>Items</h2>
    <table>
        <tr><th>#</th><th>Catalog</th><th>Description</th><th>Quantity</th><th>Units/item</th>
            <th>Unit cost</th><th>Amount</th><th>Account code</th><th>Equipment</th>
            <th>Link</th></tr>
        <?php foreach ($items as $i): ?>
        <tr>
            <td><?= (int)$i['ItemNo'] ?></td>
            <td><?= h((string)$i['CatalogNumber']) ?></td>
            <td><?= h((string)$i['Description']) ?></td>
            <td><?= rtrim(rtrim(number_format((float)$i['Quantity'], 2), '0'), '.') ?></td>
            <td><?= h((string)$i['UnitsPerItem']) ?></td>
            <td>$<?= number_format((float)$i['UnitCost'], 2) ?></td>
            <td>$<?= number_format((float)$i['Amount'], 2) ?></td>
            <td><?= h((string)$i['AccountCode']) ?></td>
            <td><?php if ((int)$i['IsEquipment']): ?>
                <?= trim((string)$i['PropertyTag']) !== ''
                    ? '<span class="good">' . h((string)$i['PropertyTag']) . '</span>'
                    : '<span class="muted">awaiting a number</span>' ?>
                <?php endif; ?></td>
            <td><?php if ($i['ProductUrl']): ?>
                <a href="<?= h((string)$i['ProductUrl']) ?>" target="_blank" rel="noopener">View item</a>
                <?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
        <tr><th colspan="6" style="text-align:right">Total</th>
            <th>$<?= number_format((float)$req['TotalCost'], 2) ?></th>
            <th></th><th></th><th></th></tr>
    </table>
</div>

<div class="panel scroll-x">
    <h2>Funding and Approvals</h2>
    <p class="muted">Every account needs both its principal investigator and its fund manager to
    approve. The cost is split <?= h(match ((string)$req['SplitMethod']) {
        'percent' => 'by percentage',
        'item'    => 'by item number',
        default   => 'by dollar amount',
    }) ?>. A principal investigator's administrative assistant may answer for them.</p>
    <table>
        <tr><th>#</th><th>Account</th><th>COA</th><th>Fund</th><th>Org</th><th>Program</th>
            <th>Activity</th><th>Principal investigator</th><th>PI status</th>
            <th>Fund manager</th><th>FM status</th><th>Amount</th></tr>
        <?php foreach ($funds as $f):
            [$piWord, $piClass] = pv_state((string)$f['PiStatus']);
            [$fmWord, $fmClass] = pv_state((string)$f['FmStatus']); ?>
        <tr>
            <td><?= (int)$f['FundNo'] ?></td>
            <td><?= h((string)$f['CfopCode']) ?>
                <div class="muted"><?= h((string)$f['AccountDescription']) ?></div></td>
            <td><?= h((string)$f['Coa']) ?></td>
            <td><?= h((string)$f['FundCode']) ?></td>
            <td><?= h((string)$f['OrgCode']) ?></td>
            <td><?= h((string)$f['ProgramCode']) ?></td>
            <td><?= h((string)$f['ActivityCode']) ?></td>
            <td><?= h((string)$f['PiName']) ?>
                <?php if ($f['PiProxyName']): ?>
                <div class="muted">proxy: <?= h((string)$f['PiProxyName']) ?></div>
                <?php endif; ?></td>
            <td class="<?= $piClass ?>"><?= h($piWord) ?>
                <?php if ($f['PiDecidedByNetID']): ?>
                <div class="muted">by <?= h((string)$f['PiDecidedByNetID']) ?></div>
                <?php endif; ?></td>
            <td><?= h((string)$f['FmName']) ?></td>
            <td class="<?= $fmClass ?>"><?= h($fmWord) ?>
                <?php if ($f['FmDecidedByNetID']): ?>
                <div class="muted">by <?= h((string)$f['FmDecidedByNetID']) ?></div>
                <?php endif; ?></td>
            <td><?= $req['SplitMethod'] === 'percent'
                    ? rtrim(rtrim(number_format((float)$f['AmountValue'], 2), '0'), '.') . '%'
                    : ($req['SplitMethod'] === 'item'
                        ? 'item ' . (int)$f['AmountValue']
                        : '$' . number_format((float)$f['AmountValue'], 2)) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<?php
// The rows this person can answer, if any.
$mine = [];
foreach ($funds as $f) {
    $seat = purchase_seat($f, $user);
    if ($seat !== '' && $f[($seat === 'pi' ? 'Pi' : 'Fm') . 'Status'] === 'pending') {
        $mine[] = [$f, $seat];
    }
}
?>
<?php if ($mine && !in_array($req['Status'], ['closed', 'denied'], true)): ?>
<div class="panel">
    <h2>Waiting on You</h2>
    <?php foreach ($mine as [$f, $seat]): ?>
    <form method="post" class="filters">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="decide">
        <input type="hidden" name="funding_id" value="<?= (int)$f['PurchaseFundingID'] ?>">
        <p><strong><?= h((string)$f['CfopCode']) ?></strong> &mdash; as
           <?= $seat === 'pi' ? 'the principal investigator'
               . (strtolower((string)$user['NetID']) === strtolower((string)$f['PiProxyNetID'])
                   ? ' (acting for ' . h((string)$f['PiName']) . ')' : '')
               : 'the fund manager' ?></p>
        <label>Decision
            <?= picklist_select('approval_status', 'decision', 'approved') ?>
        </label>
        <label>Comments <input name="comment" size="40"></label>
        <button class="primary">Submit purchase approval status</button>
    </form>
    <?php endforeach; ?>
    <p class="muted">Choosing <strong>Changes Required</strong> sends the whole request back to
    whoever filed it, with your comment. A purchase is one purchase, so it is not part-approved.</p>
</div>
<?php endif; ?>

<?php if ($req['Status'] === 'finance_review' && has_function(['finance'])): ?>
<div class="panel">
    <h2>Finance Approval</h2>
    <p class="muted">Every account on this purchase has agreed. Yours is the last approval.</p>
    <form method="post" class="filters">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="finance">
        <label>Decision <?= picklist_select('approval_status', 'decision', 'approved') ?></label>
        <label>Comments <input name="comment" size="40"></label>
        <button class="primary">Submit purchase approval status</button>
    </form>
</div>
<?php endif; ?>

<?php
$awaiting = array_values(array_filter($items,
    fn($i) => (int)$i['IsEquipment'] === 1 && trim((string)$i['PropertyTag']) === ''));
?>
<?php if ($awaiting && in_array($req['Status'], ['approved', 'ordered', 'received'], true)): ?>
<div class="panel">
    <h2>Equipment Awaiting an Inventory Number</h2>
    <p class="muted">These lines were ticked as equipment, so they are expected property. When one
    arrives, giving it a number turns it into a record in the room it went to, against this
    purchase.</p>
    <?php if (has_function(['equipment', 'inventory'])): ?>
    <ul class="plain">
        <?php foreach ($awaiting as $i): ?>
        <li><strong><?= h((string)$i['Description']) ?></strong>
            <span class="muted">$<?= number_format((float)$i['UnitCost'], 2) ?><?=
                $i['ExpectedFor'] ? ' &middot; for ' . h((string)$i['ExpectedFor']) : '' ?></span>
            &mdash; <a href="new_property.php?expected=<?= (int)$i['PurchaseItemID'] ?>">it
            arrived, give it a number</a></li>
        <?php endforeach; ?>
    </ul>
    <?php else: ?>
    <ul class="plain">
        <?php foreach ($awaiting as $i): ?>
        <li><?= h((string)$i['Description']) ?>
            <span class="muted">&mdash; waiting on the property inventory</span></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($req['Status'] === 'approved' && has_function(['finance', 'facilities'])): ?>
<div class="panel">
    <h2>Raise the Order</h2>
    <p class="muted">Every approval is in. Record the requisition or purchase order number so the
    request can be followed to delivery.</p>
    <form method="post" class="filters">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="ordered">
        <label>Requisition or PO number <input name="order_reference" size="20"></label>
        <button class="primary">Mark as ordered</button>
    </form>
</div>
<?php endif; ?>

<?php if ($files): ?>
<div class="panel">
    <h2>Related Documentation</h2>
    <ul class="plain">
        <?php foreach ($files as $f): ?>
        <li><a href="file.php?id=<?= (int)$f['AttachmentID'] ?>"><?= h((string)$f['OriginalName']) ?></a>
            <span class="muted"><?= h(number_format((int)$f['ByteSize'] / 1024)) ?> KB</span></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="panel">
    <h2>Approval Timeline</h2>
    <table>
        <tr><th>When</th><th>What</th><th>Who</th></tr>
        <?php foreach ($events as $e): ?>
        <tr>
            <td><?= h(date('n/j/Y g:i:s A', strtotime((string)$e['CreatedAt']))) ?></td>
            <td><?= h((string)$e['Detail']) ?></td>
            <td><?= h((string)($e['ByName'] ?: $e['ByNetID'])) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <form method="post" class="actions">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="replicate">
        <button>Replicate as a new purchase request</button>
        <a class="btn" href="new_purchase.php">Create a new purchase request</a>
    </form>
</div>
<?php ui_bottom(); ?>
