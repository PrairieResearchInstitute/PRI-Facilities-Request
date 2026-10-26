<?php
declare(strict_types=1);

/**
 * First-run installer (spec Section 13, rule 4).
 * Captures the three database connections through a web form, tests them,
 * creates the schema from the /sql scripts, seeds reference data, writes
 * config.php, and then disables itself: once config.php exists this page
 * refuses to run (acceptance test 12).
 */

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/csrf.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');

// ---------- self-disable ----------
if (app_installed()) {
    http_response_code(403);
    page_top('Installer disabled');
    echo '<div class="panel err"><h2>Already Installed</h2>'
       . '<p>config.php exists, so the installer refuses to run. To reinstall, an administrator '
       . 'must delete config.php from the application root and reload this page.</p>'
       . '<p><a href="index.php">Go to the application</a></p></div>';
    page_bottom();
    exit;
}

// This installer builds SQL Server databases. On PostgreSQL the schema comes
// from tools/pg/migrate.php and the data from tools/pg/load.php (or
// tools/pg/seed.php for an empty installation), run from the command line.
if (db_engine() === 'postgres') {
    http_response_code(403);
    page_top('Installer not used on PostgreSQL');
    echo '<div class="panel err"><h2>Not Used on PostgreSQL</h2>'
       . '<p>dataMode is postgres. This page builds SQL Server databases; on PostgreSQL the schema '
       . 'comes from tools/pg/migrate.php and the data from tools/pg/load.php or tools/pg/seed.php.</p></div>';
    page_bottom();
    exit;
}

app_session_start();

const SQL_DIR = __DIR__ . '/../sql';

/** Scripts per database, with the sentinel object that marks "already ran". */
const DB_SCRIPTS = [
    'work' => [
        ['file' => '00_shared_objects.sql', 'sentinel' => 'app.Settings'],
        ['file' => '01_work.sql',           'sentinel' => 'wr.WorkRequests'],
        ['file' => '04_seed_reference.sql', 'sentinel' => null],
        ['file' => '05_upgrade.sql',        'sentinel' => null],
    ],
    'move' => [
        ['file' => '00_shared_objects.sql', 'sentinel' => 'app.Settings'],
        ['file' => '02_move.sql',           'sentinel' => 'mv.MoveRequests'],
        ['file' => '04_seed_reference.sql', 'sentinel' => null],
        ['file' => '05_upgrade.sql',        'sentinel' => null],
    ],
    'shop' => [
        ['file' => '00_shared_objects.sql', 'sentinel' => 'app.Settings'],
        ['file' => '03_shop.sql',           'sentinel' => 'sh.ShopRequests'],
        ['file' => '04_seed_reference.sql', 'sentinel' => null],
        ['file' => '05_upgrade.sql',        'sentinel' => null],
    ],
];

// ---------- form state ----------
$defaults = [
    'environment' => 'development',
    'admin_netid' => '',
    'admin_name'  => '',
    'admin_email' => '',
    'create_db'   => '1',
];
foreach (APP_DB_NAMES as $n) {
    $defaults["{$n}_host"] = 'localhost';
    $defaults["{$n}_port"] = '1433';
    $defaults["{$n}_database"] = APP_DB_LABELS[$n];
    $defaults["{$n}_auth"] = 'sql';
    $defaults["{$n}_username"] = '';
    $defaults["{$n}_password"] = '';
    $defaults["{$n}_trust"] = '1';
}
$form = array_merge($defaults, $_SESSION['installer_form'] ?? []);

$action = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = $_POST['action'] ?? '';
    foreach (array_keys($defaults) as $k) {
        if (isset($_POST[$k])) {
            $form[$k] = trim((string)$_POST[$k]);
        } elseif (in_array($k, ['create_db'], true)
              || preg_match('/_trust$/', $k)) {
            $form[$k] = '';   // unchecked checkbox
        }
    }
    foreach (APP_DB_NAMES as $n) {
        $form["{$n}_trust"] = isset($_POST["{$n}_trust"]) ? '1' : '';
    }
    $_SESSION['installer_form'] = $form;
}

function conn_spec(array $form, string $n): array
{
    return [
        'host'       => $form["{$n}_host"],
        'port'       => (int)$form["{$n}_port"],
        'database'   => $form["{$n}_database"],
        'auth'       => $form["{$n}_auth"] === 'windows' ? 'windows' : 'sql',
        'username'   => $form["{$n}_username"],
        'password'   => $form["{$n}_password"],
        'trust_cert' => $form["{$n}_trust"] === '1',
    ];
}

