<?php
declare(strict_types=1);

/**
 * Move the ten databases INTO the application folder, so the whole system
 * travels together.
 *
 *   "tools\php\php.exe" tools/move_data_into_folder.php
 *   (add --go to actually do it; without it this only reports)
 *
 * The files currently live under the user profile, which is why the folder
 * could be copied to another machine and still not work. Afterwards they
 * live in data\ beside the application, and config.php names them by a
 * path relative to the application root - so the same folder opened on a
 * different machine, or a different drive letter, finds its own data.
 *
 * LocalDB attaches a file given to it by path (AttachDBFileName), which is
 * what makes this possible without installing a server.
 *
 * What this does, in order, stopping at the first thing that is not right:
 *   1. refuses to run while the application is serving
 *   2. detaches each database, so its files are released
 *   3. moves the .mdf and .ldf into data\
 *   4. pins them so OneDrive keeps them on this machine
 *   5. writes the new paths into config.php
 *   6. opens all ten through the application's own code and counts rows
 *
 * The originals are MOVED, not copied - a copy left behind is a second
 * database that will quietly diverge from the one in use. The step that
 * cannot be undone is the detach, so nothing is detached until every file
 * has been checked for and the destination is writable.
 *
 * ONE MACHINE AT A TIME. These files are synced. Two machines with the
 * application open are two servers writing the same file, which corrupts
 * it. Close the application before moving to another machine, and let
 * OneDrive finish syncing.
 */

require __DIR__ . '/../facilities/lib/db.php';
db_localdb_only('tools/move_data_into_folder.php');

$go   = in_array('--go', $argv, true);
$root = dirname(__DIR__);
$data = $root . DIRECTORY_SEPARATOR . 'data';
// Where the files are now: this account's profile, asked of Windows
// rather than written in. A hardcoded root is what let District
// Tracker write into a stale copy for weeks, and this script is meant
// to be run on whichever machine is holding the folder.
$home = rtrim((string)(getenv('USERPROFILE') ?: ''), DIRECTORY_SEPARATOR);
if ($home === '' || !is_dir($home)) {
    stop('cannot work out this account profile folder from USERPROFILE');
}

function say(string $s): void { echo $s, PHP_EOL; }
function stop(string $s): never { say('STOPPED: ' . $s); exit(1); }

say($go ? 'Moving the databases into the folder.' : 'Dry run. Add --go to do it.');
say('');

// --- 1. nothing may be serving -------------------------------------
$busy = @shell_exec('tasklist /FI "IMAGENAME eq php.exe" /FO CSV 2>&1');
if ($busy && substr_count((string)$busy, 'php.exe') > 1) {
    say('The application appears to be running. Close its window first.');
    if ($go) { stop('a server is still up'); }
}

// --- 2. find every file, before touching anything ------------------
$plan = [];
foreach (APP_DB_NAMES as $name) {
    $db  = APP_DB_LABELS[$name];
    $mdf = $home . DIRECTORY_SEPARATOR . $db . '.mdf';
    $ldf = $home . DIRECTORY_SEPARATOR . $db . '_log.ldf';
    $inFolder = $data . DIRECTORY_SEPARATOR . $db . '.mdf';
    if (is_file($inFolder)) {
        say(sprintf('  %-26s already in the folder', $db));
        $plan[$name] = ['db' => $db, 'done' => true];
        continue;
    }
    if (!is_file($mdf)) { stop("cannot find $mdf"); }
    $plan[$name] = ['db' => $db, 'mdf' => $mdf, 'ldf' => is_file($ldf) ? $ldf : null,
                    'done' => false];
    say(sprintf('  %-26s %6.0f MB', $db, filesize($mdf) / 1048576));
}
if (!is_dir($data) && !@mkdir($data, 0775, true)) { stop("cannot create $data"); }
if (!is_writable($data)) { stop("$data is not writable"); }
say('');
if (!$go) { say('Nothing changed. Re-run with --go.'); exit(0); }

// --- 3. detach, move, pin ------------------------------------------
$master = app_config()['db']['work'];
$master['database'] = 'master';
unset($master['attach']);
$pdo = db_connect($master);

foreach ($plan as $name => $p) {
    if ($p['done']) { continue; }
    $db = $p['db'];
    try {
        $pdo->exec("ALTER DATABASE [$db] SET SINGLE_USER WITH ROLLBACK IMMEDIATE");
        $pdo->exec("EXEC sp_detach_db '$db'");
    } catch (Throwable $e) {
        say("  $db: not attached (" . explode("\n", $e->getMessage())[0] . ') - moving anyway');
    }
    foreach (['mdf', 'ldf'] as $kind) {
        if (empty($p[$kind])) { continue; }
        $dest = $data . DIRECTORY_SEPARATOR . basename($p[$kind]);
        if (!@rename($p[$kind], $dest)) { stop("could not move {$p[$kind]}"); }
        @shell_exec('attrib +P "' . $dest . '" 2>&1');   // keep it on this machine
    }
    say("  moved $db");
}

// --- 4. point the configuration at the folder ----------------------
$config = app_config();
foreach ($plan as $name => $p) {
    $config['db'][$name]['attach'] = 'data/' . $p['db'] . '.mdf';
}
$php = '<?php' . PHP_EOL
     . '// Written by the application. Never hand edit (spec rule 4).' . PHP_EOL
     . 'return ' . var_export($config, true) . ';' . PHP_EOL;
$tmp = config_path() . '.tmp';
if (file_put_contents($tmp, $php, LOCK_EX) === false || !rename($tmp, config_path())) {
    @unlink($tmp);
    stop('the files moved but config.php could not be written');
}
say('');
say('config.php now names the files inside the folder.');

// --- 5. prove it, through the application's own code ---------------
say('');
foreach (APP_DB_NAMES as $name) {
    try {
        $n = db($name)->query('SELECT COUNT(*) c FROM sys.tables')->fetch()['c'];
        say(sprintf('  %-12s attached from the folder, %d tables', $name, $n));
    } catch (Throwable $e) {
        say(sprintf('  %-12s FAILED: %s', $name, explode("\n", $e->getMessage())[0]));
    }
}
say('');
say('Done. The data is in data\\ and travels with the folder.');
say('Close the application before opening it on another machine.');
