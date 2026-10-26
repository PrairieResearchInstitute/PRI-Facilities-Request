<?php
declare(strict_types=1);

/**
 * Move Request intake. Where it is going is settled first, because every
 * item on the request shares that answer, and then the items themselves
 * come off the room's property record rather than out of somebody's
 * memory. Typing is the exception, not the rule.
 */

require_once __DIR__ . '/lib/ui.php';
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
$pdo = db('move');

$buildings = $pdo->query('SELECT BuildingID, BldgNumber, Name FROM ref.Buildings
                          WHERE IsActive = 1 ORDER BY SortKey')->fetchAll();

const MOVE_TYPES = ['move' => 'Move', 'transfer' => 'Transfer', 'trash' => 'Trash', 'surplus' => 'Surplus'];
const ITEM_TYPES = ['Furniture', 'Computer / IT equipment', 'Lab equipment', 'Field equipment',
                    'Appliance', 'Shelving / storage', 'Boxes / documents', 'Other'];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $neededBy = trim((string)($_POST['needed_by'] ?? ''));
    $personMoving = isset($_POST['person_moving']) ? 1 : 0;
    $notes = trim((string)($_POST['notes'] ?? ''));

    $fromBldg = trim((string)($_POST['from_bldg'] ?? ''));
    $fromRoom = trim((string)($_POST['from_room'] ?? ''));
    $fromCode = trim((string)($_POST['from_room_code'] ?? ''));
    $toBldg = trim((string)($_POST['to_bldg'] ?? ''));
    $toRoom = trim((string)($_POST['to_room'] ?? ''));
    $toCode = trim((string)($_POST['to_room_code'] ?? ''));
    $newCustodian = trim((string)($_POST['new_custodian'] ?? ''));

    if ($neededBy === '') {
        $errors[] = 'Requested completion date is required. Please allow at least 3 days.';
    }
    if ($fromBldg === '') {
        $errors[] = 'Choose the building the items are leaving.';
    }

    // Items arrive as parallel arrays: those ticked off the room's
    // property record, and any typed by hand.
    $items = [];
    $count = count($_POST['item_include'] ?? []);
    for ($i = 0; $i < $count; $i++) {
        if (($_POST['item_include'][$i] ?? '') !== '1') {
            continue;
        }
        $desc = trim((string)($_POST['item_desc'][$i] ?? ''));
        if ($desc === '') {
            continue;
        }
        $moveType = (string)($_POST['item_movetype'][$i] ?? 'move');
        $items[] = [
            'movetype' => isset(MOVE_TYPES[$moveType]) ? $moveType : 'move',
            'itemtype' => trim((string)($_POST['item_itemtype'][$i] ?? 'Other')),
            'ptag'     => trim((string)($_POST['item_ptag'][$i] ?? '')),
            'pritag'   => trim((string)($_POST['item_pritag'][$i] ?? '')),
            'serial'   => trim((string)($_POST['item_serial'][$i] ?? '')),
            'desc'     => $desc,
            'tech'     => (($_POST['item_tech'][$i] ?? '') === '1') ? 1 : 0,
            'source'   => (int)($_POST['item_source'][$i] ?? 0) ?: null,
            // Who holds the item today comes from the property record, not
            // from whoever happens to be filling in this form.
            'custodian' => trim((string)($_POST['item_custodian'][$i] ?? '')),
        ];
    }
    if (!$items) {
        $errors[] = 'Tick at least one item to move, or describe one under "something not listed".';
    }

    // A destination is required unless everything is being discarded.
    $needsDest = false;
    foreach ($items as $row) {
        if (!in_array($row['movetype'], ['trash', 'surplus'], true)) {
            $needsDest = true;
        }
    }
    if ($needsDest && ($toBldg === '' || $toRoom === '' || $newCustodian === '')) {
        $errors[] = 'Items being moved or transferred need a destination building and room, and '
                  . 'the name of the person responsible for them afterwards. Items marked trash '
                  . 'or surplus do not.';
    }

    if (!$errors) {
        $hasTech = 0;
        foreach ($items as $row) {
            if ($row['tech']) {
                $hasTech = 1;
            }
        }
        $localUid = ensure_user('move', $user);
        $ticket = next_ticket_number('move');
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO mv.MoveRequests
                   (TicketNumber, RequestedByUserID, RequestedByNetID, SurveyCode, NeededByDate,
                    IsPersonMoving, HasTechItems, AdditionalNotes, Status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$ticket, $localUid, $user['NetID'], $user['SurveyCode'], $neededBy,
                        $personMoving, $hasTech, $notes ?: null,
                        $hasTech ? 'it_review' : 'inventory_review']);
            $moveId = (int)$pdo->lastInsertId();

            $bId = function (string $num): ?int {
                global $buildings;
                foreach ($buildings as $b) {
                    if ($b['BldgNumber'] === $num) {
                        return (int)$b['BuildingID'];
                    }
                }
                return null;
            };
            $ins = $pdo->prepare(
                'INSERT INTO mv.MoveItems
                   (MoveRequestID, MoveType, ItemType, PTag, PRITag, SerialNumber, Description,
                    FromBuildingID, FromBldgNumber, FromRoom, ToBuildingID, ToBldgNumber, ToRoom,
                    CurrentCustodian, NewCustodian, IsTechItem, FromRoomCode, ToRoomCode,
                    InventorySourceID)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($items as $row) {
                $discard = in_array($row['movetype'], ['trash', 'surplus'], true);
                $ins->execute([
                    $moveId, $row['movetype'], $row['itemtype'] ?: 'Other',
                    $row['ptag'] ?: null, $row['pritag'] ?: null, $row['serial'] ?: null, $row['desc'],
                    $bId($fromBldg), $fromBldg ?: null, $fromRoom ?: null,
                    $discard ? null : $bId($toBldg), $discard ? null : ($toBldg ?: null),
                    $discard ? null : ($toRoom ?: null),
                    $row['custodian'] ?: null, $discard ? null : ($newCustodian ?: null),
                    $row['tech'],
                    $fromCode ?: room_resolve('move', $fromBldg, $fromRoom),
                    $discard ? null : ($toCode ?: room_resolve('move', $toBldg, $toRoom)),
                    $row['source'],
                ]);
            }

            $rev = $pdo->prepare('INSERT INTO mv.Reviews (MoveRequestID, StageCode, StageOrder, IsRequired, SkipReason)
                                  VALUES (?, ?, ?, ?, ?)');
            $rev->execute([$moveId, 'it', 1, $hasTech, $hasTech ? null : 'No technology items on this request']);
            $rev->execute([$moveId, 'inventory', 2, 1, null]);
            $rev->execute([$moveId, 'facilities', 3, 1, null]);

            record_status('move', $moveId, null, $hasTech ? 'it_review' : 'inventory_review', $localUid, 'Filed');
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        attach_store_many('move', $moveId, 'photos', 'photo', $user);
        notify_move_request([
            'MoveRequestID' => $moveId, 'TicketNumber' => $ticket,
            'RequestedByNetID' => $user['NetID'], 'RequestedByName' => $user['DisplayName'],
            'NeededByDate' => $neededBy, 'ItemCount' => count($items), 'HasTechItems' => $hasTech,
            'SurveyCode' => $user['SurveyCode'], 'BldgNumber' => $fromBldg,
        ]);
        flash_set('ok', 'Move request ' . $ticket . ' filed with ' . count($items) . ' item(s).'
            . ($hasTech ? ' Routed to IT review first because technology items are included.' : ''));
        header('Location: detail.php?type=move&id=' . $moveId);
        exit;
    }
}

ui_top('Move Request', '', '', 'index.php', 'request types');
flash_show();

function building_options(array $buildings, string $selected): void
{
    echo '<option value="">Choose a building</option>';
    foreach ($buildings as $b) {
        echo '<option value="' . h((string)$b['BldgNumber']) . '"'
           . ' data-bldg="' . h((string)$b['BldgNumber']) . '"'
           . ((string)$b['BldgNumber'] === $selected ? ' selected' : '') . '>'
           . h($b['BldgNumber'] . ' — ' . $b['Name']) . '</option>';
    }
}
?>
<div class="panel">
    <p>Completing this form routes your request through IT (if there are tech items that need
    moving), inventory management, and facilities. <strong>Please allow at least 3 days for your
    request to be completed.</strong></p>
    <p class="help">For assistance, contact Devin Blobaum at configure-me@example.edu
    or 217-244-5243.</p>
</div>

<?php if ($errors): ?>
<div class="panel err"><ul>
    <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
</ul></div>
<?php endif; ?>

<form method="post" class="panel" id="moveform" enctype="multipart/form-data">
    <?= csrf_field() ?>

    <h2>1. Where It Is Now</h2>
    <div class="grid" data-loc-group id="fromgroup">
        <label>From building
            <select name="from_bldg" data-loc-building required>
                <?php building_options($buildings, (string)($_POST['from_bldg'] ?? '')); ?>
            </select>
        </label>
        <label>From floor
            <select name="from_floor" data-loc-floor disabled><option value="">Choose one</option></select>
        </label>
        <label>From room
            <select name="from_room" data-loc-room data-initial="<?= h((string)($_POST['from_room'] ?? '')) ?>">
                <option value="">Choose a building first</option>
            </select>
        </label>
        <input type="hidden" name="from_room_code" data-loc-code
               value="<?= h((string)($_POST['from_room_code'] ?? '')) ?>">
        <p class="muted" data-loc-note class="span-all" hidden></p>
    </div>

    <h2>2. Where It Is Going</h2>
    <p class="muted">Leave this empty if everything on the request is being trashed or surplussed.</p>
    <div class="grid" data-loc-group id="togroup">
        <label>To building
            <select name="to_bldg" data-loc-building>
                <?php building_options($buildings, (string)($_POST['to_bldg'] ?? '')); ?>
            </select>
        </label>
        <label>To floor
            <select name="to_floor" data-loc-floor disabled><option value="">Choose one</option></select>
        </label>
        <label>To room
            <select name="to_room" data-loc-room data-initial="<?= h((string)($_POST['to_room'] ?? '')) ?>">
                <option value="">Choose a building first</option>
            </select>
        </label>
        <input type="hidden" name="to_room_code" data-loc-code
               value="<?= h((string)($_POST['to_room_code'] ?? '')) ?>">
        <p class="muted" data-loc-note class="span-all" hidden></p>
    </div>
    <div class="grid">
        <label>Person responsible after the move
            <input name="new_custodian" id="new_custodian" data-typeahead="person"
                   data-fill="netid:new_custodian_netid"
                   value="<?= h((string)($_POST['new_custodian'] ?? '')) ?>"
                   placeholder="type a surname and pick" autocomplete="off">
        </label>
        <input type="hidden" name="new_custodian_netid" id="new_custodian_netid"
               value="<?= h((string)($_POST['new_custodian_netid'] ?? '')) ?>">
    </div>
    <p class="muted">Who holds each item today comes from the property record and is shown against
    each item below. Correct it there if the record is out of date.</p>

    <h2>3. What Is Moving</h2>
    <p class="muted" id="itemshint">Choose the room above and its tagged property appears here.
    Tick what is moving; tags, models and serial numbers come from the property record, so nobody
    retypes them.</p>
    <div class="scroll-x">
        <table id="itemtable">
            <thead>
            <tr><th style="width:2.5rem"></th><th>Item</th><th>Tag</th><th>Serial</th>
                <th>Held by now</th><th>Move type</th><th>Category</th><th style="width:4rem">Tech</th></tr>
            </thead>
            <tbody id="itemrows">
                <tr class="emptyrow"><td colspan="8" class="muted">No room chosen yet.</td></tr>
            </tbody>
        </table>
    </div>
    <div class="actions">
        <button type="button" id="addmanual">Add Something Not Listed</button>
    </div>

    <h2>4. When and Anything Else</h2>
    <div class="grid">
        <label>Requested completion date
            <input type="date" name="needed_by" required value="<?= h((string)($_POST['needed_by'] ?? '')) ?>">
        </label>
        <label class="check end">
            <input type="checkbox" name="person_moving" value="1"
                <?= isset($_POST['person_moving']) ? 'checked' : '' ?>> A person is moving to a new location
        </label>
    </div>
    <label>Additional notes
        <textarea name="notes" rows="2"><?= h((string)($_POST['notes'] ?? '')) ?></textarea>
    </label>
    <label>Photographs (optional)
        <input type="file" name="photos[]" multiple accept="image/*,.pdf">
    </label>

    <div class="actions">
        <button class="primary">Submit Move Request</button>
    </div>
</form>

<template id="rowtemplate">
    <tr>
        <td><input type="checkbox" class="inc" checked>
            <input type="hidden" name="item_include[]" value="1" class="inchidden"></td>
        <td><input name="item_desc[]" class="desc" required></td>
        <td><input name="item_ptag[]" class="ptag" size="10">
            <input type="hidden" name="item_pritag[]" value="">
            <input type="hidden" name="item_source[]" class="src" value=""></td>
        <td><input name="item_serial[]" class="serial" size="10"></td>
        <td><input name="item_custodian[]" class="custodian" size="12"></td>
        <td><select name="item_movetype[]">
                <?php foreach (MOVE_TYPES as $v => $label): ?>
                <option value="<?= h($v) ?>"><?= h($label) ?></option>
                <?php endforeach; ?>
            </select></td>
        <td><select name="item_itemtype[]">
                <?php foreach (ITEM_TYPES as $label): ?>
                <option value="<?= h($label) ?>"><?= h($label) ?></option>
                <?php endforeach; ?>
            </select></td>
        <td><input type="hidden" name="item_tech[]" value="0" class="techhidden">
            <input type="checkbox" class="techbox"></td>
    </tr>
</template>

<?php ui_bottom(['location.js', 'move.js']); ?>
