<?php
declare(strict_types=1);

/**
 * NetID sign-in without a server or a database: facilities/lib/identity.php
 * against made-up requests. Run by tools/check.js.
 *
 *   tools\php\php.exe tests\identity.php
 *
 * Ends with "All <n> identity checks passed." or exits 1.
 */

require_once __DIR__ . '/../facilities/lib/identity.php';

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
function settings(array $over = []): array
{
    return identity_settings(static fn(string $k) => $over[$k] ?? false);
}

// Settings come from the environment, with defaults.
$d = settings();
check('AUTH_MODE defaults to local', $d['AUTH_MODE'] === 'local');
check('the identity header defaults to X-Forwarded-Email, as District Tracker reads it', $d['AUTH_USER_HEADER'] === 'X-Forwarded-Email');
check('a setting from the environment wins, and AUTH_MODE is read without case',
    settings(['AUTH_MODE' => 'PROXY', 'AUTH_USER_HEADER' => 'X-Remote-User'])['AUTH_MODE'] === 'proxy'
    && settings(['AUTH_USER_HEADER' => 'X-Remote-User'])['AUTH_USER_HEADER'] === 'X-Remote-User');
check('a blank environment value falls back to the default', settings(['AUTH_NETID_DOMAINS' => '  '])['AUTH_NETID_DOMAINS'] === 'illinois.edu');

// NetIDs.
check('an illinois.edu address gives the NetID', identity_netid_from('configure-me@example.edu', ['illinois.edu']) === 'jdoe');
check('a bare NetID stands', identity_netid_from('jdoe2', ['illinois.edu']) === 'jdoe2');
check('an address at another domain is refused', identity_netid_from('jdoe@gmail.com', ['illinois.edu']) === null);
check('a value with a space or a symbol is refused', identity_netid_from('j doe', ['illinois.edu']) === null
    && identity_netid_from("jdoe'--", ['illinois.edu']) === null);

// Addresses.
check('loopback IPv4 matches 127.0.0.0/8', identity_ip_allowed('127.0.0.1', ['127.0.0.0/8']));
check('loopback IPv6 matches ::1/128', identity_ip_allowed('::1', ['::1/128']));
check('a CIDR range matches inside and refuses outside', identity_ip_allowed('10.20.30.40', ['10.20.0.0/16'])
    && !identity_ip_allowed('10.21.0.1', ['10.20.0.0/16']));
check('a bare address matches only itself', identity_ip_allowed('192.0.2.7', ['192.0.2.7'])
    && !identity_ip_allowed('192.0.2.8', ['192.0.2.7']));
check('an odd prefix length works (/20)', identity_ip_allowed('172.16.15.1', ['172.16.0.0/20'])
    && !identity_ip_allowed('172.16.16.1', ['172.16.0.0/20']));
check('rubbish is never allowed', !identity_ip_allowed('', ['0.0.0.0/0']) && !identity_ip_allowed('x', ['0.0.0.0/0']));

