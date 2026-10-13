<?php
declare(strict_types=1);

/**
 * Did the tables arrive whole?
 *
 * Row counts were checked when they were copied, and row counts are the
 * easy half. This compares the shape: columns, the identity column,
 * primary and unique keys, check constraints, defaults and indexes,
 * between the original and the copy. A table that arrived without its
 * defaults reads correctly and writes wrongly.
 */

require_once __DIR__ . '/../facilities/lib/db.php';
db_localdb_only('tools/verify_split.php');

const MOVED = [
    ['work', 'purchasing', 'rq.PurchaseRequests'],
    ['work', 'purchasing', 'rq.PurchaseItems'],
    ['work', 'purchasing', 'rq.PurchaseFunding'],
    ['work', 'purchasing', 'rq.PurchaseEvents'],
    ['work', 'purchasing', 'ref.BannerAccountCodes'],
    ['work', 'keys',       'rq.KeyRequests'],
    ['work', 'keys',       'gis.RoomKeys'],
    ['work', 'keys',       'gis.KeyEvents'],
    ['shop', 'fleet',      'ref.FleetVehicles'],
    ['shop', 'fleet',      'ref.DriverFormTypes'],
    ['shop', 'fleet',      'sh.DriverForms'],
    ['shop', 'fleet',      'sh.VehicleBookings'],
    ['shop', 'fleet',      'sh.VehicleReadings'],
    ['work', 'space',      'gis.Rooms'],
    ['work', 'space',      'gis.Floors'],
    ['work', 'space',      'gis.BuildingFootprints'],
    ['work', 'space',      'gis.BuildingInfo'],
    ['work', 'space',      'gis.CategoryColors'],
    ['work', 'space',      'gis.Occupants'],
    ['work', 'space',      'gis.RoomResponsibility'],
    ['work', 'space',      'gis.RoomAssignmentEvents'],
    ['work', 'space',      'gis.Safety'],
    ['work', 'space',      'ref.BuildingMaps'],
    ['work', 'space',      'ref.BuildingOccupants'],
    ['work', 'space',      'rq.SpaceRequests'],
    ['work', 'equipment',  'gis.Inventory'],
    ['work', 'equipment',  'gis.InventoryEvents'],
    ['work', 'equipment',  'rq.PropertyUpdates'],
];

function shape(PDO $pdo, string $table): array
{
    $one = function (string $sql) use ($pdo, $table) {
        return (int)$pdo->query(str_replace('{T}', "'$table'", $sql))->fetchColumn();
    };
    return [
        'columns'  => $one("SELECT COUNT(*) FROM sys.columns WHERE object_id = OBJECT_ID({T})"),
        'identity' => $one("SELECT COUNT(*) FROM sys.identity_columns WHERE object_id = OBJECT_ID({T})"),
        'primary'  => $one("SELECT COUNT(*) FROM sys.indexes
                             WHERE object_id = OBJECT_ID({T}) AND is_primary_key = 1"),
        'unique'   => $one("SELECT COUNT(*) FROM sys.indexes
                             WHERE object_id = OBJECT_ID({T}) AND is_unique_constraint = 1"),
        'checks'   => $one("SELECT COUNT(*) FROM sys.check_constraints
                             WHERE parent_object_id = OBJECT_ID({T})"),
        'defaults' => $one("SELECT COUNT(*) FROM sys.default_constraints
                             WHERE parent_object_id = OBJECT_ID({T})"),
        'indexes'  => $one("SELECT COUNT(*) FROM sys.indexes
                             WHERE object_id = OBJECT_ID({T}) AND is_primary_key = 0
                               AND is_unique_constraint = 0 AND name IS NOT NULL"),
        // Foreign keys were the thing the first copy quietly left behind:
        // the tables looked identical and would have taken an orphan.
        'foreign keys' => $one("SELECT COUNT(*) FROM sys.foreign_keys
                                 WHERE parent_object_id = OBJECT_ID({T})"),
        'rows'     => $one("SELECT COUNT(*) FROM $table"),
    ];
}

$bad = 0;
printf("%-26s %-22s %s\n", 'table', 'what', 'source -> copy');
echo str_repeat('-', 72) . "\n";

foreach (MOVED as [$from, $to, $table]) {
    // Round one's originals are already dropped. A table gone from the
    // source is a table that finished the journey, not a failure.
    $stillThere = (int)db($from)->query(
        "SELECT CASE WHEN OBJECT_ID('$table') IS NULL THEN 0 ELSE 1 END")->fetchColumn();
    if (!$stillThere) {
        printf("%-26s %s
", $table, 'already dropped from ' . APP_DB_LABELS[$from]);
        continue;
    }
    $a = shape(db($from), $table);
    $b = shape(db($to), $table);
    $diffs = [];
    foreach ($a as $what => $n) {
        if ($n !== $b[$what]) {
            $diffs[] = sprintf('%s %d -> %d', $what, $n, $b[$what]);
        }
    }
    if ($diffs === []) {
        printf("%-26s %s\n", $table, 'identical in every respect');
    } else {
        $bad++;
        printf("%-26s %s\n", $table, 'DIFFERS: ' . implode('; ', $diffs));
    }
}

echo "\n";
echo $bad === 0
    ? "Every moved table matches its original in shape and row count.\n"
    : "$bad table(s) differ. Nothing should be dropped until they do not.\n";
