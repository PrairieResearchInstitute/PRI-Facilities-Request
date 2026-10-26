<?php
declare(strict_types=1);

/**
 * The incident list.
 *
 * Two audiences, one page. Somebody with back end access sees every
 * report; everybody else sees the ones they filed. The page does not
 * pretend the other reports are absent - it says plainly that it is
 * showing yours - because a list that silently hides rows teaches people
 * that the system has lost things.
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

$all = may_read_incidents($user);
$filter = [];
if (!$all) {
    $filter['mine'] = (string)$user['NetID'];
}
if (($_GET['show'] ?? '') === 'open') {
    $filter['open'] = true;
}
if (($_GET['kind'] ?? '') !== '') {
    $filter['kind'] = (string)$_GET['kind'];
}

$rows = incident_list($filter, 400);
$counts = $all ? incident_counts() : [];

ui_top('Incidents', $all ? 'Every report' : 'The reports you filed');
?>

<div class="panel actions">
    <a class="btn" href="new_incident.php">Report an Incident</a>
    <?php if ($all): ?>
    <?php /* The filter that is on says so, as the admin screen's pair does. */ ?>
    <a class="btn secondary<?= ($_GET['show'] ?? '') !== 'open' ? ' on' : '' ?>"
       href="incidents.php">All</a>
    <a class="btn secondary<?= ($_GET['show'] ?? '') === 'open' ? ' on' : '' ?>"
       href="incidents.php?show=open">Still Open</a>
    <?php endif; ?>
</div>

<?php if ($all && $counts): ?>
<div class="panel stats">
    <div><span class="big"><?= (int)$counts['open'] ?></span> open</div>
    <div><span class="big"><?= (int)$counts['actions'] ?></span> corrective actions outstanding</div>
    <div><span class="big"><?= (int)$counts['year'] ?></span> in the last twelve months</div>
</div>
<?php endif; ?>

<?php if (!$all): ?>
<div class="panel note">
    <p>These are the reports you filed. Reports name people and describe what happened to them,
       so they are not shown more widely than they need to be.</p>
</div>
<?php endif; ?>

<div class="panel scroll-x">
<?php if (!$rows): ?>
    <p class="muted">Nothing here<?= $all ? '' : ' yet' ?>.</p>
<?php else: ?>
    <table>
        <tr><th>Ticket</th><th>What</th><th>When</th><th>Where</th>
            <th>Hurt</th><th>Actions open</th><th>Status</th></tr>
        <?php foreach ($rows as $r): ?>
        <?php
            $where = (string)$r['RoomCode'];
            if ($where === '') {
                $where = trim((string)$r['BldgNumber'] . ' ' . (string)$r['RoomID']);
            }
            if ($where === '') {
                $where = (string)$r['WrittenLocation'];
            }
        ?>
        <tr>
            <td><a href="incident.php?id=<?= (int)$r['IncidentID'] ?>"><?=
                h((string)$r['TicketNumber']) ?></a></td>
            <td><?= h(picklist_label('incident_kind', (string)$r['IncidentKind'])) ?></td>
            <td><?= h(ui_date(strtotime((string)$r['OccurredAt']))) ?></td>
            <td><?= h($where) ?></td>
            <td class="center"><?= (int)$r['Hurt'] ?: '' ?></td>
            <td class="center"><?= (int)$r['ActionsOpen'] ?: '' ?></td>
            <td><?= h(nice_code((string)$r['Status'])) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>
</div>

<?php ui_bottom(); ?>
