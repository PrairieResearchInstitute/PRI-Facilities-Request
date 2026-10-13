<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/scoring.php';
require_once __DIR__ . '/tickets.php';
require_once __DIR__ . '/purchase.php';

/**
 * Example data for evaluating the interface. Every example row is marked
 * so it can be removed again without touching real submissions: users get
 * the EXAMPLE_TAG in their Unit column, and requests carry
 * LegacyReference = 'EXAMPLE'.
 */

const EXAMPLE_TAG = 'EXAMPLE-DATA';

function example_users(): array
{
    return [
        ['jmartin',  'Julia Martin',      'INHS', 'staff'],
        ['bkeller',  'Ben Keller',        'INHS', 'staff'],
        ['snakamura','Sara Nakamura',     'INHS', 'coordinator'],
        ['dpatel',   'Devi Patel',        'ISGS', 'staff'],
        ['rocampo',  'Rafael Ocampo',     'ISGS', 'staff'],
        ['lschultz', 'Lena Schultz',      'ISWS', 'staff'],
        ['tgarrow',  'Tess Garrow',       'ISWS', 'coordinator'],
        ['mwhitfield','Maya Whitfield',   'ISAS', 'staff'],
        ['cdelgado', 'Carlos Delgado',    'ISAS', 'staff'],
        ['ahoffman', 'Anna Hoffman',      'ISTC', 'staff'],
        ['pberger',  'Paul Berger',       'OED',  'approver'],
        ['kmyers',   'Karen Myers',       'OED',  'approver'],
        ['jthompson','Matt Thompson',     'OED',  'coordinator'],
        // The fund managers the example accounts route to.
        ['evoss',    'Ellen Voss',        'INHS', 'approver'],
        ['mlane',    'Marcus Lane',       'ISGS', 'approver'],
        ['praman',   'Priya Raman',       'ISWS', 'approver'],
        ['teckhart', 'Tom Eckhart',       'ISAS', 'approver'],
        ['dfinance', 'Dana Ruiz',         'OED',  'approver'],
    ];
}

/**
 * (code, survey, PI, PI NetID, fund manager, fund manager NetID)
 *
 * The NetIDs matter as much as the names. A purchase asks the principal
 * investigator and the fund manager of every account it draws on, and it
 * asks them by NetID - so an account without them is an account nobody
 * can approve, and the example set would demonstrate a dead end.
 */
function example_cfops(): array
{
    return [
        ['1-305000-624001-191300-A00', 'INHS', 'Dr. Julia Martin',   'jmartin',    'Ellen Voss',   'evoss'],
        ['1-305100-624002-191300-A01', 'INHS', 'Dr. Ben Keller',     'bkeller',    'Ellen Voss',   'evoss'],
        ['1-306000-631004-191400-A00', 'ISGS', 'Dr. Devi Patel',     'dpatel',     'Marcus Lane',  'mlane'],
        ['1-306200-631009-191400-A05', 'ISGS', 'Dr. Rafael Ocampo',  'rocampo',    'Marcus Lane',  'mlane'],
        ['1-307000-642003-191500-A00', 'ISWS', 'Dr. Lena Schultz',   'lschultz',   'Priya Raman',  'praman'],
        ['1-308000-655007-191600-A00', 'ISAS', 'Dr. Maya Whitfield', 'mwhitfield', 'Tom Eckhart',  'teckhart'],
        ['1-309000-661002-191700-A00', 'ISTC', 'Dr. Anna Hoffman',   'ahoffman',   'Priya Raman',  'praman'],
        ['1-300000-600001-190000-A00', 'OED',  'Dr. Paul Berger',    'pberger',    'Ellen Voss',   'evoss'],
    ];
}

