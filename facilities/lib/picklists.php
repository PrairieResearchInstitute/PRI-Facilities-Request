<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * The short lists the forms offer, read from one editable table.
 *
 * These used to be PHP constants scattered across half a dozen files,
 * which meant that adding a kind of key, or a way property leaves, was a
 * code change. They are lists of PRI's own practice and they move; the
 * people who know when they move are not the people with an editor open.
 *
 * A code marked protected is one that logic keys on - 'Terminated' is how
 * the audit finds keys held by people who have left, 'vehicle' is how the
 * shop form knows to change shape. Those can be relabelled but not
 * renamed, and the editor enforces it.
 */

/** One list as code => label, active entries only, in order. */
function picklist(string $key): array
{
    static $cache = [];
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $out = [];
    try {
        $stmt = db('work')->prepare(
            'SELECT Code, Label FROM ref.PickLists
             WHERE ListKey = ? AND IsActive = 1 ORDER BY SortOrder, Label');
        $stmt->execute([$key]);
        foreach ($stmt as $r) {
            $out[(string)$r['Code']] = (string)$r['Label'];
        }
    } catch (Throwable $e) {
        // The table is not there yet; a caller with a fallback still works.
    }
    return $cache[$key] = $out;
}

/**
 * A list, or the given fallback if the list is empty. Every caller passes
 * what it used to hold as a constant, so a database that has not been
 * upgraded yet still shows a working form rather than an empty select.
 */
function picklist_or(string $key, array $fallback): array
{
    $got = picklist($key);
    return $got ?: $fallback;
}

/** The label for one code, or the code itself if it is not on the list. */
function picklist_label(string $key, ?string $code): string
{
    $code = (string)$code;
    return picklist($key)[$code] ?? $code;
}

/** Render a select, since almost every use is the same three lines. */
function picklist_select(string $key, string $name, ?string $current = null,
                         array $opts = []): string
{
    $items = picklist($key);
    if (isset($opts['fallback']) && !$items) {
        $items = $opts['fallback'];
    }
    $out = '<select name="' . h($name) . '"'
         . (!empty($opts['id']) ? ' id="' . h((string)$opts['id']) . '"' : '')
         . (!empty($opts['required']) ? ' required' : '')
         . (!empty($opts['onchange']) ? ' onchange="' . h((string)$opts['onchange']) . '"' : '')
         . '>';
    if (array_key_exists('blank', $opts)) {
        $out .= '<option value="">' . h((string)$opts['blank']) . '</option>';
    }
    foreach ($items as $code => $label) {
        $sel = ((string)$current === (string)$code) ? ' selected' : '';
        $out .= '<option value="' . h((string)$code) . '"' . $sel . '>' . h($label) . '</option>';
    }
    return $out . '</select>';
}

/** Every list, grouped the way the editing screen shows them. */
function picklist_types(): array
{
    try {
        return db('work')->query(
            'SELECT * FROM ref.PickListTypes ORDER BY SortOrder, Title')->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Every entry of one list, including the inactive ones, for editing. */
function picklist_all(string $key): array
{
    $stmt = db('work')->prepare(
        'SELECT * FROM ref.PickLists WHERE ListKey = ? ORDER BY SortOrder, Label');
    $stmt->execute([$key]);
    return $stmt->fetchAll();
}

function picklist_add(string $key, string $code, string $label, int $sort, string $by): string
{
    $code = trim($code);
    $label = trim($label);
    if ($code === '' || $label === '') {
        return 'A code and a label are both needed.';
    }
    $chk = db('work')->prepare('SELECT COUNT(*) FROM ref.PickLists WHERE ListKey = ? AND Code = ?');
    $chk->execute([$key, $code]);
    if ((int)$chk->fetchColumn() > 0) {
        return 'That code is already on this list.';
    }
    db('work')->prepare(
        'INSERT INTO ref.PickLists (ListKey, Code, Label, SortOrder, IsActive, IsProtected,
            UpdatedAt, UpdatedByNetID)
         VALUES (?, ?, ?, ?, 1, 0, SYSDATETIME(), ?)'
    )->execute([$key, $code, $label, $sort, $by]);
    return '';
}

/**
 * Save the edits. A protected code keeps its code whatever is posted,
 * because something in the application is matching on it.
 */
function picklist_save(string $key, array $labels, array $sorts, array $active, string $by): int
{
    $n = 0;
    $upd = db('work')->prepare(
        'UPDATE ref.PickLists SET Label = ?, SortOrder = ?, IsActive = ?,
            UpdatedAt = SYSDATETIME(), UpdatedByNetID = ?
         WHERE PickListID = ?');
    foreach ($labels as $id => $label) {
        $id = (int)$id;
        $label = trim((string)$label);
        if ($label === '') {
            continue;
        }
        $upd->execute([$label, (int)($sorts[$id] ?? 100),
                       isset($active[$id]) ? 1 : 0, $by, $id]);
        $n++;
    }
    return $n;
}

/** Remove an entry, unless the application depends on the code. */
function picklist_remove(int $id, string $by): string
{
    $st = db('work')->prepare('SELECT ListKey, Code, IsProtected FROM ref.PickLists WHERE PickListID = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) {
        return 'That entry is already gone.';
    }
    if ((int)$row['IsProtected'] === 1) {
        return 'The code "' . $row['Code'] . '" is used in the application itself. Its wording '
             . 'can be changed, but it cannot be removed. Switch it off instead if it should '
             . 'stop being offered.';
    }
    db('work')->prepare('DELETE FROM ref.PickLists WHERE PickListID = ?')->execute([$id]);
    return '';
}
