<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/access.php';

/**
 * The Studio: the application's own code, editable from inside it.
 *
 * This exists for the day something is wrong on PRI shared hosting and
 * nobody who can open a code editor is available. It is deliberately
 * powerful - it writes PHP that the next request will run - so every
 * part of it assumes the person using it is tired, in a hurry, and
 * about to make a mistake:
 *
 *   confined   a path is RESOLVED and then checked to be inside the app
 *              folder. Not string-matched - resolved - so "..", a
 *              symlink, or a Windows short name cannot walk out of it.
 *   checked    PHP is parsed before it is written. A file that would
 *              white-screen the site is refused, not saved.
 *   reversible every save copies the old file aside first, so any change
 *              goes back with one click and no typing.
 *   recorded   who saved what, when, and how much it changed.
 *
 * Access is a grant of its own. An administrator is NOT automatically
 * in: running the institute's back end and rewriting it are different
 * things, and the point of this list is that it is shorter.
 */

const STUDIO_FUNCTION = 'studio';

/** Files the Studio will open. Anything else is data, not code. */
const STUDIO_EXTENSIONS = [
    'php', 'css', 'js', 'html', 'htm', 'json', 'sql', 'md', 'txt',
    'svg', 'bat', 'ps1', 'py', 'vbs', 'ini',
];

/**
 * Never listed: other people's data, the backups this feature writes,
 * and anything big enough to make the tree useless.
 */
const STUDIO_SKIP_DIRS = [
    '.git', 'node_modules', '__pycache__', 'vendor',
    'storage/uploads', 'storage/shots', 'storage/studio',
    'Visual Pack', 'C3PO Data', 'gis',
    // The interpreter's own settings, and the databases.
    'tools/php', 'tools/odbc', 'data',
];

/**
 * Files never opened, whatever folder they are in.
 *
 * The Studio edits the application. It has no business handing anybody
 * the database credentials or a private key, and a skip list that only
 * governed LISTING was no protection at all: a typed ?file=config.php
 * walked straight past it, because studio_resolve() never consulted it.
 */
const STUDIO_DENY_FILES = [
    'config.php', 'config.php.*', '.env', '.env.*', 'php.ini',
    '*.key', '*.pem', '*.pfx', '*.sqlite', '*.sqlite3', '*.db', '*.bak',
];

/** The largest file it will open - bigger than any source file here. */
const STUDIO_MAX_BYTES = 2097152;

/** Slashes one way, whatever the operating system hands back. */
function studio_slash(string $p): string
{
    return str_replace(chr(92), '/', $p);
}

/** The application folder - the parent of facilities/. */
function studio_root(): string
{
    $root = realpath(__DIR__ . '/../..');
    if ($root === false) {
        throw new RuntimeException('Cannot resolve the application folder.');
    }
    return studio_slash($root);
}

