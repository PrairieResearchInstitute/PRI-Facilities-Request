<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/xlsxread.php';

/**
 * Keeping the three campus exports current.
 *
 * None of them can be fetched by the server: they live behind Shibboleth
 * and asking for a service account is exactly the conversation this
 * project is avoiding. What can be automated is everything either side of
 * the download — recognising a file without being told what it is,
 * refusing one already loaded, recording the result, and saying out loud
 * when a source has gone stale.
 *
 * So "auto update" here means: a person's browser, already authenticated,
 * pulls the file; the app does the rest, whether the file arrives through
 * a drop zone or is posted by a scheduled task on their own machine.
 */

/** Read a CSV into a grid, cheaply and only as far as needed. */
function ds_grid(string $path, int $limit = 0): array
{
    // Spreadsheets arrive as often as CSVs now, and "save as CSV first"
    // is a step somebody will one day forget.
    if (xlsx_is_xlsx($path)) {
        return xlsx_read($path, $limit);
    }
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
        if ($limit > 0 && count($grid) >= $limit) {
            break;
        }
    }
    fclose($fh);
    return $grid;
}

/**
 * Which of the three exports is this? Decided on shape, not file name,
 * because a file renamed or downloaded twice by a browser still has to
 * land in the right table.
 */
function ds_detect(string $path): ?string
{
    $grid = ds_grid($path, 6);
    if (!$grid) {
        return null;
    }
    $first = $grid[0];
    $wide = $grid[count($grid) > 1 ? 1 : 0];

    $heads = array_map(fn($c) => strtolower(trim((string)$c)), $first);

    // The Banner code list: nothing else pairs an account code with a
    // "data enterable" flag.
    if (in_array('account code', $heads, true)
        && (in_array('data enterable?', $heads, true)
            || in_array('data enterable', $heads, true))) {
        return 'banner';
    }

    // The key register, if it was ever saved rather than pasted: the only
    // one naming an assignee and a key number.
    if (in_array('assignee', $heads, true)
        && (in_array('key #', $heads, true) || in_array('key', $heads, true)
            || in_array('key number', $heads, true))) {
        return 'keys';
    }

    // The employee file is the only other one with headings.
    if (in_array('email', $heads, true)
        && (in_array('employee', $heads, true) || in_array('name', $heads, true))) {
        return 'people';
    }

    // The active grants report opens straight on a CFOP code.
    if (count($first) >= 8
        && preg_match('/^[0-9]-[0-9]{6}-[0-9]{6}-[0-9]{6}/', trim((string)$first[0]))) {
        return 'accounts';
    }

    // The org chart: ten columns, an email third, a UIN fourth.
    if (count($wide) >= 9
        && str_contains((string)($wide[2] ?? ''), '@')
        && preg_match('/^[0-9]{6,10}$/', trim((string)($wide[3] ?? '')))) {
        return 'orgchart';
    }

    // A last look for anything with an email column and an org-looking one.
    if (count($wide) >= 3 && str_contains((string)($wide[1] ?? ''), '@')) {
        return 'people';
    }
    return null;
}

