<?php
declare(strict_types=1);

/**
 * The PostgreSQL translation layer, checked without a server.
 *
 *   php tests/pg-translate.php        ends with "All <n> translation checks passed."
 *
 * Each case is a SQL Server statement the application sends, or one shaped
 * like it, and the PostgreSQL the register schema must receive. Run by the
 * gate (tools/check.js). The live engine is untouched: nothing here opens a
 * connection, and SQL Server statements are never rewritten on LocalDB.
 */

require_once __DIR__ . '/../facilities/lib/db.php';
require_once __DIR__ . '/../facilities/lib/pgsql.php';

$fails = 0;
$count = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $fails, $count;
    $count++;
    if ($ok) {
        echo "ok    $label\n";
    } else {
        $fails++;
        echo "FAIL  $label" . ($detail !== '' ? "\n      $detail" : '') . "\n";
    }
}
function norm(string $s): string
{
    return trim(preg_replace('/\s+/', ' ', $s));
}
function tr_is(string $label, string $sql, string $want, string $reg = 'work'): void
{
    $got = norm(pg_translate($sql, $reg));
    check($label, $got === norm($want), "got:  $got\n      want: " . norm($want));
}

// Table names: <register>.<schema>_<table>, only for tables that exist.
tr_is('table name becomes register schema plus prefixed table',
    'SELECT * FROM app.Users WHERE NetID = ?', 'SELECT * FROM work.app_users WHERE NetID = ?');
tr_is('same table, other register',
    'SELECT COUNT(*) FROM rq.PurchaseRequests', 'SELECT COUNT(*) FROM purchasing.rq_purchaserequests', 'purchasing');
tr_is('alias named like a schema is left alone',
    'SELECT wr.Status FROM wr.WorkRequests wr', 'SELECT wr.Status FROM work.wr_workrequests wr');
tr_is('three-part name picks the register of the database',
    'SELECT * INTO rq.Approvals FROM [PRI_FacilitiesWork].rq.Approvals WHERE 1 = 0',
    'SELECT * INTO keys.rq_approvals FROM work.rq_approvals WHERE 1 = 0', 'keys');
tr_is('brackets go; a bracketed name with a space stays quoted',
    'SELECT [RoomCode], x AS [Days past] FROM [gis].[Rooms]',
    'SELECT RoomCode, x AS "Days past" FROM work.gis_rooms');
tr_is('N prefix goes; a word ending in N before a quote does not',
    "SELECT 1 FROM ref.Buildings WHERE BldgNumber = N'TBD' AND Name IN('x')",
    "SELECT 1 FROM work.ref_buildings WHERE BldgNumber = 'TBD' AND Name IN('x')");
tr_is('text inside a literal is never translated',
    "SELECT 'app.Users TOP 5 ISNULL(' AS t", "SELECT 'app.Users TOP 5 ISNULL(' AS t");

// TOP and locks.
tr_is('TOP n becomes LIMIT at the end',
    "SELECT TOP 1 * FROM app.Users WHERE Role = 'admin' ORDER BY UserID",
    "SELECT * FROM work.app_users WHERE Role = 'admin' ORDER BY UserID NULLS FIRST LIMIT 1");
tr_is('TOP (n) in a sub-query stays inside its parentheses',
    'SELECT a, (SELECT TOP (1) b FROM gis.Rooms ORDER BY b DESC) AS c FROM gis.Floors',
    'SELECT a, (SELECT b FROM work.gis_rooms ORDER BY b DESC NULLS LAST LIMIT 1 ) AS c FROM work.gis_floors');
tr_is('UPDLOCK, HOLDLOCK becomes FOR UPDATE',
    'SELECT CounterYear FROM app.Counters WITH (UPDLOCK, HOLDLOCK) WHERE CounterName = ?',
    'SELECT CounterYear FROM work.app_counters WHERE CounterName = ? FOR UPDATE');
tr_is('NOLOCK disappears',
    'SELECT 1 FROM app.Users WITH (NOLOCK)', 'SELECT 1 FROM work.app_users');
tr_is('TOP and DISTINCT',
    'SELECT DISTINCT TOP 5 Unit FROM app.Users', 'SELECT DISTINCT Unit FROM work.app_users LIMIT 5');

// Functions.
tr_is('SYSDATETIME is the statement time, local',
    'UPDATE app.Users SET LastLoginAt = SYSDATETIME() WHERE UserID = ?',
    'UPDATE work.app_users SET LastLoginAt = CAST(statement_timestamp() AS timestamp(6)) WHERE UserID = ?');
tr_is('ISNULL becomes COALESCE', 'SELECT ISNULL(Unit, ?) FROM app.Users', 'SELECT COALESCE(Unit, ?) FROM work.app_users');
tr_is('CAST to a date of now',
    'SELECT CAST(SYSDATETIME() AS DATE)', 'SELECT CAST(CAST(statement_timestamp() AS timestamp(6)) AS date)');
