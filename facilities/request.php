<?php
declare(strict_types=1);

/**
 * A space or key request: what was asked for, where it stands, and — for
 * the people who decide — the decision itself and the action the decision
 * releases.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/access.php';
require_once __DIR__ . '/lib/requests2.php';

if (!app_installed()) {
    header('Location: install.php');
    exit;
}
// A purchase request is read on its own page: its funding grid and the
// two approvals per account do not fit the shape used for space and key.
if (($_GET['type'] ?? '') === 'purchase') {
    header('Location: purchase_view.php?id=' . (int)($_GET['id'] ?? 0));
    exit;
}
handle_user_switch();
$user = current_user();

$type = in_array($_GET['type'] ?? '', ['space', 'key'], true) ? (string)$_GET['type'] : 'space';
$id = (int)($_GET['id'] ?? 0);
$req = rq_get($type, $id);
if (!$req) {
    http_response_code(404);
    ui_top('Not found');
    echo '<div class="panel err"><p>No such request.</p><p><a href="index.php">Home</a></p></div>';
    ui_bottom();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'decide') {
        flash_set('ok', rq_decide($type, $id, (int)$_POST['approval_id'],
            (string)$_POST['decision'], (string)($_POST['comment'] ?? ''), $user));
    } elseif ($action === 'assign' && $type === 'space') {
        require_admin_access();
        flash_set('ok', rq_space_assign($id, (string)($_POST['room_code'] ?? ''), $_POST, $user));
    } elseif ($action === 'issue' && $type === 'key') {
        require_admin_access();
        flash_set('ok', rq_key_issue($id, $_POST, $user));
    } elseif ($action === 'close') {
        require_admin_access();
        rq_set_status($type, $id, 'closed', trim((string)($_POST['comment'] ?? '')), $user);
        flash_set('ok', $req['TicketNumber'] . ' closed.');
    }
    header('Location: request.php?type=' . $type . '&id=' . $id);
    exit;
}

$chain = rq_chain($type, $id);
$stage = rq_current_stage($type, $id);
$mayDecide = rq_may_decide($type, $req, $stage);
$flow = status_labels($type);
$isMine = $req['RequestedByNetID'] === $user['NetID'];
/* A request opened directly by its number obeys the same rule as the
   board: yours, or you are one of the people who sees everything. A 403
   rather than a redirect, so the answer is the truth rather than a
   detour. */
if (!may_see_all_requests($user)
    && strcasecmp((string)($req['RequestedByNetID'] ?? ''), (string)$user['NetID']) !== 0) {
    http_response_code(403);
    ui_top('Not yours');
    echo '<div class="panel err"><p>That request was filed by somebody else. You can see the '
       . 'ones you filed on <a href="board.php">Open Requests</a>.</p></div>';
    ui_bottom();
    exit;
}

