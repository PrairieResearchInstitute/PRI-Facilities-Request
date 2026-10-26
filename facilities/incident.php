<?php
declare(strict_types=1);

/**
 * One incident report.
 *
 * The person who filed it can always read their own. Everybody else
 * needs back end access - which is the whole list of directors,
 * administrative assistants and OED point people, and no narrower list
 * than that exists to draw.
 *
 * Grading, corrective actions and closing appear only for the people who
 * do those things. The rest of the page is the same for everybody, so a
 * reporter can see that something was actually done.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/incidents.php';
require_once __DIR__ . '/lib/picklists.php';

if (!app_installed()) {
    header('Location: install.php');
    exit;
}
handle_user_switch();
$user = current_user();

$id = (int)($_GET['id'] ?? 0);
$inc = $id > 0 ? incident_get($id) : null;
if (!$inc) {
    http_response_code(404);
    ui_top('Not Found', '');
    echo '<div class="panel error"><p>No such report.</p></div>';
    ui_bottom();
    exit;
}

$isMine = strcasecmp((string)$inc['ReportedByNetID'], (string)$user['NetID']) === 0;
if (!$isMine && !may_read_incidents($user)) {
    http_response_code(403);
    ui_top('Not Yours', '');
    echo '<div class="panel error"><p>This report names people and describes what happened '
       . 'to them. You may read your own reports; this one is not yours.</p></div>';
    ui_bottom();
    exit;
}

$mayDecide = may_decide_incidents($user);
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $do = (string)($_POST['do'] ?? '');
    if (!$mayDecide) {
        $notice = 'You may read this report but not change it.';
    } elseif ($do === 'grade') {
        $notice = incident_grade($id, (string)($_POST['severity'] ?? ''),
            !empty($_POST['reportable']), (string)($_POST['reported_to'] ?? ''),
            (string)($_POST['reported_on'] ?? ''), $user);
    } elseif ($do === 'action') {
        $notice = incident_add_action($id, $_POST, $user);
    } elseif ($do === 'action_done') {
        $notice = incident_close_action((int)($_POST['action_id'] ?? 0),
            (string)($_POST['done_note'] ?? ''), $user);
    } elseif ($do === 'close') {
        $notice = incident_close($id, (string)($_POST['findings'] ?? ''), $user);
    }
    $inc = incident_get($id);
}

$where = (string)$inc['RoomCode'];
if ($where === '') {
    $where = trim((string)$inc['BldgNumber'] . ' ' . (string)$inc['RoomID']);
}
if ($where === '') {
    $where = (string)$inc['WrittenLocation'];
}

ui_top(title_case(picklist_label('incident_kind', (string)$inc['IncidentKind'])),
       (string)$inc['TicketNumber'], '', 'incidents.php', 'all reports');
?>

<?php if ($notice): ?>
<div class="panel <?= str_contains($notice, 'still open') || str_contains($notice, 'not ')
                     ? 'error' : 'ok' ?>"><?= h($notice) ?></div>
<?php endif; ?>

<div class="panel">
    <div class="grid">
        <div><strong>When</strong><br><?= h(ui_datetime(strtotime((string)$inc['OccurredAt']))) ?></div>
        <div><strong>Where</strong><br><?= h($where ?: 'not recorded') ?>
             <?= $inc['IsOffCampus'] ? '<span class="muted"> (not a PRI building)</span>' : '' ?></div>
        <div><strong>Reported by</strong><br><?= h((string)($inc['ReportedByName']
                                                    ?: $inc['ReportedByNetID'])) ?></div>
        <div><strong>Status</strong><br><?= h(nice_code((string)$inc['Status'])) ?></div>
        <?php if ($inc['Severity']): ?>
        <div><strong>Severity</strong><br><?= h(picklist_label('incident_severity',
                                                (string)$inc['Severity'])) ?></div>
        <?php endif; ?>
        <?php if ($inc['FleetNumber']): ?>
        <div><strong>Vehicle</strong><br><?= h((string)$inc['FleetNumber']) ?></div>
        <?php endif; ?>
        <?php if ($inc['SurveyCode']): ?>
        <div><strong>Survey</strong><br><?= h((string)$inc['SurveyCode']) ?></div>
        <?php endif; ?>
    </div>
    <?php if ($inc['IsReportable']): ?>
    <p class="warn"><strong>Reportable.</strong>
       <?= $inc['ReportedTo']
            ? 'Told ' . h((string)$inc['ReportedTo'])
              . ($inc['ReportedOutwardOn'] ? ' on ' . h(ui_date(strtotime((string)$inc['ReportedOutwardOn']))) : '')
            : 'Not yet reported onward.' ?></p>
    <?php endif; ?>
</div>

<div class="panel">
    <h2>What Happened</h2>
    <p><?= nl2br(h((string)$inc['Description'])) ?></p>
    <?php if ($inc['ImmediateAction']): ?>
    <h3>Done Straight Away</h3>
    <p><?= nl2br(h((string)$inc['ImmediateAction'])) ?></p>
    <?php endif; ?>
    <?php if ($inc['ContributingCause']): ?>
    <h3>What Contributed</h3>
    <p><?= nl2br(h((string)$inc['ContributingCause'])) ?></p>
    <?php endif; ?>
</div>

<?php if ($inc['people']): ?>
<div class="panel scroll-x">
    <h2>Who Was There</h2>
    <table>
        <tr><th>Name</th><th>They were</th><th>Injury</th><th>Where</th>
            <th>Treatment</th><th>Lost time</th></tr>
        <?php foreach ($inc['people'] as $p): ?>
        <tr>
            <td><?= h((string)$p['PersonName']) ?>
                <?= $p['NetID'] ? '<span class="muted"> ' . h((string)$p['NetID']) . '</span>' : '' ?></td>
            <td><?= h(picklist_label('incident_involvement', (string)$p['Involvement'])) ?></td>
            <td><?= h((string)$p['InjuryDescription']) ?></td>
            <td><?= h((string)$p['BodyPart']) ?></td>
            <td><?= h(picklist_label('incident_treatment', (string)$p['TreatmentLevel'])) ?>
                <?= $p['TreatedAt'] ? '<span class="muted"> &middot; '
                                      . h((string)$p['TreatedAt']) . '</span>' : '' ?></td>
            <td><?= $p['LostTimeDays'] !== null ? h((string)$p['LostTimeDays']) . ' days' : '' ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php endif; ?>

<div class="panel">
    <h2>Corrective Actions</h2>
    <?php if (!$inc['actions']): ?>
    <p class="muted">None recorded yet.</p>
    <?php else: ?>
    <div class="scroll-x">
    <table>
        <tr><th>What</th><th>Who</th><th>Due</th><th>Done</th><th></th></tr>
        <?php foreach ($inc['actions'] as $a): ?>
        <tr class="<?= $a['DoneOn'] ? 'done' : '' ?>">
            <td><?= h((string)$a['Action']) ?>
                <?= $a['WorkTicketNumber'] ? '<span class="muted"> &middot; '
                    . h((string)$a['WorkTicketNumber']) . '</span>' : '' ?></td>
            <td><?= h((string)$a['OwnerName']) ?></td>
            <td><?= $a['DueOn'] ? h(ui_date(strtotime((string)$a['DueOn']))) : '' ?></td>
            <td><?= $a['DoneOn'] ? h(ui_date(strtotime((string)$a['DoneOn']))) : '' ?>
                <?= $a['DoneNote'] ? '<br><span class="muted">' . h((string)$a['DoneNote'])
                                     . '</span>' : '' ?></td>
            <td>
            <?php if ($mayDecide && !$a['DoneOn']): ?>
                <form method="post" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="do" value="action_done">
                    <input type="hidden" name="action_id" value="<?= (int)$a['IncidentActionID'] ?>">
                    <input name="done_note" placeholder="What was done" size="18">
                    <button class="btn small">Done</button>
                </form>
            <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>

    <?php if ($mayDecide && $inc['Status'] !== 'closed'): ?>
    <form method="post" class="grid">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="action">
        <label class="span-all">What is being done
            <input name="action" required
                   placeholder="Replace the guard, retrain the crew, fit a second eyewash"></label>
        <label>Whose job it is
            <input name="owner" data-typeahead="person" data-fill="netid:act_netid"
                   autocomplete="off">
            <input type="hidden" name="owner_netid" id="act_netid"></label>
        <label>By when <input type="date" name="due"></label>
        <label>Work order, if one was raised
            <input name="work_ticket" placeholder="WO-FY27-00123"></label>
        <p class="span-all"><button class="btn">Add This Action</button></p>
    </form>
    <?php endif; ?>
</div>

<?php if ($mayDecide): ?>
<div class="panel">
    <h2>Safety Office</h2>
    <form method="post" class="grid">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="grade">
        <label>How serious
            <select name="severity">
                <option value="">Choose one</option>
                <?php foreach (picklist('incident_severity') as $c => $l): ?>
                <option value="<?= h($c) ?>"
                    <?= (string)$inc['Severity'] === $c ? 'selected' : '' ?>><?= h($l) ?></option>
                <?php endforeach; ?>
            </select></label>
        <label class="check"><input type="checkbox" name="reportable" value="1"
            <?= $inc['IsReportable'] ? 'checked' : '' ?>> Has to be reported outside PRI</label>
        <label>Reported to
            <input name="reported_to" value="<?= h((string)$inc['ReportedTo']) ?>"
                   placeholder="Campus DRS, OSHA, the insurer"></label>
        <label>On
            <input type="date" name="reported_on"
                   value="<?= h((string)$inc['ReportedOutwardOn']) ?>"></label>
        <p class="span-all"><button class="btn secondary">Record This</button></p>
    </form>
</div>

<?php if ($inc['Status'] !== 'closed'): ?>
<div class="panel">
    <h2>Close It</h2>
    <p class="muted">Only once every corrective action above is done.</p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="close">
        <label>What was concluded
            <textarea name="findings" rows="4" required></textarea></label>
        <p><button class="btn">Close This Report</button></p>
    </form>
</div>
<?php else: ?>
<div class="panel ok">
    <h2>Closed</h2>
    <p><?= nl2br(h((string)$inc['Findings'])) ?></p>
    <p class="muted">Closed by <?= h((string)$inc['ClosedByNetID']) ?>
       on <?= h(ui_date(strtotime((string)$inc['ClosedAt']))) ?>.</p>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($inc['attachments']): ?>
<div class="panel">
    <h2>Photographs</h2>
    <ul>
    <?php foreach ($inc['attachments'] as $a): ?>
        <li><a href="attachment.php?id=<?= (int)$a['AttachmentID'] ?>"><?=
            h((string)$a['OriginalName']) ?></a></li>
    <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="panel">
    <h2>History</h2>
    <ul class="timeline">
    <?php foreach ($inc['events'] as $e): ?>
        <li><strong><?= h(nice_code((string)$e['EventCode'])) ?></strong>
            &middot; <?= h(ui_datetime(strtotime((string)$e['CreatedAt']))) ?>
            <?= $e['ByName'] ? ' &middot; ' . h((string)$e['ByName']) : '' ?>
            <?= $e['Detail'] ? '<br><span class="muted">' . h((string)$e['Detail'])
                               . '</span>' : '' ?></li>
    <?php endforeach; ?>
    </ul>
</div>

<?php ui_bottom(); ?>
