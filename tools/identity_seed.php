<?php
declare(strict_types=1);

/**
 * Who holds which authority, keyed by NetID and by campus group: the owner's
 * seeding command for NetID sign-in (migration 0003, facilities/lib/auth.php).
 *
 *   php -d extension=pdo_pgsql tools/identity_seed.php --database test|live <command> [options]
 *
 * Commands:
 *   audit                          read only: counts that show whether the NetIDs
 *                                  on file look like campus NetIDs (add --show to
 *                                  list the ones that do not)
 *   list                           read only: every group grant, then every NetID
 *                                  holding a role above staff or an access function
 *   grant-group  --group G (--function F [--survey S] | --role R) [--note T] --by NETID
 *   revoke-group --group G (--function F [--survey S] | --role R) --by NETID
 *   grant-netid  --netid N --function F [--survey S] [--note T] --by NETID
 *   revoke-netid --netid N --function F [--survey S] --by NETID
 *   set-role     --netid N --role R [--name "Full Name"] [--email a@b] --by NETID
 *   seed         --file seed.json [--dry] --by NETID
 *                                  every grant in the file, in one transaction; the
 *                                  file's shape is tools/identity-seed.example.json
 *   seed-owner   [--dry]           the owner (dreslik, configure-me@example.edu) as the
 *                                  superadmin: role admin on his Work user row and
 *                                  every access function for every survey, director
 *                                  and studio included (D-016). Run it first on a
 *                                  new database; the owner then assigns everyone else.
 *
 * Grants are switched off, never deleted. A NetID is lower case and has no
 * domain ("jdoe", not "configure-me@example.edu"). The target is always named:
 * --database test (PGTESTDATABASE, must end in _test) or --database live.
 * Exit codes: 0 done, 1 a grant was refused, 2 usage.
 */

require_once __DIR__ . '/pg/pgcli.php';
require_once __DIR__ . '/../facilities/lib/identity.php';

const SEED_FUNCTIONS = ['director', 'proxy', 'facilities', 'safety', 'fleet', 'equipment', 'inventory',
                        'it', 'finance', 'deputy', 'executive', 'studio'];
const SEED_ROLES = ['staff', 'coordinator', 'approver', 'admin'];

// The owner, superadmin to start (owner decision 2026-10-12, D-016).
const SEED_OWNER_NETID = 'dreslik';
const SEED_OWNER_EMAIL = 'configure-me@example.edu';
const SEED_OWNER_NAME = 'M. J. Dreslik';
const SEED_OWNER_ROLE = 'admin';

function seed_usage(string $why): never
{
    fwrite(STDERR, $why . "\nSee the head of tools/identity_seed.php for the commands.\n");
    exit(2);
}

function seed_netid(?string $v, string $what = '--netid'): string
{
    $n = identity_netid_from((string)$v, ['illinois.edu']);
    if ($n === null) {
        seed_usage("$what must be a campus NetID, such as jdoe.");
    }
    return $n;
}

/** One grant, checked: ['group'|'netid', function|null, role|null, survey|null, note|null]. */
function seed_grant(array $g): array
{
    $function = isset($g['function']) && $g['function'] !== '' ? strtolower((string)$g['function']) : null;
    $role = isset($g['role']) && $g['role'] !== '' ? strtolower((string)$g['role']) : null;
    $survey = isset($g['survey']) && $g['survey'] !== '' ? strtoupper((string)$g['survey']) : null;
    if ($function !== null && !in_array($function, SEED_FUNCTIONS, true)) {
        seed_usage("Unknown access function '$function'. One of: " . implode(', ', SEED_FUNCTIONS) . '.');
    }
    if ($role !== null && !in_array($role, SEED_ROLES, true)) {
        seed_usage("Unknown role '$role'. One of: " . implode(', ', SEED_ROLES) . '.');
    }
    if ($survey !== null && !preg_match('/^[A-Z0-9_-]{1,10}$/', $survey)) {
        seed_usage("Survey code '$survey' is not a survey code.");
    }
    return ['function' => $function, 'role' => $role, 'survey' => $survey,
            'note' => isset($g['note']) ? mb_substr((string)$g['note'], 0, 200) : null];
}

function seed_group_name(?string $g): string
{
    $g = trim((string)$g);
    if ($g === '' || mb_strlen($g) > 256) {
        seed_usage('--group names a campus group, 1 to 256 characters, as the proxy spells it.');
    }
    return $g;
}

