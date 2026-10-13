<?php
declare(strict_types=1);

/**
 * Drive-invariance checks (owner order of 2026-10-09, rules R1, R2 and R4).
 *
 *     tools\php\php.exe tests\drive-invariance.php
 *
 * Everything happens in a temporary folder of its own, removed at the end:
 * no database, no config.local.json, nothing in the drive data folder. The
 * "other letter" is the first one with no drive behind it, computed here so
 * no drive letter is written in the tests. tools\check.js runs this file.
 * Healthy output ends with "All N drive-invariance checks passed."
 */

require_once dirname(__DIR__) . '/facilities/lib/paths.php';

$passed = 0;
$failed = [];
function ok(string $what, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "    pass  $what\n";
        return;
    }
    $failed[] = $what;
    echo "    FAIL  $what" . ($detail !== '' ? "   [$detail]" : '') . "\n";
}

$ds = DIRECTORY_SEPARATOR;
$tmp = sys_get_temp_dir() . $ds . 'pri-fac-drive-inv-' . getmypid();
$unmounted = '';
for ($c = ord('Z'); $c >= ord('D'); $c--) {
    if (!is_dir(chr($c) . ':' . $ds)) {
        $unmounted = chr($c);
        break;
    }
}
$same = static fn (string $a, string $b): bool =>
    strtolower(str_replace('\\', '/', $a)) === strtolower(str_replace('\\', '/', $b));

echo "\n  Stored paths follow the drive (drive-invariance)\n";
try {
    // The drive as it was: <tmp>\old\App_to_Migrate\data\pri-facilities-request\storage.
    $oldStorage = $tmp . $ds . 'old' . $ds . 'App_to_Migrate' . $ds . 'data' . $ds . 'pri-facilities-request' . $ds . 'storage';
    @mkdir($oldStorage . $ds . 'imports', 0777, true);
    $file = $oldStorage . $ds . 'imports' . $ds . '20261009_120000_rooms.csv';
    file_put_contents($file, "Room,Building\n101,Natural Resources\n");

    // R1: the writer stores the path relative to the storage folder.
    $stored = storage_to_stored($file, $oldStorage);
    ok('the import path is stored relative to the storage folder, with forward slashes',
        $stored === 'imports/20261009_120000_rooms.csv', $stored);
    ok('a path outside the storage folder is kept as it is',
        storage_to_stored($tmp . $ds . 'elsewhere.csv', $oldStorage) === $tmp . $ds . 'elsewhere.csv');

    // The drive comes back under another letter: the same tree, another root.
    $newStorage = $tmp . $ds . 'new' . $ds . 'App_to_Migrate' . $ds . 'data' . $ds . 'pri-facilities-request' . $ds . 'storage';
    @mkdir($newStorage . $ds . 'imports', 0777, true);
    copy($file, $newStorage . $ds . 'imports' . $ds . basename($file));
    $found = storage_from_stored($stored, 'imports', $newStorage);
    ok('the stored relative path finds the file under the new root',
        is_file($found) && $same($found, $newStorage . $ds . 'imports' . $ds . basename($file)), $found);

    // R2: a row written before this change holds the absolute path under the
    // old letter. The reader re-roots it on the "storage" segment.
    if ($unmounted !== '') {
        // In a subfolder, so only the storage-segment rule can find it.
        @mkdir($newStorage . $ds . 'imports' . $ds . 'batch', 0777, true);
        $deep = $newStorage . $ds . 'imports' . $ds . 'batch' . $ds . 'deep.csv';
        file_put_contents($deep, "x\n");
        $legacy = $unmounted . ':\\App_to_Migrate\\data\\pri-facilities-request\\storage\\imports\\batch\\deep.csv';
        $found = storage_from_stored($legacy, 'imports', $newStorage);
        ok('a legacy absolute path under another letter is re-rooted on the storage segment',
            is_file($found) && $same($found, $deep), $found);
        $legacyOdd = $unmounted . ':\\Somewhere\\Else\\' . basename($file);
        $found = storage_from_stored($legacyOdd, 'imports', $newStorage);
        ok('failing that, the file name is found in the folder the writer uses',
            is_file($found) && $same(basename($found), basename($file)), $found);
        ok('a legacy path that matches nothing comes back as not found, never as another file',
            !is_file(storage_from_stored($unmounted . ':\\x\\storage\\imports\\missing.csv', 'imports', $newStorage)));
    } else {
        ok('every drive letter is mounted, so the legacy re-root was not exercised', true);
    }

    // R4: a dataFolder that does not exist is refused, never created.
    $refused = false;
    try {
        data_root_for($tmp . $ds . 'new' . $ds . 'App_to_Migrate' . $ds . 'apps' . $ds . 'pri-facilities-request',
            '..\\..\\data\\no-such-app');
    } catch (RuntimeException $e) {
        $refused = str_contains($e->getMessage(), 'does not exist');
    }
    ok('a dataFolder that does not exist is refused with a message', $refused);
    ok('and nothing was created for it', !is_dir($tmp . $ds . 'new' . $ds . 'App_to_Migrate' . $ds . 'data' . $ds . 'no-such-app'));
    @mkdir($tmp . $ds . 'new' . $ds . 'App_to_Migrate' . $ds . 'apps' . $ds . 'pri-facilities-request', 0777, true);
    ok('a relative dataFolder that exists resolves under the new root',
        $same(data_root_for($tmp . $ds . 'new' . $ds . 'App_to_Migrate' . $ds . 'apps' . $ds . 'pri-facilities-request',
            '..\\..\\data\\pri-facilities-request'),
            (string)realpath($tmp . $ds . 'new' . $ds . 'App_to_Migrate' . $ds . 'data' . $ds . 'pri-facilities-request')));
} finally {
    $rm = static function (string $d) use (&$rm): void {
        foreach (scandir($d) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $d . DIRECTORY_SEPARATOR . $f;
            is_dir($p) ? $rm($p) : @unlink($p);
        }
        @rmdir($d);
    };
    if (is_dir($tmp)) {
        $rm($tmp);
    }
}

echo "\n";
if ($failed) {
    echo count($failed) . " FAILED, $passed passed\n";
    exit(1);
}
echo "All $passed drive-invariance checks passed.\n";
exit(0);
