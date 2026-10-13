<?php
declare(strict_types=1);

/**
 * Every cell, accounted for.
 *
 * The hand-written audit in dataaudit.php answers the questions somebody
 * thought to ask. Twice now that has not been enough: a check went blind
 * after the database split and read as good news, and a whole column of
 * source data was dropped on import with nothing to say so. Both failures
 * had the same shape - the audit only saw what it was pointed at.
 *
 * So this file points at everything. It walks every table in every
 * database and reports, for every column, how many rows carry a value.
 * On top of that sweep sit expectations for the registers staff actually
 * work through - the inventory, the key register, the fleet, the rooms,
 * the agreements - grading each column as must-fill or wanted, so a
 * missing VIN is a work item and a blank Notes box is not.
 *
 * Three rules, learned the hard way:
 *   - every table gets classified, so "not examined" cannot hide;
 *   - an expectation naming a column that does not exist is reported as
 *     a fault in the audit itself, loudly, not skipped;
 *   - a query that fails is a finding, never a zero.
 */

require_once __DIR__ . '/db.php';

/**
 * The scaffolding every database carries a copy of. Audited where it is
 * mastered; everywhere else it is a replica and says so.
 */
const COMP_SHARED = [
    'app.Users', 'app.Settings', 'app.NotifyRules', 'app.AdminAccess',
    'app.DigestPreferences', 'app.Notifications', 'app.Attachments', 'app.AuditLog',
    'app.StatusHistory', 'app.Notes', 'ref.Buildings', 'ref.BuildingAliases', 'ref.Rooms',
    'ref.People', 'ref.Surveys', 'ref.Owners', 'ref.StatusFlow', 'ref.PickLists',
    'ref.PickListTypes',
];

/** Histories. They grow with use; empty cells are not gaps. */
const COMP_LOG_NAMES = [
    'AuditLog', 'StatusHistory', 'Notifications', 'Notes', 'Attachments', 'Outbox',
    'ImportBatches', 'ImportRows', 'TicketCounters',
];

/**
 * The registers, graded column by column.
 *
 * 'must' means every row should carry it and a blank is a work item.
 * 'should' means it is wanted and counted, but the register runs without
 * it. Anything not named is swept and reported without judgement.
 */
