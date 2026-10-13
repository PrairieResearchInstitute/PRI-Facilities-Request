<?php
declare(strict_types=1);

/**
 * Demonstration fills: a form, pre-populated with faux data, for the
 * briefing's job-aid slides.
 *
 * Requesting any intake form with ?demo=<key> seeds the fields from the
 * fixture below WITHOUT submitting anything - the page renders exactly
 * as it would look mid-fill, which is what a job aid needs to show.
 * Nothing is written; the guard below refuses anything but a GET.
 *
 * The same fixture carries the step list the slide prints beside the
 * screenshot, so the picture and the words can never drift apart: they
 * are one definition, read by the app and by tools/make_presentation.php.
 *
 * Each entry:
 *   page   the form to open
 *   post   field values seeded into $_POST for rendering
 *   shot   the capture's basename in storage/shots
 *   steps  [section heading, [field -> value, ...]] as printed
 *   pipe   [stages, activeIndex, where it lands] for the foot strip
 *   icon   register icon for the title band
 */

const DEMO_FILLS = [

    // ---- Work ---------------------------------------------------------
    'work_order' => [
        'page' => 'new_work.php',
        'shot' => 'wex_work_order',
        'icon' => 'reg-work',
        'pipe' => [['File', 'Triage', 'Assign', 'Close'], 0, 'Work · wr.WorkRequests'],
        'post' => [
            'building_id' => 3,
            'type_id' => 1,
            'title' => 'Standing water in the basement hallway',
            'nature_id' => 1,
            'severity' => 2,
            'location' => 'Basement corridor outside 1012, by the west stair',
            'description' => 'Water is pooling across the corridor floor and has been '
                . 'there since at least yesterday afternoon. No obvious source above.',
        ],
        'steps' => [
            ['1. Where and what', [
                'Building → Forbes Natural History Building',
                'Work type → Water, plumbing, drainage',
                'One line → "Standing water in the basement hallway"',
            ]],
            ['2. Details', [
                'Nature of work → Repair a failure',
                'Floor and room → picked from the plans, or written out for a corridor',
                'Severity → 2, normal',
            ]],
            ['3. Money', [
                'Left empty - nothing here is chargeable',
            ]],
            ['4. Photographs', [
                'One photo from the phone; more may be added later',
            ]],
        ],
    ],

    'work_gate' => [
        'page' => 'new_work.php',
        'shot' => 'wex_work_gate',
        'icon' => 'reg-work',
        'pipe' => [['File', 'Triage', 'Assign', 'Close'], 0, 'Work · wr.WorkRequests'],
        'post' => [
            'building_id' => 3,
            'type_id' => 7,
            'title' => 'Water stain spreading on the ceiling, room 1046',
            'nature_id' => 1,
            'severity' => 2,
            'room' => '1046',
        ],
        'steps' => [
            ['1. Where and what', [
                'Building → Forbes Natural History Building',
                'Work type → Structure, envelope, interior',
                'One line → "Water stain spreading on the ceiling, room 1046"',
            ]],
            ['The gate answers first', [
                'Typing the building lists the open requests already filed for it',
                'The basement-corridor ticket is offered - it matches on "water"',
            ]],
            ['Saying no', [
                'One click: not the same problem',
                'The full form opens and the report files as its own ticket',
                'Shown this, said no - recorded on both tickets',
            ]],
        ],
    ],

    'work_storm' => [
        'page' => 'new_work.php',
        'full' => true,
        'shot' => 'wex_work_storm',
        'icon' => 'reg-work',
        'pipe' => [['File', 'Triage', 'Assign', 'Close'], 0, 'Work · wr.WorkRequests'],
        'post' => [
            'building_id' => 10,
            'type_id' => 7,
            'title' => 'Wind damage to the field station roof, north slope',
            'nature_id' => 10,
            'severity' => 3,
            'location' => 'North roof slope and the equipment porch beneath it',
            'estimate' => '18000',
            'cfop' => '1-305500-621001-191100',
            'description' => 'Overnight storm lifted roofing from the north slope. '
                . 'Called in at the time; filed the following morning with photographs.',
        ],
        'steps' => [
            ['1. Where and what', [
                'Building → INHS Great Rivers Field Station',
                'Work type → Structure, envelope, interior',
                'One line → "Wind damage to the field station roof, north slope"',
            ]],
            ['2. Details', [
                'Nature of work → Storm or incident response',
                'Severity → 3 - the nature scores it at maximum in triage anyway',
                'Written location → the north slope and the porch beneath it',
            ]],
            ['3. Money', [
                'Estimate → $18,000',
                'CFOP → 1-305500-621001-191100, checked against the directory',
            ]],
            ['4. Photographs', [
                'Photographs from the morning after - the insurance record starts here',
            ]],
        ],
    ],

    'work_eyewash' => [
        'page' => 'new_work.php',
        'full' => true,
        'shot' => 'wex_work_eyewash',
        'icon' => 'reg-incidents',
        'pipe' => [['File', 'Triage', 'Assign', 'Close'], 0, 'Work · wr.WorkRequests'],
        'post' => [
            'building_id' => 3,
            'type_id' => 6,
            'title' => 'Eyewash in 1031 will not flush - failed at inspection',
            'nature_id' => 2,
            'severity' => 3,
            'room' => '1031',
            'description' => 'Annual test: the left head will not flush and the bowl '
                . 'is blocked. Station tagged out of service until repaired.',
        ],
        'steps' => [
            ['1. Where and what', [
                'Building → Forbes Natural History Building',
                'Work type → Eyewash and safety fixtures',
                'One line → "Eyewash in 1031 will not flush - failed at inspection"',
            ]],
            ['2. Details', [
                'Nature of work → Correct a hazard',
                'Room → 1031, from the floor plan',
                'Severity → 3',
            ]],
            ['What it does to the manifest', [
                'The failure is dated, so the manifest\'s test date stays honest',
                'Once the safety build lands, one filing raises the work order and '
                    . 'flags the manifest row together',
            ]],
        ],
    ],

    // ---- Move ---------------------------------------------------------
    'move' => [
        'page' => 'new_move.php',
        'shot' => 'wex_move',
        'icon' => 'reg-move',
        'pipe' => [['File', 'Confirm items', 'Move', 'Registers updated'], 0,
                   'Move · mv.MoveItems'],
        'post' => [
            'from_bldg' => '1073',
            'from_room' => '1027',
            'to_bldg' => '1073',
            'to_room' => '1041',
            'person_moving' => 'Aquatic Ecology - lab relocation',
            'needed_by' => '2026-09-18',
            'notes' => 'Freezer needs two people and a pallet jack; incubators to be '
                . 'powered down the evening before.',
        ],
        'steps' => [
            ['1. Where it is now', [
                'Building → Forbes Natural History Building',
                'Room → 1027, from the floor plan',
            ]],
            ['2. Where it is going', [
                'Building → the same building, Forbes Natural History',
                'Room → 1041',
            ]],
            ['3. What is moving', [
                'The rows arrive already filled from the inventory for 1027',
                'Tick what is going: freezer P10E35946, the incubators, the microscopes',
                'Anything untagged is added as a free row',
            ]],
            ['4. When and anything else', [
                'Needed by → 18 September 2026',
                'Notes → handling instructions for the freezer and incubators',
            ]],
        ],
    ],

    // ---- Shop ---------------------------------------------------------
    'shop_fab' => [
        'page' => 'new_shop.php',
        'shot' => 'wex_shop_fab',
        'icon' => 'reg-shop',
        'pipe' => [['File', 'Account check', 'Shop floor', 'Close'], 0,
                   'Shop · sh.ShopRequests'],
        'post' => [
            'request_kind' => 'fabrication',
            'requested_by' => 'Blobaum, Devin',
            'cfop' => '1-302100-621001-191100',
            'description' => 'Custom sampling sled, welded frame with removable '
                . 'sample trays, sized to the ISWS boat davit.',
            'dimensions' => '1200 x 700 x 400 mm',
            'materials' => 'Aluminium box section, stainless fasteners, HDPE trays',
            'justification' => 'No commercial sled fits the davit; the existing one '
                . 'is beyond repair.',
            'needed_by' => '2026-10-02',
        ],
        'steps' => [
            ['Requested by', [
                'Person → from the directory typeahead, with their survey',
            ]],
            ['Account', [
                'CFOP → 1-302100-621001-191100',
                'The account brings its PI and fund manager, so approval routes itself',
            ]],
            ['Work', [
                'Kind → Fabrication',
                'Description → custom sampling sled for the ISWS boat davit',
                'Dimensions → 1200 x 700 x 400 mm',
                'Materials → aluminium box section, stainless fasteners, HDPE trays',
                'Needed by → 2 October 2026',
            ]],
            ['Drawings', [
                'Sketch or CAD file attached to the request, not emailed separately',
            ]],
        ],
    ],

    // ---- Space --------------------------------------------------------
    'space' => [
        'page' => 'new_space.php',
        'shot' => 'wex_space',
        'icon' => 'reg-space',
        'pipe' => [['File', 'Committee', 'Director', 'Room assigned'], 0,
                   'Space · rq.SpaceRequests'],
        'post' => [
            'survey_code' => 'INHS',
            'submitted_by' => 'Garrow, Tess',
            'requester_email' => 'configure-me@example.edu',
            'kind' => 'additional',
            'space_type' => 'lab',
            'space_use' => 'Wet lab bench work and specimen processing',
            'cur_bldg' => '1073',
            'cur_room' => '1027',
            'req_bldg' => '1073',
            'req_room' => '1041',
            'staff_affected' => '4',
            'people' => 'Aquatic Ecology group - 4 staff, 1 graduate assistant',
            'justification' => 'The group has outgrown 1027 for wet work; 1041 next '
                . 'door is vacant and already plumbed.',
            'potential_costs' => 'Bench replacement and one additional sink',
            'needed_by' => '2027-01-05',
        ],
        'steps' => [
            ['1. Who is asking', [
                'Survey → INHS',
                'Submitted by → Garrow, Tess, from the directory',
            ]],
            ['2. What are you asking for', [
                'Kind → Additional space',
                'Type → Laboratory; use → wet bench work and specimen processing',
            ]],
            ['3. Space you hold now', [
                'Building and room → Forbes, 1027, from the floor plan',
            ]],
            ['4. Space you are asking for', [
                'Building and room → Forbes, 1041',
                'People affected → 4 staff and one graduate assistant',
            ]],
            ['5. Why, and what it costs', [
                'Justification → the paragraph the committee actually reads',
                'Potential costs → bench replacement and one additional sink',
            ]],
        ],
    ],

    // ---- Keys ---------------------------------------------------------
    'key' => [
        'page' => 'new_key.php',
        'shot' => 'wex_key',
        'icon' => 'reg-keys',
        'pipe' => [['Request', 'Issue', 'Return', 'Audit'], 0, 'Keys · gis.RoomKeys'],
        'post' => [
            'kind' => 'new',
            'holder_name' => 'Whitaker, Alex',
            'holder_netid' => 'awhitak',
            'holder_survey' => 'INHS',
            'emp_status' => 'Active',
            'bldg' => '1073',
            'room' => '1027',
            'key_type' => 'room',
            'qty' => '1',
            'justification' => 'New technician in Aquatic Ecology; needs routine '
                . 'access to the wet lab for sample processing.',
            'needed_by' => '2026-08-24',
        ],
        'steps' => [
            ['1. Who the key is for', [
                'Holder → Whitaker, Alex, from the directory typeahead',
                'Their NetID, survey and employment status arrive with them',
            ]],
            ['2. What access', [
                'Building and room → Forbes, 1027, from the register',
                'Key type → Room key; quantity → 1',
            ]],
            ['3. Why', [
                'Reason → new technician needs routine wet lab access',
                'Needed by → 24 August 2026',
            ]],
            ['One decision, then the register', [
                'The INHS director - or their proxy, recorded as such - approves',
                'The key office issues; the register holds holder, room and date',
            ]],
        ],
    ],

    // ---- Incidents ----------------------------------------------------
    'incident_slip' => [
        'page' => 'new_incident.php',
        'shot' => 'wex_incident_slip',
        'icon' => 'reg-incidents',
        'pipe' => [['Report', 'Notify', 'Grade', 'Actions', 'Close'], 0,
                   'Incidents · rq.Incidents'],
        'post' => [
            'kind' => 'injury',
            'survey' => 'INHS',
            'occurred_date' => '2026-08-04',
            'occurred_time' => '09:04',
            'bldg' => '1073',
            'room' => '1027',
            'description' => 'Slipped on meltwater that had run out from under the '
                . 'walk-in cooler door and put my left hand down. Wrist swollen; seen '
                . 'at the clinic the same morning.',
            'immediate_action' => 'Mopped the floor and put a wet-floor sign out; '
                . 'told the lab manager.',
        ],
        'steps' => [
            ['1. What happened', [
                'Kind → Injury (a near miss files exactly the same way)',
                'Survey → INHS',
            ]],
            ['2. When', [
                'Date → 4 August 2026; time → 09:04',
            ]],
            ['3. Where', [
                'Building and room → Forbes, 1027, from the floor plan',
            ]],
            ['4. In your own words', [
                'The reporter\'s own account - severity is never asked of them',
                'Immediate action taken → mopped, signed, told the lab manager',
            ]],
            ['5. Who was there', [
                'One row per person: name, involvement, injury, body part, treatment',
                'Blank rows are dropped, never rejected',
            ]],
        ],
    ],

    'incident_spill' => [
        'page' => 'new_incident.php',
        'shot' => 'wex_incident_spill',
        'icon' => 'reg-incidents',
        'pipe' => [['Report', 'Notify', 'Grade', 'Actions', 'Close'], 0,
                   'Incidents · rq.Incidents'],
        'post' => [
            'kind' => 'spill',
            'survey' => 'INHS',
            'occurred_date' => '2026-08-06',
            'occurred_time' => '14:20',
            'bldg' => '1073',
            'room' => '1031',
            'description' => 'A litre of dilute nitric acid went across the bench and '
                . 'onto the floor when a carboy tap failed. Nobody was splashed. '
                . 'Neutralised and cleaned with the spill kit.',
            'immediate_action' => 'Area cleared, spill kit used, fume hood left '
                . 'running, safety office called.',
        ],
        'steps' => [
            ['1. What happened', [
                'Kind → Spill or release',
                'Survey → INHS',
            ]],
            ['2. When', [
                'Date → 6 August 2026;  time → 14:20',
            ]],
            ['3. Where', [
                'Building and room → Forbes, 1031',
            ]],
            ['4. In your own words', [
                'What failed, what was spilled, who was near it',
                'Immediate action → cleared, neutralised, safety office called',
            ]],
            ['6. Photographs', [
                'Photographs straight from the phone, attached to the report',
                'The safety office grades it and records whether it is reportable',
            ]],
        ],
    ],

    'incident_vehicle' => [
        'page' => 'new_incident.php',
        'shot' => 'wex_incident_vehicle',
        'icon' => 'reg-fleet',
        'pipe' => [['Report', 'Notify', 'Grade', 'Actions', 'Close'], 0,
                   'Incidents · rq.Incidents'],
        'post' => [
            'kind' => 'vehicle',
            'survey' => 'ISGS',
            'occurred_date' => '2026-08-07',
            'occurred_time' => '16:45',
            'off_campus' => '1',
            'written_location' => 'North gate of the equipment yard, leaving onto '
                . 'Curtis Road',
            'fleet_number' => '35695-22',
            'description' => 'Reversing out of the yard I misjudged the bollard by '
                . 'the north gate and caught the rear bumper. No other vehicle '
                . 'involved, nobody hurt. Told the yard supervisor immediately.',
            'immediate_action' => 'Reported to the yard supervisor; photographs taken '
                . 'before the truck was moved.',
        ],
        'steps' => [
            ['1. What happened', [
                'Kind → Vehicle incident',
                'Fleet number → 35695-22, which ties this to the vehicle\'s history',
            ]],
            ['2. When', [
                'Date → 7 August 2026;  time → 16:45',
            ]],
            ['3. Where', [
                'Not in a PRI building - the location is written out in words',
                '"North gate of the equipment yard, leaving onto Curtis Road"',
            ]],
            ['4. In your own words', [
                'What happened, who was told, and when',
                'Graded minor; closed with a finding: a spotter at the north gate',
            ]],
        ],
    ],

    // ---- Purchasing ---------------------------------------------------
    'purchase_freezer' => [
        'page' => 'new_purchase.php',
        'shot' => 'wex_purchase_freezer',
        'icon' => 'reg-purchasing',
        'pipe' => [['File', 'Approve', 'Buy', 'Reconcile', 'Tag'], 0,
                   'Purchasing · rq.PurchaseItems'],
        'post' => [
            'staff_name' => 'Blobaum, Devin',
            'survey_code' => 'INHS',
            'vendor_name' => 'VWR International',
            'vendor_addr1' => 'Radnor Corporate Center, Building One',
            'vendor_city' => 'Radnor',
            'vendor_state' => 'PA',
            'vendor_zip' => '19087',
            'purchase_type' => 'Purchase Order',
            'bldg' => '1073',
            'room' => '1027',
            'req_date' => '2026-09-30',
            'justification' => 'Flammable storage freezer required for solvent '
                . 'standards; the current unit is out of specification.',
            'notes' => 'Quote attached. Delivery to the loading dock, then 1027.',
        ],
        'steps' => [
            ['Buyer', [
                'Staff member and survey → from the directory',
            ]],
            ['Suggested vendor', [
                'Vendor → VWR International, with its address',
            ]],
            ['Product information', [
                'Line → Flammable Storage Freezer, VWR FFV-30, $4,810.53',
                'Banner classification code → found by typeahead, not memorised',
                'Delivery room → Forbes 1027, from the floor plan',
            ]],
            ['Funding', [
                'CFOP, split if more than one account pays',
                'The approval grid fills itself from the accounts named',
            ]],
            ['What happens next', [
                'Approved on the grid, bought on PO P2261719, requisition FY22-3711',
                'Arrival registers against this line - cost, account and room carry '
                    . 'across untyped',
            ]],
        ],
    ],

    // ---- Property -----------------------------------------------------
    'property_serial' => [
        'page' => 'new_property.php',
        'shot' => 'wex_property_serial',
        'icon' => 'reg-equipment',
        'pipe' => [['File update', 'Office reviews', 'Applied', 'History'], 0,
                   'Equipment · gis.Inventory'],
        'post' => [
            'do' => 'update',
            'kind' => 'correct',
            'tag' => 'P10E22031',
            'serial' => 'VWR-2203-B',
            'reason' => 'Serial was transposed at some point before the C3PO export. '
                . 'The microscope in hand reads VWR-2203-B; the register says -A.',
        ],
        'steps' => [
            ['1. Which item', [
                'Find it by tag, serial, description, holder or room',
                'Tag → P10E22031',
            ]],
            ['2. What happened', [
                'Kind → Correct a record',
                'Serial → VWR-2203-B  (the only field touched)',
                'Every blank field leaves the record exactly as it was',
            ]],
            ['Why not simply edit it', [
                'The event history keeps the old value, the new one, who and when',
                'Next year\'s question is "which serial did we certify in 2026"',
            ]],
        ],
    ],
];

