<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/identity.php';

/**
 * Identity layer. Who the person is comes from identity.php:
 *
 *   AUTH_MODE=proxy  (the server) the university's authenticating proxy
 *                    passes the NetID in a trusted header. A NetID seen for
 *                    the first time gets a staff row in app.Users; an
 *                    imported (provisional) row becomes a real one.
 *   AUTH_MODE=local  (this computer only, the default) the session carries
 *                    an acting NetID, switchable from the page header, so
 *                    every screen can be exercised from any user's
 *                    perspective. Refused behind a proxy.
 *
 * What the person may do comes from their NetID and their campus groups:
 * the Role on their app.Users row, the access functions granted to their
 * NetID in app.AdminAccess, and anything work.app_groupaccess grants to a
 * group the proxy says they belong to (migrations/pgsql/0003_group_access.sql,
 * seeded with tools/identity_seed.php). Every permission check below and in
 * access.php reads the merged result, so none of them changed.
 */

const ROLE_ORDER = ['staff' => 0, 'coordinator' => 1, 'approver' => 1, 'admin' => 2];

/** Write the refusal and stop. 401 and 403 rather than a redirect (spec rule 5). */
function auth_refuse(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $message;
    exit;
}

/** The resolved identity of this request (identity_resolve), computed once. */
function auth_identity(): array
{
    static $id = null;
    if ($id === null) {
        $id = identity_resolve($_SERVER, identity_settings());
    }
    return $id;
}

function auth_mode(): string
{
    return auth_identity()['mode'];
}

/** May this request use the acting-user switcher? Local mode only, outside production. */
function auth_can_switch(): bool
{
    return auth_mode() === 'local' && auth_identity()['status'] === 200
        && app_environment() !== 'production';
}

/**
 * What work.app_groupaccess grants to these campus groups: rows of
 * GroupName, SurveyCode, AccessFunction, Role. PostgreSQL only: on the
 * LocalDB rollback there is no such table and no proxy, so no group grants.
 */
function auth_group_grants(array $groups): array
{
    if ($groups === [] || db_engine() !== 'postgres') {
        return [];
    }
    static $cache = [];
    $key = strtolower(implode("\n", $groups));
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $marks = implode(', ', array_fill(0, count($groups), '?'));
    // Aliases give the keys the spelling the rest of the code reads; the
    // translator would otherwise re-key some names and not others.
    $st = db('work')->prepare('SELECT groupname AS GroupName, surveycode AS SurveyCode,
                                      accessfunction AS AccessFunction, role AS Role
                               FROM work.app_groupaccess
                               WHERE isactive = 1 AND groupname IN (' . $marks . ')');
    $st->execute(array_values($groups));
    return $cache[$key] = $st->fetchAll();
}

/**
 * The user row with the group grants folded in: Role becomes the primary
 * effective role, Roles lists every role held, StoredRole keeps the row's
 * own value, Groups the campus groups the proxy passed, GroupGrants the
 * matching work.app_groupaccess rows.
 */
function auth_effective_user(array $row, array $groups, array $grants): array
{
    $groupRoles = [];
    foreach ($grants as $g) {
        if (($g['Role'] ?? null) !== null && $g['Role'] !== '') {
            $groupRoles[] = strtolower((string)$g['Role']);
        }
    }
    $stored = (string)$row['Role'];
    $row['StoredRole'] = $stored;
    $row['Roles'] = array_values(array_unique(array_merge([$stored], $groupRoles)));
    $row['Role'] = identity_primary_role($stored, $groupRoles);
    $row['Groups'] = $groups;
    $row['GroupGrants'] = $grants;
    return $row;
}

/** Find or create the app.Users row for a NetID the proxy vouched for. */
function auth_proxy_user(array $id): array
{
    $pdo = db('work');
    $stmt = $pdo->prepare('SELECT * FROM app.Users WHERE NetID = ?');
    $stmt->execute([$id['netid']]);
    $row = $stmt->fetch();
    if (!$row) {
        $pdo->prepare("INSERT INTO app.Users (NetID, DisplayName, Email, Role, IsProvisional, LastLoginAt)
                       VALUES (?, ?, ?, 'staff', 0, SYSDATETIME())")
            ->execute([$id['netid'], $id['name'] ?? $id['netid'], $id['email'] ?? $id['netid']]);
        $stmt->execute([$id['netid']]);
        $row = $stmt->fetch();
        $_SESSION['auth_seen'] = $id['netid'];
    }
    if ((int)$row['IsActive'] !== 1) {
        auth_refuse(403, 'Your NetID ' . $id['netid'] . ' is switched off in this system. '
            . 'Ask an administrator to restore it under People and roles.');
    }
    // Once a session: the last sign-in, and an imported person becomes real.
    if (($_SESSION['auth_seen'] ?? null) !== $id['netid']) {
        $pdo->prepare('UPDATE app.Users SET LastLoginAt = SYSDATETIME(), IsProvisional = 0 WHERE UserID = ?')
            ->execute([(int)$row['UserID']]);
        $_SESSION['auth_seen'] = $id['netid'];
    }
    return $row;
}

function current_user(): array
{
    static $user = null;
    if ($user !== null) {
        return $user;
    }
    $id = auth_identity();
    if ($id['status'] !== 200) {
        auth_refuse($id['status'], $id['message']);
    }
    app_session_start();

    if ($id['mode'] === 'proxy') {
        // A shared browser that changes hands keeps no session, and so no
        // CSRF token, from the person before.
        if (isset($_SESSION['auth_netid']) && $_SESSION['auth_netid'] !== $id['netid']) {
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
                session_regenerate_id(true);
            }
        }
        $_SESSION['auth_netid'] = $id['netid'];
        $row = auth_proxy_user($id);
        return $user = auth_effective_user($row, $id['groups'], auth_group_grants($id['groups']));
    }

    $netid = $_SESSION['dev_netid'] ?? null;
    $pdo = db('work');
    $row = null;
    if ($netid !== null) {
        $stmt = $pdo->prepare('SELECT * FROM app.Users WHERE NetID = ? AND IsActive = 1');
        $stmt->execute([$netid]);
        $row = $stmt->fetch() ?: null;
    }
    if ($row === null) {
        // Default to the first administrator captured at installation.
        $stmt = $pdo->query("SELECT TOP 1 * FROM app.Users WHERE Role = 'admin' AND IsActive = 1 ORDER BY UserID");
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('No active admin user exists; rerun the installer.');
        }
        $_SESSION['dev_netid'] = $row['NetID'];
    }
    return $user = auth_effective_user($row, [], []);
}

