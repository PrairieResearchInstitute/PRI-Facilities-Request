<?php
declare(strict_types=1);

/**
 * Request detail (spec Section 13): the record, notes, status history,
 * the approval or review chain when one exists, and — on work requests —
 * the confirm control, which reads "I am seeing this too" because the
 * action asserts an observation rather than expressing a preference.
 * A merged work request redirects to its survivor.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/access.php';
require_once __DIR__ . '/lib/scoring.php';
require_once __DIR__ . '/lib/tickets.php';
require_once __DIR__ . '/lib/approvals.php';
require_once __DIR__ . '/lib/attachments.php';

if (!app_installed()) {
    header('Location: install.php');
    exit;
}
handle_user_switch();
$user = current_user();

$type = in_array($_GET['type'] ?? '', ['work', 'move', 'shop'], true) ? $_GET['type'] : 'work';
$id = (int)($_GET['id'] ?? 0);
$pdo = db($type);

$tables = [
    'work' => ['wr.WorkRequests', 'WorkRequestID'],
    'move' => ['mv.MoveRequests', 'MoveRequestID'],
    'shop' => ['sh.ShopRequests', 'ShopRequestID'],
];
[$table, $pk] = $tables[$type];

$stmt = $pdo->prepare("SELECT * FROM $table WHERE $pk = ?");
$stmt->execute([$id]);
$req = $stmt->fetch();
if (!$req) {
    http_response_code(404);
    ui_top('Not found');
    echo '<div class="panel err"><p>No such request.</p><p><a href="board.php">Back to the board</a></p></div>';
    ui_bottom();
    exit;
}

/* A request opened directly by its number obeys the same rule as the
   board: yours, or you are one of the people who sees everything. A 403
   rather than a redirect, so the answer is the truth rather than a
   detour. */
/* Registers do not agree on the column that names the filer: purchasing,
   keys, space and property say RequestedByNetID; work, move, shop and
   incidents say CreatedByNetID. Reading only the first meant a work
   request matched nobody, and the person who filed it was refused their
   own request. */
$filedBy = (string)($req['RequestedByNetID'] ?? $req['CreatedByNetID'] ?? '');
if (!may_see_all_requests($user)
    && strcasecmp($filedBy, (string)$user['NetID']) !== 0) {
    http_response_code(403);
    ui_top('Not yours');
    echo '<div class="panel err"><p>That request was filed by somebody else. You can see the '
       . 'ones you filed on <a href="board.php">Open Requests</a>.</p></div>';
    ui_bottom();
    exit;
}

// Merged requests redirect to the survivor (spec Section 6.4).
if ($type === 'work' && $req['Status'] === 'merged' && !empty($req['MergedIntoID'])) {
    header('Location: detail.php?type=work&id=' . (int)$req['MergedIntoID']);
    exit;
}

