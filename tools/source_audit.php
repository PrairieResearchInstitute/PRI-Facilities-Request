<?php
declare(strict_types=1);

/**
 * What was provided, against what was kept.
 *
 * Every table here was loaded from a file somebody exported by hand. The
 * loaders map a fixed list of columns and ignore the rest, which is fast
 * and forgiving and completely silent: a column that stops being read is
 * indistinguishable from a column that was never there.
 *
 * That silence cost real data. The property inventory arrived carrying a
 * purchase document number on 1,635 of 2,342 rows and it was dropped on
 * import without a word, and then written down afterwards as something
 * still to be built.
 *
 * So this compares the two directly: for every source file, every column
 * in it, how many rows carry a value, and whether that value reached the
 * database. It is meant to be run again after any loader changes.
 *
 *   php tools/source_audit.php
 *   php tools/source_audit.php --verbose
 */

require_once __DIR__ . '/../facilities/lib/db.php';
require_once __DIR__ . '/../facilities/lib/xlsxread.php';

app_config();
$verbose = in_array('--verbose', $argv, true);

/**
 * source file => [database, table, [source column => destination column]]
 *
 * A destination of null means the column is deliberately not kept, and
 * the reason is written beside it. Anything not listed at all is a
 * column nobody has looked at, which is the case this tool exists to
 * catch.
 */
