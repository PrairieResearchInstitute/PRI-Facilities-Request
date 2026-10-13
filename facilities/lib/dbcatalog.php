<?php
declare(strict_types=1);

/**
 * The catalogue, asked the same way on either engine.
 *
 * SQL Server answers from sys.tables and sys.columns; PostgreSQL from
 * information_schema inside the register's schema. Names come back in their
 * SQL Server spelling ('app.Users', 'NetID') and types under their SQL Server
 * names ('nvarchar', 'int', 'bit', 'datetime2'), so the pages that list
 * tables, export them or audit their completeness read the same answers
 * whichever engine holds the registers. Each function takes a register name
 * or one of its connections. Required by db.php.
 */

/** [connection, register name or null] for a register name or a connection. */
function db_catalogue_target(PDO|string $where): array
{
    if (is_string($where)) {
        return [db($where), $where];
    }
    return [$where, $where instanceof FacilitiesPgPdo ? $where->register : null];
}

/** PostgreSQL table name ('app_users') to SQL Server spelling ('app.Users'). */
function db_pg_canonical_table(string $pgName): string
{
    static $map = null;
    if ($map === null) {
        require_once __DIR__ . '/pg_catalog.php';
        $map = [];
        foreach (array_keys(PG_TABLE_COLUMNS) as $q) {
            $map[strtolower(str_replace('.', '_', $q))] = $q;
        }
    }
    if (isset($map[$pgName])) {
        return $map[$pgName];
    }
    $at = strpos($pgName, '_');
    return $at === false ? $pgName : substr($pgName, 0, $at) . '.' . substr($pgName, $at + 1);
}

/** SQL Server type name for a PostgreSQL column type. */
function db_pg_type_name(string $dataType, string $udt): string
{
    if ($udt === 'citext' || $dataType === 'text' || $dataType === 'character varying') {
        return 'nvarchar';
    }
    return [
        'integer' => 'int', 'smallint' => 'bit', 'bigint' => 'bigint', 'numeric' => 'decimal',
        'timestamp without time zone' => 'datetime2', 'date' => 'date', 'real' => 'real',
        'double precision' => 'float', 'time without time zone' => 'time',
    ][$dataType] ?? $dataType;
}

/** Does the register hold this table ('schema.Table')? */
function db_has_table(PDO|string $where, string $table): bool
{
    [$pdo, $reg] = db_catalogue_target($where);
    [$schema, $name] = array_pad(explode('.', $table, 2), 2, '');
    if ($pdo instanceof FacilitiesPgPdo) {
        $st = $pdo->queryNative('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '
            . $pdo->quote($reg) . ' AND table_name = ' . $pdo->quote(strtolower($schema . '_' . $name)));
        return (int)$st->fetchColumn() > 0;
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM sys.tables t JOIN sys.schemas s ON s.schema_id = t.schema_id
                          WHERE s.name = ? AND t.name = ?');
    $st->execute([$schema, $name]);
    return (int)$st->fetchColumn() > 0;
}

/** Every table the register holds: 'schema.Table' => row count, in name order. */
function db_tables(PDO|string $where): array
{
    [$pdo, $reg] = db_catalogue_target($where);
    $out = [];
    if ($pdo instanceof FacilitiesPgPdo) {
        $names = $pdo->queryNative("SELECT table_name FROM information_schema.tables WHERE table_schema = "
            . $pdo->quote($reg) . " AND table_type = 'BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
        if (!$names) {
            return [];
        }
        $parts = [];
        foreach ($names as $n) {
            $parts[] = 'SELECT ' . $pdo->quote($n) . ' AS t, COUNT(*) AS n FROM ' . $reg . '.' . $n;
        }
        foreach ($pdo->queryNative(implode(' UNION ALL ', $parts))->fetchAll(PDO::FETCH_KEY_PAIR) as $n => $c) {
            $out[db_pg_canonical_table((string)$n)] = (int)$c;
        }
        uksort($out, 'strcasecmp');
        return $out;
    }
    $stmt = $pdo->query(
        "SELECT s.name AS SchemaName, t.name AS TableName, SUM(p.rows) AS ApproxRows
           FROM sys.tables t
           JOIN sys.schemas s ON s.schema_id = t.schema_id
           JOIN sys.partitions p ON p.object_id = t.object_id AND p.index_id IN (0, 1)
          WHERE t.is_ms_shipped = 0
          GROUP BY s.name, t.name
          ORDER BY s.name, t.name");
    foreach ($stmt as $r) {
        $out[$r['SchemaName'] . '.' . $r['TableName']] = (int)$r['ApproxRows'];
    }
    return $out;
}

/**
 * The columns of one table, in order: each ['name', 'type', 'nullable'].
 * Computed columns are left out (SQL Server); PostgreSQL has none here.
 */
function db_columns(PDO|string $where, string $table): array
{
    [$pdo, $reg] = db_catalogue_target($where);
    [$schema, $name] = array_pad(explode('.', $table, 2), 2, '');
    $out = [];
    if ($pdo instanceof FacilitiesPgPdo) {
        require_once __DIR__ . '/pg_catalog.php';
        $st = $pdo->queryNative('SELECT column_name, data_type, udt_name, is_nullable FROM information_schema.columns
                                  WHERE table_schema = ' . $pdo->quote($reg) . ' AND table_name = '
                                . $pdo->quote(strtolower($schema . '_' . $name)) . ' ORDER BY ordinal_position');
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['name' => PG_COLUMN_NAMES[$r['column_name']] ?? $r['column_name'],
                      'type' => db_pg_type_name((string)$r['data_type'], (string)$r['udt_name']),
                      'nullable' => $r['is_nullable'] === 'YES'];
        }
        return $out;
    }
    $st = $pdo->prepare(
        "SELECT c.name, ty.name AS ty, c.is_nullable
           FROM sys.columns c
           JOIN sys.types ty ON ty.user_type_id = c.user_type_id
          WHERE c.object_id = OBJECT_ID(?) AND c.is_computed = 0
          ORDER BY c.column_id");
    $st->execute([$schema . '.' . $name]);
    foreach ($st->fetchAll() as $r) {
        $out[] = ['name' => (string)$r['name'], 'type' => (string)$r['ty'], 'nullable' => (bool)$r['is_nullable']];
    }
    return $out;
}

/** Disk the ten registers occupy, in MB, as the server reports it; null if it will not say. */
function db_total_mb(): ?int
{
    try {
        $pdo = db('work');
        if ($pdo instanceof FacilitiesPgPdo) {
            $in = implode(', ', array_map(fn($r) => $pdo->quote($r), APP_DB_NAMES));
            $b = $pdo->queryNative("SELECT COALESCE(SUM(pg_total_relation_size(c.oid)), 0)
                                      FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                                     WHERE n.nspname IN ($in) AND c.relkind = 'r'")->fetchColumn();
            return (int)round(((float)$b) / 1048576);
        }
        $row = $pdo->query(
            'SELECT SUM(CAST(size AS bigint)) * 8 / 1024 AS mb
               FROM sys.master_files
              WHERE DB_NAME(database_id) LIKE ' . chr(39) . 'PRI_Facilities%' . chr(39)
        )->fetch();
        return $row && $row['mb'] !== null ? (int)$row['mb'] : null;
    } catch (Throwable $e) {
        return null;
    }
}
