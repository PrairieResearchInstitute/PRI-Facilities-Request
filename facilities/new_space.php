<?php
declare(strict_types=1);

/**
 * Space request. Anybody may ask; the survey's space committee and then
 * its director decide; a grant writes through to the room assignment.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/requests2.php';

if (!app_installed()) {
    header('Location: install.php');
    exit;
}
handle_user_switch();
$user = current_user();
$pdo = db('space');

$buildings = $pdo->query('SELECT BldgNumber, Name FROM ref.Buildings WHERE IsActive = 1
                          ORDER BY SortKey')->fetchAll();

/** An account from the directory, or null if it is not one we can charge. */
function space_account(string $cfop): ?array
{
    $stmt = db('shop')->prepare(
        'SELECT CfopCode, SurveyCode, AccountPI, FundManager, Description
         FROM ref.CfopAccounts WHERE CfopCode = ?');
    $stmt->execute([$cfop]);
    return $stmt->fetch() ?: null;
}
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $kind = (string)($_POST['kind'] ?? 'additional');
    $justification = trim((string)($_POST['justification'] ?? ''));
    $use = trim((string)($_POST['space_use'] ?? ''));
    $people = trim((string)($_POST['people'] ?? ''));
    $neededBy = trim((string)($_POST['needed_by'] ?? ''));
    $curBldg = trim((string)($_POST['cur_bldg'] ?? ''));
    $curRoom = trim((string)($_POST['cur_room'] ?? ''));
    $reqBldg = trim((string)($_POST['req_bldg'] ?? ''));
    $reqRoom = trim((string)($_POST['req_room'] ?? ''));

    if (!isset(SPACE_KINDS[$kind])) {
        $errors[] = 'Choose what kind of request this is.';
    }
    $submittedBy = trim((string)($_POST['submitted_by'] ?? ''));
    $requesterEmail = trim((string)($_POST['requester_email'] ?? ''));
    $staffAffected = trim((string)($_POST['staff_affected'] ?? ''));
    $requestedChange = trim((string)($_POST['requested_change'] ?? ''));
    $potentialCosts = trim((string)($_POST['potential_costs'] ?? ''));
    $fundingSource = trim((string)($_POST['funding_source'] ?? ''));
    $fundingCfop = strtoupper(trim((string)($_POST['funding_cfop'] ?? '')));
    $spaceType = (string)($_POST['space_type'] ?? '');
    $spaceTypeOther = trim((string)($_POST['space_type_other'] ?? ''));
    $submitterNetID = strtolower(trim((string)($_POST['submitter_netid'] ?? ''))) ?: (string)$user['NetID'];
    $survey = strtoupper(trim((string)($_POST['survey_code'] ?? ''))) ?: (string)$user['SurveyCode'];

    // The account is verified here rather than trusted from the form: the
    // fund manager and PI decide who approves a request that costs money,
    // so they are read from the directory and stored as they stand today.
    $account = null;
    if ($fundingCfop !== '') {
        $account = space_account($fundingCfop);
        if ($account === null) {
            $errors[] = 'That account is not in the directory, so it cannot be charged. '
                      . 'Leave it blank if no account is involved yet.';
        }
    }

    if ($requestedChange === '') {
        $errors[] = 'Describe the change you are asking for.';
    }
    if ($justification === '') {
        $errors[] = 'A reason is required; the committee decides on it.';
    }
    if (!isset(SPACE_TYPES[$spaceType])) {
        $errors[] = 'Choose what kind of space is needed.';
    }
    if ($spaceType === 'other' && $spaceTypeOther === '') {
        $errors[] = 'Describe the kind of space, since it is not one of the five listed.';
    }
    if ($requesterEmail !== '' && !filter_var($requesterEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'That does not look like an email address.';
    }
    if ($kind === 'release' && $curBldg === '') {
        $errors[] = 'Say which space is being released.';
    }

    if (!$errors) {
        $ticket = rq_ticket('space');
        $uid = ensure_user('space', $user);
        $pdo->prepare(
            'INSERT INTO rq.SpaceRequests (TicketNumber, RequestedByUserID, RequestedByNetID,
                SurveyCode, RequestKind, CurrentBldgNumber, CurrentRoomID, CurrentRoomCode,
                RequestedBldgNumber, RequestedRoomID, RequestedRoomCode, PeopleAffected,
                SpaceUse, NeededByDate, Justification, Status,
                SubmittedByNames, RequesterEmail, StaffAffected, RequestedChange,
                PotentialCosts, FundingSource, SpaceType, SpaceTypeOther,
                FundingCfop, FundingPI, FundingFundManager, FundingSurveyCode,
                FundingDescription, SubmitterNetID, SubmitterSurvey)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'submitted\',
                     ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $ticket, $uid, $user['NetID'], $survey, $kind,
            $curBldg ?: null, $curRoom ?: null,
            $curBldg !== '' ? room_resolve('space', $curBldg, $curRoom) : null,
            $reqBldg ?: null, $reqRoom ?: null,
            $reqBldg !== '' ? room_resolve('space', $reqBldg, $reqRoom) : null,
            is_numeric($people) ? (int)$people : null,
            $use ?: null, $neededBy !== '' ? $neededBy : null, $justification,
            $submittedBy ?: $user['DisplayName'],
            $requesterEmail ?: $user['Email'],
            $staffAffected ?: null, $requestedChange,
            $potentialCosts ?: null, $fundingSource ?: null,
            $spaceType, $spaceTypeOther ?: null,
            $account['CfopCode'] ?? null, $account['AccountPI'] ?? null,
            $account['FundManager'] ?? null, $account['SurveyCode'] ?? null,
            $account['Description'] ?? null, $submitterNetID, $survey,
        ]);
        $id = (int)$pdo->lastInsertId();
        rq_open_chain('space', $id, $survey);

        // An account brings its own people into the conversation: the fund
        // manager and the PI are told, because the money is theirs.
        $to = notify_audience('space_request_created', [
            'survey' => $survey, 'building' => $reqBldg ?: $curBldg,
        ]);
        notify_send('space', 'space_request_created', $to,
            'Space request ' . $ticket . ' needs review',
            "A space request has been filed and is with the survey space committee.\n\n"
            . 'Request: ' . SPACE_KINDS[$kind] . "\n"
            . 'From: ' . ($submittedBy ?: $user['DisplayName']) . ' (' . $submitterNetID . ")\n"
            . 'Survey: ' . $survey . "\n"
            . 'Type of space: ' . (SPACE_TYPES[$spaceType] ?? $spaceType)
            . ($spaceTypeOther !== '' ? ' — ' . $spaceTypeOther : '') . "\n"
            . ($use !== '' ? 'Intended use: ' . $use . "\n" : '')
            . ($people !== '' ? 'People affected: ' . $people . "\n" : '')
            . ($account !== null
                ? "\nAccount: " . $account['CfopCode'] . ' (' . $account['Description'] . ")\n"
                  . '  PI: ' . $account['AccountPI'] . "\n"
                  . '  Fund manager: ' . $account['FundManager'] . "\n"
                : '')
            . "\nJustification:\n" . $justification . "\n\n"
            . notify_base_url() . 'request.php?type=space&id=' . $id,
            $id, $ticket);

        flash_set('ok', 'Space request ' . $ticket . ' filed. It is with the '
            . $survey . ' space committee, then the director.');
        header('Location: request.php?type=space&id=' . $id);
        exit;
    }
}