$titles = ['space' => 'Space Request', 'key' => 'Key Request'];
ui_top($req['TicketNumber'] . ' — ' . $titles[$type], '<a href="index.php">&larr; home</a>');
flash_show();
?>
<div class="panel">
    <h2><?= h($titles[$type]) ?></h2>
    <p><strong>Status:</strong> <?= h($flow[$req['Status']]['Label'] ?? (string)$req['Status']) ?>
       <span class="muted"><?= h((string)($flow[$req['Status']]['Description'] ?? '')) ?></span></p>
    <table>
        <tr><th>Filed by</th><td><?= h((string)$req['RequestedByNetID']) ?>
            <?= $req['SurveyCode'] ? ' (' . h((string)$req['SurveyCode']) . ')' : '' ?></td></tr>
        <tr><th>Filed</th><td><?= h(date('M j, Y g:ia', strtotime((string)$req['CreatedAt']))) ?></td></tr>
        <?php if ($type === 'space'): ?>
        <tr><th>Request</th><td><?= h(str_replace('_', ' ', (string)$req['RequestKind'])) ?></td></tr>
        <?php if (!empty($req['SubmittedByNames'])): ?>
        <tr><th>Submitted by</th><td><?= h((string)$req['SubmittedByNames']) ?>
            <?= $req['RequesterEmail'] ? ' &middot; ' . h((string)$req['RequesterEmail']) : '' ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($req['StaffAffected'])): ?>
        <tr><th>Others affected</th><td><?= nl2br(h((string)$req['StaffAffected'])) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($req['SpaceType'])): ?>
        <tr><th>Type of space</th><td><?= h((string)$req['SpaceType'] === 'other'
            ? (string)$req['SpaceTypeOther']
            : (SPACE_TYPES[(string)$req['SpaceType']]
               ?? ucfirst(str_replace('_', ' ', (string)$req['SpaceType'])))) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($req['RequestedChange'])): ?>
        <tr><th>Requested change</th><td><?= nl2br(h((string)$req['RequestedChange'])) ?></td></tr>
        <?php endif; ?>
        <?php if ($req['SpaceUse']): ?><tr><th>Intended use</th><td><?= h((string)$req['SpaceUse']) ?></td></tr><?php endif; ?>
        <?php if (!empty($req['PotentialCosts'])): ?>
        <tr><th>Potential costs</th><td><?= h((string)$req['PotentialCosts']) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($req['FundingCfop'])): ?>
        <tr><th>Account</th><td><strong><?= h((string)$req['FundingCfop']) ?></strong>
            <?= $req['FundingDescription'] ? ' &middot; ' . h((string)$req['FundingDescription']) : '' ?>
            <div class="muted">
                <?= $req['FundingSurveyCode'] ? h((string)$req['FundingSurveyCode']) . ' &middot; ' : '' ?>
                PI <?= h((string)$req['FundingPI']) ?>
                &middot; fund manager <?= h((string)$req['FundingFundManager']) ?>
            </div>
            <div class="muted">As the account stood when this was filed. The people named here
            are the ones who have to agree to the spending.</div></td></tr>
        <?php endif; ?>
        <?php if (!empty($req['FundingSource'])): ?>
        <tr><th>Funding notes</th><td><?= h((string)$req['FundingSource']) ?></td></tr>
        <?php endif; ?>
        <?php if ($req['PeopleAffected']): ?><tr><th>People affected</th><td><?= (int)$req['PeopleAffected'] ?></td></tr><?php endif; ?>
        <?php if ($req['CurrentBldgNumber']): ?>
        <tr><th>Space held now</th><td><?= h((string)$req['CurrentBldgNumber'] . ' ' . (string)$req['CurrentRoomID']) ?></td></tr>
        <?php endif; ?>
        <?php if ($req['RequestedBldgNumber']): ?>
        <tr><th>Space asked for</th><td><?= h((string)$req['RequestedBldgNumber'] . ' ' . (string)$req['RequestedRoomID']) ?></td></tr>
        <?php endif; ?>
        <?php if ($req['AssignedRoomCode']): ?>
        <tr><th>Assigned</th><td class="good"><?= h((string)$req['AssignedRoomCode']) ?></td></tr>
        <?php endif; ?>
        <?php else: ?>
        <tr><th>Key for</th><td><?= h((string)$req['HolderName']) ?>
            <?= $req['HolderNetID'] ? ' (' . h((string)$req['HolderNetID']) . ')' : '' ?></td></tr>
        <tr><th>Request</th><td><?= h(str_replace('_', ' ', (string)$req['RequestKind'])) ?>
            &middot; <?= h((string)$req['KeyType']) ?> &times; <?= (int)$req['Quantity'] ?></td></tr>
        <tr><th>Where</th><td><?= h(trim((string)$req['BldgNumber'] . ' ' . (string)$req['RoomID'])) ?></td></tr>
        <?php endif; ?>
        <?php if ($req['NeededByDate']): ?>
        <tr><th>Needed by</th><td><?= h(substr((string)$req['NeededByDate'], 0, 10)) ?></td></tr>
        <?php endif; ?>
        <tr><th>Justification</th><td><?= nl2br(h((string)$req['Justification'])) ?></td></tr>
    </table>
</div>

<div class="panel">
    <h2>Where It Stands</h2>
    <ol class="flowchart">
        <?php foreach ($flow as $code => $s):
            $done = (int)$s['StageOrder'] < (int)($flow[$req['Status']]['StageOrder'] ?? 0);
            $now = $code === $req['Status'];
            $cls = $now ? 'now' : ($done ? 'done' : ''); ?>
        <li class="<?= $cls ?>"><strong><?= h((string)$s['Label']) ?></strong>
            <span class="muted"><?= h((string)$s['OwnerHint']) ?></span></li>
        <?php endforeach; ?>
    </ol>
</div>

