<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Reference data replication (spec Section 4.3). The Work database holds
 * the canonical copy; publish pushes it to Move and Shop in sequence,
 * logging each write. All matching happens on NATURAL keys — BldgNumber,
 * survey Code, owner Code, NetID, SettingKey — never on identity values,
 * because the surrogate IDs are free to differ between the three
 * databases.
 *
 * Publish is idempotent and re-runnable: a failure partway through leaves
 * drift that the next publish repairs, and the consistency report makes
 * the drift visible in the meantime.
 */

/** Simple keyed tables: natural key column + synced payload columns. */
function refsync_tables(): array
{
    return [
        // UnitKind separates the five surveys from OED, which is the
        // administrative layer above them and not a sixth survey.
        'ref.Surveys'  => ['key' => 'Code',       'cols' => ['Name', 'IsActive', 'DirectorName',
                                                             'DirectorEmail', 'ProxyName',
                                                             'ProxyEmail', 'NotifyDirector',
                                                             'UnitKind'],
                           'delete' => false],
        'ref.Owners'   => ['key' => 'Code',       'cols' => ['Name', 'HandoffEmail', 'HandoffUrl', 'ShowsOnBoard'],
                           'delete' => false],
        // The estate is the master for buildings and rooms now, so this
        // one is read from there rather than from Work. Everything else
        // in this list is still mastered in Work until the directory
        // becomes a database of its own.
        'ref.Buildings'=> ['key' => 'BldgNumber', 'cols' => ['SortKey', 'ShortCode', 'Name', 'AddressLine', 'City',
                                                             'IsOnCampus', 'IsRemote', 'Criticality', 'Latitude',
                                                             'Longitude', 'GisFeatureID', 'RequiresFreeText', 'IsActive'],
                           'delete' => false],
        'app.Users'    => ['key' => 'NetID',      'cols' => ['DisplayName', 'Email', 'SurveyCode', 'Unit', 'Phone',
                                                             'Role', 'IsProvisional', 'IsActive'],
                           'delete' => false],
        'app.Settings' => ['key' => 'SettingKey', 'cols' => ['SettingValue', 'ValueType', 'Label', 'HelpText', 'SortOrder'],
                           'delete' => false],
        // The words every form offers. Copied outward so a database can
        // draw its own dropdown without reaching into Work for the labels.
        'ref.PickLists' => ['key' => 'PickListID',
                            'cols' => ['ListKey', 'Code', 'Label', 'SortOrder', 'IsActive',
                                       'Note', 'IsProtected'],
                            'delete' => false],
        'ref.PickListTypes' => ['key' => 'ListKey',
                                'cols' => ['GroupName', 'Title', 'Description', 'UsedOn',
                                           'SortOrder'],
                                'delete' => false],
    ];
}

/** Does this database have that table at all? */
function refsync_target_has(PDO $pdo, string $table): bool
{
    [$schema, $name] = explode('.', $table, 2);
    static $cache = [];
    $ck = spl_object_id($pdo) . '|' . $table;
    if (!isset($cache[$ck])) {
        $cache[$ck] = db_has_table($pdo, $schema . '.' . $name);
    }
    return $cache[$ck];
}

function refsync_fetch_keyed(PDO $pdo, string $table, string $key, array $cols): array
{
    $colList = implode(', ', array_merge([$key], $cols));
    $rows = [];
    foreach ($pdo->query("SELECT $colList FROM $table") as $row) {
        $rows[(string)$row[$key]] = $row;
    }
    return $rows;
}

function refsync_sync_keyed(PDO $src, PDO $tgt, string $table, string $key, array $cols, bool $delete): array
{
    $sRows = refsync_fetch_keyed($src, $table, $key, $cols);
    $tRows = refsync_fetch_keyed($tgt, $table, $key, $cols);
    $inserted = $updated = $deleted = 0;

    $insCols = array_merge([$key], $cols);
    $insSql  = "INSERT INTO $table (" . implode(', ', $insCols) . ') VALUES ('
             . implode(', ', array_fill(0, count($insCols), '?')) . ')';
    $updSql  = "UPDATE $table SET " . implode(', ', array_map(fn($c) => "$c = ?", $cols))
             . " WHERE $key = ?";
    $ins = $tgt->prepare($insSql);
    $upd = $tgt->prepare($updSql);

    foreach ($sRows as $k => $row) {
        if (!isset($tRows[$k])) {
            $ins->execute(array_values(array_map(fn($c) => $row[$c], $insCols)));
            $inserted++;
        } elseif ($tRows[$k] != $row) {
            $vals = array_map(fn($c) => $row[$c], $cols);
            $vals[] = $k;
            $upd->execute($vals);
            $updated++;
        }
    }
    if ($delete) {
        $del = $tgt->prepare("DELETE FROM $table WHERE $key = ?");
        foreach (array_diff_key($tRows, $sRows) as $k => $_) {
            $del->execute([$k]);
            $deleted++;
        }
    }
    return ['inserted' => $inserted, 'updated' => $updated, 'deleted' => $deleted];
}

