<?php
declare(strict_types=1);

/**
 * The application's own functions on PostgreSQL. Started by tests/run-pg.php
 * with scratch settings ("pgTarget": "test") after the schema and the seed
 * are in place; refuses to run otherwise.
 *
 * Ends with "All <n> application checks passed." or exits 1.
 */

if (getenv('PRIOPS_LOCAL_CONFIG') === false) {
    fwrite(STDERR, "Run through tests/run-pg.php, which sets up the scratch settings.\n");
    exit(2);
}
require_once __DIR__ . '/../facilities/lib/db.php';
if (db_engine() !== 'postgres' || !preg_match('/_test$/', db_pg_database())) {
    fwrite(STDERR, "Refusing to run: not on the PostgreSQL test database.\n");
    exit(2);
}
require_once __DIR__ . '/../facilities/lib/auth.php';
require_once __DIR__ . '/../facilities/lib/settings.php';
require_once __DIR__ . '/../facilities/lib/tickets.php';
app_session_start();                       // before any output, as a page does

$fails = 0;
$count = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $fails, $count;
    $count++;
    echo ($ok ? 'ok    ' : 'FAIL  ') . $label . (!$ok && $detail !== '' ? "\n      $detail" : '') . "\n";
    if (!$ok) {
        $fails++;
    }
}
function attempt(string $label, callable $fn): mixed
{
    try {
        return $fn();
    } catch (Throwable $e) {
        check($label, false, get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
        return null;
    }
}

// The installer's step 3 on PostgreSQL: the first administrator in every register.
foreach (APP_DB_NAMES as $n) {
    $st = db($n)->prepare('SELECT UserID FROM app.Users WHERE NetID = ?');
    $st->execute(['testadmin']);
    if ($st->fetchColumn() === false) {
        db($n)->prepare("INSERT INTO app.Users (NetID, DisplayName, Email, Role) VALUES (?, ?, ?, 'admin')")
            ->execute(['testadmin', 'Test Administrator', 'configure-me@example.edu']);
    }
}
check('db_probe answers for all ten registers', count(array_filter(db_probe(), fn($r) => $r['ok'])) === 10);

$u = attempt('current_user', fn() => current_user());
// tests/run-pg.php seeds the owner first (D-016), so he is the first administrator.
check('current_user is the first administrator, the owner, keyed as SQL Server spells it',
    is_array($u) && ($u['NetID'] ?? null) === 'dreslik' && ($u['Role'] ?? null) === 'admin');
check('values come back as strings, as pdo_sqlsrv returns them', is_array($u) && is_string($u['UserID'] ?? null));

$st = db('work')->prepare('SELECT DisplayName FROM app.Users WHERE NetID = ?');
$st->execute(['TESTADMIN']);
check('NetID compares without case, as the SQL Server collation does', $st->fetchColumn() === 'Test Administrator');

$all = attempt('settings_all', fn() => settings_all(true));
check('settings are seeded and read from Work', is_array($all) && count($all) > 40, 'got ' . (is_array($all) ? count($all) : 0));
$numKey = null;
foreach ((array)$all as $k => $row) {
    if ($row['ValueType'] === 'number' && !str_contains((string)$row['SettingValue'], '.')) {
        $numKey = $k;
        break;
    }
}
check('a numeric setting reads as a number', $numKey !== null && is_int(attempt('setting', fn() => setting($numKey))), (string)$numKey);

// Ticket counter: FOR UPDATE inside a transaction, then commit.
$t1 = attempt('next_ticket_number', fn() => next_ticket_number('work'));
$t2 = attempt('next_ticket_number again', fn() => next_ticket_number('work'));
check('ticket numbers count up under the row lock', is_string($t1) && is_string($t2) && $t1 !== $t2
    && (int)substr($t2, -5) === (int)substr($t1, -5) + 1, "$t1 then $t2");

// lastInsertId and statement-level rollback inside a transaction.
$pdo = db('work');
$pdo->beginTransaction();
$pdo->prepare('INSERT INTO app.AuditLog (UserID, Action, TargetType, TargetID, Detail) VALUES (?, ?, ?, ?, ?)')
    ->execute([(int)$u['UserID'], 'test', 'test', 1, 'first']);
$id = (int)$pdo->lastInsertId();
$dupe = false;
try {
    $pdo->prepare('INSERT INTO app.Settings (SettingKey, SettingValue, Label) VALUES (?, ?, ?)')
        ->execute(['plan_warn_days', '1', 'duplicate']);
} catch (PDOException $e) {
    $dupe = true;
}
$pdo->prepare('UPDATE app.AuditLog SET Detail = ? WHERE AuditID = ?')->execute(['second', $id]);
$pdo->commit();
$st = $pdo->prepare('SELECT Detail FROM app.AuditLog WHERE AuditID = ?');
$st->execute([$id]);
check('lastInsertId returns the new identity', $id > 0);
check('a failed statement rolls back alone and the transaction carries on, as on SQL Server',
    $dupe && $st->fetchColumn() === 'second');

// Length limits hold, as NVARCHAR(n) does on SQL Server.
$tooLong = false;
try {
    db('work')->prepare('UPDATE app.Users SET Phone = ? WHERE NetID = ?')->execute([str_repeat('9', 41), 'testadmin']);
} catch (PDOException $e) {
    $tooLong = true;
}
check('a value longer than NVARCHAR(40) is refused', $tooLong);

// A PHP bool binds into a BIT column.
attempt('bool parameter', fn() => db('work')->prepare('UPDATE app.Users SET IsProvisional = ? WHERE NetID = ?')->execute([false, 'testadmin']));
$st = db('work')->prepare('SELECT IsProvisional FROM app.Users WHERE NetID = ?');
$st->execute(['testadmin']);
check('a PHP false lands as 0', $st->fetchColumn() === '0');

// Nulls sort first ascending, as on SQL Server.
$rows = db('work')->query('SELECT TOP 3 BldgNumber, ShortCode FROM ref.Buildings ORDER BY ShortCode')->fetchAll();
check('ORDER BY puts nulls first ascending', $rows && $rows[0]['ShortCode'] === null, json_encode($rows[0] ?? null));
$rows = db('work')->query('SELECT TOP 3 BldgNumber, ShortCode FROM ref.Buildings ORDER BY ShortCode DESC')->fetchAll();
check('ORDER BY DESC puts nulls last', $rows && $rows[0]['ShortCode'] !== null, json_encode($rows[0] ?? null));

// The example data walks every request type through its stages.
require_once __DIR__ . '/../facilities/lib/examples.php';
require_once __DIR__ . '/../facilities/lib/refsync.php';
$_SESSION['dev_netid'] = 'testadmin';

// The example people go into Work and are published outward, as an
// administrator's "publish reference data" does on the live system.
foreach (example_users() as [$netid, $name, $survey, $role]) {
    $st = db('work')->prepare('SELECT COUNT(*) FROM app.Users WHERE NetID = ?');
    $st->execute([$netid]);
    if ((int)$st->fetchColumn() === 0) {
        db('work')->prepare('INSERT INTO app.Users (NetID, DisplayName, Email, SurveyCode, Unit, Role) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$netid, $name, $netid . '@illinois.edu', $survey, EXAMPLE_TAG, $role]);
    }
}
$pub = attempt('refsync_publish', fn() => refsync_publish((int)$u['UserID']));
check('reference data publishes from Work to the other nine registers', is_array($pub) && count($pub['targets'] ?? []) === 9);
$st = db('purchasing')->prepare('SELECT COUNT(*) FROM app.Users WHERE NetID = ?');
$st->execute([example_users()[0][0]]);
check('a published person exists in Purchasing', (int)$st->fetchColumn() === 1);
$cons = attempt('refsync_consistency', fn() => refsync_consistency());
check('the consistency report runs', is_array($cons));
$msg = attempt('examples_load', fn() => examples_load());
check('examples_load runs', is_string($msg), (string)$msg);
$wr = (int)db('work')->query("SELECT COUNT(*) FROM wr.WorkRequests WHERE LegacyReference = 'EXAMPLE'")->fetchColumn();
$mv = (int)db('move')->query('SELECT COUNT(*) FROM mv.MoveRequests')->fetchColumn();
$pr = (int)db('purchasing')->query('SELECT COUNT(*) FROM rq.PurchaseRequests')->fetchColumn();
check('example work, move and purchase requests exist', $wr > 0 && $mv > 0 && $pr > 0, "work $wr, move $mv, purchase $pr");
$hist = (int)db('work')->query('SELECT COUNT(*) FROM app.StatusHistory')->fetchColumn();
check('status history was written', $hist > 0, (string)$hist);
$msg2 = attempt('examples_clear', fn() => examples_clear());
check('examples_clear runs', is_string($msg2), (string)$msg2);
$wr2 = (int)db('work')->query("SELECT COUNT(*) FROM wr.WorkRequests WHERE LegacyReference = 'EXAMPLE'")->fetchColumn();
check('examples_clear removes the example work requests', $wr2 === 0, (string)$wr2);
$msg3 = attempt('examples_load again', fn() => examples_load());
check('examples_load runs a second time (sequences and leftovers)', is_string($msg3), (string)$msg3);

// The Studio database screen on PostgreSQL: the ten schemas through db(), and
// none of the SQL Server settings, which would wake LocalDB if tried.
require_once __DIR__ . '/../facilities/lib/ui.php';
ob_start();
$viewRan = attempt('studio_dbview', function () {
    include __DIR__ . '/../facilities/lib/studio_dbview.php';
    return true;
});
$view = (string)ob_get_clean();
check('the Studio database screen shows the ten PostgreSQL schemas answering',
    $viewRan === true && str_contains($view, '10 of 10 answering') && str_contains($view, 'pg.env')
    && !str_contains($view, 'SQLEXPRESS'), substr(trim(strip_tags($view)), 0, 300));

if ($fails) {
    echo "$fails of $count application checks FAILED.\n";
    exit(1);
}
echo "All $count application checks passed.\n";