function ds_all(): array
{
    return db('work')->query('SELECT * FROM ref.DataSources WHERE IsActive = 1
                              ORDER BY SortOrder, Label')->fetchAll();
}

function ds_get(string $key): ?array
{
    $stmt = db('work')->prepare('SELECT * FROM ref.DataSources WHERE SourceKey = ?');
    $stmt->execute([$key]);
    return $stmt->fetch() ?: null;
}

/** Days since a source was last loaded, or null if it never has been. */
function ds_age_days(?string $lastLoadedAt): ?int
{
    if (!$lastLoadedAt) {
        return null;
    }
    return (int)floor((time() - strtotime($lastLoadedAt)) / 86400);
}

function ds_is_stale(array $src): bool
{
    $age = ds_age_days($src['LastLoadedAt'] ?? null);
    return $age === null || $age >= (int)$src['RefreshDays'];
}

/**
 * Load a file into whichever table it belongs to. The same file loaded
 * twice is not an error and not work: it is recognised by hash and
 * reported as already known, which is what makes an unattended task safe
 * to run every day against a downloads folder.
 */
function ds_load_file(string $path, string $fileName, string $byNetID,
                      string $channel = 'upload', bool $force = false): array
{
    if (!is_readable($path)) {
        return ['error' => 'That file could not be read.'];
    }
    $key = ds_detect($path);
    if ($key === null) {
        return ['error' => 'That file was not recognised. It should be one of the campus '
                         . 'exports: the active grants report, the employee file, the org '
                         . 'chart, the key register, or the Banner account code list.'];
    }
    $src = ds_get($key);
    $hash = (string)hash_file('sha256', $path);
    $bytes = (int)filesize($path);

    if (!$force && $src && (string)$src['LastFileHash'] === $hash) {
        ds_record($key, $byNetID, $channel, $fileName, $hash, $bytes,
                  null, null, null, 'skipped', 'Identical to the file already loaded.');
        return ['source' => $key, 'label' => (string)$src['Label'], 'skipped' => true,
                'message' => $src['Label'] . ': this is the same file already loaded on '
                    . date('M j', strtotime((string)$src['LastLoadedAt'])) . ', so nothing changed.'];
    }

    try {
        if ($key === 'banner') {
            require_once __DIR__ . '/bannercodes.php';
            $res = banner_import($path, $byNetID);
            $seen = (int)($res['loaded'] ?? 0);
            $note = $seen . ' codes, ' . ($res['enterable'] ?? 0)
                  . ' of them usable on a purchase line';
        } elseif ($key === 'accounts') {
            require_once __DIR__ . '/cfoppaste.php';
            require_once __DIR__ . '/accountpeople.php';
            $res = cfop_import_file($path, $byNetID);
            // The report names the principal investigator and the fund
            // manager but carries no NetID for either, and without one
            // neither can be reached - which stops every purchase
            // approval. So the names are matched to the directory on
            // every load, not once by hand.
            $matched = acct_match_people(true);
            $seen = ($res['added'] ?? 0) + ($res['updated'] ?? 0);
            $note = ($res['added'] ?? 0) . ' added, ' . ($res['updated'] ?? 0) . ' updated'
                  . (!empty($res['incomplete']) ? ', ' . $res['incomplete']
                     . ' without a PI or fund manager' : '')
                  . '; matched ' . $matched['pi_filled'] . ' PIs and '
                  . $matched['fm_filled'] . ' fund managers to a NetID';
        } elseif ($key === 'keys') {
            require_once __DIR__ . '/keyspaste.php';
            // A fresh register replaces the last one, except for keys
            // issued through this system, which C3PO has never heard of.
            $res = keys_import(file_get_contents($path), true);
            $seen = (int)($res['loaded'] ?? 0);
            $note = $seen . ' keys, ' . ($res['linked'] ?? 0) . ' linked to a mapped room'
                  . (!empty($res['unresolved_building'])
                     ? ', ' . $res['unresolved_building'] . ' with an unknown building' : '');
        } elseif ($key === 'people') {
            require_once __DIR__ . '/peopleimport.php';
            $res = people_import_file($path, $byNetID);
            $seen = ($res['added'] ?? 0) + ($res['updated'] ?? 0);
            $note = ($res['added'] ?? 0) . ' added, ' . ($res['updated'] ?? 0) . ' updated, '
                  . ($res['total'] ?? 0) . ' in the directory';
        } else {
            require_once __DIR__ . '/orgimport.php';
            $res = org_import($path, true, $byNetID);
            $seen = (int)($res['people'] ?? 0);
            $note = ($res['added'] ?? 0) . ' added, ' . ($res['updated'] ?? 0) . ' updated, '
                  . ($res['units'] ?? 0) . ' unit rows';
        }
    } catch (Throwable $e) {
        ds_record($key, $byNetID, $channel, $fileName, $hash, $bytes,
                  null, null, null, 'error', substr($e->getMessage(), 0, 400));
        return ['error' => $src['Label'] . ' could not be loaded: ' . $e->getMessage()];
    }

    if (isset($res['error'])) {
        ds_record($key, $byNetID, $channel, $fileName, $hash, $bytes,
                  null, null, null, 'error', (string)$res['error']);
        return ['error' => $src['Label'] . ': ' . $res['error']];
    }

    ds_record($key, $byNetID, $channel, $fileName, $hash, $bytes, $seen,
              (int)($res['added'] ?? 0), (int)($res['updated'] ?? 0), 'ok', $note);
    db('work')->prepare(
        'UPDATE ref.DataSources SET LastLoadedAt = SYSDATETIME(), LastLoadedBy = ?,
            LastFileName = ?, LastFileHash = ?, LastRowCount = ?, LastNote = ?
         WHERE SourceKey = ?'
    )->execute([$byNetID, $fileName, $hash, $seen, $note, $key]);

    return ['source' => $key, 'label' => (string)$src['Label'], 'skipped' => false,
            'rows' => $seen, 'result' => $res,
            'message' => $src['Label'] . ' refreshed: ' . $note . '.'];
}

function ds_record(string $key, string $by, string $channel, string $file, string $hash,
                   int $bytes, ?int $seen, ?int $added, ?int $updated,
                   string $outcome, ?string $note): void
{
    db('work')->prepare(
        'INSERT INTO app.DataLoads (SourceKey, LoadedBy, Channel, FileName, FileHash,
            FileBytes, RowsSeen, RowsAdded, RowsUpdated, Outcome, Note)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$key, $by, $channel, substr($file, 0, 260), $hash, $bytes,
                $seen, $added, $updated, $outcome, $note !== null ? substr($note, 0, 600) : null]);
}