/** (buildingNumber, typeName, title, description, severity, life, specimen, room, location, ageDays, status, confirmers) */
function example_work(): array
{
    return [
        ['1073', 'Water, plumbing, drainage', 'Standing water across the basement hallway',
         'Water is spreading from under the mechanical room door into the basement corridor. About twenty feet of floor is wet and it is still growing. Collections carts are staged in that hallway.',
         3, 0, 1, 'B12', 'basement corridor outside the mechanical room', 4, 'in_progress',
         ['bkeller', 'snakamura', 'dpatel']],

        ['1280', 'Cold storage and specimen climate', 'Cold room alarm sounding at Kaskaskia',
         'The walk-in cold room is alarming and reading 12 degrees C, up from 4. Tissue samples and voucher specimens are inside. Nobody else is on site today.',
         3, 0, 1, '104', 'walk-in cold room, north wall', 1, 'triaged', []],

        ['0109', 'Electrical and power', 'Third floor east corridor lights out',
         'The entire east corridor on the third floor is dark. Emergency lighting is on but the overhead fixtures are dead. Started this morning.',
         2, 0, 0, '', 'third floor east corridor', 9, 'open',
         ['jmartin', 'mwhitfield', 'ahoffman', 'rocampo', 'lschultz']],

        ['1104', 'HVAC and environmental control', 'No cooling in the director suite',
         'Air handler appears to be running but only warm air is coming from the vents. Office temperatures are in the low eighties.',
         2, 0, 0, '150', 'director suite, south offices', 6, 'scheduled', ['tgarrow']],

        ['0187', 'Fire, alarm, life safety', 'Exit sign dark above the south stairwell',
         'The illuminated exit sign over the south stairwell door is completely dark. Battery backup does not appear to engage when tested.',
         3, 1, 0, '', 'south stairwell, second floor landing', 2, 'open', ['jmartin']],

        ['0206', 'Spills and hazardous materials', 'Solvent spill in the fume hood, lab 210',
         'Approximately 500 mL of acetone spilled inside the fume hood during a transfer. Hood is running and the area is taped off. Need waste pickup and a wipe-down.',
         3, 1, 0, '210', 'fume hood 3, west bench', 1, 'triaged', ['ahoffman']],

        ['1139', 'Doors, locks, access', 'Shipping dock door will not latch',
         'The overhead personnel door at the shipping dock does not latch when closed. It can be pushed open from outside. This is the receiving entrance.',
         2, 0, 0, '', 'shipping and receiving dock, personnel door', 12, 'open', ['lschultz', 'tgarrow']],

        ['0321', 'Structure, envelope, interior', 'Roof leak over the core storage bay',
         'Water is dripping onto the top rack of core boxes in the northeast bay. Buckets are placed but the cardboard is already wet.',
         3, 0, 1, '', 'northeast bay, core storage', 3, 'in_progress', ['dpatel', 'rocampo']],

        ['1279', 'Remote site utilities', 'Well pump cycling at Forbes Biological Station',
         'The well pump is short cycling and the pressure tank is not holding. Station has intermittent water. Field crews are staged here through next week.',
         2, 0, 0, '', 'pump house behind the main building', 7, 'open', []],

        ['0110', 'Network and telecommunications', 'Dead network jack in room 118',
         'The wall jack in room 118 has no link light on any cable or laptop. The neighboring office works fine.',
         1, 0, 0, '118', 'north wall jack', 5, 'open', []],

        ['0134', 'Research and collections systems', 'Greenhouse zone 2 misting system failed',
         'The misting solenoid in zone 2 is not opening on schedule. Seedling trays are drying out and need hand watering twice daily until fixed.',
         2, 0, 1, '', 'greenhouse zone 2', 8, 'on_hold', ['jmartin']],

        ['0313', 'Pest and wildlife', 'Mice in the storage building specimen cabinets',
         'Droppings found on two shelves near the north wall and one cabinet gasket is chewed through. Need exclusion and traps before this reaches the dermestid colony.',
         2, 0, 1, '', 'north wall shelving, Storage Building 1', 14, 'open', ['bkeller']],

        ['0128', 'Grounds, roads, exterior', 'Pothole in the ARL parking lot entrance',
         'A pothole about eighteen inches across has opened at the lot entrance. Vehicles are swerving into the oncoming lane to avoid it.',
         2, 0, 0, '', 'parking lot entrance off Griffith Drive', 21, 'open', ['rocampo']],

        ['1073', 'Custodial and waste', 'Recycling not collected in the west wing',
         'The recycling bins on the second floor west wing have not been emptied in over a week and are overflowing into the hallway.',
         1, 0, 0, '', 'second floor west wing', 10, 'work_done', []],

        ['0109', 'Eyewash and safety fixtures', 'Eyewash station certification overdue, lab 220',
         'The tag on the eyewash station in lab 220 shows the last inspection was fourteen months ago. Flow test also runs weak on the left nozzle.',
         3, 1, 0, '220', 'lab 220, sink station', 5, 'triaged', ['mwhitfield']],

        ['1108', 'Elevator, lift, hoist', 'Freight elevator out of service',
         'The freight elevator stops between floors two and three and the door does not open. Nobody is inside. Sample transport is now going up the stairs.',
         2, 0, 0, '', 'freight elevator, all floors', 2, 'in_progress', ['lschultz']],
    ];
}

