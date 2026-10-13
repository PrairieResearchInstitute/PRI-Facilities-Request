<?php
declare(strict_types=1);

/**
 * Key request. Filed by anybody, approved by the director of the unit or
 * their proxy, and only then issued into the key register.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/requests2.php';

if (!app_installed()) {
    header('Location: install.php');
    exit;
}
handle_user_switch();
$user = current_user();
$pdo = db('keys');

$buildings = $pdo->query('SELECT BldgNumber, Name FROM ref.Buildings WHERE IsActive = 1
                          ORDER BY SortKey')->fetchAll();
const KEY_KINDS = [
    'new'         => 'A key I do not have',
    'additional'  => 'An extra key for space I already work in',
    'replacement' => 'Replacement for a lost or broken key',
    'return'      => 'Returning a key I no longer need',
];

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    // The survey comes from the person the key is for, which the name
    // lookup fills in; the filer may belong to a different one.
    $survey = strtoupper(trim((string)($_POST['holder_survey'] ?? ''))) ?: (string)$user['SurveyCode'];
    $kind = (string)($_POST['kind'] ?? 'new');
    $holderName = trim((string)($_POST['holder_name'] ?? ''));
    $holderNetID = trim((string)($_POST['holder_netid'] ?? ''));
    $bldg = trim((string)($_POST['bldg'] ?? ''));
    $room = trim((string)($_POST['room'] ?? ''));
    $floor = trim((string)($_POST['floor'] ?? ''));
    $justification = trim((string)($_POST['justification'] ?? ''));
    $keyType = trim((string)($_POST['key_type'] ?? 'Key'));
    $qty = (int)($_POST['qty'] ?? 1) ?: 1;
    $neededBy = trim((string)($_POST['needed_by'] ?? ''));

    if (!isset(KEY_KINDS[$kind])) {
        $errors[] = 'Choose what kind of request this is.';
    }
    if ($holderName === '') {
        $errors[] = 'Name the person the key is for.';
    }
    if ($bldg === '') {
        $errors[] = 'Choose the building.';
    }
    if ($justification === '') {
        $errors[] = 'Say why the key is needed; the director decides on it.';
    }

    if (!$errors) {
        $ticket = rq_ticket('key');
        $uid = ensure_user('work', $user);
        $pdo->prepare(
            'INSERT INTO rq.KeyRequests (TicketNumber, RequestedByUserID, RequestedByNetID,
                SurveyCode, HolderName, HolderNetID, EmpStatus, BldgNumber, FloorCode, RoomID,
                RoomCode, KeyType, Quantity, RequestKind, Justification, NeededByDate, Status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'submitted\')'
        )->execute([
            $ticket, $uid, $user['NetID'], $survey, $holderName,
            $holderNetID ?: null, trim((string)($_POST['emp_status'] ?? '')) ?: null,
            $bldg, $floor ?: null, $room ?: null,
            room_resolve('work', $bldg, $room), $keyType, $qty, $kind, $justification,
            $neededBy !== '' ? $neededBy : null,
        ]);
        $id = (int)$pdo->lastInsertId();
        rq_open_chain('key', $id, $survey);

        $to = notify_audience('key_request_created', [
            'survey' => $survey, 'building' => $bldg,
        ]);
        notify_send('work', 'key_request_created', $to,
            'Key request ' . $ticket . ' needs approval',
            "A key request has been filed and is with the unit's director or their administrative assistant.\n\n"
            . 'Key for: ' . $holderName . ($holderNetID !== '' ? ' (' . $holderNetID . ')' : '') . "\n"
            . 'Building: ' . $bldg . ($room !== '' ? ', room ' . $room : '') . "\n"
            . 'Request: ' . KEY_KINDS[$kind] . "\n"
            . 'Filed by: ' . $user['DisplayName'] . ' (' . $user['NetID'] . ")\n"
            . "\nJustification:\n" . $justification . "\n\n"
            . notify_base_url() . 'request.php?type=key&id=' . $id,
            $id, $ticket);

        flash_set('ok', 'Key request ' . $ticket . ' filed. It is with the '
            . $survey . ' director or their proxy.');
        header('Location: request.php?type=key&id=' . $id);
        exit;
    }
}

ui_top('Key Request', '<a href="index.php">&larr; request types</a>');
flash_show();
?>
<div class="panel">
    <p>Ask for a key or a card credential. The director of your unit, or their administrative assistant, approves it, and
    facilities issues it into the key register afterwards. Nothing is cut until it is approved.</p>
</div>

<?php if ($errors): ?>
<div class="panel err"><ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" class="panel" data-loc-group>
    <?= csrf_field() ?>
    <h2>1. Who the Key Is For</h2>
    <div class="grid">
        <label>Name
            <input name="holder_name" id="holder_name" required placeholder="Last, First"
                   data-typeahead="person"
                   data-fill="netid:holder_netid,survey:holder_survey"
                   value="<?= h((string)($_POST['holder_name'] ?? $user['DisplayName'])) ?>"
                   autocomplete="off">
        </label>
        <label>NetID
            <input name="holder_netid" id="holder_netid"
                   value="<?= h((string)($_POST['holder_netid'] ?? $user['NetID'])) ?>">
        </label>
        <label>Survey
            <input name="holder_survey" id="holder_survey" readonly tabindex="-1"
                   value="<?= h((string)($_POST['holder_survey'] ?? $user['SurveyCode'])) ?>">
        </label>
        <label>Employment status
            <select name="emp_status">
                <option value="">--</option>
                <?php foreach (['Active', 'Student', 'Visitor', 'Retired'] as $s): ?>
                <option <?= ($_POST['emp_status'] ?? '') === $s ? 'selected' : '' ?>><?= h($s) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>

    <h2>2. What Access</h2>
    <div class="grid">
        <label>Request
            <select name="kind">
                <?php foreach (KEY_KINDS as $k => $label): ?>
                <option value="<?= h($k) ?>" <?= ($_POST['kind'] ?? '') === $k ? 'selected' : '' ?>>
                    <?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Type
            <select name="key_type">
                <?php foreach (['Key', 'iCard', 'Padlock', 'Cabinet key', 'Other'] as $t): ?>
                <option <?= ($_POST['key_type'] ?? '') === $t ? 'selected' : '' ?>><?= h($t) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>How many <input name="qty" size="4" value="<?= h((string)($_POST['qty'] ?? '1')) ?>"></label>
        <label>Needed by <input type="date" name="needed_by" value="<?= h((string)($_POST['needed_by'] ?? '')) ?>"></label>
    </div>
    <div class="grid">
        <label>Building
            <select name="bldg" data-loc-building required>
                <option value="">--Select Building--</option>
                <?php foreach ($buildings as $b): ?>
                <option value="<?= h((string)$b['BldgNumber']) ?>" data-bldg="<?= h((string)$b['BldgNumber']) ?>"
                    <?= (string)$b['BldgNumber'] === (string)($_POST['bldg'] ?? '') ? 'selected' : '' ?>>
                    <?= h($b['BldgNumber'] . ' — ' . $b['Name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Floor <select name="floor" data-loc-floor disabled><option value="">--</option></select></label>
        <label>Room
            <select name="room" data-loc-room data-initial="<?= h((string)($_POST['room'] ?? '')) ?>">
                <option value="">--Select a building first--</option>
            </select>
        </label>
        <input type="hidden" data-loc-code>
        <p class="muted" data-loc-note style="grid-column:1/-1" hidden></p>
    </div>

    <h2>3. Why</h2>
    <label>Justification
        <textarea name="justification" rows="4" required
            placeholder="What the person does in that space, and why they need their own access."><?= h((string)($_POST['justification'] ?? '')) ?></textarea>
    </label>

    <div class="actions">
        <button class="primary">Submit key request</button>
    </div>
</form>
<script src="assets/location.js"></script>
<?php ui_bottom(); ?>
