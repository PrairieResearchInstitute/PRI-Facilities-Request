<?php
declare(strict_types=1);

/**
 * Which copy of the application is answering on this port.
 *
 * Deliberately touches nothing - no database, no session, no config. It
 * has to answer while SQL Server is down, because the launcher uses it
 * to tell "PRIOps is already running here" from "something else has the
 * port" and from "the OLD copy is running". A server left over from
 * another folder used to answer silently and serve stale code.
 *
 * health.php?db=1 is the one exception, asked by the container health
 * check (Dockerfile) and by nothing in the launchers: it also opens every
 * register on the engine dataMode names and answers 503 when any of them
 * does not answer SELECT 1. The answer names the engine and the registers
 * that failed, never a server, an account or an error text.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$out = [
    'app'  => 'PRIOps',
    'root' => str_replace(chr(92), '/', (string)realpath(__DIR__)),
    'php'  => PHP_VERSION,
];
if (($_GET['db'] ?? '') === '1') {
    $down = [];
    try {
        require_once __DIR__ . '/lib/db.php';
        $out['engine'] = db_engine();
        foreach (db_probe() as $name => $r) {
            if (!$r['ok']) {
                $down[] = $name;
            }
        }
    } catch (Throwable $e) {
        $down[] = 'configuration';
    }
    $out['db'] = $down ? 'down' : 'ok';
    if ($down) {
        $out['failed'] = $down;
        http_response_code(503);
    }
}
echo json_encode($out, JSON_UNESCAPED_SLASHES);