// Local mode: this computer only.
$local = settings();
$r = identity_resolve(['REMOTE_ADDR' => '127.0.0.1'], $local, 'cli-server');
check('local sign-in answers a request from this computer', $r['status'] === 200 && $r['mode'] === 'local');
$r = identity_resolve([], $local, 'cli');
check('local sign-in answers the command line', $r['status'] === 200);
$r = identity_resolve(['REMOTE_ADDR' => '10.0.0.5'], $local, 'apache2handler');
check('local sign-in refuses another computer', $r['status'] === 403);
$r = identity_resolve(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '10.0.0.5'], $local, 'cli-server');
check('local sign-in refuses a request a proxy forwarded, even on loopback', $r['status'] === 403);
$r = identity_resolve(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_EMAIL' => 'configure-me@example.edu'], $local, 'cli-server');
check('local sign-in refuses a request carrying the identity header', $r['status'] === 403);
$r = identity_resolve(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_CUSTOM_USER' => 'jdoe'],
    settings(['AUTH_USER_HEADER' => 'X-Custom-User']), 'cli-server');
check('local sign-in refuses a request carrying a configured identity header', $r['status'] === 403);
$r = identity_resolve(['REMOTE_ADDR' => '127.0.0.1'], settings(['TIER' => 'live']), 'cli-server');
check('local sign-in refuses on a server tier (TIER live)', $r['status'] === 403);
$r = identity_resolve([], settings(['TIER' => 'test']), 'cli');
check('local sign-in refuses on a server tier (TIER test), even on the command line', $r['status'] === 403);
$r = identity_resolve(['REMOTE_ADDR' => '127.0.0.1'], settings(['AUTH_MODE' => 'ldap']), 'cli-server');
check('an unknown AUTH_MODE is refused', $r['status'] === 403);

// Proxy mode: the trusted header.
$proxy = settings(['AUTH_MODE' => 'proxy', 'AUTH_NAME_HEADER' => 'X-Forwarded-Preferred-Username']);
$req = ['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_EMAIL' => 'configure-me@example.edu',
        'HTTP_X_FORWARDED_GROUPS' => 'pri-facilities-admins, inhs-staff ,,inhs-staff',
        'HTTP_X_FORWARDED_PREFERRED_USERNAME' => 'Jane Doe'];
$r = identity_resolve($req, $proxy, 'apache2handler');
check('proxy sign-in reads the NetID from the header', $r['status'] === 200 && $r['netid'] === 'jdoe', json_encode($r));
check('proxy sign-in keeps the address, lower case', $r['email'] === 'configure-me@example.edu');
check('proxy sign-in reads the name header when one is configured', $r['name'] === 'Jane Doe');
check('groups split on the separator, trimmed, empty and repeated ones dropped',
    $r['groups'] === ['pri-facilities-admins', 'inhs-staff'], json_encode($r['groups']));
$r = identity_resolve(['REMOTE_ADDR' => '10.1.2.3'], $proxy, 'apache2handler');
check('proxy sign-in without the header answers 401', $r['status'] === 401 && $r['netid'] === null);
$r = identity_resolve(['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_EMAIL' => 'someone@example.org'], $proxy, 'apache2handler');
check('proxy sign-in refuses an address outside the NetID domains', $r['status'] === 403 && $r['netid'] === null);
$r = identity_resolve(['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_EMAIL' => 'jdoe'], $proxy, 'apache2handler');
check('a bare NetID header gets the first domain as its address', $r['email'] === 'configure-me@example.edu');

$trusted = settings(['AUTH_MODE' => 'proxy', 'AUTH_TRUSTED_PROXIES' => '10.1.0.0/16, 192.0.2.10']);
check('a trusted proxy address is accepted',
    identity_resolve($req, $trusted, 'apache2handler')['status'] === 200);
check('the header from any other address is refused before it is read',
    identity_resolve(['REMOTE_ADDR' => '203.0.113.9'] + $req, $trusted, 'apache2handler')['status'] === 403
    && identity_resolve(['REMOTE_ADDR' => '203.0.113.9'] + $req, $trusted, 'apache2handler')['netid'] === null);

$semi = settings(['AUTH_MODE' => 'proxy', 'AUTH_GROUPS_HEADER' => 'X-IsMemberOf', 'AUTH_GROUPS_SEPARATOR' => ';']);
$r = identity_resolve(['HTTP_X_FORWARDED_EMAIL' => 'configure-me@example.edu',
    'HTTP_X_ISMEMBEROF' => 'urn:mace:uiuc.edu:urbana:pri:a,b;urn:mace:uiuc.edu:urbana:pri:c'], $semi, 'apache2handler');
check('a configured groups header and separator keep commas inside group names',
    $r['groups'] === ['urn:mace:uiuc.edu:urbana:pri:a,b', 'urn:mace:uiuc.edu:urbana:pri:c'], json_encode($r['groups']));

$gate = settings(['AUTH_MODE' => 'proxy', 'AUTH_ALLOWED_GROUPS' => 'PRI-All-Staff']);
check('AUTH_ALLOWED_GROUPS admits a member, without case',
    identity_resolve(['HTTP_X_FORWARDED_EMAIL' => 'configure-me@example.edu', 'HTTP_X_FORWARDED_GROUPS' => 'pri-all-staff'], $gate, 'apache2handler')['status'] === 200);
check('AUTH_ALLOWED_GROUPS refuses a NetID in none of the groups',
    identity_resolve(['HTTP_X_FORWARDED_EMAIL' => 'configure-me@example.edu', 'HTTP_X_FORWARDED_GROUPS' => 'other'], $gate, 'apache2handler')['status'] === 403);

// Roles.
check('a group admin role makes the primary role admin', identity_primary_role('staff', ['coordinator', 'admin']) === 'admin');
check('a stored role above staff stays primary', identity_primary_role('coordinator', ['approver']) === 'coordinator');
check('a staff member lent approver by a group is an approver', identity_primary_role('staff', ['approver']) === 'approver');
check('no grants leave the stored role', identity_primary_role('staff', []) === 'staff');

echo $fails === 0 ? "All $count identity checks passed.\n" : "$fails of $count identity checks FAILED.\n";
exit($fails === 0 ? 0 : 1);