const COMP_EXPECT = [

    'equipment:gis.Inventory' => [
        'label' => 'Property inventory (active items)',
        'area' => 'Property', 'where' => 'Property → Equipment',
        'filter' => "Status = 'active'",
        'cols' => [
            'PropertyTag' => 'must', 'Description' => 'must', 'Person' => 'must',
            'BldgNumber' => 'must', 'Cost' => 'must', 'AcquiredOn' => 'must',
            'RoomCode' => 'should', 'SurveyCode' => 'should', 'EquipClass' => 'should',
            'Condition' => 'should', 'PoNumber' => 'should', 'FundingSource' => 'should',
        ],
    ],

    'keys:gis.RoomKeys' => [
        'label' => 'Keys currently out',
        'area' => 'Keys and access', 'where' => 'People and Access → Key Assignment',
        'filter' => "KeyState = 'Active'",
        'cols' => [
            'HolderName' => 'must', 'KeyCode' => 'must', 'KeyType' => 'must',
            'HolderNetID' => 'should', 'RoomCode' => 'should', 'SurveyCode' => 'should',
            'IssuedOn' => 'should',
        ],
    ],

    'fleet:ref.FleetVehicles' => [
        'label' => 'The vehicle fleet',
        'area' => 'Vehicles', 'where' => 'Property → Fleet Vehicles',
        'filter' => 'IsActive = 1',
        'cols' => [
            'FleetNumber' => 'must', 'Description' => 'must', 'VehicleYear' => 'must',
            'PlateNumber' => 'must', 'VIN' => 'must',
            'Make' => 'should', 'Model' => 'should', 'HomeBldgNumber' => 'should',
            'SurveyCode' => 'should',
        ],
    ],

    'space:gis.Rooms' => [
        'label' => 'Rooms on the floor plans',
        'area' => 'Space', 'where' => 'Space → Buildings and Rooms',
        'filter' => '',
        'cols' => [
            'RoomCode' => 'must', 'AreaSqFt' => 'must',
            'CategoryCode' => 'should', 'UseCode' => 'should',
        ],
    ],

    'space:ref.Buildings' => [
        'label' => 'The buildings list',
        'area' => 'Estate', 'where' => 'Space → Buildings and Rooms',
        'filter' => 'IsActive = 1',
        'cols' => [
            'Name' => 'must', 'LocationClass' => 'must',
            'AddressLine' => 'should', 'Latitude' => 'should', 'Longitude' => 'should',
            'Ownership' => 'should', 'BuildingType' => 'should',
        ],
    ],

    'space:est.Agreements' => [
        'label' => 'Leases and agreements',
        'area' => 'Estate', 'where' => 'Space → Leases and Agreements',
        'filter' => '',
        'cols' => [
            'AgreementKind' => 'must', 'EndsOn' => 'must',
            'Reference' => 'should', 'Counterparty' => 'should', 'StartsOn' => 'should',
            'OwnerNetID' => 'should', 'AnnualCost' => 'should',
        ],
    ],

    'space:gis.Safety' => [
        'label' => 'Safety equipment',
        'area' => 'Safety', 'where' => 'Space → Safety Manifest',
        'filter' => '',
        'cols' => [
            'RoomCode' => 'must', 'Kind' => 'must', 'LastTested' => 'must',
            'NextDue' => 'should', 'Status' => 'should', 'Tag' => 'should',
        ],
    ],

    'space:gis.Occupants' => [
        'label' => 'Who sits where',
        'area' => 'Space', 'where' => 'Space → Room Assignment',
        'filter' => '',
        'cols' => [
            'PersonName' => 'must', 'NetID' => 'should', 'ConfirmedOn' => 'should',
        ],
    ],

    'work:ref.People' => [
        'label' => 'The staff directory',
        'area' => 'People and routing',
        'where' => 'People and Access → People, Roles and Notifications',
        'filter' => 'IsActive = 1',
        'cols' => [
            'DisplayName' => 'must', 'NetID' => 'must', 'Email' => 'must',
            'SurveyCode' => 'should', 'Position' => 'should', 'PositionType' => 'should',
        ],
    ],

    'shop:ref.CfopAccounts' => [
        'label' => 'The accounts directory',
        'area' => 'Accounts', 'where' => 'Property → Accounts Directory',
        'filter' => 'IsActive = 1',
        'cols' => [
            'AccountPI' => 'must', 'FundManager' => 'must', 'AccountPINetID' => 'must',
            'FundManagerNetID' => 'must',
            'SurveyCode' => 'should', 'EndsOn' => 'should',
        ],
    ],
];

/**
 * Columns the registers still need that no source export carries. Each
 * is checked live against the schema, so the moment one is added it
 * drops off the list by itself.
 */
const COMP_WANTED = [
    'equipment:gis.Inventory' => [
        'Custodian'         => 'The accountable person, held apart from whoever uses it',
        'PurchaseRequestID' => 'Ties a registered item to the purchase that bought it',
        'Manufacturer'      => 'Who made it, for recalls and service',
        'WarrantyUntil'     => 'When cover runs out',
        'ContractNumber'    => 'The service contract, if one exists',
        'ContractExpires'   => 'When that contract runs out',
        'ExpectedLifeYears' => 'How long this kind of thing lasts',
        'ReplacementDue'    => 'When it should be replaced',
        'ReplacementCost'   => 'What replacing it would cost',
        'CalibrationDue'    => 'When it is next due for calibration',
        'LastCertifiedOn'   => 'When custody was last certified',
        'CertifiedByNetID'  => 'Who certified it',
        'FundingCfop'       => 'The account that carries it',
    ],
];

/** Columns held as the wrong type. Checked live, like the wanted list. */
const COMP_TYPE_FIXES = [
    'equipment:gis.Inventory:AcquiredOn' =>
        ['date', 'Held as text, so nothing can sort it, subtract it, or plan replacement from it'],
];

/** Which database a shared table is authored in. Everywhere else holds a copy. */
function comp_master(string $table): string
{
    return ['ref.Buildings' => 'space', 'ref.Rooms' => 'space',
            'ref.BuildingAliases' => 'space'][$table] ?? 'work';
}

function comp_string_type(string $type): bool
{
    return in_array($type, ['nvarchar', 'varchar', 'char', 'nchar', 'ntext', 'text'], true);
}

