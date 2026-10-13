<?php
declare(strict_types=1);

/**
 * Unattended loading of a campus export.
 *
 * The server cannot reach PRIDE, but a desktop that has just downloaded a
 * report can reach the server. This endpoint is the far end of that: a
 * scheduled task posts the file with the shared key, and the app decides
 * what it is and whether it has seen it before.
 *
 * It is deliberately narrow. The key is compared in constant time, only
 * the three known exports are accepted, and a file already loaded is
 * answered with "known" rather than reloaded, so a task may run every day
 * without doing any damage.
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/datasources.php';

header('Content-Type: application/json');

function ingest_fail(int $code, string $why): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $why]);
    exit;
}

$method = (string)($_SERVER['REQUEST_METHOD'] ?? '');
if ($method !== 'POST' && $method !== 'GET') {
    ingest_fail(405, 'Post a file to this address.');
}

$expected = ds_token();
if ($expected === '') {
    ingest_fail(403, 'Unattended loading is switched off. Generate a key on the Data refresh screen.');
}
$given = (string)($_POST['token'] ?? $_GET['token'] ?? $_SERVER['HTTP_X_INGEST_TOKEN'] ?? '');
if (!hash_equals($expected, $given)) {
    ingest_fail(403, 'That key is not right.');
}

// A GET asks what to fetch. The desktop script starts here so that a
// report address changed on the Data refresh screen reaches the scheduled
// task without anybody editing a file on a desktop.
if ($method === 'GET') {
    $out = [];
    foreach (ds_all() as $s) {
        $out[] = [
            'key'      => (string)$s['SourceKey'],
            'label'    => (string)$s['Label'],
            'url'      => (string)$s['SourceUrl'],
            'pattern'  => (string)$s['FilePattern'],
            'stale'    => ds_is_stale($s),
            'age_days' => ds_age_days($s['LastLoadedAt']),
        ];
    }
    echo json_encode(['ok' => true, 'sources' => $out]);
    exit;
}

if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
    ingest_fail(400, 'No file arrived.');
}

$name = (string)($_FILES['file']['name'] ?? 'posted.csv');
$by = trim((string)($_POST['as'] ?? '')) ?: 'scheduled task';
$res = ds_load_file($_FILES['file']['tmp_name'], $name, $by, 'ingest');

if (isset($res['error'])) {
    ingest_fail(422, $res['error']);
}
echo json_encode([
    'ok'      => true,
    'source'  => $res['source'],
    'label'   => $res['label'],
    'skipped' => $res['skipped'],
    'rows'    => $res['rows'] ?? null,
    'message' => $res['message'],
]);
