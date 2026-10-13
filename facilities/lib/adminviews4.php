<?php
declare(strict_types=1);

require_once __DIR__ . '/datasources.php';

/**
 * Data refresh: the three campus exports and how current each one is.
 *
 * The honest position is stated on the screen rather than hidden: nothing
 * here reaches out and fetches anything, because the reports sit behind
 * Shibboleth. What the app does is recognise a file, refuse one it has
 * already seen, and complain when a source has gone stale — which is the
 * whole of "keeping it up to date" minus the click.
 */
function av_datafeeds(): void
{
    $sources = ds_all();
    $token = ds_token();
    $base = (string)(($_SERVER['HTTPS'] ?? '') ? 'https' : 'http')
          . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $ingestUrl = $base . dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/admin.php')) . '/api/ingest.php';
    ?>
    <div class="panel">
        <h2>Where Data Comes From</h2>
        <p class="muted">Everything this system knows that it did not collect itself arrives on
        this screen, and only on this screen. Four reports come from PRIDE &mdash; the accounts a
        request can be charged to, the people the lookups search, the org chart behind survey
        leadership, and the key register. All of them sit behind Shibboleth, so the server cannot
        fetch them: your browser is already signed in and it can. Pull the report, drop the file
        below, and the app works out which one it is from its shape rather than its name. Dropping
        the same file twice costs nothing. Space, floor plans and property come from the
        geodatabase instead, and are loaded further down.</p>
        <p class="muted">Getting data back <em>out</em> is the other screen:
        <a href="admin.php?section=tables">Data &rarr; Download and browse</a>.</p>
    </div>

    <div class="cards">
        <?php foreach ($sources as $s):
            $age = ds_age_days($s['LastLoadedAt']);
            $stale = ds_is_stale($s); ?>
        <div class="panel card <?= $stale ? 'card-warn' : '' ?>">
            <h3><?= h((string)$s['Label']) ?></h3>
            <p class="muted"><?= h((string)$s['Description']) ?></p>
            <p>
                <?php if ($age === null): ?>
                    <strong class="bad">Never loaded.</strong>
                <?php else: ?>
                    <strong class="<?= $stale ? 'bad' : 'good' ?>">
                        <?= $age === 0 ? 'Loaded today' : 'Loaded ' . $age . ' day' . ($age === 1 ? '' : 's') . ' ago' ?>
                    </strong>
                    <span class="muted">by <?= h((string)$s['LastLoadedBy']) ?>
                        &mdash; <?= h((string)$s['LastNote']) ?></span>
                <?php endif; ?>
            </p>
            <p class="muted">Refresh every <?= (int)$s['RefreshDays'] ?> days.
                Usually downloads as <code><?= h((string)$s['FilePattern']) ?></code>.</p>
            <div class="actions">
                <?php if ($s['SourceUrl']): ?>
                <a class="button" href="<?= h((string)$s['SourceUrl']) ?>" target="_blank" rel="noopener">
                    Open the report</a>
                <?php endif; ?>
            </div>
            <details>
                <summary class="muted">Where it comes from</summary>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="source_key" value="<?= h((string)$s['SourceKey']) ?>">
                    <label>Report address
                        <input name="source_url" value="<?= h((string)$s['SourceUrl']) ?>"></label>
                    <label>Refresh every
                        <input name="refresh_days" type="number" min="1" max="365"
                               value="<?= (int)$s['RefreshDays'] ?>"> days</label>
                    <label>Downloads named
                        <input name="file_pattern" value="<?= h((string)$s['FilePattern']) ?>"></label>
                    <div class="actions"><button name="action" value="ds_source_edit">Save</button></div>
                </form>
            </details>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="panel">
        <h2>Load a Downloaded Export</h2>
        <p class="muted">Any of the sources above, one or several at once, as a CSV or as the
        spreadsheet it came out of. The file is identified by its shape, not its name, so a
        browser's &ldquo;(1)&rdquo; suffix makes no difference.</p>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <label>Exports <input type="file" name="files[]" accept=".csv,.txt,.xlsx" multiple required></label>
            <div class="actions">
                <button name="action" value="ds_upload" class="primary">Load these</button>
            </div>
        </form>
    </div>

    <div class="panel">
        <h2>Refresh Without Being Here</h2>
        <p class="muted">A scheduled task on a PRI desktop can do the whole round trip: open the
        reports in the browser that is already signed in, wait for the downloads, and post
        whatever landed. The key below is what proves the post is yours. Nothing else can use it,
        and clearing it switches unattended loading off entirely.</p>
        <?php if ($token === ''): ?>
        <p><strong>Unattended loading is off.</strong> No key exists, so
           <code>api/ingest.php</code> refuses every post.</p>
        <form method="post"><?= csrf_field() ?>
            <button name="action" value="ds_token_new" class="primary">Generate a key</button>
        </form>
        <?php else: ?>
        <p>Key: <code><?= h($token) ?></code></p>
        <p>Post to: <code><?= h($ingestUrl) ?></code></p>
        <p class="muted">On the desktop, run <code>tools\pri-refresh.ps1</code> once by hand to
        check it, then add it to Task Scheduler weekly. It reads these two values from the command
        line, so the key never sits in a file it did not need to.</p>
        <pre class="snippet">powershell -ExecutionPolicy Bypass -File "<?= h('tools\\pri-refresh.ps1') ?>" `
   -Server "<?= h($base . dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/admin.php'))) ?>" `
   -Token  "<?= h($token) ?>"</pre>
        <form method="post" onsubmit="return confirm('Clear the key? Any scheduled task using it will stop working.')">
            <?= csrf_field() ?>
            <button name="action" value="ds_token_new">Replace the key</button>
            <button name="action" value="ds_token_clear" class="danger">Turn unattended loading off</button>
        </form>
        <?php endif; ?>
    </div>

    <div class="panel">
        <h2>Space, Floor Plans and Property</h2>
        <p class="muted">Rooms, floor plans, occupants, property, safety equipment and room
        responsibility. Loading again is safe: survey, principal investigator and safety contact
        set here are never overwritten.</p>
        <?php
        require_once __DIR__ . '/gisload.php';
        $extracts = function_exists('gis_extract_status') ? gis_extract_status() : [];
        if ($extracts): ?>
        <div class="scroll-x">
            <table>
                <tr><th>Extract</th><th>Rows</th><th>Written</th></tr>
                <?php foreach ($extracts as $file => $st): ?>
                <tr>
                    <td><?= h((string)$file) ?></td>
                    <td><?= $st ? (int)$st['rows'] : '&mdash;' ?></td>
                    <td class="<?= $st ? '' : 'muted' ?>"><?= $st ? h((string)$st['when']) : 'not present' ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>
        <form method="post">
            <?= csrf_field() ?>
            <div class="actions">
                <button name="action" value="gis_load" class="primary">Load space and property data</button>
            </div>
        </form>
    </div>

    <div class="panel">
        <h2>Key Assignments</h2>
        <?php
        $keyCount = 0;
        $keyLabel = null;
        try {
            $keyCount = (int)db('keys')->query('SELECT COUNT(*) FROM gis.RoomKeys')->fetchColumn();
            $keyLabel = db('keys')->query('SELECT TOP 1 SourceLabel FROM gis.RoomKeys
                                           ORDER BY RoomKeyID DESC')->fetchColumn();
        } catch (Throwable $e) { /* not loaded yet */ }
        ?>
        <p class="muted">C3PO offers no download for the key table, so it is copied from the page
        instead. Select the whole table, copy, and paste it below &mdash; or paste it into a
        spreadsheet first, save it, and drop the file in the box above. The heading row tells the
        importer which column is which, and the repeated headings and the stacked Return Key /
        Mark Lost / Edit rows that come with a copy are recognised and dropped.</p>
        <p>Currently holding <strong><?= number_format($keyCount) ?></strong> key
           assignment<?= $keyCount === 1 ? '' : 's' ?><?= $keyLabel ? ', ' . h((string)$keyLabel) : '' ?>.
           A fresh register replaces the last one, except for keys issued through this app.</p>
        <form method="post">
            <?= csrf_field() ?>
            <label>Pasted key table
                <textarea name="keys_blob" rows="6"
                    placeholder="Assignee&#9;Emp Status&#9;Survey&#9;State&#9;Building&#9;Floor&#9;Room&#9;Key #&#9;Type&#9;Qty&#9;Assigned By&#9;Comments&#9;Assigned On"></textarea>
            </label>
            <div class="actions">
                <button name="action" value="keys_paste" class="primary">Load pasted keys</button>
            </div>
        </form>
    </div>

    <div class="panel scroll-x">
        <h2>What Has Been Loaded</h2>
        <?php $hist = ds_history(30); ?>
        <?php if (!$hist): ?>
        <p class="muted">Nothing yet.</p>
        <?php else: ?>
        <table>
            <tr><th>When</th><th>Source</th><th>How</th><th>File</th><th>Rows</th>
                <th>Outcome</th><th>Detail</th></tr>
            <?php foreach ($hist as $r): ?>
            <tr>
                <td><?= h(date('M j, g:ia', strtotime((string)$r['LoadedAt']))) ?></td>
                <td><?= h((string)($r['Label'] ?: $r['SourceKey'])) ?></td>
                <td class="muted"><?= h((string)$r['Channel']) ?><br>
                    <span class="muted"><?= h((string)$r['LoadedBy']) ?></span></td>
                <td class="muted"><?= h((string)$r['FileName']) ?></td>
                <td><?= $r['RowsSeen'] === null ? '&mdash;' : number_format((int)$r['RowsSeen']) ?></td>
                <td class="<?= $r['Outcome'] === 'ok' ? 'good' : ($r['Outcome'] === 'error' ? 'bad' : 'muted') ?>">
                    <?= h((string)$r['Outcome']) ?></td>
                <td class="muted"><?= h((string)$r['Note']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>
    <?php
}

// ------------------------------------------------------- data to fix
/**
 * The data audit report: everything the data needs doing to it, worked
 * out fresh each time rather than kept in a list somebody maintains.
 *
 * Two halves. The findings say what is wrong, how much of it there is,
 * why it matters and which screen fixes it. The coverage figures say how
 * far along the filling-in has got, which is what shows progress: a fault
 * list only ever goes down when work is done, but it never shows how much
 * is left.
 */
function av_audit(): void
{
    require_once __DIR__ . '/dataaudit.php';
    $checks = audit_all();
    $open = array_values(array_filter($checks, fn($c) => (int)$c['count'] > 0 || $c['error']));
    $clear = array_values(array_filter($checks, fn($c) => !$c['error'] && (int)$c['count'] === 0));
    $show = (string)($_GET['check'] ?? '');

    $tally = ['blocker' => 0, 'important' => 0, 'tidy' => 0];
    $rowsTotal = 0;
    foreach ($open as $c) {
        $tally[$c['severity']] = ($tally[$c['severity']] ?? 0) + 1;
        $rowsTotal += (int)$c['count'];
    }
    $words = ['blocker' => 'stops something working',
              'important' => 'makes something unreliable',
              'tidy' => 'worth correcting'];
    $bands = ['blocker' => 'critical', 'important' => 'high', 'tidy' => 'low'];

    // Grouped by area, and within an area the worst first.
    $byArea = [];
    foreach ($open as $c) {
        $byArea[$c['category']][] = $c;
    }
    ?>
    <div class="panel">
        <h2>Data Audit</h2>
        <p class="muted">Every check runs against the data as it stands right now. Each finding
        says where it gets fixed; counts fall as the work is done and a finding disappears once it
        is clear. <?= count($checks) ?> checks, <?= count($clear) ?> of them currently clear.</p>
        <p>
            <strong class="bad"><?= $tally['blocker'] ?></strong> stopping something working &middot;
            <strong><?= $tally['important'] ?></strong> making something unreliable &middot;
            <strong class="muted"><?= $tally['tidy'] ?></strong> worth correcting &middot;
            <strong><?= number_format($rowsTotal) ?></strong> rows to work through in total.
        </p>
        <div class="actions">
            <a class="button" href="report_audit.php">Read it as a report</a>
            <a class="button" href="export_audit_xlsx.php">Excel workbook, a tab per area</a>
            <a class="button" href="export_audit.php">CSV, one row per thing to fix</a>
        </div>
        <p class="muted">The report reads as a document and prints to PDF; the workbook is the one
        to take into a meeting, since it can be sorted, filtered and split between people.</p>
    </div>

    <div class="panel scroll-x">
        <h2>How Complete the Data Is</h2>
        <p class="muted">Not faults, but coverage: how much of each thing has been filled in. This
        is the number that moves week to week as the list below is worked through.</p>
        <table>
            <tr><th>Measure</th><th>Filled in</th><th>Of</th><th>Coverage</th></tr>
            <?php foreach (audit_coverage() as $label => $m): ?>
            <tr>
                <td><?= h((string)$label) ?></td>
                <td><?= number_format($m['have']) ?></td>
                <td><?= number_format($m['total']) ?></td>
                <td class="<?= $m['pct'] >= 90 ? 'good' : ($m['pct'] >= 50 ? '' : 'bad') ?>">
                    <?= $m['pct'] ?>%</td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <?php foreach ($byArea as $area => $items): ?>
    <div class="panel">
        <h2><?= h((string)$area) ?></h2>
        <p class="muted"><?= count($items) ?> finding<?= count($items) === 1 ? '' : 's' ?>
           in this area.</p>
    </div>
        <?php foreach ($items as $c): ?>
        <div class="panel <?= $c['severity'] === 'blocker' ? 'card-warn' : '' ?>">
            <h3><?= h($c['title']) ?>
                <?php if ($c['count'] !== null): ?>
                <span class="band band-<?= h($bands[$c['severity']]) ?>">
                    <?= number_format((int)$c['count']) ?></span>
                <?php endif; ?>
            </h3>
            <p class="muted"><?= h($words[$c['severity']]) ?> &mdash; <?= h($c['why']) ?></p>
            <?php if ($c['error']): ?>
            <p class="bad"><?= h($c['error']) ?></p>
            <?php else: ?>
            <p><strong>Fix it at:</strong>
                <a href="<?= h($c['fix_link']) ?>"><?= h($c['fix_where']) ?></a></p>
            <?php if ($show === $c['key']): ?>
            <div class="scroll-x">
                <table>
                    <tr><?php foreach ($c['columns'] as $col): ?><th><?= h((string)$col) ?></th><?php endforeach; ?></tr>
                    <?php foreach (array_slice($c['rows'], 0, AUDIT_SHOW) as $r): ?>
                    <tr><?php foreach ($r as $v): ?><td><?= h((string)$v) ?></td><?php endforeach; ?></tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php if ((int)$c['count'] > AUDIT_SHOW): ?>
            <p class="muted">Showing the first <?= AUDIT_SHOW ?>. The CSV has all
               <?= number_format((int)$c['count']) ?>.</p>
            <?php endif; ?>
            <p><a href="admin.php?section=audit">Hide the detail</a></p>
            <?php else: ?>
            <p><a href="admin.php?section=audit&amp;check=<?= h($c['key']) ?>">Show me which ones</a></p>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php endforeach; ?>

    <?php if ($clear): ?>
    <div class="panel">
        <h2>Currently Clear</h2>
        <p class="muted">These checks found nothing. They stay on the list because they will find
        something again the moment the data slips.</p>
        <ul class="plain">
            <?php foreach ($clear as $c): ?>
            <li class="good"><?= h($c['title']) ?>
                <span class="muted">&mdash; <?= h((string)$c['category']) ?></span></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
    <?php
}