function example_moves(): array
{
    return [
        ['jmartin', 'INHS', 5, 0, 'Lab consolidation into the Forbes basement wing.', [
            ['move', 'Lab equipment', 'Leica dissecting scope', 'P442081', '', 'DM4-22913', '1073', '210', '1073', 'B14', 'jmartin', 'bkeller', 0],
            ['move', 'Furniture', 'Four-drawer lateral file cabinet', '', '', '', '1073', '210', '1073', 'B14', 'jmartin', 'jmartin', 0],
            ['surplus', 'Furniture', 'Two damaged task chairs', '', '', '', '1073', '210', '', '', 'jmartin', '', 0],
            ['trash', 'Other', 'Broken specimen shelving, particle board', '', '', '', '1073', '210', '', '', 'jmartin', '', 0],
        ]],
        ['dpatel', 'ISGS', 9, 1, 'Staff office relocation, includes workstation.', [
            ['move', 'Computer / IT equipment', 'Dell Precision workstation and two monitors', 'P551204', 'PRI-2291', 'DL77201', '0321', '145', '0128', '210', 'dpatel', 'dpatel', 1],
            ['move', 'Furniture', 'Standing desk, electric', '', '', '', '0321', '145', '0128', '210', 'dpatel', 'dpatel', 0],
            ['move', 'Boxes / documents', 'Eight boxes of project files', '', '', '', '0321', '145', '0128', '210', 'dpatel', 'dpatel', 0],
        ]],
        ['lschultz', 'ISWS', 12, 0, 'Field equipment staging for the summer season.', [
            ['move', 'Field equipment', 'Two ISCO automatic samplers', 'P338812', '', 'ISCO-6712-4', '1110', '105', '1522', 'Barn', 'lschultz', 'tgarrow', 0],
            ['move', 'Field equipment', 'Sonde calibration kit', '', 'PRI-4417', '', '1110', '105', '1522', 'Barn', 'lschultz', 'tgarrow', 0],
        ]],
        ['mwhitfield', 'ISAS', 3, 0, 'Curation materials to the repository.', [
            ['transfer', 'Shelving / storage', 'Six archival cabinets', '', 'PRI-8890', '', '0110', '12', '0330', 'Bay 3', 'mwhitfield', 'cdelgado', 0],
            ['transfer', 'Boxes / documents', 'Forty artifact boxes, Cahokia project', '', '', '', '0110', '12', '0330', 'Bay 3', 'mwhitfield', 'cdelgado', 0],
        ]],
        ['ahoffman', 'ISTC', 7, 0, 'Analytical instrument relocation.', [
            ['move', 'Lab equipment', 'GC-MS bench unit', 'P229004', 'PRI-1120', 'AGL-5977B', '0206', '118', '0206', '224', 'ahoffman', 'ahoffman', 0],
            ['move', 'Computer / IT equipment', 'Instrument control PC', 'P229005', '', 'HP-88112', '0206', '118', '0206', '224', 'ahoffman', 'ahoffman', 1],
        ]],
        ['bkeller', 'INHS', 14, 0, 'Herbarium cabinet shift.', [
            ['move', 'Shelving / storage', 'Twelve herbarium cases', '', 'PRI-5510', '', '0313', 'Main', '0311', 'Main', 'bkeller', 'bkeller', 0],
        ]],
        ['rocampo', 'ISGS', 6, 0, 'Core lab equipment cleanout.', [
            ['surplus', 'Lab equipment', 'Obsolete core saw', 'P110238', '', 'CS-4400', '0315', '101', '', '', 'rocampo', '', 0],
            ['trash', 'Other', 'Three pallets of broken core trays', '', '', '', '0315', '101', '', '', 'rocampo', '', 0],
            ['move', 'Lab equipment', 'Replacement core saw', 'P553001', 'PRI-7781', 'CS-9100', '0315', '101', '0305', '12', 'rocampo', 'rocampo', 0],
        ]],
        ['cdelgado', 'ISAS', 21, 1, 'Field office move to Central Illinois.', [
            ['move', 'Computer / IT equipment', 'Two laptops and a docking station', 'P667712', '', 'LT-2291', '0110', '8', '2571', '104', 'cdelgado', 'cdelgado', 1],
            ['move', 'Furniture', 'Desk and bookcase', '', '', '', '0110', '8', '2571', '104', 'cdelgado', 'cdelgado', 0],
        ]],
        ['tgarrow', 'ISWS', 4, 0, 'Shop tool transfer.', [
            ['transfer', 'Other', 'Drill press', 'P884420', '', 'DP-770', '1139', 'Shop', '1111', 'Garage', 'tgarrow', 'lschultz', 0],
        ]],
        ['jmartin', 'INHS', 11, 0, 'Wildlife building storage cleanup.', [
            ['trash', 'Furniture', 'Rusted metal shelving units', '', '', '', '1099', 'Barn', '', '', 'jmartin', '', 0],
            ['surplus', 'Appliance', 'Non-working chest freezer', 'P442299', '', 'FZ-1180', '1099', 'Barn', '', '', 'jmartin', '', 0],
        ]],
        ['snakamura', 'INHS', 8, 0, 'Entomology lab reorganization.', [
            ['move', 'Lab equipment', 'Two stereo microscopes', 'P447781', 'PRI-3302', 'SM-4471', '0187', '112', '0187', '118', 'snakamura', 'snakamura', 0],
            ['move', 'Shelving / storage', 'Insect drawer cabinet', '', 'PRI-3303', '', '0187', '112', '0187', '118', 'snakamura', 'snakamura', 0],
        ]],
        ['dpatel', 'ISGS', 16, 0, 'Wetlands office equipment.', [
            ['move', 'Computer / IT equipment', 'Plotter and print server', 'P992001', '', 'HP-T2600', '0507', '3', '1501', 'Garage', 'dpatel', 'rocampo', 1],
        ]],
        ['ahoffman', 'ISTC', 2, 0, 'Urgent bench relocation before inspection.', [
            ['move', 'Furniture', 'Two lab benches', '', '', '', '0206', '210', '0206', '212', 'ahoffman', 'ahoffman', 0],
        ]],
    ];
}

