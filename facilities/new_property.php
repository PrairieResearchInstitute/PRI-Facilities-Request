<?php
declare(strict_types=1);

/**
 * One form for every change to a property record.
 *
 * Registering, moving, handing over and disposing were four cards on the
 * front page. They are four sentences about the same object, and asking
 * somebody to decide which screen their sentence belongs on is asking
 * them to know how the database is laid out. So: find the item, say what
 * happened, and only the boxes that matter appear.
 *
 * The people who hold the property inventory file and apply in one
 * motion. Everybody else files, and the property office applies - which
 * is the honest arrangement, because the person who watched a microscope
 * leave the building is rarely the person allowed to say so officially.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/property.php';
require_once __DIR__ . '/lib/purchase.php';

if (!app_installed()) {
    header('Location: install.php');
    exit;
}
handle_user_switch();
$user = current_user();
$mayApply = has_function(['equipment', 'inventory']);

$buildings = db('work')->query('SELECT BldgNumber, Name FROM ref.Buildings WHERE IsActive = 1
                                ORDER BY SortKey')->fetchAll();

$errors = [];
$kind = (string)($_POST['kind'] ?? $_GET['kind'] ?? '');
$invId = (int)($_POST['inventory_id'] ?? $_GET['item'] ?? 0);
$purchaseItem = (int)($_POST['purchase_item_id'] ?? $_GET['expected'] ?? 0);
$q = trim((string)($_POST['q'] ?? $_GET['q'] ?? ''));
$found = [];

// Arriving from "Property awaiting a number" fills the top of the form
// from the purchase, so nothing about a known arrival is retyped.
$fromPurchase = null;
if ($purchaseItem > 0) {
    foreach (purchase_expected_equipment() as $e) {
        if ((int)$e['PurchaseItemID'] === $purchaseItem) {
            $fromPurchase = $e;
        }
    }
    if ($fromPurchase && $kind === '') {
        $kind = 'register';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    if (($_POST['do'] ?? '') === 'search') {
        $found = property_find($q, 40);
        if (!$found) {
            $errors[] = 'Nothing matched &ldquo;' . h($q) . '&rdquo;. Try the inventory number, '
                      . 'part of the description, the serial number, or the name of the person '
                      . 'holding it.';
        }
    } elseif (($_POST['do'] ?? '') === 'file') {
        $res = property_file([
            'kind'             => $kind,
            'inventory_id'     => $invId,
            'purchase_item_id' => $purchaseItem,
            'tag'              => (string)($_POST['tag'] ?? ''),
            'description'      => (string)($_POST['description'] ?? ''),
            'model'            => (string)($_POST['model'] ?? ''),
            'serial'           => (string)($_POST['serial'] ?? ''),
            'class'            => (string)($_POST['class'] ?? ''),
            'cost'             => (string)($_POST['cost'] ?? ''),
            'acquired'         => (string)($_POST['acquired'] ?? ''),
            'funding'          => (string)($_POST['funding'] ?? ''),
            'condition'        => (string)($_POST['condition'] ?? ''),
            'bldg'             => (string)($_POST['bldg'] ?? ''),
            'room'             => (string)($_POST['room'] ?? ''),
            'survey'           => (string)($_POST['survey'] ?? ''),
            'person'           => (string)($_POST['person'] ?? ''),
            'person_netid'     => (string)($_POST['person_netid'] ?? ''),
            'removal_reason'   => (string)($_POST['removal_reason'] ?? ''),
            'removed_on'       => (string)($_POST['removed_on'] ?? ''),
            'salvage'          => (string)($_POST['salvage'] ?? ''),
            'reason'           => (string)($_POST['reason'] ?? ''),
        ], $user);

        if (isset($res['error'])) {
            $errors[] = $res['error'];
        } else {
            flash_set('ok', $res['applied']
                ? 'Done &mdash; ' . $res['ticket'] . '. The inventory says so now, and the change '
                  . "is on the item's history."
                : 'Filed as ' . $res['ticket'] . '. The property inventory will apply it, and you '
                  . 'will be told when they have.');
            if (!empty($res['warning'])) {
                flash_set('err', $res['warning']);
            }
            header('Location: new_property.php');
            exit;
        }
    }
}

$item = $invId > 0 ? property_item($invId) : null;
if ($q !== '' && !$found && !$item) {
    $found = property_find($q, 40);
}

/** What was typed last time, so an error does not cost the typing. */
function old(string $name, $fallback = ''): string
{
    return (string)($_POST[$name] ?? $fallback ?? '');
}

