<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * CSV export and import (spec Section 12). Exports carry natural keys —
 * building number, survey code, NetID — never surrogate identifiers.
 * UTF-8 with a byte order mark so Excel opens accented characters
 * correctly.
 */

/**
 * Make one cell safe to open in a spreadsheet.
 *
 * Excel and Calc treat a cell beginning with =, +, -, @, a tab or a
 * carriage return as a FORMULA, not as text. A person who types
 * =HYPERLINK("http://...","click") into a request description has, until
 * now, been writing a formula into every export of that register - it
 * runs on whoever opens the file. A leading apostrophe makes the cell
 * text; the spreadsheet does not display it.
 *
 * Numbers are left alone so columns still sum.
 */
function csv_cell(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    if (is_int($value) || is_float($value) || is_bool($value)) {
        return (string)$value;
    }
    $s = (string)$value;
    if ($s !== '' && str_contains("=+-@	", $s[0])) {
        return "'" . $s;
    }
    return $s;
}

/** Guard a whole row. */
function csv_row(array $row): array
{
    return array_map('csv_cell', $row);
}

function csv_send(string $filename, array $header, iterable $rows): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");        // BOM for Excel
    fputcsv($out, csv_row($header));
    foreach ($rows as $row) {
        fputcsv($out, csv_row((array)$row));
    }
    fclose($out);
}

/** Column definitions per dataset: header label => row callable. */
function csv_datasets(): array
{
    return [
        'work' => [
            'label'  => 'Work requests',
            'file'   => 'work_requests.csv',
            'header' => ['Ticket Number', 'Title', 'Description', 'Building Number', 'Building Name',
                         'Room', 'Written Location', 'Work Type', 'Nature', 'Owner', 'Survey Code',
                         'Reported Severity', 'Triaged Severity', 'Life Safety', 'Specimen Risk',
                         'Estimate Amount', 'Status', 'Priority Base', 'Urgency Band', 'Confirmations',
                         'Requester NetID', 'On Behalf Of', 'Needed By', 'Handoff Reference',
                         'Created At', 'Closed At'],
        ],
        'move' => [
            'label'  => 'Move items (one row per item)',
            'file'   => 'move_items.csv',
            'header' => ['Ticket Number', 'Requester NetID', 'Survey Code', 'Needed By', 'Person Moving',
                         'Request Status', 'Item Number', 'Move Type', 'Item Type', 'PTag', 'PRI Tag',
                         'Serial Number', 'Description', 'From Building Number', 'From Room',
                         'To Building Number', 'To Room', 'Current Custodian', 'New Custodian',
                         'Tech Item', 'Created At'],
        ],
        'shop' => [
            'label'  => 'Shop requests',
            'file'   => 'shop_requests.csv',
            'header' => ['Ticket Number', 'Requester NetID', 'CFOP', 'Survey Code', 'Account PI',
                         'Fund Manager', 'Work Description', 'Business Justification',
                         'Building Number', 'Room', 'Estimate Amount', 'Needed By', 'Status',
                         'Created At', 'Closed At'],
        ],
        'space' => [
            'label'  => 'Space requests',
            'file'   => 'space_requests.csv',
            'header' => ['Ticket Number', 'Requester NetID', 'Submitted By', 'Requester Email',
                         'Survey Code', 'Request Kind', 'Type of Space', 'Other Type',
                         'Requested Change', 'Intended Use', 'People Affected',
                         'Current Building', 'Current Room', 'Current Room Code',
                         'Requested Building', 'Requested Room', 'Requested Room Code',
                         'Potential Costs', 'Account', 'Account PI', 'Fund Manager',
                         'Account Survey', 'Funding Notes', 'Needed By', 'Justification',
                         'Status', 'Assigned Room Code', 'Created At', 'Closed At'],
        ],
        'purchase' => [
            'label'  => 'Purchase items (one row per line)',
            'file'   => 'purchase_items.csv',
            'header' => ['Ticket Number', 'Legacy Reference', 'Requester NetID', 'Buyer',
                         'Survey Code', 'Purchase Date', 'Purchase Method', 'Destination',
                         'Building Number', 'Room', 'Room Code', 'Vendor', 'Status',
                         'Order Reference', 'Item Number', 'Catalog Number', 'Description',
                         'Quantity', 'Units Per Item', 'Unit Cost', 'Amount', 'Account Code',
                         'Is Equipment', 'Expected For', 'Property Tag', 'Tagged By',
                         'Request Total', 'Created At'],
        ],
        'purchasefunding' => [
            'label'  => 'Purchase funding and approvals (one row per fund line)',
            'file'   => 'purchase_funding.csv',
            'header' => ['Ticket Number', 'Requester NetID', 'Request Status', 'Request Total',
                         'Split Method', 'Fund Number', 'CFOP', 'Chart', 'Fund', 'Organisation',
                         'Program', 'Activity', 'Account Description', 'Account Survey',
                         'Amount Kind', 'Amount', 'PI Name', 'PI NetID', 'PI Proxy NetID',
                         'PI Decision', 'PI Decided By', 'PI Decided At', 'PI Comment',
                         'Fund Manager', 'Fund Manager NetID', 'Fund Manager Decision',
                         'Fund Manager Decided By', 'Fund Manager Decided At',
                         'Fund Manager Comment'],
        ],
        'key' => [
            'label'  => 'Key requests',
            'file'   => 'key_requests.csv',
            'header' => ['Ticket Number', 'Requester NetID', 'Holder Name', 'Holder NetID',
                         'Employment Status', 'Survey Code', 'Building Number', 'Floor',
                         'Room', 'Room Code', 'Key Type', 'Quantity', 'Request Kind',
                         'Needed By', 'Justification', 'Status', 'Decision Note',
                         'Created At', 'Closed At'],
        ],
    ];
}

