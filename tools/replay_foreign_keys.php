<?php
declare(strict_types=1);

/**
 * The foreign keys the copy did not bring.
 *
 * SELECT INTO carries columns, types and identity. It does not carry
 * relationships, and the migration replayed defaults, keys, checks and
 * indexes but not these - so the copies were arriving without the rule
 * that says a purchase line belongs to a purchase. The tables looked
 * right and would have accepted an orphan.
 *
 * Every one of these is between two tables that moved together, so
 * nothing here creates a relationship across a database boundary; that
 * is still not allowed and still is not done.
 *
 *   php tools/replay_foreign_keys.php --apply
 */

require_once __DIR__ . '/../facilities/lib/db.php';
db_localdb_only('tools/replay_foreign_keys.php');

$apply = in_array('--apply', $argv, true);

/** Which database each moved table went to. */
const HOMES = [
    'rq.PurchaseRequests' => ['work', 'purchasing'],
    'rq.PurchaseItems'    => ['work', 'purchasing'],
    'rq.PurchaseFunding'  => ['work', 'purchasing'],
    'rq.PurchaseEvents'   => ['work', 'purchasing'],
    'rq.KeyRequests'      => ['work', 'keys'],
    'gis.RoomKeys'        => ['work', 'keys'],
    'gis.KeyEvents'       => ['work', 'keys'],
    'ref.FleetVehicles'   => ['shop', 'fleet'],
    'ref.DriverFormTypes' => ['shop', 'fleet'],
    'sh.DriverForms'      => ['shop', 'fleet'],
    'sh.VehicleBookings'  => ['shop', 'fleet'],
    'sh.VehicleReadings'  => ['shop', 'fleet'],
    'gis.Rooms'               => ['work', 'space'],
    'gis.Floors'              => ['work', 'space'],
    'gis.BuildingFootprints'  => ['work', 'space'],
    'gis.BuildingInfo'        => ['work', 'space'],
    'gis.CategoryColors'      => ['work', 'space'],
    'gis.Occupants'           => ['work', 'space'],
    'gis.RoomResponsibility'  => ['work', 'space'],
    'gis.RoomAssignmentEvents' => ['work', 'space'],
    'gis.Safety'              => ['work', 'space'],
    'ref.BuildingMaps'        => ['work', 'space'],
    'ref.BuildingOccupants'   => ['work', 'space'],
    'rq.SpaceRequests'        => ['work', 'space'],
    'gis.Inventory'           => ['work', 'equipment'],
    'gis.InventoryEvents'     => ['work', 'equipment'],
    'rq.PropertyUpdates'      => ['work', 'equipment'],
];

$made = 0;
$skipped = [];

foreach (['work', 'shop'] as $source) {
    $rows = db($source)->query(
        "SELECT fk.name AS fkname,
                OBJECT_SCHEMA_NAME(fk.parent_object_id) + '.' + OBJECT_NAME(fk.parent_object_id) AS child,
                OBJECT_SCHEMA_NAME(fk.referenced_object_id) + '.' + OBJECT_NAME(fk.referenced_object_id) AS parent,
                fk.delete_referential_action_desc AS ondelete,
                STUFF((SELECT ', [' + COL_NAME(fkc.parent_object_id, fkc.parent_column_id) + ']'
                         FROM sys.foreign_key_columns fkc
                        WHERE fkc.constraint_object_id = fk.object_id
                        ORDER BY fkc.constraint_column_id
                        FOR XML PATH('')), 1, 2, '') AS childcols,
                STUFF((SELECT ', [' + COL_NAME(fkc.referenced_object_id, fkc.referenced_column_id) + ']'
                         FROM sys.foreign_key_columns fkc
                        WHERE fkc.constraint_object_id = fk.object_id
                        ORDER BY fkc.constraint_column_id
                        FOR XML PATH('')), 1, 2, '') AS parentcols
           FROM sys.foreign_keys fk")->fetchAll();

    foreach ($rows as $fk) {
        $child = (string)$fk['child'];
        $parent = (string)$fk['parent'];
        if (!isset(HOMES[$child])) {
            continue;                       // the child did not move
        }
        $target = HOMES[$child][1];

        // The parent has to be in the same database, either because it
        // moved too or because it is scaffolding copied everywhere.
        $parentMoved = isset(HOMES[$parent]) ? HOMES[$parent][1] : null;
        $parentHere = (int)db($target)->query(
            "SELECT CASE WHEN OBJECT_ID('$parent') IS NULL THEN 0 ELSE 1 END")->fetchColumn();

        if ($parentMoved !== null && $parentMoved !== $target) {
            $skipped[] = "$child -> $parent (they went to different databases)";
            continue;
        }
        if (!$parentHere) {
            $skipped[] = "$child -> $parent ($parent is not in " . APP_DB_LABELS[$target] . ')';
            continue;
        }

        $exists = (int)db($target)->query(
            "SELECT COUNT(*) FROM sys.foreign_keys
              WHERE parent_object_id = OBJECT_ID('$child')
                AND referenced_object_id = OBJECT_ID('$parent')")->fetchColumn();
        if ($exists) {
            continue;
        }

        $onDelete = $fk['ondelete'] === 'CASCADE' ? ' ON DELETE CASCADE' : '';
        $sql = "ALTER TABLE $child ADD CONSTRAINT [{$fk['fkname']}] FOREIGN KEY ({$fk['childcols']})"
             . " REFERENCES $parent ({$fk['parentcols']})$onDelete";

        printf("  %-24s -> %-24s in %s\n", $child, $parent, APP_DB_LABELS[$target]);
        if ($apply) {
            try {
                db($target)->exec($sql);
                $made++;
            } catch (Throwable $e) {
                echo '      FAILED: ' . substr($e->getMessage(), 0, 140) . "\n";
            }
        }
    }
}

echo "\n";
foreach ($skipped as $s) {
    echo "  not replayed: $s\n";
}
echo $apply ? "$made foreign key(s) recreated.\n" : "Dry run. Add --apply.\n";
