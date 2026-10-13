<?php
declare(strict_types=1);

/**
 * What is still to be built, as data.
 *
 * One list, used by the backlog document and the audit workbook both, so
 * the two can never disagree about what remains. Each item: title, what
 * it is, why it is wanted, a size ('small' is an afternoon, 'medium' a
 * few days, 'large' a fortnight or more), and what it waits on.
 */

const APP_BACKLOG = [

'Data and Structure' => [
    ['Directory database',
     'People, the org chart, surveys and unit contacts still sit in the Work database, '
     . 'which makes Work both a request database and a reference database. Everything else '
     . 'has been separated on that principle; this is the last piece that has not.',
     'It is the one body of data every other database copies. Keeping it inside a request '
     . 'database means a change to a request table and a change to the staff list are the '
     . 'same maintenance window, and the copy that goes out to the other nine databases is '
     . 'published from a database that is also being written to constantly.',
     'medium', 'Nothing. It can be done next.'],

    ['Loaders that fail loudly',
     'Every loader maps a fixed list of columns and silently ignores the rest. A column '
     . 'that stops being read is indistinguishable from a column that was never there.',
     'This is what cost the purchase document number on 1,635 property records, the whole '
     . 'lease and ownership register, and four columns of the staff roster - none of it '
     . 'announced. tools/source_audit.php now catches it after the fact; the loaders should '
     . 'refuse before the fact.',
     'small', 'Nothing. tools/source_audit.php already knows every mapping.'],

    ['Missing lease end dates',
     'Thirty-three agreements were recovered from the estate workbook. Five carried an end '
     . 'date buried in the reference text and now have real ones. Twenty-eight have nothing.',
     'Five leases turned out to have expired between 2022 and 2023, one of them more than '
     . 'four years ago. The twenty-eight with no date cannot be checked at all.',
     'small', 'The dates themselves, which are on the leases rather than in any export.'],

    ['Missing equipment columns',
     'PurchaseRequestID, ContractNumber, ContractExpires, Manufacturer, '
     . 'WarrantyUntil, ExpectedLifeYears, ReplacementDue, ReplacementCost, CalibrationDue, '
     . 'LastCertifiedOn, CertifiedByNetID, Custodian held separately from Person, and '
     . 'FundingCfop. The purchase order number is no longer on this list: it was in the '
     . 'C3PO export all along and has been recovered.',
     'Without these the inventory can say what exists and where, but not what it cost to '
     . 'run, when it falls out of warranty, when it is next due for calibration, or when it '
     . 'should be replaced. Those are the questions a facilities office is actually asked.',
     'small', 'Nothing.'],

    ['Building wizard',
     'A guided way to add a building: what it is, who owns it, the agreement if PRI does '
     . 'not own it, floor plans if they exist, rooms typed in if they do not.',
     'On-campus buildings arrive through the GIS and C3PO exports, but an off-campus field '
     . 'station or a new lease has no export to arrive on. Today it is added bare in '
     . 'Buildings and Rooms; the wizard walks the whole chain so nothing - ownership, '
     . 'agreement, rooms - gets forgotten at the door.',
     'medium', 'Nothing. The estate register it feeds already exists.'],

    ['AcquiredOn made a real date',
     'The acquisition date is held as text. It came across that way from the inventory '
     . 'export and has stayed that way.',
     'Nothing can sort, compare or subtract it, so age, depreciation and replacement '
     . 'planning are all impossible while it stays text. Every row currently has a value, '
     . 'so the conversion has data to work with.',
     'small', 'Should be done in the same pass as the equipment columns.'],
],

'Safety' => [
    ['Safety equipment problems',
     'An eyewash that will not flush, an extinguisher missing from its bracket, a fume hood '
     . 'reading wrong, an expired AED pad. Raises a work order against the room.',
     'The safety manifest records where equipment is and when it was tested. Nothing yet '
     . 'lets somebody say that a particular item is broken now.',
     'small', 'The manifest and work orders both exist. This joins them.'],

    ['Room hazard profile',
     'What is in a room decides what it must carry: chemicals, lasers, biologicals, '
     . 'compressed gas, cryogens. The profile prints the door card and lists what is missing.',
     'It turns the safety manifest from a list of what is there into a comparison against '
     . 'what ought to be there, which is the only form in which it finds a gap.',
     'medium', 'Needs the hazard classes agreed with the safety office first.'],

    ['Inspection rounds',
     'Eyewashes weekly, extinguishers monthly, fume hoods annually. A round is a route '
     . 'through a building: scan, pass or fail, photograph, done.',
     'The audit currently shows 108 pieces of safety equipment with no test date at all. A '
     . 'round is what produces those dates as a by-product of the walk somebody already does.',
     'medium', 'The QR encoder already built for vehicle stickers works for equipment.'],

    ['Safety contacts lookup',
     'Who to call for this room, this floor, this building: safety contact, chemical '
     . 'hygiene officer, floor warden, after hours.',
     'One room in five hundred that can hurt somebody currently has a safety contact '
     . 'recorded. The lookup is easy; the filling in is the work, and the lookup is what '
     . 'makes the filling in worth doing.',
     'small', 'Room responsibility already holds the field.'],
],

'Vehicles' => [
    ['Driver forms',
     'Two forms are required before somebody may drive, and a third is optional. The '
     . 'schema and lib/drivers.php exist; nothing renders them.',
     'A booking cannot be checked against a driver who is cleared to drive until the forms '
     . 'are recorded. At the moment the check has nothing to check against.',
     'small', 'Needs the real names of the two required forms. The placeholders seeded are '
     . 'mvr and certification, plus defensive as the optional third.'],

    ['Vehicle booking',
     'Who has which vehicle, when, and for what. The tables exist and nothing renders them.',
     'It is the half of the fleet system that staff would use daily; maintenance requests '
     . 'are the half they use rarely.',
     'medium', 'The fleet database is built and the vehicle list is loaded.'],

    ['Fleet numbers corrected',
     'Nearly three hundred vehicles carry a property tag beginning P1 where a fleet number '
     . 'belongs, because they were swept out of the property inventory rather than entered '
     . 'as vehicles.',
     'A shop request naming one of these will not match the number painted on the vehicle, '
     . 'so the request cannot be found by the person holding the keys.',
     'small', 'Data work, not code. One reconciled export fixes it.'],
],

'Equipment and Property' => [
    ['Certification round',
     'The annual property certification: each custodian confirms what they hold, what has '
     . 'gone, and what they cannot find.',
     'It happens every year on spreadsheets. The inventory, the custody field and the '
     . 'event log are already here; the round is the process on top of them.',
     'medium', 'Needs Custodian separated from Person first.'],

    ['Calibration due lists',
     'What falls out of warranty, out of calibration or out of expected life this year.',
     'It is the reason the extra equipment columns are worth adding, and it is what turns '
     . 'the inventory from a record into a planning tool.',
     'small', 'Depends entirely on the equipment columns above.'],

    ['Surplus and disposal route',
     'The path an item takes once it leaves: to surplus, to another unit, to salvage, or '
     . 'written off.',
     'The property update form can already record a removal. What is missing is the '
     . 'onward paperwork and the evidence trail behind it.',
     'small', 'The removal fields already exist on the form.'],
],

'Delivery' => [
    ['Outlook relay turned on',
     'Every notification is being written to the outbox instead of being sent, because the '
     . 'relay host, port and credentials have not been supplied.',
     'It is the single largest gap between the system working and the system being used. '
     . 'Nothing else is blocked by it; everything else is diminished by it.',
     'small', 'Needs the relay details from campus IT and nothing else.'],

    ['Contacts for four surveys',
     'INHS is complete. ISAS, ISGS, ISTC and ISWS are not.',
     'Space requests route to the survey committee and then the director. Where the '
     . 'committee is unknown the request has nowhere to go.',
     'small', 'Names to agree, not software to write.'],
],
];

const APP_BACKLOG_SIZE = [
    'small'  => 'An afternoon',
    'medium' => 'A few days',
    'large'  => 'A fortnight or more',
];