function example_shop(): array
{
    return [
        ['jmartin', '1-305000-624001-191300-A00', 6, 'Fabricate twelve aluminum specimen trays to fit the new cabinets.',
         'Existing trays do not fit the replacement cabinets purchased this year. Custom trays let the collection be rehoused without buying new cabinets, saving roughly eight thousand dollars.', '1073', 'B14', 2400.00, 'awaiting_approval'],
        ['dpatel', '1-306000-631004-191400-A00', 10, 'Repair the hydraulic core splitter, replace seals and cylinder.',
         'The splitter is the only unit able to handle four-inch core. Repair returns the lab to full throughput before the summer drilling program starts in June.', '0315', '101', 5600.00, 'awaiting_approval'],
        ['lschultz', '1-307000-642003-191500-A00', 14, 'Build four weather station mounting frames, galvanized.',
         'New monitoring sites require frames rated for sustained wind loading. Shop fabrication meets the specification at half the vendor quote.', '1522', '', 3100.00, 'approved'],
        ['mwhitfield', '1-308000-655007-191600-A00', 21, 'Construct eight archival storage shelves for the repository bay.',
         'Incoming Cahokia collection requires shelving sized to the bay depth. Standard commercial shelving wastes twenty percent of the floor area.', '0330', 'Bay 3', 4200.00, 'in_shop'],
        ['ahoffman', '1-309000-661002-191700-A00', 5, 'Modify the fume hood sash stop and install an airflow alarm.',
         'Safety inspection flagged the sash travel. Modification brings the hood into compliance and avoids taking the lab out of service.', '0206', '210', 1800.00, 'awaiting_approval'],
        ['rocampo', '1-306200-631009-191400-A05', 30, 'Fabricate a custom sample cart with locking casters.',
         'Moving core boxes by hand has caused two back injuries this year. A purpose-built cart addresses the hazard identified in the safety review.', '0321', '', 1500.00, 'approved'],
        ['bkeller', '1-305100-624002-191300-A01', 12, 'Repair and rebalance the herbarium compactor track.',
         'The compactor binds and requires two people to move a carriage. Repair restores single-person operation and prevents damage to the specimens shelved above.', '0313', 'Main', 7800.00, 'awaiting_approval'],
        ['cdelgado', '1-308000-655007-191600-A00', 9, 'Build a field screening station with adjustable legs.',
         'Field crews screen soil on uneven ground; adjustable legs prevent the tipping that destroyed last season\'s unit.', '2571', '104', 950.00, 'complete'],
        ['tgarrow', '1-307000-642003-191500-A00', 3, 'Weld a replacement trailer hitch mount for the sampling trailer.',
         'The existing mount is cracked and unsafe to tow. Field sampling stops entirely until this is repaired.', '1139', 'Shop', 600.00, 'in_shop'],
        ['jmartin', '1-305000-624001-191300-A00', 25, 'Install a compressed air drop at bench four.',
         'New sorting equipment requires compressed air at the bench. The nearest existing drop is thirty feet away across a walkway.', '0187', '112', 2200.00, 'awaiting_approval'],
        ['pberger', '1-300000-600001-190000-A00', 45, 'Fabricate and install donor recognition panels in the lobby.',
         'Recognition panels were promised in the capital campaign. Shop fabrication matches the existing lobby materials better than an outside vendor could.', '0109', 'Lobby', 12500.00, 'awaiting_approval'],
        ['ahoffman', '1-309000-661002-191700-A00', 18, 'Repair the walk-in environmental chamber door seal and hinge.',
         'Chamber cannot hold set point with the seal failing, putting long-duration experiments at risk of loss.', '0206', '118', 3400.00, 'approved'],
        ['lschultz', '1-307000-642003-191500-A00', 7, 'Machine twenty replacement sonde deployment brackets.',
         'Brackets are no longer sold by the manufacturer. In-house machining keeps sixteen monitoring stations operating.', '1110', '105', 1750.00, 'complete'],
    ];
}

/**
 * (buyer, survey, vendor, method, destination, building, room, justification,
 *  how far it got, [items], [funding])
 *
 * Four purchases, deliberately at four different points, because the
 * screens that matter are the ones in the middle: a request nobody has
 * answered yet, one half answered, one sitting with Finance, and one
 * approved with a piece of equipment still waiting for its number.
 */
function example_purchases(): array
{
    return [
        ['jmartin', 'INHS', 'Fisher Scientific', 'regular_po', 'PRI Central Receiving',
         '1073', '1027',
         'Replacement balance for the analytical bench. The current one no longer holds '
         . 'calibration and the herbarium weighings depend on it.',
         'nobody',
         [['S-4200A', 'Analytical balance, 0.1 mg readability', 1, 'each', 4250.00, '126010', 1],
          ['GL-90', 'Nitrile gloves, powder free, case of 1000', 4, 'case', 62.50, '121500', 0]],
         [['1-305000-624001-191300-A00', 100]]],

        ['dpatel', 'ISGS', 'Grainger', 'ibuy_shopper', 'PRI Central Receiving',
         '1216', '221',
         'Consumables for the core logging bench: sleeves, caps and labels for the summer '
         . 'drilling programme.',
         'half',
         [['5XR21', 'Core sleeve, 3in, box of 50', 12, 'box', 88.00, '121500', 0],
          ['4TF77', 'End caps, 3in, bag of 100', 6, 'bag', 21.00, '121500', 0]],
         [['1-306000-631004-191400-A00', 60], ['1-306200-631009-191400-A05', 40]]],

        ['lschultz', 'ISWS', 'YSI Incorporated', 'regular_po', 'PRI Central Receiving',
         '1110', '105',
         'Two multiparameter sondes to replace units retired after the 2025 field season. '
         . 'Without them the continuous monitoring sites go dark in May.',
         'finance',
         [['EXO2-01', 'EXO2 multiparameter sonde', 2, 'each', 9400.00, '127000', 1],
          ['599110', 'Calibration standards kit', 1, 'kit', 340.00, '121500', 0]],
         [['1-307000-642003-191500-A00', 100]]],

        ['mwhitfield', 'ISAS', 'Leica Microsystems', 'regular_po', 'Exception',
         '1215', '116',
         'Stereo microscope with camera for the artifact photography station. Approved out '
         . 'of the curation account at the spring meeting.',
         'approved',
         [['S9i-CAM', 'Stereo microscope with integrated camera', 1, 'each', 6800.00, '127000', 1],
          ['ST-ARM', 'Boom stand and ring light', 1, 'each', 1150.00, '126010', 0]],
         [['1-308000-655007-191600-A00', 100]]],
    ];
}

/**
 * Build the example purchases and walk each one as far as its stage says.
 *
 * They are walked rather than written flat, so what the screens show is
 * what the engine actually does: the same approvals, the same events, the
 * same equipment expectations a real purchase would raise.
 */
