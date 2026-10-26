<?php
declare(strict_types=1);

/**
 * How often a person wants their summary. Notices about their own
 * requests are not optional — those are the answer to a question they
 * asked — but the standing summary is theirs to set.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/digest.php';

if (!app_installed()) {
    header('Location: install.php');
    exit;
}
handle_user_switch();
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    if (($_POST['action'] ?? '') === 'send_now') {
        $r = digest_run(1, true, (string)$user['NetID']);
        flash_set($r['sent'] ? 'ok' : 'err', $r['sent']
            ? 'Digest generated with ' . $r['sent'][0]['items'] . ' item(s). '
              . 'Delivery is currently ' . setting('notify_mode', 'log') . '.'
            : 'Nothing is outstanding for you, so no digest was generated.');
    } else {
        flash_set('ok', digest_save_prefs((string)$user['NetID'], $_POST));
    }
    header('Location: preferences.php');
    exit;
}

$pref = digest_prefs((string)$user['NetID']);
$items = digest_items($user, (bool)$pref['IncludeMine']);
$days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

ui_top('Your Preferences', '', '', 'index.php', 'home');
flash_show();
?>
<div class="panel">
    <h2>Your Summary Email</h2>
    <p class="muted">A list of everything waiting on you, as often as you like. It links to a page
    of your own that is generated when you open it, so it empties as you work through it.</p>
    <p>Right now you have <strong><?= digest_count($items) ?></strong> item(s) outstanding —
       <a href="digest.php">see them</a>.</p>

    <form method="post" class="grid">
        <?= csrf_field() ?>
        <label>How often
            <select name="frequency">
                <?php foreach (['daily' => 'Every day', 'weekly' => 'Once a week',
                                'off' => 'Never'] as $k => $label): ?>
                <option value="<?= h($k) ?>" <?= $pref['Frequency'] === $k ? 'selected' : '' ?>>
                    <?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>On which day (weekly only)
            <select name="send_day">
                <?php foreach ($days as $i => $d): ?>
                <option value="<?= $i ?>" <?= (int)$pref['SendDay'] === $i ? 'selected' : '' ?>>
                    <?= h($d) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Around what time
            <select name="send_hour">
                <?php for ($h = 5; $h <= 20; $h++): ?>
                <option value="<?= $h ?>" <?= (int)$pref['SendHour'] === $h ? 'selected' : '' ?>>
                    <?= h(date('g a', mktime($h, 0))) ?></option>
                <?php endfor; ?>
            </select>
        </label>
        <label class="check end">
            <input type="checkbox" name="include_mine" value="1"
                <?= $pref['IncludeMine'] ? 'checked' : '' ?>>
            Include my own open requests
        </label>
        <div class="actions span-all">
            <button name="action" value="save" class="primary">Save</button>
            <button name="action" value="send_now">Send Me One Now</button>
        </div>
    </form>

    <p class="muted">
        <?php if ($pref['LastSentAt']): ?>
        Last sent <?= h(date('M j, Y g:ia', strtotime((string)$pref['LastSentAt']))) ?>
        with <?= (int)$pref['LastItemCount'] ?> item(s).
        <?php else: ?>
        No digest has gone to you yet.
        <?php endif; ?>
        You are always emailed when one of your own requests moves, whatever this is set to.
    </p>
</div>
<?php ui_bottom(); ?>
