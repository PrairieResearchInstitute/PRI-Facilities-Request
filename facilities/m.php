<?php
declare(strict_types=1);

/**
 * The page a vehicle sticker opens.
 *
 * Short filename on purpose: it goes in a QR code, and every character
 * is another module in the grid and another thing to go wrong on a label
 * that has been in a windscreen for three summers.
 *
 * This is the only page in the system designed for somebody standing
 * outside in the cold holding a phone. One number, one photograph, one
 * button, no navigation to get lost in.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/mileage.php';
require_once __DIR__ . '/lib/attachments.php';

if (!app_installed()) {
    header('Location: install.php');
    exit;
}
handle_user_switch();
$user = current_user();

$fleet = trim((string)($_GET['v'] ?? $_POST['fleet'] ?? ''));
$vehicle = $fleet !== '' ? mileage_vehicle($fleet) : null;

$errors = [];
$done = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'record') {
    csrf_require();

    // The photograph first, so its id can be recorded against the reading.
    $photoId = null;
    if (!empty($_FILES['dash']['name'])) {
        attach_store('shop', 0, $_FILES['dash'], 'odometer', '', $user, 'mileage');
        $photoId = ($GLOBALS['attach_last_id'] ?? 0) ?: null;
    }

    $res = mileage_record([
        'fleet'         => $fleet,
        'miles'         => (string)($_POST['miles'] ?? ''),
        'read_on'       => (string)($_POST['read_on'] ?? ''),
        'source'        => (string)($_POST['source'] ?? 'manual'),
        'note'          => (string)($_POST['note'] ?? ''),
        'suggested'     => (string)($_POST['suggested'] ?? ''),
        'was_suggested' => (string)($_POST['was_suggested'] ?? '') === '1',
        'photo_id'      => $photoId,
    ], $user);

    if (isset($res['error'])) {
        $errors[] = $res['error'];
    } else {
        if ($photoId) {
            db('shop')->prepare('UPDATE app.Attachments SET RequestID = ? WHERE AttachmentID = ?')
                ->execute([$res['id'], $photoId]);
        }
        $done = $res;
    }
}

$last = $vehicle ? mileage_last((string)$vehicle['FleetNumber']) : null;

ui_top('Mileage', '', 'phone');
flash_show();
?>

<?php if (!$vehicle): ?>
<div class="panel">
    <h2>Which Vehicle?</h2>
    <?php if ($fleet !== ''): ?>
    <p class="bad">There is no active vehicle with fleet number
        <strong><?= h($fleet) ?></strong>. Check the sticker, or find it below.</p>
    <?php endif; ?>
    <form method="get">
        <label>Fleet number
            <input name="v" value="<?= h($fleet) ?>" autofocus
                   placeholder="the number on the door or the sticker"></label>
        <div class="actions"><button class="primary">Find it</button></div>
    </form>
    <?php
    $some = mileage_fleet($fleet, '');
    if ($fleet !== '' && $some):
    ?>
    <ul class="plain">
        <?php foreach (array_slice($some, 0, 12) as $v): ?>
        <li><a href="m.php?v=<?= h(urlencode((string)$v['FleetNumber'])) ?>">
            <strong><?= h((string)$v['FleetNumber']) ?></strong>
            &mdash; <?= h(trim((string)$v['VehicleYear'] . ' ' . (string)$v['Make']
                    . ' ' . (string)$v['Model'])) ?: h((string)$v['Description']) ?></a></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>

<?php elseif ($done): ?>
<div class="panel ok">
    <h2>Recorded</h2>
    <p class="bignum"><?= number_format((int)($_POST['miles'] ?? 0)) ?> miles</p>
    <p><?= h((string)$vehicle['FleetNumber']) ?>
       &mdash; <?= h(trim((string)$vehicle['VehicleYear'] . ' ' . (string)$vehicle['Make']
                . ' ' . (string)$vehicle['Model'])) ?: h((string)$vehicle['Description']) ?></p>
    <?php if ($done['since'] !== null): ?>
    <p class="muted"><?= number_format((int)$done['since']) ?> miles since the last reading
        <?= (int)$done['days'] > 0 ? ', ' . (int)$done['days'] . ' day'
            . ((int)$done['days'] === 1 ? '' : 's') . ' ago' : ', earlier today' ?>.</p>
    <?php endif; ?>
    <?php if ($done['queried']): ?>
    <p class="bad">Flagged for the fleet office: <?= h((string)$done['note']) ?>
       It is recorded either way.</p>
    <?php endif; ?>
    <div class="actions">
        <a class="btn primary" href="m.php?v=<?= h(urlencode((string)$vehicle['FleetNumber'])) ?>">Another
           reading</a>
        <a class="btn" href="index.php">Done</a>
    </div>
</div>

<?php else: ?>
<div class="panel">
    <h2><?= h((string)$vehicle['FleetNumber']) ?></h2>
    <p><?= h(trim((string)$vehicle['VehicleYear'] . ' ' . (string)$vehicle['Make'] . ' '
            . (string)$vehicle['Model'] . ' ' . (string)$vehicle['Color'])) ?:
       h((string)$vehicle['Description']) ?>
       <?= $vehicle['PlateNumber'] ? '<br>plate ' . h((string)$vehicle['PlateNumber']) : '' ?>
       <?= $vehicle['SurveyCode'] ? ' &middot; ' . h((string)$vehicle['SurveyCode']) : '' ?></p>
    <?php if ($last): ?>
    <p class="muted">Last reading <strong><?= number_format((int)$last['Miles']) ?></strong>
        on <?= h(date('M j, Y', strtotime((string)$last['ReadOn']))) ?>
        by <?= h((string)($last['ReadByName'] ?: $last['ReadByNetID'])) ?>.</p>
    <?php else: ?>
    <p class="muted">No reading on record yet. This will be the first.</p>
    <?php endif; ?>
</div>

<?php if ($errors): ?>
<div class="panel err"><ul><?php foreach ($errors as $e): ?><li><?= $e ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="panel" id="mileform">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="record">
    <input type="hidden" name="fleet" value="<?= h((string)$vehicle['FleetNumber']) ?>">
    <input type="hidden" name="source" value="<?= isset($_GET['v']) ? 'qr' : 'manual' ?>">
    <input type="hidden" name="suggested" id="suggested" value="">
    <input type="hidden" name="was_suggested" id="was_suggested" value="0">

    <h2>The Reading</h2>
    <label class="bigfield">Miles on the odometer
        <input name="miles" id="miles" inputmode="numeric" pattern="[0-9]*" required
               autocomplete="off" value="<?= h((string)($_POST['miles'] ?? '')) ?>"
               <?= $last ? 'min="' . (int)$last['Miles'] . '"' : '' ?>></label>

    <label>Photograph of the dash
        <input type="file" name="dash" id="dash" accept="image/*" capture="environment"></label>
    <p class="muted">The photograph is the evidence the number is real. It is worth taking even
    when you have typed the number already.</p>

    <?php /* Offered only once a photograph is chosen. odotest.php is the
             bench: on drawn displays it reads 11 of 15 and declines the
             other 4, and gets none wrong - which is the only score that
             makes a suggestion safe to show. */ ?>
    <div id="reader" hidden>
        <canvas id="shot" hidden></canvas>
        <div class="actions">
            <button type="button" id="tryread">Read it from the photograph</button>
            <span id="readnote" class="muted"></span>
        </div>
    </div>

    <label>Date <input name="read_on" type="date"
        value="<?= h((string)($_POST['read_on'] ?? date('Y-m-d'))) ?>"></label>
    <label>Anything worth saying <input name="note" value="<?= h((string)($_POST['note'] ?? '')) ?>"></label>

    <div class="actions">
        <button class="primary big">Record it</button>
    </div>
</form>

<?php $hist = mileage_history((string)$vehicle['FleetNumber'], 8); ?>
<?php if ($hist): ?>
<details class="panel">
    <summary>Earlier readings</summary>
    <table>
        <tr><th>Miles</th><th>When</th><th>Who</th><th>Since</th></tr>
        <?php foreach ($hist as $r): ?>
        <tr<?= (int)$r['IsQueried'] ? ' class="warn"' : '' ?>>
            <td><?= number_format((int)$r['Miles']) ?></td>
            <td><?= h(date('M j, Y', strtotime((string)$r['ReadOn']))) ?></td>
            <td><?= h((string)($r['ReadByName'] ?: $r['ReadByNetID'])) ?></td>
            <td><?= $r['MilesSinceLast'] !== null
                ? number_format((int)$r['MilesSinceLast']) : '' ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</details>
<?php endif; ?>
<script src="assets/odometer.js"></script>
<?php endif; ?>
<?php ui_bottom(); ?>