const SOURCES = [

'C3PO Data/C3poInventoryPage20260806184755.csv' => ['equipment', 'gis.Inventory', [
    'Person'                     => 'Person',
    'Property Tag (PTag) #'      => 'PropertyTag',
    'Description'                => 'Description',
    'Model #'                    => 'Model',
    'Serial #'                   => 'SerialNumber',
    'Location'                   => 'RoomID',
    'Banner Location Code'       => 'BannerLocationCode',
    'Acq. Date'                  => 'AcquiredOn',
    'Cost'                       => 'Cost',
    'Original Funding Source'    => 'FundingSource',
    'Computed Depreciated Value' => 'DepreciatedValue',
    'Depreciation'               => 'AccumulatedDepreciation',
    'Purchase Document'          => 'PurchaseDocument',   // also split into
                                                          // PoNumber / RequisitionNumber
    'Images'                     => null,      // empty in every row of the export
    'Confirmed'                  => null,      // empty in every row of the export
]],

'storage/gis/occupants.csv' => ['space', 'gis.Occupants', [
    'RoomCode'     => 'RoomCode',
    'BldgNumber'   => 'BldgNumber',
    'FloorCode'    => 'FloorCode',
    'RoomID'       => 'RoomID',
    'PersonName'   => 'PersonName',
    'NetID'        => 'NetID',
    'UIN'          => 'UIN',
    'Org'          => 'Org',
    'EmpType'      => 'EmpType',
    'PrimaryOffice' => 'PrimaryOffice',
    'ConfirmedOn'  => 'ConfirmedOn',
    'Notes'        => 'Notes',
]],

'storage/gis/rooms.csv' => ['space', 'gis.Rooms', [
    'RoomCode' => 'RoomCode', 'BldgNumber' => 'BldgNumber', 'FloorCode' => 'FloorCode',
    'RoomID' => 'RoomID', 'RoomName' => 'RoomName', 'CollectionType' => 'CollectionType',
    'CategoryCode' => 'CategoryCode', 'CategoryDesc' => 'CategoryDesc',
    'UseCode' => 'UseCode', 'UseDesc' => 'UseDesc', 'Department' => 'Department',
    'Division' => 'Division', 'DivisionName' => 'DivisionName', 'AreaSqFt' => 'AreaSqFt',
    'CentroidX' => 'CentroidX', 'CentroidY' => 'CentroidY', 'PathData' => 'PathData',
    'OtherDescription' => 'OtherDescription', 'DrsInspect' => 'DrsInspect',
    'CollectionReport' => 'CollectionReport', 'Category02' => 'Category02',
    'Desc02' => 'Desc02', 'SpacePercent' => 'SpacePercent', 'SplitSpace' => 'SplitSpace',
    'SurveyCode' => 'SurveyCode', 'Org1' => 'Org1', 'Org1A' => 'Org1A',
    'Org3A' => 'Org3A', 'Org4A' => 'Org4A',
    'KeyCodeRoom' => 'KeyCodeRoom', 'KeyCodeBuilding' => 'KeyCodeBuilding',
]],

'storage/gis/buildings.csv' => ['space', 'ref.Buildings', [
    'BldgNumber' => 'BldgNumber', 'Name1' => 'Name', 'Name2' => 'Name2',
    'Location' => 'LocationClass', 'AddressLine' => 'AddressLine', 'City' => 'City',
    'State' => 'StateCode', 'PostalCode' => 'PostalCode',
    'Longitude' => 'Longitude', 'Latitude' => 'Latitude',
]],

'gis/Facilities/All_PRI_Buildings_v06_2023_02_05_from GIS.xlsx' => ['space', 'ref.Buildings', [
    'OBJECTID' => null,                          // the shapefile's own row id
    'Building' => 'BldgNumber', 'Name_1' => 'Name', 'Name_2' => 'Name2',
    'Location' => 'LocationClass',
    'Type' => 'BuildingType', 'Property Ownership' => 'Ownership',
    'PRI Priority' => 'PriPriority', 'Survey' => 'SurveyCode', 'Condition' => 'Condition',
    // The arrangement, not the building: these became agreements.
    'Status' => 'est.Agreements.TenureStatus',
    'Terms' => 'est.Agreements.TermsBasis',
    'Lease Cost' => 'est.Agreements.LeaseCostBasis',
    'O&M Cost' => 'est.Agreements.OmCostBasis',
    'Contract - Lease, Utilties, and O&M' => 'est.Agreements.ContractShare',
    'ICR - Lease, Utilties, and O&M' => 'est.Agreements.IcrShare',
    'Lease Reference' => 'est.Agreements.Reference',
    'Address1' => 'AddressName', 'Address2' => 'AddressLine', 'Address3' => 'City',
    'Address4' => 'StateCode', 'Address5' => 'PostalCode',
    'Point_only' => null,                        // a drawing hint, not an estate fact
    'X_WKID3435' => null, 'Y_WKID3435' => null,  // state plane, superseded by lat/long
    'X_WKID4326' => 'Longitude', 'Y_WKID4326' => 'Latitude',
]],

'storage/gis/safety.csv' => ['space', 'gis.Safety', [
    'RoomCode' => 'RoomCode', 'BldgNumber' => 'BldgNumber', 'RoomID' => 'RoomID',
    'Kind' => 'Kind', 'Tag' => 'Tag', 'Brand' => 'Brand', 'Model' => 'Model',
    'SerialNumber' => 'SerialNumber', 'Descriptor' => 'Descriptor',
    'Status' => 'Status', 'LastTested' => 'LastTested',
]],

'storage/gis/roster.csv' => ['work', 'ref.People', [
    'NetID' => 'NetID', 'PersonName' => 'DisplayName', 'Org' => 'Org', 'UIN' => 'UIN',
    'Position' => 'Position', 'PositionType' => 'PositionType',
    'BuildingCommonName' => 'HomeBuilding', 'RoomID' => 'HomeRoom',
    'FieldStation' => 'FieldStation',
]],

'C3PO Data/KeyAssignments.csv' => ['keys', 'gis.RoomKeys', [
    'Assignee' => 'HolderName', 'Emp Status' => 'EmpStatus', 'Survey' => 'SurveyCode',
    'State' => 'KeyState', 'Building' => 'BldgNumber', 'Floor' => 'FloorText',
    'Room' => 'RoomID', 'Key #' => 'KeyCode', 'Type' => 'KeyType', 'Qty' => 'Quantity',
    'Assigned By' => 'AssignedBy', 'Comments' => 'Notes', 'Assigned On' => 'IssuedOn',
]],

'Active Banner Account Codes-08-05-2026.xlsx' => ['purchasing', 'ref.BannerAccountCodes', [
    'Account Code' => 'AccountCode', 'Full Title' => 'FullTitle',
    'Banner Title' => 'BannerTitle', 'Description' => 'Description',
    'Summary Code' => 'SummaryCode', 'Examples' => 'Examples',
    'Do Not Use For' => 'DoNotUseFor', 'Predecessor' => 'Predecessor',
    'Account Type' => 'AccountType', 'SAMS Code' => 'SamsCode',
    'Budget Code' => 'IsBudgetCode', 'Data Enterable?' => 'IsEnterable',
    'Beginning and Ending Service Dates' => 'ServiceDates',
]],

'export-employee-file-2026-8-6-23_57.csv' => ['work', 'ref.People', [
    'Employee' => 'DisplayName', 'Email' => 'Email',
    'Primary Org' => 'OrgPath', 'Room' => 'RoomText',
]],
];