tr_is('DATEADD years',
    'SELECT 1 WHERE x >= DATEADD(year, -1, SYSDATETIME())',
    "SELECT 1 WHERE x >= ((CAST(statement_timestamp() AS timestamp(6))) + (-1) * INTERVAL '1 year')");
tr_is('DATEADD days on a date stays a date',
    'SELECT DATEADD(day, 30, CAST(SYSDATETIME() AS DATE))',
    "SELECT CAST((CAST(CAST(statement_timestamp() AS timestamp(6)) AS date)) + (30) * INTERVAL '1 day' AS date)");
tr_is('DATEDIFF days counts date boundaries',
    'SELECT DATEDIFF(day, a.CreatedAt, SYSDATETIME()) AS Waited',
    'SELECT (CAST(CAST(statement_timestamp() AS timestamp(6)) AS date) - CAST(a.CreatedAt AS date)) AS Waited');
tr_is('CAST to NVARCHAR(n) keeps truncation and case-insensitivity',
    'SELECT CAST(VehicleYear AS NVARCHAR(10))',
    'SELECT CAST(CAST(VehicleYear AS varchar(10)) AS public.citext)');
tr_is('CAST to NVARCHAR(MAX) is citext', 'SELECT CAST(x AS NVARCHAR(MAX))', 'SELECT CAST(x AS public.citext)');
tr_is('LEN ignores trailing spaces as SQL Server does', 'SELECT LEN(Name) FROM app.Users',
    'SELECT char_length(rtrim(Name)) FROM work.app_users');
tr_is('a column named like a function is not a call', 'SELECT r.Year, Day FROM t r', 'SELECT r.Year, Day FROM t r');

// Comparisons and ordering.
tr_is('LIKE compares without case, as the SQL Server collation does',
    "SELECT 1 FROM app.Users WHERE Email NOT LIKE '%@x'", "SELECT 1 FROM work.app_users WHERE Email NOT ILIKE '%@x'");
tr_is('plus beside a literal joins text',
    "SELECT 'a' + 'b', 'x' + ?", "SELECT 'a' || 'b', 'x' || ?");
tr_is('plus between numbers stays plus', 'SELECT Quantity + 1 FROM gis.RoomKeys', 'SELECT Quantity + 1 FROM work.gis_roomkeys');
tr_is('ORDER BY gets SQL Server null placement, item by item',
    'SELECT * FROM app.Users ORDER BY Unit, CreatedAt DESC, UserID ASC',
    'SELECT * FROM work.app_users ORDER BY Unit NULLS FIRST, CreatedAt DESC NULLS LAST, UserID ASC NULLS FIRST');
tr_is('ORDER BY inside OVER ends at its parenthesis',
    'SELECT ROW_NUMBER() OVER (PARTITION BY a ORDER BY b DESC) AS n FROM t',
    'SELECT ROW_NUMBER() OVER (PARTITION BY a ORDER BY b DESC NULLS LAST) AS n FROM t');
tr_is('OFFSET FETCH is left as it is',
    'SELECT * FROM app.Users ORDER BY UserID OFFSET 10 ROWS FETCH NEXT 5 ROWS ONLY',
    'SELECT * FROM work.app_users ORDER BY UserID NULLS FIRST OFFSET 10 ROWS FETCH NEXT 5 ROWS ONLY');
tr_is('named parameters survive', 'UPDATE app.Users SET Unit = :u WHERE UserID = :id',
    'UPDATE work.app_users SET Unit = :u WHERE UserID = :id');

// Result keys come back as the code spells them.
$map = pg_key_map('SELECT COUNT(*) AS N, wr.Status, [Year] FROM wr.WorkRequests wr');
check('alias spelling wins for result keys', ($map['n'] ?? null) === 'N');
check('bracketed alias spelling is kept', ($map['year'] ?? null) === 'Year');
check('schema spelling covers SELECT *', ($map['bldgnumber'] ?? null) === 'BldgNumber');
check('rekey renames lower-case keys only', pg_rekey(['status' => 'open', 0 => 'x'], $map) === ['Status' => 'open', 0 => 'x']);

// The catalogue the translation relies on.
check('catalogue holds 79 tables', count(PG_TABLE_COLUMNS) === 79, (string)count(PG_TABLE_COLUMNS));
check('ten registers in the catalogue', array_keys(PG_REGISTER_TABLES) === APP_DB_NAMES);
$conflict = [];
foreach (PG_TABLE_COLUMNS as $t => $cols) {
    foreach ($cols as $c) {
        if (strtolower($c) !== $c && (PG_COLUMN_NAMES[strtolower($c)] ?? null) !== $c) {
            $conflict[] = "$t.$c";
        }
    }
}
check('no column name is spelt two ways', !$conflict, implode(', ', array_slice($conflict, 0, 5)));

// LocalDB stays the default.
check('dataMode absent means LocalDB', db_engine() === 'localdb' || (local_config()['dataMode'] ?? '') === 'postgres');

if ($fails) {
    echo "$fails of $count translation checks FAILED.\n";
    exit(1);
}
echo "All $count translation checks passed.\n";
