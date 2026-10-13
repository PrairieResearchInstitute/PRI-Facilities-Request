<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * What is stored where, said in the words of the work rather than the
 * words of the database.
 *
 * Six databases, and a person asking "are purchase requests being kept"
 * should not have to know which one to look in, nor read a list of a
 * hundred and thirteen table names to find out.
 *
 * So this maps the subjects a person actually has in mind onto the tables
 * that hold them, wherever those are. Every table belongs to exactly one subject, and
 * anything not named here is collected under "Everything else" rather
 * than being hidden - a map that quietly omits things is worse than no
 * map, because it is believed.
 */

/** Subject => [[database, table, what it holds], ...] */
function data_subjects(): array
{
    return [
        'Work Requests' => [
            ['work', 'wr.WorkRequests', 'The requests themselves'],
            ['work', 'wr.Approvals', 'Who signed off, and when'],
            ['work', 'wr.Corroborations', '"I am seeing this too"'],
            ['work', 'wr.DedupDecisions', 'What was judged a duplicate of what'],
        ],
        'Move Requests' => [
            ['move', 'mv.MoveRequests', 'The requests themselves'],
            ['move', 'mv.MoveItems', 'Each thing being moved'],
            ['move', 'mv.Reviews', 'IT, inventory and facilities review'],
        ],
        'Shop and Vehicle Work' => [
            ['shop', 'sh.ShopRequests', 'The requests themselves'],
            ['shop', 'sh.Approvals', 'The account holder and the shop supervisor'],
        ],
        'Space Requests' => [
            ['space', 'rq.SpaceRequests', 'Asking for space, giving it back, changing its use'],
            ['space', 'rq.Approvals', 'Committee and director decisions on a space request'],
        ],
        'Keys' => [
            ['keys', 'rq.KeyRequests', 'Asking for a key'],
            ['keys', 'gis.RoomKeys', 'Who holds which key to which room'],
            ['keys', 'gis.KeyEvents', 'Issued, returned, lost, rekeyed'],
            ['keys', 'rq.Approvals', 'The director decision on each key request'],
        ],
        'Purchase Requests' => [
            ['purchasing', 'rq.PurchaseRequests', 'The requests themselves'],
            ['purchasing', 'rq.PurchaseItems', 'Every product line, with its Banner code'],
            ['purchasing', 'rq.PurchaseFunding', 'The accounts charged, and both approvals on each'],
            ['purchasing', 'rq.PurchaseEvents', 'The approval timeline'],
            ['purchasing', 'ref.BannerAccountCodes', 'The university expenditure codes'],
            ['purchasing', 'ref.CfopAccounts', 'The accounts, their PIs and fund managers'],
        ],
        'Property and Inventory' => [
            ['equipment', 'gis.Inventory', 'Every tagged item, where it is and who holds it'],
            ['equipment', 'gis.InventoryEvents', "Each item's history"],
            ['equipment', 'rq.PropertyUpdates', 'Arrivals, moves, handovers and disposals to apply'],
        ],
        'Vehicles and Drivers' => [
            ['fleet', 'ref.FleetVehicles', 'The fleet'],
            ['fleet', 'sh.VehicleReadings', 'Odometer readings'],
            ['fleet', 'sh.VehicleBookings', 'Who has which vehicle, when'],
            ['fleet', 'sh.DriverForms', 'Driver paperwork, with its expiry'],
            ['fleet', 'ref.DriverFormTypes', 'Which forms are required, and for how long'],
        ],
        'Buildings, Rooms and Floor Plans' => [
            ['space', 'gis.Rooms', 'Every room, by its building, floor and room code'],
            ['space', 'gis.Floors', 'Floors, and the plans drawn for them'],
            ['space', 'gis.BuildingFootprints', 'Building outlines from the campus map'],
            ['space', 'gis.BuildingInfo', 'What the campus system says about each building'],
            ['space', 'gis.CategoryColors', 'How room categories are coloured on a plan'],
            ['space', 'gis.Occupants', 'Who sits where'],
            ['space', 'gis.RoomResponsibility', 'Survey, principal investigator, safety contact'],
            ['space', 'gis.RoomAssignmentEvents', 'Changes to who a room belongs to'],
            ['space', 'ref.Buildings', 'The building list the forms offer'],
            ['space', 'ref.Rooms', 'The room list the forms offer'],
            ['space', 'ref.BuildingAliases', 'The other names people call a building'],
            ['space', 'ref.BuildingMaps', 'Which plan belongs to which building'],
            ['space', 'ref.BuildingOccupants', 'Which units are in which building'],
        ],
        'Safety Equipment' => [
            ['space', 'gis.Safety', 'Eyewashes, showers, extinguishers, hoods, AEDs'],
        ],
        'Incidents' => [
            ['incidents', 'rq.Incidents', 'Injuries, near misses, spills, exposures'],
            ['incidents', 'rq.IncidentPeople', 'Who was hurt, who saw it, what treatment'],
            ['incidents', 'rq.IncidentActions', 'What is being done so it does not recur'],
            ['incidents', 'rq.IncidentEvents', 'The timeline of one report'],
        ],
        'Safety Plans' => [
            ['plans', 'rq.SafetyPlans', 'Plans and procedures, their owners and review dates'],
            ['plans', 'rq.PlanRooms', 'The rooms and buildings each plan covers'],
            ['plans', 'rq.PlanReviews', 'Every time a plan was read again'],
            ['plans', 'ref.PlanTypes', 'The kinds of plan and how often each is reviewed'],
        ],
        'People, Units and Accounts' => [
            ['work', 'ref.People', 'Everybody, from the employee export'],
            ['work', 'ref.OrgUnits', 'The org chart'],
            ['work', 'ref.Surveys', 'The five surveys and OED, with their leadership'],
            ['work', 'ref.UnitContacts', 'Who to ask, per unit, per subject'],
            ['work', 'gis.Roster', 'The campus roster as pulled'],
            ['work', 'app.Users', 'Anybody who has used the system'],
            ['work', 'app.AdminAccess', 'Who may see the back end, and for what'],
            ['shop', 'ref.CfopAccounts', 'The CFOP accounts, their PIs and fund managers'],
        ],
        'Lists, Settings and Reference' => [
            ['work', 'ref.PickLists', 'Every dropdown the forms offer'],
            ['work', 'ref.PickListTypes', 'What each of those lists is for'],
            ['work', 'ref.StatusFlow', 'The stages each request type walks through'],
            ['work', 'ref.Natures', 'Kinds of work'],
            ['work', 'ref.WorkTypes', 'Work request categories'],
            ['work', 'ref.Owners', 'Who a request is handed to'],
            ['work', 'ref.TermSynonyms', 'Words that mean the same thing when searching'],
            ['work', 'ref.DataSources', 'The campus exports, and how current each one is'],
            ['work', 'app.Settings', 'Every weight, threshold and dollar limit'],
        ],
        'Notifications and History' => [
            ['work', 'app.Notifications', 'Everything that was sent, or would have been'],
            ['work', 'app.NotifyRules', 'Who is told what'],
            ['work', 'app.StatusHistory', 'Every status change on every request'],
            ['work', 'app.AuditLog', 'Who did what in the back end'],
            ['work', 'app.Notes', 'Notes left on requests'],
            ['work', 'app.DigestPreferences', 'How often each person wants a digest'],
        ],
        'Files, Imports and Counters' => [
            ['work', 'app.Attachments', 'Photographs, drawings, quotes'],
            ['work', 'app.ImportBatches', 'Past data staged for loading'],
            ['work', 'app.ImportRows', 'The rows in those batches'],
            ['work', 'app.DataLoads', 'Every campus export that has been loaded'],
            ['work', 'app.Counters', 'The ticket number sequences'],
        ],
    ];
}

