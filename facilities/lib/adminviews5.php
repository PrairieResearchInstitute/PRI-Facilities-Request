<?php
declare(strict_types=1);

require_once __DIR__ . '/picklists.php';

/**
 * One screen for every short list a form offers, grouped and folded away
 * so the page is a table of contents until something is opened.
 *
 * The point is that none of this needs a developer. A new kind of key, a
 * new way property leaves, a new vehicle service - typed here, offered on
 * the form immediately.
 */
function av_lists(): void
{
    $types = picklist_types();
    if (!$types) {
        echo '<div class="panel"><h2>Lists</h2><p class="bad">The list tables are not '
           . 'installed yet. Run <code>sql\\24_picklists.sql</code>.</p></div>';
        return;
    }
    $openKey = (string)($_GET['list'] ?? '');
    $groups = [];
    foreach ($types as $t) {
        $groups[$t['GroupName']][] = $t;
    }
    ?>
    <div class="panel">
        <h2>Lists the Forms Offer</h2>
        <p class="muted">Every short list in the system, in one place. Change the wording, change
        the order, switch an entry off so it stops being offered without losing the records that
        already use it, or add a new one &mdash; it appears on the form straight away.</p>
        <p class="muted">A few codes are marked <strong>fixed</strong>. Something in the
        application matches on those exact words &mdash; the audit finds keys held by people whose
        status is <code>Terminated</code>, the shop form changes shape when the kind is
        <code>vehicle</code>. Their wording can be changed; the code behind them cannot.</p>
    </div>

    <?php foreach ($groups as $group => $lists): ?>
    <div class="panel">
        <h2><?= h((string)$group) ?></h2>
        <?php foreach ($lists as $t):
            $key = (string)$t['ListKey'];
            $rows = picklist_all($key);
            $live = count(array_filter($rows, fn($r) => (int)$r['IsActive'] === 1));
        ?>
        <details <?= $openKey === $key ? 'open' : '' ?>>
            <summary>
                <strong><?= h((string)$t['Title']) ?></strong>
                <span class="muted">&mdash; <?= $live ?> in use<?php
                    if (count($rows) > $live) {
                        echo ', ' . (count($rows) - $live) . ' switched off';
                    } ?></span>
            </summary>
            <p class="muted"><?= h((string)$t['Description']) ?>
                <?php if ($t['UsedOn']): ?><br>Shown on: <?= h((string)$t['UsedOn']) ?>.<?php endif; ?></p>

            <form method="post" class="scroll-x">
                <?= csrf_field() ?>
                <input type="hidden" name="list_key" value="<?= h($key) ?>">
                <table>
                    <tr><th>Code</th><th>What people read</th><th>Order</th>
                        <th>Offered</th><th></th></tr>
                    <?php foreach ($rows as $r): $id = (int)$r['PickListID']; ?>
                    <tr>
                        <td><code><?= h((string)$r['Code']) ?></code>
                            <?php if ((int)$r['IsProtected'] === 1): ?>
                            <span class="soon">fixed</span>
                            <?php endif; ?></td>
                        <td><input name="label[<?= $id ?>]" value="<?= h((string)$r['Label']) ?>" size="46"></td>
                        <td><input name="sort[<?= $id ?>]" value="<?= (int)$r['SortOrder'] ?>" size="4"></td>
                        <td><input type="checkbox" name="active[<?= $id ?>]" value="1"
                                   <?= (int)$r['IsActive'] === 1 ? 'checked' : '' ?>></td>
                        <td><?php if ((int)$r['IsProtected'] === 0): ?>
                            <button name="action" value="list_remove" formnovalidate
                                    onclick="return confirm('Remove <?= h((string)$r['Code']) ?> from this list?')"
                                    class="danger" title="Remove">&times;</button>
                            <input type="hidden" name="remove_id" value="<?= $id ?>">
                        <?php endif; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                <div class="actions">
                    <button name="action" value="list_save" class="primary">Save this list</button>
                </div>
            </form>

            <form method="post" class="filters">
                <?= csrf_field() ?>
                <input type="hidden" name="list_key" value="<?= h($key) ?>">
                <label>New code <input name="new_code" placeholder="short_code"></label>
                <label>What people read <input name="new_label" size="34"></label>
                <label>Order <input name="new_sort" size="4" value="50"></label>
                <button name="action" value="list_add">Add to this list</button>
            </form>
        </details>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    <?php
}