function csv_rows(string $dataset, array $filters = []): array
{
    $rows = [];
    if ($dataset === 'work') {
        $sql = 'SELECT w.*, b.Name AS BuildingName, t.Name AS TypeName, o.Code AS OwnerCode,
                       n.Name AS NatureName,
                       (SELECT COUNT(*) FROM wr.Corroborations c WHERE c.WorkRequestID = w.WorkRequestID) AS Confirmations
                FROM wr.WorkRequests w
                JOIN ref.Buildings b ON b.BuildingID = w.BuildingID
                JOIN ref.WorkTypes t ON t.WorkTypeID = w.WorkTypeID
                JOIN ref.Owners o ON o.OwnerID = w.OwnerID
                LEFT JOIN ref.Natures n ON n.NatureID = w.NatureID';
        [$where, $params] = csv_filter_sql($filters, 'w');
        $stmt = db('work')->prepare($sql . $where);
        $stmt->execute($params);
        foreach ($stmt as $r) {
            $rows[] = [$r['TicketNumber'], $r['Title'], $r['Description'], $r['BldgNumber'], $r['BuildingName'],
                       $r['RoomNumber'], $r['WrittenLocation'], $r['TypeName'], $r['NatureName'], $r['OwnerCode'],
                       $r['SurveyCode'], $r['ReportedSeverity'], $r['TriagedSeverity'],
                       $r['LifeSafety'] ? 'Yes' : 'No', $r['SpecimenRisk'] ? 'Yes' : 'No',
                       $r['EstimateAmount'], $r['Status'], $r['PriorityBase'], $r['UrgencyBand'],
                       $r['Confirmations'], $r['CreatedByNetID'], $r['OnBehalfOfName'], $r['NeededByDate'],
                       $r['HandoffReference'] ?? '', $r['CreatedAt'], $r['ClosedAt']];
        }
    } elseif ($dataset === 'move') {
        $sql = 'SELECT m.*, i.* FROM mv.MoveRequests m
                JOIN mv.MoveItems i ON i.MoveRequestID = m.MoveRequestID';
        [$where, $params] = csv_filter_sql($filters, 'm');
        $stmt = db('move')->prepare($sql . $where . ' ORDER BY m.MoveRequestID, i.MoveItemID');
        $stmt->execute($params);
        $n = 0;
        $lastTicket = null;
        foreach ($stmt as $r) {
            $n = ($r['TicketNumber'] === $lastTicket) ? $n + 1 : 1;
            $lastTicket = $r['TicketNumber'];
            $rows[] = [$r['TicketNumber'], $r['RequestedByNetID'], $r['SurveyCode'], $r['NeededByDate'],
                       $r['IsPersonMoving'] ? 'Yes' : 'No', $r['Status'], $n, $r['MoveType'], $r['ItemType'],
                       $r['PTag'], $r['PRITag'], $r['SerialNumber'], $r['Description'],
                       $r['FromBldgNumber'], $r['FromRoom'], $r['ToBldgNumber'], $r['ToRoom'],
                       $r['CurrentCustodian'], $r['NewCustodian'], $r['IsTechItem'] ? 'Yes' : 'No',
                       $r['CreatedAt']];
        }
    } elseif ($dataset === 'space' || $dataset === 'key') {
        // Space and key requests live in the Work database under rq, and
        // are filtered on the same survey and status as the rest.
        $table = $dataset === 'space' ? 'rq.SpaceRequests' : 'rq.KeyRequests';
        [$where, $params] = csv_filter_sql($filters, 'r');
        $stmt = db($dataset === 'space' ? 'space' : 'keys')
            ->prepare("SELECT r.* FROM $table r" . $where . ' ORDER BY r.CreatedAt');
        $stmt->execute($params);
        foreach ($stmt as $r) {
            $rows[] = $dataset === 'space'
                ? [$r['TicketNumber'], $r['RequestedByNetID'], $r['SubmittedByNames'],
                   $r['RequesterEmail'], $r['SurveyCode'], $r['RequestKind'], $r['SpaceType'],
                   $r['SpaceTypeOther'], $r['RequestedChange'], $r['SpaceUse'], $r['PeopleAffected'],
                   $r['CurrentBldgNumber'], $r['CurrentRoomID'], $r['CurrentRoomCode'],
                   $r['RequestedBldgNumber'], $r['RequestedRoomID'], $r['RequestedRoomCode'],
                   $r['PotentialCosts'], $r['FundingCfop'], $r['FundingPI'], $r['FundingFundManager'],
                   $r['FundingSurveyCode'], $r['FundingSource'], $r['NeededByDate'],
                   $r['Justification'], $r['Status'], $r['AssignedRoomCode'],
                   $r['CreatedAt'], $r['ClosedAt']]
                : [$r['TicketNumber'], $r['RequestedByNetID'], $r['HolderName'], $r['HolderNetID'],
                   $r['EmpStatus'], $r['SurveyCode'], $r['BldgNumber'], $r['FloorCode'],
                   $r['RoomID'], $r['RoomCode'], $r['KeyType'], $r['Quantity'], $r['RequestKind'],
                   $r['NeededByDate'], $r['Justification'], $r['Status'], $r['DecisionNote'],
                   $r['CreatedAt'], $r['ClosedAt']];
        }
    } elseif ($dataset === 'purchase') {
        // One row per product line, the way a purchase is actually read:
        // what was bought, off which code, and whether it turned into a
        // property record.
        $sql = 'SELECT r.*, i.ItemNo, i.CatalogNumber, i.Description AS ItemDescription,
                       i.Quantity, i.UnitsPerItem, i.UnitCost, i.AccountCode, i.IsEquipment,
                       i.ExpectedFor, i.PropertyTag, i.TaggedByNetID
                FROM rq.PurchaseRequests r
                JOIN rq.PurchaseItems i ON i.PurchaseRequestID = r.PurchaseRequestID';
        [$where, $params] = csv_filter_sql($filters, 'r');
        $stmt = db('purchasing')->prepare($sql . $where
            . ' ORDER BY r.PurchaseRequestID, i.ItemNo');
        $stmt->execute($params);
        foreach ($stmt as $r) {
            $rows[] = [$r['TicketNumber'], $r['LegacyReference'], $r['RequestedByNetID'],
                       $r['StaffRequesting'], $r['SurveyCode'], $r['PurchaseReqDate'],
                       $r['PurchaseType'], $r['Destination'], $r['DeliverBldgNumber'],
                       $r['DeliverRoomID'], $r['DeliverRoomCode'], $r['VendorName'], $r['Status'],
                       $r['OrderReference'], $r['ItemNo'], $r['CatalogNumber'],
                       $r['ItemDescription'], $r['Quantity'], $r['UnitsPerItem'], $r['UnitCost'],
                       (float)$r['Quantity'] * (float)$r['UnitCost'], $r['AccountCode'],
                       $r['IsEquipment'] ? 'Yes' : 'No', $r['ExpectedFor'], $r['PropertyTag'],
                       $r['TaggedByNetID'], $r['TotalCost'], $r['CreatedAt']];
        }
    } elseif ($dataset === 'purchasefunding') {
        // The approval grid, flattened. Both decisions on a fund line sit
        // on the same row, so a spreadsheet can be sorted by who has not
        // answered yet.
        $sql = 'SELECT r.TicketNumber, r.RequestedByNetID, r.Status, r.TotalCost, r.SplitMethod,
                       r.SurveyCode AS ReqSurvey, f.*
                FROM rq.PurchaseRequests r
                JOIN rq.PurchaseFunding f ON f.PurchaseRequestID = r.PurchaseRequestID';
        [$where, $params] = csv_filter_sql($filters, 'r');
        $stmt = db('purchasing')->prepare($sql . $where
            . ' ORDER BY r.PurchaseRequestID, f.FundNo');
        $stmt->execute($params);
        foreach ($stmt as $r) {
            $rows[] = [$r['TicketNumber'], $r['RequestedByNetID'], $r['Status'], $r['TotalCost'],
                       $r['SplitMethod'], $r['FundNo'], $r['CfopCode'], $r['Coa'], $r['FundCode'],
                       $r['OrgCode'], $r['ProgramCode'], $r['ActivityCode'],
                       $r['AccountDescription'], $r['SurveyCode'], $r['AmountKind'],
                       $r['AmountValue'], $r['PiName'], $r['PiNetID'], $r['PiProxyNetID'],
                       $r['PiStatus'], $r['PiDecidedByNetID'], $r['PiDecidedAt'], $r['PiComment'],
                       $r['FmName'], $r['FmNetID'], $r['FmStatus'], $r['FmDecidedByNetID'],
                       $r['FmDecidedAt'], $r['FmComment']];
        }
    } else {
        $sql = 'SELECT s.* FROM sh.ShopRequests s';
        [$where, $params] = csv_filter_sql($filters, 's');
        $stmt = db('shop')->prepare($sql . $where);
        $stmt->execute($params);
        foreach ($stmt as $r) {
            $rows[] = [$r['TicketNumber'], $r['RequestedByNetID'], $r['CfopCode'], $r['SurveyCode'],
                       $r['AccountPI'], $r['FundManager'], $r['WorkDescription'], $r['BusinessJustification'],
                       $r['BldgNumber'], $r['RoomNumber'], $r['EstimateAmount'], $r['NeededByDate'],
                       $r['Status'], $r['CreatedAt'], $r['ClosedAt']];
        }
    }
    return $rows;
}

