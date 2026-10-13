<?php
declare(strict_types=1);

/**
 * tools/pg/load.php, proved on the test database. Started by tests/run-pg.php
 * after tests/pg-app.php has left real rows (seed plus example requests).
 *
 * The switch-over source is LocalDB, which no session may open; so the
 * mechanics are proved PostgreSQL to PostgreSQL (register schemas into
 * lt_<register> schemas of facilities_test), and the SQL Server value shapes
 * the loader will meet (seven fractional digits, ".5" decimals, "1" bits)
 * are proved against the server's own input rules.
 *
 * Ends with "All <n> loader checks passed." or exits 1.
 */

if (getenv('PRIOPS_LOCAL_CONFIG') === false) {
    fwrite(STDERR, "Run through tests/run-pg.php, which sets up the scratch settings.\n");
    exit(2);
}
require_once __DIR__ . '/../tools/pg/pgcli.php';
if (!preg_match('/_test$/', db_pg_database())) {
    fwrite(STDERR, "Refusing to run: not on the PostgreSQL test database.\n");
    exit(2);
}

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
function load(array $args): array
{
    $cmd = array_merge([PHP_BINARY, '-d', 'extension=pdo_pgsql', __DIR__ . '/../tools/pg/load.php', '--database', 'test'], $args);
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), getenv());
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($p), $out];
}

$pg = pgcli_connect(db_pg_database());
foreach (APP_DB_NAMES as $r) {
    $pg->exec('DROP SCHEMA IF EXISTS lt_' . $r . ' CASCADE');
}

[$c, $o] = load(['--from-pg', '', '--target-prefix', 'lt_']);
preg_match('/VERIFIED: (\d+) rows across (\d+) tables/', $o, $m);
check('a full load verifies every row', $c === 0 && isset($m[1]) && (int)$m[1] > 1000, trim($o));
$rows = (int)($m[1] ?? 0);

[$c, $o] = load(['--from-pg', '', '--target-prefix', 'lt_']);
check('a second load onto a full target is refused without --replace', $c === 1 && str_contains($o, 'already holds rows'), trim($o));

[$c, $o] = load(['--from-pg', '', '--target-prefix', 'lt_', '--replace', '--sabotage']);
check('one changed value fails verification', $c === 1 && str_contains($o, 'DIFF:') && str_contains($o, 'rolled back'), trim($o));
$owners = (int)$pg->query('SELECT COUNT(*) FROM lt_work.ref_owners WHERE code ILIKE \'%sabotaged%\'')->fetchColumn();
check('the sabotaged load committed nothing', $owners === 0);

[$c, $o] = load(['--from-pg', '', '--target-prefix', 'lt_', '--replace']);
check('--replace reloads and verifies again', $c === 0 && str_contains($o, "VERIFIED: $rows rows"), trim($o));

$max = (int)$pg->query('SELECT MAX(workrequestid) FROM lt_work.wr_workrequests')->fetchColumn();
$seq = (int)$pg->query("SELECT nextval(pg_get_serial_sequence('lt_work.wr_workrequests', 'workrequestid'))")->fetchColumn();
check('identity sequences sit past the highest loaded id', $seq > $max, "max $max, next $seq");

[$c, $o] = load(['--from-pg', '', '--target-prefix', 'lt_', '--dry']);
check('--dry checks shapes and counts and writes nothing', $c === 0 && str_contains($o, 'DRY RUN'), trim($o));

// A source table the target does not know stops the load before any write.
$pg->exec('CREATE TABLE work.app_portprobe (id integer)');
[$c, $o] = load(['--from-pg', '', '--target-prefix', 'lt_', '--replace', '--register', 'work']);
$pg->exec('DROP TABLE work.app_portprobe');
check('a source table with no target table is refused', $c === 1 && str_contains($o, 'nobody ported'), trim($o));

// SQL Server value shapes, as pdo_sqlsrv returns them, against PostgreSQL's input rules.
$v = $pg->query("SELECT CAST('2026-10-04 15:16:35.1234567' AS timestamp(6))::text")->fetchColumn();
check('seven fractional digits load, rounded to six', $v === '2026-10-04 15:16:35.123457', (string)$v);
$v = $pg->query("SELECT CAST('.5000' AS numeric(9,4))::text")->fetchColumn();
check('a decimal without its leading zero loads', $v === '0.5000', (string)$v);
require_once __DIR__ . '/../tools/pg/load_values.php';
check('verification treats 7 and 6 fractional digits alike',
    norm_value('2026-10-04 15:16:35.1234567', 'timestamp without time zone') === norm_value('2026-10-04 15:16:35.123457', 'timestamp without time zone'));
check('verification treats .5 and 0.50 alike', norm_value('.5', 'numeric') === norm_value('0.50', 'numeric'));
check('verification still tells two timestamps apart',
    norm_value('2026-10-04 15:16:35.1234567', 'timestamp without time zone') !== norm_value('2026-10-04 15:16:35.123458', 'timestamp without time zone'));
// An exact half in the seventh digit: PostgreSQL rounds it through a double
// (.5099075 to .509907), so the loader sends the six-digit value itself.
$half = norm_value('2026-08-07 13:13:05.5099075', 'timestamp without time zone');
$v = $pg->query('SELECT CAST(' . $pg->quote($half) . ' AS timestamp(6))::text')->fetchColumn();
check('an exact half in the seventh digit loads as verification expects', $v === $half && $half === '2026-08-07 13:13:05.509908', (string)$v);
check('verification compares text exactly, case included', norm_value('Lab', 'USER-DEFINED') !== norm_value('lab', 'USER-DEFINED'));

foreach (APP_DB_NAMES as $r) {
    $pg->exec('DROP SCHEMA IF EXISTS lt_' . $r . ' CASCADE');
}
if ($fails) {
    echo "$fails of $count loader checks FAILED.\n";
    exit(1);
}
echo "All $count loader checks passed.\n";
