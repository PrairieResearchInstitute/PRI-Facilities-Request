<?php
declare(strict_types=1);

/**
 * Remove the originals, once the copies have been proved.
 *
 * This runs last and only by hand. It refuses to drop anything unless
 * the copy has at least as many rows as the original, so a half-finished
 * migration cannot be finished by deleting the evidence.
 *
 *   php tools/drop_moved_tables.php            what it would drop
 *   php tools/drop_moved_tables.php --apply    drop them
 */

require_once __DIR__ . '/../facilities/lib/db.php';
db_localdb_only('tools/drop_moved_tables.php');

$apply = in_array('--apply', $argv, true);

/**
 * Children before parents. rq.PurchaseItems points at rq.PurchaseRequests,
 * so the parent cannot go first - SQL Server refuses, which is the
 * database doing its job.
 */
const MOVED = [
    ['work', 'purchasing', 'rq.PurchaseItems'],
    ['work', 'purchasing', 'rq.PurchaseFunding'],
    ['work', 'purchasing', 'rq.PurchaseEvents'],
    ['work', 'purchasing', 'rq.PurchaseRequests'],
    ['work', 'purchasing', 'ref.BannerAccountCodes'],
    ['work', 'keys',       'rq.KeyRequests'],
    ['work', 'keys',       'gis.RoomKeys'],
    ['work', 'keys',       'gis.KeyEvents'],
    ['shop', 'fleet',      'ref.FleetVehicles'],
    ['shop', 'fleet',      'ref.DriverFormTypes'],
    ['shop', 'fleet',      'sh.DriverForms'],
    ['shop', 'fleet',      'sh.VehicleBookings'],
    ['shop', 'fleet',      'sh.VehicleReadings'],

    // Round two. Children before parents again: ref.BuildingOccupants
    // points at ref.Buildings, and gis.Rooms is pointed at by others.
    ['work', 'space',      'ref.BuildingOccupants'],
    ['work', 'space',      'ref.BuildingMaps'],
    ['work', 'space',      'rq.SpaceRequests'],
    ['work', 'space',      'gis.RoomAssignmentEvents'],
    ['work', 'space',      'gis.RoomResponsibility'],
    ['work', 'space',      'gis.Occupants'],
    ['work', 'space',      'gis.Safety'],
    ['work', 'space',      'gis.CategoryColors'],
    ['work', 'space',      'gis.BuildingInfo'],
    ['work', 'space',      'gis.BuildingFootprints'],
    ['work', 'space',      'gis.Floors'],
    ['work', 'space',      'gis.Rooms'],
    ['work', 'equipment',  'gis.InventoryEvents'],
    ['work', 'equipment',  'rq.PropertyUpdates'],
    ['work', 'equipment',  'gis.Inventory'],
];

$refused = 0;
$dropped = 0;

foreach (MOVED as [$from, $to, $table]) {
    try {
        $was = (int)db($from)->query("SELECT COUNT(*) FROM $table")->fetchColumn();
    } catch (Throwable $e) {
        printf("  %-26s already gone from %s\n", $table, APP_DB_LABELS[$from]);
        continue;
    }
    $now = (int)db($to)->query("SELECT COUNT(*) FROM $table")->fetchColumn();

    if ($now < $was) {
        printf("  %-26s REFUSED: %s has %d rows, %s has %d\n",
            $table, APP_DB_LABELS[$from], $was, APP_DB_LABELS[$to], $now);
        $refused++;
        continue;
    }
    if (!$apply) {
        printf("  %-26s would drop from %-24s (%d rows, safely in %s)\n",
            $table, APP_DB_LABELS[$from], $was, APP_DB_LABELS[$to]);
        continue;
    }
    db($from)->exec("DROP TABLE $table");
    printf("  %-26s dropped from %s\n", $table, APP_DB_LABELS[$from]);
    $dropped++;
}

// The key half of rq.Approvals, now that key requests live elsewhere.
if ($apply) {
    $n = db('work')->exec("DELETE FROM rq.Approvals WHERE RequestType = 'key'");
    if ($n > 0) {
        echo "  removed $n key approval row(s) from Work; they are in the keys database.\n";
    }
}

echo "\n";
if (!$apply) {
    echo "Dry run. Add --apply to drop them.\n";
} elseif ($refused > 0) {
    echo "$dropped dropped, $refused refused. Look at the refusals before running again.\n";
} else {
    echo "$dropped tables dropped. Each subject now lives in exactly one database.\n";
}
