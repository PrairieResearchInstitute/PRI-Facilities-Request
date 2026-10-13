<?php
declare(strict_types=1);

/**
 * What is still to be built.
 *
 * Kept as data at the top of this file rather than as prose, so the list
 * can be reordered, added to and regenerated without rewriting a
 * document. The parts that can be counted - how many cards are still
 * skeletons, what the equipment table is missing - are read from the
 * live system instead of being asserted, so the document cannot drift
 * from what is actually there.
 *
 *   php tools/make_backlog_doc.php
 */

require_once __DIR__ . '/../facilities/lib/db.php';
require_once __DIR__ . '/../facilities/lib/docx.php';

app_config();

/**
 * Each item: what it is, why it is wanted, and what it depends on.
 * 'size' is honest rather than optimistic: 'small' is an afternoon,
 * 'medium' is a few days, 'large' is a fortnight or more.
 */
require_once __DIR__ . '/../facilities/lib/backlog.php';

const BACKLOG = APP_BACKLOG;

$sizeWord = [
    'small'  => 'An afternoon',
    'medium' => 'A few days',
    'large'  => 'A fortnight or more',
];

// ------------------------------------------------ what is really there
$skeleton = 0;
$index = @file_get_contents(dirname(__DIR__) . '/facilities/index.php');
if ($index !== false) {
    $skeleton = substr_count($index, "'in preparation'");
}

$missing = [];
$have = [];
// db_columns() asks the catalogue of whichever engine holds the registers.
$inventoryColumns = db_columns('equipment', 'gis.Inventory');
foreach ($inventoryColumns as $c) {
    $have[strtolower((string)$c['name'])] = true;
}
foreach (['PurchaseRequestID', 'PONumber', 'ContractNumber', 'ContractExpires', 'Manufacturer',
          'WarrantyUntil', 'ExpectedLifeYears', 'ReplacementDue', 'ReplacementCost',
          'CalibrationDue', 'LastCertifiedOn', 'CertifiedByNetID', 'Custodian',
          'FundingCfop'] as $col) {
    if (!isset($have[strtolower($col)])) {
        $missing[] = $col;
    }
}

$acquiredType = '';
foreach ($inventoryColumns as $c) {
    if (strcasecmp((string)$c['name'], 'AcquiredOn') === 0) {
        $acquiredType = (string)$c['type'];
    }
}

$total = 0;
foreach (BACKLOG as $items) {
    $total += count($items);
}

// ------------------------------------------------------------ document
$b = '';
$b .= docx_h('PRI Facilities Request System', 1);
$b .= docx_p('What is still to be built', 'Subtitle');
$b .= docx_facts([
    'Produced'                  => date('j F Y, g:ia'),
    'Items listed'              => $total,
    'Cards still unbuilt on the front page' => $skeleton,
    'Databases in service'      => count(APP_DB_NAMES),
    'Equipment columns still missing' => count($missing),
]);

$b .= docx_h('What This Is', 2);
$b .= docx_p('Everything that has been asked for and not yet built, with what each thing '
    . 'depends on. It is deliberately separate from the data audit: that document is about '
    . 'gaps in the data the system inherited, this one is about the system itself.');
$b .= docx_p('One entry here used to read *PONumber*. It was never missing - it arrived in '
    . 'the C3PO property export on 1,635 of 2,342 records, was dropped on import without a '
    . 'word, and was then written down as work still to do. A full comparison of every source '
    . 'file against every table found four more losses of the same kind. All five have been '
    . 'recovered, and *tools/source_audit.php* now checks the whole set on demand.');
$b .= docx_p('The order inside each section is the order that costs least, because each '
    . 'item lists what it is waiting on. Several are waiting on an answer rather than on '
    . 'work, and those are collected at the end so they can be settled in one conversation.');

$b .= docx_h('Where The System Stands', 2);
$b .= docx_p('Ten databases in service, none of them holding a key that crosses into '
    . 'another. Work, Move, Shop, Purchasing, Keys, Fleet, Space, Equipment, Incidents and '
    . 'Plans. Each register lives with the requests that feed it, so a key request sits '
    . 'beside the key register and a purchase sits beside its Banner codes.');
$b .= docx_p($skeleton . ' cards on the front page are still marked *in preparation*. Each '
    . 'one describes something real that was asked for; none of them is a placeholder for '
    . 'its own sake.');

if ($missing) {
    $b .= docx_p('The inventory is missing ' . count($missing) . ' of the columns asked '
        . 'for: ' . implode(', ', $missing) . '.');
}
if ($acquiredType !== '' && !in_array(strtolower($acquiredType), ['date', 'datetime',
                                                                 'datetime2'], true)) {
    $b .= docx_p('*AcquiredOn is still ' . $acquiredType . '*, so nothing can sort or '
        . 'subtract it.');
}

foreach (BACKLOG as $section => $items) {
    $b .= docx_pagebreak();
    $b .= docx_h($section, 2);
    foreach ($items as [$title, $what, $why, $size, $depends]) {
        $b .= docx_h($title, 3);
        $b .= docx_p($what);
        $b .= docx_p('*Why:* ' . $why);
        $b .= docx_facts([
            'Roughly'   => $sizeWord[$size] ?? $size,
            'Waiting on' => $depends,
        ]);
    }
}

// ----------------------------------------------- what needs an answer
$b .= docx_pagebreak();
$b .= docx_h('What Is Waiting On An Answer Rather Than On Work', 2);
$b .= docx_p('None of these needs anybody to write anything. Each is a decision or a '
    . 'detail that only somebody at PRI can supply, and each blocks something already '
    . 'built or nearly built.');
$b .= docx_bullet('*The Outlook relay.* Host, port, and whether it authenticates. Until '
    . 'this arrives every notification is written to the outbox and read by nobody.');
$b .= docx_bullet('*The real names of the two required driver forms.* The schema is seeded '
    . 'with mvr and certification as placeholders, plus defensive as an optional third.');
$b .= docx_bullet('*Space committee and safety contacts for ISAS, ISGS, ISTC and ISWS.* '
    . 'INHS is complete. A space request from any of the other four has nowhere to route.');
$b .= docx_bullet('*The hazard classes for the room profile.* Which categories the safety '
    . 'office wants a room classified under, and what each class obliges the room to carry.');
$b .= docx_bullet('*Whether the property certification round is annual and who runs it.*');

$b .= docx_h('Suggested Order', 2);
$b .= docx_p('If the aim is the most working system per week of effort:');
$b .= docx_bullet('*The relay*, because it is a configuration line and it makes everything '
    . 'already built visible to the people it was built for.');
$b .= docx_bullet('*The equipment columns and the date conversion*, because they are one '
    . 'afternoon and three other items are waiting behind them.');
$b .= docx_bullet('*The directory database*, because it is the last structural piece and '
    . 'it gets harder the longer other things are built on top of Work.');
$b .= docx_bullet('*Driver forms and vehicle booking*, because the schema is already there '
    . 'and only the screens are missing.');
$b .= docx_bullet('*The safety cards*, in the order equipment problem, contacts lookup, '
    . 'inspection rounds, hazard profile - which is also cheapest first.');

$path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'PRI-Facilities-Still-To-Build.docx';
$n = docx_write($path, 'PRI Facilities Request System - What Is Still To Be Built', $b);
echo "Wrote $path (" . number_format($n) . " bytes)\n";
echo "$total items, $skeleton skeleton cards, " . count($missing) . " equipment columns missing\n";
