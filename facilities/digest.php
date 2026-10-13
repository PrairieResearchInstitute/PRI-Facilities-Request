<?php
declare(strict_types=1);

/**
 * My work: one person's outstanding work, on tabs the same way the board
 * is. Reached from the digest email by a private link, or simply by
 * being signed in. It is generated when asked rather than stored, so it
 * empties itself as the work is done, and the digest email is written
 * from exactly these lists.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/digest.php';

if (!app_installed()) {
    header('Location: install.php');
    exit;
}
handle_user_switch();

$token = trim((string)($_GET['t'] ?? ''));
$viaToken = false;
$user = null;

if ($token !== '') {
    $row = digest_by_token($token);
    if ($row) {
        $user = digest_user((string)$row['NetID']);
        $viaToken = true;
    }
}
if ($user === null) {
    $user = current_user();
}

$pref = digest_prefs((string)$user['NetID']);
$items = digest_items($user, (bool)$pref['IncludeMine']);
$total = digest_count($items);

$tabs = [
    'decisions' => ['Waiting on you', count($items['decisions'])],
    'queue'     => ['In your queue', count($items['queue'])],
    'mine'      => ['Your requests', count($items['mine'])],
];
// Open on the tab that has something on it, unless one was asked for.
$tab = (string)($_GET['tab'] ?? '');
if (!isset($tabs[$tab])) {
    $tab = 'decisions';
    foreach ($tabs as $k => [$label, $n]) {
        if ($n > 0) {
            $tab = $k;
            break;
        }
    }
}

function work_qs(array $overrides): string
{
    return h('?' . http_build_query(array_merge($_GET, $overrides)));
}

ui_top('My work', 'for ' . h((string)$user['DisplayName']));
flash_show();
?>
<?php if ($viaToken && $user['NetID'] !== current_user()['NetID']): ?>
<div class="panel">
    <p class="muted">You are looking at the list belonging to
    <strong><?= h((string)$user['DisplayName']) ?></strong> through their digest link.</p>
</div>
<?php endif; ?>

<div class="tabs">
    <?php foreach ($tabs as $key => [$label, $count]): ?>
    <a href="<?= work_qs(['tab' => $key]) ?>" class="<?= $tab === $key ? 'active' : '' ?>">
        <?= h($label) ?> (<?= $count ?>)
    </a>
    <?php endforeach; ?>
</div>

<div class="panel">
    <p class="muted">
        <?= $total === 0
            ? 'Nothing is outstanding for you.'
            : $total . ' item(s) outstanding in total.' ?>
        This page is built when you open it, so it clears itself as you work through it.
        <?php if ($pref['Frequency'] !== 'off'): ?>
            Your digest email arrives <?= h((string)$pref['Frequency']) ?> and is written from
            these same lists &mdash; <a href="preferences.php">change how often</a>.
        <?php else: ?>
            Digest emails are off for you &mdash; <a href="preferences.php">turn them on</a>.
        <?php endif; ?>
    </p>
</div>

<?php if ($tab === 'decisions'): ?>
<div class="panel <?= $items['decisions'] ? 'err' : '' ?>">
    <h2>Waiting on your decision (<?= count($items['decisions']) ?>)</h2>
    <?php if (!$items['decisions']): ?>
    <p class="muted">Nothing is waiting on you.</p>
    <?php else: ?>
    <p class="muted">Nothing moves on these until you decide.</p>
    <div class="scroll-x">
    <table>
        <tr><th>Ticket</th><th>Kind</th><th>Stage</th><th>What</th><th>Filed by</th><th>Waiting</th><th></th></tr>
        <?php foreach ($items['decisions'] as $d): $days = dash_days_since($d['since']); ?>
        <tr class="rowlink" onclick="location='<?= h($d['url']) ?>'">
            <td><a href="<?= h($d['url']) ?>"><?= h($d['ticket']) ?></a></td>
            <td><?= h($d['kind']) ?></td>
            <td><strong><?= h($d['stage']) ?></strong></td>
            <td><?= h($d['summary']) ?></td>
            <td><?= h($d['filed_by']) ?><?= $d['survey'] ? ' <span class="muted">' . h($d['survey']) . '</span>' : '' ?></td>
            <td class="<?= $days > 7 ? 'bad' : '' ?>"><?= $days ?>d</td>
            <td><a class="btn" href="<?= h($d['url']) ?>">Decide</a></td>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'queue'): ?>
<div class="panel">
    <h2>In your queue (<?= count($items['queue']) ?>)</h2>
    <?php if (!$items['queue']): ?>
    <p class="muted"><?= can_admin($user)
        ? 'No open work in the surveys you cover.'
        : 'You do not staff a queue, so nothing appears here.' ?></p>
    <?php else: ?>
    <p class="muted">Open work in the surveys you cover, longest untouched first. Moving one along
    tells the person who filed it.</p>
    <div class="scroll-x">
    <table>
        <tr><th>Ticket</th><th>Kind</th><th>What</th><th>Stage</th><th>Untouched</th><th></th></tr>
        <?php foreach (array_slice($items['queue'], 0, 60) as $q):
            $flow = status_labels($q['type']);
            $days = dash_days_since($q['since']); ?>
        <tr class="rowlink" onclick="location='<?= h($q['url']) ?>'">
            <td><a href="<?= h($q['url']) ?>"><?= h($q['ticket']) ?></a></td>
            <td><?= h($q['kind']) ?></td>
            <td><?= h($q['summary']) ?></td>
            <td><?= h($flow[$q['status']]['Label'] ?? str_replace('_', ' ', $q['status'])) ?></td>
            <td class="<?= $days > 14 ? 'bad' : '' ?>"><?= $days ?>d</td>
            <td><a class="btn" href="<?= h($q['url']) ?>">Open</a></td>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php if (count($items['queue']) > 60): ?>
    <p class="muted"><?= count($items['queue']) - 60 ?> more; the
       <a href="board.php">board</a> holds all of them.</p>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php else: ?>
<div class="panel">
    <h2>Your own requests still open (<?= count($items['mine']) ?>)</h2>
    <?php if (!$items['mine']): ?>
    <p class="muted">You have nothing open. <a href="index.php">File something</a>.</p>
    <?php else: ?>
    <p class="muted">You are emailed whenever one of these moves to a new stage.</p>
    <div class="scroll-x">
    <table>
        <tr><th>Ticket</th><th>Kind</th><th>What</th><th>Stage</th><th>Filed</th></tr>
        <?php foreach ($items['mine'] as $m): $flow = status_labels($m['type']); ?>
        <tr class="rowlink" onclick="location='<?= h($m['url']) ?>'">
            <td><a href="<?= h($m['url']) ?>"><?= h($m['ticket']) ?></a></td>
            <td><?= h($m['kind']) ?></td>
            <td><?= h($m['summary']) ?></td>
            <td><?= h($flow[$m['status']]['Label'] ?? str_replace('_', ' ', $m['status'])) ?></td>
            <td class="muted"><?= dash_days_since($m['since']) ?>d ago</td>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php ui_bottom(); ?>
