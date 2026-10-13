<?php
declare(strict_types=1);

/**
 * Where the application keeps the files it writes: uploads, imports, GIS
 * extracts and the Studio's backups, all under one storage folder.
 *
 * Portable mode (platform decisions D-013 and D-014). config.local.json
 * beside the application folder may name a dataFolder, relative to the
 * application folder, for example "..\\..\\data\\pri-facilities-request".
 * Storage then lives in <dataFolder>\storage, outside the repository, so a
 * copy launched from the drive reads the real files instead of creating an
 * empty storage folder of its own. Without config.local.json, or without a
 * dataFolder in it, storage stays where it always was: <app>\storage.
 *
 * config.local.json is git-ignored and written only by SETUP.bat through
 * tools\local_config.php. A named dataFolder that does not exist is an
 * error, never a reason to create an empty one: a missing drive must not
 * look like a fresh installation.
 */

/** The application folder: the parent of facilities\. */
function paths_app_root(): string
{
    return dirname(__DIR__, 2);
}

function local_config_path(): string
{
    // The PostgreSQL test harness points a process at its own scratch
    // settings (tests/.work); nothing else sets this variable.
    $override = getenv('PRIOPS_LOCAL_CONFIG');
    if (is_string($override) && $override !== '') {
        return $override;
    }
    return paths_app_root() . DIRECTORY_SEPARATOR . 'config.local.json';
}

/** The parsed config.local.json, or an empty array when there is none. */
function local_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = [];
        $file = local_config_path();
        if (is_file($file)) {
            $raw = (string)file_get_contents($file);
            $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                throw new RuntimeException('config.local.json is not valid JSON.');
            }
            $cfg = $data;
        }
    }
    return $cfg;
}

/** True when the path starts with a drive letter, a UNC prefix or a slash. */
function paths_is_absolute(string $p): bool
{
    return (bool)preg_match('#^([A-Za-z]:[\\\\/]|[\\\\/])#', $p);
}

/**
 * The folder that holds storage\: the dataFolder from config.local.json,
 * resolved against the application folder, or the application folder.
 */
function data_root(): string
{
    return data_root_for(paths_app_root(), (string)(local_config()['dataFolder'] ?? ''));
}

/**
 * data_root() for a given application folder and dataFolder value, so the
 * tests can prove the refusal against a temporary folder.
 */
function data_root_for(string $appRoot, string $folder): string
{
    $folder = trim($folder);
    if ($folder === '') {
        return $appRoot;
    }
    // Written on Windows (..\..\data\...); on a Linux server a backslash is an
    // ordinary character, so use this system's separator for both kinds.
    $folder = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $folder);
    $path = paths_is_absolute($folder)
        ? $folder
        : $appRoot . DIRECTORY_SEPARATOR . $folder;
    $real = realpath($path);
    if ($real === false || !is_dir($real)) {
        throw new RuntimeException('The data folder named in config.local.json does not exist. '
            . 'Connect the drive that holds it, or correct dataFolder.');
    }
    return $real;
}

/**
 * The one writer of config.local.json. Merges $changes into the file
 * (created from config.template.json when missing) and refuses a
 * dataFolder with a drive letter: the drive takes a different letter on
 * each computer, so the folder is always relative to the application.
 */
function local_config_save(array $changes): array
{
    if (isset($changes['dataFolder']) && paths_is_absolute((string)$changes['dataFolder'])) {
        throw new RuntimeException('dataFolder must be relative to the application folder.');
    }
    $file = local_config_path();
    if (is_file($file)) {
        $cfg = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', (string)file_get_contents($file)), true);
        if (!is_array($cfg)) {
            throw new RuntimeException('config.local.json is not valid JSON; fix it by hand first.');
        }
    } else {
        $tpl = paths_app_root() . DIRECTORY_SEPARATOR . 'config.template.json';
        $cfg = is_file($tpl) ? json_decode((string)file_get_contents($tpl), true) : [];
        if (!is_array($cfg)) {
            $cfg = [];
        }
    }
    $cfg = array_merge($cfg, $changes);
    $out = $cfg;
    if (isset($out['secrets']) && $out['secrets'] === []) {
        $out['secrets'] = new stdClass();   // keep {} an object, as the template has it
    }
    $json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    if (file_put_contents($file . '.tmp', $json) === false || !rename($file . '.tmp', $file)) {
        throw new RuntimeException('Could not write config.local.json.');
    }
    return $cfg;
}