ui_top('Property Update', '<a href="index.php">&larr; request types</a>');
flash_show();
?>

<div class="panel">
    <p>Something about a piece of tagged property has changed &mdash; it arrived, it moved, it
    belongs to somebody else now, the record is wrong, or it has gone. Say which, and only the
    boxes that matter will appear.</p>
    <p class="muted"><?= $mayApply
        ? 'You hold the property inventory, so what you file here is applied straight away.'
        : 'What you file goes to the property inventory to be applied. You will be told when it '
          . 'has been, or why it has not.' ?></p>
</div>

<?php if ($errors): ?>
<div class="panel err"><ul><?php foreach ($errors as $e): ?><li><?= $e ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php /* ---------------- step one: which item ------------------------ */ ?>
<?php if (!$fromPurchase && !$item): ?>
<form method="post" class="panel">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="search">
    <h2>1. Which item?</h2>
    <label>Inventory number, description, serial number, room, or who has it
        <input name="q" value="<?= h($q) ?>" autofocus
               placeholder="P10E35946 &middot; stereo microscope &middot; Whitfield &middot; 1073_01_1027">
    </label>
    <div class="actions">
        <button class="primary">Find it</button>
        <span class="muted" style="margin-left:1rem">Brand new and not in the inventory yet?
            <a href="?kind=register">Register it instead</a>.</span>
    </div>
</form>
<?php endif; ?>

<?php if ($found): ?>
<div class="panel scroll-x">
    <h3><?= count($found) ?> match<?= count($found) === 1 ? '' : 'es' ?></h3>
    <table>
        <tr><th>Tag</th><th>Item</th><th>Serial</th><th>Held by</th><th>Room</th>
            <th>Status</th><th></th></tr>
        <?php foreach ($found as $f): ?>
        <tr>
            <td><?= h((string)$f['PropertyTag']) ?></td>
            <td><?= h((string)$f['Description']) ?>
                <?= $f['Model'] ? '<span class="muted"> &middot; ' . h((string)$f['Model'])
                                  . '</span>' : '' ?></td>
            <td><?= h((string)$f['SerialNumber']) ?></td>
            <td><?= h((string)$f['Person']) ?></td>
            <td><?= h((string)($f['RoomCode']
                    ?: trim((string)$f['BldgNumber'] . ' ' . (string)$f['RoomID']))) ?></td>
            <td><?= h((string)$f['Status']) ?></td>
            <td><a href="?item=<?= (int)$f['InventoryID'] ?>">This one</a></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php endif; ?>

<?php if ($item || $fromPurchase || $kind === 'register'): ?>

<?php if ($item): ?>
<div class="panel">
    <h2>1. The Item</h2>
    <p><strong><?= h((string)$item['PropertyTag']) ?></strong> &mdash;
       <?= h((string)$item['Description']) ?>
       <?= $item['Model'] ? ' &middot; ' . h((string)$item['Model']) : '' ?>
       <?= $item['SerialNumber'] ? ' &middot; serial ' . h((string)$item['SerialNumber']) : '' ?></p>
    <p class="muted">Held by <?= h((string)($item['Person'] ?: 'nobody recorded')) ?>
       &middot; in <?= h((string)($item['RoomCode']
            ?: trim((string)$item['BldgNumber'] . ' ' . (string)$item['RoomID'])
            ?: 'no room recorded')) ?>
       &middot; <?= h((string)$item['Status']) ?>
       <?= $item['Cost'] !== null ? ' &middot; $' . number_format((float)$item['Cost'], 2) : '' ?>
       <?= $item['SurveyCode'] ? ' &middot; ' . h((string)$item['SurveyCode']) : '' ?></p>
    <p><a href="new_property.php">Choose a different item</a></p>
