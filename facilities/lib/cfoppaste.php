<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * The account directory, loaded from whatever you can already get hold
 * of: a spreadsheet from fiscal services, a copied web table, or the
 * account strings the old form's dropdown shows.
 *
 * Three shapes are understood:
 *   1. A table with headings (CFOP, Survey, PI, Fund Manager, ...)
 *   2. Tab separated text pasted from a spreadsheet
 *   3. Bare account strings in the shape the old form displays:
 *      "1-594662-375002-191100-A01 --A01 - Tollway Main Fund"
 *
 * The third gives us codes and descriptions but no PI or fund manager,
 * which is enough to make the picker work while the rest is filled in.
 */

const CFOP_FIELD_MAP = [
    'cfop'          => 'CfopCode',
    'cfop code'     => 'CfopCode',
    'account'       => 'CfopCode',
    'account code'  => 'CfopCode',
    'account information' => 'CfopCode',
    'c-fop'         => 'CfopCode',
    'survey'        => 'SurveyCode',
    'unit'          => 'SurveyCode',
    'pi'            => 'AccountPI',
    'account pi'    => 'AccountPI',
    'principal investigator' => 'AccountPI',
    'fund manager'  => 'FundManager',
    'fundmanager'   => 'FundManager',
    'business manager' => 'FundManager',
    'activity'      => 'ActivityCode',
    'activity code' => 'ActivityCode',
    'description'   => 'Description',
    'purpose'       => 'Description',
    'account name'  => 'Description',
    'title'         => 'Description',
    'fund type'     => 'FundType',
    'fund'          => 'FundType',
    'pi email'      => 'AccountPIEmail',
    'fund manager email' => 'FundManagerEmail',
];

/** "1-594662-375002-191100-A01 --A01 - Tollway Main Fund" */
function cfop_parse_display(string $line): ?array
{
    $line = trim($line);
    if (!preg_match('/^([0-9]-[0-9]{6}-[0-9]{6}-[0-9]{6}(?:-[0-9A-Z]{3,4})?)\s*(.*)$/i', $line, $m)) {
        return null;
    }
    $rest = trim($m[2]);
    $activity = null;
    if (preg_match('/^--\s*([A-Z0-9]+)\s*-?\s*(.*)$/i', $rest, $r)) {
        $activity = strtoupper($r[1]);
        $rest = trim($r[2]);
    }
    return [
        'CfopCode'     => strtoupper($m[1]),
        'ActivityCode' => $activity,
        'Description'  => $rest !== '' ? $rest : null,
    ];
}

/**
 * The PRIDE active grants report, pulled by anyone with a PRI login from
 * pride.prairie.illinois.edu/pride/accounts/activeGrantsReport.php. It
 * has no heading row and nine columns:
 *
 *   0 account   1 fund       2 title       3 activity   4 ends
 *   5 fund manager           6 secondary   7 PI         8 "INHS (dreslik)"
 *
 * This is the authoritative account list, and it carries the one thing
 * the old form's lookup never returned: who owns the money.
 */
function cfop_parse_pride(array $grid): array
{
    $rows = [];
    foreach ($grid as $row) {
        if (count($row) < 8) {
            continue;
        }
        $code = strtoupper(trim((string)$row[0]));
        if (!preg_match('/^[0-9]-[0-9]{6}-[0-9]{6}-[0-9]{6}(-[0-9A-Z]{3,4})?$/', $code)) {
            continue;
        }
        // "INHS (dreslik)" or "OED/FIN (adudley)": the survey, then the
        // PI's NetID, which is how a name becomes a person here.
        $survey = null;
        $piNetID = null;
        $owner = trim((string)($row[8] ?? ''));
        if ($owner !== '') {
            if (preg_match('/^([A-Z]+)/i', $owner, $s)) {
                $survey = strtoupper($s[1]);
            }
            if (preg_match('/\(([^)]+)\)/', $owner, $n)) {
                $piNetID = strtolower(trim($n[1]));
            }
        }
        $rows[] = [
            'CfopCode'       => $code,
            'FundType'       => trim((string)$row[1]) ?: null,
            'Description'    => trim((string)$row[2]) ?: null,
            'ActivityCode'   => trim((string)$row[3]) ?: null,
            'EndsOn'         => trim((string)$row[4]) ?: null,
            'FundManager'    => trim((string)$row[5]) ?: null,
            'AccountPI'      => trim((string)$row[7]) ?: null,
            'SurveyCode'     => $survey,
            'AccountPINetID' => $piNetID,
        ];
    }
    return ['rows' => $rows, 'mode' => 'PRIDE active grants report'];
}

