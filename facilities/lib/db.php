<?php
declare(strict_types=1);

/**
 * Named PDO_SQLSRV connections. Configuration lives in config.php one
 * level above the document root, written by the installer and never hand
 * edited (spec rule 4).
 *
 * Six databases, because purchasing, keys and the fleet are live
 * programmes that are audited on their own terms - a purchasing log that
 * can only be produced by extracting a schema out of a general database
 * is not a purchasing log.
 *
 * They are still cross-referenced, and always on natural keys: RoomCode,
 * BldgNumber, NetID, FleetNumber, PropertyTag, TicketNumber, CfopCode.
 * No foreign key crosses a database boundary. Reference data - buildings,
 * rooms, people, surveys, the settings, the lists the forms offer - is
 * published into every one of them, so each can answer its own questions
 * without reaching across.
 */

// The friendly failure page and the global handler that shows it in
// place of an uncaught PDOException. Required before anything queries,
// so the very first db() call in a request is already covered.
require_once __DIR__ . '/fail.php';
// storage_dir(): uploads, imports and extracts follow config.local.json's dataFolder.
require_once __DIR__ . '/paths.php';
// server_tier(): on Radiant (TIER test or live) every setting comes from the environment.
require_once __DIR__ . '/serverenv.php';
// pg_env(): the PostgreSQL credentials in pg.env, never printed.
require_once __DIR__ . '/pgenv.php';
db_install_failure_handler();

const APP_DB_NAMES = ['work', 'move', 'shop', 'purchasing', 'keys', 'fleet',
                      'space', 'equipment', 'incidents', 'plans'];

const APP_DB_LABELS = [
    'work'       => 'PRI_FacilitiesWork',
    'move'       => 'PRI_FacilitiesMove',
    'shop'       => 'PRI_FacilitiesShop',
    'purchasing' => 'PRI_FacilitiesPurchasing',
    'keys'       => 'PRI_FacilitiesKeys',
    'fleet'      => 'PRI_FacilitiesFleet',
    'space'      => 'PRI_FacilitiesSpace',
    'equipment'  => 'PRI_FacilitiesEquipment',
    'incidents'  => 'PRI_FacilitiesIncidents',
    'plans'      => 'PRI_FacilitiesPlans',
];

/** What each one is for, in the words of the work. */
const APP_DB_PURPOSE = [
    'work'       => 'Work requests, space, property and the reference data everything shares',
    'move'       => 'Move requests and their items',
    'shop'       => 'Shop and vehicle work requests',
    'purchasing' => 'Purchase requests, their funding, and the Banner account codes',
    'keys'       => 'Key requests, who holds which key, and every key event',
    'fleet'      => 'Vehicles, odometer readings, bookings and driver paperwork',
    'space'      => 'The estate: buildings, floors, rooms, occupants, who is responsible '
                  . 'for each room, and the requests that change all of that',
    'equipment'  => 'The inventory: every tagged item, its custody, its history, its disposal',
    'incidents'  => 'Injuries, near misses, spills and exposures, what was done and what '
                  . 'came of it',
    'plans'      => 'Safety plans and procedures, who owns each one and when it is next '
                  . 'due to be read again',
];

function app_root(): string
{
    return dirname(__DIR__, 2);
}

function config_path(): string
{
    // config.local.json may name another config.php, relative to the app
    // folder: the PostgreSQL tests run with their own scratch copy.
    $named = trim((string)(local_config()['configFile'] ?? ''));
    if ($named !== '' && !paths_is_absolute($named)) {
        return app_root() . DIRECTORY_SEPARATOR . str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $named);
    }
    return app_root() . DIRECTORY_SEPARATOR . 'config.php';
}

function app_installed(): bool
{
    // A server tier is installed by its environment file; it has no config.php.
    return server_tier() !== null || is_file(config_path());
}

function app_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        // A server tier reads the environment only, even if a config.php
        // somehow reached the image (.dockerignore keeps it out).
        if (server_tier() !== null) {
            $cfg = server_config();
            app_set_timezone((string)$cfg['timezone']);
            return $cfg;
        }
        if (!app_installed()) {
            throw new RuntimeException('Application is not installed. Run install.php.');
        }
        $cfg = require config_path();
        if (!is_array($cfg) || !isset($cfg['db'])) {
            throw new RuntimeException('config.php is unreadable. Delete it and rerun install.php.');
        }
        app_set_timezone((string)($cfg['timezone'] ?? APP_DEFAULT_TIMEZONE));
    }
    return $cfg;
}

/**
 * PHP and SQL Server have to agree about what day it is.
 *
 * They did not. PHP had no timezone set, so it fell back to UTC while
 * SQL Server stamped SYSDATETIME() in local time - five hours apart. A
 * review recorded today came back due a day late; a report filed at nine
 * in the evening was rejected as being in the future. Nothing announced
 * this, because each half of every pair was internally consistent.
 *
 * So one timezone is set once, here, before anything reads a clock.
 */
