<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/xlsxread.php';

/**
 * The Banner account code directory.
 *
 * It arrives as a spreadsheet, changes, and is nearly three thousand
 * rows long, so it is loaded the way every other campus export is: drop
 * the file in, the app recognises it by its headings, and what was there
 * before is replaced.
 *
 * The column that decides everything is "Data Enterable?". Most codes are
 * budget rollups that no one may put on a purchase line; offering them
 * would be offering a mistake, so they are stored but never suggested.
 */

/** The headings, however they are cased or spaced. */
function banner_field_map(): array
{
    return [
        'account code'  => 'AccountCode',
        'full title'    => 'FullTitle',
        'banner title'  => 'BannerTitle',
        'description'   => 'Description',
        'summary code'  => 'SummaryCode',
        'examples'      => 'Examples',
        'do not use for' => 'DoNotUseFor',
        'predecessor'   => 'Predecessor',
        'account type'  => 'AccountType',
        'sams code'     => 'SamsCode',
        'budget code'   => 'IsBudgetCode',
        'data enterable?' => 'IsEnterable',
        'data enterable' => 'IsEnterable',
        'beginning and ending service dates' => 'ServiceDates',
    ];
}

/** Does this grid look like the account code list? */
function banner_looks_right(array $grid): bool
{
    if (!$grid) {
        return false;
    }
    $map = banner_field_map();
    $hit = 0;
    foreach ($grid[0] as $h) {
        $k = strtolower(trim(preg_replace('/\s+/', ' ', (string)$h) ?? ''));
        if (isset($map[$k])) {
            $hit++;
        }
    }
    // "Account Code" plus "Data Enterable?" is the pair nothing else has.
    return $hit >= 4;
}

/**
 * Load the directory. Replaces what was there, because the spreadsheet is
 * the whole active list and a code dropped from it is a code that should
 * stop being offered.
 */
function banner_import(string $path, string $byNetID): array
{
    $grid = xlsx_is_xlsx($path) ? xlsx_read($path) : banner_read_csv($path);
    if (!$grid) {
        return ['error' => 'That file could not be read as a spreadsheet or a CSV.'];
    }
    if (!banner_looks_right($grid)) {
        return ['error' => 'That does not look like the Banner account code list. It should have '
                         . 'an Account Code column and a Data Enterable? column.'];
    }

    $map = banner_field_map();
    $cols = [];
    foreach ($grid[0] as $i => $h) {
        $k = strtolower(trim(preg_replace('/\s+/', ' ', (string)$h) ?? ''));
        if (isset($map[$k])) {
            $cols[$i] = $map[$k];
        }
    }

    $pdo = db('purchasing');
    $pdo->exec('DELETE FROM ref.BannerAccountCodes');
    $ins = $pdo->prepare(
        'INSERT INTO ref.BannerAccountCodes (AccountCode, FullTitle, BannerTitle, Description,
            Examples, DoNotUseFor, SummaryCode, Predecessor, AccountType, SamsCode,
            IsBudgetCode, IsEnterable, ServiceDates, SourceLabel, UpdatedAt)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, SYSDATETIME())');
    $label = 'spreadsheet ' . date('Y-m-d');

    $loaded = 0;
    $enterable = 0;
    $yes = fn($v) => strtoupper(trim((string)$v)) === 'Y' ? 1 : 0;

    foreach (array_slice($grid, 1) as $row) {
        $rec = array_fill_keys(array_values($map), null);
        foreach ($cols as $i => $field) {
            $rec[$field] = trim((string)($row[$i] ?? ''));
        }
        $code = trim((string)$rec['AccountCode']);
        if ($code === '' || !preg_match('/^[0-9A-Z]{3,20}$/i', $code)) {
            continue;
        }
        $isEnterable = $yes($rec['IsEnterable']);
        $ins->execute([
            $code, $rec['FullTitle'] ?: null, $rec['BannerTitle'] ?: null,
            $rec['Description'] ?: null, $rec['Examples'] ?: null, $rec['DoNotUseFor'] ?: null,
            $rec['SummaryCode'] ?: null, $rec['Predecessor'] ?: null,
            $rec['AccountType'] ?: null, $rec['SamsCode'] ?: null,
            $yes($rec['IsBudgetCode']), $isEnterable, $rec['ServiceDates'] ?: null, $label,
        ]);
        $loaded++;
        $enterable += $isEnterable;
    }
    return ['loaded' => $loaded, 'enterable' => $enterable];
}

/** A CSV of the same thing, for when somebody saves it out. */
function banner_read_csv(string $path): array
{
    $fh = @fopen($path, 'r');
    if (!$fh) {
        return [];
    }
    $grid = [];
    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) === 1 && trim((string)$row[0]) === '') {
            continue;
        }
        $grid[] = $row;
    }
    fclose($fh);
    return $grid;
}

/**
 * Search the codes a person may actually use.
 *
 * REPLACEMENT POINT, like the other two lookups: swap the body if this
 * ever comes from the university directly. Keep the keys.
 */
function lookup_banner(string $q, int $limit = 15): array
{
    $like = '%' . $q . '%';
    $out = [];
    try {
        $stmt = db('purchasing')->prepare(
            'SELECT TOP ' . (int)$limit . ' AccountCode, FullTitle, BannerTitle, AccountType,
                    Description
             FROM ref.BannerAccountCodes
             WHERE IsEnterable = 1
               AND (AccountCode LIKE ? OR FullTitle LIKE ? OR BannerTitle LIKE ?)
             ORDER BY CASE WHEN AccountCode LIKE ? THEN 0 ELSE 1 END, AccountCode');
        $stmt->execute([$like, $like, $like, $q . '%']);
        foreach ($stmt as $r) {
            $title = (string)($r['FullTitle'] ?: $r['BannerTitle']);
            $out[] = [
                'value' => (string)$r['AccountCode'],
                'label' => $r['AccountCode'] . ' — ' . $title,
                'title' => $title,
                'hint'  => mb_substr((string)$r['Description'], 0, 90),
            ];
        }
    } catch (Throwable $e) {
        return [];
    }
    return $out;
}

/** One code, so a typed value can be checked rather than trusted. */
function banner_code_valid(string $code): bool
{
    $code = strtoupper(trim($code));
    if ($code === '') {
        return true;   // the column is optional
    }
    try {
        $st = db('purchasing')->prepare(
            'SELECT COUNT(*) FROM ref.BannerAccountCodes WHERE AccountCode = ? AND IsEnterable = 1');
        $st->execute([$code]);
        return (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) {
        return true;   // no directory loaded yet; do not block a request on it
    }
}
