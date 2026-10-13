<?php
declare(strict_types=1);

/**
 * LocalDB stays exactly as it was until the owner switches dataMode.
 *
 *   php tests/localdb-unchanged.php     ends with "All <n> LocalDB checks passed."
 *
 * The port must not change a single statement SQL Server receives. Nothing
 * here opens LocalDB (CLAUDE.md forbids it from a session): the checks run on
 * scratch settings in the system temporary folder and hand the catalogue
 * helpers a recording connection (in-memory SQLite, when pdo_sqlite is
 * loaded) that keeps the SQL it is given, so the text that would reach SQL
 * Server is compared with the text the pages sent before the port.
 */

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'priops-localdb-' . getmypid();
@mkdir($tmp, 0777, true);
file_put_contents($tmp . DIRECTORY_SEPARATOR . 'config.local.json', json_encode(['tier' => 'local', 'dataFolder' => '']));
putenv('PRIOPS_LOCAL_CONFIG=' . $tmp . DIRECTORY_SEPARATOR . 'config.local.json');

require_once __DIR__ . '/../facilities/lib/db.php';

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

check('no dataMode means LocalDB', db_engine() === 'localdb');
check('the PostgreSQL layer is not even loaded on LocalDB', !class_exists('FacilitiesPgPdo', false));
check('APP_DB_NAMES still lists the ten registers in order',
    APP_DB_NAMES === ['work', 'move', 'shop', 'purchasing', 'keys', 'fleet', 'space', 'equipment', 'incidents', 'plans']);

if (extension_loaded('pdo_sqlite')) {
    /** Keeps every statement; answers with empty results. */
    class RecordingPdo extends PDO
    {
        public array $seen = [];

        public function __construct()
        {
            parent::__construct('sqlite::memory:');
            $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        }

        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            $this->seen[] = preg_replace('/\s+/', ' ', trim($query));
            return parent::prepare('SELECT 0 AS n WHERE 0' . str_repeat(' AND ? IS NULL', substr_count($query, '?')));
        }

        public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false
        {
            $this->seen[] = preg_replace('/\s+/', ' ', trim($query));
            return parent::query('SELECT 0 AS n WHERE 0');
        }
    }
    $r = new RecordingPdo();
    db_has_table($r, 'app.Users');
    check('db_has_table sends SQL Server the sys.tables query the pages sent before',
        ($r->seen[0] ?? '') === 'SELECT COUNT(*) FROM sys.tables t JOIN sys.schemas s ON s.schema_id = t.schema_id WHERE s.name = ? AND t.name = ?',
        $r->seen[0] ?? '');
    $r->seen = [];
    db_tables($r);
    check('db_tables sends SQL Server the export catalogue query',
        str_contains($r->seen[0] ?? '', 'FROM sys.tables t JOIN sys.schemas s ON s.schema_id = t.schema_id JOIN sys.partitions p'),
        $r->seen[0] ?? '');
    $r->seen = [];
    db_columns($r, 'app.Users');
    check('db_columns sends SQL Server a sys.columns query in column order',
        str_contains($r->seen[0] ?? '', 'FROM sys.columns c JOIN sys.types ty') && str_contains($r->seen[0] ?? '', 'ORDER BY c.column_id'),
        $r->seen[0] ?? '');
} else {
    echo "skip  pdo_sqlite is not loaded, so the recording connection checks did not run\n";
}

// The translation layer is a no-op unless asked for: a SQL Server statement
// passed to a LocalDB connection is never rewritten, because db() returns a
// plain PDO there. Prove the class map agrees.
check('db() on LocalDB would build a plain PDO (db_connect, not db_pg_open)',
    (new ReflectionFunction('db_connect'))->getReturnType()?->getName() === 'PDO');

// The launchers ask tools/data_mode.php before anything else; without a
// dataMode they must keep every LocalDB step and load no PostgreSQL driver.
$mode = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/tools/data_mode.php')));
check('tools/data_mode.php tells the launchers "localdb" when dataMode is absent', $mode === 'localdb', $mode);

// The SQL Server only tools carry on: on LocalDB the guard returns.
db_localdb_only('tests/localdb-unchanged.php');
check('db_localdb_only() lets a LocalDB tool run', true);

// health.php without ?db=1 still touches nothing: no database library loaded.
$health = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
    'ob_start(); include ' . var_export(dirname(__DIR__) . '/facilities/health.php', true) . '; ob_end_clean();'
    . " echo function_exists('db') ? 'loaded' : 'untouched';"));
check('health.php without ?db=1 loads no database code', trim($health) === 'untouched', $health);

@unlink($tmp . DIRECTORY_SEPARATOR . 'config.local.json');
@rmdir($tmp);
if ($fails) {
    echo "$fails of $count LocalDB checks FAILED.\n";
    exit(1);
}
echo "All $count LocalDB checks passed.\n";