const APP_DEFAULT_TIMEZONE = 'America/Chicago';

function app_set_timezone(string $tz): void
{
    if (@date_default_timezone_set($tz) === false) {
        date_default_timezone_set(APP_DEFAULT_TIMEZONE);
    }
}

function app_environment(): string
{
    return app_config()['environment'] ?? 'production';
}

/**
 * Build a DSN and open a connection from a connection descriptor:
 * host, port, database, auth ('sql'|'windows'), username, password, trust_cert.
 * Used directly by the installer before config.php exists.
 */
function db_connect(array $c): PDO
{
    $server = $c['host'];
    if (!empty($c['port']) && (int)$c['port'] !== 0) {
        $server .= ',' . (int)$c['port'];
    }
    $dsn = 'sqlsrv:Server=' . $server;
    if (!empty($c['database'])) {
        $dsn .= ';Database=' . $c['database'];
    }
    if (!empty($c['trust_cert'])) {
        $dsn .= ';TrustServerCertificate=1';
    }
    // A database whose file lives in the application folder. LocalDB
    // attaches it on demand from this path, which is what lets the data
    // travel with the folder instead of living under the user profile. A
    // relative path is resolved against the application root, so the
    // folder can be opened from another machine or drive letter.
    if (!empty($c['attach'])) {
        $file = (string)$c['attach'];
        if (!preg_match('/^[A-Za-z]:/', $file)) {
            $file = app_root() . DIRECTORY_SEPARATOR
                  . str_replace('/', DIRECTORY_SEPARATOR, $file);
        }
        if (is_file($file)) {
            $dsn .= ';AttachDBFileName=' . $file;
        }
    }
    // How long to wait for the server to answer at all. On a local
    // instance this never matters; pointed at another machine it is the
    // difference between a page that says the server is unreachable and
    // a page that hangs for half a minute first.
    // A cold LocalDB start brings up ten databases before it answers, which
    // regularly takes longer than five seconds. Five was set here on 3 Sep
    // 2026 and it turned a slow start into a hard failure. Local instances
    // now get room to wake; a named server, which either answers or is not
    // there, still fails fast.
    $wait = (int)($c['connect_timeout'] ?? 0);
    if ($wait <= 0) {
        $wait = db_is_local((string)($c['host'] ?? '')) ? 45 : 8;
    }
    $dsn .= ';LoginTimeout=' . max(1, $wait);
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    try {
        return db_open($dsn, $c, $options);
    } catch (PDOException $e) {
        // A local instance that has idled out can be woken and tried once
        // more. Nothing is installed and nothing is killed here - a web
        // request is no place to be stopping a database engine.
        if (db_is_local((string)($c['host'] ?? '')) && db_wake()) {
            try {
                return db_open($dsn, $c, $options);
            } catch (PDOException $again) {
                $e = $again;
            }
        }
        // Re-thrown without the DSN. PDO puts the whole connection
        // string in its message, and an unreachable server is exactly
        // when that message ends up on a screen somebody can see - so
        // it must not carry the server, the account or the password.
        throw new DbUnreachable(db_reason($e), (int)$e->getCode(), $e);
    }
}

/** Is this a local instance this machine can start for itself? */
function db_is_local(string $host): bool
{
    return stripos($host, 'localdb') !== false;
}

/** Open the connection, Windows or SQL authentication. */
function db_open(string $dsn, array $c, array $options): PDO
{
    if (($c['auth'] ?? 'sql') === 'windows') {
        return new PDO($dsn, null, null, $options);
    }
    return new PDO($dsn, $c['username'] ?? '', $c['password'] ?? '', $options);
}

/**
 * Ask LocalDB to start, once per request, and say whether it is up.
 *
 * It stops itself after idling, and the first page opened afterwards used
 * to meet a stopped instance and fail. Starting it is a normal, reversible
 * thing for the owning account to do. An engine that LocalDB has lost
 * track of is NOT dealt with here - that is the launcher's job, where it
 * can be aimed at the one process rather than at every SQL Server running.
 */
function db_wake(): bool
{
    static $tried = false;
    if ($tried || !function_exists('shell_exec') || stripos(PHP_OS, 'WIN') !== 0) {
        return false;
    }
    $tried = true;
    if (stripos((string)@shell_exec('sqllocaldb info MSSQLLocalDB 2>&1'), 'Running') !== false) {
        return false;               // already up, so the fault is elsewhere
    }
    @shell_exec('sqllocaldb start MSSQLLocalDB 2>&1');
    return stripos((string)@shell_exec('sqllocaldb info MSSQLLocalDB 2>&1'), 'Running') !== false;
}

