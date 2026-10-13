<?php
declare(strict_types=1);

/**
 * Read-only probe for tools\check.js. Loads the application's own path
 * code (paths.php, attachments.php, gisload.php) and prints, as JSON, the
 * folders the running app would read and write, plus how file.php would
 * resolve one real stored upload. Opens no database, starts no session,
 * creates no folder and writes no file.
 */

$lib = dirname(__DIR__) . '/facilities/lib';
require_once $lib . '/attachments.php';
require_once $lib . '/gisload.php';

$out = ['ok' => true];
try {
    $out['localConfig'] = is_file(local_config_path());
    $out['dataFolder'] = (string)(local_config()['dataFolder'] ?? '');
    $out['dataRoot'] = data_root();
    $out['storage'] = storage_dir();
    $out['imports'] = storage_dir('imports');
    $out['gis'] = gis_dir();
    $out['studio'] = storage_dir('studio');
    $uploads = storage_dir('uploads');
    // attach_dir() creates the folder when missing; call it only when it exists.
    $out['uploads'] = is_dir($uploads) ? attach_dir() : null;
    if ($out['uploads'] !== null) {
        $files = array_values(array_filter(scandir($uploads) ?: [],
            fn($f) => is_file($uploads . DIRECTORY_SEPARATOR . $f)));
        $out['uploadCount'] = count($files);
        if ($files) {
            // The exact expression file.php uses for a row whose StoredName is $files[0].
            $path = attach_dir() . DIRECTORY_SEPARATOR . basename((string)$files[0]);
            $out['sample'] = [
                'storedName' => $files[0],
                'resolved'   => $path,
                'isFile'     => is_file($path),
                'bytes'      => is_file($path) ? filesize($path) : null,
                'sha256'     => is_file($path) ? substr(hash_file('sha256', $path), 0, 16) : null,
            ];
        }
    }
    $out['gisFiles'] = is_dir($out['gis']) ? count(glob($out['gis'] . DIRECTORY_SEPARATOR . '*.csv') ?: []) : 0;
} catch (Throwable $e) {
    $out['ok'] = false;
    $out['error'] = $e->getMessage();
}
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
