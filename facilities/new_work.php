<?php
declare(strict_types=1);

/**
 * Facilities Work Request intake — triage first (spec Section 6).
 * Step 1 asks building, work type, and one line. Open requests for that
 * building render before the full form unlocks (gates one and two).
 * The create handler recomputes candidates server side and refuses a
 * submission that skipped the review (gate three).
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/dedupe.php';
require_once __DIR__ . '/lib/scoring.php';
require_once __DIR__ . '/lib/tickets.php';
require_once __DIR__ . '/lib/notify.php';
require_once __DIR__ . '/lib/rooms.php';
require_once __DIR__ . '/lib/attachments.php';

if (!app_installed()) {
    header('Location: install.php');
    exit;
}
handle_user_switch();
$user = current_user();
$pdo = db('work');

$buildings = $pdo->query('SELECT BuildingID, BldgNumber, Name, RequiresFreeText FROM ref.Buildings
                          WHERE IsActive = 1 ORDER BY SortKey')->fetchAll();
$types = $pdo->query('SELECT WorkTypeID, Name, IsPhoneOnly, PhoneInstruction FROM ref.WorkTypes
                      WHERE IsActive = 1 ORDER BY Name')->fetchAll();
$natures = $pdo->query('SELECT NatureID, Name FROM ref.Natures WHERE IsActive = 1 ORDER BY NatureID')->fetchAll();

$errors = [];
$blocking = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $in = [
        'building_id' => (int)($_POST['building_id'] ?? 0),
        'type_id'     => (int)($_POST['type_id'] ?? 0),
        'title'       => trim((string)($_POST['title'] ?? '')),
        'description' => trim((string)($_POST['description'] ?? '')),
        'nature_id'   => (int)($_POST['nature_id'] ?? 0),
        'severity'    => (int)($_POST['severity'] ?? 2),
        'life_safety'   => isset($_POST['life_safety']) ? 1 : 0,
        'specimen_risk' => isset($_POST['specimen_risk']) ? 1 : 0,
        'room'        => trim((string)($_POST['room'] ?? '')),
        'floor'       => trim((string)($_POST['floor'] ?? '')),
        'location'    => trim((string)($_POST['location'] ?? '')),
        'needed_by'   => trim((string)($_POST['needed_by'] ?? '')),
        'on_behalf'   => trim((string)($_POST['on_behalf'] ?? '')),
        'estimate'    => trim((string)($_POST['estimate'] ?? '')),
        'cfop'        => strtoupper(trim((string)($_POST['cfop'] ?? ''))),
        'room_code'   => trim((string)($_POST['room_code'] ?? '')),
    ];
    $shownIds = array_filter(array_map('intval', explode(',', (string)($_POST['shown_ids'] ?? ''))));

    // Validation.
    $bld = null;
    foreach ($buildings as $b) {
        if ((int)$b['BuildingID'] === $in['building_id']) {
            $bld = $b;
        }
    }
    $type = null;
    foreach ($types as $t) {
        if ((int)$t['WorkTypeID'] === $in['type_id']) {
            $type = $t;
        }
    }
    if (!$bld) {
        $errors[] = 'Choose a building.';
    }
    if (!$type) {
        $errors[] = 'Choose a work type.';
    } elseif (!empty($type['IsPhoneOnly'])) {
        $errors[] = $type['PhoneInstruction'] ?: 'This issue is handled by telephone, not by ticket.';
    }
    if ($in['title'] === '') {
        $errors[] = 'Describe the problem in one line.';
    }
    if ($in['description'] === '') {
        $errors[] = 'Provide a detailed description of the issue.';
    }
    if ($in['severity'] < 1 || $in['severity'] > 4) {
        $in['severity'] = 2;
    }
    if ($bld && !empty($bld['RequiresFreeText']) && $in['location'] === '') {
        $errors[] = 'This building selection requires a written description of the location.';
    }

    if (!$errors) {
        // Gate three: recompute candidates independently of the browser.
        $cands = dedupe_candidates([
            'building_id' => $in['building_id'],
            'type_id'     => $in['type_id'],
            'text'        => $in['title'] . ' ' . $in['description'],
            'room'        => $in['room'],
            'floor'       => $in['floor'],
            'location'    => $in['location'],
        ]);
        $mustAck = dedupe_blocking($cands);
        $unacked = array_filter($mustAck, fn($c) => !in_array((int)$c['WorkRequestID'], $shownIds, true));

        if ($unacked) {
            http_response_code(409);
            $blocking = array_values($unacked);
        } else {
            // Accept: record the decision trail, then create.
            $localUid = ensure_user('work', $user);
            foreach ($mustAck as $c) {
                $pdo->prepare('INSERT INTO wr.DedupDecisions (UserID, CandidateID, Decision, SimilarityScore)
                               VALUES (?, ?, ?, ?)')
                    ->execute([$localUid, (int)$c['WorkRequestID'], 'rejected', $c['_similarity']]);
            }
            $ownerId = (int)$pdo->query('SELECT OwnerID FROM ref.WorkTypes WHERE WorkTypeID = ' . $in['type_id'])->fetchColumn();
            $ticket = next_ticket_number('work');
            $stmt = $pdo->prepare(
                'INSERT INTO wr.WorkRequests
                   (TicketNumber, Title, Description, BuildingID, BldgNumber, WrittenLocation, RoomNumber,
                    FloorCode, WorkTypeID, NatureID, OwnerID, SurveyCode, ReportedSeverity, LifeSafety,
                    SpecimenRisk, NeededByDate, Status, CreatedByUserID, CreatedByNetID, OnBehalfOfName,
                    SearchBlob, RoomCode, EstimateAmount, CfopCode)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'open\', ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $ticket, $in['title'], $in['description'], $in['building_id'], $bld['BldgNumber'],
                $in['location'] ?: null, $in['room'] ?: null, $in['floor'] ?: null,
                $in['type_id'], $in['nature_id'] ?: null, $ownerId, $user['SurveyCode'],
                $in['severity'], $in['life_safety'], $in['specimen_risk'],
                $in['needed_by'] !== '' ? $in['needed_by'] : null,
                $localUid, $user['NetID'], $in['on_behalf'] ?: null,
                mb_strtolower($in['title'] . ' ' . $in['description'] . ' ' . $in['location'] . ' ' . $in['room']),
                $in['room_code'] ?: room_resolve('work', (string)$bld['BldgNumber'], $in['room']),
                $in['estimate'] !== '' ? (float)preg_replace('/[^0-9.]/', '', $in['estimate']) : null,
                $in['cfop'] ?: null,
            ]);
            $newId = (int)$pdo->lastInsertId();
            record_status('work', $newId, null, 'open', $localUid, 'Filed');
            scoring_recompute($newId);

            $typeName = '';
            foreach ($types as $t) {
                if ((int)$t['WorkTypeID'] === $in['type_id']) {
                    $typeName = $t['Name'];
                }
            }
            $ownerCode = (string)$pdo->query('SELECT Code FROM ref.Owners WHERE OwnerID = '
                . (int)$ownerId)->fetchColumn();
            foreach (attach_store_many('work', $newId, 'photos', 'photo', $user) as $m) {
                flash_set('ok', $m);
            }
            notify_work_request([
                'WorkRequestID' => $newId, 'TicketNumber' => $ticket, 'Title' => $in['title'],
                'Description' => $in['description'], 'BuildingName' => $bld['Name'],
                'BldgNumber' => $bld['BldgNumber'], 'RoomNumber' => $in['room'],
                'WrittenLocation' => $in['location'], 'TypeName' => $typeName,
                'ReportedSeverity' => $in['severity'], 'LifeSafety' => $in['life_safety'],
                'SpecimenRisk' => $in['specimen_risk'], 'CreatedByNetID' => $user['NetID'],
                'RequestedByName' => $user['DisplayName'],
                'SurveyCode' => $user['SurveyCode'], 'OwnerCode' => $ownerCode,
            ]);
            flash_set('ok', 'Work request ' . $ticket . ' filed.');
            header('Location: detail.php?type=work&id=' . $newId);
            exit;
        }
    }
}

ui_top('Facilities Work Request', '', '', 'index.php', 'request types');
flash_show();
?>
<div class="panel">
    <p class="urgent">If this issue is an emergency or urgent, call Matt Thompson at 217-300-1176
    before submitting the work request.</p>
</div>

<?php if ($errors): ?>
<div class="panel err"><ul>
    <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
</ul></div>
<?php endif; ?>

<?php if ($blocking !== null): ?>
<div class="panel err">
    <h2>These Open Requests Look Like the Same Problem</h2>
    <p>Review them before your request is accepted. If one of these is your issue, open it and
    choose <em>I am seeing this too</em>. Otherwise resubmit and your request will be filed.</p>
    <?php foreach ($blocking as $c): ?>
        <div class="candidate">
            <strong><a href="detail.php?type=work&amp;id=<?= (int)$c['WorkRequestID'] ?>"><?= h($c['TicketNumber']) ?>
            &mdash; <?= h($c['Title']) ?></a></strong>
            <div class="meta">
                <?= h($c['RoomNumber'] ? 'Room ' . $c['RoomNumber'] : ($c['WrittenLocation'] ?: '')) ?>
                &middot; <?= h($c['Status']) ?>
                &middot; <?= (int)$c['Confirmations'] ?> confirmation(s)
                &middot; filed <?= (int)floor((time() - strtotime((string)$c['CreatedAt'])) / 86400) ?> day(s) ago
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<form method="post" id="workform" class="panel" enctype="multipart/form-data" data-loc-group>
    <?= csrf_field() ?>
    <input type="hidden" name="shown_ids" id="shown_ids"
           value="<?= h((string)($_POST['shown_ids'] ?? '')) ?><?php
               if ($blocking) { echo h(($_POST['shown_ids'] ?? '') !== '' ? ',' : '')
                   . h(implode(',', array_map(fn($c) => (string)(int)$c['WorkRequestID'], $blocking))); } ?>">

    <h2>1. Where and What</h2>
    <div class="grid">
        <label>Building
            <select name="building_id" id="building_id" required data-loc-building>
                <option value="">Choose a building</option>
                <?php foreach ($buildings as $b): ?>
                <option value="<?= (int)$b['BuildingID'] ?>"
                    data-bldg="<?= h((string)$b['BldgNumber']) ?>"
                    data-freetext="<?= (int)$b['RequiresFreeText'] ?>"
                    <?= (int)$b['BuildingID'] === (int)($_POST['building_id'] ?? 0) ? 'selected' : '' ?>>
                    <?= h($b['Name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Work type
            <select name="type_id" id="type_id" required>
                <option value="">Choose a type</option>
                <?php foreach ($types as $t): ?>
                <option value="<?= (int)$t['WorkTypeID'] ?>"
                    data-phone="<?= (int)$t['IsPhoneOnly'] ?>"
                    data-instruction="<?= h((string)$t['PhoneInstruction']) ?>"
                    <?= (int)$t['WorkTypeID'] === (int)($_POST['type_id'] ?? 0) ? 'selected' : '' ?>>
                    <?= h($t['Name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
    <p class="urgent" id="phonewarn" hidden></p>
    <label>Describe the problem in one line
        <input name="title" id="title" maxlength="200" required
               value="<?= h((string)($_POST['title'] ?? '')) ?>"
               placeholder="e.g., Standing water in the basement hallway">
    </label>

    <div id="candidatebox" hidden>
        <h2>Open Requests for This Building</h2>
        <p class="muted">Look through these before continuing. If your problem is already
        listed, open it and choose <em>I am seeing this too</em> instead of filing again.</p>
        <div id="candidates" aria-live="polite"></div>
    </div>

    <div class="actions">
        <button type="button" id="continuebtn" class="primary" disabled>Continue to the Full Form</button>
    </div>

    <div id="fullform" <?= ($_SERVER['REQUEST_METHOD'] === 'POST' || demo_fill_full())
        ? '' : 'hidden' ?>>
        <h2>2. Details</h2>
        <div class="grid">
            <label>Nature of request
                <select name="nature_id">
                    <option value="">Choose one</option>
                    <?php foreach ($natures as $n): ?>
                    <option value="<?= (int)$n['NatureID'] ?>"
                        <?= (int)$n['NatureID'] === (int)($_POST['nature_id'] ?? 0) ? 'selected' : '' ?>>
                        <?= h($n['Name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>How severe is it?
                <select name="severity">
                    <?php
                    $sevLabels = [1 => '1 - Minor', 2 => '2 - Moderate', 3 => '3 - Serious', 4 => '4 - Emergency'];
                    $sevSel = (int)($_POST['severity'] ?? 2);
                    foreach ($sevLabels as $v => $label): ?>
                    <option value="<?= $v ?>" <?= $v === $sevSel ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <div data-loc-group class="grid">
            <label>Floor
                <select name="floor" data-loc-floor disabled>
                    <option value="">Choose a building first</option>
                </select>
            </label>
            <label>Room
                <select name="room" data-loc-room data-initial="<?= h((string)($_POST['room'] ?? '')) ?>">
                    <option value="">Choose a building first</option>
                </select>
            </label>
            <input type="hidden" name="room_code" data-loc-code value="<?= h((string)($_POST['room_code'] ?? '')) ?>">
            <p class="muted" data-loc-note class="span-all" hidden></p>
        </div>
        <label>Where exactly? (written location &mdash; e.g., east stairwell landing, loading dock apron)
            <input name="location" id="location" maxlength="300" value="<?= h((string)($_POST['location'] ?? '')) ?>">
        </label>
        <label>Detailed description of the issue
            <textarea name="description" rows="5" required><?= h((string)($_POST['description'] ?? '')) ?></textarea>
        </label>
        <label class="check"><input type="checkbox" name="life_safety" value="1"
            <?= isset($_POST['life_safety']) ? 'checked' : '' ?>> This is a risk to people</label>
        <label class="check"><input type="checkbox" name="specimen_risk" value="1"
            <?= isset($_POST['specimen_risk']) ? 'checked' : '' ?>> This is a risk to collections, specimens, or live animals</label>
        <div class="grid">
            <label>Needed by (optional)
                <input type="date" name="needed_by" value="<?= h((string)($_POST['needed_by'] ?? '')) ?>">
            </label>
            <label>Filing on behalf of (optional)
                <input name="on_behalf" id="on_behalf" maxlength="160" data-typeahead="person"
                       data-fill="netid:on_behalf_netid"
                       value="<?= h((string)($_POST['on_behalf'] ?? '')) ?>"
                       placeholder="type a surname and pick" autocomplete="off">
            </label>
            <input type="hidden" name="on_behalf_netid" id="on_behalf_netid"
                   value="<?= h((string)($_POST['on_behalf_netid'] ?? '')) ?>">
        </div>

        <h2>3. Money, If This Work Will Cost Anything</h2>
        <p class="muted">Leave both blank for a repair Facilities absorbs. An estimate above
        $<?= number_format((float)setting('estimate_approval_threshold')) ?> opens a fund manager
        approval, and above $<?= number_format((float)setting('director_approval_threshold')) ?>
        the Director signs as well. Triage can add or correct these later.</p>
        <div class="grid">
            <label>Estimated cost
                <input name="estimate" inputmode="decimal" placeholder="0.00"
                       value="<?= h((string)($_POST['estimate'] ?? '')) ?>">
            </label>
            <label>Account code (CFOP), if a unit is paying
                <input name="cfop" id="cfop" placeholder="X-XXXXXX-XXXXXX-XXXXXX-XXX"
                       data-typeahead="cfop"
                       data-fill="survey:cfop_survey,account_pi:cfop_pi,fund_manager:cfop_fm,description:cfop_desc"
                       value="<?= h((string)($_POST['cfop'] ?? '')) ?>" autocomplete="off">
            </label>
        </div>
        <p class="muted">Type part of the account or its purpose and pick it from the list. The
        survey, principal investigator and fund manager fill in from it, and they are who the
        approval goes to when the estimate crosses the threshold.</p>
        <div class="grid">
            <label>Survey <input id="cfop_survey" readonly tabindex="-1"></label>
            <label>Account PI <input id="cfop_pi" readonly tabindex="-1"></label>
            <label>Fund manager <input id="cfop_fm" readonly tabindex="-1"></label>
            <label class="span-all">Account purpose
                <input id="cfop_desc" readonly tabindex="-1"></label>
        </div>

        <h2>4. Photographs</h2>
        <p class="muted">A photograph of the problem saves a trip. If it carries location data,
        the coordinates are offered rather than applied.</p>
        <label>Attach photographs
            <input type="file" name="photos[]" multiple accept="image/*,.pdf">
        </label>

        <div class="actions">
            <button class="primary">Submit Work Request</button>
        </div>
    </div>
</form>
<?php ui_bottom(['location.js', 'work.js']); ?>