// ---------- actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = $_POST['action'] ?? '';
    $localUid = ensure_user($type, $user);

    if ($action === 'confirm' && $type === 'work') {
        // One confirmation per person per request; the reporter cannot confirm their own.
        if ($req['CreatedByNetID'] === $user['NetID']) {
            flash_set('err', 'You filed this request, so your report already counts.');
        } else {
            try {
                $pdo->prepare('INSERT INTO wr.Corroborations (WorkRequestID, UserID, SurveyCode) VALUES (?, ?, ?)')
                    ->execute([$id, $localUid, $user['SurveyCode']]);
                scoring_recompute($id);
                flash_set('ok', 'Thank you — your confirmation raised this request\'s priority.');
            } catch (Throwable $e) {
                flash_set('err', 'You have already confirmed this request.');
            }
        }
    } elseif ($action === 'decide' && in_array($type, ['work', 'shop'], true)) {
        if (!approval_may_decide($user)) {
            http_response_code(403);
            header('Content-Type: text/plain');
            echo 'Only an approver may record a decision.';
            exit;
        }
        flash_set('ok', approval_decide($type, $id, (int)$_POST['approval_id'],
            (string)$_POST['decision'], (string)($_POST['comment'] ?? ''),
            (string)($_POST['return_to'] ?? ''), $user));

    } elseif ($action === 'cost' && role_at_least('coordinator')) {
        // The cost is captured when the order is written; this is the
        // correction afterwards, and the change is recorded.
        $raw = trim((string)($_POST['amount'] ?? ''));
        $amount = $raw === '' ? null : (float)preg_replace('/[^0-9.\-]/', '', $raw);
        $col = $type === 'work' ? 'wr.WorkRequests' : 'sh.ShopRequests';
        $pk = $type === 'work' ? 'WorkRequestID' : 'ShopRequestID';
        $was = $req['EstimateAmount'];
        $pdo->prepare("UPDATE $col SET EstimateAmount = ?, UpdatedAt = SYSDATETIME() WHERE $pk = ?")
            ->execute([$amount, $id]);
        record_status($type, $id, $req['Status'], $req['Status'], $localUid,
            'Cost changed from ' . ($was === null ? 'none' : '$' . number_format((float)$was, 2))
            . ' to ' . ($amount === null ? 'none' : '$' . number_format($amount, 2))
            . (trim((string)($_POST['cost_note'] ?? '')) !== '' ? ': ' . $_POST['cost_note'] : ''));
        flash_set('ok', 'Cost updated.');

    } elseif ($action === 'attach') {
        $msg = attach_store($type, $id, $_FILES['attachment'] ?? [],
            (string)($_POST['purpose'] ?? 'photo'), trim((string)($_POST['caption'] ?? '')), $user);
        flash_set(str_contains((string)$msg, 'attached') ? 'ok' : 'err',
            $msg ?? 'No file was chosen.');

    } elseif ($action === 'note') {
        $body = trim((string)($_POST['body'] ?? ''));
        $internal = role_at_least('coordinator') && isset($_POST['internal']) ? 1 : 0;
        if ($body !== '') {
            $pdo->prepare('INSERT INTO app.Notes (RequestID, UserID, Body, IsInternal) VALUES (?, ?, ?, ?)')
                ->execute([$id, $localUid, $body, $internal]);
            flash_set('ok', 'Note added.');
        }
    }
    header('Location: detail.php?type=' . $type . '&id=' . $id);
    exit;
}

// ---------- reads ----------
$notesSql = 'SELECT n.*, u.DisplayName FROM app.Notes n JOIN app.Users u ON u.UserID = n.UserID
             WHERE n.RequestID = ?' . (role_at_least('coordinator') ? '' : ' AND n.IsInternal = 0')
          . ' ORDER BY n.CreatedAt';
$notes = $pdo->prepare($notesSql);
$notes->execute([$id]);
$notes = $notes->fetchAll();

