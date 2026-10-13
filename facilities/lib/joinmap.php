<?php
declare(strict_types=1);

/**
 * The join map, read from the live catalogue.
 *
 * The ten databases are joined on shared names, never on cross-database
 * keys. Each name is owned by one register; every other database that
 * carries that name joins TO that register - hub and spoke, not a mesh.
 * Saying so from the schema itself means the briefing cannot claim a
 * join that no longer exists, or miss one that was added.
 *
 * The ref.* tables are the published copies every database holds, so a
 * column only counts as a join where it appears in a WORKING table -
 * the request and register schemas - not in the copy.
 */

require_once __DIR__ . '/db.php';

/** The name, the register that owns it, and why it is worth joining. */
const JOIN_KEYS = [
    'RoomCode'    => ['Space · gis.Rooms',            'the room on the floor plan'],
    'BldgNumber'  => ['Space · ref.Buildings',        'the building it stands in'],
    'NetID'       => ['ref.People',                   'the person, from the directory'],
    'SurveyCode'  => ['ref.Surveys',                  'which survey owns it'],
    'CfopCode'    => ['ref.CfopAccounts',             'the account that pays'],
    'PropertyTag' => ['Equipment · gis.Inventory',    'the tagged item'],
    'FleetNumber' => ['Fleet · ref.FleetVehicles',    'the vehicle'],
];

/** One colour per shared name, used by the deck and the drawing alike. */
const JOIN_COLORS = [
    'RoomCode'    => 'C7461F',
    'BldgNumber'  => '1D7168',
    'NetID'       => '457B9D',
    'SurveyCode'  => '7A4FA0',
    'CfopCode'    => 'A0761B',
    'PropertyTag' => '2A9D8F',
    'FleetNumber' => '13294B',
];

/** Schemas that hold real work, as opposed to the published copies. */
const JOIN_WORKING_SCHEMAS = "'wr','mv','sh','gis','rq','est'";

/**
 * The fleet register lives in ref because it is reference data that the
 * fleet database happens to own; it is working data there, so it counts.
 */
const JOIN_ALSO = ['fleet' => ['ref.FleetVehicles']];

/**
 * The same fact under another column name. The move sheet calls a
 * property tag PTag, and carries the older PRI tag beside it.
 */
const JOIN_ALIASES = ['PropertyTag' => ['PTag', 'PRITag']];

const JOIN_DB_LABEL = [
    'work' => 'Work', 'move' => 'Move', 'shop' => 'Shop', 'space' => 'Space',
    'keys' => 'Keys', 'incidents' => 'Incidents', 'plans' => 'Plans',
    'purchasing' => 'Purchasing', 'equipment' => 'Equipment', 'fleet' => 'Fleet',
];

/**
 * [key => ['owner' => ..., 'why' => ..., 'carriers' => [db => [table, ...]]]]
 * A column counts when its name matches, or ends with the name, which is
 * how the same fact appears as HolderNetID, FromRoomCode or HomeBldgNumber.
 */
function join_map(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    foreach (JOIN_KEYS as $key => [$owner, $why]) {
        $map[$key] = ['owner' => $owner, 'why' => $why, 'carriers' => []];
    }
    foreach (array_keys(JOIN_DB_LABEL) as $db) {
        // Asked through the catalogue helpers, so PostgreSQL answers too.
        $working = array_map(fn($s) => trim($s, " '"), explode(',', JOIN_WORKING_SCHEMAS));
        $also = JOIN_ALSO[$db] ?? [];
        $rows = [];
        foreach (array_keys(db_tables($db)) as $t) {
            $schema = strtolower(explode('.', $t, 2)[0]);
            if (in_array($schema, $working, true) || in_array($t, $also, true)) {
                foreach (db_columns($db, $t) as $c) {
                    $rows[] = ['t' => $t, 'c' => $c['name']];
                }
            }
        }
        foreach ($rows as $r) {
            foreach (JOIN_KEYS as $key => $_) {
                $names = array_merge([$key], JOIN_ALIASES[$key] ?? []);
                foreach ($names as $name) {
                    if (strcasecmp($r['c'], $name) === 0
                        || (strlen($r['c']) > strlen($name)
                            && strcasecmp(substr($r['c'], -strlen($name)), $name) === 0)) {
                        $map[$key]['carriers'][$db][$r['t']] = true;
                        break;
                    }
                }
            }
        }
    }
    foreach ($map as $key => &$e) {
        foreach ($e['carriers'] as $db => $tables) {
            $e['carriers'][$db] = array_keys($tables);
        }
        ksort($e['carriers']);
    }
    return $map;
}

/** "Equipment, Incidents, Keys, ..." - the databases that carry a name. */
function join_carrier_names(string $key): string
{
    $m = join_map();
    $names = [];
    foreach (array_keys($m[$key]['carriers'] ?? []) as $db) {
        $names[] = JOIN_DB_LABEL[$db] ?? $db;
    }
    sort($names);
    return implode(', ', $names);
}