/**
 * Try the named database; when absent and creation is allowed, verify the
 * server is reachable through master. Returns [status, detail, PDO|null].
 * status: 'ok' | 'missing' | 'fail'
 */
function probe_connection(array $spec, bool $allowCreate): array
{
    try {
        return ['ok', 'Connected to ' . $spec['database'] . '.', db_connect($spec)];
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
    if ($allowCreate) {
        $master = $spec;
        $master['database'] = 'master';
        try {
            db_connect($master);
            return ['missing', 'Server reachable; database ' . $spec['database']
                . ' does not exist yet and will be created.', null];
        } catch (Throwable $e) {
            return ['fail', 'Server unreachable: ' . $e->getMessage(), null];
        }
    }
    return ['fail', $dbError, null];
}

/** Split a T-SQL script on GO batch separators and run every batch. */
function run_sql_script(PDO $pdo, string $path): int
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Cannot read ' . $path);
    }
    $batches = preg_split('/^\s*GO\s*$/mi', $sql);
    $ran = 0;
    foreach ($batches as $i => $batch) {
        if (trim($batch) === '') {
            continue;
        }
        try {
            $pdo->exec($batch);
            $ran++;
        } catch (Throwable $e) {
            throw new RuntimeException(
                basename($path) . ', batch ' . ($i + 1) . ': ' . $e->getMessage()
            );
        }
    }
    return $ran;
}

function object_exists(PDO $pdo, string $name): bool
{
    $stmt = $pdo->prepare('SELECT OBJECT_ID(?)');
    $stmt->execute([$name]);
    return $stmt->fetchColumn() !== null;
}

function table_count(PDO $pdo, string $table): ?int
{
    try {
        return (int)$pdo->query("SELECT COUNT(*) AS n FROM $table")->fetchColumn();
    } catch (Throwable $e) {
        return null;
    }
}

// ---------- actions ----------
$testResults = null;
$installReport = null;

if ($action === 'test' || $action === 'install') {
    $allowCreate = $form['create_db'] === '1';
    $testResults = [];
    foreach (APP_DB_NAMES as $n) {
        [$status, $detail] = probe_connection(conn_spec($form, $n), $allowCreate);
        $testResults[$n] = ['status' => $status, 'detail' => $detail];
    }
}