$hist = $pdo->prepare('SELECT sh.*, u.DisplayName FROM app.StatusHistory sh
                       LEFT JOIN app.Users u ON u.UserID = sh.UserID
                       WHERE sh.RequestID = ? ORDER BY sh.CreatedAt');
$hist->execute([$id]);
$hist = $hist->fetchAll();

$building = null;
if (!empty($req['BuildingID'])) {
    $b = $pdo->prepare('SELECT * FROM ref.Buildings WHERE BuildingID = ?');
    $b->execute([$req['BuildingID']]);
    $building = $b->fetch();
}

$chain = [];
$items = [];
$corro = [];
$workType = null;
if ($type === 'work') {
    $c = $pdo->prepare('SELECT c.*, u.DisplayName FROM wr.Corroborations c
                        JOIN app.Users u ON u.UserID = c.UserID WHERE c.WorkRequestID = ? ORDER BY c.CreatedAt');
    $c->execute([$id]);
    $corro = $c->fetchAll();
    $a = $pdo->prepare('SELECT * FROM wr.Approvals WHERE WorkRequestID = ? ORDER BY StageOrder');
    $a->execute([$id]);
    $chain = $a->fetchAll();
    $t = $pdo->prepare('SELECT * FROM ref.WorkTypes WHERE WorkTypeID = ?');
    $t->execute([$req['WorkTypeID']]);
    $workType = $t->fetch();
} elseif ($type === 'move') {
    $i = $pdo->prepare('SELECT * FROM mv.MoveItems WHERE MoveRequestID = ? ORDER BY MoveItemID');
    $i->execute([$id]);
    $items = $i->fetchAll();
    $a = $pdo->prepare('SELECT * FROM mv.Reviews WHERE MoveRequestID = ? ORDER BY StageOrder');
    $a->execute([$id]);
    $chain = $a->fetchAll();
} else {
    $a = $pdo->prepare('SELECT * FROM sh.Approvals WHERE ShopRequestID = ? ORDER BY StageOrder');
    $a->execute([$id]);
    $chain = $a->fetchAll();
}

$typeNames = ['work' => 'Work Request', 'move' => 'Move Request', 'shop' => 'Shop Services Request'];
ui_top($req['TicketNumber'] . ' — ' . $typeNames[$type], '<a href="board.php?tab=' . $type . '">&larr; board</a>');
flash_show();

$alreadyConfirmed = false;
foreach ($corro as $c) {
    if ($c['DisplayName'] === $user['DisplayName']) {
        $alreadyConfirmed = true;
    }
}
?>
<div class="panel">
    <h2><?= h($type === 'work' ? $req['Title'] : ($type === 'shop' ? mb_substr((string)$req['WorkDescription'], 0, 120) : 'Move of ' . count($items) . ' item(s)')) ?></h2>
    <p>
        <strong>Status:</strong> <?= h(nice_code($req['Status'])) ?>
        <?php if ($type === 'work'): ?>
            &middot; <strong>Priority:</strong>
            <span class="band band-<?= h(scoring_band(scoring_current($req))) ?>"><?= h(scoring_band(scoring_current($req))) ?></span>
            <?= number_format(scoring_current($req), 1) ?>
        <?php endif; ?>
    </p>
    <table>
        <?php if ($building): ?>
        <tr><th>Building</th><td><?= h($building['Name']) ?> (#<?= h($building['BldgNumber']) ?>)</td></tr>
        <?php endif; ?>
        <?php if (!empty($req['RoomNumber'])): ?><tr><th>Room</th><td><?= h($req['RoomNumber']) ?></td></tr><?php endif; ?>
        <?php if (!empty($req['WrittenLocation'])): ?><tr><th>Location</th><td><?= h($req['WrittenLocation']) ?></td></tr><?php endif; ?>
        <?php if ($type === 'work'): ?>
        <tr><th>Work type</th><td><?= h($workType['Name'] ?? '') ?></td></tr>
        <tr><th>Severity</th><td>Reported <?= (int)$req['ReportedSeverity'] ?><?=
            $req['TriagedSeverity'] !== null ? ', triaged ' . (int)$req['TriagedSeverity'] : '' ?></td></tr>
        <?php if (!empty($req['LifeSafety'])): ?><tr><th>Life safety</th><td class="bad">Risk to people flagged</td></tr><?php endif; ?>
        <?php if (!empty($req['SpecimenRisk'])): ?><tr><th>Specimen risk</th><td class="bad">Risk to collections flagged</td></tr><?php endif; ?>
        <?php if ($req['EstimateAmount'] !== null): ?><tr><th>Estimate</th><td>$<?= number_format((float)$req['EstimateAmount'], 2) ?></td></tr><?php endif; ?>
        <tr><th>Description</th><td><?= nl2br(h((string)$req['Description'])) ?></td></tr>
        <tr><th>Filed by</th><td><?= h($req['CreatedByNetID']) ?><?= $req['OnBehalfOfName'] ? ' on behalf of ' . h($req['OnBehalfOfName']) : '' ?></td></tr>
        <?php elseif ($type === 'shop'): ?>
        <tr><th>CFOP</th><td><?= h($req['CfopCode']) ?></td></tr>
        <tr><th>Survey</th><td><?= h($req['SurveyCode']) ?></td></tr>
        <tr><th>Account PI</th><td><?= h($req['AccountPI']) ?></td></tr>
        <tr><th>Fund manager</th><td><?= h($req['FundManager']) ?></td></tr>
        <tr><th>Work</th><td><?= nl2br(h((string)$req['WorkDescription'])) ?></td></tr>
        <tr><th>Justification</th><td><?= nl2br(h((string)$req['BusinessJustification'])) ?></td></tr>
        <tr><th>Filed by</th><td><?= h($req['RequestedByNetID']) ?></td></tr>
        <?php else: ?>
        <tr><th>Filed by</th><td><?= h($req['RequestedByNetID']) ?></td></tr>
        <tr><th>Person moving?</th><td><?= $req['IsPersonMoving'] ? 'Yes' : 'No' ?></td></tr>
        <?php if (!empty($req['AdditionalNotes'])): ?><tr><th>Notes</th><td><?= nl2br(h((string)$req['AdditionalNotes'])) ?></td></tr><?php endif; ?>
        <?php endif; ?>
        <?php if (!empty($req['NeededByDate'])): ?><tr><th>Needed by</th><td><?= h((string)$req['NeededByDate']) ?></td></tr><?php endif; ?>
        <tr><th>Filed</th><td><?= h(date('M j, Y g:ia', strtotime((string)$req['CreatedAt']))) ?></td></tr>
    </table>
</div>

<?php if ($type === 'work'): ?>
<div class="panel">
    <h2>Confirmations (<?= count($corro) ?>)</h2>
    <?php if ($corro): ?>
    <ul>
        <?php foreach ($corro as $c): ?>
        <li><?= h($c['DisplayName']) ?><?= $c['SurveyCode'] ? ' (' . h($c['SurveyCode']) . ')' : '' ?>
            &mdash; <?= h(date('M j', strtotime((string)$c['CreatedAt']))) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php else: ?>
    <p class="muted">Nobody else has confirmed this yet.</p>
    <?php endif; ?>
    <?php if (!in_array($req['Status'], ['closed', 'merged', 'declined', 'verified'], true)
              && $req['CreatedByNetID'] !== $user['NetID'] && !$alreadyConfirmed): ?>
    <form method="post">
        <?= csrf_field() ?>
        <button name="action" value="confirm" class="primary">I am seeing this too</button>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($type === 'move' && $items): ?>
<div class="panel scroll-x">
    <h2>Items (<?= count($items) ?>)</h2>
    <table>
        <tr><th>#</th><th>Move type</th><th>Item</th><th>Tags</th><th>From</th><th>To</th><th>Custodians</th><th>Tech</th></tr>
        <?php foreach ($items as $n => $i): ?>
        <tr>
            <td><?= $n + 1 ?></td>
            <td><?= h($i['MoveType']) ?></td>
            <td><?= h($i['ItemType']) ?>: <?= h($i['Description']) ?></td>
            <td><?= h(trim(($i['PTag'] ? 'P:' . $i['PTag'] . ' ' : '') . ($i['PRITag'] ? 'PRI:' . $i['PRITag'] . ' ' : '')
                . ($i['SerialNumber'] ? 'S/N:' . $i['SerialNumber'] : '')) ?: '—') ?></td>
            <td><?= h(trim(($i['FromBldgNumber'] ?? '') . ' ' . ($i['FromRoom'] ?? '')) ?: '—') ?></td>
            <td><?= h(trim(($i['ToBldgNumber'] ?? '') . ' ' . ($i['ToRoom'] ?? '')) ?: '—') ?></td>
            <td><?= h(trim(($i['CurrentCustodian'] ?? '') . ' → ' . ($i['NewCustodian'] ?? ''), ' →') ?: '—') ?></td>
            <td><?= $i['IsTechItem'] ? 'Yes' : '' ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php endif; ?>

<?php if ($type === 'move' && $items && role_at_least('coordinator')): ?>
<div class="panel">
    <h2>Apply This Move to the Equipment Records</h2>
    <p class="muted">Every item carrying a property tag moves to its destination room, and items
    sent to trash or surplus are retired, so the room tables match what actually happened.</p>
    <form method="post" action="admin.php?section=equipment">
        <?= csrf_field() ?>
        <input type="hidden" name="move_id" value="<?= (int)$id ?>">
        <button name="action" value="equip_apply_move" class="primary">Apply to equipment records</button>
    </form>
</div>
<?php endif; ?>

<?php if (in_array($type, ['work', 'shop'], true) && $req['EstimateAmount'] !== null
          && role_at_least('coordinator')): ?>
<div class="panel">
    <h2>Cost</h2>
    <form method="post" class="filters">
        <?= csrf_field() ?>
        <label>Amount $ <input name="amount" size="12"
            value="<?= h(number_format((float)$req['EstimateAmount'], 2, '.', '')) ?>"></label>
        <label>Why it changed <input name="cost_note" size="28"></label>
        <button name="action" value="cost">Update cost</button>
    </form>
    <p class="muted">Corrections are recorded in the status history, so the figure behind every
    room and building total can be traced.</p>
</div>
<?php endif; ?>

<?php if ($chain): ?>
<div class="panel">
    <h2><?= $type === 'move' ? 'Review chain' : 'Approval chain' ?></h2>
    <?php
    $currentStage = in_array($type, ['work', 'shop'], true) ? approval_current($type, $id) : null;
    if ($currentStage && approval_may_decide($user)):
        $prior = null;
        foreach ($chain as $s3) {
            if ((int)$s3['StageOrder'] < (int)$currentStage['StageOrder'] && $s3['IsRequired']) {
                $prior = $s3;
            }
        }
    ?>
    <div class="itemrow">
        <h4>Your decision on <?= h(approval_stage_label((string)$currentStage['StageCode'])) ?></h4>
        <?php if (!empty($currentStage['ApproverName'])): ?>
        <p class="muted">Named approver: <?= h((string)$currentStage['ApproverName']) ?></p>
        <?php endif; ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="approval_id" value="<?= (int)$currentStage['ApprovalID'] ?>">
            <label>Comment (required to deny or return)
                <textarea name="comment" rows="2"></textarea>
            </label>
            <div class="filters">
                <label>Return to
                    <select name="return_to">
                        <?php foreach ($chain as $s4):
                            if ((int)$s4['StageOrder'] >= (int)$currentStage['StageOrder']) continue; ?>
                        <option value="<?= h((string)$s4['StageCode']) ?>"
                            <?= ($prior && $s4['StageCode'] === $prior['StageCode']) ? 'selected' : '' ?>>
                            <?= h(approval_stage_label((string)$s4['StageCode'])) ?></option>
                        <?php endforeach; ?>
                        <?php if (!$prior): ?><option value="">no earlier stage</option><?php endif; ?>
                    </select>
                </label>
                <button name="action" value="decide" class="primary"
                        onclick="this.form.decision.value='approved'">Approve</button>
                <button name="action" value="decide"
                        onclick="this.form.decision.value='returned'">Return</button>
                <button name="action" value="decide"
                        onclick="this.form.decision.value='denied'">Deny</button>
                <input type="hidden" name="decision" value="approved">
            </div>
        </form>
        <p class="muted">A denial stops the request; it does not reopen, and a new request must be
        filed. A return sends it back to the stage named above rather than to the submitter.</p>
    </div>
    <?php elseif ($currentStage): ?>
    <p class="muted">Awaiting <?= h(approval_stage_label((string)$currentStage['StageCode'])) ?>.
       Your role (<?= h(user_role()) ?>) cannot record that decision.</p>
    <?php endif; ?>
    <ul class="stagechain">
        <?php foreach ($chain as $st): ?>
        <li>
            <strong><?= h(nice_code((string)$st['StageCode'])) ?></strong>
            <?php if (!empty($st['ApproverName'])): ?> &mdash; <?= h($st['ApproverName']) ?><?php endif; ?>
            <?php if (!$st['IsRequired']): ?>
                <span class="muted">skipped: <?= h((string)$st['SkipReason']) ?></span>
            <?php elseif ($st['Decision'] !== null): ?>
                <span class="<?= in_array($st['Decision'], ['approved', 'cleared'], true) ? 'good' : 'bad' ?>">
                    <?= h($st['Decision']) ?></span>
                <?= $st['DecisionAt'] ? h(date('M j', strtotime((string)$st['DecisionAt']))) : '' ?>
                <?php if (!empty($st['Comment'])): ?><div class="muted"><?= h((string)$st['Comment']) ?></div><?php endif; ?>
            <?php else: ?>
                <span class="muted">pending</span>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php $files = attach_list($type, $id); ?>
<div class="panel">
    <h2><?= $type === 'shop' ? 'Drawings and files' : 'Photographs and files' ?> (<?= count($files) ?>)</h2>
    <?php if ($files): ?>
    <div class="filegrid">
        <?php foreach ($files as $f):
            $isImage = str_starts_with((string)$f['MimeType'], 'image/');
            $url = 'file.php?type=' . h($type) . '&amp;id=' . (int)$f['AttachmentID']; ?>
        <figure class="filecard">
            <a href="<?= $url ?>" target="_blank" rel="noopener">
                <?php if ($isImage): ?>
                <img src="<?= $url ?>" alt="<?= h((string)($f['Caption'] ?: $f['OriginalName'])) ?>">
                <?php else: ?>
                <span class="filetype"><?= h(strtoupper(pathinfo((string)$f['OriginalName'], PATHINFO_EXTENSION))) ?></span>
                <?php endif; ?>
            </a>
            <figcaption>
                <?= h((string)$f['OriginalName']) ?>
                <span class="muted"><?= h((string)$f['Purpose']) ?>
                    · <?= h(number_format((float)$f['SizeBytes'] / 1024, 0)) ?> KB
                    <?php if (!empty($f['ExifLatitude'])): ?>· carries coordinates<?php endif; ?>
                </span>
            </figcaption>
        </figure>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p class="muted">Nothing attached yet.</p>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="filters">
            <label>Add a file <input type="file" name="attachment"
                accept="image/*,.pdf,.dwg,.dxf" required></label>
            <label>What is it
                <select name="purpose">
                    <option value="photo">photograph</option>
                    <option value="drawing" <?= $type === 'shop' ? 'selected' : '' ?>>drawing</option>
                    <option value="document">document</option>
                </select>
            </label>
            <label>Caption <input name="caption" size="24"></label>
            <button name="action" value="attach">Attach</button>
        </div>
    </form>
</div>

<div class="panel">
    <h2>Notes</h2>
    <?php foreach ($notes as $n): ?>
    <p><strong><?= h($n['DisplayName']) ?></strong>
       <span class="muted"><?= h(date('M j, Y g:ia', strtotime((string)$n['CreatedAt']))) ?><?= $n['IsInternal'] ? ' · internal' : '' ?></span><br>
       <?= nl2br(h((string)$n['Body'])) ?></p>
    <?php endforeach; ?>
    <form method="post">
        <?= csrf_field() ?>
        <label>Add a note
            <textarea name="body" rows="2" required></textarea>
        </label>
        <?php if (role_at_least('coordinator')): ?>
        <label class="check"><input type="checkbox" name="internal" value="1"> Internal note (staff cannot see it)</label>
        <?php endif; ?>
        <div class="actions"><button name="action" value="note">Add note</button></div>
    </form>
</div>

<div class="panel">
    <h2>Status History</h2>
    <ul class="stagechain">
        <?php foreach ($hist as $s2): ?>
        <li><?= h(date('M j, Y g:ia', strtotime((string)$s2['CreatedAt']))) ?> &mdash;
            <?= $s2['FromStatus'] ? h(nice_code($s2['FromStatus'])) . ' → ' : '' ?>
            <strong><?= h(nice_code($s2['ToStatus'])) ?></strong>
            <?= $s2['DisplayName'] ? 'by ' . h($s2['DisplayName']) : '' ?>
            <?php if (!empty($s2['Comment'])): ?><span class="muted">(<?= h((string)$s2['Comment']) ?>)</span><?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php ui_bottom(); ?>
