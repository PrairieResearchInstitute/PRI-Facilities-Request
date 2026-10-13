<?php
declare(strict_types=1);

/**
 * Who is asking, and from where. No database, no session: the functions
 * here take the request ($_SERVER) and the settings as arguments, so
 * tests/identity.php runs them without a server.
 *
 * Two modes, chosen by the AUTH_MODE setting:
 *
 *   proxy   NetID sign-in. The university's authenticating proxy (NCSA
 *           Radiant) verifies the person and passes the identity in a
 *           trusted header; the app reads it and never asks for a password.
 *           The header is trustworthy only while the app is reachable
 *           exclusively through the proxy: never publish the container's
 *           port, and name the proxy's addresses in AUTH_TRUSTED_PROXIES.
 *   local   This computer only: the development login (the acting-user
 *           switcher). Refused for any request that did not come from this
 *           computer, for any request that carries a proxy header, and on
 *           a server tier (TIER test or live).
 *
 * Every setting is read from the environment (the tier's env file on the
 * server; see env.template.txt), so the move to Radiant fills in values
 * and changes no code. An unset AUTH_MODE means local, which fails closed
 * behind a proxy: every proxied request is refused.
 */

const IDENTITY_DEFAULTS = [
    'AUTH_MODE'            => 'local',
    'AUTH_USER_HEADER'     => 'X-Forwarded-Email',
    'AUTH_EMAIL_HEADER'    => 'X-Forwarded-Email',
    'AUTH_NAME_HEADER'     => '',
    'AUTH_GROUPS_HEADER'   => 'X-Forwarded-Groups',
    'AUTH_GROUPS_SEPARATOR' => ',',
    'AUTH_NETID_DOMAINS'   => 'illinois.edu',
    'AUTH_TRUSTED_PROXIES' => '',
    'AUTH_ALLOWED_GROUPS'  => '',
    'AUTH_LOGOUT_URL'      => '',
    'TIER'                 => '',
];

/** Headers that mean a request passed through a proxy. */
const IDENTITY_PROXY_MARKERS = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_PROTO',
    'HTTP_X_FORWARDED_EMAIL', 'HTTP_X_FORWARDED_USER', 'HTTP_X_FORWARDED_GROUPS',
    'HTTP_X_FORWARDED_PREFERRED_USERNAME', 'HTTP_X_REAL_IP', 'HTTP_FORWARDED', 'HTTP_X_AUTH_REQUEST_USER',
    'HTTP_X_AUTH_REQUEST_EMAIL', 'HTTP_REMOTE_USER'];

/** A campus NetID: letters, digits and a few separators, at most 64 characters. */
const IDENTITY_NETID_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,63}$/';

/** The identity settings, from the environment, with the defaults above. */
function identity_settings(?callable $env = null): array
{
    $env = $env ?? static fn(string $k) => getenv($k);
    $out = [];
    foreach (IDENTITY_DEFAULTS as $k => $default) {
        $v = $env($k);
        $out[$k] = ($v === false || $v === null || trim((string)$v) === '') ? $default : trim((string)$v);
    }
    $out['AUTH_MODE'] = strtolower($out['AUTH_MODE']);
    return $out;
}

/** "X-Forwarded-Email" -> "HTTP_X_FORWARDED_EMAIL", as PHP names a request header. */
function identity_server_key(string $header): string
{
    return 'HTTP_' . strtoupper(str_replace('-', '_', trim($header)));
}

/** Read one request header, trimmed; '' when absent or when no header is configured. */
function identity_header(array $server, string $header): string
{
    if (trim($header) === '') {
        return '';
    }
    return trim((string)($server[identity_server_key($header)] ?? ''));
}

/** Split a list setting into trimmed, non-empty items, in their own spelling. */
function identity_list(string $value, string $sep = ','): array
{
    $out = [];
    foreach (explode($sep, $value) as $item) {
        $item = trim($item);
        if ($item !== '') {
            $out[] = $item;
        }
    }
    return $out;
}

/** Is $ip inside one of the addresses or CIDR ranges (IPv4 or IPv6)? */
function identity_ip_allowed(string $ip, array $ranges): bool
{
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return false;
    }
    foreach ($ranges as $range) {
        [$net, $bits] = array_pad(explode('/', $range, 2), 2, null);
        $netBin = @inet_pton(trim((string)$net));
        if ($netBin === false || strlen($netBin) !== strlen($bin)) {
            continue;
        }
        $max = strlen($bin) * 8;
        $bits = $bits === null ? $max : (int)$bits;
        if ($bits < 0 || $bits > $max) {
            continue;
        }
        $whole = intdiv($bits, 8);
        if (substr($bin, 0, $whole) !== substr($netBin, 0, $whole)) {
            continue;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        if ((ord($bin[$whole]) & $mask) === (ord($netBin[$whole]) & $mask)) {
            return true;
        }
    }
    return false;
}

/**
 * The NetID in a header value: "configure-me@example.edu" -> "jdoe" when the
 * domain is one of AUTH_NETID_DOMAINS; a bare "jdoe" stands as it is.
 * Returns null for an address at another domain or a malformed value.
 */