/**
 * The same person must exist in the database being written to, because
 * foreign keys cannot cross databases and surrogate IDs differ per copy.
 * Returns the LOCAL UserID for that database. The copy carries the role on
 * the person's own row, never one a group lends them for this request.
 */
function ensure_user(string $dbName, ?array $user = null): int
{
    $user = $user ?? current_user();
    $pdo = db($dbName);
    $stmt = $pdo->prepare('SELECT UserID FROM app.Users WHERE NetID = ?');
    $stmt->execute([$user['NetID']]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
        return (int)$id;
    }
    $pdo->prepare('INSERT INTO app.Users (NetID, DisplayName, Email, SurveyCode, Role, IsProvisional)
                   VALUES (?, ?, ?, ?, ?, 0)')
        ->execute([$user['NetID'], $user['DisplayName'], $user['Email'],
                   $user['SurveyCode'], $user['StoredRole'] ?? $user['Role']]);
    $stmt->execute([$user['NetID']]);
    return (int)$stmt->fetchColumn();
}

function user_role(): string
{
    return current_user()['Role'];
}

/** Every role the person holds: their own and any their groups grant. */
function user_roles(): array
{
    $u = current_user();
    return $u['Roles'] ?? [$u['Role']];
}

function role_at_least(string $role): bool
{
    $r = user_roles();
    if ($role === 'coordinator') {
        return array_intersect($r, ['coordinator', 'admin']) !== [];
    }
    if ($role === 'approver') {
        return array_intersect($r, ['approver', 'admin']) !== [];
    }
    if ($role === 'admin') {
        return in_array('admin', $r, true);
    }
    return true; // staff
}

/** 403 rather than redirect (spec rule 5). */
function require_role(string $role): void
{
    if (!role_at_least($role)) {
        http_response_code(403);
        header('Content-Type: text/plain');
        echo 'Your role (' . user_role() . ') is not permitted to do this.';
        exit;
    }
}

/** Handle the dev user switcher POST; call before rendering any page. */
function handle_user_switch(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['dev_switch'] ?? '') === '1') {
        csrf_require();
        if (auth_can_switch()) {
            $_SESSION['dev_netid'] = (string)($_POST['dev_netid'] ?? '');
        }
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . (empty($_SERVER['QUERY_STRING']) ? '' : '?' . $_SERVER['QUERY_STRING']));
        exit;
    }
}

function all_users(): array
{
    return db('work')->query('SELECT NetID, DisplayName, SurveyCode, Role FROM app.Users
                              WHERE IsActive = 1 ORDER BY DisplayName')->fetchAll();
}
