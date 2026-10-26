<?php
declare(strict_types=1);

/**
 * Shop Services Work Request intake (spec Section 11). The CFOP comes
 * first because it resolves the survey, account PI, and fund manager;
 * those render read-only. An unmatched CFOP blocks submission. The
 * approval chain (Account PI, Fund Manager, Shop Supervisor) opens at
 * creation.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/picklists.php';
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
$pdo = db('shop');

$buildings = $pdo->query('SELECT BuildingID, BldgNumber, Name FROM ref.Buildings
                          WHERE IsActive = 1 ORDER BY SortKey')->fetchAll();

// Fleet vehicles are loaded from the fleet listing and maintained in Admin.
$vehicles = [];
try {
    // The fleet has a database of its own; a shop request names a vehicle
    // by its fleet number, which means the same thing in both.
    $vehicles = db('fleet')->query('SELECT FleetVehicleID, FleetNumber, Description, HomeBldgNumber
                             FROM ref.FleetVehicles WHERE IsActive = 1 ORDER BY FleetNumber')->fetchAll();
} catch (Throwable $e) {
    $vehicles = [];
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $cfop = strtoupper(trim((string)($_POST['cfop'] ?? '')));
    $neededBy = trim((string)($_POST['needed_by'] ?? ''));
    $desc = trim((string)($_POST['description'] ?? ''));
    $just = trim((string)($_POST['justification'] ?? ''));
    $bldgNumberIn = trim((string)($_POST['bldg'] ?? ''));
    $room = trim((string)($_POST['room'] ?? ''));
    $floor = trim((string)($_POST['floor'] ?? ''));
    $roomCode = trim((string)($_POST['room_code'] ?? ''));
    $location = trim((string)($_POST['location'] ?? ''));
    $buildingId = 0;
    foreach ($buildings as $b) {
        if ($b['BldgNumber'] === $bldgNumberIn) {
            $buildingId = (int)$b['BuildingID'];
        }
    }
    $kind = ($_POST['request_kind'] ?? 'general') === 'vehicle' ? 'vehicle' : 'general';
    $vehicleId = (int)($_POST['fleet_vehicle_id'] ?? 0);

    // The two kinds ask different questions, so they are read from
    // different fields and only the relevant ones are validated. A person
    // who filled in half of one and then switched does not carry the
    // other half's requirements with them.
    $vehicle = null;
    $shopService = trim((string)($_POST['shop_service'] ?? ''));
    $vehService = trim((string)($_POST['vehicle_service'] ?? ''));
    $vehUrgency = trim((string)($_POST['vehicle_urgency'] ?? ''));
    $odometer = preg_replace('/[^0-9]/', '', (string)($_POST['odometer'] ?? ''));
    $vehWhere = trim((string)($_POST['vehicle_location'] ?? ''));
    $dimensions = trim((string)($_POST['dimensions'] ?? ''));
    $materials = trim((string)($_POST['materials'] ?? ''));

    if ($kind === 'vehicle') {
        $desc = trim((string)($_POST['description_v'] ?? ''));
        $neededBy = trim((string)($_POST['needed_by_v'] ?? ''));
        foreach ($vehicles as $v) {
            if ((int)$v['FleetVehicleID'] === $vehicleId) {
                $vehicle = $v;
            }
        }
        if (!$vehicle) {
            $errors[] = 'Choose the vehicle this request is for.';
        }
        if ($vehService === '') {
            $errors[] = 'Say what the vehicle needs.';
        }
        // The location matters when the vehicle cannot be driven to the shop.
        if (in_array($vehUrgency, ['down', 'stranded'], true) && $vehWhere === '') {
            $errors[] = 'The vehicle cannot be driven, so say where it is.';
        }
    } elseif ($shopService === '') {
        $errors[] = 'Say what kind of work is being asked for.';
    }

    // Server-side CFOP resolution; never trust the read-only fields.
    $acct = false;
    if ($cfop !== '') {
        try {
            $stmt = $pdo->prepare('SELECT * FROM ref.CfopAccounts WHERE CfopCode = ? AND IsActive = 1');
            $stmt->execute([$cfop]);
            $acct = $stmt->fetch();
        } catch (Throwable $e) {
            $acct = false;
        }
    }
    if (!$acct) {
        $errors[] = 'The CFOP was not recognized. Enter a PRI CFOP in the format '
                  . 'X-XXXXXX-XXXXXX-XXXXXX-XXX. If you believe this is an error, send a support '
                  . 'request to configure-me@example.edu.';
    }
    if ($kind !== 'vehicle' && $neededBy === '') {
        $errors[] = 'Desired completion date is required.';
    }
    if ($desc === '') {
        $errors[] = $kind === 'vehicle'
            ? 'Describe what is wrong, or what service is due.'
            : 'Provide a detailed description of the work to be completed.';
    }
    if ($just === '') {
        $errors[] = 'Business justification is required (who, what, when, where, and why/benefit '
                  . 'to the University).';
    }

    if (!$errors) {
        $bldgNum = null;
        foreach ($buildings as $b) {
            if ((int)$b['BuildingID'] === $buildingId) {
                $bldgNum = $b['BldgNumber'];
            }
        }
        // The requester is whoever the work is for; the person keying it
        // is recorded separately so filing on behalf stays honest.
        $requestedNetID = strtolower(trim((string)($_POST['requested_netid'] ?? ''))) ?: $user['NetID'];
        $requester = $user;
        if ($requestedNetID !== $user['NetID']) {
            $st = $pdo->prepare('SELECT * FROM app.Users WHERE NetID = ?');
            $st->execute([$requestedNetID]);
            $found = $st->fetch();
            if ($found) {
                $requester = $found;
            } else {
                $dir = db('work')->prepare('SELECT NetID, DisplayName, Email, SurveyCode
                                            FROM ref.People WHERE NetID = ?');
                $dir->execute([$requestedNetID]);
                $p = $dir->fetch();
                if ($p) {
                    $requester = ['NetID' => $p['NetID'], 'DisplayName' => $p['DisplayName'],
                                  'Email' => $p['Email'], 'SurveyCode' => $p['SurveyCode'],
                                  'Role' => 'staff'];
                }
            }
        }
        $localUid = ensure_user('shop', $requester);
        $ticket = next_ticket_number('shop');
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO sh.ShopRequests
                   (TicketNumber, RequestedByUserID, RequestedByNetID, NeededByDate, CfopCode,
                    SurveyCode, AccountPI, FundManager, WorkDescription, BusinessJustification,
                    BuildingID, BldgNumber, RoomNumber, WrittenLocation, Status,
                    RequestKind, FleetVehicleID, FleetNumber, RoomCode, FloorCode, FiledByNetID)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'awaiting_approval\', ?, ?, ?, ?, ?, ?)'
            )->execute([
                $ticket, $localUid, $requester['NetID'], $neededBy, $acct['CfopCode'],
                $acct['SurveyCode'], $acct['AccountPI'], $acct['FundManager'], $desc, $just,
                $buildingId ?: null, $bldgNumberIn ?: null, $room ?: null, $location ?: null,
                $kind, $vehicle['FleetVehicleID'] ?? null, $vehicle['FleetNumber'] ?? null,
                $roomCode ?: room_resolve('shop', $bldgNumberIn, $room), $floor ?: null,
                $user['NetID'],
            ]);
            $shopId = (int)$pdo->lastInsertId();

            // Approval chain opens at intake (spec Section 9): PI, Fund Manager, Shop Supervisor.
            $stage = $pdo->prepare('INSERT INTO sh.Approvals (ShopRequestID, StageCode, StageOrder, ApproverName)
                                    VALUES (?, ?, ?, ?)');
            $stage->execute([$shopId, 'account_pi', 1, $acct['AccountPI']]);
            $stage->execute([$shopId, 'fund_manager', 2, $acct['FundManager']]);
            $stage->execute([$shopId, 'shop_supervisor', 3, null]);

            record_status('shop', $shopId, null, 'awaiting_approval', $localUid,
                'Filed; approval chain opened (copied to PI for review)');
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        attach_store_many('shop', $shopId, 'drawings', 'drawing', $user);
        notify_shop_request([
            'ShopRequestID' => $shopId, 'TicketNumber' => $ticket,
            'RequestedByNetID' => $user['NetID'], 'RequestedByName' => $user['DisplayName'],
            'NeededByDate' => $neededBy, 'CfopCode' => $acct['CfopCode'],
            'AccountPI' => $acct['AccountPI'], 'FundManager' => $acct['FundManager'],
            'WorkDescription' => $desc, 'BusinessJustification' => $just,
            'SurveyCode' => $acct['SurveyCode'], 'BldgNumber' => $bldgNumberIn,
        ], $vehicle);
        flash_set('ok', 'Shop request ' . $ticket . ' filed. It has been copied to your PI ('
            . $acct['AccountPI'] . ') for review.');
        header('Location: detail.php?type=shop&id=' . $shopId);
        exit;
    }
}

ui_top('Shop Services Work Request', '', '', 'index.php', 'request types');
flash_show();
?>
<div class="panel">
    <p>To facilitate getting equipment created/modified, repaired, or to have maintenance work
    performed, please complete and submit a Shop Services Request. When your request is
    submitted, it will also be copied to your PI for review. Drawings attach to the ticket
    after filing &mdash; no separate email needed.</p>
</div>

<?php if ($errors): ?>
<div class="panel err"><ul>
    <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
</ul></div>
<?php endif; ?>

<form method="post" class="panel" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <h2>Requested By</h2>
    <label>Person the work is for
        <input name="requested_by" id="requested_by" data-typeahead="person"
               data-fill="netid:requested_netid,email:requested_email"
               value="<?= h((string)($_POST['requested_by'] ?? $user['DisplayName'])) ?>"
               autocomplete="off">
    </label>
    <input type="hidden" name="requested_netid" id="requested_netid"
           value="<?= h((string)($_POST['requested_netid'] ?? $user['NetID'])) ?>">
    <input type="hidden" id="requested_email">
    <p class="muted">Type a surname and pick the person. Leave it as yourself unless you are
    filing on somebody else's behalf; either way the system records that you keyed it.</p>

    <h2>Account</h2>
    <label>Account information (PRI CFOP)
        <input name="cfop" id="cfop" required placeholder="X-XXXXXX-XXXXXX-XXXXXX-XXX"
               data-typeahead="cfop"
               data-fill="survey:cfop_survey,account_pi:cfop_pi,fund_manager:cfop_fm,description:cfop_desc"
               value="<?= h((string)($_POST['cfop'] ?? '')) ?>" autocomplete="off">
    </label>
    <p class="muted" id="cfopstate">Enter your PRI CFOP in the format
    X-XXXXXX-XXXXXX-XXXXXX-XXX, then wait to select the matching account from the list. The
    survey, account PI, and fund manager fill in from it. If your CFOP is not listed it is not
    valid for this form; send a support request to configure-me@example.edu if you believe
    that is an error.</p>
    <div class="grid">
        <label>Survey <input id="cfop_survey" readonly tabindex="-1"></label>
        <label>Account PI <input id="cfop_pi" readonly tabindex="-1"></label>
        <label>Fund Manager <input id="cfop_fm" readonly tabindex="-1"></label>
        <label class="span-all">Account purpose
            <input id="cfop_desc" readonly tabindex="-1"></label>
    </div>

    <h2>Work</h2>
    <fieldset>
        <legend>What kind of work is this?</legend>
        <p class="muted">These are two different jobs and they need different things asked, so the
        rest of the form changes with the answer.</p>
        <label class="check"><input type="radio" name="request_kind" value="general" id="kind_general"
            <?= ($_POST['request_kind'] ?? 'general') !== 'vehicle' ? 'checked' : '' ?>>
            <strong>Shop work</strong> &mdash; build, modify, repair or install something</label>
        <label class="check"><input type="radio" name="request_kind" value="vehicle" id="kind_vehicle"
            <?= ($_POST['request_kind'] ?? '') === 'vehicle' ? 'checked' : '' ?>>
            <strong>Vehicle</strong> &mdash; service, repair or inspection on a fleet vehicle</label>
    </fieldset>

    <!-- ===================== shop work ===================== -->
    <div data-kind="general">
        <div class="grid">
            <label>What is being asked for
                <?= picklist_select('shop_service', 'shop_service',
                        (string)($_POST['shop_service'] ?? ''),
                        ['blank' => '--Select--']) ?>
            </label>
            <label>Desired completion date
                <input type="date" name="needed_by" value="<?= h((string)($_POST['needed_by'] ?? '')) ?>">
            </label>
        </div>

        <div class="grid" data-loc-group>
            <label>Building (where the work lands)
                <select name="bldg" data-loc-building>
                    <option value="">Choose a building</option>
                    <?php foreach ($buildings as $b): ?>
                    <option value="<?= h((string)$b['BldgNumber']) ?>" data-bldg="<?= h((string)$b['BldgNumber']) ?>"
                        <?= (string)$b['BldgNumber'] === (string)($_POST['bldg'] ?? '') ? 'selected' : '' ?>>
                        <?= h($b['BldgNumber'] . ' — ' . $b['Name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Floor
                <select name="floor" data-loc-floor disabled><option value="">Choose one</option></select>
            </label>
            <label>Room
                <select name="room" data-loc-room data-initial="<?= h((string)($_POST['room'] ?? '')) ?>">
                    <option value="">Choose a building first</option>
                </select>
            </label>
            <input type="hidden" name="room_code" data-loc-code value="<?= h((string)($_POST['room_code'] ?? '')) ?>">
            <label>Written location
                <input name="location" maxlength="300" value="<?= h((string)($_POST['location'] ?? '')) ?>"></label>
            <p class="muted" data-loc-note class="span-all" hidden></p>
        </div>

        <div class="grid">
            <label>Rough dimensions or quantity
                <input name="dimensions" maxlength="200" placeholder="e.g. 4 shelves, 900mm x 400mm"
                       value="<?= h((string)($_POST['dimensions'] ?? '')) ?>"></label>
            <label>Materials, if you have a preference
                <input name="materials" maxlength="200" placeholder="e.g. birch ply, powder coated steel"
                       value="<?= h((string)($_POST['materials'] ?? '')) ?>"></label>
        </div>

        <label>Description of the work
            <textarea name="description" rows="4" placeholder="What you want made, changed or mended."><?= h((string)($_POST['description'] ?? '')) ?></textarea>
        </label>

        <h2>Drawings and Reference Files</h2>
        <p class="muted">Attach drawings here rather than emailing them. PDF, DWG, DXF and
        photographs are accepted, up to ten megabytes each.</p>
        <label>Attach drawings
            <input type="file" name="drawings[]" multiple accept=".pdf,.dwg,.dxf,image/*">
        </label>
    </div>

    <!-- ===================== vehicle ===================== -->
    <div data-kind="vehicle">
        <?php if ($vehicles): ?>
        <div class="grid">
            <label>Which vehicle
                <select name="fleet_vehicle_id" id="fleet_vehicle_id">
                    <option value="">Choose a vehicle</option>
                    <?php foreach ($vehicles as $v): ?>
                    <option value="<?= (int)$v['FleetVehicleID'] ?>"
                        data-plate="<?= h((string)($v['PlateNumber'] ?? '')) ?>"
                        data-year="<?= h((string)($v['VehicleYear'] ?? '')) ?>"
                        data-home="<?= h((string)($v['HomeBldgNumber'] ?? '')) ?>"
                        <?= (int)$v['FleetVehicleID'] === (int)($_POST['fleet_vehicle_id'] ?? 0) ? 'selected' : '' ?>>
                        <?= h($v['FleetNumber'] . ' — ' . $v['Description']
                            . ($v['HomeBldgNumber'] ? ' (' . $v['HomeBldgNumber'] . ')' : '')) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>What is needed
                <?= picklist_select('vehicle_service', 'vehicle_service',
                        (string)($_POST['vehicle_service'] ?? ''),
                        ['blank' => '--Select--']) ?>
            </label>
            <label>Can it still be driven?
                <?= picklist_select('vehicle_urgency', 'vehicle_urgency',
                        (string)($_POST['vehicle_urgency'] ?? 'driveable')) ?>
            </label>
            <label>Odometer reading now
                <input name="odometer" inputmode="numeric" placeholder="miles"
                       value="<?= h((string)($_POST['odometer'] ?? '')) ?>"></label>
            <label>Where is the vehicle now
                <input name="vehicle_location" maxlength="200"
                       placeholder="e.g. Forbes lot, or Havana field station"
                       value="<?= h((string)($_POST['vehicle_location'] ?? '')) ?>"></label>
            <label>Needed back by
                <input type="date" name="needed_by_v" value="<?= h((string)($_POST['needed_by_v'] ?? '')) ?>">
            </label>
        </div>
        <p class="muted">Odometer readings are what the service schedule is built from, so it is
        worth walking out to read it. Vehicles come from the fleet listing; if yours is missing,
        ask an administrator to add it rather than describing it in free text.</p>

        <label>What is wrong, or what is due
            <textarea name="description_v" rows="4"
                placeholder="Noises, warning lights, when it started, whether it is getting worse."><?= h((string)($_POST['description_v'] ?? '')) ?></textarea>
        </label>

        <h2>Photographs</h2>
        <p class="muted">A photograph of the damage, the warning light, or the part saves a trip.</p>
        <label>Attach photographs
            <input type="file" name="drawings_v[]" multiple accept="image/*,.pdf">
        </label>
        <?php else: ?>
        <p class="bad">No fleet vehicles have been loaded yet. An administrator loads them under
        Admin &rarr; Fleet vehicles before a vehicle request can be filed.</p>
        <?php endif; ?>
    </div>

    <label>Business justification for this request (including Who, What, When, Where, and
    Why/benefit to the University)
        <textarea name="justification" rows="4" required><?= h((string)($_POST['justification'] ?? '')) ?></textarea>
    </label>

    <div class="actions">
        <button class="primary">Submit Shop Request</button>
    </div>
</form>
<?php ui_bottom(['location.js', 'shop.js']); ?>