/** Does this look like the PRIDE report: nine columns, no headings? */
function cfop_looks_like_pride(array $grid): bool
{
    if (!$grid || count($grid[0]) < 8) {
        return false;
    }
    return (bool)preg_match('/^[0-9]-[0-9]{6}-[0-9]{6}-[0-9]{6}/', trim((string)$grid[0][0]));
}

function cfop_parse(string $blob): array
{
    $blob = trim($blob);
    if ($blob === '') {
        return ['rows' => [], 'mode' => 'empty'];
    }

    // An HTML table pasted from a browser.
    if (stripos($blob, '<tr') !== false || stripos($blob, '<table') !== false) {
        $prev = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $blob);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $grid = [];
        foreach ($doc->getElementsByTagName('tr') as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $node) {
                if ($node instanceof DOMElement
                    && in_array(strtolower($node->tagName), ['td', 'th'], true)) {
                    $cells[] = preg_replace('/\s+/', ' ', trim($node->textContent));
                }
            }
            if ($cells) {
                $grid[] = $cells;
            }
        }
        return cfop_from_grid($grid);
    }

    $lines = preg_split('/\r\n|\r|\n/', $blob);
    $grid = [];
    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }
        $grid[] = str_contains($line, "\t")
            ? array_map('trim', explode("\t", $line))
            : [trim($line)];
    }
    if (cfop_looks_like_pride($grid)) {
        return cfop_parse_pride($grid);
    }

    // A single column of account strings is the dropdown, pasted.
    $single = true;
    foreach ($grid as $row) {
        if (count($row) > 1) {
            $single = false;
            break;
        }
    }
    if ($single) {
        $rows = [];
        foreach ($grid as $row) {
            $parsed = cfop_parse_display($row[0]);
            if ($parsed) {
                $rows[] = $parsed;
            }
        }
        return ['rows' => $rows, 'mode' => 'display strings'];
    }
    return cfop_from_grid($grid);
}

function cfop_from_grid(array $grid): array
{
    if (cfop_looks_like_pride($grid)) {
        return cfop_parse_pride($grid);
    }
    if (count($grid) < 2) {
        return ['rows' => [], 'mode' => 'table'];
    }
    // Find a heading row within the first few.
    $headerAt = -1;
    $fields = [];
    foreach (array_slice($grid, 0, 4) as $i => $row) {
        $candidate = [];
        foreach ($row as $c => $label) {
            $key = strtolower(trim(preg_replace('/\s+/', ' ', (string)$label)));
            if (isset(CFOP_FIELD_MAP[$key])) {
                $candidate[$c] = CFOP_FIELD_MAP[$key];
            }
        }
        if (in_array('CfopCode', $candidate, true) && count($candidate) >= 2) {
            $headerAt = $i;
            $fields = $candidate;
            break;
        }
    }
    if ($headerAt < 0) {
        return ['rows' => [], 'mode' => 'table', 'error' =>
            'No heading row was recognised. It needs at least a CFOP column and one other, '
            . 'such as Survey, Account PI, Fund Manager, Activity or Description.'];
    }

    $rows = [];
    foreach (array_slice($grid, $headerAt + 1) as $row) {
        $rec = [];
        foreach ($fields as $c => $field) {
            $v = trim((string)($row[$c] ?? ''));
            if ($v !== '') {
                $rec[$field] = $v;
            }
        }
        if (empty($rec['CfopCode'])) {
            continue;
        }
        // A code cell may still carry the whole display string.
        $parsed = cfop_parse_display((string)$rec['CfopCode']);
        if ($parsed) {
            $rec['CfopCode'] = $parsed['CfopCode'];
            $rec['ActivityCode'] = $rec['ActivityCode'] ?? $parsed['ActivityCode'];
            $rec['Description'] = $rec['Description'] ?? $parsed['Description'];
        }
        $rows[] = $rec;
    }
    return ['rows' => $rows, 'mode' => 'table', 'columns' => array_values(array_unique($fields))];
}