/**
 * Seed $_POST from a fixture so the form renders mid-fill.
 *
 * Read-only by construction: it refuses anything but a GET, so no
 * handler can ever see these values as a submission.
 */
function demo_fill_apply(): void
{
    $key = (string)($_GET['demo'] ?? '');
    if ($key === '' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return;
    }
    if (!isset(DEMO_FILLS[$key])) {
        return;
    }
    foreach (DEMO_FILLS[$key]['post'] ?? [] as $field => $value) {
        $_POST[$field] = is_int($value) ? (string)$value : $value;
    }
}

/**
 * True when the running demo wants the whole form on screen rather than
 * just its first step - a job aid that explains section three has to
 * show section three.
 */
function demo_fill_full(): bool
{
    $key = (string)($_GET['demo'] ?? '');
    return $key !== '' && !empty(DEMO_FILLS[$key]['full']);
}

/**
 * A demo capture lays the page out narrow, the way a phone would.
 * A wide form photographed for a slide is unreadable by the time it is
 * four inches across; the narrow column keeps the type legible - and it
 * is how most people file anyway.
 */
function demo_fill_phone(): bool
{
    return (string)($_GET['demo'] ?? '') !== ''
        && isset(DEMO_FILLS[(string)$_GET['demo']]);
}

/**
 * Capture aid: render chosen dropdowns OPEN.
 *
 * A native select draws its option list outside the page, so no
 * screenshot can ever contain it - the deck needs to show the choices a
 * person actually gets. Setting size turns the same element into a list
 * box, so what is photographed is the real control holding the real
 * options from the database, not a mock-up of one.
 *
 * Opt-in only: ?open=field1,field2 (or ?open=all). Without the
 * parameter this emits nothing and the forms behave exactly as before.
 */
