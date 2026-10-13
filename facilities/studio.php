<?php
declare(strict_types=1);

/**
 * The Studio - the application's own source, edited from inside it.
 *
 * The layout is borrowed from WebDesigner Studio, which solved the same
 * problem: a rail of things you can open, the thing you are working on
 * in the middle, what is true about it on the right, and what just
 * happened along the bottom. Reading it goes left to right, the way the
 * work does.
 */

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/studio.php';
require_once __DIR__ . '/lib/dbsettings.php';

handle_user_switch();
require_studio();

$view = ($_GET['view'] ?? '') === 'database' ? 'database' : 'files';
$file = (string)($_GET['file'] ?? '');
$dbTest = null;
$note = '';
$noteKind = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = (string)($_POST['action'] ?? '');
    $file = (string)($_POST['file'] ?? '');
    if ($action === 'save') {
        // The browser sends CRLF for a textarea. Storing that would
        // rewrite every line of a file that only changed on one.
        $code = str_replace(chr(13) . chr(10), chr(10), (string)($_POST['code'] ?? ''));
        [$ok, $msg] = studio_save($file, $code);
        $note = $msg;
        $noteKind = $ok ? 'good' : 'bad';
    } elseif (($action === 'dbtest' || $action === 'dbsave') && db_engine() === 'postgres') {
        // These settings describe SQL Server. On PostgreSQL the connection
        // lives in pg.env, which the owner writes and no page edits.
        $view = 'database';
        $note = 'The registers are on PostgreSQL. Its connection lives in pg.env in the data folder, '
              . 'which the owner writes; nothing was tested or saved here.';
        $noteKind = 'bad';
    } elseif ($action === 'dbtest') {
        $view = 'database';
        $d = dbs_from_input($_POST);
        $dbTest = dbs_try($d, (string)$d['database']);
        $note = $dbTest['ok']
            ? 'Connected to ' . $d['host'] . ' in ' . $dbTest['ms'] . ' ms. Nothing saved.'
            : 'No connection: ' . $dbTest['error'];
        $noteKind = $dbTest['ok'] ? 'good' : 'bad';
    } elseif ($action === 'dbsave') {
        $view = 'database';
        [$ok, $msg] = dbs_save($_POST);
        $note = $msg;
        $noteKind = $ok ? 'good' : 'bad';
    } elseif ($action === 'restore') {
        [$ok, $msg] = studio_restore($file, (string)($_POST['backup'] ?? ''));
        $note = $msg;
        $noteKind = $ok ? 'good' : 'bad';
    }
}

// A refused save should not also throw away what was typed. The stored
// settings are what is true; the submitted ones are what is being tried,
// and while one is being tried it is what belongs in the boxes.
$dbForm = null;
if ($view === 'database' && $_SERVER['REQUEST_METHOD'] === 'POST' && db_engine() !== 'postgres') {
    $dbForm = dbs_from_input($_POST) + ['has_password' => dbs_current()['has_password']];
}

$full = $file === '' ? null : studio_resolve($file);
if ($file !== '' && $full === null) {
    $note = 'That file is not part of this application.';
    $noteKind = 'bad';
    $file = '';
}

$code = $full === null ? '' : (string)@file_get_contents($full);
$tree = studio_tree();
$backups = $file === '' ? [] : studio_backups($file);

// The tree is grouped by folder, because a flat list of two hundred
// paths is a wall rather than a map.
$groups = [];
foreach ($tree as $rel) {
    $dir = str_contains($rel, '/') ? substr($rel, 0, strrpos($rel, '/')) : '.';
    $groups[$dir][] = $rel;
}
ksort($groups, SORT_NATURAL);