/** Every table in one database: [table => [column => type]], computed columns left out. */
function comp_tables(string $db): array
{
    // Cached: the work lists walk the same databases the sweep does.
    static $cache = [];
    if (isset($cache[$db])) {
        return $cache[$db];
    }
    $out = [];
    foreach (array_keys(db_tables($db)) as $tbl) {
        foreach (db_columns($db, $tbl) as $c) {
            $out[$tbl][$c['name']] = $c['type'];
        }
    }
    return $cache[$db] = $out;
}

/** One aggregate query: total rows and, per column, how many carry a value. */
function comp_fill(string $db, string $table, array $cols, string $filter = ''): array
{
    $parts = ['COUNT(*) AS _t'];
    $i = 0;
    $names = [];
    foreach ($cols as $col => $type) {
        $alias = '_c' . $i++;
        $names[$alias] = $col;
        $test = comp_string_type($type)
            ? "([$col] IS NOT NULL AND LTRIM(RTRIM(CAST([$col] AS NVARCHAR(MAX)))) <> '')"
            : "[$col] IS NOT NULL";
        $parts[] = "SUM(CASE WHEN $test THEN 1 ELSE 0 END) AS $alias";
    }
    $sql = 'SELECT ' . implode(', ', $parts) . " FROM $table"
         . ($filter !== '' ? " WHERE $filter" : '');
    $row = db($db)->query($sql)->fetch();
    $filled = [];
    foreach ($names as $alias => $col) {
        $filled[$col] = (int)($row[$alias] ?? 0);
    }
    return [(int)($row['_t'] ?? 0), $filled];
}

/** What kind of table this is, so nothing can sit outside the audit unlabelled. */
function comp_class(string $db, string $table, int $rows): string
{
    if (isset(COMP_EXPECT["$db:$table"])) {
        return 'graded';
    }
    if (in_array($table, COMP_SHARED, true) && $db !== comp_master($table)) {
        return 'replica';
    }
    [$schema, $name] = explode('.', $table, 2);
    if (in_array($name, COMP_LOG_NAMES, true)
        || str_ends_with($name, 'Events') || str_ends_with($name, 'History')) {
        return 'log';
    }
    if (in_array($schema, ['wr', 'sh', 'rq'], true)) {
        return 'transactional';
    }
    return $rows === 0 ? 'empty' : 'swept';
}

const COMP_CLASS_NOTE = [
    'graded'        => 'A register staff work through; graded cell by cell against expectations',
    'swept'         => 'Fill rates reported in the matrix; no expectations set',
    'transactional' => 'Written by forms that enforce their own required fields; swept only',
    'log'           => 'A history; it grows with use, so an empty cell is not a gap',
    'replica'       => 'A published copy; audited in the database that masters it',
    'empty'         => 'Empty; fills through use',
];

/**
 * The whole estate of data at once: the matrix, the graded gaps, the
 * accounting, and any faults in the audit itself. One walk, one result,
 * so the four views can never disagree about what they saw.
 */
