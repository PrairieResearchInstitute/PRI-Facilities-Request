<?php
declare(strict_types=1);

/**
 * The server tier without a server: with TIER=test and made-up values in
 * the environment, the app must take every setting from the environment
 * and none from config.php, config.local.json or pg.env. Opens no database
 * and prints no value it reads. Run by tools/check.js.
 *
 *   tools\php\php.exe tests\server-env.php
 *
 * Ends with "All <n> server-tier checks passed." or exits 1.
 */

$fake = [
    'TIER' => 'test', 'APP_ENVIRONMENT' => 'staging-check', 'APP_TIMEZONE' => 'America/Denver',
    'APP_KEY' => 'k-check', 'PGHOST' => 'db.example.invalid', 'PGPORT' => '6543',
    'PGDATABASE' => 'facilities_test', 'PGUSER' => 'facilities_app', 'PGPASSWORD' => 'p-check',
    'SMTP_HOST' => 'smtp.example.invalid', 'SMTP_FROM' => 'ops@example.invalid', 'SMTP_VERIFY_PEER' => 'false',
    'DATA_MODE' => '',
    // A settings file that does not exist: the server tier must not need one.
    'PRIOPS_LOCAL_CONFIG' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'priops-no-such-settings.json',
];
foreach ($fake as $k => $v) {
    putenv($k . '=' . $v);
}
require_once __DIR__ . '/../facilities/lib/db.php';
require_once __DIR__ . '/../facilities/lib/smtp.php';
require_once __DIR__ . '/../facilities/lib/identity.php';

$fails = 0;
$count = 0;
function check(string $label, bool $ok): void
{
    global $fails, $count;
    $count++;
    echo ($ok ? 'ok    ' : 'FAIL  ') . $label . "\n";
    if (!$ok) {
        $fails++;
    }
}

check('TIER test marks a server tier', server_tier() === 'test');
check('a server tier counts as installed without config.php', app_installed());
$cfg = app_config();
check('app_config comes from the environment, not config.php', ($cfg['source'] ?? '') === 'environment'
    && $cfg['environment'] === 'staging-check' && $cfg['app_key'] === 'k-check');
check('APP_TIMEZONE sets the clock', date_default_timezone_get() === 'America/Denver');
check('SMTP_* fill the mail settings, with the app defaults for the rest',
    smtp_config()['host'] === 'smtp.example.invalid' && smtp_config()['port'] === 587
    && smtp_config()['security'] === 'starttls' && smtp_config()['verify_peer'] === false);
check('the Studio cannot write mail settings into a config.php on the server',
    str_contains(smtp_save_config(['host' => 'x']), 'nothing was saved'));
check('the engine is PostgreSQL when DATA_MODE is blank', db_engine() === 'postgres');
$env = pg_env();
check('pg_env reads PGHOST, PGPORT, PGDATABASE, PGUSER, PGPASSWORD from the environment',
    $env['PGHOST'] === 'db.example.invalid' && $env['PGPORT'] === '6543' && $env['PGUSER'] === 'facilities_app');
check('the database is PGDATABASE (facilities_test or facilities_live per tier)', db_pg_database() === 'facilities_test');
putenv('PGPASSWORD=');
$msg = '';
try {
    server_pg_env(PG_ENV_KEYS);
} catch (RuntimeException $e) {
    $msg = $e->getMessage();
}
check('a missing PG line is named by key, and no value appears in the message',
    str_contains($msg, 'PGPASSWORD') && !str_contains($msg, 'db.example.invalid') && !str_contains($msg, 'facilities_app'));
check('the local login refuses on a server tier', identity_resolve([], identity_settings(), 'cli')['status'] === 403);
putenv('TIER=');
check('without TIER the app is back on this computer', server_tier() === null);

echo $fails === 0 ? "All $count server-tier checks passed.\n" : "$fails of $count server-tier checks FAILED.\n";
exit($fails === 0 ? 0 : 1);