/**
 * The same map, with the tables that exist but are not named above added
 * under "Everything else", so the screen never quietly omits one.
 */
function data_subjects_complete(array $catalogue): array
{
    $subjects = data_subjects();

    $claimed = [];
    foreach ($subjects as $tables) {
        foreach ($tables as [$db, $table]) {
            $claimed[$db . '|' . $table] = true;
        }
    }

    $leftovers = [];
    foreach ($catalogue as $db => $tables) {
        foreach (array_keys($tables) as $table) {
            if (isset($claimed[$db . '|' . $table])) {
                continue;
            }
            // A reference table replicated into Move and Shop is the same
            // table as the Work one, named once already.
            // The same reference table is published into every database.
            // Naming it once, where it is maintained, is enough.
            $claimedElsewhere = false;
            foreach (APP_DB_NAMES as $other) {
                if ($other !== $db && isset($claimed[$other . '|' . $table])) {
                    $claimedElsewhere = true;
                }
            }
            if ($claimedElsewhere) {
                continue;   // the same reference table, published outward
            }
            $leftovers[] = [$db, $table, ''];
        }
    }
    if ($leftovers) {
        $subjects['Everything Else'] = $leftovers;
    }
    return $subjects;
}

/**
 * A one line summary per subject: how many tables, how many rows, and
 * which databases it touches. This is what answers "is that even being
 * stored" without anybody opening a table.
 */
function data_subject_summary(array $catalogue): array
{
    $out = [];
    foreach (data_subjects_complete($catalogue) as $subject => $tables) {
        $rows = 0;
        $dbs = [];
        $count = 0;
        foreach ($tables as [$db, $table]) {
            if (!isset($catalogue[$db][$table])) {
                continue;   // not installed yet
            }
            $rows += (int)$catalogue[$db][$table];
            $dbs[APP_DB_LABELS[$db] ?? $db] = true;
            $count++;
        }
        $out[$subject] = ['tables' => $count, 'rows' => $rows,
                          'databases' => array_keys($dbs)];
    }
    return $out;
}

/**
 * Reference data is deliberately copied into all three databases, so the
 * same name appears more than once in a raw table list. Saying which
 * tables those are stops it reading as duplication nobody intended.
 */
function data_replicated_tables(array $catalogue): array
{
    $seen = [];
    foreach ($catalogue as $db => $tables) {
        foreach (array_keys($tables) as $table) {
            $seen[$table][] = $db;
        }
    }
    $out = [];
    foreach ($seen as $table => $dbs) {
        if (count($dbs) > 1) {
            $out[$table] = $dbs;
        }
    }
    ksort($out);
    return $out;
}
