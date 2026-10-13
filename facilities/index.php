<?php
declare(strict_types=1);

/**
 * Front door: a chooser, and nothing else in front of it. Most people
 * arrive here to file something, so that is what the page is for.
 *
 * Anything waiting on the person is a slim line under the choices and
 * lives in full on My work, rather than a queue standing between a
 * reporter and the form they came for.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/dashboard.php';
require_once __DIR__ . '/lib/digest.php';

if (!app_installed()) {
    header('Location: install.php');
    exit;
}
handle_user_switch();
$user = current_user();

// Shared hosting promises no scheduler, so the front page carries the
// digest run occasionally. It never delays the page it is riding on.
digest_maybe_run();

$waiting = dash_waiting_on_me($user);
$mine = dash_my_requests($user);

ui_top((string)setting('app_name', 'PRIOps'),
       'Prairie Research Institute, University of Illinois Urbana-Champaign');
flash_show();
?>

<p class="lede">What do you need? Choose the area, then the request. Before a new work request is
accepted you will see the open requests already filed for your building, so you can confirm an
existing report instead of filing a duplicate.</p>

<?php
/**
 * Four areas, each opening onto its own requests, and all four shut on
 * arrival. The whole page is then four lines - title and one phrase
 * apiece - so the choice is made before anything is read. A card marked
 * "in preparation" says so plainly rather than pretending to be finished.
 */
$areas = [
    'facilities' => [
        'title' => 'Facilities',
        'blurb' => 'Repairs, moves, shop work, space and keys.',
        'open'  => false,
        'cards' => [
            ['new_work.php', 'Facilities Work Request',
             'Report a building issue so it can be repaired: leaks, electrical, heating and '
             . 'cooling, doors and locks, grounds, pests, or anything broken or hazardous.',
             'File a work request', 'If this is an emergency, call Matt Thompson at '
             . '217-300-1176 before submitting.', null],
            ['new_move.php', 'Move Request',
             'Have equipment, furniture, or an office moved, transferred, surplussed, or '
             . 'trashed. Routes through IT if tech items are moving, then inventory and '
             . 'facilities. Allow at least 3 days.', 'File a move request', null, null],
            ['new_shop.php', 'Shop Services Work Request',
             'Have something built, modified or repaired by the shop. Requires an account, '
             . 'and is copied to your PI for review.', 'File a shop request', null, null],
            ['new_space.php', 'Space Request',
             "Ask for space, change how a room is used, relocate, or give space back. Your "
             . "unit's space committee reviews it and the director decides.",
             'File a space request', null, null],
            ['new_key.php', 'Key Request',
             'Ask for a key or card credential for yourself or someone in your unit. Approved '
             . 'by your director or their administrative assistant, then issued by facilities.',
             'File a key request', null, null],
            ['board.php', 'Open Requests',
             'Everything currently open across every request type, filterable by survey and '
             . 'status, and downloadable as a spreadsheet.', 'See the board', null, null],
        ],
    ],
    'safety' => [
        'title' => 'Safety',
        'blurb' => 'Incidents, inspections, hazards and safety plans.',
        'open'  => false,
        'cards' => [
            ['new_incident.php', 'Report an Incident or Near Miss',
             'An injury, a spill, an exposure, or something that nearly went wrong. Goes to '
             . 'the safety office and your director, and is tracked until it is closed.',
             'File a report', null, null],
            ['#', 'Safety Equipment Problem',
             'An eyewash that will not flush, an extinguisher missing from its bracket, a fume '
             . 'hood reading wrong, an expired AED pad. Raises a work order against the room.',
             'Report a problem', null, 'in preparation'],
            ['#', 'Room Hazard Profile',
             'What is in a room decides what it must carry: chemicals, lasers, biologicals, '
             . 'compressed gas, cryogens. The profile prints the door card and lists what is '
             . 'missing.', 'See a room', null, 'in preparation'],
            ['#', 'Inspection Rounds',
             'Eyewashes weekly, extinguishers monthly, fume hoods annually. A round is a route '
             . 'through a building: scan, pass or fail, photograph, done.',
             'Start a round', null, 'in preparation'],
            ['plans.php', 'Safety Plan Repository',
             'Chemical hygiene plans, lab and field safety plans, building emergency action '
             . 'plans and standard operating procedures, each owned by somebody, each with a '
             . 'review date, each linked to the rooms it covers.', 'Find a plan', null, null],
            ['#', 'Safety Contacts',
             'Who to call for this room, this floor, this building: safety contact, chemical '
             . 'hygiene officer, floor warden, after hours.', 'Look up a contact', null,
             'in preparation'],
        ],
    ],
    'equipment' => [
        'title' => 'Equipment',
        'blurb' => 'Purchasing, and tagged property from arrival to disposal.',
        'open'  => false,
        'cards' => [
            ['new_purchase.php', 'Purchase Request',
             'Ask for something to be bought. Every account you charge asks its principal '
             . 'investigator and its fund manager to approve, and the people are found for you '
             . 'from the account itself.',
             'File a purchase request', null, null],
            // Registering, moving, handing over and disposing were four
            // cards here. They are four sentences about the same object,
            // and choosing between four screens meant knowing how the
            // database is laid out. One form asks what happened.
            ['new_property.php', 'Property Update',
             'Something changed about a tagged item: it arrived and needs a number, it moved, '
             . 'somebody else is responsible for it, the record is wrong, or it has gone. '
             . 'Find it, say what happened, and only the boxes that matter appear.',
             'Update a record', null, null],
            ['#', 'Annual Property Certification',
             'Your own list, once a year: confirm each item, flag what you cannot find, sign '
             . 'it off. Replaces the spreadsheet.', 'Certify my property', null,
             'in preparation'],
            ['#', 'Find an Item',
             'By tag, description, serial number, room, or the person holding it.',
             'Search property', null, 'in preparation'],
            ['#', 'Equipment Age and Replacement',
             'What is old, what is out of warranty, what is due for replacement and what it '
             . 'would cost.', 'See the report', null, 'in preparation'],
        ],
    ],
    'vehicles' => [
        'title' => 'Vehicles',
        'blurb' => 'The fleet, its mileage, its servicing and its repairs.',
        'open'  => false,
        'cards' => [
            ['new_shop.php?kind=vehicle', 'Vehicle Maintenance Request',
             'Servicing, a repair, an inspection, or something wrong with a vehicle. Goes to '
             . 'the shop and to Adam Deany.', 'File a vehicle request', null, null],
            ['m.php', 'Record Mileage',
             'Scan the sticker in the cab with your phone camera and it opens that vehicle. '
             . 'One number and a photograph of the dash. Everything else - service due, cost per '
             . 'mile, use by survey - is built from these.',
             'Record a reading', null, null],
            ['#', 'Book a Vehicle',
             'Who has which vehicle, when, and where it is going. Doubles as the record of who '
             . 'is out in the field.', 'Book a vehicle', null, 'in preparation'],
            ['#', 'Report an Accident or Incident',
             'Damage, a collision, or a breakdown, with photographs and the report number.',
             'File a report', null, 'in preparation'],
            ['#', 'Vehicle Record',
             'Year, plate, VIN, home station, who it is assigned to, its service schedule and '
             . 'everything ever done to it.', 'Look up a vehicle', null, 'in preparation'],
            ['#', 'Fleet Age and Replacement',
             'Age against mileage against what has been spent repairing it, so a replacement '
             . 'can be argued for with a number.', 'See the report', null, 'in preparation'],
        ],
    ],
];
?>