function identity_netid_from(string $value, array $domains): ?string
{
    $value = strtolower(trim($value));
    if ($value === '') {
        return null;
    }
    $at = strrpos($value, '@');
    if ($at !== false) {
        $domain = substr($value, $at + 1);
        if (!in_array($domain, array_map('strtolower', $domains), true)) {
            return null;
        }
        $value = substr($value, 0, $at);
    }
    return preg_match(IDENTITY_NETID_PATTERN, $value) ? $value : null;
}

/**
 * May the local development login answer this request? Only from this
 * computer (the command line, or a loopback address with no proxy header
 * at all) and never on a server tier.
 */
function identity_local_allowed(array $server, array $settings, string $sapi = PHP_SAPI): bool
{
    if (in_array(strtolower($settings['TIER']), ['test', 'live'], true)) {
        return false;
    }
    if ($sapi === 'cli' && !isset($server['REMOTE_ADDR'])) {
        return true;
    }
    $ip = (string)($server['REMOTE_ADDR'] ?? '');
    if (!identity_ip_allowed($ip, ['127.0.0.0/8', '::1/128'])) {
        return false;
    }
    $markers = IDENTITY_PROXY_MARKERS;
    foreach (['AUTH_USER_HEADER', 'AUTH_EMAIL_HEADER', 'AUTH_NAME_HEADER', 'AUTH_GROUPS_HEADER'] as $k) {
        if (trim($settings[$k]) !== '') {
            $markers[] = identity_server_key($settings[$k]);
        }
    }
    foreach ($markers as $m) {
        if (isset($server[$m]) && trim((string)$server[$m]) !== '') {
            return false;
        }
    }
    return true;
}

/**
 * Resolve the request to an identity. Never throws; a refusal comes back
 * as 'status' (401 or 403) with a message fit for the person.
 *
 * Returns: mode, status (200, 401, 403), message, netid, email, name, groups.
 */
function identity_resolve(array $server, array $settings, string $sapi = PHP_SAPI): array
{
    $out = ['mode' => $settings['AUTH_MODE'], 'status' => 200, 'message' => '',
            'netid' => null, 'email' => null, 'name' => null, 'groups' => []];
    $refuse = static function (int $status, string $message) use ($out): array {
        $out['status'] = $status;
        $out['message'] = $message;
        return $out;
    };

    if ($settings['AUTH_MODE'] === 'local') {
        if (!identity_local_allowed($server, $settings, $sapi)) {
            return $refuse(403, 'The local sign-in works on this computer only. On the server, '
                . 'set AUTH_MODE=proxy so the university sign-in supplies your NetID.');
        }
        return $out;                       // the session names the acting user (auth.php)
    }
    if ($settings['AUTH_MODE'] !== 'proxy') {
        return $refuse(403, 'AUTH_MODE must be proxy or local.');
    }

    $trusted = identity_list($settings['AUTH_TRUSTED_PROXIES']);
    if ($trusted !== [] && !identity_ip_allowed((string)($server['REMOTE_ADDR'] ?? ''), $trusted)) {
        return $refuse(403, 'This request did not come through the university sign-in.');
    }
    $domains = identity_list($settings['AUTH_NETID_DOMAINS']);
    $raw = identity_header($server, $settings['AUTH_USER_HEADER']);
    if ($raw === '') {
        return $refuse(401, 'Not signed in. Reach this site through the university sign-in.');
    }
    $netid = identity_netid_from($raw, $domains);
    if ($netid === null) {
        return $refuse(403, 'Signed in as ' . $raw . ', which is not a university NetID.');
    }
    $email = identity_header($server, $settings['AUTH_EMAIL_HEADER']);
    $email = filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? strtolower($email) : null;
    $name = identity_header($server, $settings['AUTH_NAME_HEADER']);
    $sep = $settings['AUTH_GROUPS_SEPARATOR'] === '' ? ',' : $settings['AUTH_GROUPS_SEPARATOR'];
    $groups = array_slice(array_values(array_unique(
        identity_list(identity_header($server, $settings['AUTH_GROUPS_HEADER']), $sep))), 0, 500);

    $allowed = identity_list($settings['AUTH_ALLOWED_GROUPS']);
    if ($allowed !== []) {
        $mine = array_map('strtolower', $groups);
        if (array_intersect(array_map('strtolower', $allowed), $mine) === []) {
            return $refuse(403, 'Signed in as ' . $netid . ', who is not in a group allowed to use this site.');
        }
    }
    $out['netid'] = $netid;
    $out['email'] = $email ?? ($domains !== [] ? $netid . '@' . strtolower($domains[0]) : null);
    $out['name'] = $name !== '' ? mb_substr($name, 0, 160) : null;
    $out['groups'] = $groups;
    return $out;
}

/**
 * The roles a person holds: the one on their user row plus any a group
 * grants. Order of strength for the primary role: admin, then the stored
 * role, then approver, then coordinator, then staff.
 */
function identity_primary_role(string $stored, array $groupRoles): string
{
    $all = array_unique(array_merge([$stored], $groupRoles));
    if (in_array('admin', $all, true)) {
        return 'admin';
    }
    if ($stored !== 'staff' && $stored !== '') {
        return $stored;
    }
    foreach (['approver', 'coordinator'] as $r) {
        if (in_array($r, $all, true)) {
            return $r;
        }
    }
    return 'staff';
}