<div class="panel">
    <h2>Approval</h2>
    <ul class="stagechain">
        <?php foreach ($chain as $st): ?>
        <li><strong><?= h(ucwords(str_replace('_', ' ', (string)$st['StageCode']))) ?></strong>
            <?= $st['ApproverName'] ? ' — ' . h((string)$st['ApproverName']) : '' ?>
            <?php if ($st['Decision'] !== null): ?>
                <span class="<?= $st['Decision'] === 'approved' ? 'good' : 'bad' ?>"><?= h((string)$st['Decision']) ?></span>
                <?= $st['DecisionAt'] ? h(date('M j', strtotime((string)$st['DecisionAt']))) : '' ?>
                <?php if ($st['Comment']): ?><div class="muted"><?= h((string)$st['Comment']) ?></div><?php endif; ?>
            <?php else: ?><span class="muted">pending</span><?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($stage && $mayDecide): ?>
    <div class="itemrow">
        <h4>Your decision on <?= h(ucwords((string)$stage['StageCode'])) ?></h4>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="approval_id" value="<?= (int)$stage['ApprovalID'] ?>">
            <label>Comment (required to deny or return)
                <textarea name="comment" rows="2"></textarea>
            </label>
            <div class="actions">
                <button name="action" value="decide" class="primary"
                        onclick="this.form.decision.value='approved'">Approve</button>
                <button name="action" value="decide"
                        onclick="this.form.decision.value='returned'">Return to submitter</button>
                <button name="action" value="decide"
                        onclick="this.form.decision.value='denied'">Deny</button>
                <input type="hidden" name="decision" value="approved">
            </div>
        </form>
    </div>
    <?php elseif ($stage): ?>
    <p class="muted">Awaiting the <?= h((string)$stage['StageCode']) ?>.
       <?= $isMine ? 'You will be emailed when it is decided.' : '' ?></p>
    <?php endif; ?>
</div>

<?php if (!$stage && $req['Status'] === 'approved' && can_admin()): ?>
<div class="panel ok">
    <h2><?= $type === 'space' ? 'Assign the room' : 'Issue the key' ?></h2>
    <?php if ($type === 'space'): ?>
    <p class="muted">Granting the request does not move anybody. Assigning the room does, and it
    writes through to the room's record.</p>
    <form method="post" class="grid">
        <?= csrf_field() ?>
        <label>Room being assigned
            <select name="room_code" required>
                <option value="">--Select Room--</option>
                <?php
                $rooms = db('space')->prepare(
                    'SELECT RoomCode, BldgNumber, FloorCode, RoomID, RoomName FROM gis.Rooms
                     WHERE BldgNumber = ? ORDER BY FloorCode, RoomID');
                $rooms->execute([$req['RequestedBldgNumber'] ?: $req['CurrentBldgNumber']]);
                foreach ($rooms as $r): ?>
                <option value="<?= h((string)$r['RoomCode']) ?>"
                    <?= $r['RoomCode'] === $req['RequestedRoomCode'] ? 'selected' : '' ?>>
                    <?= h($r['BldgNumber'] . ' · floor ' . $r['FloorCode'] . ' · ' . $r['RoomID']
                        . ($r['RoomName'] ? ' — ' . $r['RoomName'] : '')) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Principal investigator <input name="pi_name"></label>
        <label class="check" style="align-self:end">
            <input type="checkbox" name="assign_occupant" value="1" checked>
            Also record the requester as an occupant</label>
        <label style="grid-column:1/-1">Note <input name="notes"></label>
        <div class="actions" style="grid-column:1/-1">
            <button name="action" value="assign" class="primary">Assign the room</button>
        </div>
    </form>
    <?php else: ?>
    <p class="muted">Approval does not cut a key. Recording the key number here puts it in the
    register against <?= h((string)$req['HolderName']) ?>.</p>
    <form method="post" class="filters">
        <?= csrf_field() ?>
        <label>Key number <input name="key_code" required></label>
        <button name="action" value="issue" class="primary">Issue and record</button>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (can_admin() && !in_array($req['Status'], ['closed', 'denied'], true)): ?>
<div class="panel">
    <form method="post" class="filters">
        <?= csrf_field() ?>
        <label>Closing note <input name="comment" size="34"></label>
        <button name="action" value="close">Close this request</button>
    </form>
</div>
<?php endif; ?>
<?php ui_bottom(); ?>
