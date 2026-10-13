<?php
declare(strict_types=1);

require_once __DIR__ . '/paths.php';

/**
 * CSRF protection (spec rule 5). Session-bound token, verified on every
 * state-changing request. API endpoints send the token in the
 * X-CSRF-Token header; HTML forms post it as a hidden field.
 */

function app_session_start(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        // Standard v2 section B: nothing on the computer. An empty
        // session.save_path, which is what the bundled php.ini leaves, puts
        // every session file in the profile temp folder on C:. The drive
        // folder is created on demand by runtime_dir().
        try {
            session_save_path(runtime_dir('sessions'));
        } catch (Throwable $e) {
            // A disconnected drive must not stop a page rendering its own
            // error; PHP falls back to its default in that case.
        }
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']),
            'path'     => '/',
        ]);
        session_start();
    }
}

function csrf_token(): string
{
    app_session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

/**
 * Dies with 403 when the token is absent or wrong. Call at the top of
 * every POST handler.
 */
function csrf_require(): void
{
    app_session_start();
    $sent = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $held = $_SESSION['csrf_token'] ?? '';
    if ($held === '' || !hash_equals($held, (string)$sent)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'CSRF token missing or invalid.']);
        exit;
    }
}