/** A connection that could not be opened, said without the credentials. */
class DbUnreachable extends RuntimeException
{
}

/**
 * The human-readable half of a driver error. The driver returns several
 * bracketed layers - ODBC, the network library, then the server - and
 * only the last of them means anything to a person.
 */
function db_reason(Throwable $e): string
{
    $msg = $e->getMessage();
    // The driver stacks its layers on the front - the SQLSTATE, then
    // ODBC, then the network library, then the server. Strip the
    // prefixes rather than hunt for the tail, because some messages end
    // in a bracketed error code and the tail is then just a full stop.
    $msg = preg_replace('/^SQLSTATE\[[^\]]*\]:\s*/', '', $msg);
    $msg = preg_replace('/^(\[[^\]]*\])+/', '', (string)$msg);
    $msg = trim((string)$msg);
    return $msg === '' ? 'The database did not answer.' : $msg;
}

/**
 * Which engine holds the registers: 'localdb' (SQL Server LocalDB, the
 * default) or 'postgres', from dataMode in config.local.json. Read once,
 * so a request can never talk to both (drez-sqlite-to-postgres-port rule
 * 4). Anything but "postgres" means LocalDB: an installation that has not
 * been switched behaves exactly as before.
 */
function db_engine(): string
{
    static $engine = null;
    if ($engine === null) {
        // A server tier names its engine in DATA_MODE (postgres when unset);
        // this computer names it in config.local.json.
        $mode = server_tier() !== null
            ? strtolower(server_env('DATA_MODE', 'postgres'))
            : strtolower(trim((string)(local_config()['dataMode'] ?? '')));
        $engine = $mode === 'postgres' ? 'postgres' : 'localdb';
    }
    return $engine;
}

/**
 * Stop a command-line tool that speaks SQL Server only, when the registers
 * are on PostgreSQL. The split, verify, replay, drop and recovery tools
 * restructured the LocalDB databases once (catalogue views, SELECT INTO,
 * IDENTITY_INSERT, FOR XML PATH); PostgreSQL got the finished shape from
 * migrations/pgsql instead, so none of them has a PostgreSQL meaning.
 * tests/pg-sql-scan.php skips a tool that calls this.
 */
function db_localdb_only(string $tool): void
{
    if (db_engine() !== 'localdb') {
        fwrite(STDERR, $tool . ' works on the SQL Server LocalDB registers only. dataMode is '
            . db_engine() . ', where migrations/pgsql already holds the shape this tool built.' . PHP_EOL);
        exit(2);
    }
}

/**
 * The PostgreSQL database this installation uses: PGDATABASE from pg.env,
 * or PGTESTDATABASE when config.local.json says "pgTarget": "test". The
 * test name must end in _test, so a test configuration can never reach
 * the real database (drez-sqlite-to-postgres-port rule 5).
 */
function db_pg_database(): string
{
    $env = pg_env();
    if (strtolower((string)(local_config()['pgTarget'] ?? '')) === 'test') {
        $db = (string)($env['PGTESTDATABASE'] ?? '');
        if (!preg_match('/_test$/', $db)) {
            throw new RuntimeException('pgTarget is test but PGTESTDATABASE in pg.env does not end in _test.');
        }
        return $db;
    }
    return (string)$env['PGDATABASE'];
}

/**
 * One PostgreSQL connection for a register: same database, the register's
 * own schema. Opened with the application timezone, so SYSDATETIME() in a
 * query and a default stamp the same local time SQL Server did.
 */
function db_pg_open(string $register, ?string $database = null): FacilitiesPgPdo
{
    // The bundled php.ini loads pdo_sqlsrv only: the launchers pass
    // -d extension=pdo_pgsql, and so must a scheduled task (send_digests.php).
    if (!extension_loaded('pdo_pgsql')) {
        throw new DbUnreachable('dataMode is postgres but PHP was started without pdo_pgsql. '
            . 'Start it with -d extension=pdo_pgsql, as the launchers do.');
    }
    require_once __DIR__ . '/pgsql.php';
    $env = pg_env();
    $dsn = 'pgsql:host=' . $env['PGHOST'] . ';port=' . (int)$env['PGPORT']
         . ';dbname=' . ($database ?? db_pg_database()) . ';connect_timeout=45';
    $tz = date_default_timezone_get();
    try {
        return new FacilitiesPgPdo($dsn, $env['PGUSER'], $env['PGPASSWORD'], $register, $tz);
    } catch (PDOException $e) {
        // As for SQL Server: the message goes on a screen, so it carries
        // neither the server nor the account nor the password.
        throw new DbUnreachable('PostgreSQL did not accept the connection (' . ($e->errorInfo[0] ?? $e->getCode()) . ').',
                                0, null);
    }
}

