<?php
declare(strict_types=1);

/**
 * Print the scratch folders the server must use, one per line, and create
 * them. The launchers read this and pass the paths to php -S with -d.
 *
 *   php tools/runtime_dirs.php
 *   -> <data folder>\runtime
 *      <data folder>\runtime\upload
 *      <data folder>\runtime\sessions
 *
 * Standard v2 section B: the application reads and writes nothing on the
 * computer. sys_temp_dir and upload_tmp_dir are PHP_INI_SYSTEM, so no page
 * can set them; only the process that starts PHP can. Left empty, as the
 * bundled php.ini leaves them, PHP uses the profile temp folder on C:, and
 * every uploaded photograph lands there before move_uploaded_file() puts it
 * in storage.
 *
 * Exit 1 and print nothing usable when the data folder is unreachable. The
 * launcher then starts the server without the overrides rather than not at
 * all, because a drive problem already has its own message.
 */

require __DIR__ . '/../facilities/lib/paths.php';

try {
    echo runtime_dir(), PHP_EOL;
    echo runtime_dir('upload'), PHP_EOL;
    echo runtime_dir('sessions'), PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