$env = app_environment();
ui_top('Studio', 'The application, editable from inside it', 'studio');
?>
<div class="studio-wrap">

  <div class="studio-topbar">
    <div class="studio-tabs">
      <a href="studio.php" class="studio-tab<?= $view === 'files' ? ' on' : '' ?>">Files</a>
      <a href="studio.php?view=database" class="studio-tab<?= $view === 'database' ? ' on' : '' ?>">Database</a>
    </div>
    <div class="studio-where">
      <?php if ($view === 'database'): ?>
        <span class="studio-crumb">Where the databases live</span>
      <?php elseif ($file === ''): ?>
        <span class="studio-idle">Choose a file</span>
      <?php else: ?>
        <?php
        $parts = explode('/', $file);
        $last = array_pop($parts);
        ?>
        <span class="studio-crumb"><?= h(implode(' / ', $parts)) ?><?= $parts ? ' / ' : '' ?></span><strong><?= h($last) ?></strong>
      <?php endif; ?>
    </div>
    <div class="studio-actions">
      <span class="studio-env studio-env-<?= h($env) ?>"><?= h($env) ?></span>
      <?php if ($view === 'files' && $file !== ''): ?>
        <button type="submit" form="studioform" class="studio-save">Save</button>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($view === 'database'): require __DIR__ . '/lib/studio_dbview.php'; else: ?>
  <div class="studio-body">

    <aside class="studio-rail">
      <label class="studio-filter">
        <input type="search" id="studiofilter" placeholder="Filter files" autocomplete="off">
      </label>
      <nav class="studio-tree" aria-label="Application files">
        <?php foreach ($groups as $dir => $files): ?>
          <details class="studio-group"<?= ($file !== '' && str_starts_with($file, $dir === '.' ? '' : $dir . '/')) || $file === '' && $dir === 'facilities' ? ' open' : '' ?>>
            <summary><?= h($dir === '.' ? 'app root' : $dir) ?><span class="studio-count"><?= count($files) ?></span></summary>
            <ul>
              <?php foreach ($files as $rel): ?>
                <li><a href="studio.php?file=<?= h(rawurlencode($rel)) ?>"
                       class="studio-file<?= $rel === $file ? ' active' : '' ?>"
                       data-path="<?= h($rel) ?>"><?= h(basename($rel)) ?></a></li>
              <?php endforeach; ?>
            </ul>
          </details>
        <?php endforeach; ?>
      </nav>
    </aside>

    <section class="studio-main">
      <?php if ($file === ''): ?>
        <div class="studio-empty">
          <h2>The application, from inside itself</h2>
          <p>Pick a file on the left. Every save is checked before it is written,
             the copy it replaces is kept, and putting it back is one click.</p>
          <p class="studio-empty-note"><?= count($tree) ?> files are editable here.</p>
        </div>
      <?php else: ?>
        <form method="post" id="studioform" class="studio-editform">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="file" value="<?= h($file) ?>">
          <div class="studio-editor">
            <pre class="studio-gutter" id="studiogutter" aria-hidden="true"></pre>
            <textarea name="code" id="studiocode" spellcheck="false"
                      wrap="off" autocomplete="off"><?= h($code) ?></textarea>
          </div>
        </form>
      <?php endif; ?>
    </section>
    <aside class="studio-inspector">
      <?php if ($file === ''): ?>
        <h3>Nothing open</h3>
        <p class="studio-hint">The inspector shows what is true about the open
           file, and every copy the Studio has kept of it.</p>
      <?php else: ?>
        <h3>This file</h3>
        <dl class="studio-facts">
          <dt>Kind</dt><dd><?= h(strtoupper((string)pathinfo($file, PATHINFO_EXTENSION))) ?></dd>
          <dt>Size</dt><dd><?= number_format((int)@filesize($full)) ?> bytes</dd>
          <dt>Lines</dt><dd><?= number_format(substr_count($code, chr(10)) + 1) ?></dd>
          <dt>Changed</dt><dd><?= h(date('j M Y H:i', (int)@filemtime($full))) ?></dd>
          <dt>Writable</dt><dd><?= is_writable($full) ? 'yes' : 'no - read only' ?></dd>
        </dl>
        <?php if (basename($file) === 'config.php'): ?>
          <p class="studio-warn">Spec rule 4: this file is written by the
             application and is not meant to be edited by hand. The installer
             rewrites it.</p>
        <?php endif; ?>

        <h3>Kept copies</h3>
        <?php if (!$backups): ?>
          <p class="studio-hint">None yet. The first save will keep one.</p>
        <?php else: ?>
          <ul class="studio-backups">
            <?php foreach ($backups as $b): ?>
              <li>
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="restore">
                  <input type="hidden" name="file" value="<?= h($file) ?>">
                  <input type="hidden" name="backup" value="<?= h($b['name']) ?>">
                  <span class="studio-when"><?= h(substr($b['when'], 6, 2) . ' ' . substr($b['when'], 4, 2) . '/' . substr($b['when'], 0, 4)) ?>
                    <em><?= h(substr($b['when'], 9, 2) . ':' . substr($b['when'], 11, 2)) ?></em></span>
                  <span class="studio-bytes"><?= number_format($b['size']) ?> b</span>
                  <button type="submit" class="studio-restore">Put back</button>
                </form>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      <?php endif; ?>
    </aside>
  </div>
  <?php endif; ?>

  <div class="studio-drawer<?= $note === '' ? ' quiet' : '' ?>">
    <?php if ($note !== ''): ?>
      <p class="studio-note studio-<?= h($noteKind) ?>"><?= h($note) ?></p>
    <?php else: ?>
      <?php if ($view === 'database'): ?>
        <p class="studio-note">Every entry above was tried just now - this is
          the application connecting, not a cached answer.</p>
      <?php else: ?>
        <p class="studio-note">Saves are parsed before they are written. A file
          that would not run is refused, and nothing on disk changes.</p>
      <?php endif; ?>
    <?php endif; ?>
  </div>

</div>
<script src="assets/studio.js" defer></script>
<?php
ui_bottom();
