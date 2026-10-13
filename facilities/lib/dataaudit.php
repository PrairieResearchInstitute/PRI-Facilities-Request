<?php
declare(strict_types=1);

require_once __DIR__ . '/csvio.php';

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';

/**
 * What is wrong with the data, computed rather than remembered.
 *
 * Every check here answers three questions: what is wrong, how much of it
 * there is, and where it gets fixed. Each returns rows so the finding can
 * be worked through rather than just counted, and a CSV of the lot can be
 * taken away and marked up.
 *
 * Checks are deliberately cheap and independent: one failing must not
 * stop the rest, because a half-drawn list of problems is worse than a
 * complete one with a gap noted in it.
 */

const AUDIT_SEVERITY = ['blocker' => 0, 'important' => 1, 'tidy' => 2];

/**
 * Which rooms actually need a safety contact.
 *
 * Not all of them. An earlier version of this audit asked for a safety
 * contact on all 2,157 rooms, which included corridors, mechanical rooms
 * and offices, and produced a number so large it was useless. What needs
 * a named person is a room where something can hurt somebody: the
 * laboratories, the greenhouses and animal quarters, the shops, the
 * hazardous stores - and any room that already has safety equipment in
 * it, because the equipment is evidence of the hazard.
 *
 * Category 200 is Laboratory in the space category key.
 */
const AUDIT_HAZARD_ROOM = "(
    r.CategoryCode = '200'
    OR r.UseDesc LIKE '%Greenhouse%'
    OR r.UseDesc LIKE '%Shop%'
    OR r.UseDesc LIKE '%Animal Quarters%'
    OR r.UseDesc LIKE '%Hazardous%'
    OR EXISTS (SELECT 1 FROM gis.Safety sq WHERE sq.RoomCode = r.RoomCode)
)";

/**
 * Which rooms should belong to a survey. Circulation, building service
 * and mechanical space belongs to the building rather than to a unit, so
 * asking who is answerable for a corridor is asking the wrong question.
 */
const AUDIT_ASSIGNABLE_ROOM = "r.CategoryCode NOT IN ('WWW', 'XXX', 'YYY')";

function audit_check(string $key, string $title, string $category, string $severity,
                     string $why, string $fixWhere, string $fixLink, callable $run): array
{
    $base = ['key' => $key, 'title' => $title, 'category' => $category,
             'severity' => $severity, 'why' => $why,
             'fix_where' => $fixWhere, 'fix_link' => $fixLink];
    try {
        $found = $run();
    } catch (Throwable $e) {
        // A check that cannot run is itself a finding: silence here would
        // read as "nothing wrong".
        return $base + ['severity' => 'important', 'count' => null, 'rows' => [],
                        'columns' => [],
                        'error' => 'This check could not run: ' . $e->getMessage()];
    }
    return $base + ['count' => $found['count'], 'rows' => $found['rows'] ?? [],
                    'columns' => $found['columns'] ?? [], 'error' => null];
}

function audit_rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    return ['count' => count($rows), 'rows' => $rows,
            'columns' => $rows ? array_keys($rows[0]) : []];
}

/** How many rows of a finding the screen shows before pointing at the CSV. */
const AUDIT_SHOW = 200;