if ($action === 'install') {
    $failures = array_filter($testResults, fn($r) => $r['status'] === 'fail');
    if ($form['admin_netid'] === '' || $form['admin_name'] === '' || $form['admin_email'] === '') {
        $failures['_admin'] = ['detail' => 'The first administrator NetID, name, and email are required.'];
    }
    if ($failures) {
        $installReport = ['status' => 'blocked',
            'message' => 'Fix the connection or form problems reported above, then install again.'];
    } else {
        $installReport = ['status' => 'ok', 'databases' => [], 'flags' => [], 'counts' => []];
        $pdos = [];
        try {
            // 1. Connect, creating databases where allowed and missing.
            foreach (APP_DB_NAMES as $n) {
                $spec = conn_spec($form, $n);
                try {
                    $pdos[$n] = db_connect($spec);
                } catch (Throwable $e) {
                    $master = $spec;
                    $master['database'] = 'master';
                    $m = db_connect($master);
                    // Database names come from the form; brackets with doubling
                    // is the only safe quoting for a DDL identifier.
                    $safe = '[' . str_replace(']', ']]', $spec['database']) . ']';
                    $m->exec('CREATE DATABASE ' . $safe);
                    $pdos[$n] = db_connect($spec);
                    $installReport['databases'][$n][] = 'Created database ' . $spec['database'] . '.';
                }
            }

            // 2. Run the scripts, skipping any whose sentinel already exists.
            foreach (DB_SCRIPTS as $n => $scripts) {
                foreach ($scripts as $s) {
                    $label = $s['file'];
                    if ($s['sentinel'] !== null && object_exists($pdos[$n], $s['sentinel'])) {
                        $installReport['databases'][$n][] = $label . ': skipped, objects already exist.';
                        continue;
                    }
                    try {
                        $batches = run_sql_script($pdos[$n], SQL_DIR . '/' . $s['file']);
                    } catch (Throwable $e) {
                        throw new RuntimeException(APP_DB_LABELS[$n] . ': ' . $e->getMessage());
                    }
                    $installReport['databases'][$n][] = $label . ': ran ' . $batches . ' batches.';
                }
            }

            // 3. First administrator, provisioned into all three databases.
            foreach (APP_DB_NAMES as $n) {
                $stmt = $pdos[$n]->prepare('SELECT UserID FROM app.Users WHERE NetID = ?');
                $stmt->execute([$form['admin_netid']]);
                if ($stmt->fetchColumn() === false) {
                    $pdos[$n]->prepare(
                        "INSERT INTO app.Users (NetID, DisplayName, Email, Role) VALUES (?, ?, ?, 'admin')"
                    )->execute([$form['admin_netid'], $form['admin_name'], $form['admin_email']]);
                    $installReport['databases'][$n][] = 'Administrator ' . $form['admin_netid'] . ' provisioned.';
                }
            }

            // 4. Seed verification counts.
            $countTables = ['ref.Surveys', 'ref.Owners', 'ref.Buildings', 'ref.BuildingAliases',
                            'ref.BuildingOccupants', 'app.Settings', 'app.Counters'];
            foreach (APP_DB_NAMES as $n) {
                foreach ($countTables as $t) {
                    $installReport['counts'][$n][$t] = table_count($pdos[$n], $t);
                }
            }
            foreach (['ref.WorkTypes', 'ref.Natures', 'ref.TermSynonyms'] as $t) {
                $installReport['counts']['work'][$t] = table_count($pdos['work'], $t);
            }

            // 5. Known data gaps (spec Section 3): flag, never invent.
            // Both are parked until the geodatabase crosswalk work by ruling
            // of 2026-08-06; they remain listed so the record explains itself.
            $installReport['flags'][] = 'Storage Building 2 is absent from the source building list '
                . '(SB1, SB3, SB4, SB5 seeded). Parked until the geodatabase crosswalk.';
            $tbd = $pdos['work']->query("SELECT Name FROM ref.Buildings WHERE BldgNumber = N'TBD'")->fetchAll();
            foreach ($tbd as $row) {
                $installReport['flags'][] = '"' . $row['Name'] . '" is seeded with the placeholder building '
                    . 'number TBD. Parked until the geodatabase crosswalk.';
            }

            // 6. Write config.php and self-disable.
            $config = [
                'environment'  => $form['environment'] === 'production' ? 'production' : 'development',
                'installed_at' => date('c'),
                'app_key'      => bin2hex(random_bytes(32)),
                'db'           => [],
            ];
            foreach (APP_DB_NAMES as $n) {
                $config['db'][$n] = conn_spec($form, $n);
            }
            $php = "<?php\n// Written by install.php on " . date('c') . ".\n"
                 . "// Never hand edit (spec rule 4): delete this file and rerun the installer instead.\n"
                 . 'return ' . var_export($config, true) . ";\n";
            $tmp = config_path() . '.tmp';
            if (file_put_contents($tmp, $php, LOCK_EX) === false || !rename($tmp, config_path())) {
                throw new RuntimeException('Could not write config.php to ' . config_path());
            }
            @chmod(config_path(), 0640);
            unset($_SESSION['installer_form']);
        } catch (Throwable $e) {
            $installReport['status'] = 'failed';
            $installReport['error'] = $e->getMessage();
        }
    }
}

// ---------- rendering ----------
function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES);
}

function page_top(string $title): void
{
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . h($title) . ' - PRIOps</title>'
       . '<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">'
       . '<link rel="icon" href="assets/favicon.ico" sizes="any">'
       . '<link rel="stylesheet" href="assets/app.css"></head><body>'
       . '<header class="site"><h1>PRIOps</h1>'
       . '<p class="sub">Prairie Research Institute, University of Illinois Urbana-Champaign</p></header>'
       . '<main>';
}

function page_bottom(): void
{
    echo '</main></body></html>';
}

page_top('Installer');

if ($installReport !== null && $installReport['status'] === 'ok') {
    echo '<div class="panel ok"><h2>Installation Complete</h2>';
    foreach ($installReport['databases'] as $n => $lines) {
        echo '<h3>' . h(APP_DB_LABELS[$n]) . '</h3><ul>';
        foreach ($lines as $line) {
            echo '<li>' . h($line) . '</li>';
        }
        echo '</ul>';
    }
    echo '<h3>Seeded Reference Data</h3><table><tr><th>Table</th>';
    foreach (APP_DB_NAMES as $n) {
        echo '<th>' . h(APP_DB_LABELS[$n]) . '</th>';
    }
    echo '</tr>';
    $allTables = array_keys($installReport['counts']['work']);
    foreach ($allTables as $t) {
        echo '<tr><td>' . h($t) . '</td>';
        foreach (APP_DB_NAMES as $n) {
            $v = $installReport['counts'][$n][$t] ?? null;
            echo '<td>' . ($v === null ? '&mdash;' : (int)$v) . '</td>';
        }
        echo '</tr>';
    }
    echo '</table>';
    echo '<h3>Data Gaps Requiring Attention</h3><ul class="flags">';
    foreach ($installReport['flags'] as $flag) {
        echo '<li>' . h($flag) . '</li>';
    }
    echo '</ul>';
    echo '<p>config.php has been written and this installer is now disabled.</p>';
    echo '<p><a class="btn" href="index.php">Open the Application</a></p></div>';
    page_bottom();
    exit;
}

