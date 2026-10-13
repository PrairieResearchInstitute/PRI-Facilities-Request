<?php
declare(strict_types=1);

/**
 * NetID sign-in on PostgreSQL: the proxy header, provisioning, and authority
 * from campus groups (migration 0003, tools/identity_seed.php). Started by
 * tests/run-pg.php once per scenario, after the seed file
 * tools/identity-seed.example.json is loaded into the test database; one
 * process is one request, so one identity.
 *
 *   php tests/pg-identity.php <scenario>
 *     admin-group   a new NetID whose group grants the admin role
 *     function-groups  a new NetID whose groups grant finance (every survey)
 *                   and facilities (INHS only)
 *     revoked       the same NetID after the finance grant is switched off
 *     inactive      a NetID whose user row is switched off
 *     local-proxied AUTH_MODE local, reached from another computer
 *
 * Ends with "All <n> identity checks passed (<scenario>)." or exits 1.
 */

if (getenv('PRIOPS_LOCAL_CONFIG') === false) {
    fwrite(STDERR, "Run through tests/run-pg.php, which sets up the scratch settings.\n");
    exit(2);
}
$scenario = $argv[1] ?? '';
$groups = 'example-pri-oed-finance,example-inhs-facilities,unrelated-group';
$setups = [
    'admin-group'     => ['proxy', 'configure-me@example.edu', 'example-pri-facilities-admins', '10.1.2.3'],
    'function-groups' => ['proxy', 'configure-me@example.edu', $groups, '10.1.2.3'],
    'revoked'         => ['proxy', 'configure-me@example.edu', $groups, '10.1.2.3'],
    'inactive'        => ['proxy', 'configure-me@example.edu', '', '10.1.2.3'],
    'local-proxied'   => ['local', null, '', '10.1.2.3'],
];
if (!isset($setups[$scenario])) {
    fwrite(STDERR, 'Name a scenario: ' . implode(', ', array_keys($setups)) . "\n");
    exit(2);
}
[$mode, $email, $groupHeader, $ip] = $setups[$scenario];
putenv('AUTH_MODE=' . $mode);
putenv('AUTH_TRUSTED_PROXIES=10.1.0.0/16');
$_SERVER['REMOTE_ADDR'] = $ip;
if ($email !== null) {
    $_SERVER['HTTP_X_FORWARDED_EMAIL'] = $email;
}
if ($groupHeader !== '') {
    $_SERVER['HTTP_X_FORWARDED_GROUPS'] = $groupHeader;
}

require_once __DIR__ . '/../facilities/lib/db.php';
if (db_engine() !== 'postgres' || !preg_match('/_test$/', db_pg_database())) {
    fwrite(STDERR, "Refusing to run: not on the PostgreSQL test database.\n");
    exit(2);
}
require_once __DIR__ . '/../facilities/lib/auth.php';
require_once __DIR__ . '/../facilities/lib/access.php';
require_once __DIR__ . '/../facilities/lib/studio.php';
app_session_start();

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
function finish(string $scenario): never
{
    global $fails, $count;
    echo $fails === 0 ? "All $count identity checks passed ($scenario).\n" : "$fails of $count identity checks FAILED ($scenario).\n";
    exit($fails === 0 ? 0 : 1);
}

// A refusal ends the request with exit; the shutdown function reports it.
if (in_array($scenario, ['inactive', 'local-proxied'], true)) {
    if ($scenario === 'inactive') {
        $st = db('work')->prepare('SELECT COUNT(*) FROM app.Users WHERE NetID = ?');
        $st->execute(['offperson']);
        if ((int)$st->fetchColumn() === 0) {
            db('work')->prepare("INSERT INTO app.Users (NetID, DisplayName, Email, Role, IsActive) VALUES (?, ?, ?, 'admin', 0)")
                ->execute(['offperson', 'Switched Off', 'configure-me@example.edu']);
        }
    }
    register_shutdown_function(function () use ($scenario) {
        global $count, $fails;
        if ($count === 0) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            $code = http_response_code();
            check('the request is refused with 403 (' . $scenario . ')', $code === 403, 'status ' . var_export($code, true));
            echo $fails === 0 ? "All $count identity checks passed ($scenario).\n" : "$fails of $count identity checks FAILED ($scenario).\n";
            if ($fails !== 0) {
                exit(1);
            }
        }
    });
    ob_start(static fn(string $buf) => '');          // the refusal text is for a browser
    current_user();
    ob_end_clean();
    check('the request should have been refused, but current_user() answered', false);
    finish($scenario);
}

$u = current_user();
$st = db('work')->prepare('SELECT Role, IsProvisional, LastLoginAt, Email FROM app.Users WHERE NetID = ?');
$st->execute([$u['NetID']]);
$row = $st->fetch();

if ($scenario === 'admin-group') {
    check('the NetID comes from the proxy header', $u['NetID'] === 'newadmin');
    check('a NetID seen for the first time gets a staff user row, signed in now',
        $row && $row['Role'] === 'staff' && $row['IsProvisional'] === '0' && $row['LastLoginAt'] !== null
        && $row['Email'] === 'configure-me@example.edu', json_encode($row));
    check('the admin group makes the effective role admin', $u['Role'] === 'admin' && $u['StoredRole'] === 'staff');
    check('role checks see the group role', role_at_least('admin') && role_at_least('coordinator') && role_at_least('approver'));
    check('an administrator reaches the back end', can_admin() && has_function(['safety'], 'ISGS'));
    $id = ensure_user('move');
    $st = db('move')->prepare('SELECT Role FROM app.Users WHERE UserID = ?');
    $st->execute([$id]);
    check('the copy in another register carries the stored role, never the lent one', $st->fetchColumn() === 'staff');
    check('the acting-user switcher is off behind the proxy', !auth_can_switch());
    finish($scenario);
}

if ($scenario === 'function-groups' || $scenario === 'revoked') {
    check('the NetID comes from the proxy header', $u['NetID'] === 'newstaff');
    check('function grants leave the role at staff', $u['Role'] === 'staff' && !role_at_least('coordinator'));
    check('only groups with a grant match; the unrelated group grants nothing',
        count($u['GroupGrants']) === ($scenario === 'revoked' ? 1 : 2), json_encode($u['GroupGrants']));
    check('a group function grant opens the back end', can_admin());
    check('the INHS facilities group grants facilities for INHS', has_function(['facilities'], 'INHS'));
    check('and not for another survey', !has_function(['facilities'], 'ISGS'));
    check('may decide the INHS committee stage through the group', may_decide_committee('INHS'));
    check('no director stage from a facilities grant', !may_decide_director('INHS'));
    check('no Studio unless a group or NetID holds studio', !can_studio());
    $mine = access_rows('newstaff');
    check('the group rows are shaped as app.AdminAccess rows',
        $mine !== [] && isset($mine[0]['AccessFunction'], $mine[0]['NetID']) && $mine[0]['Source'] === 'group');
    check("another person's rows never pick up the signed-in person's groups",
        array_filter(access_rows('testadmin'), fn($r) => ($r['Source'] ?? '') === 'group') === []);
    if ($scenario === 'function-groups') {
        check('the finance group grants finance in every survey', has_function(['finance'], 'ISGS') && has_function(['finance'], 'INHS'));
        check('a grant for every survey means no survey filter', access_surveys() === []);
        check('the description names the group grants', str_contains(access_description(), 'finance'));
    } else {
        check('a switched-off group grant grants nothing', !has_function(['finance'], 'ISGS'));
        check('the survey filter now follows the INHS grant', access_surveys() === ['INHS'], json_encode(access_surveys()));
    }
    finish($scenario);
}
