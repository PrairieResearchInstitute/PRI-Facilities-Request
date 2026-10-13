<?php
declare(strict_types=1);

/**
 * Put back what the loaders threw away.
 *
 * tools/source_audit.php found five places where a column arrived in a
 * file somebody exported and never reached a database. This adds the
 * columns and fills them from those same files. Nothing here invents a
 * value: every figure is read from the file it came in on, and a row the
 * file has nothing to say about is left alone.
 *
 * Safe to run twice. Columns are added only if absent, and every write
 * is conditional on the target being empty, so a second run over
 * corrected data does not undo the correction.
 *
 *   php tools/recover_dropped_data.php            what it would do
 *   php tools/recover_dropped_data.php --apply    do it
 */

require_once __DIR__ . '/../facilities/lib/db.php';
db_localdb_only('tools/recover_dropped_data.php');
require_once __DIR__ . '/../facilities/lib/xlsxread.php';

app_config();
$apply = in_array('--apply', $argv, true);
$root = dirname(__DIR__) . DIRECTORY_SEPARATOR;

function say(string $s): void
{
    echo $s . "\n";
}

/** Add a column if it is not already there. Returns true if it added one. */
function add_column(PDO $pdo, string $table, string $col, string $type, bool $apply): bool
{
    $has = (int)$pdo->query(
        "SELECT COUNT(*) FROM sys.columns
          WHERE object_id = OBJECT_ID('$table') AND name = '$col'")->fetchColumn();
    if ($has) {
        return false;
    }
    if ($apply) {
        $pdo->exec("ALTER TABLE $table ADD [$col] $type NULL");
    }
    return true;
}

/** '-----' and friends mean nobody filled it in, not a value. */
function clean(?string $v): ?string
{
    $v = trim((string)$v);
    if ($v === '' || preg_match('/^-+$/', $v) || strcasecmp($v, 'n/a') === 0) {
        return null;
    }
    return $v;
}

// =====================================================================
// 1. The estate. Facts about the building go on the building; the
//    arrangement that lets PRI occupy it becomes an agreement.
// =====================================================================
say('=== 1. Buildings: what sort, who owns it, what condition ===');

/* ref.Buildings is published into every database, so a column added in
   one and not the others makes the next publish fail on the ones that
   are short of it. All ten, or none. */
const BUILDING_COLUMNS = [
    'Name2'        => 'NVARCHAR(300)',
    'AddressName'  => 'NVARCHAR(200)',
    'StateCode'    => 'NVARCHAR(10)',
    'PostalCode'   => 'NVARCHAR(20)',
    'LocationClass' => 'NVARCHAR(30)',
    'BuildingType' => 'NVARCHAR(60)',
    'Ownership'    => 'NVARCHAR(40)',
    'Condition'    => 'NVARCHAR(30)',
    'SurveyCode'   => 'NVARCHAR(10)',
    'PriPriority'  => 'INT',
];

$added = 0;
foreach (APP_DB_NAMES as $name) {
    $pdo = db($name);
    $here = 0;
    foreach (BUILDING_COLUMNS as $col => $type) {
        if (add_column($pdo, 'ref.Buildings', $col, $type, $apply)) {
            $here++;
        }
    }
    if ($here) {
        say(sprintf('    %-28s %d column(s)', APP_DB_LABELS[$name], $here));
        $added += $here;
    }
}
say($added ? "    $added column(s) in total" : '    already present everywhere');

// ---- fill them from the workbook -----------------------------------
$wbPath = $root . 'reference' . DIRECTORY_SEPARATOR . 'gis' . DIRECTORY_SEPARATOR . 'Facilities' . DIRECTORY_SEPARATOR
        . 'All_PRI_Buildings_v06_2023_02_05_from GIS.xlsx';
if (!is_file($wbPath)) {
    say('    workbook not found, nothing to fill from');
    exit(1);
}
$wb = xlsx_read($wbPath, 0);
$head = array_map(fn($v) => trim((string)$v), array_shift($wb));
$col = array_flip($head);
$get = function (array $row, string $name) use ($col): ?string {
    return isset($col[$name]) ? clean((string)($row[$col[$name]] ?? '')) : null;
};

$space = db('space');
$filled = 0;
$noBuilding = [];
$agreements = [];

foreach ($wb as $row) {
    $bldg = clean((string)($row[$col['Building']] ?? ''));
    if ($bldg === null) {
        continue;
    }
    // The workbook writes 507 where the estate writes 0507.
    $padded = str_pad($bldg, 4, '0', STR_PAD_LEFT);

    $st = $space->prepare('SELECT BuildingID, BldgNumber FROM ref.Buildings
                            WHERE BldgNumber = ? OR BldgNumber = ?');
    $st->execute([$bldg, $padded]);
    $b = $st->fetch();
    if (!$b) {
        $noBuilding[] = $bldg . '  ' . (string)$get($row, 'Name_1');
        continue;
    }

    $vals = [
        'Name2'         => $get($row, 'Name_2'),
        'AddressName'   => $get($row, 'Address1'),
        'StateCode'     => $get($row, 'Address4'),
        'PostalCode'    => $get($row, 'Address5'),
        'LocationClass' => $get($row, 'Location'),
        'BuildingType'  => $get($row, 'Type'),
        'Ownership'     => $get($row, 'Property Ownership'),
        'Condition'     => $get($row, 'Condition'),
        'SurveyCode'    => $get($row, 'Survey'),
        'PriPriority'   => $get($row, 'PRI Priority'),
    ];

    if ($apply) {
        // Only where the column is still empty, so a correction made in
        // the application is not overwritten by a stale spreadsheet.
        $sets = [];
        $args = [];
        foreach ($vals as $c => $v) {
            if ($v === null) {
                continue;
            }
            $sets[] = "[$c] = CASE WHEN " . ($c === 'PriPriority'
                        ? "[$c] IS NULL" : "ISNULL(CAST([$c] AS NVARCHAR(300)), '') = ''")
                    . " THEN ? ELSE [$c] END";
            $args[] = $c === 'PriPriority' ? (int)$v : $v;
        }
        if ($sets) {
            $args[] = (int)$b['BuildingID'];
            $space->prepare('UPDATE ref.Buildings SET ' . implode(', ', $sets)
                          . ' WHERE BuildingID = ?')->execute($args);
        }
    }
    $filled++;

    // An agreement only exists where somebody else owns the building.
    $owner = $get($row, 'Property Ownership');
    $status = $get($row, 'Status');
    $reference = $get($row, 'Lease Reference');
    if ($owner !== null && strcasecmp($owner, 'UI') !== 0
        && ($status !== null || $reference !== null || $get($row, 'Terms') !== null)) {
        $agreements[] = [
            'BuildingID'     => (int)$b['BuildingID'],
            'BldgNumber'     => (string)$b['BldgNumber'],
            'AgreementKind'  => agreement_kind($status, $get($row, 'Terms')),
            'Reference'      => $reference,
            'Title'          => trim((string)$get($row, 'Name_1') . ' - '
                                . ($status ?? 'arrangement')),
            'TenureStatus'   => $status,
            'TermsBasis'     => $get($row, 'Terms'),
            'LeaseCostBasis' => $get($row, 'Lease Cost'),
            'OmCostBasis'    => $get($row, 'O&M Cost'),
            'ContractShare'  => share($get($row, 'Contract - Lease, Utilties, and O&M')),
            'IcrShare'       => share($get($row, 'ICR - Lease, Utilties, and O&M')),
            'Status'         => $status === null ? 'unknown' : 'current',
        ];
    }
}

function agreement_kind(?string $status, ?string $terms): string
{
    $s = strtolower((string)$status);
    if (str_contains($s, 'ground lease') || str_contains($s, 'leasehold')) {
        return 'ground_lease';
    }
    if (str_contains($s, 'sponsor')) {
        return 'sponsor';
    }
    if (str_contains($s, 'lease')) {
        return 'lease';
    }
    if (str_contains(strtolower((string)$terms), 'idnr legacy')) {
        return 'legacy';
    }
    return 'unknown';
}

function share(?string $v): ?string
{
    if ($v === null || !is_numeric($v)) {
        return null;
    }
    $f = (float)$v;
    return ($f >= 0 && $f <= 1) ? number_format($f, 4, '.', '') : null;
}

say('    ' . $filled . ' building(s) matched and filled');
if ($noBuilding) {
    say('    ' . count($noBuilding) . ' row(s) in the workbook name a building that is not '
        . 'on the list:');
    foreach (array_slice($noBuilding, 0, 8) as $n) {
        say('        ' . $n);
    }
}

// ---- the agreements -------------------------------------------------
say('');
say('=== 2. Leases, sponsor arrangements and memoranda ===');
$haveTable = (int)$space->query(
    "SELECT CASE WHEN OBJECT_ID('est.Agreements') IS NULL THEN 0 ELSE 1 END")->fetchColumn();
if (!$haveTable) {
    say('    est.Agreements does not exist yet. Apply sql/35_estate.sql first.');
} else {
    $already = (int)$space->query('SELECT COUNT(*) FROM est.Agreements')->fetchColumn();
    say('    ' . count($agreements) . ' arrangement(s) in the workbook, '
        . $already . ' already recorded');
    if ($apply && $already === 0) {
        $ins = $space->prepare(
            'INSERT INTO est.Agreements
                (BuildingID, BldgNumber, AgreementKind, Reference, Title, TenureStatus,
                 TermsBasis, LeaseCostBasis, OmCostBasis, ContractShare, IcrShare,
                 Status, SourceLabel)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($agreements as $a) {
            $ins->execute([
                $a['BuildingID'], $a['BldgNumber'], $a['AgreementKind'], $a['Reference'],
                $a['Title'], $a['TenureStatus'], $a['TermsBasis'], $a['LeaseCostBasis'],
                $a['OmCostBasis'], $a['ContractShare'], $a['IcrShare'], $a['Status'],
                'All_PRI_Buildings v06 2023-02-05',
            ]);
        }
        say('    ' . count($agreements) . ' recorded');
    }
}

// =====================================================================
// 3. The inventory: the purchase document and the depreciation.
// =====================================================================
say('');
say('=== 3. Inventory: purchase document and depreciation ===');
$eq = db('equipment');
$n = 0;
foreach ([
    'PurchaseDocument'        => 'NVARCHAR(60)',   // as C3PO writes it
    'PoNumber'                => 'NVARCHAR(30)',   // the P number on its own
    'RequisitionNumber'       => 'NVARCHAR(30)',   // the FY reference on its own
    'PurchaseDocKind'         => 'NVARCHAR(12)',   // a purchase order, or a P-Card charge
    'AccumulatedDepreciation' => 'DECIMAL(14,2)',
] as $c => $t) {
    if (add_column($eq, 'gis.Inventory', $c, $t, $apply)) {
        $n++;
        say("    added gis.Inventory.$c");
    }
}
if (!$n) {
    say('    columns already present');
}

$csv = $root . 'C3PO Data' . DIRECTORY_SEPARATOR . 'C3poInventoryPage20260806184755.csv';
$hit = $miss = $poSplit = 0;
if (is_file($csv) && $apply && $n >= 0) {
    $h = fopen($csv, 'r');
    $ch = array_map(fn($v) => trim((string)$v), fgetcsv($h));
    $ci = array_flip($ch);
    $upd = $eq->prepare(
        'UPDATE gis.Inventory
            SET PurchaseDocument = CASE WHEN ISNULL(PurchaseDocument, \'\') = \'\'
                                        THEN ? ELSE PurchaseDocument END,
                PoNumber = CASE WHEN ISNULL(PoNumber, \'\') = \'\' THEN ? ELSE PoNumber END,
                RequisitionNumber = CASE WHEN ISNULL(RequisitionNumber, \'\') = \'\'
                                         THEN ? ELSE RequisitionNumber END,
                PurchaseDocKind = CASE WHEN ISNULL(PurchaseDocKind, \'\') = \'\'
                                       THEN ? ELSE PurchaseDocKind END,
                AccumulatedDepreciation = CASE WHEN AccumulatedDepreciation IS NULL
                                               THEN ? ELSE AccumulatedDepreciation END
          WHERE PropertyTag = ?');
    while (($r = fgetcsv($h)) !== false) {
        $tag = trim((string)($r[$ci['Property Tag (PTag) #']] ?? ''));
        if ($tag === '') {
            continue;
        }
        $doc = clean((string)($r[$ci['Purchase Document']] ?? ''));
        $po = $req = $kind = null;
        // "P2261719 (FY22-3711)" is a purchase order and the requisition
        // it came from. Kept whole and also split, because the whole
        // string is what C3PO shows and the parts are what joins.
        //
        // Not all of them are purchase orders. "PCA30CQT (FY19-1091)" is
        // a P-Card charge, which is bought and reconciled by a different
        // route entirely, so which kind it is gets recorded rather than
        // left for somebody to infer from the shape of the string.
        if ($doc !== null && preg_match('/^\s*([A-Z]{1,3}[0-9A-Z]+)\s*(?:\(([^)]+)\))?/i',
                                        $doc, $m)) {
            $po = strtoupper($m[1]);
            $req = isset($m[2]) ? trim($m[2]) : null;
            $kind = str_starts_with($po, 'PCA') ? 'pcard'
                  : (preg_match('/^P\d+$/', $po) ? 'po' : 'other');
            if ($req !== null) {
                $poSplit++;
            }
        }
        $dep = trim((string)($r[$ci['Depreciation']] ?? ''));
        $dep = $dep === '' ? null : (float)str_replace([',', '$'], '', $dep);

        $upd->execute([$doc, $po, $req, $kind, $dep, $tag]);
        if ($upd->rowCount() > 0) {
            $hit++;
        } else {
            $miss++;
        }
    }
    fclose($h);
    say("    $hit item(s) updated, $miss tag(s) in the file with no matching item");
    say("    $poSplit purchase document(s) carried a requisition as well as a PO number");
} elseif (!$apply) {
    say('    would fill from ' . basename($csv));
}

// =====================================================================
// 4. The accounts that were never loaded.
// =====================================================================
say('');
say('=== 4. Accounts missing from the directory ===');
require_once __DIR__ . '/../facilities/lib/cfoppaste.php';
$parsed = cfop_parse((string)file_get_contents($root . 'activeGrants.csv'));
$shop = db('shop');
$chk = $shop->prepare('SELECT COUNT(*) FROM ref.CfopAccounts WHERE CfopCode = ?');
$missing = [];
foreach ($parsed['rows'] ?? [] as $rec) {
    $code = strtoupper((string)$rec['CfopCode']);
    $chk->execute([$code]);
    if (!(int)$chk->fetchColumn()) {
        $missing[] = $rec;
    }
}
say('    ' . count($missing) . ' of ' . count($parsed['rows'] ?? [])
    . ' account(s) in the file are not in the directory');
if ($apply && $missing) {
    $ins = $shop->prepare(
        'INSERT INTO ref.CfopAccounts (CfopCode, SurveyCode, AccountPI, FundManager,
            ActivityCode, Description, FundType, AccountPIEmail, FundManagerEmail,
            AccountPINetID, EndsOn, SourceLabel, UpdatedAt)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?, SYSDATETIME())');
    $done = 0;
    foreach ($missing as $rec) {
        try {
            $ins->execute([strtoupper((string)$rec['CfopCode']), $rec['SurveyCode'] ?? null,
                $rec['AccountPI'] ?? '', $rec['FundManager'] ?? '', $rec['ActivityCode'] ?? null,
                $rec['Description'] ?? null, $rec['FundType'] ?? null,
                $rec['AccountPIEmail'] ?? null, $rec['FundManagerEmail'] ?? null,
                $rec['AccountPINetID'] ?? null, $rec['EndsOn'] ?? null,
                'activeGrants recovery ' . date('Y-m-d')]);
            $done++;
        } catch (Throwable $e) {
            say('        ' . $rec['CfopCode'] . ': ' . substr($e->getMessage(), 0, 90));
        }
    }
    say("    $done added");
}

// =====================================================================
// 4b. Lease end dates that were written inside the reference.
//
// Five of them read "CN-00040418 (ends 1/31/22)" or "BOT CN-22287-01
// ends 1/14/22". A date inside a text field cannot be sorted, compared
// or warned about, which is the only reason anybody records a lease end
// date at all. So it is pulled out into EndsOn and the reference is left
// as just the reference.
// =====================================================================
say('');
say('=== 4b. Lease end dates buried in the reference text ===');
$rows = $space->query(
    "SELECT AgreementID, Reference FROM est.Agreements
      WHERE Reference IS NOT NULL AND EndsOn IS NULL
        AND Reference LIKE '%end%'")->fetchAll();
$pulled = 0;
foreach ($rows as $r) {
    if (!preg_match('~ends?\s*[:\s]?\s*(\d{1,2})/(\d{1,2})/(\d{2,4})~i',
                    (string)$r['Reference'], $m)) {
        continue;
    }
    $year = (int)$m[3];
    $year += $year < 100 ? ($year < 70 ? 2000 : 1900) : 0;
    $ends = sprintf('%04d-%02d-%02d', $year, (int)$m[1], (int)$m[2]);
    // Strip the parenthetical and any trailing "ends ..." from the
    // reference, leaving the contract number on its own.
    $ref = trim(preg_replace('~\s*\(?\s*ends?\s*[:\s]?\s*\d{1,2}/\d{1,2}/\d{2,4}\s*\)?~i', '',
                             (string)$r['Reference']) ?? '');
    say(sprintf('    %-46s -> %s  ends %s',
        preg_replace('/\s+/', ' ', (string)$r['Reference']), $ref, $ends));
    if ($apply) {
        $space->prepare(
            "UPDATE est.Agreements
                SET EndsOn = ?, Reference = ?,
                    Status = CASE WHEN ? < CAST(SYSDATETIME() AS DATE) THEN 'expired'
                                  ELSE Status END,
                    UpdatedAt = SYSDATETIME()
              WHERE AgreementID = ?")
              ->execute([$ends, $ref, $ends, (int)$r['AgreementID']]);
    }
    $pulled++;
}
say($pulled ? "    $pulled date(s) recovered" : '    none left to recover');

// =====================================================================
// 5. The people columns.
// =====================================================================
say('');
say('=== 5. People: position type, home station, field station ===');
$work = db('work');
$n = 0;
foreach ([
    'PositionType' => 'NVARCHAR(60)',
    'HomeBuilding' => 'NVARCHAR(60)',
    'HomeRoom'     => 'NVARCHAR(40)',
    'FieldStation' => 'NVARCHAR(120)',
    'RoomText'     => 'NVARCHAR(200)',
] as $c => $t) {
    if (add_column($work, 'ref.People', $c, $t, $apply)) {
        $n++;
        say("    added ref.People.$c");
    }
}
if (!$n) {
    say('    columns already present');
}

if ($apply) {
    $roster = storage_dir('gis') . DIRECTORY_SEPARATOR . 'roster.csv';
    $hit = 0;
    if (is_file($roster)) {
        $h = fopen($roster, 'r');
        $rh = array_flip(array_map(fn($v) => trim((string)$v), fgetcsv($h)));
        $upd = $work->prepare(
            'UPDATE ref.People
                SET PositionType = CASE WHEN ISNULL(PositionType,\'\')=\'\' THEN ? ELSE PositionType END,
                    HomeBuilding = CASE WHEN ISNULL(HomeBuilding,\'\')=\'\' THEN ? ELSE HomeBuilding END,
                    HomeRoom     = CASE WHEN ISNULL(HomeRoom,\'\')=\'\'     THEN ? ELSE HomeRoom END,
                    FieldStation = CASE WHEN ISNULL(FieldStation,\'\')=\'\' THEN ? ELSE FieldStation END
              WHERE NetID = ?');
        while (($r = fgetcsv($h)) !== false) {
            $net = trim((string)($r[$rh['NetID']] ?? ''));
            if ($net === '') {
                continue;
            }
            $upd->execute([
                clean((string)($r[$rh['PositionType']] ?? '')),
                clean((string)($r[$rh['BuildingCommonName']] ?? '')),
                clean((string)($r[$rh['RoomID']] ?? '')),
                clean((string)($r[$rh['FieldStation']] ?? '')),
                $net,
            ]);
            $hit += $upd->rowCount() > 0 ? 1 : 0;
        }
        fclose($h);
        say("    $hit person(s) updated from the roster");
    }

    $emp = $root . 'export-employee-file-2026-8-6-23_57.csv';
    if (is_file($emp)) {
        $h = fopen($emp, 'r');
        $eh = array_flip(array_map(fn($v) => trim((string)$v), fgetcsv($h)));
        $upd = $work->prepare(
            'UPDATE ref.People SET RoomText = CASE WHEN ISNULL(RoomText,\'\')=\'\'
                                                   THEN ? ELSE RoomText END
              WHERE Email = ?');
        $hit = 0;
        while (($r = fgetcsv($h)) !== false) {
            $email = trim((string)($r[$eh['Email']] ?? ''));
            $room = clean((string)($r[$eh['Room']] ?? ''));
            if ($email === '' || $room === null) {
                continue;
            }
            $upd->execute([$room, $email]);
            $hit += $upd->rowCount() > 0 ? 1 : 0;
        }
        fclose($h);
        say("    $hit person(s) given a room from the employee file");
    }
}

say('');
say($apply ? 'Done. Run tools/source_audit.php to confirm nothing is still adrift.'
           : 'Dry run. Add --apply.');
