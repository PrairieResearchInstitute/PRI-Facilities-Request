<?php
/**
 * Where the databases are - the screen that turns "move them to a
 * server" from a code change into a settings change.
 *
 * Included by studio.php with $dbTest and $note already decided.
 *
 * On PostgreSQL (dataMode) the SQL Server settings below mean nothing and
 * trying them would wake LocalDB, so the screen shows the ten register
 * schemas as db() reaches them, and says where the connection is kept.
 */
if (db_engine() === 'postgres'):
    $pgRows = [];
    foreach (APP_DB_NAMES as $name) {
        $t0 = microtime(true);
        try {
            db($name)->query('SELECT 1')->fetch();
            $pgRows[$name] = ['ok' => true, 'error' => null];
        } catch (Throwable $e) {
            $pgRows[$name] = ['ok' => false, 'error' => $e->getMessage()];
        }
        $pgRows[$name]['ms'] = (int)round((microtime(true) - $t0) * 1000);
    }
    $pgOk = count(array_filter($pgRows, fn($r) => $r['ok']));
?>
<div class="studio-body studio-dbbody">
  <section class="studio-dbmain">
    <h3>The Ten Registers, as the Application Reaches Them Now</h3>
    <p>They live in one PostgreSQL database, one schema per register. The
       connection is kept in <code>pg.env</code> in the data folder, which the
       owner writes; this page shows it working and does not edit it.</p>
    <table class="studio-dbtable">
      <thead><tr><th>Register</th><th>Schema</th><th>Answer</th><th>Took</th></tr></thead>
      <tbody>
        <?php foreach ($pgRows as $name => $r): ?>
          <tr class="<?= $r['ok'] ? 'ok' : 'bad' ?>">
            <td><?= h(ucfirst($name)) ?><span class="studio-dbwhat"><?= h(APP_DB_PURPOSE[$name] ?? '') ?></span></td>
            <td><code><?= h($name) ?></code></td>
            <td><?= $r['ok'] ? 'connected' : h((string)$r['error']) ?></td>
            <td><?= $r['ms'] ?> ms</td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="studio-hint"><?= $pgOk ?> of <?= count($pgRows) ?> answering.</p>
  </section>
</div>
<?php
    return;
endif;

$cur = dbs_current();
// What is on disk drives the warning and the status list; what was
// submitted, if anything, drives the boxes.
$val = $dbForm ?? $cur;
$status = dbs_status();
$onLocal = dbs_is_localdb($cur['host']);
$okCount = count(array_filter($status, fn($r) => $r['ok']));
?>
<div class="studio-body studio-dbbody">

  <section class="studio-dbmain">

    <?php if ($onLocal): ?>
      <div class="studio-localdb">
        <h3>These Databases Cannot Be Reached from Another Computer</h3>
        <p>The server is <code><?= h($cur['host']) ?></code>. LocalDB is a
           user-mode instance: it starts on demand, listens on a private
           named pipe belonging to one Windows account, and has no network
           listener at all. No firewall rule or setting changes that.</p>
        <p>To serve other machines the databases have to live on a real
           instance. SQL Server Express is free and its 10&nbsp;GB ceiling is
           far above<?php $mb = dbs_total_mb(); ?>
           <?= $mb === null ? ' what these use' : ' the ' . number_format($mb) . ' MB these use' ?>.
           Once one is running, name it below and the application follows -
           no code changes anywhere.</p>
      </div>
    <?php endif; ?>

    <h3>The Ten Databases, as the Application Reaches Them Now</h3>
    <table class="studio-dbtable">
      <thead><tr><th>Register</th><th>Database</th><th>Answer</th><th>Took</th></tr></thead>
      <tbody>
        <?php foreach ($status as $name => $r): ?>
          <tr class="<?= $r['ok'] ? 'ok' : 'bad' ?>">
            <td><?= h(ucfirst($name)) ?><span class="studio-dbwhat"><?= h(APP_DB_PURPOSE[$name] ?? '') ?></span></td>
            <td><code><?= h($r['database']) ?></code>
                <?php if (!$r['named']): ?>
                  <span class="studio-dbwhat">not named in config.php - reached
                  with the shared server settings</span>
                <?php endif; ?></td>
            <td><?= $r['ok'] ? 'connected' : h((string)$r['error']) ?></td>
            <td><?= $r['ms'] ?> ms</td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="studio-hint"><?= $okCount ?> of <?= count($status) ?> answering.
       The time is the round trip for a connection and one statement, which is
       what every page pays.</p>
  </section>

  <aside class="studio-inspector studio-dbform">
    <h3>Where the Databases Live</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="file" value="">
      <label>Server
        <input name="host" value="<?= h($val['host']) ?>" required
               placeholder="SERVERNAME\SQLEXPRESS">
      </label>
      <p class="studio-hint">A machine name, or <code>name\instance</code>,
         or an address. Leave the port at 0 unless the instance uses a fixed one.</p>
      <label>Port
        <input name="port" type="number" min="0" max="65535" value="<?= (int)$val['port'] ?>">
      </label>
      <label>Sign in with
        <select name="auth" id="dbauth">
          <option value="windows"<?= $val['auth'] === 'windows' ? ' selected' : '' ?>>Windows account</option>
          <option value="sql"<?= $val['auth'] === 'sql' ? ' selected' : '' ?>>SQL login</option>
        </select>
      </label>
      <p class="studio-hint">Across machines that are not on a domain, a SQL
         login is the workable one: a Windows account would have to exist, with
         the same password, on every computer.</p>
      <label>User
        <input name="username" value="<?= h($val['username']) ?>" autocomplete="off">
      </label>
      <label>Password
        <input name="password" type="password" autocomplete="new-password"
               placeholder="<?= $cur['has_password'] ? 'unchanged' : 'none stored' ?>">
      </label>
      <p class="studio-hint">Left empty, the stored password is kept.</p>
      <label class="studio-check">
        <input type="checkbox" name="trust_cert" value="1"<?= $val['trust_cert'] ? ' checked' : '' ?>>
        Trust the server certificate
      </label>
      <label class="studio-inline">Give up after
        <input name="connect_timeout" type="number" min="1" max="60"
               value="<?= (int)$val['connect_timeout'] ?>"> seconds
      </label>
      <div class="studio-dbbuttons">
        <button type="submit" name="action" value="dbtest" class="studio-restore">Test</button>
        <button type="submit" name="action" value="dbsave" class="studio-save">Save</button>
      </div>
      <p class="studio-hint">Test proves the settings without writing them.
         Save proves them first and refuses to write settings that do not
         connect, because that would lock everyone out - including this page.</p>
    </form>
  </aside>
</div>