function completeness_run(): array
{
    $matrix = [];
    $accounting = [];
    $gaps = [];
    $faults = [];

    // Expectations naming a database, table or column that does not
    // exist are the audit auditing itself. They come first because a
    // wrong expectation silently grades nothing.
    foreach (COMP_EXPECT as $key => $cfg) {
        [$db, $table] = explode(':', $key, 2);
        if (!in_array($db, APP_DB_NAMES, true)) {
            $faults[] = "Expectation $key names a database this installation does not have";
        }
    }

    foreach (APP_DB_NAMES as $db) {
        try {
            $tables = comp_tables($db);
        } catch (Throwable $e) {
            $faults[] = APP_DB_LABELS[$db] . ' could not be walked: ' . $e->getMessage();
            continue;
        }

        foreach ($tables as $table => $cols) {
            try {
                [$total, $filled] = comp_fill($db, $table, $cols);
            } catch (Throwable $e) {
                $faults[] = APP_DB_LABELS[$db] . " . $table could not be swept: "
                          . substr($e->getMessage(), 0, 140);
                continue;
            }
            $class = comp_class($db, $table, $total);
            $accounting[] = [
                'db' => $db, 'database' => APP_DB_LABELS[$db], 'table' => $table,
                'class' => $class, 'rows' => $total, 'columns' => count($cols),
                'note' => COMP_CLASS_NOTE[$class],
            ];
            foreach ($cols as $col => $type) {
                $matrix[] = [
                    'database' => APP_DB_LABELS[$db], 'table' => $table, 'column' => $col,
                    'type' => $type, 'rows' => $total, 'filled' => $filled[$col],
                    'pct' => $total > 0 ? (int)round(100 * $filled[$col] / $total) : null,
                    'class' => $class,
                ];
            }
        }

        // The graded pass runs with each register's own filter, because
        // "active items" and "all rows ever" are different denominators.
        foreach (COMP_EXPECT as $key => $cfg) {
            [$edb, $table] = explode(':', $key, 2);
            if ($edb !== $db) {
                continue;
            }
            if (!isset($tables[$table])) {
                $faults[] = "Expectation $key names a table that is not in "
                          . APP_DB_LABELS[$db];
                continue;
            }
            $known = $tables[$table];
            $graded = [];
            foreach ($cfg['cols'] as $col => $need) {
                if (!isset($known[$col])) {
                    $faults[] = "Expectation $key names column $col, which does not exist";
                    continue;
                }
                if ($need === 'must' || $need === 'should') {
                    $graded[$col] = $known[$col];
                }
            }
            if (!$graded) {
                continue;
            }
            try {
                [$total, $filled] = comp_fill($db, $table, $graded, $cfg['filter']);
            } catch (Throwable $e) {
                $faults[] = "Graded sweep of $key failed: " . substr($e->getMessage(), 0, 140);
                continue;
            }
            foreach ($graded as $col => $type) {
                $missing = $total - $filled[$col];
                if ($missing <= 0) {
                    continue;
                }
                $gaps[] = [
                    'area' => $cfg['area'], 'register' => $cfg['label'],
                    'table' => APP_DB_LABELS[$db] . ' . ' . $table, 'column' => $col,
                    'need' => $cfg['cols'][$col] === 'must' ? 'Must be filled' : 'Wanted',
                    'missing' => $missing, 'of' => $total,
                    'pct' => $total > 0 ? (int)round(100 * $filled[$col] / $total) : 0,
                    'where' => $cfg['where'],
                ];
            }
        }
    }

    // Columns that need adding, checked against what is really there.
    $toAdd = [];
    foreach (COMP_WANTED as $key => $wanted) {
        [$db, $table] = explode(':', $key, 2);
        try {
            $have = [];
            foreach (db_columns($db, $table) as $c) {
                $have[strtolower($c['name'])] = true;
            }
        } catch (Throwable $e) {
            $faults[] = "Wanted-column check on $key failed: " . $e->getMessage();
            continue;
        }
        foreach ($wanted as $col => $why) {
            if (!isset($have[strtolower($col)])) {
                $toAdd[] = ['table' => APP_DB_LABELS[$db] . ' . ' . $table,
                            'column' => $col, 'why' => $why];
            }
        }
    }
    foreach (COMP_TYPE_FIXES as $key => [$want, $why]) {
        [$db, $table, $col] = explode(':', $key, 3);
        try {
            $ty = '';
            foreach (db_columns($db, $table) as $c) {
                if (strcasecmp($c['name'], $col) === 0) {
                    $ty = $c['type'];
                }
            }
        } catch (Throwable $e) {
            $faults[] = "Type check on $key failed: " . $e->getMessage();
            continue;
        }
        if ($ty !== '' && strcasecmp($ty, $want) !== 0) {
            $toAdd[] = ['table' => APP_DB_LABELS[$db] . ' . ' . $table, 'column' => $col,
                        'why' => "Is $ty, should be $want. $why"];
        }
    }

    // Worst first: must-fill gaps by how much is missing, then wanted.
    usort($gaps, fn($a, $b) => [$a['need'] !== 'Must be filled', -$a['missing']]
                           <=> [$b['need'] !== 'Must be filled', -$b['missing']]);

    return ['matrix' => $matrix, 'gaps' => $gaps, 'accounting' => $accounting,
            'to_add' => $toAdd, 'faults' => $faults];
}

/**
 * How each register's rows are recognised by the person fixing them.
 * A row in a work list is useless unless it says which vehicle, which
 * key, which account it is.
 */
/**
 * The register's own row number, where the identifying columns are not
 * enough to tell two rows apart. Two keys for the same room with no
 * holder recorded are identical in every column a person would name, so
 * the worklist prints "1109 · 636 · Key" twice and reads like a mistake.
 * The row number is also the thing that finds the row again.
 */
