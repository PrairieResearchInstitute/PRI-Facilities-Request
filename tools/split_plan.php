<?php
declare(strict_types=1);

/**
 * The shape of the system, as one list.
 *
 * Nine databases, on one rule: a database holds a register - the record
 * of what IS - together with the requests that write to it. A key request
 * and the key register belong together; so do a space request and the
 * room it assigns; so do a purchase and the property it becomes.
 *
 * The registers came from somewhere real. The geodatabase is a database:
 * buildings, floors, rooms, footprints, occupants, and which unit is
 * responsible for each room. The inventory is a database: every tagged
 * item and its whole life. Treating either as a lookup table was the
 * mistake that put twelve thousand rows of other people's business inside
 * the work order database.
 */

/** Every database, and what it is for. */
const SPLIT_DATABASES = [
    'work'       => ['PRI_FacilitiesWork',       'Work orders, moves and shop work'],
    'move'       => ['PRI_FacilitiesMove',       'Move requests'],
    'shop'       => ['PRI_FacilitiesShop',       'Shop and vehicle work requests'],
    'purchasing' => ['PRI_FacilitiesPurchasing', 'Purchases, Banner codes, CFOP accounts'],
    'keys'       => ['PRI_FacilitiesKeys',       'Key requests and the key register'],
    'fleet'      => ['PRI_FacilitiesFleet',      'Vehicles, mileage, bookings, drivers'],
    'space'      => ['PRI_FacilitiesSpace',      'The estate: buildings, floors, rooms, '
                                               . 'occupants, responsibility, and space requests'],
    'equipment'  => ['PRI_FacilitiesEquipment',  'The inventory: every tagged item, its '
                                               . 'custody, its history and its disposal'],
    'incidents'  => ['PRI_FacilitiesIncidents',  'Injuries, near misses, spills, exposures'],
    'plans'      => ['PRI_FacilitiesPlans',      'Safety plans, SOPs, their owners and '
                                               . 'review dates'],
];

/**
 * What moves in each round, as [source connection, table].
 *
 * Round two: the two registers that are in the wrong place. Space takes
 * the geodatabase and becomes the master for buildings and rooms, which
 * every other database copies from. Equipment takes the inventory.
 */
const SPLIT_ROUNDS = [
    'space' => [
        ['work', 'gis.Rooms'],
        ['work', 'gis.Floors'],
        ['work', 'gis.BuildingFootprints'],
        ['work', 'gis.BuildingInfo'],
        ['work', 'gis.CategoryColors'],
        ['work', 'gis.Occupants'],
        ['work', 'gis.RoomResponsibility'],
        ['work', 'gis.RoomAssignmentEvents'],
        // Where the eyewashes, showers, extinguishers and hoods are. A
        // fixture of a room, drawn on the floor plan, so it lives with
        // the rooms rather than with incidents or with plans.
        ['work', 'gis.Safety'],
        ['work', 'ref.BuildingMaps'],
        ['work', 'ref.BuildingOccupants'],
        // A space request and the assignment it produces are two things,
        // and they belong in the same database for the same reason a key
        // request belongs with the key register.
        ['work', 'rq.SpaceRequests'],
    ],
    'equipment' => [
        ['work', 'gis.Inventory'],
        ['work', 'gis.InventoryEvents'],
        ['work', 'rq.PropertyUpdates'],
    ],
];

/**
 * Which database is the master for a shared table, and therefore the one
 * the reference publish reads from.
 *
 * Buildings and rooms move to Space. Everything that names a building
 * still gets a copy - a work order has to offer the building list - but
 * the copy now comes from the estate rather than from the work orders.
 */
const SPLIT_MASTERS = [
    'ref.Buildings'        => 'space',
    'ref.Rooms'            => 'space',
    'ref.BuildingAliases'  => 'space',
    'ref.BuildingMaps'     => 'space',
    'ref.BuildingOccupants' => 'space',
    // Until the directory is its own database these stay where they are.
    'ref.Surveys'          => 'work',
    'ref.Owners'           => 'work',
    'ref.PickLists'        => 'work',
    'ref.PickListTypes'    => 'work',
    'ref.StatusFlow'       => 'work',
    'app.Users'            => 'work',
    'app.Settings'         => 'work',
];

/** Copied into every database: the scaffolding each one needs to stand alone. */
const SPLIT_SCAFFOLD = [
    'app.Users', 'app.Settings', 'app.NotifyRules', 'app.AdminAccess', 'app.DigestPreferences',
    'app.Notifications', 'app.Attachments', 'app.AuditLog', 'app.StatusHistory', 'app.Notes',
    'ref.Buildings', 'ref.BuildingAliases', 'ref.Rooms', 'ref.People', 'ref.Surveys',
    'ref.Owners', 'ref.StatusFlow', 'ref.PickLists', 'ref.PickListTypes',
];

/** Structure only. Each database keeps its own log; nobody inherits Work's. */
const SPLIT_STRUCTURE_ONLY = [
    'app.Notifications', 'app.Attachments', 'app.AuditLog', 'app.StatusHistory', 'app.Notes',
];