/** Board filters translate into the same WHERE the board used. */
function csv_filter_sql(array $f, string $alias): array
{
    $where = [];
    $params = [];
    if (!empty($f['survey'])) {
        $where[] = "$alias.SurveyCode = ?";
        $params[] = $f['survey'];
    }
    if (!empty($f['status'])) {
        $where[] = "$alias.Status = ?";
        $params[] = $f['status'];
    }
    return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
}

/** Parse an uploaded CSV into header + rows, tolerating a BOM. */
function csv_parse(string $path): array
{
    $fh = fopen($path, 'r');
    if (!$fh) {
        throw new RuntimeException('Cannot read the uploaded file.');
    }
    $header = fgetcsv($fh);
    if ($header && isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
    }
    $rows = [];
    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) === 1 && trim((string)$row[0]) === '') {
            continue;
        }
        $rows[] = $row;
    }
    fclose($fh);
    return [$header ?: [], $rows];
}

/** Resolve a building by number, then by alias, for import (spec 12.2). */
function csv_resolve_building(PDO $pdo, string $text): ?array
{
    $text = trim($text);
    if ($text === '') {
        return null;
    }
    $stmt = $pdo->prepare('SELECT BuildingID, BldgNumber, Name FROM ref.Buildings WHERE BldgNumber = ?');
    $stmt->execute([$text]);
    if ($row = $stmt->fetch()) {
        return $row;
    }
    $norm = preg_replace('/\s+/', ' ', trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($text))));
    $stmt = $pdo->prepare('SELECT TOP 1 b.BuildingID, b.BldgNumber, b.Name
                           FROM ref.BuildingAliases a JOIN ref.Buildings b ON b.BuildingID = a.BuildingID
                           WHERE a.AliasNorm = ?');
    $stmt->execute([$norm]);
    return $stmt->fetch() ?: null;
}