/** Target-local BuildingID and SurveyID maps, keyed on naturals. */
function refsync_building_map(PDO $pdo): array
{
    $map = [];
    foreach ($pdo->query('SELECT BldgNumber, BuildingID FROM ref.Buildings') as $r) {
        $map[$r['BldgNumber']] = (int)$r['BuildingID'];
    }
    return $map;
}

function refsync_survey_map(PDO $pdo): array
{
    $map = [];
    foreach ($pdo->query('SELECT Code, SurveyID FROM ref.Surveys') as $r) {
        $map[$r['Code']] = (int)$r['SurveyID'];
    }
    return $map;
}

/** Occupants: composite natural key (BldgNumber, SurveyCode); full sync. */
function refsync_sync_occupants(PDO $src, PDO $tgt): array
{
    $sSql = 'SELECT b.BldgNumber, s.Code AS SurveyCode, o.IsPrimary
             FROM ref.BuildingOccupants o
             JOIN ref.Buildings b ON b.BuildingID = o.BuildingID
             JOIN ref.Surveys  s ON s.SurveyID  = o.SurveyID';
    $srcRows = [];
    foreach ($src->query($sSql) as $r) {
        $srcRows[$r['BldgNumber'] . '|' . $r['SurveyCode']] = (int)$r['IsPrimary'];
    }
    $tgtRows = [];
    foreach ($tgt->query($sSql) as $r) {
        $tgtRows[$r['BldgNumber'] . '|' . $r['SurveyCode']] = (int)$r['IsPrimary'];
    }
    $bMap = refsync_building_map($tgt);
    $sMap = refsync_survey_map($tgt);

    $ins = $tgt->prepare('INSERT INTO ref.BuildingOccupants (BuildingID, SurveyID, IsPrimary) VALUES (?, ?, ?)');
    $upd = $tgt->prepare('UPDATE ref.BuildingOccupants SET IsPrimary = ? WHERE BuildingID = ? AND SurveyID = ?');
    $del = $tgt->prepare('DELETE FROM ref.BuildingOccupants WHERE BuildingID = ? AND SurveyID = ?');

    $inserted = $updated = $deleted = 0;
    $skipped = [];
    foreach ($srcRows as $k => $isPrimary) {
        [$bldg, $survey] = explode('|', $k);
        if (!isset($bMap[$bldg]) || !isset($sMap[$survey])) {
            $skipped[] = $k;   // parent row missing in target; buildings sync first, so this means drift
            continue;
        }
        if (!isset($tgtRows[$k])) {
            $ins->execute([$bMap[$bldg], $sMap[$survey], $isPrimary]);
            $inserted++;
        } elseif ($tgtRows[$k] !== $isPrimary) {
            $upd->execute([$isPrimary, $bMap[$bldg], $sMap[$survey]]);
            $updated++;
        }
    }
    foreach (array_diff_key($tgtRows, $srcRows) as $k => $_) {
        [$bldg, $survey] = explode('|', $k);
        if (isset($bMap[$bldg], $sMap[$survey])) {
            $del->execute([$bMap[$bldg], $sMap[$survey]]);
            $deleted++;
        }
    }
    return ['inserted' => $inserted, 'updated' => $updated, 'deleted' => $deleted, 'skipped' => $skipped];
}