const COMP_ROWID = [
    'keys:gis.RoomKeys' => 'RoomKeyID',
];

const COMP_IDENT = [
    'equipment:gis.Inventory'  => ['PropertyTag', 'Description', 'BldgNumber'],
    'keys:gis.RoomKeys'        => ['HolderName', 'BldgNumber', 'RoomID', 'KeyType'],
    'fleet:ref.FleetVehicles'  => ['FleetNumber', 'Description'],
    'space:gis.Rooms'          => ['RoomCode', 'BldgNumber', 'RoomID', 'RoomName'],
    'space:ref.Buildings'      => ['BldgNumber', 'Name'],
    'space:est.Agreements'     => ['BldgNumber', 'Title', 'Reference'],
    'space:gis.Safety'         => ['BldgNumber', 'RoomID', 'Kind', 'Descriptor'],
    'space:gis.Occupants'      => ['RoomCode', 'PersonName'],
    'work:ref.People'          => ['NetID', 'DisplayName'],
    'shop:ref.CfopAccounts'    => ['CfopCode', 'Description'],
];

/**
 * The graded gaps as rows somebody can go address: every register row
 * with at least one must-fill column blank, named, with exactly which
 * columns it is missing. "PlateNumber is short by 279" is a fact;
 * "F-350, fleet 04-118, needs PlateNumber and VIN" is a job.
 */
function completeness_worklists(): array
{
    $out = [];
    foreach (COMP_EXPECT as $key => $cfg) {
        [$db, $table] = explode(':', $key, 2);
        try {
            $types = comp_tables($db)[$table] ?? [];
        } catch (Throwable $e) {
            continue;   // already reported as a fault by completeness_run()
        }
        $graded = [];
        foreach ($cfg['cols'] as $col => $need) {
            if (isset($types[$col]) && ($need === 'must' || $need === 'should')) {
                $graded[$col] = $need;
            }
        }
        $must = array_keys(array_filter($graded, fn($n) => $n === 'must'));
        if (!$must) {
            continue;
        }

        $blankSql = function (string $col) use ($types): string {
            return comp_string_type($types[$col])
                ? "([$col] IS NULL OR LTRIM(RTRIM(CAST([$col] AS NVARCHAR(MAX)))) = '')"
                : "[$col] IS NULL";
        };
        $ident = array_values(array_filter(COMP_IDENT[$key] ?? [],
                                           fn($c) => isset($types[$c])));
        $rowid = COMP_ROWID[$key] ?? null;
        if ($rowid !== null && isset($types[$rowid])) {
            array_unshift($ident, $rowid);
        } else {
            $rowid = null;
        }
        $select = array_unique(array_merge($ident, array_keys($graded)));
        $where = '(' . implode(' OR ', array_map($blankSql, $must)) . ')'
               . ($cfg['filter'] !== '' ? ' AND ' . $cfg['filter'] : '');

        try {
            $rows = db($db)->query(
                'SELECT [' . implode('], [', $select) . "] FROM $table WHERE $where")
                ->fetchAll();
        } catch (Throwable $e) {
            continue;
        }
        if (!$rows) {
            continue;
        }

        $list = [];
        foreach ($rows as $r) {
            $needs = [];
            $wants = [];
            foreach ($graded as $col => $need) {
                $v = $r[$col] ?? null;
                $blank = $v === null || trim((string)$v) === '';
                if (!$blank) {
                    continue;
                }
                if ($need === 'must') {
                    $needs[] = $col;
                } else {
                    $wants[] = $col;
                }
            }
            if (!$needs) {
                continue;
            }
            $row = [];
            foreach ($ident as $c) {
                $v = $r[$c] === null ? '' : (string)$r[$c];
                // The row number reads as a row number, not as another
                // bare figure beside a building and a room.
                $row[$c] = ($c === $rowid && $v !== '') ? 'Row ' . $v : $v;
            }
            $row['Must fill'] = implode(', ', $needs);
            $row['Also wanted'] = implode(', ', $wants);
            $list[] = $row;
        }
        if ($list) {
            $out[] = ['label' => $cfg['label'], 'area' => $cfg['area'],
                      'where' => $cfg['where'],
                      'columns' => array_keys($list[0]), 'rows' => $list];
        }
    }
    return $out;
}
