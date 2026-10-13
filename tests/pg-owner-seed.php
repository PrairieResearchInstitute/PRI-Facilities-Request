<?php
declare(strict_types=1);

/**
 * A fresh seed yields exactly one superadmin, the owner (D-016). Started by
 * tests/run-pg.php right after the test database is rebuilt from the
 * migrations and `tools/identity_seed.php --database test seed-owner` has run
 * twice, before any other test adds people.
 *
 *   php tests/pg-owner-seed.php
 *
 * Ends with "All <n> owner seed checks passed." or exits 1.
 */

if (getenv('PRIOPS_LOCAL_CONFIG') === false) {
    fwrite(STDERR, "Run through tests/run-pg.php, which sets up the scratch settings.\n");
    exit(2);
}
require_once __DIR__ . '/../tools/pg/pgcli.php';

$db = pgcli_database(['', '--database', 'test']);
$pdo = pgcli_connect($db);

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

$every = ['deputy', 'director', 'equipment', 'executive', 'facilities', 'finance', 'fleet', 'inventory', 'it',
          'proxy', 'safety', 'studio'];

$users = $pdo->query('SELECT netid, email, role, isactive FROM work.app_users')->fetchAll(PDO::FETCH_ASSOC);
check('a fresh seed leaves exactly one user row, the owner',
    count($users) === 1 && $users[0]['netid'] === 'dreslik' && $users[0]['email'] === 'configure-me@example.edu',
    json_encode($users));
check('the owner holds the admin role, switched on',
    count($users) === 1 && $users[0]['role'] === 'admin' && (int)$users[0]['isactive'] === 1, json_encode($users));

// The reference data (tools/pg/seed.php, from sql/23_real_people.sql) already names survey
// directors, proxies and staff by function; none of them holds every function or a role.
$all = $pdo->query('SELECT netid, accessfunction FROM work.app_adminaccess WHERE isactive = 1 AND surveycode IS NULL')
    ->fetchAll(PDO::FETCH_ASSOC);
$byNetid = [];
foreach ($all as $r) {
    $byNetid[$r['netid']][$r['accessfunction']] = true;
}
$full = array_keys(array_filter($byNetid, fn($f) => count(array_intersect_key(array_flip($every), $f)) === count($every)));
$mine = array_keys($byNetid['dreslik'] ?? []);
sort($mine);
check('the owner holds every access function for every survey, director and studio included', $mine === $every, json_encode($mine));
check('exactly one NetID holds every access function: the owner', $full === ['dreslik'], json_encode($full));
$studio = $pdo->query("SELECT DISTINCT netid FROM work.app_adminaccess WHERE isactive = 1 AND accessfunction = 'studio'")
    ->fetchAll(PDO::FETCH_COLUMN);
check('nobody but the owner holds the Studio', $studio === ['dreslik'], json_encode($studio));

$groups = (int)$pdo->query('SELECT COUNT(*) FROM work.app_groupaccess WHERE isactive = 1')->fetchColumn();
check('no campus group holds anything: the owner assigns every other role', $groups === 0, "$groups group grants");

$others = 0;
foreach (['move', 'shop', 'purchasing', 'keys', 'fleet', 'space', 'equipment', 'incidents', 'plans'] as $schema) {
    $others += (int)$pdo->query("SELECT COUNT(*) FROM $schema.app_users WHERE role = 'admin'")->fetchColumn(); // literal schema names
}
check('no other register holds an administrator', $others === 0, "$others administrators elsewhere");

echo $fails === 0 ? "All $count owner seed checks passed.\n" : "$fails of $count owner seed checks FAILED.\n";
exit($fails === 0 ? 0 : 1);
