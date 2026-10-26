<?php
declare(strict_types=1);

/**
 * Report an incident or a near miss.
 *
 * Deliberately short. The person filling this in has just had a bad
 * afternoon, or is filling it in for somebody who has gone to a clinic.
 * Four questions are required - what, when, where, and what happened -
 * and everything else appears only when it is relevant. Severity is not
 * asked at all; that is the safety office's judgement, made later, from
 * the description.
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

$buildings = db('incidents')->query('SELECT BldgNumber, Name FROM ref.Buildings WHERE IsActive = 1
                                     ORDER BY SortKey')->fetchAll();
$surveys = db('incidents')->query('SELECT Code, Name FROM ref.Surveys
                                 WHERE IsActive = 1 ORDER BY Code')
                          ->fetchAll();
$vehicles = [];
try {
    $vehicles = db('fleet')->query('SELECT FleetNumber, Description FROM ref.FleetVehicles
                                     WHERE IsActive = 1 ORDER BY FleetNumber')->fetchAll();
} catch (Throwable $e) {
    $vehicles = [];
}

$errors = [];
$done = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    // The people boxes arrive as parallel arrays, one index per row.
    $people = [];
    foreach ((array)($_POST['p_name'] ?? []) as $i => $name) {
        $people[] = [
            'name'        => $name,
            'netid'       => $_POST['p_netid'][$i] ?? '',
            'involvement' => $_POST['p_involvement'][$i] ?? '',
            'staff'       => !empty($_POST['p_staff'][$i]),
            'injury'      => $_POST['p_injury'][$i] ?? '',
            'body_part'   => $_POST['p_body'][$i] ?? '',
            'treatment'   => $_POST['p_treatment'][$i] ?? '',
            'treated_at'  => $_POST['p_treated_at'][$i] ?? '',
        ];
    }

    $res = incident_file([
        'kind'             => (string)($_POST['kind'] ?? ''),
        'occurred_at'      => trim((string)($_POST['occurred_date'] ?? '') . ' '
                                 . (string)($_POST['occurred_time'] ?? '')),
        'description'      => (string)($_POST['description'] ?? ''),
        'immediate_action' => (string)($_POST['immediate_action'] ?? ''),
        'bldg'             => (string)($_POST['bldg'] ?? ''),
        'room'             => (string)($_POST['room'] ?? ''),
        'written_location' => (string)($_POST['written_location'] ?? ''),
        'off_campus'       => !empty($_POST['off_campus']),
        'fleet_number'     => (string)($_POST['fleet_number'] ?? ''),
        'survey'           => (string)($_POST['survey'] ?? ''),
        'people'           => $people,
    ], $user);

    if (isset($res['error'])) {
        $errors[] = $res['error'];
    } else {
        $done = $res;
    }
}

ui_top('Report an Incident', 'An injury, a near miss, a spill, an exposure');
?>

<?php if ($done): ?>
<div class="panel ok">
    <h2>Reported</h2>
    <p>This is <strong><?= h($done['ticket']) ?></strong>. The safety office has been told.</p>
    <p>You can look at it again on <a href="incident.php?id=<?= (int)$done['id'] ?>">your report</a>,
       and you will see anything added to it.</p>
    <p><a class="btn" href="new_incident.php">Report Something Else</a>
       <a class="btn secondary" href="index.php">Back to the Start</a></p>
</div>
<?php else: ?>

<?php foreach ($errors as $e): ?>
<div class="panel error"><?= $e ?></div>
<?php endforeach; ?>

<div class="panel note">
    <p>If anybody needs help right now, stop and call <strong>911</strong>, then come back to
       this. A near miss - where nothing went wrong but easily could have - belongs here just
       as much as an injury does.</p>
</div>

<form method="post" enctype="multipart/form-data" class="panel" id="incform">
    <?= csrf_field() ?>

    <h2>1. What Happened</h2>
    <div class="radios">
    <?php foreach (picklist('incident_kind') as $code => $label): ?>
        <label class="check"><input type="radio" name="kind" value="<?= h($code) ?>"
            <?= (string)($_POST['kind'] ?? '') === $code ? 'checked' : '' ?>
            data-kind> <?= h($label) ?></label>
    <?php endforeach; ?>
    </div>

    <h2>2. When</h2>
    <div class="grid">
        <label>Date
            <input type="date" name="occurred_date" required
                   max="<?= date('Y-m-d') ?>"
                   value="<?= h((string)($_POST['occurred_date'] ?? date('Y-m-d'))) ?>"></label>
        <label>Time, near enough
            <input type="time" name="occurred_time"
                   value="<?= h((string)($_POST['occurred_time'] ?? '')) ?>"></label>
    </div>

    <h2>3. Where</h2>
    <label class="check"><input type="checkbox" name="off_campus" id="offsite"
        <?= !empty($_POST['off_campus']) ? 'checked' : '' ?>> It was not in a PRI building</label>

    <div class="grid" data-loc-group data-loc="inside">
        <label>Building
            <select name="bldg" data-loc-building>
                <option value="">Choose one</option>
                <?php foreach ($buildings as $b): ?>
                <option value="<?= h((string)$b['BldgNumber']) ?>"
                    <?= (string)($_POST['bldg'] ?? '') === (string)$b['BldgNumber'] ? 'selected' : '' ?>>
                    <?= h((string)$b['Name']) ?></option>
                <?php endforeach; ?>
            </select></label>
        <label>Floor
            <select name="floor" data-loc-floor disabled><option value="">Choose one</option></select></label>
        <label>Room
            <select name="room" data-loc-room
                    data-initial="<?= h((string)($_POST['room'] ?? '')) ?>">
                <option value="">Choose a building first</option>
            </select></label>
        <input type="hidden" data-loc-code>
        <p class="muted" data-loc-note class="span-all" hidden></p>
    </div>

    <label>Where exactly, in words
        <input name="written_location" placeholder="Loading dock, the field site off Route 47, "
               value="<?= h((string)($_POST['written_location'] ?? '')) ?>"></label>

    <div data-when-kind="vehicle">
        <label>Which vehicle
            <select name="fleet_number">
                <option value="">Choose one</option>
                <?php foreach ($vehicles as $v): ?>
                <option value="<?= h((string)$v['FleetNumber']) ?>"
                    <?= (string)($_POST['fleet_number'] ?? '') === (string)$v['FleetNumber'] ? 'selected' : '' ?>>
                    <?= h((string)$v['FleetNumber'] . ' - ' . (string)$v['Description']) ?></option>
                <?php endforeach; ?>
            </select></label>
    </div>

    <label>Which survey&rsquo;s work was this?
        <select name="survey">
            <option value="">Choose one</option>
            <?php foreach ($surveys as $s): ?>
            <option value="<?= h((string)$s['Code']) ?>"
                <?= (string)($_POST['survey'] ?? '') === (string)$s['Code'] ? 'selected' : '' ?>>
                <?= h((string)$s['Name']) ?></option>
            <?php endforeach; ?>
        </select></label>

    <h2>4. Tell It in Your Own Words</h2>
    <label>What happened
        <textarea name="description" rows="5" required
                  placeholder="What you saw, in the order it happened."><?=
            h((string)($_POST['description'] ?? '')) ?></textarea></label>
    <label>What was done straight away
        <textarea name="immediate_action" rows="2"
                  placeholder="Area taped off, spill kit used, sent to the clinic, nothing yet."><?=
            h((string)($_POST['immediate_action'] ?? '')) ?></textarea></label>

    <h2>5. Who Was There</h2>
    <p class="muted">Leave this empty for a near miss with nobody involved. Add a row for each
       person hurt, and for anybody who saw it.</p>
    <div class="scroll-x">
    <table id="people">
        <tr><th>Name</th><th>NetID</th><th>They were</th><th>PRI staff</th>
            <th>Injury, if any</th><th>Where on the body</th><th>Treatment</th><th></th></tr>
        <tr data-row>
            <?php /* data-fill names an element id, and cloned rows would all
                     share one, so the NetID is filled from the sibling box
                     when the choice bubbles up. */ ?>
            <td><input name="p_name[]" autocomplete="off" data-typeahead="person"></td>
            <td><input name="p_netid[]" size="10" autocomplete="off"></td>
            <td><select name="p_involvement[]">
                <?php foreach (picklist('incident_involvement') as $c => $l): ?>
                <option value="<?= h($c) ?>"><?= h($l) ?></option>
                <?php endforeach; ?>
                </select></td>
            <td class="center"><input type="checkbox" name="p_staff[0]" value="1" checked></td>
            <td><input name="p_injury[]"></td>
            <td><input name="p_body[]" size="12"></td>
            <td><select name="p_treatment[]">
                <option value="">Choose one</option>
                <?php foreach (picklist('incident_treatment') as $c => $l): ?>
                <option value="<?= h($c) ?>"><?= h($l) ?></option>
                <?php endforeach; ?>
                </select></td>
            <td><button type="button" class="rowdel" title="Remove this person"
                    aria-label="Remove this person">&times;</button></td>
        </tr>
    </table>
    </div>
    <p><button type="button" class="btn secondary" id="addperson">Add Another Person</button></p>

    <h2>6. Photographs</h2>
    <label>Anything worth showing
        <input type="file" name="files[]" multiple></label>

    <p><button class="btn">Report It</button></p>
