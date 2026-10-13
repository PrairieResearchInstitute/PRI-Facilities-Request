<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csvio.php';
require_once __DIR__ . '/rooms.php';

/**
 * Loads the extracts written by tools/gis_etl.py into the work database.
 * Each table is replaced wholesale, because the extract is the source of
 * truth and a partial load would be worse than none. Room responsibility
 * is never touched: it holds decisions made in this application.
 */

function gis_dir(): string
{
    return storage_dir('gis');
}

function gis_extract_status(): array
{
    $files = ['rooms.csv', 'floors.csv', 'buildings.csv', 'occupants.csv',
              'roster.csv', 'inventory.csv', 'safety.csv', 'keys.csv'];
    $out = [];
    foreach ($files as $f) {
        $path = gis_dir() . DIRECTORY_SEPARATOR . $f;
        $out[$f] = is_file($path)
            ? ['rows' => max(0, count(file($path)) - 1), 'when' => date('M j, Y g:ia', (int)filemtime($path))]
            : null;
    }
    return $out;
}

/** Load one extract into one table, replacing its contents. */
function gis_load_file(PDO $pdo, string $file, string $table, array $columns, array $numeric = []): int
{
    $path = gis_dir() . DIRECTORY_SEPARATOR . $file;
    if (!is_file($path)) {
        return -1;
    }
    [$header, $rows] = csv_parse($path);
    $index = [];
    foreach ($header as $i => $name) {
        $index[trim((string)$name)] = $i;
    }
    foreach ($columns as $c) {
        if (!isset($index[$c])) {
            throw new RuntimeException("$file is missing the $c column; rerun tools\\gis_etl.py");
        }
    }

    $pdo->exec("DELETE FROM $table");
    $place = implode(', ', array_fill(0, count($columns), '?'));
    $stmt = $pdo->prepare("INSERT INTO $table (" . implode(', ', $columns) . ") VALUES ($place)");

    $n = 0;
    $pdo->beginTransaction();
    try {
        foreach ($rows as $row) {
            $vals = [];
            foreach ($columns as $c) {
                $v = $row[$index[$c]] ?? null;
                $v = ($v === '' ? null : $v);
                if ($v !== null && in_array($c, $numeric, true)) {
                    $v = is_numeric($v) ? $v : null;
                }
                $vals[] = $v;
            }
            $stmt->execute($vals);
            $n++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw new RuntimeException("$file row " . ($n + 1) . ': ' . $e->getMessage());
    }
    return $n;
}

/**
 * The people directory, merged from the C3PO roster and the assigned
 * space report. Both carry a NetID, which is the thing the old form's
 * autocomplete never returned. Rows are matched on NetID so a reload
 * refreshes rather than duplicates, and anybody added by hand survives.
 */
function gis_load_people(): int
{
    $pdo = db('space');
    $seen = [];

    foreach ([['roster.csv', 'PersonName', 'Org', 'Position'],
              ['occupants.csv', 'PersonName', 'Org', null]] as [$file, $nameCol, $orgCol, $posCol]) {
        $path = gis_dir() . DIRECTORY_SEPARATOR . $file;
        if (!is_file($path)) {
            continue;
        }
        [$header, $rows] = csv_parse($path);
        $index = [];
        foreach ($header as $i => $name) {
            $index[trim((string)$name)] = $i;
        }
        if (!isset($index['NetID'], $index[$nameCol])) {
            continue;
        }
        foreach ($rows as $row) {
            $netid = strtolower(trim((string)($row[$index['NetID']] ?? '')));
            $name = trim((string)($row[$index[$nameCol]] ?? ''));
            if ($netid === '' || $name === '') {
                continue;
            }
            // The roster wins where both carry the person.
            if (isset($seen[$netid])) {
                continue;
            }
            $org = $orgCol !== null && isset($index[$orgCol])
                ? trim((string)($row[$index[$orgCol]] ?? '')) : '';
            $seen[$netid] = [
                'name' => $name,
                'org'  => $org,
                'pos'  => $posCol !== null && isset($index[$posCol])
                    ? trim((string)($row[$index[$posCol]] ?? '')) : '',
                // "ISGS/ISGS-SubsurfEnergy (ndwebb2)" starts with the survey.
                'survey' => preg_match('/^(INHS|ISGS|ISWS|ISAS|ISTC|OED)/i', $org, $m)
                    ? strtoupper($m[1]) : null,
            ];
        }
    }
    if (!$seen) {
        return -1;
    }

    $ins = $pdo->prepare('INSERT INTO ref.People (NetID, DisplayName, Email, SurveyCode, Org,
                          Position, SourceLabel, UpdatedAt)
                          VALUES (?, ?, ?, ?, ?, ?, ?, SYSDATETIME())');
    $upd = $pdo->prepare('UPDATE ref.People SET DisplayName = ?, SurveyCode = ?, Org = ?,
                          Position = ?, SourceLabel = ?, UpdatedAt = SYSDATETIME(), IsActive = 1
                          WHERE NetID = ?');
    $chk = $pdo->prepare('SELECT COUNT(*) FROM ref.People WHERE NetID = ?');

    $n = 0;
    foreach ($seen as $netid => $p) {
        $chk->execute([$netid]);
        if ((int)$chk->fetchColumn() > 0) {
            $upd->execute([$p['name'], $p['survey'], $p['org'] ?: null, $p['pos'] ?: null,
                           'C3PO', $netid]);
        } else {
            $ins->execute([$netid, $p['name'], $netid . '@illinois.edu', $p['survey'],
                           $p['org'] ?: null, $p['pos'] ?: null, 'C3PO']);
        }
        $n++;
    }
    return $n;
}

function gis_load_all(): array
{
    $pdo = db('space');
    $report = [];

    $report['gis.Rooms'] = gis_load_file($pdo, 'rooms.csv', 'gis.Rooms',
        ['RoomCode', 'BldgNumber', 'FloorCode', 'RoomID', 'RoomName', 'CollectionType',
         'CategoryCode', 'CategoryDesc', 'UseCode', 'UseDesc', 'Department', 'Division',
         'DivisionName', 'AreaSqFt', 'CentroidX', 'CentroidY', 'PathData',
         'OtherDescription', 'DrsInspect', 'CollectionReport', 'Category02', 'Desc02',
         'SpacePercent', 'SplitSpace', 'SurveyCode', 'Org1', 'Org1A', 'Org3A', 'Org4A',
         'KeyCodeRoom', 'KeyCodeBuilding'],
        ['AreaSqFt', 'CentroidX', 'CentroidY', 'SpacePercent']);

    $report['gis.Floors'] = gis_load_file($pdo, 'floors.csv', 'gis.Floors',
        ['BldgNumber', 'FloorCode', 'MinX', 'MinY', 'MaxX', 'MaxY', 'WallPath', 'DoorPath'],
        ['MinX', 'MinY', 'MaxX', 'MaxY']);

    $report['gis.CategoryColors'] = gis_load_file($pdo, 'category_colors.csv', 'gis.CategoryColors',
        ['Scope', 'CategoryCode', 'CategoryName', 'Red', 'Green', 'Blue'],
        ['Red', 'Green', 'Blue']);

    $report['gis.BuildingFootprints'] = gis_load_file($pdo, 'footprints.csv', 'gis.BuildingFootprints',
        ['BldgNumber', 'MinX', 'MinY', 'MaxX', 'MaxY', 'PathData'],
        ['MinX', 'MinY', 'MaxX', 'MaxY']);

    $report['gis.BuildingInfo'] = gis_load_file($pdo, 'buildings.csv', 'gis.BuildingInfo',
        ['BldgNumber', 'Name1', 'Name2', 'Location', 'AddressLine', 'City', 'State',
         'PostalCode', 'Longitude', 'Latitude'],
        ['Longitude', 'Latitude']);

    $report['gis.Occupants'] = gis_load_file($pdo, 'occupants.csv', 'gis.Occupants',
        ['RoomCode', 'BldgNumber', 'FloorCode', 'RoomID', 'PersonName', 'NetID', 'UIN',
         'Org', 'EmpType', 'PrimaryOffice', 'ConfirmedOn', 'Notes']);

    $report['gis.Roster'] = gis_load_file($pdo, 'roster.csv', 'gis.Roster',
        ['NetID', 'PersonName', 'Org', 'UIN', 'BuildingCommonName', 'RoomID',
         'Position', 'PositionType', 'FieldStation']);

    $report['gis.Inventory'] = gis_load_file($pdo, 'inventory.csv', 'gis.Inventory',
        ['RoomCode', 'BldgNumber', 'BannerRoom', 'PropertyTag', 'Description', 'Model',
         'SerialNumber', 'Person', 'AcquiredOn', 'Cost', 'DepreciatedValue', 'FundingSource',
         'BannerLocationCode'],
        ['Cost', 'DepreciatedValue']);

    $report['gis.Safety'] = gis_load_file($pdo, 'safety.csv', 'gis.Safety',
        ['RoomCode', 'BldgNumber', 'RoomID', 'Kind', 'Tag', 'Brand', 'Model',
         'SerialNumber', 'Descriptor', 'Status', 'LastTested']);

    // The people directory behind the "requested by" type ahead comes
    // from the same C3PO export, so a NetID is available for everybody
    // PRI employs without asking anybody for a feed.
    $report['ref.People'] = gis_load_people();

    // Key holders come from the current assignment register, pasted in on
    // the map screen, so they are not replaced by a space data load.

    // Publish the room list into all three databases and connect existing
    // requests to it.
    $report['ref.Rooms published'] = rooms_publish();
    $report['connector backfill'] = rooms_backfill();

    return $report;
}
