<?php
declare(strict_types=1);

/**
 * The application on PostgreSQL, end to end, against facilities_test only.
 *
 *   tools\php\php.exe -d extension=pdo_pgsql tests\run-pg.php [--rounds 2] [--skip-pages]
 *
 * Each round: empty the test database and build it from migrations/pgsql,
 * seed the reference data, then run tests/pg-app.php (the app's own
 * functions: sign-in, settings, example data loaded and removed, counters,
 * statement-level rollback), NetID sign-in (tools/identity_seed.php loads
 * tools/identity-seed.example.json twice, then tests/pg-identity.php runs one
 * request per scenario), tests/pg-load.php (the loader, PostgreSQL to
 * PostgreSQL, with a sabotaged run) and request every page and Admin section
 * through PHP's own server, after tests/pg-sql-scan.php has had PostgreSQL plan
 * every SQL literal in the source. Two rounds by default, because the second run
 * is the one that finds state left behind (drez-sqlite-to-postgres-port
 * rule 1). Ends with "PG SUITE PASSED: ..." or "PG SUITE FAILED: ...".
 *
 * Safety (rule 5): everything runs on scratch settings in tests/.work, with
 * "pgTarget": "test"; db_pg_database() refuses a test database whose name
 * does not end in _test, and so does tools/pg/migrate.php --reset. The data
 * folder is a scratch copy, so no upload, session or log reaches the live
 * data folder; pg.env is read where it lives, never copied or printed.
 */

$app = dirname(__DIR__);
$work = $app . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . '.work';
$php = PHP_BINARY;
$rounds = 2;
foreach ($argv as $i => $a) {
    if ($a === '--rounds' && isset($argv[$i + 1])) {
        $rounds = max(1, (int)$argv[$i + 1]);
    }
}
$skipPages = in_array('--skip-pages', $argv, true);

if (!extension_loaded('pdo_pgsql')) {
    fwrite(STDERR, "Run with -d extension=pdo_pgsql (the bundled php.ini loads only pdo_sqlsrv).\n");
    exit(2);
}