function examples_purchases_load(): int
{
    $made = 0;
    foreach (example_purchases() as [$buyer, $survey, $vendor, $method, $dest, $bldg, $room,
                                     $why, $stage, $items, $funds]) {
        $exists = db('purchasing')->prepare(
            "SELECT COUNT(*) FROM rq.PurchaseRequests
              WHERE LegacyReference = 'EXAMPLE' AND RequestedByNetID = ? AND VendorName = ?");
        $exists->execute([$buyer, $vendor]);
        if ((int)$exists->fetchColumn() > 0) {
            continue;
        }

        $person = null;
        foreach (example_users() as [$netid, $name, $sv, $role]) {
            if ($netid === $buyer) {
                $person = ['NetID' => $netid, 'DisplayName' => $name, 'Role' => $role,
                           'UserID' => null, 'SurveyCode' => $sv];
            }
        }
        if (!$person) {
            continue;
        }

        $head = [
            'staff_name' => $person['DisplayName'], 'staff_netid' => $buyer, 'survey' => $survey,
            'req_date' => date('n/j/Y', strtotime('-' . (3 + $made * 5) . ' days')),
            'purchase_type' => $method, 'destination' => $dest, 'bldg' => $bldg, 'room' => $room,
            'justification' => $why, 'notes' => '',
            'vendor_name' => $vendor, 'vendor_web' => '', 'vendor_addr1' => '',
            'vendor_addr2' => '', 'vendor_city' => '', 'vendor_state' => '', 'vendor_zip' => '',
            'vendor_phone' => '', 'vendor_notes' => '',
            'split' => count($funds) > 1 ? 'percent' : 'dollar',
        ];
        $rows = [];
        foreach ($items as [$cat, $desc, $qty, $units, $cost, $acct, $equip]) {
            $rows[] = ['catalog' => $cat, 'description' => $desc, 'quantity' => $qty,
                       'units' => $units, 'unit_cost' => $cost,
                       'Amount' => round($qty * $cost, 2), 'account_code' => $acct,
                       'is_equipment' => $equip, 'url' => ''];
        }
        $total = purchase_total($rows);
        $fundRows = [];
        foreach ($funds as [$code, $share]) {
            $acct = purchase_account($code);
            if (!$acct) {
                continue 2;
            }
            $fundRows[] = ['_account' => $acct, 'cfop' => $code,
                           'amount' => count($funds) > 1 ? $share : $total,
                           'pi_netid' => (string)$acct['AccountPINetID'],
                           'pi_email' => (string)$acct['AccountPIEmail'],
                           'fm_netid' => (string)$acct['FundManagerNetID'],
                           'fm_email' => (string)$acct['FundManagerEmail']];
        }

        $res = purchase_create($head, $rows, $fundRows, $person);
        $id = (int)$res['id'];
        db('purchasing')->prepare("UPDATE rq.PurchaseRequests SET LegacyReference = 'EXAMPLE'
                             WHERE PurchaseRequestID = ?")->execute([$id]);
        $made++;
        if ($stage === 'nobody') {
            continue;
        }

        // How far it walked. "half" answers the first seat of the first
        // fund and stops, which is the state a chaser actually looks at.
        $seats = [];
        foreach (purchase_funds($id) as $f) {
            $seats[] = [(int)$f['PurchaseFundingID'], 'pi', (string)$f['PiNetID'],
                        (string)$f['PiName'], (string)$f['SurveyCode']];
            $seats[] = [(int)$f['PurchaseFundingID'], 'fm', (string)$f['FmNetID'],
                        (string)$f['FmName'], (string)$f['SurveyCode']];
        }
        $take = $stage === 'half' ? 1 : count($seats);
        foreach (array_slice($seats, 0, $take) as [$fid, $seat, $netid, $name, $sv]) {
            if ($netid === '') {
                continue;
            }
            purchase_decide($id, $fid, $seat, 'approved',
                $seat === 'pi' ? 'Yes, this is on my account.' : 'Funds are available.',
                ['NetID' => $netid, 'DisplayName' => $name, 'Role' => 'approver',
                 'UserID' => null, 'SurveyCode' => $sv]);
        }
        if ($stage === 'approved') {
            purchase_finance_decide($id, 'approved', 'Coded and released to purchasing.',
                ['NetID' => 'dfinance', 'DisplayName' => 'Dana Ruiz', 'Role' => 'approver',
                 'UserID' => null, 'SurveyCode' => 'OED']);
        }
    }
    return $made;
}

function examples_load(): string
{
    $work = db('work');
    $move = db('move');
    $shop = db('shop');

    // --- users, replicated into all three databases ---
    foreach (example_users() as [$netid, $name, $survey, $role]) {
        foreach (['work', 'move', 'shop'] as $n) {
            $pdo = db($n);
            $st = $pdo->prepare('SELECT UserID FROM app.Users WHERE NetID = ?');
            $st->execute([$netid]);
            if ($st->fetchColumn() === false) {
                $pdo->prepare('INSERT INTO app.Users (NetID, DisplayName, Email, SurveyCode, Unit, Role)
                               VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$netid, $name, $netid . '@illinois.edu', $survey, EXAMPLE_TAG, $role]);
            }
        }
    }

    // --- CFOP directory (shop only) ---
    foreach (example_cfops() as [$code, $survey, $pi, $piNet, $fm, $fmNet]) {
        $st = $shop->prepare('SELECT COUNT(*) FROM ref.CfopAccounts WHERE CfopCode = ?');
        $st->execute([$code]);
        if ((int)$st->fetchColumn() === 0) {
            $shop->prepare(
                'INSERT INTO ref.CfopAccounts (CfopCode, SurveyCode, AccountPI, AccountPINetID,
                    AccountPIEmail, FundManager, FundManagerNetID, FundManagerEmail)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$code, $survey, $pi, $piNet, $piNet . '@illinois.edu',
                           $fm, $fmNet, $fmNet . '@illinois.edu']);
        } else {
            // An earlier load made these without approvers, and an account
            // nobody can approve is worse than no account at all.
            $shop->prepare(
                "UPDATE ref.CfopAccounts
                    SET AccountPINetID = ?, AccountPIEmail = ?,
                        FundManagerNetID = ?, FundManagerEmail = ?
                  WHERE CfopCode = ? AND ISNULL(AccountPINetID, '') = ''")
                ->execute([$piNet, $piNet . '@illinois.edu', $fmNet, $fmNet . '@illinois.edu',
                           $code]);
        }
    }

    $uid = function (PDO $pdo, string $netid): int {
        $st = $pdo->prepare('SELECT UserID FROM app.Users WHERE NetID = ?');
        $st->execute([$netid]);
        return (int)$st->fetchColumn();
    };

    // --- work requests ---
    $reporters = ['jmartin', 'dpatel', 'lschultz', 'mwhitfield', 'ahoffman', 'bkeller',
                  'rocampo', 'cdelgado', 'snakamura', 'tgarrow'];
    $made = 0;
    foreach (example_work() as $i => $w) {
        [$bldg, $typeName, $title, $desc, $sev, $life, $spec, $room, $loc, $age, $status, $confirmers] = $w;
        $reporter = $reporters[$i % count($reporters)];
        $b = $work->prepare('SELECT BuildingID, BldgNumber FROM ref.Buildings WHERE BldgNumber = ?');
        $b->execute([$bldg]);
        $bRow = $b->fetch();
        $t = $work->prepare('SELECT WorkTypeID, OwnerID FROM ref.WorkTypes WHERE Name = ?');
        $t->execute([$typeName]);
        $tRow = $t->fetch();
        if (!$bRow || !$tRow) {
            continue;
        }
        $survey = null;
        foreach (example_users() as [$n2, , $s2]) {
            if ($n2 === $reporter) {
                $survey = $s2;
            }
        }
        $ticket = next_ticket_number('work');
        $created = date('Y-m-d H:i:s', time() - $age * 86400);
        $work->prepare(
            'INSERT INTO wr.WorkRequests (TicketNumber, Title, Description, BuildingID, BldgNumber,
                WrittenLocation, RoomNumber, WorkTypeID, OwnerID, SurveyCode, ReportedSeverity,
                LifeSafety, SpecimenRisk, Status, CreatedByUserID, CreatedByNetID, SearchBlob,
                LegacyReference, CreatedAt, UpdatedAt)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'EXAMPLE\', ?, ?)'
        )->execute([
            $ticket, $title, $desc, $bRow['BuildingID'], $bRow['BldgNumber'], $loc ?: null,
            $room ?: null, $tRow['WorkTypeID'], $tRow['OwnerID'], $survey, $sev, $life, $spec,
            $status, $uid($work, $reporter), $reporter,
            mb_strtolower($title . ' ' . $desc . ' ' . $loc . ' ' . $room), $created, $created,
        ]);
        $wid = (int)$work->lastInsertId();
        record_status('work', $wid, null, 'open', $uid($work, $reporter), 'Filed');
        if ($status !== 'open') {
            record_status('work', $wid, 'open', $status, $uid($work, 'snakamura'), 'Example progression');
        }
        foreach ($confirmers as $c) {
            $cSurvey = null;
            foreach (example_users() as [$n3, , $s3]) {
                if ($n3 === $c) {
                    $cSurvey = $s3;
                }
            }
            try {
                $work->prepare('INSERT INTO wr.Corroborations (WorkRequestID, UserID, SurveyCode) VALUES (?, ?, ?)')
                     ->execute([$wid, $uid($work, $c), $cSurvey]);
            } catch (Throwable $e) { /* unique constraint: ignore */ }
        }
        // A couple of notes so the detail view has conversation in it.
        if ($i % 3 === 0) {
            $work->prepare('INSERT INTO app.Notes (RequestID, UserID, Body, IsInternal) VALUES (?, ?, ?, 0)')
                 ->execute([$wid, $uid($work, 'snakamura'), 'Walked the area this morning; confirmed and photographed.']);
        }
        if ($i % 4 === 0) {
            $work->prepare('INSERT INTO app.Notes (RequestID, UserID, Body, IsInternal) VALUES (?, ?, ?, 1)')
                 ->execute([$wid, $uid($work, 'jthompson'), 'Internal: waiting on a part, ETA Friday.']);
        }
        scoring_recompute($wid);
        $made++;
    }

    // --- an approval chain on one expensive example ---
    $expensive = $work->query("SELECT TOP 1 WorkRequestID FROM wr.WorkRequests
                               WHERE LegacyReference = 'EXAMPLE' AND Status = 'triaged'
                               ORDER BY WorkRequestID DESC")->fetchColumn();
    if ($expensive) {
        $work->prepare('UPDATE wr.WorkRequests SET EstimateAmount = 31500.00, TriagedSeverity = 3,
                        Status = \'awaiting_approval\', TriagedAt = SYSDATETIME() WHERE WorkRequestID = ?')
             ->execute([(int)$expensive]);
        $ins = $work->prepare('INSERT INTO wr.Approvals (WorkRequestID, StageCode, StageOrder, ApproverName, Decision, DecisionAt, Comment)
                               VALUES (?, ?, ?, ?, ?, ?, ?)');
        $ins->execute([(int)$expensive, 'fund_manager', 1, 'Ellen Voss', 'approved',
                       date('Y-m-d H:i:s', time() - 86400), 'Funds available in the maintenance line.']);
        $ins->execute([(int)$expensive, 'director', 2, 'Dr. Paul Berger', null, null, null]);
        scoring_recompute((int)$expensive);
    }

    // --- move requests ---
    $moveMade = 0;
    foreach (example_moves() as [$netid, $survey, $days, $person, $notes, $items]) {
        $hasTech = 0;
        foreach ($items as $it) {
            if ($it[12]) {
                $hasTech = 1;
            }
        }
        $ticket = next_ticket_number('move');
        $move->prepare(
            'INSERT INTO mv.MoveRequests (TicketNumber, RequestedByUserID, RequestedByNetID, SurveyCode,
                NeededByDate, IsPersonMoving, HasTechItems, AdditionalNotes, Status, LegacyReference)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'EXAMPLE\')'
        )->execute([$ticket, $uid($move, $netid), $netid, $survey,
                    date('Y-m-d', time() + $days * 86400), $person, $hasTech, $notes,
                    $hasTech ? 'it_review' : 'inventory_review']);
        $mid = (int)$move->lastInsertId();

        $bId = function (string $num) use ($move) {
            if ($num === '') {
                return [null, null];
            }
            $st = $move->prepare('SELECT BuildingID, BldgNumber FROM ref.Buildings WHERE BldgNumber = ?');
            $st->execute([$num]);
            $r = $st->fetch();
            return $r ? [(int)$r['BuildingID'], $r['BldgNumber']] : [null, null];
        };
        $insItem = $move->prepare(
            'INSERT INTO mv.MoveItems (MoveRequestID, MoveType, ItemType, PTag, PRITag, SerialNumber,
                Description, FromBuildingID, FromBldgNumber, FromRoom, ToBuildingID, ToBldgNumber,
                ToRoom, CurrentCustodian, NewCustodian, IsTechItem)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($items as $it) {
            [$mt, $itype, $desc2, $ptag, $pritag, $serial, $fromB, $fromR, $toB, $toR, $cur, $new, $tech] = $it;
            [$fbId, $fbNum] = $bId($fromB);
            [$tbId, $tbNum] = $bId($toB);
            $insItem->execute([$mid, $mt, $itype, $ptag ?: null, $pritag ?: null, $serial ?: null, $desc2,
                               $fbId, $fbNum, $fromR ?: null, $tbId, $tbNum, $toR ?: null,
                               $cur ?: null, $new ?: null, $tech]);
        }
        $rev = $move->prepare('INSERT INTO mv.Reviews (MoveRequestID, StageCode, StageOrder, IsRequired, SkipReason)
                               VALUES (?, ?, ?, ?, ?)');
        $rev->execute([$mid, 'it', 1, $hasTech, $hasTech ? null : 'No technology items on this request']);
        $rev->execute([$mid, 'inventory', 2, 1, null]);
        $rev->execute([$mid, 'facilities', 3, 1, null]);
        record_status('move', $mid, null, $hasTech ? 'it_review' : 'inventory_review', $uid($move, $netid), 'Filed');
        $moveMade++;
    }

    // --- shop requests ---
    $shopMade = 0;
    foreach (example_shop() as [$netid, $cfop, $days, $desc3, $just, $bldg2, $room2, $amount, $status2]) {
        $acct = $shop->prepare('SELECT * FROM ref.CfopAccounts WHERE CfopCode = ?');
        $acct->execute([$cfop]);
        $a = $acct->fetch();
        if (!$a) {
            continue;
        }
        $bs = $shop->prepare('SELECT BuildingID, BldgNumber FROM ref.Buildings WHERE BldgNumber = ?');
        $bs->execute([$bldg2]);
        $bRow2 = $bs->fetch();
        $ticket = next_ticket_number('shop');
        $shop->prepare(
            'INSERT INTO sh.ShopRequests (TicketNumber, RequestedByUserID, RequestedByNetID, NeededByDate,
                CfopCode, SurveyCode, AccountPI, FundManager, WorkDescription, BusinessJustification,
                BuildingID, BldgNumber, RoomNumber, EstimateAmount, Status, LegacyReference)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'EXAMPLE\')'
        )->execute([$ticket, $uid($shop, $netid), $netid, date('Y-m-d', time() + $days * 86400),
                    $a['CfopCode'], $a['SurveyCode'], $a['AccountPI'], $a['FundManager'], $desc3, $just,
                    $bRow2['BuildingID'] ?? null, $bRow2['BldgNumber'] ?? null, $room2 ?: null,
                    $amount, $status2]);
        $sid = (int)$shop->lastInsertId();

        $stage = $shop->prepare('INSERT INTO sh.Approvals (ShopRequestID, StageCode, StageOrder, ApproverName,
                                 Decision, DecisionAt, Comment) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $advanced = in_array($status2, ['approved', 'in_shop', 'complete'], true);
        $stage->execute([$sid, 'account_pi', 1, $a['AccountPI'], $advanced ? 'approved' : null,
                         $advanced ? date('Y-m-d H:i:s', time() - 3 * 86400) : null,
                         $advanced ? 'Approved as submitted.' : null]);
        $stage->execute([$sid, 'fund_manager', 2, $a['FundManager'], $advanced ? 'approved' : null,
                         $advanced ? date('Y-m-d H:i:s', time() - 2 * 86400) : null,
                         $advanced ? 'Funds confirmed.' : null]);
        $stage->execute([$sid, 'shop_supervisor', 3, 'Bob Bryant',
                         in_array($status2, ['in_shop', 'complete'], true) ? 'approved' : null,
                         in_array($status2, ['in_shop', 'complete'], true) ? date('Y-m-d H:i:s', time() - 86400) : null,
                         in_array($status2, ['in_shop', 'complete'], true) ? 'Scheduled into the shop queue.' : null]);
        record_status('shop', $sid, null, $status2, $uid($shop, $netid), 'Filed; copied to PI for review');
        $shopMade++;
    }

    $purchases = examples_purchases_load();

    return "Example data loaded: $made work requests, $moveMade move requests, $shopMade shop requests, "
         . "$purchases purchase requests, " . count(example_users()) . ' staff accounts, and '
         . count(example_cfops()) . ' CFOP accounts.';
}