/** <data root>\storage, or one subfolder of it (uploads, imports, gis, studio). */
function storage_dir(string $sub = ''): string
{
    $dir = data_root() . DIRECTORY_SEPARATOR . 'storage';
    if ($sub !== '') {
        $dir .= DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sub);
    }
    return $dir;
}

/**
 * The form a file path takes in the database (drive-invariance rule R1):
 * relative to storage_dir(), with forward slashes, for example
 * "imports/20261009_120000_rooms.csv". A path outside the storage folder
 * stays as it is. $root defaults to storage_dir(); the tests pass their own.
 */
function storage_to_stored(string $abs, ?string $root = null): string
{
    $root = rtrim(str_replace('\\', '/', $root ?? storage_dir()), '/');
    $norm = str_replace('\\', '/', $abs);
    if ($root !== '' && strncasecmp($norm, $root . '/', strlen($root) + 1) === 0) {
        return substr($norm, strlen($root) + 1);
    }
    return $abs;
}

/**
 * A stored path as a file on this computer (rules R1 and R2). A relative
 * value resolves against the current storage folder. An absolute value that
 * exists stands. An absolute value that does not exist was written while the
 * drive had another letter: its tail after the last "storage" segment is
 * rebuilt under the current storage folder, and failing that its file name
 * is looked for in $sub (the folder the writer uses, such as "imports").
 * When nothing exists the cleanest reading is returned, so a caller that
 * tests existence still gets a sensible "not found". Nothing is rewritten in
 * the database.
 */
function storage_from_stored(string $stored, string $sub = '', ?string $root = null): string
{
    $root = rtrim($root ?? storage_dir(), '\\/');
    $ds = DIRECTORY_SEPARATOR;
    if (!paths_is_absolute($stored)) {
        return $root . $ds . str_replace(['/', '\\'], $ds, ltrim($stored, '\\/'));
    }
    if (file_exists($stored)) {
        return $stored;
    }
    $norm = str_replace('\\', '/', $stored);
    $candidates = [];
    $at = strripos($norm, '/storage/');
    if ($at !== false) {
        $candidates[] = $root . $ds . str_replace('/', $ds, substr($norm, $at + strlen('/storage/')));
    }
    $sub = trim(str_replace(['/', '\\'], $ds, $sub), $ds);
    $candidates[] = $root . ($sub !== '' ? $ds . $sub : '') . $ds . basename($norm);
    foreach ($candidates as $c) {
        if (file_exists($c)) {
            return $c;
        }
    }
    return $candidates[0];
}

/**
 * Scratch folders the running application needs on the drive: PHP's
 * session files, its upload temporaries and anything else that would
 * otherwise land in the computer's temp folder.
 *
 * Standard v2 section B: the application reads and writes nothing on C:
 * or under the user profile. PHP's defaults break that quietly. With
 * session.save_path, sys_temp_dir and upload_tmp_dir all empty, as they
 * are in the bundled php.ini, sys_get_temp_dir() returns
 * <profile>\AppData\Local\Temp, so every session file and every uploaded
 * photograph passes through the computer before attachments.php moves it
 * into storage. The folders are created on demand, because scratch is not
 * data: an empty one is a correct starting state, unlike a missing
 * storage folder.
 *
 * sys_temp_dir and upload_tmp_dir are PHP_INI_SYSTEM, so only the
 * launcher can set them; it passes this folder with -d. session.save_path
 * is settable per request, and app_session_start() does it.
 */
function runtime_dir(string $sub = ''): string
{
    $dir = data_root() . DIRECTORY_SEPARATOR . 'runtime';
    if ($sub !== '') {
        $dir .= DIRECTORY_SEPARATOR . str_replace([chr(47), chr(92)], DIRECTORY_SEPARATOR, $sub);
    }
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the runtime folder ' . $dir . '.');
    }
    return $dir;
}
