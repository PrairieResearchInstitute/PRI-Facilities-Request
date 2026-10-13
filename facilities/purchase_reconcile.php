<?php
declare(strict_types=1);

/**
 * The reconciliation sheet: what to paste into Emburse Enterprise.
 *
 * One block per Banner account code, in the exact format the notes box
 * wants, with its total beside it so it can be matched against the charge
 * line. Nothing here is retyped, because the purchase already knew all of
 * it the day it was filed.
 *
 * It opens in its own window on purpose. Reconciling means having Emburse
 * in one window and this in another, and a page that closes when you
 * click away is no use for that.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/purchase.php';

if (!app_installed()) {
    header('Location: install.php');
    exit;
}
handle_user_switch();
current_user();

$id = (int)($_GET['id'] ?? 0);
$req = purchase_get($id);
if (!$req) {
    http_response_code(404);
    ui_top('Reconciliation');
    echo '<div class="panel"><p>No such purchase request.</p></div>';
    ui_bottom();
    exit;
}

$rec = purchase_reconcile($id);

// Plain text of the whole thing, for the person who would rather take one
// copy and split it up themselves.
$whole = '';
foreach ($rec['groups'] as $g) {
    $whole .= $g['code'] . '  $' . number_format($g['total'], 2) . "\n" . $g['text'] . "\n\n";
}

ui_top('Reconcile ' . h((string)$req['TicketNumber']),
       '<a href="purchase_view.php?id=' . $id . '">&larr; the purchase</a>');
?>
<div class="panel">
    <h2>Paste This into Emburse</h2>
    <p class="muted"><?= h((string)$req['VendorName']) ?>
       &middot; <?= h((string)$req['StaffRequesting']) ?>
       &middot; total $<?= number_format($rec['grand'], 2) ?>
       <?= $req['OrderReference'] ? ' &middot; order ' . h((string)$req['OrderReference']) : '' ?></p>
    <p>One block per Banner account code, already in the format the notes box wants. Match the
    total to the charge line, press Copy, paste.</p>
    <?php if (count($rec['groups']) > 1): ?>
    <p class="muted">This purchase splits across <?= count($rec['groups']) ?> codes, so there are
    <?= count($rec['groups']) ?> charge lines to reconcile.</p>
    <?php endif; ?>
</div>

<?php if ($rec['uncoded']): ?>
<div class="panel err">
    <h3>Lines with no account code</h3>
    <p>These cannot be reconciled, because Emburse is broken down by code and these have none.
    Add the code to the purchase and they will appear here.</p>
    <ul>
        <?php foreach ($rec['uncoded'] as $u): ?>
        <li><?= h(purchase_reconcile_line($u)) ?>
            <span class="muted">&mdash; $<?= number_format((float)$u['Amount'], 2) ?></span></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if (!$rec['groups']): ?>
<div class="panel"><p>Nothing on this purchase carries a Banner account code yet.</p></div>
<?php else: ?>

<?php foreach ($rec['groups'] as $n => $g): ?>
<div class="panel recblock">
    <h2><?= h($g['code']) ?>
        <span class="rectotal">$<?= number_format($g['total'], 2) ?></span></h2>
    <?php if ($g['title']): ?>
    <p class="muted"><?= h($g['title']) ?></p>
    <?php endif; ?>
    <textarea readonly rows="<?= max(3, count($g['lines'])) ?>" id="rec<?= $n ?>"
              onclick="this.select()"><?= h($g['text']) ?></textarea>
    <div class="actions">
        <button type="button" class="primary" data-copy="rec<?= $n ?>">Copy this block</button>
        <button type="button" data-copy="rec<?= $n ?>" data-with-total="1">Copy with the total</button>
        <span class="copied" hidden>Copied.</span>
    </div>
</div>
<?php endforeach; ?>

<div class="panel">
    <h2>Everything at Once</h2>
    <p class="muted">Every code, with its total, in one go.</p>
    <textarea readonly rows="<?= max(4, substr_count($whole, "\n")) ?>" id="recall"
              onclick="this.select()"><?= h(trim($whole)) ?></textarea>
    <div class="actions">
        <button type="button" data-copy="recall">Copy all of it</button>
        <span class="copied" hidden>Copied.</span>
    </div>
</div>
<?php endif; ?>

<script>
/* Copy without a library and without asking for a permission the browser
   may refuse: the modern call first, and the old selection trick when it
   is not there, which it will not be on an intranet served over http. */
document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-copy]');
    if (!btn) { return; }
    var box = document.getElementById(btn.getAttribute('data-copy'));
    if (!box) { return; }

    var text = box.value;
    if (btn.hasAttribute('data-with-total')) {
        var panel = btn.closest('.recblock');
        var total = panel.querySelector('.rectotal').textContent.trim();
        text = total + '\n\n' + text;
    }

    var done = function () {
        var note = btn.closest('.actions').querySelector('.copied');
        note.hidden = false;
        setTimeout(function () { note.hidden = true; }, 2000);
    };

    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done, function () { fallback(text, done); });
    } else {
        fallback(text, done);
    }
});

function fallback(text, done) {
    var t = document.createElement('textarea');
    t.value = text;
    t.style.position = 'fixed';
    t.style.opacity = '0';
    document.body.appendChild(t);
    t.select();
    try { document.execCommand('copy'); done(); } catch (err) { t.focus(); }
    document.body.removeChild(t);
}
</script>
<?php ui_bottom(); ?>