</div>
<?php elseif ($fromPurchase): ?>
<div class="panel">
    <h2>1. The Item</h2>
    <p><strong><?= h((string)$fromPurchase['Description']) ?></strong>
       <span class="muted">expected on
       <a href="purchase_view.php?id=<?= (int)$fromPurchase['PurchaseRequestID'] ?>"><?=
            h((string)$fromPurchase['TicketNumber']) ?></a>
       &middot; $<?= number_format((float)$fromPurchase['UnitCost'], 2) ?>
       &middot; <?= h((string)$fromPurchase['VendorName']) ?></span></p>
    <p class="muted">Registering it here closes the expectation on that purchase, and the cost,
    the account it came off and the delivery room come across with it.</p>
</div>
<?php endif; ?>

<?php
/* Every block is rendered once, with its real field names, and shown or
   hidden by the kind that is chosen. Nothing is duplicated, so nothing
   can disagree with itself, and switching kinds costs no round trip.
   The server ignores the boxes a kind does not use. */
$blockFor = [
    'identity' => ['register', 'correct'],
    'location' => ['register', 'move', 'correct'],
    'person'   => ['register', 'reassign', 'correct'],
    'removal'  => ['remove'],
];
?>
<form method="post" class="panel" id="propform">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="file">
    <input type="hidden" name="inventory_id" value="<?= (int)$invId ?>">
    <input type="hidden" name="purchase_item_id" value="<?= (int)$purchaseItem ?>">

    <h2>2. What happened?</h2>
    <?php foreach (picklist('property_change') as $code => $label): ?>
        <?php /* You cannot register something already in the inventory,
                 and you cannot move something that is not in it yet. */ ?>
        <?php if (($code === 'register') !== ($item === null)) { continue; } ?>
    <label class="check"><input type="radio" name="kind" value="<?= h($code) ?>"
        <?= $kind === $code ? 'checked' : '' ?>> <?= h($label) ?></label>
    <?php endforeach; ?>

    <div data-block="identity" data-kinds="<?= h(implode(' ', $blockFor['identity'])) ?>" hidden>
        <h3>What is it?</h3>
        <p class="muted" data-only="correct">Fill in only what should be different. A box left
        blank leaves that value alone.</p>
        <div class="grid">
            <label>University inventory number
                <input name="tag" placeholder="P10E35946" autocomplete="off"
                       value="<?= h(old('tag')) ?>"></label>
            <label>What it is
                <input name="description"
                       value="<?= h(old('description', $fromPurchase['Description'] ?? '')) ?>"></label>
            <label>Make and model <input name="model" value="<?= h(old('model')) ?>"></label>
            <label>Serial number <input name="serial" value="<?= h(old('serial')) ?>"></label>
            <label>Kind of thing
                <?= picklist_select('equip_class', 'class', old('class'), ['blank' => '--']) ?></label>
            <label>Condition
                <?= picklist_select('equip_condition', 'condition',
                        old('condition', $item ? '' : 'new'), ['blank' => '--']) ?></label>
            <label>Cost
                <input name="cost" inputmode="decimal"
                       value="<?= h(old('cost', $fromPurchase['UnitCost'] ?? '')) ?>"></label>
            <label>Acquired
                <input name="acquired"
                       value="<?= h(old('acquired', $fromPurchase ? date('M j, Y') : '')) ?>"></label>
            <label style="grid-column:1/-1">Bought on
                <input name="funding"
                       value="<?= h(old('funding', $fromPurchase
                            ? 'Purchase ' . $fromPurchase['TicketNumber'] : '')) ?>"></label>
        </div>
    </div>

    <div data-block="location" data-kinds="<?= h(implode(' ', $blockFor['location'])) ?>" hidden>
        <h3>Where is it?</h3>
        <div class="grid" data-loc-group>
            <label>Building
                <select name="bldg" data-loc-building>
                    <option value="">--Select Building--</option>
                    <?php foreach ($buildings as $b): ?>
                    <option value="<?= h((string)$b['BldgNumber']) ?>"
                        <?= (string)$b['BldgNumber'] === old('bldg',
                                $fromPurchase['DeliverBldgNumber'] ?? '') ? 'selected' : '' ?>>
                        <?= h($b['BldgNumber'] . ' — ' . $b['Name']) ?></option>
                    <?php endforeach; ?>
                </select></label>
            <label>Floor
                <select name="floor" data-loc-floor disabled><option value="">--</option></select></label>
            <label>Room
                <select name="room" data-loc-room
                        data-initial="<?= h(old('room', $fromPurchase['DeliverRoomID'] ?? '')) ?>">
                    <option value="">--Select a building first--</option>
                </select></label>
            <label>Survey
                <input name="survey" size="8"
                       value="<?= h(old('survey', $item['SurveyCode']
                            ?? $fromPurchase['SurveyCode'] ?? $user['SurveyCode'])) ?>"></label>
            <input type="hidden" name="room_code" data-loc-code>
            <p class="muted" data-loc-note style="grid-column:1/-1" hidden></p>
        </div>
    </div>

    <div data-block="person" data-kinds="<?= h(implode(' ', $blockFor['person'])) ?>" hidden>
        <h3>Who is responsible for it?</h3>
        <div class="grid">
            <label>Responsible person
                <input name="person" data-typeahead="person" data-fill="netid:prop_person_netid"
                       autocomplete="off"
                       value="<?= h(old('person', $fromPurchase['ExpectedFor'] ?? '')) ?>"></label>
            <input type="hidden" name="person_netid" id="prop_person_netid"
                   value="<?= h(old('person_netid')) ?>">
        </div>
    </div>

    <div data-block="removal" data-kinds="<?= h(implode(' ', $blockFor['removal'])) ?>" hidden>
        <h3>How did it leave?</h3>
        <div class="grid">
            <label>How it left
                <?= picklist_select('removal_reason', 'removal_reason', old('removal_reason'),
                        ['blank' => '--Select--']) ?></label>
            <label>When
                <input name="removed_on" value="<?= h(old('removed_on', date('M j, Y'))) ?>"></label>
            <label>Salvage value, if any
                <input name="salvage" inputmode="decimal" value="<?= h(old('salvage')) ?>"></label>
        </div>
        <p class="muted">Removing an item does not delete it. It stops being active, keeps its
        history, and stays on the reports that ask what left and why.</p>
    </div>

    <label>Anything the property office should know
        <textarea name="reason" rows="2"><?= h(old('reason')) ?></textarea></label>

    <div class="actions">
        <button class="primary"><?= $mayApply ? 'Apply this change' : 'File this change' ?></button>
    </div>
</form>

<?php if ($item): ?>
<div class="panel scroll-x">
    <h2>What Has Happened to This Item</h2>
    <?php $hist = property_history((int)$item['InventoryID']); ?>
    <?php if (!$hist): ?>
    <p class="muted">Nothing recorded yet. This will be the first entry.</p>
    <?php else: ?>
    <table>
        <tr><th>When</th><th>What</th><th>Detail</th><th>Who</th></tr>
        <?php foreach ($hist as $e): ?>
        <tr>
            <td><?= h(date('M j, Y', strtotime((string)$e['CreatedAt']))) ?></td>
            <td><?= h((string)$e['EventCode']) ?></td>
            <td><?= h((string)$e['Detail']) ?></td>
            <td><?= h((string)$e['ByNetID']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php endif; ?>

<script src="assets/location.js"></script>
<script src="assets/property.js"></script>
<?php ui_bottom(); ?>