/** Where a replaced file is kept, so every save can be undone. */
function studio_backup_dir(): string
{
    $dir = studio_slash(storage_dir('studio/backups'));
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

/**
 * May this person use the Studio?
 *
 * Explicit grant only. has_function() answers true for any administrator,
 * which is right for the back end and wrong here.
 */
function can_studio(?array $user = null): bool
{
    $user = $user ?? current_user();
    foreach (access_rows($user['NetID']) as $row) {
        if (($row['AccessFunction'] ?? '') === STUDIO_FUNCTION) {
            return true;
        }
    }
    return false;
}

/** The same question, asked where the answer must stop the request. */
function require_studio(): void
{
    if (!can_studio()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'The Studio is granted separately from the back end.' . PHP_EOL
           . "Ask an administrator for the 'studio' function on People and Access.";
        exit;
    }
}

/**
 * Turn a path from the browser into a real file inside the app, or
 * nothing at all. The check is on the RESOLVED path, because comparing
 * the string the browser sent would be fooled by "..", by a symlink,
 * and on Windows by an 8.3 short name.
 */
function studio_resolve(string $rel): ?string
{
    $rel = studio_slash(trim($rel));
    if ($rel === '' || str_contains($rel, chr(0))) {
        return null;
    }
    $root = studio_root();
    $full = realpath($root . '/' . ltrim($rel, '/'));
    if ($full === false) {
        return null;
    }
    $full = studio_slash($full);
    if ($full !== $root && !str_starts_with($full, $root . '/')) {
        return null;
    }
    if (!is_file($full)) {
        return null;
    }
    $ext = strtolower((string)pathinfo($full, PATHINFO_EXTENSION));
    if (!in_array($ext, STUDIO_EXTENSIONS, true)) {
        return null;
    }
    // The same rules that hide a file from the tree now also refuse to
    // open or save it. Applying them only while listing meant the tree
    // looked right and a typed path ignored it entirely.
    $rel = ltrim(studio_slash(substr($full, strlen(studio_root()))), '/');
    $dir = dirname($rel);
    if (studio_skipped($dir === '.' ? '' : $dir) || studio_file_denied($rel)) {
        return null;
    }
    return $full;
}

/** The path as the Studio shows it: relative to the app folder. */
function studio_relative(string $full): string
{
    return ltrim(substr(studio_slash($full), strlen(studio_root())), '/');
}

/** Does this name match one of the deny patterns? Case-insensitive. */
function studio_name_matches(string $name, string $pattern): bool
{
    $re = '/^' . str_replace(['__STAR__', '__Q__'], ['.*', '.'],
          preg_quote(str_replace(['*', '?'], ['__STAR__', '__Q__'], $pattern), '/')) . '$/i';
    return preg_match($re, $name) === 1;
}

/** Is this file one the Studio must never open, on any path? */
function studio_file_denied(string $rel): bool
{
    $name = basename($rel);
    foreach (STUDIO_DENY_FILES as $pattern) {
        if (studio_name_matches($name, $pattern) || studio_name_matches($rel, $pattern)) {
            return true;
        }
    }
    return false;
}

function studio_skipped(string $relDir): bool
{
    $relDir = trim(studio_slash($relDir), '/');
    foreach (STUDIO_SKIP_DIRS as $skip) {
        if ($relDir === $skip || str_starts_with($relDir . '/', $skip . '/')) {
            return true;
        }
    }
    return false;
}

/** Every editable file in the app, sorted, as relative paths. */
function studio_tree(): array
{
    $root = studio_root();
    $out = [];
    $walk = function (string $dir) use (&$walk, $root, &$out): void {
        $rel = trim(substr(studio_slash($dir), strlen($root)), '/');
        if ($rel !== '' && studio_skipped($rel)) {
            return;
        }
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $path = $dir . '/' . $e;
            if (is_dir($path)) {
                $walk($path);
                continue;
            }
            $ext = strtolower((string)pathinfo($e, PATHINFO_EXTENSION));
            if (!in_array($ext, STUDIO_EXTENSIONS, true)) {
                continue;
            }
            if ((int)@filesize($path) > STUDIO_MAX_BYTES) {
                continue;
            }
            $out[] = studio_relative($path);
        }
    };
    $walk($root);
    sort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

/**
 * Is this PHP the parser will accept?
 *
 * token_get_all with TOKEN_PARSE throws on a syntax error and does not
 * run a line of it, so the check is safe to make on the very server
 * about to save the file. Shelling out to "php -l" would be the other
 * way, and exec() is commonly closed on shared hosting.
 */
function studio_check(string $rel, string $code): array
{
    $ext = strtolower((string)pathinfo($rel, PATHINFO_EXTENSION));
    if ($ext !== 'php') {
        return ['ok' => true, 'message' => 'Not PHP, so saved as written.'];
    }
    try {
        token_get_all($code, TOKEN_PARSE);
        return ['ok' => true, 'message' => 'PHP parses.'];
    } catch (ParseError $e) {
        return ['ok' => false, 'message' => 'PHP syntax error on line '
              . $e->getLine() . ': ' . $e->getMessage()];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Could not parse: ' . $e->getMessage()];
    }
}

/** A backup name that sorts by time and keeps the original path in it. */
function studio_backup_name(string $rel): string
{
    return date('Ymd-His') . '__' . str_replace('/', '~', $rel);
}

/** Every kept copy of one file, newest first. */
function studio_backups(string $rel): array
{
    $dir = studio_backup_dir();
    $suffix = '__' . str_replace('/', '~', $rel);
    $out = [];
    foreach (@scandir($dir) ?: [] as $e) {
        if ($e === '.' || $e === '..' || !str_ends_with($e, $suffix)) {
            continue;
        }
        $out[] = ['name' => $e, 'when' => substr($e, 0, 15),
                  'size' => (int)@filesize($dir . '/' . $e)];
    }
    usort($out, fn($a, $b) => strcmp($b['name'], $a['name']));
    return $out;
}

/** The audit line. A change to the code is worth a record. */
function studio_log(string $action, string $rel, string $detail = ''): void
{
    $dir = studio_slash(storage_dir('studio'));
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $line = json_encode([
        'at' => date('c'), 'who' => current_user()['NetID'] ?? '?',
        'action' => $action, 'file' => $rel, 'detail' => $detail,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    @file_put_contents($dir . '/history.log', $line, FILE_APPEND | LOCK_EX);
}

/**
 * Save a file: check it, keep the old one, then write.
 * Nothing is written unless the check passes, so a syntax error costs a
 * message rather than the site.
 */
function studio_save(string $rel, string $code): array
{
    $full = studio_resolve($rel);
    if ($full === null) {
        return [false, 'That file is not part of this application.'];
    }
    if (!is_writable($full)) {
        return [false, 'The file is read-only on disk.'];
    }
    $check = studio_check($rel, $code);
    if (!$check['ok']) {
        studio_log('refused', $rel, $check['message']);
        return [false, $check['message'] . ' Nothing was written.'];
    }
    $before = (string)@file_get_contents($full);
    if ($before === $code) {
        return [true, 'No change to save.'];
    }
    if (@copy($full, studio_backup_dir() . '/' . studio_backup_name($rel)) === false) {
        return [false, 'Could not keep a copy of the old file, so nothing was written.'];
    }
    // Written whole and then moved into place, so a half-written file
    // never exists for the next request to load.
    $tmp = $full . '.studio-tmp';
    if (@file_put_contents($tmp, $code, LOCK_EX) === false || !@rename($tmp, $full)) {
        @unlink($tmp);
        return [false, 'The write failed. The file is unchanged.'];
    }
    $delta = strlen($code) - strlen($before);
    studio_log('saved', $rel, ($delta >= 0 ? '+' : '') . $delta . ' bytes');
    return [true, 'Saved. ' . $check['message'] . ' The previous copy is kept.'];
}

/** Put a kept copy back, keeping the current one first. */
function studio_restore(string $rel, string $backupName): array
{
    if (basename($backupName) !== $backupName) {
        return [false, 'Not a backup this Studio wrote.'];
    }
    // The name carries the path it was taken from, so this is exact:
    // without it, one file's backup could be written over another.
    if (!str_ends_with($backupName, '__' . str_replace('/', '~', $rel))) {
        return [false, 'That copy belongs to a different file.'];
    }
    $src = studio_backup_dir() . '/' . $backupName;
    if (!is_file($src)) {
        return [false, 'That copy is no longer there.'];
    }
    $res = studio_save($rel, (string)@file_get_contents($src));
    if ($res[0]) {
        studio_log('restored', $rel, $backupName);
        return [true, 'Restored the copy from ' . substr($backupName, 0, 15) . '.'];
    }
    return $res;
}