</form>

<script>
(function () {
    var form = document.getElementById('incform');
    if (!form) { return; }

    /* Blocks that only make sense for one kind of report. */
    function kindNow() {
        var r = form.querySelector('input[name="kind"]:checked');
        return r ? r.value : '';
    }
    function sync() {
        var k = kindNow();
        form.querySelectorAll('[data-when-kind]').forEach(function (el) {
            el.hidden = el.getAttribute('data-when-kind') !== k;
        });
        var off = document.getElementById('offsite').checked;
        form.querySelectorAll('[data-loc="inside"]').forEach(function (el) { el.hidden = off; });
    }
    form.querySelectorAll('[data-kind]').forEach(function (r) {
        r.addEventListener('change', sync);
    });
    document.getElementById('offsite').addEventListener('change', sync);
    sync();

    /* Extra people. A clone carries no listeners, so the remove button is
       delegated rather than bound per row. */
    var table = document.getElementById('people');
    var n = 1;
    document.getElementById('addperson').addEventListener('click', function () {
        var first = table.querySelector('[data-row]');
        var row = first.cloneNode(true);
        row.querySelectorAll('input, select').forEach(function (f) {
            if (f.type === 'checkbox') { f.name = 'p_staff[' + n + ']'; f.checked = true; }
            else { f.value = ''; }
        });
        table.appendChild(row);
        n++;
        if (window.PriTypeAhead) {
            row.querySelectorAll('[data-typeahead]').forEach(window.PriTypeAhead.bind);
        }
    });

    /* Choosing a person fills the NetID beside them. */
    table.addEventListener('typeahead:choose', function (e) {
        var netid = e.target.closest('tr').querySelector('input[name="p_netid[]"]');
        if (netid && e.detail && e.detail.netid) { netid.value = e.detail.netid; }
    });
    table.addEventListener('click', function (e) {
        var drop = e.target.closest('.rowdel');
        if (!drop) { return; }
        var rows = table.querySelectorAll('[data-row]');
        if (rows.length > 1) { drop.closest('tr').remove(); }
        else { rows[0].querySelectorAll('input, select').forEach(function (f) {
            if (f.type !== 'checkbox') { f.value = ''; } }); }
    });
})();
</script>

<?php endif; ?>
<?php ui_bottom(['location.js']); ?>