// --- scratch settings ------------------------------------------------------
$realCfgFile = $app . DIRECTORY_SEPARATOR . 'config.local.json';
$realCfg = is_file($realCfgFile) ? json_decode(preg_replace('/^\xEF\xBB\xBF/', '', (string)file_get_contents($realCfgFile)), true) : [];
$realData = trim((string)($realCfg['dataFolder'] ?? ''));
if ($realData === '' || preg_match('#^([A-Za-z]:|[\\\\/])#', $realData)) {
    fwrite(STDERR, "config.local.json must name a relative dataFolder (run SETUP.bat): pg.env lives there.\n");
    exit(2);
}
$scratchData = $work . DIRECTORY_SEPARATOR . 'data';
foreach (['storage/uploads', 'storage/imports', 'storage/gis', 'storage/studio', 'runtime'] as $sub) {
    $d = $scratchData . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $sub);
    if (!is_dir($d)) {
        mkdir($d, 0777, true);
    }
}
$localCfg = [
    'tier' => 'test',
    'dataFolder' => 'tests\\.work\\data',
    'dataMode' => 'postgres',
    'pgTarget' => 'test',
    'pgEnv' => rtrim(str_replace('/', '\\', $realData), '\\') . '\\pg.env',
    'configFile' => 'tests\\.work\\config.php',
];
$localCfgFile = $work . DIRECTORY_SEPARATOR . 'config.local.json';
file_put_contents($localCfgFile, json_encode($localCfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
$configFile = $work . DIRECTORY_SEPARATOR . 'config.php';
if (!is_file($configFile)) {
    $config = ['environment' => 'development', 'installed_at' => date('c'), 'timezone' => 'America/Chicago',
               'app_key' => bin2hex(random_bytes(32)), 'db' => ['engine' => 'postgres']];
    file_put_contents($configFile, "<?php\n// Scratch settings for tests/run-pg.php; never the real config.php.\nreturn "
        . var_export($config, true) . ";\n");
}
putenv('PRIOPS_LOCAL_CONFIG=' . $localCfgFile);
$env = getenv();
$env['PRIOPS_LOCAL_CONFIG'] = $localCfgFile;

// The guard, before anything touches a database.
require_once $app . '/facilities/lib/db.php';
try {
    $target = db_pg_database();
} catch (Throwable $e) {
    fwrite(STDERR, 'Refusing to run: ' . $e->getMessage() . "\n");
    exit(2);
}
if (!preg_match('/_test$/', $target)) {
    fwrite(STDERR, "Refusing to run: the target database does not end in _test.\n");
    exit(2);
}
echo "target database: $target (scratch settings in tests/.work)\n";

function run_child(string $php, array $args, array $env, string $cwd): array
{
    $cmd = array_merge([$php, '-d', 'extension=pdo_pgsql'], $args);
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($p), $out];
}

function free_port(): int
{
    for ($port = 8790; $port < 8890; $port++) {
        $s = @stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
        if ($s !== false) {
            fclose($s);
            return $port;
        }
    }
    throw new RuntimeException('No free port between 8790 and 8889.');
}

/** Every page and Admin section, requested as the default administrator. */
function crawl(string $php, array $env, string $app, string $scratchData): array
{
    $port = free_port();
    $rt = $scratchData . DIRECTORY_SEPARATOR . 'runtime';
    foreach (['tmp', 'uploads', 'sessions'] as $s) {
        if (!is_dir("$rt/$s")) {
            mkdir("$rt/$s", 0777, true);
        }
    }
    $cmd = [$php, '-d', 'extension=pdo_pgsql', '-d', 'sys_temp_dir=' . $rt . DIRECTORY_SEPARATOR . 'tmp',
            '-d', 'upload_tmp_dir=' . $rt . DIRECTORY_SEPARATOR . 'uploads', '-d', 'display_errors=1',
            '-d', 'error_reporting=' . E_ALL, '-S', "127.0.0.1:$port", '-t', $app . DIRECTORY_SEPARATOR . 'facilities'];
    $log = $scratchData . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'server.log';
    $srv = proc_open($cmd, [1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes, $app, $env);
    $ok = false;
    for ($i = 0; $i < 50 && !$ok; $i++) {
        usleep(100000);
        $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
        if ($c) {
            fclose($c);
            $ok = true;
        }
    }
    $results = [];
    if (!$ok) {
        proc_terminate($srv);
        return [['server', 0, 'the PHP server did not start']];
    }
    $pages = ['index.php', 'board.php', 'request.php', 'new_work.php', 'new_move.php', 'new_shop.php',
              'new_key.php', 'new_space.php', 'new_purchase.php', 'new_property.php', 'new_incident.php',
              'incidents.php', 'plans.php', 'plan.php', 'preferences.php', 'stages.php', 'diagram.php',
              'report_audit.php', 'purchase_reconcile.php', 'm.php', 'health.php', 'health.php?db=1',
              'studio.php', 'studio.php?view=database', 'stickers.php', 'digest.php',
              'api/rooms.php?bldg=1073', 'api/lookup.php?q=a', 'api/search_open.php?q=a', 'api/cfop.php?q=1'];
    $sections = ['queue', 'access', 'accounts', 'approvals', 'audit', 'datafeeds', 'drivers', 'equipment',
                 'estate', 'examples', 'expected', 'fleet', 'import', 'keys', 'lists', 'map', 'mileage',
                 'orgchart', 'people', 'property', 'purchases', 'refdata', 'requests2', 'roomassign',
                 'safety', 'settings', 'space', 'stages', 'tables'];
    foreach ($sections as $s) {
        $pages[] = 'admin.php?section=' . $s;
    }
    foreach (APP_DB_NAMES as $reg) {
        $pages[] = 'admin.php?section=tables&db=' . $reg;
    }
    // Records the application wrote in tests/pg-app.php, opened one by one.
    $ids = function (string $reg, string $sql): array {
        try {
            return array_map('intval', db($reg)->query($sql)->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            return [];
        }
    };
    foreach (['work' => 'SELECT TOP 4 WorkRequestID FROM wr.WorkRequests ORDER BY WorkRequestID DESC',
              'move' => 'SELECT TOP 4 MoveRequestID FROM mv.MoveRequests ORDER BY MoveRequestID DESC',
              'shop' => 'SELECT TOP 4 ShopRequestID FROM sh.ShopRequests ORDER BY ShopRequestID DESC'] as $type => $sql) {
        foreach ($ids($type, $sql) as $id) {
            $pages[] = "detail.php?type=$type&id=$id";
        }
    }
    foreach ($ids('space', 'SELECT TOP 3 SpaceRequestID FROM rq.SpaceRequests ORDER BY SpaceRequestID DESC') as $id) {
        $pages[] = "request.php?type=space&id=$id";
    }
    foreach ($ids('keys', 'SELECT TOP 3 KeyRequestID FROM rq.KeyRequests ORDER BY KeyRequestID DESC') as $id) {
        $pages[] = "request.php?type=key&id=$id";
    }
    foreach ($ids('purchasing', 'SELECT PurchaseRequestID FROM rq.PurchaseRequests ORDER BY PurchaseRequestID') as $id) {
        $pages[] = "purchase_view.php?id=$id";
    }
    foreach ($ids('incidents', 'SELECT TOP 3 IncidentID FROM rq.Incidents ORDER BY IncidentID DESC') as $id) {
        $pages[] = "incident.php?id=$id";
    }
    foreach ($ids('plans', 'SELECT TOP 3 SafetyPlanID FROM rq.SafetyPlans ORDER BY SafetyPlanID DESC') as $id) {
        $pages[] = "plan.php?id=$id";
    }
    foreach (['work', 'move', 'shop', 'space', 'key', 'purchase', 'purchasefunding'] as $tab) {
        $pages[] = "export.php?tab=$tab";
    }
    foreach (['export_all.php', 'export_audit.php', 'export_audit_xlsx.php', 'export_safety.php',
              'export_table.php?db=work&table=wr.WorkRequests', 'export_table.php?db=purchasing&table=rq.PurchaseRequests',
              'export_table.php?db=space&table=gis.Rooms'] as $x) {
        $pages[] = $x;
    }
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 120]]);
    foreach ($pages as $p) {
        $body = @file_get_contents("http://127.0.0.1:$port/$p", false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
                $status = (int)$m[1];
            }
        }
        $problem = '';
        if ($body === false) {
            $problem = 'no answer';
        } elseif (preg_match('/(?:<b>)?(?:Fatal error|Warning|Notice|Deprecated)(?:<\/b>)?:\s.{0,400}? on line|Uncaught [A-Za-z\\]+|SQLSTATE\[[0-9A-Z]+\]/s', (string)$body, $m)) {
            $at = strpos((string)$body, $m[0]);
            $problem = trim(preg_replace('/\s+/', ' ', strip_tags(substr((string)$body, $at, 500))));
        } elseif ($status >= 500 || $status === 0) {
            $problem = 'status ' . $status . ': ' . trim(preg_replace('/\s+/', ' ', strip_tags(substr((string)$body, 0, 300))));
        }
        // Pages whose PostgreSQL answer differs from the SQL Server one, by what they must say.
        // (The Studio answers 403 here: sql/23 dropped 'studio' from the AccessFunction check, so
        // nobody can be granted it; tests/pg-app.php renders its PostgreSQL panel instead.)
        $expect = ['health.php?db=1' => '"engine":"postgres","db":"ok"'];
        if ($problem === '' && isset($expect[$p]) && !str_contains((string)$body, $expect[$p])) {
            $problem = 'the answer does not say ' . $expect[$p];
        }
        $results[] = [$p, $status, $problem];
    }
    proc_terminate($srv);
    proc_close($srv);
    return $results;
}