/**
 * Named connection: db('work'), db('purchasing'), db('fleet') and so on.
 * Cached per request. On PostgreSQL each name is a schema of one database
 * and the connection translates the SQL Server queries (pgsql.php).
 *
 * A database added after an installation has no entry in config.php, so
 * its descriptor is derived from the Work one - same server, same
 * credentials, different database. That way an existing installation
 * keeps working without anybody editing a file they were told never to
 * edit, and the installer fills the entries in properly next time.
 */
function db(string $name): PDO
{
    static $pool = [];
    if (!isset($pool[$name])) {
        if (!in_array($name, APP_DB_NAMES, true)) {
            throw new InvalidArgumentException('Unknown database name: ' . $name);
        }
        if (db_engine() === 'postgres') {
            app_config();                          // sets the timezone the connection uses
            return $pool[$name] = db_pg_open($name);
        }
        $config = app_config()['db'];
        $descriptor = $config[$name] ?? null;
        if ($descriptor === null) {
            $descriptor = $config['work'];
            $descriptor['database'] = APP_DB_LABELS[$name];
        }
        $pool[$name] = db_connect($descriptor);
    }
    return $pool[$name];
}

/**
 * One-row-per-database connectivity probe for status displays.
 */
function db_probe(): array
{
    $out = [];
    foreach (APP_DB_NAMES as $name) {
        try {
            db($name)->query('SELECT 1')->fetch();
            $out[$name] = ['ok' => true, 'error' => null];
        } catch (Throwable $e) {
            $out[$name] = ['ok' => false, 'error' => $e->getMessage()];
        }
    }
    return $out;
}

/**
 * Which database holds a table.
 *
 * Purchasing, keys and the fleet moved out of the general databases into
 * their own. The schema and table names did not change, so every query
 * kept its SQL and changed only the connection it runs on - and this is
 * the one place that says which connection that is. Moving a table again
 * is an edit here rather than a hunt through twenty six files.
 */
const APP_TABLE_HOME = [
    'rq.PurchaseRequests'    => 'purchasing',
    'rq.PurchaseItems'       => 'purchasing',
    'rq.PurchaseFunding'     => 'purchasing',
    'rq.PurchaseEvents'      => 'purchasing',
    'ref.BannerAccountCodes' => 'purchasing',

    'rq.KeyRequests'         => 'keys',
    'gis.RoomKeys'           => 'keys',
    'gis.KeyEvents'          => 'keys',

    'ref.FleetVehicles'      => 'fleet',
    'ref.DriverFormTypes'    => 'fleet',
    'sh.DriverForms'         => 'fleet',
    'sh.VehicleBookings'     => 'fleet',
    'sh.VehicleReadings'     => 'fleet',

    /* The geodatabase. This is a database, not a lookup list: buildings,
       floors, rooms, their outlines, who sits in them and which unit
       answers for them. It is also now the master for the building and
       room lists every other database offers on a form. */
    'gis.Rooms'               => 'space',
    'gis.Floors'              => 'space',
    'gis.BuildingFootprints'  => 'space',
    'gis.BuildingInfo'        => 'space',
    'gis.CategoryColors'      => 'space',
    'gis.Occupants'           => 'space',
    'gis.RoomResponsibility'  => 'space',
    'gis.RoomAssignmentEvents' => 'space',
    'gis.Safety'              => 'space',
    'ref.BuildingMaps'        => 'space',
    'ref.BuildingOccupants'   => 'space',
    'rq.SpaceRequests'        => 'space',

    /* The inventory, whole. One database of equipment. */
    'gis.Inventory'           => 'equipment',
    'gis.InventoryEvents'     => 'equipment',
    'rq.PropertyUpdates'      => 'equipment',

    /* An incident can name a person and describe an injury, so it is the
       one thing here that is not open to every back end account. */
    'rq.Incidents'            => 'incidents',
    'rq.IncidentPeople'       => 'incidents',
    'rq.IncidentActions'      => 'incidents',
    'rq.IncidentEvents'       => 'incidents',

    'rq.SafetyPlans'          => 'plans',
    'rq.PlanRooms'            => 'plans',
    'rq.PlanReviews'          => 'plans',
    'ref.PlanTypes'           => 'plans',
];

/** The connection that holds this table. */
function db_for(string $table): PDO
{
    return db(APP_TABLE_HOME[$table] ?? 'work');
}

/** The name of the database that holds it, for a message or a heading. */
function db_home(string $table): string
{
    return APP_TABLE_HOME[$table] ?? 'work';
}

// db_has_table(), db_tables(), db_columns(), db_total_mb(): the catalogue on either engine.
require_once __DIR__ . '/dbcatalog.php';