function audit_all(): array
{
    $work = db('work');
    $shop = db('shop');
    $keys = db('keys');
    $fleet = db('fleet');
    $purchasing = db('purchasing');
    $space = db('space');
    $equipment = db('equipment');
    $out = [];
    $add = function (...$a) use (&$out) { $out[] = audit_check(...$a); };

    // ================================================ space and buildings
    $add('rooms_no_owner', 'Rooms with nobody answerable for them', 'Space', 'important',
        'No unit, principal investigator or safety contact is recorded. A request against one of '
        . 'these has no obvious approver. Corridors, mechanical rooms and building service space '
        . 'are left out: those belong to the building, not to a unit.',
        'Space → Room assignment', 'admin.php?section=roomassign',
        fn() => audit_rows($space,
            "SELECT r.BldgNumber AS [Building], r.RoomCode AS [Room code], r.RoomID AS [Room],
                    r.RoomName AS [Name], r.AreaSqFt AS [Square feet]
             FROM gis.Rooms r
             LEFT JOIN gis.RoomResponsibility rr ON rr.RoomCode = r.RoomCode
             WHERE rr.RoomCode IS NULL AND ISNULL(r.SurveyCode, '') = ''
               AND " . AUDIT_ASSIGNABLE_ROOM . "
             ORDER BY r.BldgNumber, r.RoomID"));

    $add('rooms_no_safety_contact', 'Rooms that can hurt somebody, with no safety contact',
        'Safety', 'important',
        'Laboratories, greenhouses, animal quarters, shops, hazardous stores, and any room that '
        . 'already holds safety equipment. When an inspection finding or a spill lands on one of '
        . 'these there has to be somebody to send it to. Offices, corridors and mechanical rooms '
        . 'are deliberately not counted.',
        'Space → Room assignment', 'admin.php?section=roomassign',
        fn() => audit_rows($space,
            "SELECT r.BldgNumber AS [Building], r.RoomCode AS [Room code], r.RoomID AS [Room],
                    ISNULL(r.CategoryDesc, '') AS [Category], ISNULL(r.UseDesc, '') AS [Use],
                    ISNULL(rr.SurveyCode, r.SurveyCode) AS [Unit],
                    (SELECT COUNT(*) FROM gis.Safety sq WHERE sq.RoomCode = r.RoomCode)
                        AS [Safety equipment in it]
             FROM gis.Rooms r
             LEFT JOIN gis.RoomResponsibility rr ON rr.RoomCode = r.RoomCode
             WHERE ISNULL(rr.SafetyContact, '') = ''
               AND " . AUDIT_HAZARD_ROOM . "
             ORDER BY r.BldgNumber, r.RoomID"));

    $add('buildings_no_rooms', 'Buildings with no rooms on the floor plans', 'Space', 'important',
        'A request against one of these cannot pick a room from a list, so the room stays free '
        . 'text and never joins to anything. Either the geodatabase has no plan for it, or the '
        . 'space data has not been loaded since it was added.',
        'Data → Where data comes from', 'admin.php?section=datafeeds',
        function () use ($space, $keys, $equipment) {
            // The building is the estate's, the keys are the key
            // register's and the property is the inventory's. That was
            // one query when they shared a database; it is three now,
            // joined here on the building number rather than by the
            // server.
            $base = audit_rows($space,
                "SELECT b.BldgNumber AS [Building], b.Name AS [Name]
                   FROM ref.Buildings b
                  WHERE b.IsActive = 1
                    AND NOT EXISTS (SELECT 1 FROM gis.Rooms r WHERE r.BldgNumber = b.BldgNumber)
                  ORDER BY b.SortKey");
            $count = function (PDO $pdo, string $sql): array {
                $out = [];
                foreach ($pdo->query($sql) as $r) {
                    $out[(string)$r['B']] = (int)$r['N'];
                }
                return $out;
            };
            $k = $count($keys, 'SELECT BldgNumber AS B, COUNT(*) AS N FROM gis.RoomKeys
                                 GROUP BY BldgNumber');
            $p = $count($equipment, 'SELECT BldgNumber AS B, COUNT(*) AS N FROM gis.Inventory
                                      GROUP BY BldgNumber');
            foreach ($base['rows'] as &$row) {
                $b = (string)$row['Building'];
                $row['Keys against it'] = $k[$b] ?? 0;
                $row['Property in it'] = $p[$b] ?? 0;
            }
            unset($row);
            $base['columns'] = $base['rows'] ? array_keys($base['rows'][0]) : [];
            return $base;
        });

    $add('rooms_no_area', 'Rooms with no floor area', 'Space', 'tidy',
        'Square footage is what space reporting and the indirect cost rate proposal are built '
        . 'from. A room with no area cannot appear in either.',
        'Data → Where data comes from (reload space data)', 'admin.php?section=datafeeds',
        fn() => audit_rows($space,
            "SELECT BldgNumber AS [Building], RoomCode AS [Room code], RoomID AS [Room],
                    RoomName AS [Name], CategoryDesc AS [Category]
             FROM gis.Rooms WHERE ISNULL(AreaSqFt, 0) = 0
             ORDER BY BldgNumber, RoomID"));

    $add('rooms_no_use', 'Rooms with no use code', 'Space', 'tidy',
        'The use code says whether a room is research, office, teaching or storage. Without it '
        . 'the room cannot be counted in a space survey.',
        'Space → Room assignment (use override)', 'admin.php?section=roomassign',
        fn() => audit_rows($space,
            "SELECT r.BldgNumber AS [Building], r.RoomCode AS [Room code], r.RoomID AS [Room],
                    r.RoomName AS [Name], r.AreaSqFt AS [Square feet]
             FROM gis.Rooms r
             LEFT JOIN gis.RoomResponsibility rr ON rr.RoomCode = r.RoomCode
             WHERE ISNULL(r.UseCode, '') = '' AND ISNULL(rr.RoomUseOverride, '') = ''
             ORDER BY r.BldgNumber, r.RoomID"));

    $add('buildings_no_occupant', 'Buildings with no survey recorded as occupying them',
        'Space', 'tidy',
        'Nothing says who is in the building, so it cannot be reported on by survey and a '
        . 'building-wide notice has no audience.',
        'Space → Buildings and rooms', 'admin.php?section=space',
        fn() => audit_rows($space,
            "SELECT b.BldgNumber AS [Building], b.Name AS [Name],
                    ISNULL(b.AddressLine, '') AS [Address]
             FROM ref.Buildings b
             WHERE b.IsActive = 1
               AND NOT EXISTS (SELECT 1 FROM ref.BuildingOccupants o WHERE o.BuildingID = b.BuildingID)
             ORDER BY b.SortKey"));

    // ========================================================== property
    $add('inventory_unknown_building', 'Property in buildings that are not on the buildings list',
        'Property', 'blocker',
        'These items cannot attach to a room, so they are invisible on the map, in room detail '
        . 'and in any move request. Either the building belongs on the list or the property '
        . 'record has the wrong number on it.',
        'Space → Buildings and rooms', 'admin.php?section=space',
        fn() => audit_rows($equipment,
            "SELECT i.BldgNumber AS [Building number], COUNT(*) AS [Items],
                    SUM(ISNULL(i.Cost, 0)) AS [Value at purchase],
                    MIN(i.Description) AS [An example item]
             FROM gis.Inventory i
             LEFT JOIN ref.Buildings b ON b.BldgNumber = i.BldgNumber
             WHERE b.BldgNumber IS NULL AND i.Status = 'active' AND ISNULL(i.BldgNumber, '') <> ''
             GROUP BY i.BldgNumber ORDER BY COUNT(*) DESC"));

    $add('inventory_no_person', 'Property with nobody responsible for it', 'Property', 'important',
        'Nobody is named as holding these, so at inventory certification time there is no one to '
        . 'ask and no one to sign.',
        'Property → Equipment (search, then transfer responsibility)', 'admin.php?section=equipment',
        fn() => audit_rows($equipment,
            "SELECT PropertyTag AS [Tag], Description AS [Item], BldgNumber AS [Building],
                    ISNULL(RoomID, BannerRoom) AS [Room], Cost AS [Cost]
             FROM gis.Inventory WHERE Status = 'active' AND ISNULL(Person, '') = ''
             ORDER BY Description"));

    $add('inventory_no_acquired', 'Property with no acquisition date', 'Property', 'important',
        'Without a date there is no age, so nothing can be planned for replacement, depreciated, '
        . 'or reported against the year it was bought.',
        'Property → Equipment', 'admin.php?section=equipment',
        fn() => audit_rows($equipment,
            "SELECT PropertyTag AS [Tag], Description AS [Item], BldgNumber AS [Building],
                    Cost AS [Cost], ISNULL(FundingSource, '') AS [Bought on]
             FROM gis.Inventory WHERE Status = 'active' AND ISNULL(AcquiredOn, '') = ''
             ORDER BY Description"));

    $add('inventory_no_cost', 'Property with no purchase cost', 'Property', 'tidy',
        'The item cannot be valued, so it is missing from any total and from insurance figures.',
        'Property → Equipment', 'admin.php?section=equipment',
        fn() => audit_rows($equipment,
            "SELECT PropertyTag AS [Tag], Description AS [Item], BldgNumber AS [Building],
                    ISNULL(AcquiredOn, '') AS [Acquired]
             FROM gis.Inventory WHERE Status = 'active' AND Cost IS NULL
             ORDER BY Description"));

    $add('inventory_no_tag', 'Property with no university tag', 'Property', 'tidy',
        'An untagged item cannot be matched to the university property system, so it cannot be '
        . 'certified, transferred or surplused through the official route.',
        'Property → Equipment', 'admin.php?section=equipment',
        fn() => audit_rows($equipment,
            "SELECT Description AS [Item], BldgNumber AS [Building],
                    ISNULL(RoomID, BannerRoom) AS [Room], ISNULL(Person, '') AS [Held by],
                    Cost AS [Cost]
             FROM gis.Inventory WHERE Status = 'active' AND ISNULL(PropertyTag, '') = ''
             ORDER BY Cost DESC"));

    $add('inventory_no_room', 'Property not attached to a mapped room', 'Property', 'tidy',
        'The item has a building but no room the floor plans recognise, so it will not show in '
        . 'room detail and a move request cannot pick it up from its origin room.',
        'Space → Buildings and rooms, or correct the property record', 'admin.php?section=space',
        fn() => audit_rows($equipment,
            "SELECT BldgNumber AS [Building], ISNULL(RoomID, BannerRoom) AS [Room as recorded],
                    COUNT(*) AS [Items]
             FROM gis.Inventory
             WHERE Status = 'active' AND RoomCode IS NULL AND ISNULL(BldgNumber, '') <> ''
             GROUP BY BldgNumber, ISNULL(RoomID, BannerRoom)
             ORDER BY COUNT(*) DESC"));

    // ========================================================== vehicles
    $add('fleet_tags', 'Fleet vehicles numbered with a property tag', 'Vehicles', 'important',
        'These came from a sweep of the property inventory, so their fleet number is really a '
        . 'property tag. A shop request naming one will not match the number on the vehicle.',
        'Property → Fleet vehicles', 'admin.php?section=fleet',
        fn() => audit_rows($fleet,
            "SELECT FleetNumber AS [Fleet number], Description AS [Vehicle]
             FROM ref.FleetVehicles WHERE IsActive = 1 AND FleetNumber LIKE 'P1%'
             ORDER BY Description"));

    $add('fleet_incomplete', 'Vehicles missing year, plate or VIN', 'Vehicles', 'important',
        'Age, registration and insurance all hang off these. Without them a vehicle cannot be '
        . 'scheduled for service, valued, or replaced on a plan.',
        'Property → Fleet vehicles', 'admin.php?section=fleet',
        fn() => audit_rows($fleet,
            "SELECT FleetNumber AS [Fleet number], Description AS [Vehicle],
                    ISNULL(CAST(VehicleYear AS NVARCHAR(10)), '') AS [Year],
                    ISNULL(PlateNumber, '') AS [Plate], ISNULL(VIN, '') AS [VIN],
                    ISNULL(SurveyCode, '') AS [Survey]
             FROM ref.FleetVehicles
             WHERE IsActive = 1 AND (VehicleYear IS NULL OR ISNULL(PlateNumber, '') = ''
                                     OR ISNULL(VIN, '') = '')
             ORDER BY Description"));

    $add('fleet_no_home', 'Vehicles with no home building or survey', 'Vehicles', 'tidy',
        'Nothing says where the vehicle lives or who pays for it, so maintenance cannot be '
        . 'charged and a field station cannot see its own fleet.',
        'Property → Fleet vehicles', 'admin.php?section=fleet',
        fn() => audit_rows($fleet,
            "SELECT FleetNumber AS [Fleet number], Description AS [Vehicle],
                    ISNULL(HomeBldgNumber, '') AS [Home building],
                    ISNULL(SurveyCode, '') AS [Survey], ISNULL(AssignedTo, '') AS [Assigned to]
             FROM ref.FleetVehicles
             WHERE IsActive = 1 AND (ISNULL(HomeBldgNumber, '') = '' OR ISNULL(SurveyCode, '') = '')
             ORDER BY Description"));

    // ============================================================== keys
    $add('keys_terminated', 'Keys still active, held by people marked Terminated',
        'Keys and access', 'blocker',
        'Somebody who has left still has a working key on the register. This is the finding here '
        . 'with a security consequence.',
        'People and access → Key assignment', 'admin.php?section=keys',
        fn() => audit_rows($keys,
            "SELECT HolderName AS [Holder], SurveyCode AS [Survey], BuildingText AS [Building],
                    RoomID AS [Room], KeyCode AS [Key], KeyType AS [Type], IssuedOn AS [Assigned on]
             FROM gis.RoomKeys WHERE KeyState = 'Active' AND EmpStatus = 'Terminated'
             ORDER BY SurveyCode, HolderName"));

    $add('keys_no_holder', 'Keys with nobody named against them', 'Keys and access', 'important',
        'The register says the key is out but not who has it, so it can never be returned or '
        . 'chased.',
        'People and access → Key assignment', 'admin.php?section=keys',
        fn() => audit_rows($keys,
            "SELECT KeyCode AS [Key], BuildingText AS [Building], RoomID AS [Room],
                    KeyState AS [State], ISNULL(AssignedBy, '') AS [Assigned by]
             FROM gis.RoomKeys WHERE KeyState = 'Active' AND ISNULL(HolderName, '') = ''
             ORDER BY BuildingText, RoomID"));

    $add('keys_unplaced', 'Keys naming a room that is not on the plans', 'Keys and access', 'tidy',
        'Doors and whole-building readers belong here and always will: a Front Door is not a '
        . 'room. What is worth reading is anything that looks like a real room number, because '
        . 'that is either a plan gap or a typo in the register.',
        'Data → Where data comes from', 'admin.php?section=datafeeds',
        fn() => audit_rows($keys,
            "SELECT ISNULL(BuildingText, '(none given)') AS [Building], RoomID AS [Room named],
                    COUNT(*) AS [Keys]
             FROM gis.RoomKeys
             WHERE RoomCode IS NULL
               AND RoomID NOT LIKE '%Door%' AND RoomID NOT LIKE '%door%'
               AND RoomID NOT LIKE 'IC[0-9]' AND RoomID NOT LIKE 'IC[0-9][0-9]'
               AND RoomID NOT LIKE '%card%' AND RoomID NOT LIKE '%Card%'
               AND RoomID NOT LIKE '%aster%' AND RoomID NOT LIKE '%Gate%'
             GROUP BY BuildingText, RoomID ORDER BY COUNT(*) DESC"));

    $add('keys_no_survey', 'Keys with no survey against them', 'Keys and access', 'tidy',
        'The key cannot be reported or chased by survey, so it falls outside anybody sweep of '
        . 'their own people.',
        'People and access → Key assignment', 'admin.php?section=keys',
        fn() => audit_rows($keys,
            "SELECT HolderName AS [Holder], BuildingText AS [Building], RoomID AS [Room],
                    KeyCode AS [Key], EmpStatus AS [Employment]
             FROM gis.RoomKeys WHERE KeyState = 'Active' AND ISNULL(SurveyCode, '') = ''
             ORDER BY HolderName"));

    // ============================================================ safety
    $add('safety_unplaced', 'Safety equipment not attached to a room', 'Safety', 'important',
        'An eyewash or a fume hood that is not on a room cannot appear in room detail, cannot be '
        . 'found in an emergency, and cannot be put on an inspection round.',
        'Data → Where data comes from (reload space data)', 'admin.php?section=datafeeds',
        fn() => audit_rows($space,
            "SELECT Kind AS [Kind], BldgNumber AS [Building], ISNULL(RoomID, '') AS [Room as recorded],
                    ISNULL(Descriptor, '') AS [Description], ISNULL(Tag, '') AS [Tag]
             FROM gis.Safety WHERE RoomCode IS NULL ORDER BY BldgNumber, Kind"));

    $add('safety_no_test', 'Safety equipment with no test or inspection date', 'Safety', 'important',
        'Eyewashes, showers and fume hoods are on a testing cycle. With no last test and no next '
        . 'due date there is nothing to prove the cycle is being kept and nothing to remind '
        . 'anybody.',
        'Space → Safety manifest', 'admin.php?section=safety',
        fn() => audit_rows($space,
            "SELECT Kind AS [Kind], BldgNumber AS [Building], ISNULL(RoomID, '') AS [Room],
                    ISNULL(Descriptor, '') AS [Description], ISNULL(Status, '') AS [Status]
             FROM gis.Safety
             WHERE ISNULL(LastTested, '') = '' AND ISNULL(NextDue, '') = ''
             ORDER BY BldgNumber, Kind"));

    $add('buildings_no_safety', 'Buildings with no safety equipment recorded at all',
        'Safety', 'important',
        'Every occupied building has extinguishers at least. A building with nothing recorded '
        . 'means the safety register has never covered it, not that it is safe.',
        'Space → Safety manifest', 'admin.php?section=safety',
        fn() => audit_rows($space,
            "SELECT b.BldgNumber AS [Building], b.Name AS [Name],
                    (SELECT COUNT(*) FROM gis.Rooms r WHERE r.BldgNumber = b.BldgNumber) AS [Rooms on the plans]
             FROM ref.Buildings b
             WHERE b.IsActive = 1
               AND EXISTS (SELECT 1 FROM gis.Rooms r WHERE r.BldgNumber = b.BldgNumber)
               AND NOT EXISTS (SELECT 1 FROM gis.Safety s WHERE s.BldgNumber = b.BldgNumber)
             ORDER BY b.SortKey"));

    // ================================================ people and routing
    $add('surveys_no_director', 'Units with no director or no administrative assistant recorded',
        'People and routing', 'blocker',
        'Space and key requests are decided by the director of the unit that owns them, and every '
        . 'notification also goes to them and to their administrative assistant. Until these are '
        . 'real people those requests have nobody to go to.',
        'People and access → Leadership from the org chart', 'admin.php?section=orgchart',
        fn() => audit_rows($work,
            "SELECT Code AS [Survey], Name AS [Name],
                    ISNULL(DirectorName, '(not set)') AS [Director],
                    ISNULL(DirectorEmail, '') AS [Director email],
                    ISNULL(ProxyName, '(not set)') AS [Administrative assistant],
                    ISNULL(ProxyEmail, '') AS [Assistant email]
             FROM ref.Surveys
             WHERE IsActive = 1 AND (ISNULL(DirectorEmail, '') = '' OR ISNULL(ProxyEmail, '') = '')
             ORDER BY Code"));

    $add('surveys_placeholder_director', 'Units whose director is still an example person',
        'People and routing', 'blocker',
        'The seat is filled, so the "no director" check passes, but it is filled by somebody the '
        . 'evaluation data invented. Approvals and notifications are going to a person who does '
        . 'not exist.',
        'People and access → Leadership from the org chart', 'admin.php?section=orgchart',
        fn() => audit_rows($work,
            "SELECT s.Code AS [Unit], s.DirectorName AS [Director recorded],
                    s.DirectorEmail AS [Email], s.ProxyName AS [Proxy recorded]
             FROM ref.Surveys s
             WHERE s.IsActive = 1
               AND EXISTS (SELECT 1 FROM app.Users u
                           WHERE u.Unit = 'EXAMPLE-DATA'
                             AND (u.Email = s.DirectorEmail OR u.Email = s.ProxyEmail
                                  OR u.DisplayName = s.DirectorName OR u.DisplayName = s.ProxyName))
             ORDER BY s.Code"));

    $add('surveys_no_admin', 'Units with nobody granted back end access',
        'People and routing', 'important',
        'Nobody from these units can see the administration side at all, so their requests can '
        . 'only be worked by somebody holding the admin role.',
        'People and access → Back end access', 'admin.php?section=access',
        fn() => audit_rows($work,
            "SELECT s.Code AS [Survey], s.Name AS [Name]
             FROM ref.Surveys s
             WHERE s.IsActive = 1
               AND NOT EXISTS (SELECT 1 FROM app.AdminAccess a
                               WHERE a.IsActive = 1 AND a.SurveyCode = s.Code)
             ORDER BY s.Code"));

    $add('org_approvers_no_access', 'People the org chart says may approve, with no access',
        'People and routing', 'tidy',
        'The org chart marks these people as able to approve for their unit, but they cannot see '
        . 'this system. Some of them should be able to; that is a judgement, not a default.',
        'People and access → Leadership from the org chart', 'admin.php?section=orgchart',
        fn() => audit_rows($work,
            "SELECT p.DisplayName AS [Person], p.NetID AS [NetID], p.SurveyCode AS [Survey],
                    p.OrgUnit AS [Unit], p.OrgRole AS [Org chart role]
             FROM ref.People p
             WHERE p.IsActive = 1 AND p.OrgRole LIKE '%approve%'
               AND NOT EXISTS (SELECT 1 FROM app.AdminAccess a
                               WHERE a.NetID = p.NetID AND a.IsActive = 1)
             ORDER BY p.SurveyCode, p.DisplayName"));

    $add('people_no_netid', 'People in the directory with no NetID', 'People and routing', 'tidy',
        'A person without a NetID cannot be told anything by this system and cannot be matched '
        . 'to a request, a key or a piece of property.',
        'Data → Where data comes from', 'admin.php?section=datafeeds',
        fn() => audit_rows($work,
            "SELECT DisplayName AS [Name], Email AS [Email], SurveyCode AS [Survey],
                    Org AS [Organisation]
             FROM ref.People WHERE IsActive = 1 AND ISNULL(NetID, '') = ''
             ORDER BY DisplayName"));

    $add('people_no_survey', 'People in the directory with no survey', 'People and routing', 'tidy',
        'Anything filed by or for these people cannot be routed to a director, so it '
        . 'lands nowhere in particular.',
        'Data → Where data comes from', 'admin.php?section=datafeeds',
        fn() => audit_rows($work,
            "SELECT DisplayName AS [Name], NetID AS [NetID], Email AS [Email],
                    ISNULL(Org, '') AS [Organisation], ISNULL(OrgPath, '') AS [Org path]
             FROM ref.People WHERE IsActive = 1 AND ISNULL(SurveyCode, '') = ''
             ORDER BY DisplayName"));

    // ========================================================== accounts
    $add('cfop_incomplete', 'Accounts with no principal investigator or fund manager',
        'Accounts', 'important',
        'An approval chain routes to those two people. An account missing either cannot open a '
        . 'funding approval, so a request charged to it stalls.',
        'Property → Accounts directory', 'admin.php?section=accounts',
        fn() => audit_rows($shop,
            "SELECT CfopCode AS [Account], SurveyCode AS [Survey], Description AS [Purpose],
                    AccountPI AS [Principal investigator], FundManager AS [Fund manager]
             FROM ref.CfopAccounts
             WHERE IsActive = 1 AND (ISNULL(AccountPI, '') = '' OR ISNULL(FundManager, '') = '')
             ORDER BY CfopCode"));

    $add('cfop_no_approver', 'Accounts whose approvers cannot be reached', 'Accounts', 'blocker',
        'A purchase charged to one of these cannot be approved: the person is named but there is '
        . 'no NetID for them, so the system has nobody to ask. The active grants report carries '
        . 'no NetID for a fund manager at all, so these are the ones the directory could not be '
        . 'matched to - usually somebody who has left, or a name written differently.',
        'Data → Where data comes from (reload accounts, which re-matches), or correct the name',
        'admin.php?section=datafeeds',
        fn() => audit_rows($shop,
            "SELECT CfopCode AS [Account], SurveyCode AS [Unit], Description AS [Purpose],
                    CASE WHEN ISNULL(AccountPINetID, '') = '' THEN AccountPI ELSE '' END
                        AS [Principal investigator not reachable],
                    CASE WHEN ISNULL(FundManagerNetID, '') = '' THEN FundManager ELSE '' END
                        AS [Fund manager not reachable]
             FROM ref.CfopAccounts
             WHERE IsActive = 1
               AND ((ISNULL(AccountPI, '') <> '' AND ISNULL(AccountPINetID, '') = '')
                 OR (ISNULL(FundManager, '') <> '' AND ISNULL(FundManagerNetID, '') = ''))
             ORDER BY CfopCode"));

    $add('cfop_no_survey', 'Accounts with no survey', 'Accounts', 'tidy',
        'The account cannot be attributed to a unit, so spending on it does not roll up anywhere.',
        'Property → Accounts directory', 'admin.php?section=accounts',
        fn() => audit_rows($shop,
            "SELECT CfopCode AS [Account], Description AS [Purpose], FundType AS [Fund type],
                    AccountPI AS [Principal investigator]
             FROM ref.CfopAccounts WHERE IsActive = 1 AND ISNULL(SurveyCode, '') = ''
             ORDER BY CfopCode"));

    $add('requests_unknown_account', 'Requests charged to an account not in the directory',
        'Accounts', 'important',
        'The account was typed rather than picked, or the directory has moved on. Either way the '
        . 'approval cannot find a fund manager and the charge cannot be checked.',
        'Data → Where data comes from (reload accounts)', 'admin.php?section=datafeeds',
        function () use ($shop, $space) {
            $known = $shop->query('SELECT CfopCode FROM ref.CfopAccounts')
                          ->fetchAll(PDO::FETCH_COLUMN);
            $known = array_flip(array_map('strtoupper', $known));
            $rows = [];
            foreach ([['work', 'wr.WorkRequests', 'work'], ['shop', 'sh.ShopRequests', 'shop']] as $s) {
                $q = db($s[0])->query(
                    "SELECT TicketNumber, CfopCode, Status FROM {$s[1]}
                     WHERE ISNULL(CfopCode, '') <> ''
                       AND Status NOT IN ('closed','merged','declined','denied')");
                foreach ($q as $r) {
                    if (!isset($known[strtoupper((string)$r['CfopCode'])])) {
                        $rows[] = ['Ticket' => (string)$r['TicketNumber'], 'Type' => $s[2],
                                   'Account as entered' => (string)$r['CfopCode'],
                                   'Status' => (string)$r['Status']];
                    }
                }
            }
            $q = $space->query(
                "SELECT TicketNumber, FundingCfop, Status FROM rq.SpaceRequests
                 WHERE ISNULL(FundingCfop, '') <> '' AND Status NOT IN ('closed','denied')");
            foreach ($q as $r) {
                if (!isset($known[strtoupper((string)$r['FundingCfop'])])) {
                    $rows[] = ['Ticket' => (string)$r['TicketNumber'], 'Type' => 'space',
                               'Account as entered' => (string)$r['FundingCfop'],
                               'Status' => (string)$r['Status']];
                }
            }
            return ['count' => count($rows), 'rows' => $rows,
                    'columns' => $rows ? array_keys($rows[0]) : []];
        });

    // ================================================ requests and flow
    $add('requests_no_room', 'Open requests whose room did not match the floor plans',
        'Requests', 'tidy',
        'The request still works, but it will not appear against the room on the map or in the '
        . 'room history, because the room was typed rather than chosen and did not match.',
        'Open the request and pick the room from the list', 'board.php',
        function () {
            $rows = [];
            foreach ([['work', 'wr.WorkRequests', 'RoomNumber', 'work'],
                      ['shop', 'sh.ShopRequests', 'RoomNumber', 'shop']] as [$d, $t, $col, $type]) {
                $q = db($d)->query(
                    "SELECT TicketNumber, BldgNumber, $col AS RoomText, Status FROM $t
                     WHERE RoomCode IS NULL AND ISNULL($col, '') <> ''
                       AND Status NOT IN ('closed','merged','declined','denied')");
                foreach ($q as $r) {
                    $rows[] = ['Ticket' => (string)$r['TicketNumber'], 'Type' => $type,
                               'Building' => (string)$r['BldgNumber'],
                               'Room as typed' => (string)$r['RoomText'],
                               'Status' => (string)$r['Status']];
                }
            }
            return ['count' => count($rows), 'rows' => $rows,
                    'columns' => $rows ? array_keys($rows[0]) : []];
        });

    $add('requests_stalled', 'Requests open with no movement for over 30 days',
        'Requests', 'important',
        'Nothing has happened to these for a month. Either they are waiting on somebody who does '
        . 'not know it, or they are finished and were never closed.',
        'Requests → Queue and triage', 'admin.php?section=queue',
        function () {
            $rows = [];
            foreach ([['work', 'wr.WorkRequests', 'work'], ['shop', 'sh.ShopRequests', 'shop'],
                      ['move', 'mv.MoveRequests', 'move']] as [$d, $t, $type]) {
                $q = db($d)->query(
                    "SELECT TicketNumber, Status, SurveyCode,
                            DATEDIFF(day, ISNULL(UpdatedAt, CreatedAt), SYSDATETIME()) AS Idle
                     FROM $t
                     WHERE Status NOT IN ('closed','merged','declined','denied','complete','verified')
                       AND DATEDIFF(day, ISNULL(UpdatedAt, CreatedAt), SYSDATETIME()) > 30");
                foreach ($q as $r) {
                    $rows[] = ['Ticket' => (string)$r['TicketNumber'], 'Type' => $type,
                               'Survey' => (string)$r['SurveyCode'],
                               'Status' => (string)$r['Status'],
                               'Days without movement' => (int)$r['Idle']];
                }
            }
            usort($rows, fn($a, $b) => $b['Days without movement'] <=> $a['Days without movement']);
            return ['count' => count($rows), 'rows' => $rows,
                    'columns' => $rows ? array_keys($rows[0]) : []];
        });

    $add('approvals_waiting', 'Approvals sitting undecided for over 14 days',
        'Requests', 'important',
        'Somebody is being waited on and may not know. Every one of these is a request that '
        . 'cannot move until a person acts.',
        'Requests → Approvals', 'admin.php?section=approvals',
        function () {
            $rows = [];
            $sets = [['work', 'wr.Approvals a JOIN wr.WorkRequests r ON r.WorkRequestID = a.WorkRequestID', 'work'],
                     ['shop', 'sh.Approvals a JOIN sh.ShopRequests r ON r.ShopRequestID = a.ShopRequestID', 'shop']];
            foreach ($sets as [$d, $from, $type]) {
                $q = db($d)->query(
                    "SELECT r.TicketNumber, a.StageCode, a.ApproverName,
                            DATEDIFF(day, a.CreatedAt, SYSDATETIME()) AS Waited
                     FROM $from
                     WHERE a.Decision IS NULL AND DATEDIFF(day, a.CreatedAt, SYSDATETIME()) > 14");
                foreach ($q as $r) {
                    $rows[] = ['Ticket' => (string)$r['TicketNumber'], 'Type' => $type,
                               'Stage' => (string)$r['StageCode'],
                               'Waiting on' => (string)$r['ApproverName'],
                               'Days waiting' => (int)$r['Waited']];
                }
            }
            $q = db('work')->query(
                "SELECT a.RequestType, a.StageCode, a.ApproverName,
                        DATEDIFF(day, a.CreatedAt, SYSDATETIME()) AS Waited
                 FROM rq.Approvals a
                 WHERE a.Decision IS NULL AND DATEDIFF(day, a.CreatedAt, SYSDATETIME()) > 14");
            foreach ($q as $r) {
                $rows[] = ['Ticket' => '(' . $r['RequestType'] . ' request)',
                           'Type' => (string)$r['RequestType'],
                           'Stage' => (string)$r['StageCode'],
                           'Waiting on' => (string)$r['ApproverName'],
                           'Days waiting' => (int)$r['Waited']];
            }
            usort($rows, fn($a, $b) => $b['Days waiting'] <=> $a['Days waiting']);
            return ['count' => count($rows), 'rows' => $rows,
                    'columns' => $rows ? array_keys($rows[0]) : []];
        });

    $add('requests_no_survey', 'Open requests with no survey', 'Requests', 'important',
        'Nothing routes without a survey: not the director notification, not the approval, not '
        . 'the reporting.',
        'Open the request and set the survey', 'board.php',
        function () {
            $rows = [];
            foreach ([['work', 'wr.WorkRequests', 'work'], ['shop', 'sh.ShopRequests', 'shop'],
                      ['move', 'mv.MoveRequests', 'move']] as [$d, $t, $type]) {
                $q = db($d)->query(
                    "SELECT TicketNumber, Status FROM $t
                     WHERE ISNULL(SurveyCode, '') = ''
                       AND Status NOT IN ('closed','merged','declined','denied')");
                foreach ($q as $r) {
                    $rows[] = ['Ticket' => (string)$r['TicketNumber'], 'Type' => $type,
                               'Status' => (string)$r['Status']];
                }
            }
            return ['count' => count($rows), 'rows' => $rows,
                    'columns' => $rows ? array_keys($rows[0]) : []];
        });

    // ============================================ delivery and settings
    $add('mail_not_sending', 'Email is being written to the outbox instead of sent',
        'Delivery', 'blocker',
        'Every notification and digest this system produces is being recorded and not delivered. '
        . 'Nothing reaches anybody until the relay details from campus IT are in place.',
        'People, Roles and Notifications', 'admin.php?section=people',
        function () {
            $mode = (string)setting('notify_mode', 'log');
            $rows = $mode === 'smtp' ? [] : [[
                'Setting' => 'notify_mode', 'Currently' => $mode,
                'Means' => $mode === 'log' ? 'written to the outbox and not sent'
                    : 'handed to the local mail function, which this server may not have']];
            return ['count' => count($rows), 'rows' => $rows,
                    'columns' => $rows ? array_keys($rows[0]) : []];
        });

    $add('events_no_recipients', 'Notification events with nobody listening',
        'Delivery', 'important',
        'These events fire and reach no one beyond the director of the owning unit. If somebody is '
        . 'act on them, they are not being told.',
        'People, Roles and Notifications', 'admin.php?section=people',
        function () use ($work) {
            $events = ['work_request_created', 'work_request_urgent', 'move_request_created',
                       'shop_request_created', 'space_request_created', 'key_request_created',
                       'approval_needed', 'status_changed'];
            $rows = [];
            foreach ($events as $e) {
                $st = $work->prepare(
                    'SELECT COUNT(*) FROM app.NotifyRules WHERE EventCode = ? AND IsActive = 1');
                $st->execute([$e]);
                if ((int)$st->fetchColumn() === 0) {
                    $rows[] = ['Event' => $e, 'Rules' => 0,
                               'Means' => 'only the director and their assistant are told'];
                }
            }
            return ['count' => count($rows), 'rows' => $rows,
                    'columns' => $rows ? array_keys($rows[0]) : []];
        });

    $add('digests_off', 'Nobody is receiving a digest', 'Delivery', 'tidy',
        'The digest is what stops work sitting unnoticed. With everybody off, the only prompt to '
        . 'act is somebody remembering to look.',
        'Preferences, or People, Roles and Notifications', 'preferences.php',
        function () use ($work) {
            $on = (int)$work->query(
                "SELECT COUNT(*) FROM app.DigestPreferences WHERE Frequency <> 'off'")->fetchColumn();
            $rows = $on > 0 ? [] : [['People with a digest turned on' => 0,
                'Default for new people' => (string)setting('digest_default_frequency', 'weekly')]];
            return ['count' => count($rows), 'rows' => $rows,
                    'columns' => $rows ? array_keys($rows[0]) : []];
        });

    // ============================================ sources and consistency
    $add('sources_stale', 'Campus exports past their refresh interval', 'Data sources', 'important',
        'The accounts, people, org chart and key register all go out of date quietly. Past their '
        . 'interval, what the forms offer is no longer what campus holds.',
        'Data → Where data comes from', 'admin.php?section=datafeeds',
        function () {
            require_once __DIR__ . '/datasources.php';
            $rows = [];
            foreach (ds_all() as $s) {
                if (ds_is_stale($s)) {
                    $age = ds_age_days($s['LastLoadedAt']);
                    $rows[] = ['Source' => (string)$s['Label'],
                               'Last loaded' => $age === null ? 'never' : $age . ' days ago',
                               'Refresh every' => (int)$s['RefreshDays'] . ' days',
                               'Where from' => (string)($s['SourceUrl'] ?: 'address not recorded')];
                }
            }
            return ['count' => count($rows), 'rows' => $rows,
                    'columns' => $rows ? array_keys($rows[0]) : []];
        });

    $add('example_data', 'Evaluation data still in place', 'Data sources', 'blocker',
        'These rows were loaded to make the screens worth looking at. They must be gone before '
        . 'anybody files a real request, or the two will be indistinguishable.',
        'Data → Example data', 'admin.php?section=examples',
        function () {
            $rows = [];
            foreach ([['work', 'wr.WorkRequests', 'work requests'],
                      ['move', 'mv.MoveRequests', 'move requests'],
                      ['shop', 'sh.ShopRequests', 'shop requests']] as [$d, $t, $label]) {
                $n = (int)db($d)->query(
                    "SELECT COUNT(*) FROM $t WHERE LegacyReference = 'EXAMPLE'")->fetchColumn();
                if ($n > 0) {
                    $rows[] = ['What' => $label, 'Rows' => $n,
                               'Marked' => "LegacyReference = 'EXAMPLE'"];
                }
            }
            $n = (int)db('work')->query(
                "SELECT COUNT(*) FROM app.Users WHERE Unit = 'EXAMPLE-DATA'")->fetchColumn();
            if ($n > 0) {
                $rows[] = ['What' => 'example sign-in accounts', 'Rows' => $n,
                           'Marked' => "app.Users.Unit = 'EXAMPLE-DATA'"];
            }
            // The example people were written into the directory as well,
            // where a lookup cannot tell them from staff.
            $n = (int)db('work')->query(
                "SELECT COUNT(*) FROM ref.People WHERE SourceLabel = 'EXAMPLE-DATA'")->fetchColumn();
            if ($n > 0) {
                $rows[] = ['What' => 'example people in the directory', 'Rows' => $n,
                           'Marked' => "ref.People.SourceLabel = 'EXAMPLE-DATA'"];
            }
            return ['count' => count($rows), 'rows' => $rows,
                    'columns' => $rows ? array_keys($rows[0]) : []];
        });

    $add('rooms_publish_drift', 'The room connector is out of step with the floor plans',
        'Data sources', 'important',
        'Requests join to rooms through ref.Rooms, which is published from the plans into all '
        . 'every database. Where they disagree, a request cannot find its room.',
        'Data → Publish and check', 'admin.php?section=refdata',
        function () {
            $gis = (int)db('space')->query('SELECT COUNT(*) FROM gis.Rooms')->fetchColumn();
            $rows = [];
            foreach (APP_DB_NAMES as $n) {
                try {
                    $c = (int)db($n)->query('SELECT COUNT(*) FROM ref.Rooms')->fetchColumn();
                    if ($c !== $gis) {
                        $rows[] = ['Database' => APP_DB_LABELS[$n],
                                   'Rooms published' => $c, 'Rooms on the plans' => $gis,
                                   'Difference' => $gis - $c];
                    }
                } catch (Throwable $e) { /* not present */ }
            }
            return ['count' => count($rows), 'rows' => $rows,
                    'columns' => $rows ? array_keys($rows[0]) : []];
        });

    $add('people_not_published', 'The people directory exists in only one database',
        'Data sources', 'tidy',
        'The lookups read the Work database so nothing is broken today, but the copies in Move '
        . 'and Shop hold a handful of stale rows that will mislead anybody reading them.',
        'Data → Publish and check', 'admin.php?section=refdata',
        function () {
            $rows = [];
            $counts = [];
            foreach (APP_DB_NAMES as $n) {
                try {
                    $counts[$n] = (int)db($n)->query('SELECT COUNT(*) FROM ref.People')->fetchColumn();
                } catch (Throwable $e) { $counts[$n] = 0; }
            }
            $max = max($counts);
            foreach ($counts as $n => $c) {
                if ($c < $max * 0.5) {
                    $rows[] = ['Database' => APP_DB_LABELS[$n], 'People here' => $c,
                               'People in the fullest copy' => $max];
                }
            }
            return ['count' => count($rows), 'rows' => $rows,
                    'columns' => $rows ? array_keys($rows[0]) : []];
        });

    $add('imports_staged', 'Imports staged and never committed', 'Data sources', 'tidy',
        'Somebody started loading past data and stopped. The rows are sitting in staging where '
        . 'nothing can see them.',
        'Data → Import past data', 'admin.php?section=import',
        fn() => audit_rows($work,
            "SELECT ImportBatchID AS [Batch], SourceSystem AS [Source], FileName AS [File],
                    RowsRead AS [Rows read], RowsAccepted AS [Accepted], Status AS [Status],
                    CreatedAt AS [Started]
             FROM app.ImportBatches
             WHERE Status NOT IN ('committed', 'reversed') ORDER BY CreatedAt DESC"));

    // ============================================================ estate
    $add('agreements_expired', 'Leases being occupied past their end date', 'Estate', 'blocker',
        'Occupation is continuing on paper that has run out. Either the renewal exists and '
        . 'was never recorded, or the survey is in the building on goodwill.',
        'Space → Leases and Agreements', 'admin.php?section=estate',
        fn() => audit_rows($space,
            "SELECT b.Name AS [Building], a.Reference AS [Reference],
                    a.EndsOn AS [Ended], a.TermsBasis AS [Terms],
                    DATEDIFF(day, a.EndsOn, CAST(SYSDATETIME() AS DATE)) AS [Days past]
             FROM est.Agreements a JOIN ref.Buildings b ON b.BuildingID = a.BuildingID
             WHERE a.Status = 'expired' ORDER BY a.EndsOn"));

    $add('agreements_no_end', 'Agreements with no end date recorded', 'Estate', 'important',
        'Without the date nothing can warn before it expires, which is how five leases were '
        . 'found already years past their end. The dates are on the lease documents, not in '
        . 'any export.',
        'Space → Leases and Agreements', 'admin.php?section=estate',
        fn() => audit_rows($space,
            "SELECT b.Name AS [Building], a.AgreementKind AS [Kind],
                    ISNULL(a.Reference, '') AS [Reference], ISNULL(a.TermsBasis, '') AS [Terms],
                    b.Ownership AS [Owner]
             FROM est.Agreements a JOIN ref.Buildings b ON b.BuildingID = a.BuildingID
             WHERE a.EndsOn IS NULL AND a.Status <> 'ended' ORDER BY b.Name"));

    $add('buildings_no_owner', 'Off campus buildings with no owner recorded', 'Estate',
        'important',
        'Until the owner is known it cannot be said whether an agreement is even needed, so '
        . 'the building sits outside the lease register entirely.',
        'Space → Buildings and Rooms', 'admin.php?section=space',
        fn() => audit_rows($space,
            "SELECT BldgNumber AS [Building], Name AS [Name],
                    ISNULL(LocationClass, '') AS [Location]
             FROM ref.Buildings
             WHERE IsActive = 1 AND ISNULL(Ownership, '') = ''
               AND ISNULL(LocationClass, '') <> 'On campus'
             ORDER BY Name"));

    $add('inventory_no_purchase_doc', 'Property with no purchase document', 'Property', 'tidy',
        'The item cannot be tied to how it was bought. C3PO has nothing for these either, so '
        . 'the answer, where it exists, is on paper purchase orders.',
        'Property → Equipment', 'admin.php?section=equipment',
        fn() => audit_rows($equipment,
            "SELECT PropertyTag AS [Tag], Description AS [Item], Cost AS [Cost],
                    ISNULL(AcquiredOn, '') AS [Acquired]
             FROM gis.Inventory
             WHERE Status = 'active' AND ISNULL(PurchaseDocument, '') = ''
             ORDER BY Cost DESC"));

    // Blockers first, then by how much of it there is.
    usort($out, function ($a, $b) {
        $s = AUDIT_SEVERITY[$a['severity']] <=> AUDIT_SEVERITY[$b['severity']];
        return $s !== 0 ? $s : ($b['count'] ?? 0) <=> ($a['count'] ?? 0);
    });
    return $out;
}

/**
 * Coverage rather than faults: how complete each thing is, as a
 * percentage. A fault list says what is broken; this says how far along
 * the filling-in is, which is what shows progress week to week.
 */
function audit_coverage(): array
{
    $work = db('work');
    $shop = db('shop');
    $keysDb = db('keys');
    $fleetDb = db('fleet');
    $space = db('space');
    $equipment = db('equipment');

    $pct = function (int $have, int $total): array {
        return ['have' => $have, 'total' => $total,
                'pct' => $total > 0 ? (int)round(100 * $have / $total) : 0];
    };

    // A query that cannot run used to come back as zero, which reads as a
    // real answer: after the split every figure here quietly said 0 per
    // cent of 0 and nothing said why. A failure is now carried through to
    // the display as a failure.
    $failed = [];
    $one = function (PDO $pdo, string $sql) use (&$failed): int {
        try {
            return (int)$pdo->query($sql)->fetchColumn();
        } catch (Throwable $e) {
            $failed[] = preg_replace('/\s+/', ' ', $e->getMessage());
            return -1;
        }
    };

    $out = [];
    $rooms = $one($space, 'SELECT COUNT(*) FROM gis.Rooms');
    $assignable = $one($space, 'SELECT COUNT(*) FROM gis.Rooms r WHERE ' . AUDIT_ASSIGNABLE_ROOM);
    $out['Assignable rooms with somebody answerable'] = $pct($one($space,
        "SELECT COUNT(DISTINCT r.RoomCode) FROM gis.Rooms r
         LEFT JOIN gis.RoomResponsibility rr ON rr.RoomCode = r.RoomCode
         WHERE ISNULL(rr.SurveyCode, r.SurveyCode) <> '' AND " . AUDIT_ASSIGNABLE_ROOM),
        $assignable);
    // Measured against the rooms that need one, not against every room.
    $hazard = $one($space, 'SELECT COUNT(*) FROM gis.Rooms r WHERE ' . AUDIT_HAZARD_ROOM);
    $out['Rooms that can hurt somebody, with a safety contact'] = $pct($one($space,
        "SELECT COUNT(*) FROM gis.Rooms r
         JOIN gis.RoomResponsibility rr ON rr.RoomCode = r.RoomCode
         WHERE ISNULL(rr.SafetyContact,'') <> '' AND " . AUDIT_HAZARD_ROOM), $hazard);
    $out['Rooms with a floor area'] = $pct($one($space,
        'SELECT COUNT(*) FROM gis.Rooms WHERE ISNULL(AreaSqFt, 0) > 0'), $rooms);

    $items = $one($equipment, "SELECT COUNT(*) FROM gis.Inventory WHERE Status = 'active'");
    $out['Property attached to a mapped room'] = $pct($one($equipment,
        "SELECT COUNT(*) FROM gis.Inventory WHERE Status='active' AND RoomCode IS NOT NULL"), $items);
    $out['Property with somebody responsible'] = $pct($one($equipment,
        "SELECT COUNT(*) FROM gis.Inventory WHERE Status='active' AND ISNULL(Person,'') <> ''"), $items);
    $out['Property with an acquisition date'] = $pct($one($equipment,
        "SELECT COUNT(*) FROM gis.Inventory WHERE Status='active' AND ISNULL(AcquiredOn,'') <> ''"), $items);
    $out['Property with a cost'] = $pct($one($equipment,
        "SELECT COUNT(*) FROM gis.Inventory WHERE Status='active' AND Cost IS NOT NULL"), $items);

    $keyCount = $one($keysDb, "SELECT COUNT(*) FROM gis.RoomKeys WHERE KeyState = 'Active'");
    $out['Active keys attached to a room'] = $pct($one($keysDb,
        "SELECT COUNT(*) FROM gis.RoomKeys WHERE KeyState='Active' AND RoomCode IS NOT NULL"), $keyCount);
    $out['Active keys held by current staff'] = $pct($one($keysDb,
        "SELECT COUNT(*) FROM gis.RoomKeys WHERE KeyState='Active' AND EmpStatus <> 'Terminated'"), $keyCount);

    $safety = $one($space, 'SELECT COUNT(*) FROM gis.Safety');
    $out['Safety equipment attached to a room'] = $pct($one($space,
        'SELECT COUNT(*) FROM gis.Safety WHERE RoomCode IS NOT NULL'), $safety);
    $out['Safety equipment with a test date'] = $pct($one($space,
        "SELECT COUNT(*) FROM gis.Safety WHERE ISNULL(LastTested,'') <> '' OR ISNULL(NextDue,'') <> ''"),
        $safety);

    $acc = $one($shop, 'SELECT COUNT(*) FROM ref.CfopAccounts WHERE IsActive = 1');
    $out['Accounts whose approvers can be reached'] = $pct($one($shop,
        "SELECT COUNT(*) FROM ref.CfopAccounts WHERE IsActive=1
         AND ISNULL(AccountPINetID,'') <> '' AND ISNULL(FundManagerNetID,'') <> ''"), $acc);
    $out['Accounts with a PI and a fund manager'] = $pct($one($shop,
        "SELECT COUNT(*) FROM ref.CfopAccounts WHERE IsActive=1
         AND ISNULL(AccountPI,'') <> '' AND ISNULL(FundManager,'') <> ''"), $acc);

    // The fleet list moved out of the shop database with the vehicles.
    $fleetCount = $one($fleetDb, 'SELECT COUNT(*) FROM ref.FleetVehicles WHERE IsActive = 1');
    $out['Vehicles with a year, plate and VIN'] = $pct($one($fleetDb,
        "SELECT COUNT(*) FROM ref.FleetVehicles WHERE IsActive=1 AND VehicleYear IS NOT NULL
         AND ISNULL(PlateNumber,'') <> '' AND ISNULL(VIN,'') <> ''"), $fleetCount);
    $out['Vehicles with a home building or survey'] = $pct($one($fleetDb,
        "SELECT COUNT(*) FROM ref.FleetVehicles WHERE IsActive=1
         AND (ISNULL(HomeBldgNumber,'') <> '' OR ISNULL(SurveyCode,'') <> '')"), $fleetCount);

    $surveys = $one($work, 'SELECT COUNT(*) FROM ref.Surveys WHERE IsActive = 1');
    $out['Units with a director and an assistant'] = $pct($one($work,
        "SELECT COUNT(*) FROM ref.Surveys WHERE IsActive=1
         AND ISNULL(DirectorEmail,'') <> '' AND ISNULL(ProxyEmail,'') <> ''"), $surveys);

    // A figure built from a query that failed is not a figure.
    foreach ($out as $label => $row) {
        if ($row['have'] < 0 || $row['total'] < 0) {
            $out[$label] = ['have' => 0, 'total' => 0, 'pct' => 0, 'failed' => true];
        }
    }
    if ($failed) {
        $out['_errors'] = array_values(array_unique($failed));
    }

    return $out;
}

/** Everything outstanding as one flat CSV, for working through offline. */
function audit_csv_stream(): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="pri-facilities-data-to-fix-'
        . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, csv_row(['Area', 'Severity', 'Finding', 'Total in this finding',
                   'Why it matters', 'Where it gets fixed', 'Detail']));
    foreach (audit_all() as $c) {
        if (!$c['count']) {
            continue;
        }
        foreach ($c['rows'] as $r) {
            $bits = [];
            foreach ($r as $k => $v) {
                $bits[] = $k . ': ' . (string)$v;
            }
            fputcsv($out, csv_row([$c['category'], $c['severity'], $c['title'], $c['count'],
                           $c['why'], $c['fix_where'], implode('; ', $bits)]));
        }
    }
    fclose($out);
}
