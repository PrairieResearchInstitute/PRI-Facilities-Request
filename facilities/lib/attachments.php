<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

/**
 * Attachments: photographs on a work request, drawings on a shop
 * request. Files are validated by extension and by what the file
 * actually contains, capped, stored outside the document root, and
 * served back through a handler that checks the session (spec 4.2).
 */

const ATTACH_MAX_BYTES = 10485760;   // ten megabytes

const ATTACH_TYPES = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'heic' => 'image/heic',
    'pdf'  => 'application/pdf',
    'dwg'  => 'image/vnd.dwg',
    'dxf'  => 'image/vnd.dxf',
];

function attach_dir(): string
{
    $dir = storage_dir('uploads');
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }
    return $dir;
}

/**
 * Store one uploaded file against a request. Returns a message, or null
 * when nothing was uploaded.
 */
function attach_store(string $dbName, int $requestId, array $file, string $purpose,
                      string $caption, array $user, string $requestType = ''): ?string
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return 'Upload failed (' . (int)$file['error'] . '). The file may exceed the server limit.';
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return 'That upload was rejected.';
    }
    if ((int)$file['size'] > ATTACH_MAX_BYTES) {
        return 'That file is larger than ten megabytes.';
    }

    $original = (string)$file['name'];
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!isset(ATTACH_TYPES[$ext])) {
        return 'That file type is not accepted. Photographs, PDF drawings, DWG and DXF are.';
    }

    // Trust the content, not the name: an image must really be an image.
    $mime = ATTACH_TYPES[$ext];
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        $info = @getimagesize($file['tmp_name']);
        if ($info === false) {
            return 'That file claims to be an image but does not contain one.';
        }
        $mime = $info['mime'] ?? $mime;
    } elseif (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $detected = finfo_file($fi, $file['tmp_name']);
        finfo_close($fi);
        if ($ext === 'pdf' && $detected !== 'application/pdf') {
            return 'That file claims to be a PDF but does not contain one.';
        }
    }

    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], attach_dir() . DIRECTORY_SEPARATOR . $stored)) {
        return 'The file could not be saved.';
    }

    // A photograph taken on a phone often carries where it was taken.
    $lat = $lon = null;
    $captured = null;
    if (in_array($ext, ['jpg', 'jpeg'], true) && function_exists('exif_read_data')) {
        $exif = @exif_read_data(attach_dir() . DIRECTORY_SEPARATOR . $stored);
        if ($exif) {
            $captured = $exif['DateTimeOriginal'] ?? null;
            if (isset($exif['GPSLatitude'], $exif['GPSLongitude'])) {
                $lat = attach_gps($exif['GPSLatitude'], $exif['GPSLatitudeRef'] ?? 'N');
                $lon = attach_gps($exif['GPSLongitude'], $exif['GPSLongitudeRef'] ?? 'E');
            }
        }
    }

    db($dbName)->prepare(
        'INSERT INTO app.Attachments (RequestID, RequestType, UserID, StoredName, OriginalName,
            MimeType, SizeBytes, ExifLatitude, ExifLongitude, CapturedAt, Purpose, Caption)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$requestId, $requestType ?: $dbName,
                ensure_user($dbName, $user), $stored, mb_substr($original, 0, 260),
                $mime, (int)$file['size'], $lat, $lon,
                $captured ? date('Y-m-d H:i:s', strtotime(str_replace(':', '-', substr($captured, 0, 10))
                    . substr($captured, 10))) : null,
                $purpose, $caption !== '' ? $caption : null]);

    // The caller sometimes needs the row it just made - a mileage reading
    // records which photograph backs it up. Returning it would change a
    // signature five pages rely on, so it is left here instead.
    $GLOBALS['attach_last_id'] = (int)db($dbName)->lastInsertId();

    return $lat !== null
        ? 'File attached. It carries coordinates, which are offered as the request location rather '
          . 'than applied automatically.'
        : 'File attached.';
}

/** Several files from one multi-file field. */
function attach_store_many(string $dbName, int $requestId, string $field, string $purpose,
                           array $user, string $requestType = ''): array
{
    $out = [];
    if (empty($_FILES[$field]) || !is_array($_FILES[$field]['name'])) {
        return $out;
    }
    $n = count($_FILES[$field]['name']);
    for ($i = 0; $i < $n; $i++) {
        $one = [
            'name'     => $_FILES[$field]['name'][$i],
            'type'     => $_FILES[$field]['type'][$i],
            'tmp_name' => $_FILES[$field]['tmp_name'][$i],
            'error'    => $_FILES[$field]['error'][$i],
            'size'     => $_FILES[$field]['size'][$i],
        ];
        $msg = attach_store($dbName, $requestId, $one, $purpose, '', $user, $requestType);
        if ($msg !== null) {
            $out[] = $msg;
        }
    }
    return $out;
}

function attach_gps($coordinate, string $ref): ?float
{
    if (!is_array($coordinate) || count($coordinate) < 3) {
        return null;
    }
    $parts = [];
    foreach (array_slice($coordinate, 0, 3) as $p) {
        if (is_string($p) && str_contains($p, '/')) {
            [$a, $b] = explode('/', $p);
            $parts[] = (float)$b == 0.0 ? 0.0 : (float)$a / (float)$b;
        } else {
            $parts[] = (float)$p;
        }
    }
    $deg = $parts[0] + $parts[1] / 60 + $parts[2] / 3600;
    return in_array(strtoupper($ref), ['S', 'W'], true) ? -$deg : $deg;
}

/**
 * The files on one request.
 *
 * $requestType defaults to the database name because that is what the
 * three original request types used, and their rows were backfilled to
 * match. Anything sharing a database with another kind of request - the
 * Work database now holds five - has to name its type or it will be
 * handed somebody else's files.
 */
function attach_list(string $dbName, int $requestId, string $requestType = ''): array
{
    $stmt = db($dbName)->prepare(
        "SELECT a.*, u.DisplayName FROM app.Attachments a
         LEFT JOIN app.Users u ON u.UserID = a.UserID
         WHERE a.RequestID = ? AND ISNULL(a.RequestType, ?) = ?
         ORDER BY a.AttachmentID");
    $type = $requestType ?: $dbName;
    $stmt->execute([$requestId, $type, $type]);
    return $stmt->fetchAll();
}
