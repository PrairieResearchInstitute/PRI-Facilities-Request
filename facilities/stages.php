<?php
declare(strict_types=1);

/**
 * The stage chart. Everyone can see how a request moves; the stages
 * themselves are edited on the back end, so a change of practice is a
 * change of data rather than a change of code.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/requests2.php';

if (!app_installed()) {
    header('Location: install.php');
    exit;
}
handle_user_switch();
current_user();

$types = [
    'work'  => 'Facilities work request',
    'shop'  => 'Shop services request',
    'move'  => 'Move request',
    'space' => 'Space request',
    'key'   => 'Key request',
];

ui_top('How requests move', '<a href="index.php">&larr; home</a>');
?>
<div class="panel">
    <p>Every request walks these stages. Whoever filed it is emailed each time it moves, and again
    when it is closed. The person named beside a stage is the one who normally moves it on.</p>
</div>

<?php foreach ($types as $type => $label):
    $flow = status_labels($type);
    if (!$flow) {
        continue;
    } ?>
<div class="panel">
    <h2><?= h($label) ?></h2>
    <ol class="flowchart">
        <?php foreach ($flow as $s): ?>
        <li class="<?= $s['IsTerminal'] ? 'terminal' : '' ?>">
            <strong><?= h((string)$s['Label']) ?></strong>
            <span class="muted"><?= h((string)$s['Description']) ?></span>
            <?php if ($s['OwnerHint']): ?>
            <span class="stageowner"><?= h((string)$s['OwnerHint']) ?></span>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ol>
</div>
<?php endforeach; ?>
<?php ui_bottom(); ?>