function demo_open_selects(): string
{
    $want = trim((string)($_GET['open'] ?? ''));
    if ($want === '') {
        return '';
    }
    // Only ever field names. Anything else is dropped rather than
    // encoded: this parameter is reflected into a <script>, and with
    // JSON_UNESCAPED_SLASHES a value containing </script> closed the block
    // and the rest was parsed as HTML - a working injection on every page,
    // added here for a screenshot convenience. The slashes stay escaped
    // and the tag characters are hex-encoded as well.
    $names = array_values(array_filter(
        array_map('trim', explode(',', $want)),
        static fn($n) => $n !== '' && preg_match('/^[A-Za-z0-9_\[\]]{1,64}$/', $n) === 1
    ));
    if ($names === []) {
        return '';
    }
    $json = json_encode($names, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $rows  = max(2, min(12, (int)($_GET['rows'] ?? 7)));
    return '<script>(function(){'
         . 'var want=' . $json . ',rows=' . $rows . ';'
         . 'var all=want.length===1&&want[0]==="all";'
         . 'document.querySelectorAll("select").forEach(function(s){'
         . 'if(!all&&want.indexOf(s.name)<0)return;'
         . 'var n=Math.min(rows,Math.max(2,s.options.length));'
         . 's.size=n;s.style.minWidth=s.offsetWidth+"px";'
         . 'if(s.selectedIndex>0){s.options[s.selectedIndex].scrollIntoView({block:"nearest"});}'
         . '});})();</script>';
}

/**
 * Capture aid: drop the page chrome so a screenshot is the screen, not
 * the banner.
 *
 * A slide's picture area is a few inches tall on a thirteen inch slide.
 * A whole page photographed top to bottom has to be shrunk until nothing
 * can be read - the mistake this exists to avoid. Captures are taken at
 * a narrow viewport (the form falls to one column and the type lands
 * larger per inch) and cropped to the part being discussed.
 *
 * ?bare=1 hides the banner, the acting-as switch and the footer.
 */
function demo_capture_frame(): string
{
    if (($_GET['bare'] ?? '') === '') {
        return '';
    }
    return '<script>(function(){'
         . '["header.site","footer.foot",".devswitch"].forEach(function(q){'
         . 'document.querySelectorAll(q).forEach(function(e){e.style.display="none";});});'
         . 'document.querySelectorAll("main").forEach(function(m){m.style.padding="0";});'
         . '})();</script>';
}
