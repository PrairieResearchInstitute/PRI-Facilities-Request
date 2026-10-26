<?php
declare(strict_types=1);

/**
 * Ends the application session. Behind the university sign-in
 * (AUTH_MODE=proxy) the proxy holds the real session, so the page then
 * sends the browser to AUTH_LOGOUT_URL, the proxy's own sign-out address,
 * when the tier's environment file names one.
 */

require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/identity.php';
require_once __DIR__ . '/lib/ui.php';   // for ui_brand_mark(), not the chrome

app_session_start();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
$auth = identity_settings();
if ($auth['AUTH_MODE'] === 'proxy' && $auth['AUTH_LOGOUT_URL'] !== '') {
    header('Location: ' . $auth['AUTH_LOGOUT_URL']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Signed out - PRIOps</title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="icon" href="assets/favicon.ico" sizes="any">
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<?php
// The lockup, not the chrome. ui_top() would print the navigation and the
// acting-user line, which is exactly what a signed-out page must not show,
// so this page carries the brand mark alone (PFR-13).
?>
<header class="site"><div class="bar"><div><?= ui_brand_mark(44) ?><h1>Signed out</h1></div></div></header>
<main>
    <div class="panel">
        <p>Your application session has ended. To sign out of the campus single sign-on
           entirely, close your browser.</p>
        <p><a href="index.php">Return to the board</a></p>
    </div>
</main>
</body>
</html>
