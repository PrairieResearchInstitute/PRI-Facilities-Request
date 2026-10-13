<?php
declare(strict_types=1);

/**
 * The server tier (NCSA Radiant): every setting from the environment.
 *
 * The image never carries config.php, config.local.json or pg.env (the
 * .dockerignore keeps them out), so on a server the tier's environment file
 * (compose.yaml env_file, keys in env.template.txt) supplies what those
 * three files supply on this computer. TIER=test or TIER=live marks a
 * server; without it nothing here changes how the app runs on this computer.
 *
 *   config.php        -> APP_ENVIRONMENT, APP_TIMEZONE, APP_KEY, SMTP_*
 *   config.local.json -> DATA_MODE (postgres, the only engine on a server)
 *   pg.env            -> PGHOST, PGPORT, PGDATABASE, PGUSER, PGPASSWORD
 *
 * An error names the missing key, never a value.
 */

/** 'test' or 'live' on a server tier; null on this computer. */
function server_tier(): ?string
{
    $t = strtolower(trim((string)(getenv('TIER') ?: '')));
    return in_array($t, ['test', 'live'], true) ? $t : null;
}

/** One environment value, trimmed; $default when unset or blank. */
function server_env(string $key, string $default = ''): string
{
    $v = getenv($key);
    return ($v === false || trim($v) === '') ? $default : trim($v);
}

/**
 * The array config.php would hold, built from the environment. db carries
 * only the engine: the SQL Server descriptors have no meaning on a server.
 */
function server_config(): array
{
    $bool = static fn(string $k, bool $d) => in_array(strtolower(server_env($k, $d ? '1' : '0')), ['1', 'true', 'yes', 'on'], true);
    return [
        'environment' => server_env('APP_ENVIRONMENT', 'production'),
        'timezone'    => server_env('APP_TIMEZONE', 'America/Chicago'),
        'app_key'     => server_env('APP_KEY'),
        'db'          => ['engine' => 'postgres'],
        'smtp'        => [
            'host'        => server_env('SMTP_HOST'),
            'port'        => (int)server_env('SMTP_PORT', '587'),
            'security'    => server_env('SMTP_SECURITY', 'starttls'),
            'username'    => server_env('SMTP_USERNAME'),
            'password'    => server_env('SMTP_PASSWORD'),
            'from'        => server_env('SMTP_FROM'),
            'from_name'   => server_env('SMTP_FROM_NAME', 'PRI Facilities Request System'),
            'timeout'     => (int)server_env('SMTP_TIMEOUT', '15'),
            'verify_peer' => $bool('SMTP_VERIFY_PEER', true),
        ],
        'source'      => 'environment',
    ];
}

/** The PostgreSQL settings pg.env would hold, from the environment. */
function server_pg_env(array $required): array
{
    $out = [];
    foreach (array_merge($required, ['PGTESTDATABASE']) as $k) {
        $v = server_env($k);
        if ($v === '' && in_array($k, $required, true)) {
            throw new RuntimeException('The server environment file has no ' . $k . ' line (env.template.txt).');
        }
        if ($v !== '') {
            $out[$k] = $v;
        }
    }
    return $out;
}
