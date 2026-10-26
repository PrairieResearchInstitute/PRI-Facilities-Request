<?php
declare(strict_types=1);

/**
 * The safety plan register, and the form that adds to it.
 *
 * One page rather than three, because the register is short enough to
 * read whole and the thing people come here to do is check whether
 * anything is overdue. The form to add a plan sits behind a disclosure
 * on the same page, so the answer to "is my plan on here" and the way to
 * put it on are never two navigations apart.
 *
 * Unlike an incident, a plan is not private. Everybody may read the
 * register; the safety office and the plan's own owner may change one.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/plans.php';

if (!app_installed()) {
    header('Location: install.php');
    exit;
}
handle_user_switch();
$user = current_user();
$mayEdit = has_function(['safety', 'executive', 'deputy']);

$types = plan_types();
$buildings = db('plans')->query('SELECT BldgNumber, Name FROM ref.Buildings WHERE IsActive = 1
                                 ORDER BY SortKey')->fetchAll();
$surveys = db('plans')->query('SELECT Code, Name FROM ref.Surveys
                                 WHERE IsActive = 1 ORDER BY Code')
                      ->fetchAll();

$errors = [];
$done = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    if (!$mayEdit) {
        $errors[] = 'You may read the register but not add to it. The safety office keeps it.';
    } else {
        $rooms = [];
        foreach ((array)($_POST['r_bldg'] ?? []) as $i => $b) {
            $rooms[] = [
                'bldg'  => $b,
                'room'  => $_POST['r_room'][$i] ?? '',
                'whole' => !empty($_POST['r_whole'][$i]),
                'note'  => $_POST['r_note'][$i] ?? '',
            ];
        }
        $res = plan_save([
            'type'        => (string)($_POST['type'] ?? ''),
            'title'       => (string)($_POST['title'] ?? ''),
            'reference'   => (string)($_POST['reference'] ?? ''),
            'version'     => (string)($_POST['version'] ?? ''),
            'owner'       => (string)($_POST['owner'] ?? ''),
            'owner_netid' => (string)($_POST['owner_netid'] ?? ''),
            'survey'      => (string)($_POST['survey'] ?? ''),
            'unit'        => (string)($_POST['unit'] ?? ''),
            'effective'   => (string)($_POST['effective'] ?? ''),
            'summary'     => (string)($_POST['summary'] ?? ''),
            'rooms'       => $rooms,
        ], $user);
        if (isset($res['error'])) {
            $errors[] = $res['error'];
        } else {
            $done = $res;
        }
    }
}

$show = (string)($_GET['show'] ?? '');
$rows = plan_list($show === 'due' ? ['due' => true] : []);
$counts = plan_counts();

ui_top('Safety Plans', 'Plans, procedures, their owners and when each is next read again');
?>

<?php foreach ($errors as $e): ?>
<div class="panel error"><?= h($e) ?></div>
<?php endforeach; ?>

<?php if ($done): ?>
<div class="panel ok">
    <p>Registered as <strong><?= h($done['ticket']) ?></strong>.
       <a href="plan.php?id=<?= (int)$done['id'] ?>">Look at it</a>.</p>
</div>
<?php endif; ?>

<div class="panel stats">
    <div><span class="big"><?= (int)$counts['current'] ?></span> current</div>
    <div><span class="big <?= $counts['due'] ? 'warn' : '' ?>"><?= (int)$counts['due'] ?></span>
         due for review</div>
    <div><span class="big <?= $counts['overdue'] ? 'bad' : '' ?>"><?= (int)$counts['overdue'] ?></span>
         overdue</div>
</div>

<div class="panel actions">
    <a class="btn secondary" href="plans.php">Everything</a>
    <a class="btn secondary" href="plans.php?show=due">Due or Overdue</a>
</div>

<div class="panel scroll-x">
<?php if (!$rows): ?>
    <p class="muted"><?= $show === 'due'
        ? 'Nothing is due. That is the answer you wanted.'
        : 'No plans registered yet.' ?></p>
<?php else: ?>
    <table>
        <tr><th>Reference</th><th>Kind</th><th>Title</th><th>Owner</th>
            <th>Covers</th><th>Next review</th></tr>
        <?php foreach ($rows as $r): ?>
        <?php
            $days = $r['DueDays'] === null ? null : (int)$r['DueDays'];
            $cls = $days === null ? '' : ($days < 0 ? 'bad' : ($days <= 60 ? 'warn' : ''));
        ?>
        <tr>
            <td><a href="plan.php?id=<?= (int)$r['SafetyPlanID'] ?>"><?=
                h((string)($r['Reference'] ?: $r['TicketNumber'])) ?></a></td>
            <td><?= h((string)$r['TypeTitle']) ?></td>
            <td><?= h((string)$r['Title']) ?></td>
            <td><?= h((string)$r['OwnerName']) ?></td>
            <td class="center"><?= (int)$r['Rooms'] ?: '' ?></td>
            <td class="<?= $cls ?>">
                <?= $r['ReviewDueOn']
                    ? h(ui_date(strtotime((string)$r['ReviewDueOn'])))
                      . ($days !== null && $days < 0
                         ? ' <strong>' . abs($days) . ' days late</strong>' : '')
                    : '<span class="muted">not set</span>' ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>
</div>

<?php if ($mayEdit): ?>
<details class="panel" <?= $errors ? 'open' : '' ?>>
    <summary><h2 class="inline-block">Register a Plan</h2></summary>
    <form method="post" id="planform">
        <?= csrf_field() ?>
        <div class="grid">
            <label>Kind
                <select name="type" required>
                    <option value="">Choose one</option>
                    <?php foreach ($types as $t): ?>
                    <option value="<?= h((string)$t['PlanTypeCode']) ?>"
                        <?= (string)($_POST['type'] ?? '') === (string)$t['PlanTypeCode'] ? 'selected' : '' ?>>
                        <?= h((string)$t['Title']) ?>
                        (every <?= (int)$t['ReviewMonths'] ?> months)</option>
                    <?php endforeach; ?>
                </select></label>
            <label>Title
                <input name="title" required value="<?= h((string)($_POST['title'] ?? '')) ?>"></label>
            <label>Its own reference, if it has one
                <input name="reference" value="<?= h((string)($_POST['reference'] ?? '')) ?>"></label>
            <label>Version
                <input name="version" value="<?= h((string)($_POST['version'] ?? '')) ?>"></label>
            <label>Owner
                <input name="owner" required data-typeahead="person" data-fill="netid:owner_netid"
                       autocomplete="off" value="<?= h((string)($_POST['owner'] ?? '')) ?>">
                <input type="hidden" name="owner_netid" id="owner_netid"
                       value="<?= h((string)($_POST['owner_netid'] ?? '')) ?>"></label>
            <label>Survey
                <select name="survey">
                    <option value="">Choose one</option>
                    <?php foreach ($surveys as $s): ?>
                    <option value="<?= h((string)$s['Code']) ?>"
                        <?= (string)($_POST['survey'] ?? '') === (string)$s['Code'] ? 'selected' : '' ?>>
                        <?= h((string)$s['Name']) ?></option>
                    <?php endforeach; ?>
                </select></label>
            <label>Unit
                <input name="unit" value="<?= h((string)($_POST['unit'] ?? '')) ?>"></label>
            <label>Effective from
                <input type="date" name="effective"
                       value="<?= h((string)($_POST['effective'] ?? date('Y-m-d'))) ?>"></label>
        </div>
        <p class="muted">The next review date is worked out from the kind of plan and the date it
           takes effect, so it cannot be set to something convenient.</p>

        <label>What it covers, in a sentence
            <textarea name="summary" rows="3"><?= h((string)($_POST['summary'] ?? '')) ?></textarea></label>

        <h3>Where It Applies</h3>
        <div class="scroll-x">
        <table id="cover">
            <tr><th>Building</th><th>Room</th><th>Whole building</th><th>Note</th><th></th></tr>
            <tr data-row>
                <td><select name="r_bldg[]">
                    <option value="">Choose one</option>
                    <?php foreach ($buildings as $b): ?>
                    <option value="<?= h((string)$b['BldgNumber']) ?>"><?= h((string)$b['Name']) ?></option>
                    <?php endforeach; ?>
                    </select></td>
                <td><input name="r_room[]" size="10" autocomplete="off"></td>
                <td class="center"><input type="checkbox" name="r_whole[0]" value="1"></td>
                <td><input name="r_note[]"></td>
                <td><button type="button" class="rowdel" title="Remove this place"
                    aria-label="Remove this place">&times;</button></td>
            </tr>
        </table>
        </div>
        <p><button type="button" class="btn secondary" id="addcover">Add Another Place</button></p>

        <p><button class="btn">Register It</button></p>
    </form>
</details>

<script>
(function () {
    var table = document.getElementById('cover');
    if (!table) { return; }
    var n = 1;
    document.getElementById('addcover').addEventListener('click', function () {
        var row = table.querySelector('[data-row]').cloneNode(true);
        row.querySelectorAll('input, select').forEach(function (f) {
            if (f.type === 'checkbox') { f.name = 'r_whole[' + n + ']'; f.checked = false; }
            else { f.value = ''; }
        });
        table.appendChild(row);
        n++;
    });
    table.addEventListener('click', function (e) {
        var drop = e.target.closest('.rowdel');
        if (!drop) { return; }
        var rows = table.querySelectorAll('[data-row]');
        if (rows.length > 1) { drop.closest('tr').remove(); }
    });
})();
</script>
<?php endif; ?>

<?php ui_bottom(); ?>