/** An uploaded file, read as if it had been pasted. */
function cfop_import_file(string $tmpPath, string $byNetID): array
{
    $text = @file_get_contents($tmpPath);
    if ($text === false) {
        return ['error' => 'That file could not be read.'];
    }
    // A CSV pastes fine once commas become tabs, but doing that naively
    // breaks quoted fields, so parse it properly and re-join.
    $fh = fopen($tmpPath, 'r');
    $lines = [];
    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) === 1 && trim((string)$row[0]) === '') {
            continue;
        }
        $lines[] = implode("\t", array_map(fn($c) => str_replace("\t", ' ', (string)$c), $row));
    }
    fclose($fh);
    return cfop_import(implode("\n", $lines), $byNetID);
}

/**
 * Load parsed accounts. Existing rows are updated field by field, so a
 * later paste carrying PI and fund manager fills in what an earlier
 * paste of bare codes could not.
 */
function cfop_import(string $blob, string $byNetID): array
{
    $parsed = cfop_parse($blob);
    if (isset($parsed['error'])) {
        return ['error' => $parsed['error']];
    }
    if (!$parsed['rows']) {
        return ['error' => 'Nothing recognisable was pasted. Paste a spreadsheet with headings, '
                         . 'or a list of account strings like '
                         . '1-594662-375002-191100-A01 --A01 - Tollway Main Fund.'];
    }

    $pdo = db('shop');
    $chk = $pdo->prepare('SELECT * FROM ref.CfopAccounts WHERE CfopCode = ?');
    $added = $updated = 0;
    $label = 'pasted ' . date('Y-m-d');

    foreach ($parsed['rows'] as $rec) {
        $code = strtoupper((string)$rec['CfopCode']);
        $chk->execute([$code]);
        $existing = $chk->fetch();

        if (!$existing) {
            $pdo->prepare(
                'INSERT INTO ref.CfopAccounts (CfopCode, SurveyCode, AccountPI, FundManager,
                    ActivityCode, Description, FundType, AccountPIEmail, FundManagerEmail,
                    AccountPINetID, EndsOn, SourceLabel, UpdatedAt)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, SYSDATETIME())'
            )->execute([$code, $rec['SurveyCode'] ?? null, $rec['AccountPI'] ?? '',
                        $rec['FundManager'] ?? '', $rec['ActivityCode'] ?? null,
                        $rec['Description'] ?? null, $rec['FundType'] ?? null,
                        $rec['AccountPIEmail'] ?? null, $rec['FundManagerEmail'] ?? null,
                        $rec['AccountPINetID'] ?? null, $rec['EndsOn'] ?? null, $label]);
            $added++;
            continue;
        }

        // Only overwrite with something; a bare code paste must not wipe
        // a PI somebody already filled in.
        $sets = [];
        $vals = [];
        foreach (['SurveyCode', 'AccountPI', 'FundManager', 'ActivityCode', 'Description',
                  'FundType', 'AccountPIEmail', 'FundManagerEmail', 'AccountPINetID',
                  'EndsOn'] as $f) {
            if (!empty($rec[$f])) {
                $sets[] = "$f = ?";
                $vals[] = $rec[$f];
            }
        }
        if (!$sets) {
            continue;
        }
        $sets[] = 'SourceLabel = ?';
        $vals[] = $label;
        $sets[] = 'UpdatedAt = SYSDATETIME()';
        $vals[] = $code;
        $pdo->prepare('UPDATE ref.CfopAccounts SET ' . implode(', ', $sets) . ' WHERE CfopCode = ?')
            ->execute($vals);
        $updated++;
    }

    $incomplete = (int)$pdo->query(
        "SELECT COUNT(*) FROM ref.CfopAccounts
         WHERE IsActive = 1 AND (AccountPI IS NULL OR AccountPI = ''
                              OR FundManager IS NULL OR FundManager = '')")->fetchColumn();

    return ['added' => $added, 'updated' => $updated, 'mode' => $parsed['mode'],
            'incomplete' => $incomplete];
}
