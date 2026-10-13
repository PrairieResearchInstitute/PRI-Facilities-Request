<?php
declare(strict_types=1);

/**
 * The stickers. Print, cut, one per cab.
 *
 * A label carries the code, the fleet number large enough to read across
 * a car park, and one line saying what to do with it - because a QR code
 * with no words beside it is a thing people photograph and then wonder
 * about.
 *
 * Everything is SVG and CSS. No image extension is installed on this PHP,
 * and none is needed.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/mileage.php';
require_once __DIR__ . '/lib/qr.php';

if (!app_installed()) {
    header('Location: install.php');
    exit;
}
handle_user_switch();
current_user();
require_admin_access();

$survey = trim((string)($_GET['survey'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$only = trim((string)($_GET['only'] ?? ''));

$vehicles = mileage_fleet($q, $survey);
if ($only !== '') {
    $wanted = array_map('trim', explode(',', $only));
    $vehicles = array_values(array_filter($vehicles,
        fn($v) => in_array((string)$v['FleetNumber'], $wanted, true)));
}

$base = mileage_base_url();
$configured = trim((string)setting('mileage_base_url', '')) !== '';

$surveys = db('fleet')->query(
    "SELECT DISTINCT SurveyCode FROM ref.FleetVehicles
      WHERE IsActive = 1 AND ISNULL(SurveyCode,'') <> '' ORDER BY SurveyCode")->fetchAll();

ui_top('Vehicle Stickers', '<a href="admin.php?section=mileage">&larr; mileage</a>');
?>
<div class="panel noprint">
    <h2>Vehicle Stickers</h2>
    <p class="muted">One label per vehicle. Scanning it with a phone camera opens that vehicle's
    mileage page &mdash; no app, no typing, no finding it in a list of
    <?= number_format(count(mileage_fleet())) ?>.</p>

    <?php if (!$configured): ?>
    <p class="bad">The address these point at is not set, so they currently say
        <code><?= h($base) ?></code> &mdash; which is wherever you happen to be reading this
        from. Set it under
        <a href="admin.php?section=settings">Settings</a> before printing, or every sticker will
        be wrong the moment the address changes.</p>
    <?php else: ?>
    <p class="muted">They point at <code><?= h($base) ?></code>.</p>
    <?php endif; ?>

    <form method="get" class="filters">
        <label>Search <input name="q" value="<?= h($q) ?>" placeholder="number, plate, make"></label>
        <label>Survey
            <select name="survey">
                <option value="">All</option>
                <?php foreach ($surveys as $s): ?>
                <option value="<?= h((string)$s['SurveyCode']) ?>"
                    <?= $survey === (string)$s['SurveyCode'] ? 'selected' : '' ?>>
                    <?= h((string)$s['SurveyCode']) ?></option>
                <?php endforeach; ?>
            </select></label>
        <button class="primary">Show</button>
        <a href="stickers.php">Clear</a>
    </form>
    <p><strong><?= count($vehicles) ?></strong> label<?= count($vehicles) === 1 ? '' : 's' ?>.
       <button type="button" onclick="window.print()">Print them</button></p>
</div>

<?php if (!$vehicles): ?>
<div class="panel"><p>No vehicles match.</p></div>
<?php else: ?>
<div class="labels">
    <?php foreach ($vehicles as $v):
        $fleet = (string)$v['FleetNumber'];
        $url = mileage_sticker_url($fleet);
        $what = trim((string)$v['VehicleYear'] . ' ' . (string)$v['Make'] . ' ' . (string)$v['Model']);
        if ($what === '') {
            $what = (string)$v['Description'];
        }
        // Error correction Q, not M: this label lives in a windscreen and
        // will be scuffed, faded and partly peeled before it is replaced.
        $svg = qr_svg($url, 4, 'Q', 'Mileage for vehicle ' . $fleet);
    ?>
    <div class="label">
        <?= $svg ?>
        <div class="who">
            <span class="fleetno"><?= h($fleet) ?></span>
            <span class="what"><?= h(mb_substr($what, 0, 40)) ?></span>
            <?php if ($v['PlateNumber']): ?>
            <span class="what"><?= h((string)$v['PlateNumber']) ?></span>
            <?php endif; ?>
            <div class="say">Scan to record the mileage</div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php ui_bottom(); ?>