$totalFail = 0;
$summary = [];
for ($round = 1; $round <= $rounds; $round++) {
    echo "=== round $round of $rounds ===\n";
    [$c, $o] = run_child($php, ['tools/pg/migrate.php', '--database', 'test', '--reset'], $env, $app);
    echo '  ' . trim(str_replace("\n", "\n  ", trim($o))) . "\n";
    if ($c !== 0) {
        echo "PG SUITE FAILED: migrations\n";
        exit(1);
    }
    [$c, $o] = run_child($php, ['tools/pg/seed.php', '--database', 'test'], $env, $app);
    echo '  ' . trim(substr($o, strrpos(rtrim($o), "\n") ?: 0)) . "\n";
    if ($c !== 0) {
        echo $o;
        echo "PG SUITE FAILED: seed\n";
        exit(1);
    }
    // The owner as the one superadmin on the fresh database (D-016), before anything adds people.
    [$c1, $o1] = run_child($php, ['tools/identity_seed.php', '--database', 'test', 'seed-owner'], $env, $app);
    [$c2, $o2] = run_child($php, ['tools/identity_seed.php', '--database', 'test', 'seed-owner'], $env, $app);
    [$c3, $o3] = run_child($php, ['tests/pg-owner-seed.php'], $env, $app);
    echo '  ' . trim(str_replace("\n", "\n  ", trim($o1 . $o2 . $o3))) . "\n";
    if ($c1 !== 0 || $c2 !== 0 || $c3 !== 0 || !str_contains($o1, 'done:')
        || preg_match('/\b(granted|created|restored|updated)\b/', $o2)
        || !preg_match('/All (\d+) owner seed checks passed/', $o3, $m)) {
        $totalFail++;
        $summary[] = "round $round: owner seed checks failed";
    } else {
        $summary[] = "round $round: {$m[1]} owner seed checks";
    }
    [$c, $o] = run_child($php, ['tests/pg-sql-scan.php'], $env, $app);
    echo $o;
    if ($c !== 0 || !preg_match('/All (\d+) SQL literals plan on PostgreSQL \((\d+) skipped\)/', $o, $m)) {
        $totalFail++;
        $summary[] = "round $round: SQL literal scan failed";
    } else {
        $summary[] = "round $round: {$m[1]} SQL literals plan ({$m[2]} spliced, left to the pages)";
    }
    [$c, $o] = run_child($php, ['tests/pg-app.php'], $env, $app);
    echo $o;
    if ($c !== 0 || !preg_match('/All (\d+) application checks passed/', $o, $m)) {
        $totalFail++;
        $summary[] = "round $round: application checks failed";
    } else {
        $summary[] = "round $round: {$m[1]} application checks";
    }
    // NetID sign-in: the seeding command, then one request per scenario.
    $idOk = true;
    $idSteps = [
        ['tools/identity_seed.php', '--database', 'test', 'seed', '--file', 'tools/identity-seed.example.json', '--by', 'testadmin'],
        ['tools/identity_seed.php', '--database', 'test', 'seed', '--file', 'tools/identity-seed.example.json', '--by', 'testadmin'],
        ['tools/identity_seed.php', '--database', 'test', 'audit'],
        ['tests/pg-identity.php', 'admin-group'],
        ['tests/pg-identity.php', 'function-groups'],
        ['tools/identity_seed.php', '--database', 'test', 'revoke-group', '--group', 'example-pri-oed-finance',
         '--function', 'finance', '--by', 'testadmin'],
        ['tests/pg-identity.php', 'revoked'],
        ['tools/identity_seed.php', '--database', 'test', 'grant-group', '--group', 'example-pri-oed-finance',
         '--function', 'finance', '--by', 'testadmin'],
        ['tests/pg-identity.php', 'inactive'],
        ['tests/pg-identity.php', 'local-proxied'],
    ];
    $idChecks = 0;
    foreach ($idSteps as $k => $step) {
        [$c, $o] = run_child($php, $step, $env, $app);
        echo '  ' . trim(str_replace("\n", "\n  ", trim($o))) . "\n";
        $want = match (true) {
            $step[0] === 'tests/pg-identity.php' => '/All (\d+) identity checks passed/',
            in_array('audit', $step, true) => '/AUDIT/',
            in_array('revoke-group', $step, true) => '/revoked\s+group/',
            in_array('grant-group', $step, true) => '/restored\s+group/',
            default => '/done:/',
        };
        // The second seed must change nothing: grants are idempotent.
        if ($c !== 0 || !preg_match($want, $o, $m)
            || ($k === 1 && preg_match('/\b(granted|created|restored|updated)\b/', $o))) {
            $idOk = false;
            echo "  FAIL  identity step: " . implode(' ', $step) . "\n";
        } elseif ($step[0] === 'tests/pg-identity.php') {
            $idChecks += (int)$m[1];
        }
    }
    if (!$idOk) {
        $totalFail++;
        $summary[] = "round $round: NetID sign-in checks failed";
    } else {
        $summary[] = "round $round: $idChecks NetID sign-in checks";
    }
    [$c, $o] = run_child($php, ['tests/pg-load.php'], $env, $app);
    echo $o;
    if ($c !== 0 || !preg_match('/All (\d+) loader checks passed/', $o, $m)) {
        $totalFail++;
        $summary[] = "round $round: loader checks failed";
    } else {
        $summary[] = "round $round: {$m[1]} loader checks";
    }
    // Backup and restore drill: the pages below then run on the restored copy.
    $bakDir = $work . DIRECTORY_SEPARATOR . 'backups';
    foreach (glob($bakDir . DIRECTORY_SEPARATOR . '*') ?: [] as $old) {
        @unlink($old);
    }
    [$c, $o] = run_child($php, ['tools/pg/backup.php', '--database', 'test', '--out', $bakDir], $env, $app);
    echo '  ' . trim($o) . "\n";
    $dump = glob($bakDir . DIRECTORY_SEPARATOR . '*.dump')[0] ?? '';
    if ($c !== 0 || $dump === '' || !str_contains($o, 'BACKUP VERIFIED')) {
        $totalFail++;
        $summary[] = "round $round: backup failed";
    } else {
        [$c, $o] = run_child($php, ['tools/pg/backup.php', '--database', 'test', '--drill', $dump], $env, $app);
        echo '  ' . trim($o) . "\n";
        if ($c !== 0 || !preg_match('/DRILL PASSED: (\d+) tables, (\d+) rows/', $o, $m)) {
            $totalFail++;
            $summary[] = "round $round: restore drill failed";
        } else {
            $summary[] = "round $round: backup restored, {$m[1]} tables and {$m[2]} rows match";
        }
    }
    if (!$skipPages) {
        $res = crawl($php, $env, $app, $scratchData);
        $bad = array_filter($res, fn($r) => $r[2] !== '');
        foreach ($res as [$p, $status, $problem]) {
            echo ($problem === '' ? 'ok   ' : 'FAIL ') . " $status $p" . ($problem !== '' ? "\n        $problem" : '') . "\n";
        }
        $summary[] = "round $round: " . (count($res) - count($bad)) . ' of ' . count($res) . ' pages answer cleanly';
        if ($bad) {
            $totalFail++;
        }
    }
}
if ($totalFail) {
    echo 'PG SUITE FAILED: ' . implode('; ', $summary) . "\n";
    exit(1);
}
echo 'PG SUITE PASSED: ' . implode('; ', $summary) . "\n";