/** Read a source file into [header, rows]. */
function src_read(string $path): array
{
    if (str_ends_with(strtolower($path), '.xlsx')) {
        $rows = xlsx_read($path, 0);
    } else {
        $rows = [];
        $h = fopen($path, 'r');
        while (($r = fgetcsv($h)) !== false) {
            $rows[] = $r;
        }
        fclose($h);
    }
    if (!$rows) {
        return [[], []];
    }
    $head = array_map(fn($v) => trim((string)$v), array_shift($rows));
    return [$head, $rows];
}

function db_columns(string $dbName, string $table): array
{
    $out = [];
    try {
        foreach (db($dbName)->query(
            "SELECT name FROM sys.columns WHERE object_id = OBJECT_ID('$table')") as $c) {
            $out[strtolower((string)$c['name'])] = (string)$c['name'];
        }
    } catch (Throwable $e) {
        // reported by the caller as a missing table
    }
    return $out;
}

$root = dirname(__DIR__) . DIRECTORY_SEPARATOR;
$problems = [];
$totalLostRows = 0;

foreach (SOURCES as $file => [$dbName, $table, $map]) {
    $path = $root . str_replace('/', DIRECTORY_SEPARATOR, $file);
    echo str_repeat('=', 78), "\n$file\n   -> " . APP_DB_LABELS[$dbName] . " . $table\n";

    if (!is_file($path)) {
        echo "   FILE NOT FOUND\n\n";
        $problems[] = "$file: not found";
        continue;
    }

    [$head, $rows] = src_read($path);
    $cols = db_columns($dbName, $table);
    if (!$cols) {
        echo "   TABLE NOT FOUND\n\n";
        $problems[] = "$file -> $table: table not found";
        continue;
    }

    // How many rows carry a value in each source column.
    $fill = array_fill(0, count($head), 0);
    foreach ($rows as $r) {
        foreach ($head as $i => $_) {
            if (trim((string)($r[$i] ?? '')) !== '') {
                $fill[$i]++;
            }
        }
    }

    $dropped = [];
    foreach ($head as $i => $name) {
        if ($name === '') {
            continue;
        }
        $known = array_key_exists($name, $map);
        $dest = $map[$name] ?? null;

        // "est.Agreements.TenureStatus" names its own table: one export
        // can feed several, and the estate workbook feeds two.
        $destTable = $table;
        $destCol = $dest;
        $destCols = $cols;
        if ($dest !== null && substr_count($dest, '.') === 2) {
            $bits = explode('.', $dest);
            $destCol = array_pop($bits);
            $destTable = implode('.', $bits);
            $destCols = db_columns($dbName, $destTable);
        }
        $inDb = $destCol !== null && isset($destCols[strtolower($destCol)]);

        if (!$known) {
            $state = 'NOBODY LOOKED AT IT';
        } elseif ($dest === null) {
            $state = 'deliberately not kept';
        } elseif (!$inDb) {
            $state = 'LOST - no such column';
        } else {
            $state = 'kept as ' . ($destTable === $table ? '' : $destTable . '.')
                   . $destCols[strtolower($destCol)];
        }

        $bad = str_contains($state, 'LOST') || str_contains($state, 'NOBODY');
        if ($bad && $fill[$i] > 0) {
            $dropped[] = [$name, $fill[$i], $state];
            $totalLostRows += $fill[$i];
        }
        if ($verbose || $bad) {
            printf("   %-38s %6s  %s\n", $name, number_format($fill[$i]), $state);
        }
    }

    $srcRows = count($rows);
    $dbRows = 0;
    try {
        $dbRows = (int)db($dbName)->query("SELECT COUNT(*) FROM $table")->fetchColumn();
    } catch (Throwable $e) {
    }
    printf("   rows: %s in the file, %s in the table%s\n",
        number_format($srcRows), number_format($dbRows),
        $dbRows < $srcRows ? '   <-- FEWER' : '');

    if ($dropped) {
        $problems[] = sprintf('%s: %d column(s) with data not kept - %s',
            basename($file), count($dropped),
            implode(', ', array_map(fn($d) => $d[0] . ' (' . number_format($d[1]) . ')', $dropped)));
    }
    echo "\n";
}

echo str_repeat('=', 78), "\n";
if (!$problems) {
    echo "Every column carrying a value is accounted for.\n";
} else {
    echo count($problems) . " source(s) with something unaccounted for:\n\n";
    foreach ($problems as $p) {
        echo "  * $p\n";
    }
    echo "\n" . number_format($totalLostRows) . " values in total sit in a file and not in a database.\n";
}