<?php foreach ($areas as $key => $area): ?>
<details class="area" <?= $area['open'] ? 'open' : '' ?>>
    <summary>
        <span class="area-title"><?= h($area['title']) ?></span>
        <span class="area-blurb"><?= h($area['blurb']) ?></span>
    </summary>
    <div class="cards">
        <?php foreach ($area['cards'] as [$href, $title, $body, $go, $urgent, $soon]): ?>
        <?php if ($soon): ?>
        <div class="card card-soon">
            <h3><?= h($title) ?> <span class="soon"><?= h($soon) ?></span></h3>
            <p><?= h($body) ?></p>
            <span class="go muted">Not built yet</span>
        </div>
        <?php else: ?>
        <a class="card" href="<?= h($href) ?>">
            <h3><?= h($title) ?></h3>
            <p><?= h($body) ?></p>
            <?php if ($urgent): ?><p class="urgent"><?= h($urgent) ?></p><?php endif; ?>
            <span class="go"><?= h($go) ?> &rarr;</span>
        </a>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
</details>
<?php endforeach; ?>

<?php if ($waiting || $mine): ?>
<p class="worknote">
    <?php if ($waiting): ?>
    <strong><?= count($waiting) ?></strong> item<?= count($waiting) === 1 ? '' : 's' ?>
    waiting on your decision<?= $mine ? ', and ' : '. ' ?>
    <?php endif; ?>
    <?php if ($mine): ?>
    <strong><?= count($mine) ?></strong> of your own request<?= count($mine) === 1 ? '' : 's' ?>
    still open.
    <?php endif; ?>
    <a href="digest.php">See my work</a>
</p>
<?php endif; ?>

<p class="muted"><a href="stages.php">How requests move through their stages</a>
   &middot; <a href="preferences.php">How often you are reminded</a></p>
<?php ui_bottom(); ?>