function ds_history(int $limit = 25): array
{
    // TOP takes the number inline: a bound parameter is typed as nvarchar
    // by the sqlsrv driver and the statement will not compile. The value
    // is clamped here rather than trusted.
    $limit = max(1, min(500, $limit));
    return db('work')->query(
        "SELECT TOP $limit l.*, s.Label FROM app.DataLoads l
         LEFT JOIN ref.DataSources s ON s.SourceKey = l.SourceKey
         ORDER BY l.LoadedAt DESC")->fetchAll();
}

/**
 * One line for the administration side: which sources have gone past
 * their interval. Silence means everything is current.
 */
function ds_stale_note(): string
{
    try {
        if ((int)setting('ingest_nag', 1) !== 1) {
            return '';
        }
        $stale = [];
        foreach (ds_all() as $s) {
            if (ds_is_stale($s)) {
                $age = ds_age_days($s['LastLoadedAt']);
                $stale[] = $s['Label'] . ' (' . ($age === null ? 'never loaded' : $age . ' days old') . ')';
            }
        }
        if (!$stale) {
            return '';
        }
        return count($stale) === 1
            ? $stale[0] . ' is due for a refresh.'
            : 'Due for a refresh: ' . implode('; ', $stale) . '.';
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * The shared secret a desktop scheduled task posts with. Generated on
 * first request rather than at install, so a site that never automates
 * never has one to leak.
 */
function ds_token(bool $create = false): string
{
    $tok = trim((string)setting('ingest_token', ''));
    if ($tok === '' && $create) {
        ds_setting_put('ingest_token', bin2hex(random_bytes(20)));
        $tok = trim((string)setting('ingest_token', ''));
    }
    return $tok;
}

function ds_token_clear(): void
{
    ds_setting_put('ingest_token', '');
}

/** Settings are replicated, so a write goes to all three databases. */
function ds_setting_put(string $key, string $value): void
{
    foreach (APP_DB_NAMES as $n) {
        try {
            db($n)->prepare('UPDATE app.Settings SET SettingValue = ? WHERE SettingKey = ?')
                  ->execute([$value, $key]);
        } catch (Throwable $e) { /* a database that lacks the row is not a failure */ }
    }
    settings_forget();
}