/** Aliases: composite natural key (BldgNumber, AliasNorm); full sync. */
function refsync_sync_aliases(PDO $src, PDO $tgt): array
{
    $sql = 'SELECT b.BldgNumber, a.AliasNorm, a.AliasText, a.Source
            FROM ref.BuildingAliases a
            JOIN ref.Buildings b ON b.BuildingID = a.BuildingID';
    $srcRows = [];
    foreach ($src->query($sql) as $r) {
        $srcRows[$r['BldgNumber'] . '|' . $r['AliasNorm']] = ['AliasText' => $r['AliasText'], 'Source' => $r['Source']];
    }
    $tgtRows = [];
    foreach ($tgt->query($sql) as $r) {
        $tgtRows[$r['BldgNumber'] . '|' . $r['AliasNorm']] = ['AliasText' => $r['AliasText'], 'Source' => $r['Source']];
    }
    $bMap = refsync_building_map($tgt);

    $ins = $tgt->prepare('INSERT INTO ref.BuildingAliases (BuildingID, AliasText, AliasNorm, Source) VALUES (?, ?, ?, ?)');
    $upd = $tgt->prepare('UPDATE ref.BuildingAliases SET AliasText = ?, Source = ?
                          WHERE BuildingID = ? AND AliasNorm = ?');
    $del = $tgt->prepare('DELETE FROM ref.BuildingAliases WHERE BuildingID = ? AND AliasNorm = ?');

    $inserted = $updated = $deleted = 0;
    $skipped = [];
    foreach ($srcRows as $k => $row) {
        [$bldg, $normKey] = explode('|', $k, 2);
        if (!isset($bMap[$bldg])) {
            $skipped[] = $k;
            continue;
        }
        if (!isset($tgtRows[$k])) {
            $ins->execute([$bMap[$bldg], $row['AliasText'], $normKey, $row['Source']]);
            $inserted++;
        } elseif ($tgtRows[$k] != $row) {
            $upd->execute([$row['AliasText'], $row['Source'], $bMap[$bldg], $normKey]);
            $updated++;
        }
    }
    foreach (array_diff_key($tgtRows, $srcRows) as $k => $_) {
        [$bldg, $normKey] = explode('|', $k, 2);
        if (isset($bMap[$bldg])) {
            $del->execute([$bMap[$bldg], $normKey]);
            $deleted++;
        }
    }
    return ['inserted' => $inserted, 'updated' => $updated, 'deleted' => $deleted, 'skipped' => $skipped];
}

/**
 * Publish the Work database's reference copy to Move and Shop.
 * Returns a report; also writes one AuditLog row per target.
 * $userId is the acting user in the WORK database (null pre-Phase-2).
 */
function refsync_publish(?int $userId = null): array
{
    $src = db('work');
    $report = ['warnings' => [], 'targets' => []];

    // Section 3: a building without a real number must not publish silently.
    $tbd = $src->query("SELECT BldgNumber, Name FROM ref.Buildings WHERE BldgNumber = N'TBD'")->fetchAll();
    foreach ($tbd as $row) {
        $report['warnings'][] = 'Building "' . $row['Name'] . '" still carries the placeholder number TBD. '
            . 'Assign a local number before this reference data is treated as final.';
    }

    // Every database except the canonical one. Purchasing, keys and
    // the fleet need the same buildings, rooms, people and settings
    // as the rest, or each would have to reach into Work to draw a
    // dropdown.
    foreach (array_values(array_diff(APP_DB_NAMES, ['work'])) as $name) {
        $tgt = db($name);
        $result = [];
        try {
            foreach (refsync_tables() as $table => $def) {
                // Not every database carries every reference table, and a
                // table one target lacks must not stop the publish to the
                // others. It is reported as skipped rather than swallowed.
                if (!refsync_target_has($tgt, $table)) {
                    $result[$table] = ['inserted' => 0, 'updated' => 0, 'deleted' => 0,
                                       'skipped' => 'not present here'];
                    continue;
                }
                $result[$table] = refsync_sync_keyed($src, $tgt, $table, $def['key'], $def['cols'], $def['delete']);
            }
            $result['ref.BuildingOccupants'] = refsync_target_has($tgt, 'ref.BuildingOccupants')
                ? refsync_sync_occupants($src, $tgt)
                : ['inserted' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 'not present here'];
            $result['ref.BuildingAliases'] = refsync_target_has($tgt, 'ref.BuildingAliases')
                ? refsync_sync_aliases($src, $tgt)
                : ['inserted' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 'not present here'];
            $result['_status'] = 'ok';
        } catch (Throwable $e) {
            $result['_status'] = 'failed';
            $result['_error']  = $e->getMessage();
        }
        // Log the write into the target so each database records its own history.
        try {
            $tgt->prepare('INSERT INTO app.AuditLog (UserID, Action, TargetType, Detail)
                           VALUES (NULL, ?, ?, ?)')
                ->execute(['reference_publish', 'refdata', json_encode($result)]);
        } catch (Throwable $e) {
            $result['_audit_error'] = $e->getMessage();
        }
        $report['targets'][$name] = $result;
    }

    // And one row in the source naming the action as a whole.
    try {
        $src->prepare('INSERT INTO app.AuditLog (UserID, Action, TargetType, Detail)
                       VALUES (?, ?, ?, ?)')
            ->execute([$userId, 'reference_publish', 'refdata', json_encode($report['targets'])]);
    } catch (Throwable $e) {
        $report['warnings'][] = 'Source audit write failed: ' . $e->getMessage();
    }
    return $report;
}

/**
 * Cross-database consistency report (spec Section 4.3): row counts and
 * drift per replicated table, matched on natural keys. Read only.
 */
function refsync_consistency(): array
{
    $report = [];
    $tables = refsync_tables();
    $src = db('work');

    foreach ($tables as $table => $def) {
        $sRows = refsync_fetch_keyed($src, $table, $def['key'], $def['cols']);
        $entry = ['work_rows' => count($sRows)];
        foreach (array_values(array_diff(APP_DB_NAMES, ['work'])) as $name) {
            // A database that does not carry this table is not drifting
            // from it; it simply has no opinion about it.
            if (!refsync_target_has(db($name), $table)) {
                $entry[$name] = ['rows' => null, 'missing' => [], 'extra' => [],
                                 'differs' => [], 'clean' => true, 'absent' => true];
                continue;
            }
            $tRows = refsync_fetch_keyed(db($name), $table, $def['key'], $def['cols']);
            $missing = array_keys(array_diff_key($sRows, $tRows));
            $extra   = array_keys(array_diff_key($tRows, $sRows));
            $differs = [];
            foreach ($sRows as $k => $row) {
                if (isset($tRows[$k]) && $tRows[$k] != $row) {
                    $differs[] = $k;
                }
            }
            $entry[$name] = [
                'rows'    => count($tRows),
                'missing' => $missing,
                'extra'   => $extra,
                'differs' => $differs,
                'clean'   => !$missing && !$extra && !$differs,
            ];
        }
        $report[$table] = $entry;
    }
    return $report;
}
