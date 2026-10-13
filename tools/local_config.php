<?php
declare(strict_types=1);

/**
 * Creates and updates config.local.json through facilities\lib\paths.php,
 * the one writer of that file. SETUP.bat calls it; it never touches a
 * database and never starts a server.
 *
 *   php tools\local_config.php init                 create from config.template.json when missing
 *   php tools\local_config.php set-data-folder REL  save dataFolder (relative to the app folder)
 *   php tools\local_config.php set-data-mode MODE   save dataMode: localdb (the default) or postgres
 *   php tools\local_config.php show                 print the resolved storage folders and the engine
 */

require_once dirname(__DIR__) . '/facilities/lib/paths.php';

$cmd = $argv[1] ?? 'show';
try {
    switch ($cmd) {
        case 'init':
            if (is_file(local_config_path())) {
                echo "config.local.json exists; left as it is.\n";
            } else {
                local_config_save([]);
                echo "config.local.json created from config.template.json.\n";
            }
            break;
        case 'set-data-folder':
            $rel = (string)($argv[2] ?? '');
            if ($rel === '') {
                throw new RuntimeException('Name the folder, relative to the application folder.');
            }
            local_config_save(['dataFolder' => $rel]);
            echo "dataFolder saved: $rel\n";
            break;
        case 'set-data-mode':
            // The engine switch (plan.md PG10). localdb removes nothing: it
            // writes the value an unswitched installation behaves as.
            $mode = strtolower((string)($argv[2] ?? ''));
            if (!in_array($mode, ['localdb', 'postgres'], true)) {
                throw new RuntimeException('Name the engine: localdb or postgres.');
            }
            local_config_save(['dataMode' => $mode]);
            echo "dataMode saved: $mode
";
            break;
        case 'show':
            break;
        default:
            throw new RuntimeException("Unknown command: $cmd");
    }
    echo 'data root: ' . data_root() . "\n";
    echo 'storage:   ' . storage_dir() . "\n";
    $mode = strtolower(trim((string)(local_config()['dataMode'] ?? '')));
    echo 'engine:    ' . ($mode === 'postgres' ? 'postgres' : 'localdb') . "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'local_config: ' . $e->getMessage() . "\n");
    exit(1);
}