ui_top('Space Request', '<a href="index.php">&larr; request types</a>');
flash_show();

function space_building_options(array $buildings, string $sel): void
{
    echo '<option value="">--Select Building--</option>';
    foreach ($buildings as $b) {
        echo '<option value="' . h((string)$b['BldgNumber']) . '" data-bldg="' . h((string)$b['BldgNumber']) . '"'
           . ((string)$b['BldgNumber'] === $sel ? ' selected' : '') . '>'
           . h($b['BldgNumber'] . ' — ' . $b['Name']) . '</option>';
    }
}
?>
<div class="panel">
    <p>Ask for space, to change how a room is used, or to give space back. Your survey's space
    committee reviews the request and the director decides. Nothing changes on a floor plan until
    it is granted and a room is assigned.</p>
    <p class="muted">You will be contacted within 30 days of submitting.
    <?php
    // The reader's own unit's space contact, not somebody else's. This
    // used to name one survey's contact to everybody, which was wrong for
    // five readers out of six.
    require_once __DIR__ . '/lib/unitcontacts.php';
    echo unit_contact_line((string)$user['SurveyCode'], 'space', 'the space request process');
    ?></p>
</div>

<?php if ($errors): ?>
<div class="panel err"><ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" class="panel">
    <?= csrf_field() ?>
    <h2>1. Who Is Asking</h2>
    <div class="grid">
        <label>Primary requester
            <input name="submitted_by" id="submitted_by" data-typeahead="person"
                   data-fill="netid:submitter_netid,email:requester_email,survey:survey_code"
                   value="<?= h((string)($_POST['submitted_by'] ?? $user['DisplayName'])) ?>"
                   autocomplete="off">
        </label>
        <label>Email address
            <input type="email" name="requester_email" id="requester_email"
                   value="<?= h((string)($_POST['requester_email'] ?? $user['Email'])) ?>">
        </label>
        <label>Survey
            <input name="survey_code" id="survey_code" readonly tabindex="-1"
                   value="<?= h((string)($_POST['survey_code'] ?? $user['SurveyCode'])) ?>">
        </label>
    </div>
    <input type="hidden" name="submitter_netid" id="submitter_netid"
           value="<?= h((string)($_POST['submitter_netid'] ?? $user['NetID'])) ?>">
    <p class="muted">Type a surname and pick the person; the email address and survey arrive with
    them. Leave it as yourself unless you are filing on somebody else's behalf. The survey decides
    which space committee and which director see this, so it is worth getting right.</p>
    <label>Staff members affected other than the primary requester
        <textarea name="staff_affected" rows="2"><?= h((string)($_POST['staff_affected'] ?? '')) ?></textarea>
    </label>

    <h2>2. What Are You Asking For</h2>
    <label>Request
        <select name="kind" required>
            <?php foreach (SPACE_KINDS as $k => $label): ?>
            <option value="<?= h($k) ?>" <?= ($_POST['kind'] ?? '') === $k ? 'selected' : '' ?>>
                <?= h($label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Requested change
        <textarea name="requested_change" rows="3" required
            placeholder="What should change: the space you want, the use you want it put to, or the space you are giving up."><?= h((string)($_POST['requested_change'] ?? '')) ?></textarea>
    </label>

    <fieldset>
        <legend>What type of space is needed?</legend>
        <p class="muted">If several types are needed, choose the first that applies.</p>
        <?php foreach (SPACE_TYPES as $k => $label):
            if (in_array($k, SPACE_TYPES_RETIRED, true)) { continue; } ?>
        <label class="check"><input type="radio" name="space_type" value="<?= h($k) ?>"
            <?= ($_POST['space_type'] ?? '') === $k ? 'checked' : '' ?>> <?= h($label) ?></label>
        <?php endforeach; ?>
        <label>If other, describe it
            <input name="space_type_other" value="<?= h((string)($_POST['space_type_other'] ?? '')) ?>">
        </label>
    </fieldset>

    <div class="grid">
        <label>Intended use, in your own words
            <input name="space_use" placeholder="wet lab, collection storage, shared office"
                   value="<?= h((string)($_POST['space_use'] ?? '')) ?>">
        </label>
        <label>How many people
            <input name="people" size="6" value="<?= h((string)($_POST['people'] ?? '')) ?>">
        </label>
        <label>Needed by
            <input type="date" name="needed_by" value="<?= h((string)($_POST['needed_by'] ?? '')) ?>">
        </label>
    </div>

    <h2>3. Space You Hold Now</h2>
    <p class="muted">Leave empty if this is not about space you already occupy.</p>
    <div class="grid" data-loc-group>
        <label>Building
            <select name="cur_bldg" data-loc-building>
                <?php space_building_options($buildings, (string)($_POST['cur_bldg'] ?? '')); ?>
            </select>
        </label>
        <label>Floor <select name="cur_floor" data-loc-floor disabled><option value="">--</option></select></label>
        <label>Room
            <select name="cur_room" data-loc-room data-initial="<?= h((string)($_POST['cur_room'] ?? '')) ?>">
                <option value="">--Select a building first--</option>
            </select>
        </label>
        <input type="hidden" data-loc-code>
        <p class="muted" data-loc-note style="grid-column:1/-1" hidden></p>
    </div>

    <h2>4. Space You Are Asking For</h2>
    <p class="muted">If you have somewhere particular in mind, name it. Otherwise describe what you
    need in the justification and the committee will look for something suitable.</p>
    <div class="grid" data-loc-group>
        <label>Building
            <select name="req_bldg" data-loc-building>
                <?php space_building_options($buildings, (string)($_POST['req_bldg'] ?? '')); ?>
            </select>
        </label>
        <label>Floor <select name="req_floor" data-loc-floor disabled><option value="">--</option></select></label>
        <label>Room
            <select name="req_room" data-loc-room data-initial="<?= h((string)($_POST['req_room'] ?? '')) ?>">
                <option value="">--Select a building first--</option>
            </select>
        </label>
        <input type="hidden" data-loc-code>
        <p class="muted" data-loc-note style="grid-column:1/-1" hidden></p>
    </div>

    <h2>5. Why, and What It Costs</h2>
    <label>Reason for the request
        <textarea name="justification" rows="5" required
            placeholder="What the space is for, what happens without it, and how long you need it."><?= h((string)($_POST['justification'] ?? '')) ?></textarea>
    </label>
    <label>Potential costs
        <input name="potential_costs" placeholder="none known, or an estimate"
               value="<?= h((string)($_POST['potential_costs'] ?? '')) ?>">
    </label>

    <label>Source of funds
        <input name="funding_cfop" id="funding_cfop" placeholder="X-XXXXXX-XXXXXX-XXXXXX-XXX"
               data-typeahead="cfop"
               data-fill="survey:fund_survey,account_pi:fund_pi,fund_manager:fund_fm,description:fund_desc"
               value="<?= h((string)($_POST['funding_cfop'] ?? '')) ?>" autocomplete="off">
    </label>
    <p class="muted">Only needed if the request costs money. Type part of the account or its
    purpose and pick it from the list; the survey, principal investigator and fund manager fill in
    from it and go to the committee with the request, because they are the people who have to
    agree to the spending. If no account is settled yet, leave it blank and say so below.</p>
    <div class="grid">
        <label>Survey <input id="fund_survey" readonly tabindex="-1"></label>
        <label>Account PI <input id="fund_pi" readonly tabindex="-1"></label>
        <label>Fund manager <input id="fund_fm" readonly tabindex="-1"></label>
        <label style="grid-column:1/-1">Account purpose
            <input id="fund_desc" readonly tabindex="-1"></label>
    </div>
    <label>Anything else about the funding, such as a grant name
        <input name="funding_source" value="<?= h((string)($_POST['funding_source'] ?? '')) ?>">
    </label>

    <div class="actions">
        <button class="primary">Submit space request</button>
    </div>
</form>
<script src="assets/location.js"></script>
<?php ui_bottom(); ?>