/** Grant to a group, or switch one back on. */
function seed_grant_group(PDO $pdo, string $group, array $g, string $by, callable $say): void
{
    if (($g['function'] === null) === ($g['role'] === null)) {
        seed_usage('A group grant names exactly one of --function or --role.');
    }
    if ($g['role'] !== null && $g['survey'] !== null) {
        seed_usage('A role holds across every survey; leave out --survey for --role.');
    }
    if ($g['role'] === 'staff') {
        seed_usage('Everybody who signs in is staff already; a group grant names a higher role.');
    }
    $find = $pdo->prepare("SELECT groupaccessid, isactive FROM work.app_groupaccess
        WHERE groupname = ? AND COALESCE(surveycode, '') = COALESCE(?, '')
          AND COALESCE(accessfunction, '') = COALESCE(?, '') AND COALESCE(role, '') = COALESCE(?, '')");
    $find->execute([$group, $g['survey'], $g['function'], $g['role']]);
    $row = $find->fetch();
    $what = $g['role'] !== null ? 'role ' . $g['role'] : 'function ' . $g['function'] . ' for ' . ($g['survey'] ?? 'every survey');
    if ($row && (int)$row['isactive'] === 1) {
        $say("  unchanged  group $group: $what");
        return;
    }
    if ($row) {
        $pdo->prepare('UPDATE work.app_groupaccess SET isactive = 1, grantedbynetid = ?, note = COALESCE(?, note),
                       grantedat = LOCALTIMESTAMP WHERE groupaccessid = ?')
            ->execute([$by, $g['note'], (int)$row['groupaccessid']]);
        $say("  restored   group $group: $what");
        return;
    }
    $pdo->prepare('INSERT INTO work.app_groupaccess (groupname, surveycode, accessfunction, role, note, grantedbynetid)
                   VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$group, $g['survey'], $g['function'], $g['role'], $g['note'], $by]);
    $say("  granted    group $group: $what");
}

function seed_revoke_group(PDO $pdo, string $group, array $g, callable $say): int
{
    $st = $pdo->prepare("UPDATE work.app_groupaccess SET isactive = 0
        WHERE isactive = 1 AND groupname = ? AND COALESCE(surveycode, '') = COALESCE(?, '')
          AND COALESCE(accessfunction, '') = COALESCE(?, '') AND COALESCE(role, '') = COALESCE(?, '')");
    $st->execute([$group, $g['survey'], $g['function'], $g['role']]);
    $n = $st->rowCount();
    $say($n ? "  revoked    group $group" : "  nothing to revoke for group $group");
    return $n ? 0 : 1;
}

/** Grant an access function to one NetID in app.AdminAccess (Work), or switch it back on. */
function seed_grant_netid(PDO $pdo, string $netid, array $g, string $by, callable $say): void
{
    if ($g['function'] === null || $g['role'] !== null) {
        seed_usage('grant-netid names --function; a NetID role is set with set-role.');
    }
    $find = $pdo->prepare("SELECT adminaccessid, isactive FROM work.app_adminaccess
        WHERE netid = ? AND COALESCE(surveycode, '') = COALESCE(?, '') AND accessfunction = ?");
    $find->execute([$netid, $g['survey'], $g['function']]);
    $row = $find->fetch();
    $what = 'function ' . $g['function'] . ' for ' . ($g['survey'] ?? 'every survey');
    if ($row && (int)$row['isactive'] === 1) {
        $say("  unchanged  $netid: $what");
        return;
    }
    if ($row) {
        $pdo->prepare('UPDATE work.app_adminaccess SET isactive = 1, grantedbynetid = ?, grantedat = LOCALTIMESTAMP
                       WHERE adminaccessid = ?')->execute([$by, (int)$row['adminaccessid']]);
        $say("  restored   $netid: $what");
        return;
    }
    $pdo->prepare('INSERT INTO work.app_adminaccess (netid, surveycode, accessfunction, note, grantedbynetid)
                   VALUES (?, ?, ?, ?, ?)')
        ->execute([$netid, $g['survey'], $g['function'], $g['note'], $by]);
    $say("  granted    $netid: $what");
}

function seed_revoke_netid(PDO $pdo, string $netid, array $g, callable $say): int
{
    $st = $pdo->prepare("UPDATE work.app_adminaccess SET isactive = 0
        WHERE isactive = 1 AND netid = ? AND COALESCE(surveycode, '') = COALESCE(?, '') AND accessfunction = ?");
    $st->execute([$netid, $g['survey'], (string)$g['function']]);
    $n = $st->rowCount();
    $say($n ? "  revoked    $netid: function {$g['function']}" : "  nothing to revoke for $netid");
    return $n ? 0 : 1;
}

/** Set the role on one NetID's Work user row, creating the row when needed. */
function seed_set_role(PDO $pdo, string $netid, string $role, ?string $name, ?string $email, callable $say): void
{
    if (!in_array($role, SEED_ROLES, true)) {
        seed_usage("Unknown role '$role'. One of: " . implode(', ', SEED_ROLES) . '.');
    }
    $st = $pdo->prepare('SELECT userid, role FROM work.app_users WHERE netid = ?');
    $st->execute([$netid]);
    $row = $st->fetch();
    if ($row) {
        if (strtolower((string)$row['role']) === $role) {
            $say("  unchanged  $netid: role $role");
            return;
        }
        $pdo->prepare('UPDATE work.app_users SET role = ?, isactive = 1 WHERE userid = ?')->execute([$role, (int)$row['userid']]);
        $say("  updated    $netid: role {$row['role']} -> $role");
        return;
    }
    $pdo->prepare('INSERT INTO work.app_users (netid, displayname, email, role, isprovisional) VALUES (?, ?, ?, ?, 1)')
        ->execute([$netid, $name ?? $netid, $email ?? ($netid . '@illinois.edu'), $role]);
    $say("  created    $netid: role $role (provisional until the first sign-in)");
}

// ------------------------------------------------------------------ main
$valued = ['--database', '--group', '--function', '--survey', '--role', '--note', '--by', '--netid', '--name',
           '--email', '--file'];
$command = null;
for ($i = 1; $i < count($argv); $i++) {
    if (in_array($argv[$i], $valued, true)) {
        $i++;                              // skip the option's value
    } elseif (!str_starts_with($argv[$i], '--')) {
        $command = $argv[$i];
        break;
    }
}
if ($command === null) {
    seed_usage('Name a command: audit, list, grant-group, revoke-group, grant-netid, revoke-netid, set-role, seed or seed-owner.');
}
pgcli_timezone();
$db = pgcli_database($argv);
$pdo = pgcli_connect($db);
$say = static fn(string $s) => print($s . PHP_EOL);
$opt = static fn(string $n) => pgcli_arg($argv, $n);

$has = $pdo->query("SELECT to_regclass('work.app_groupaccess') IS NOT NULL")->fetchColumn();
if (!$has && $command !== 'audit') {
    fwrite(STDERR, "database $db has no work.app_groupaccess: run tools/pg/migrate.php --database "
        . pgcli_arg($argv, 'database') . " first (migration 0003).\n");
    exit(2);
}

if ($command === 'audit') {
    $say("database $db: NetID audit (read only)");
    $users = $pdo->query('SELECT netid, role, isactive, isprovisional, lastloginat FROM work.app_users')->fetchAll();
    $bad = array_values(array_filter($users, fn($u) => identity_netid_from((string)$u['netid'], []) === null));
    $roles = [];
    foreach ($users as $u) {
        if ((int)$u['isactive'] === 1) {
            $roles[strtolower((string)$u['role'])] = ($roles[strtolower((string)$u['role'])] ?? 0) + 1;
        }
    }
    ksort($roles);
    $say('  users: ' . count($users) . ' (' . count(array_filter($users, fn($u) => (int)$u['isactive'] === 1)) . ' active, '
        . count(array_filter($users, fn($u) => (int)$u['isprovisional'] === 1)) . ' imported, never signed in)');
    $say('  active roles: ' . ($roles ? implode(', ', array_map(fn($k, $v) => "$k $v", array_keys($roles), $roles)) : 'none'));
    $say('  NetIDs that do not look like a campus NetID (a domain, a space or a symbol): ' . count($bad));
    if (pgcli_flag($argv, 'show')) {
        foreach ($bad as $u) {
            $say('    ' . $u['netid']);
        }
    }
    $aa = $pdo->query('SELECT COUNT(*) AS n, COUNT(DISTINCT netid) AS people FROM work.app_adminaccess WHERE isactive = 1')->fetch();
    $orphans = (int)$pdo->query('SELECT COUNT(*) FROM work.app_adminaccess a WHERE a.isactive = 1
        AND NOT EXISTS (SELECT 1 FROM work.app_users u WHERE u.netid = a.netid)')->fetchColumn();
    $say("  access function grants by NetID: {$aa['n']} active, held by {$aa['people']} NetIDs; $orphans name a NetID with no user row");
    $say('  group grants: ' . ($has ? (int)$pdo->query('SELECT COUNT(*) FROM work.app_groupaccess WHERE isactive = 1')->fetchColumn()
        . ' active' : 'table missing (migration 0003 not applied)'));
    $say(count($bad) === 0 ? 'AUDIT OK: every NetID on file has the shape of a campus NetID.'
        : 'AUDIT: ' . count($bad) . ' NetID(s) need a look before NetID sign-in (add --show to list them).');
    exit(0);
}

if ($command === 'list') {
    $say("database $db: group grants");
    foreach ($pdo->query('SELECT groupname, surveycode, accessfunction, role, isactive, grantedbynetid, note
                          FROM work.app_groupaccess ORDER BY groupname, role, accessfunction, surveycode') as $r) {
        $say(sprintf('  %-8s %-40s %s%s%s', (int)$r['isactive'] === 1 ? 'active' : 'off', $r['groupname'],
            $r['role'] !== null ? 'role ' . $r['role'] : 'function ' . $r['accessfunction'],
            $r['surveycode'] !== null ? ' for ' . $r['surveycode'] : '',
            $r['note'] !== null ? '  (' . $r['note'] . ')' : ''));
    }
    $say("database $db: NetIDs above staff");
    foreach ($pdo->query("SELECT netid, role FROM work.app_users WHERE isactive = 1 AND role <> 'staff' ORDER BY role, netid") as $r) {
        $say("  {$r['role']}  {$r['netid']}");
    }
    $say("database $db: access functions by NetID");
    foreach ($pdo->query('SELECT netid, accessfunction, surveycode FROM work.app_adminaccess WHERE isactive = 1
                          ORDER BY netid, accessfunction, surveycode') as $r) {
        $say("  {$r['netid']}  {$r['accessfunction']}" . ($r['surveycode'] !== null ? " for {$r['surveycode']}" : ''));
    }
    exit(0);
}

$by = $command === 'seed-owner' && $opt('by') === null
    ? SEED_OWNER_NETID
    : seed_netid($opt('by'), '--by (the NetID of the person granting)');
$code = 0;
$pdo->beginTransaction();
try {
    switch ($command) {
        case 'seed-owner':
            seed_set_role($pdo, SEED_OWNER_NETID, SEED_OWNER_ROLE, SEED_OWNER_NAME, SEED_OWNER_EMAIL, $say);
            foreach (SEED_FUNCTIONS as $function) {
                seed_grant_netid($pdo, SEED_OWNER_NETID, ['function' => $function, 'role' => null, 'survey' => null,
                    'note' => 'owner, superadmin to start (D-016)'], $by, $say);
            }
            break;
        case 'grant-group':
            seed_grant_group($pdo, seed_group_name($opt('group')), seed_grant(['function' => $opt('function'),
                'role' => $opt('role'), 'survey' => $opt('survey'), 'note' => $opt('note')]), $by, $say);
            break;
        case 'revoke-group':
            $code = seed_revoke_group($pdo, seed_group_name($opt('group')), seed_grant(['function' => $opt('function'),
                'role' => $opt('role'), 'survey' => $opt('survey')]), $say);
            break;
        case 'grant-netid':
            seed_grant_netid($pdo, seed_netid($opt('netid')), seed_grant(['function' => $opt('function'),
                'survey' => $opt('survey'), 'note' => $opt('note')]), $by, $say);
            break;
        case 'revoke-netid':
            $code = seed_revoke_netid($pdo, seed_netid($opt('netid')), seed_grant(['function' => $opt('function'),
                'survey' => $opt('survey')]), $say);
            break;
        case 'set-role':
            seed_set_role($pdo, seed_netid($opt('netid')), strtolower((string)$opt('role')), $opt('name'), $opt('email'), $say);
            break;
        case 'seed':
            $file = (string)$opt('file');
            if ($file === '' || !is_file($file)) {
                seed_usage('seed needs --file naming a JSON file shaped like tools/identity-seed.example.json.');
            }
            $data = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', (string)file_get_contents($file)), true);
            if (!is_array($data)) {
                seed_usage("$file is not valid JSON.");
            }
            foreach ($data['people'] ?? [] as $p) {
                seed_set_role($pdo, seed_netid($p['netid'] ?? null), strtolower((string)($p['role'] ?? 'staff')),
                    $p['name'] ?? null, $p['email'] ?? null, $say);
            }
            foreach ($data['netids'] ?? [] as $p) {
                seed_grant_netid($pdo, seed_netid($p['netid'] ?? null), seed_grant($p), $by, $say);
            }
            foreach ($data['groups'] ?? [] as $p) {
                seed_grant_group($pdo, seed_group_name($p['group'] ?? null), seed_grant($p), $by, $say);
            }
            break;
        default:
            seed_usage("Unknown command '$command'.");
    }
    if (pgcli_flag($argv, 'dry')) {
        $pdo->rollBack();
        $say("DRY RUN: nothing written to $db.");
    } else {
        $pdo->commit();
        $say("done: $db");
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'REFUSED, nothing written: ' . $e->getMessage() . "\n");
    exit(1);
}
exit($code);