if ($installReport !== null && $installReport['status'] === 'failed') {
    echo '<div class="panel err"><h2>Installation Failed</h2><p>' . h($installReport['error']) . '</p>'
       . '<p>Nothing was written to config.php. Objects created before the failure remain; '
       . 'the installer skips existing objects, so fixing the cause and installing again is safe.</p></div>';
}
if ($installReport !== null && $installReport['status'] === 'blocked') {
    echo '<div class="panel err"><p>' . h($installReport['message']) . '</p></div>';
}

if ($testResults !== null) {
    echo '<div class="panel"><h2>Connection Test</h2><ul>';
    foreach ($testResults as $n => $r) {
        $cls = $r['status'] === 'fail' ? 'bad' : 'good';
        echo '<li class="' . $cls . '"><strong>' . h(APP_DB_LABELS[$n]) . ':</strong> ' . h($r['detail']) . '</li>';
    }
    echo '</ul></div>';
}

echo '<div class="panel"><h2>First Run Installer</h2>'
   . '<p>Provide a SQL Server connection for each of the three databases. Reference data replicates '
   . 'into all three, and foreign keys never cross a database boundary. Nothing is written until '
   . 'you choose Install.</p>';

echo '<form method="post" autocomplete="off">';
echo csrf_field();

foreach (APP_DB_NAMES as $n) {
    echo '<fieldset><legend>' . h(APP_DB_LABELS[$n]) . '</legend>';
    echo '<div class="grid">';
    echo '<label>Server host<input name="' . $n . '_host" required value="' . h($form["{$n}_host"]) . '"></label>';
    echo '<label>Port<input name="' . $n . '_port" value="' . h($form["{$n}_port"]) . '"></label>';
    echo '<label>Database name<input name="' . $n . '_database" required value="' . h($form["{$n}_database"]) . '"></label>';
    echo '<label>Authentication<select name="' . $n . '_auth">'
       . '<option value="sql"' . ($form["{$n}_auth"] === 'sql' ? ' selected' : '') . '>SQL Server login</option>'
       . '<option value="windows"' . ($form["{$n}_auth"] === 'windows' ? ' selected' : '') . '>Windows integrated</option>'
       . '</select></label>';
    echo '<label>Username<input name="' . $n . '_username" value="' . h($form["{$n}_username"]) . '"></label>';
    echo '<label>Password<input type="password" name="' . $n . '_password" value="' . h($form["{$n}_password"]) . '"></label>';
    echo '</div>';
    echo '<label class="check"><input type="checkbox" name="' . $n . '_trust" value="1"'
       . ($form["{$n}_trust"] === '1' ? ' checked' : '') . '> Trust the server certificate (development, self-signed)</label>';
    echo '</fieldset>';
}

echo '<fieldset><legend>Application</legend><div class="grid">';
echo '<label>Environment<select name="environment">'
   . '<option value="development"' . ($form['environment'] === 'development' ? ' selected' : '') . '>Development</option>'
   . '<option value="production"' . ($form['environment'] === 'production' ? ' selected' : '') . '>Production</option>'
   . '</select></label>';
echo '<label>First administrator NetID<input name="admin_netid" required value="' . h($form['admin_netid']) . '"></label>';
echo '<label>Administrator display name<input name="admin_name" required value="' . h($form['admin_name']) . '"></label>';
echo '<label>Administrator email<input type="email" name="admin_email" required value="' . h($form['admin_email']) . '"></label>';
echo '</div>';
echo '<label class="check"><input type="checkbox" name="create_db" value="1"'
   . ($form['create_db'] === '1' ? ' checked' : '') . '> Create the databases when they do not exist '
   . '(requires CREATE DATABASE permission; uncheck on shared hosting where the databases are pre-created)</label>';
echo '</fieldset>';

echo '<div class="actions">'
   . '<button name="action" value="test">Test Connections</button> '
   . '<button name="action" value="install" class="primary">Install</button>'
   . '</div></form></div>';

page_bottom();