function examples_clear(): string
{
    $work = db('work');
    $move = db('move');
    $shop = db('shop');
    $n = 0;

    $ids = $work->query("SELECT WorkRequestID FROM wr.WorkRequests WHERE LegacyReference = 'EXAMPLE'")->fetchAll();
    foreach ($ids as $r) {
        $id = (int)$r['WorkRequestID'];
        foreach (['DELETE FROM app.Notes WHERE RequestID = ?',
                  'DELETE FROM app.Attachments WHERE RequestID = ?',
                  'DELETE FROM app.StatusHistory WHERE RequestID = ?',
                  'DELETE FROM wr.Corroborations WHERE WorkRequestID = ?',
                  'DELETE FROM wr.Approvals WHERE WorkRequestID = ?'] as $sql) {
            $work->prepare($sql)->execute([$id]);
        }
        $work->prepare('DELETE FROM wr.DedupDecisions WHERE CandidateID = ? OR ResultingID = ?')->execute([$id, $id]);
        $work->prepare('DELETE FROM wr.WorkRequests WHERE WorkRequestID = ?')->execute([$id]);
        $n++;
    }
    foreach ($move->query("SELECT MoveRequestID FROM mv.MoveRequests WHERE LegacyReference = 'EXAMPLE'")->fetchAll() as $r) {
        $id = (int)$r['MoveRequestID'];
        foreach (['DELETE FROM app.Notes WHERE RequestID = ?',
                  'DELETE FROM app.StatusHistory WHERE RequestID = ?',
                  'DELETE FROM mv.MoveItems WHERE MoveRequestID = ?',
                  'DELETE FROM mv.Reviews WHERE MoveRequestID = ?',
                  'DELETE FROM mv.MoveRequests WHERE MoveRequestID = ?'] as $sql) {
            $move->prepare($sql)->execute([$id]);
        }
        $n++;
    }
    foreach ($shop->query("SELECT ShopRequestID FROM sh.ShopRequests WHERE LegacyReference = 'EXAMPLE'")->fetchAll() as $r) {
        $id = (int)$r['ShopRequestID'];
        foreach (['DELETE FROM app.Notes WHERE RequestID = ?',
                  'DELETE FROM app.StatusHistory WHERE RequestID = ?',
                  'DELETE FROM sh.Approvals WHERE ShopRequestID = ?',
                  'DELETE FROM sh.ShopRequests WHERE ShopRequestID = ?'] as $sql) {
            $shop->prepare($sql)->execute([$id]);
        }
        $n++;
    }
    // A purchase can have created a property record. That record is the
    // one thing here with a life of its own, so it goes too - otherwise
    // clearing the examples leaves invented equipment in real rooms.
    // Every table below lives in PURCHASING, and the property record it
    // created lives in EQUIPMENT. This whole branch used to run on $work:
    // the first SELECT threw, because rq.PurchaseRequests is not in the
    // work register at all - which is the only reason it never did harm.
    // The one statement that WOULD have succeeded is the attachment
    // delete, because app.Attachments exists in all ten registers and a
    // PurchaseRequestID collides happily with a WorkRequestID. That is a
    // real request's attachments, deleted on an id that merely matched.
    $purch = db('purchasing');
    $equip = db('equipment');
    foreach ($purch->query("SELECT PurchaseRequestID FROM rq.PurchaseRequests
                            WHERE LegacyReference = 'EXAMPLE'")->fetchAll() as $r) {
        $id = (int)$r['PurchaseRequestID'];
        // No statement can span two databases, so the inventory ids are
        // read where they are recorded and removed where they live. (After
        // the move to Postgres these become two schemas, and this stays
        // two steps for the same reason.)
        $items = $purch->prepare('SELECT InventoryID FROM rq.PurchaseItems
                                   WHERE PurchaseRequestID = ? AND InventoryID IS NOT NULL');
        $items->execute([$id]);
        foreach ($items->fetchAll() as $item) {
            $equip->prepare('DELETE FROM gis.Inventory WHERE InventoryID = ?')
                  ->execute([(int)$item['InventoryID']]);
        }
        foreach (['DELETE FROM app.Attachments WHERE RequestID = ?',
                  'DELETE FROM rq.PurchaseEvents WHERE PurchaseRequestID = ?',
                  'DELETE FROM rq.PurchaseFunding WHERE PurchaseRequestID = ?',
                  'DELETE FROM rq.PurchaseItems WHERE PurchaseRequestID = ?',
                  'DELETE FROM rq.PurchaseRequests WHERE PurchaseRequestID = ?'] as $sql) {
            $purch->prepare($sql)->execute([$id]);
        }
        $n++;
    }

    return "Example data removed: $n request(s). Staff accounts and CFOP entries were left in place "
         . 'so anything you filed yourself still resolves.';
}
