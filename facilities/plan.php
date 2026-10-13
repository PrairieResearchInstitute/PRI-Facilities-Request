<?php
declare(strict_types=1);

/**
 * One safety plan, and the button that records a review of it.
 *
 * Reviewing is one action with one outcome and an optional note. It is
 * deliberately not an edit form: the record of "somebody read this on
 * this date and said it still stands" has to be a line that gets added,
 * never a field that gets overwritten, or the register cannot answer the
 * only question anybody asks it.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/plans.php';
require_once __DIR__ . '/lib/picklists.php';

if (!app_installed()) {
    header('Location: install.php');
    exit;
}
handle_user_switch();
$user = current_user();

$id = (int)($_GET['id'] ?? 0);
$plan = $id > 0 ? plan_get($id) : null;
if (!$plan) {
    http_response_code(404);
    ui_top('Not Found', '');
    echo '<div class="panel error"><p>No such plan.</p></div>';
    ui_bottom();
    exit;
}

// The safety office keeps the register; a plan's own owner may record a
// review of their own plan, because they are the person who did it.
$isOwner = $plan['OwnerNetID'] !== null
        && strcasecmp((string)$plan['OwnerNetID'], (string)$user['NetID']) === 0;
$mayReview = $isOwner || has_function(['safety', 'executive', 'deputy']);

$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    if (!$mayReview) {
        $notice = 'Only the safety office or this plan&rsquo;s owner may record a review.';
    } else {
        $notice = plan_review($id, (string)($_POST['outcome'] ?? 'unchanged'),
                              (string)($_POST['note'] ?? ''), $user);
    }
    $plan = plan_get($id);
}

$days = $plan['ReviewDueOn'] !== null
    ? (int)floor((strtotime((string)$plan['ReviewDueOn']) - strtotime(date('Y-m-d'))) / 86400)
    : null;

ui_top(title_case((string)$plan['Title']),
       h((string)($plan['Reference'] ?: $plan['TicketNumber'])) . ' &middot; '
       . h((string)$plan['TypeTitle']) . ' &middot; <a href="plans.php">the register</a>');
?>

<?php if ($notice): ?>
<div class="panel ok"><?= $notice ?></div>
<?php endif; ?>

<?php if ($days !== null && $days < 0 && $plan['Status'] === 'current'): ?>
<div class="panel error">
    <p><strong>Overdue by <?= abs($days) ?> days.</strong> It was due on
       <?= h(date('j F Y', strtotime((string)$plan['ReviewDueOn']))) ?>.</p>
</div>
<?php endif; ?>

<div class="panel">
    <div class="grid">
        <div><strong>Owner</strong><br><?= h((string)$plan['OwnerName']) ?>
            <?= $plan['OwnerNetID'] ? '<span class="muted"> ' . h((string)$plan['OwnerNetID'])
                                      . '</span>' : '' ?></div>
        <div><strong>Unit</strong><br><?= h((string)($plan['UnitName']
                ?: $plan['SurveyCode'] ?: 'not recorded')) ?></div>
        <div><strong>Effective from</strong><br><?= $plan['EffectiveOn']
                ? h(date('j F Y', strtotime((string)$plan['EffectiveOn']))) : '&mdash;' ?></div>
        <div><strong>Last read</strong><br><?= $plan['LastReviewedOn']
                ? h(date('j F Y', strtotime((string)$plan['LastReviewedOn'])))
                : '<span class="muted">not since it was registered</span>' ?></div>
        <div><strong>Next due</strong><br><?= $plan['ReviewDueOn']
                ? h(date('j F Y', strtotime((string)$plan['ReviewDueOn']))) : '&mdash;' ?></div>
        <div><strong>Status</strong><br><?= h(nice_code((string)$plan['Status'])) ?>
            <?= $plan['Version'] ? '<span class="muted"> &middot; version '
                                   . h((string)$plan['Version']) . '</span>' : '' ?></div>
    </div>
    <?php if ($plan['Summary']): ?>
    <p><?= nl2br(h((string)$plan['Summary'])) ?></p>
    <?php endif; ?>
</div>

<div class="panel">
    <h2>Where It Applies</h2>
    <?php if (!$plan['rooms']): ?>
    <p class="muted">No rooms or buildings recorded.</p>
    <?php else: ?>
    <ul>
    <?php foreach ($plan['rooms'] as $r): ?>
        <li><?= $r['CoversWholeBuilding']
                ? 'All of building ' . h((string)$r['BldgNumber'])
                : h((string)($r['RoomCode'] ?: $r['BldgNumber'])) ?>
            <?= $r['Note'] ? '<span class="muted"> &middot; ' . h((string)$r['Note'])
                             . '</span>' : '' ?></li>
    <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>

<?php if ($mayReview && $plan['Status'] === 'current'): ?>
<div class="panel">
    <h2>Record A Review</h2>
    <p class="muted">This adds a dated line. It does not overwrite anything.</p>
    <form method="post" class="grid">
        <?= csrf_field() ?>
        <label>What you concluded
            <select name="outcome">
                <?php foreach (picklist('plan_review_outcome') as $c => $l): ?>
                <option value="<?= h($c) ?>"><?= h($l) ?></option>
                <?php endforeach; ?>
            </select></label>
        <label style="grid-column:2/-1">Note
            <input name="note" placeholder="What changed, or that nothing did"></label>
        <p style="grid-column:1/-1"><button class="btn">Record It</button></p>
    </form>
</div>
<?php endif; ?>

<div class="panel">
    <h2>Reviews</h2>
    <?php if (!$plan['reviews']): ?>
    <p class="muted">Never reviewed. It was registered on
       <?= h(date('j F Y', strtotime((string)$plan['CreatedAt']))) ?>.</p>
    <?php else: ?>
    <table>
        <tr><th>Date</th><th>By</th><th>Outcome</th><th>Note</th><th>Next due</th></tr>
        <?php foreach ($plan['reviews'] as $r): ?>
        <tr>
            <td><?= h(date('j M Y', strtotime((string)$r['ReviewedOn']))) ?></td>
            <td><?= h((string)($r['ReviewedByName'] ?: $r['ReviewedByNetID'])) ?></td>
            <td><?= h(picklist_label('plan_review_outcome', (string)$r['Outcome'])) ?></td>
            <td><?= h((string)$r['Note']) ?></td>
            <td><?= $r['NextDueOn'] ? h(date('j M Y', strtotime((string)$r['NextDueOn']))) : '' ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<?php ui_bottom(); ?>
