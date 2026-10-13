<?php
declare(strict_types=1);

/**
 * Serves an attachment from outside the document root, only to a signed
 * in person, and only by its stored identifier (spec 4.2).
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/attachments.php';

if (!app_installed()) {
    header('Location: install.php');
    exit;
}
current_user();

$type = in_array($_GET['type'] ?? '', ['work', 'move', 'shop'], true) ? $_GET['type'] : 'work';
$id = (int)($_GET['id'] ?? 0);

$stmt = db($type)->prepare('SELECT * FROM app.Attachments WHERE AttachmentID = ?');
$stmt->execute([$id]);
$file = $stmt->fetch();
if (!$file) {
    http_response_code(404);
    echo 'Not found.';
    exit;
}

$path = attach_dir() . DIRECTORY_SEPARATOR . basename((string)$file['StoredName']);
if (!is_file($path)) {
    http_response_code(404);
    echo 'The stored file is missing.';
    exit;
}

$inline = in_array($file['MimeType'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp',
                                       'application/pdf'], true);
header('Content-Type: ' . $file['MimeType']);
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
     . '; filename="' . str_replace('"', '', (string)$file['OriginalName']) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
