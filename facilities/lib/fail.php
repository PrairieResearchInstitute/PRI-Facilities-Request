<?php
declare(strict_types=1);

/**
 * The page shown when the application cannot reach its database.
 *
 * Before this existed, an unreachable database threw an uncaught
 * PDOException, and PHP printed it: the connection string, a stack trace,
 * the file paths, and the word "password" beside a redacted object. That
 * is the one moment the raw error is most likely to be seen by somebody
 * who is not a developer - because the database being down is not a
 * developer's mistake, it is Tuesday - and the one moment it must not
 * spill the server name and the account.
 *
 * So a global handler catches it and renders this instead: what is wrong,
 * in a sentence, and what to do about it. Nothing here reads the
 * database or loads a file from another host, because both are exactly
 * what might be unavailable when it runs.
 */

/** Is this driver error the ODBC driver simply not being installed? */
function db_error_is_missing_driver(string $msg): bool
{
    return stripos($msg, 'ODBC Driver') !== false
        && (stripos($msg, 'requires') !== false
            || stripos($msg, 'not installed') !== false
            || stripos($msg, 'Data source name not found') !== false);
}

/**
 * Did the driver fail to START (as opposed to being absent or the server
 * being unreachable)? "SQLAllocHandle on SQL_HANDLE_ENV failed" is the
 * signature: the driver is registered and found, but this particular,
 * long-running server process could not initialise it - typically because
 * the SQL Server driver or the Visual C++ runtime was updated on disk while
 * this process was already running. A fresh process is fine, so the fix is
 * to restart the server, not to reboot the machine.
 */
function db_error_is_driver_init(string $msg): bool
{
    return stripos($msg, 'SQLAllocHandle') !== false
        || stripos($msg, 'SQL_HANDLE_ENV') !== false;
}

/**
 * Install the handler that turns an uncaught database failure into a
 * readable page. Registered once, from db.php, before any query runs.
 */
function db_install_failure_handler(): void
{
    // The command line is where the tools and tests run. There, an
    // exception should behave normally - print and stop - not paint an
    // HTML page into a terminal.
    if (PHP_SAPI === 'cli') {
        return;
    }
    set_exception_handler(static function (Throwable $e): void {
        // Only database unreachability gets the friendly treatment. A
        // real bug in the code should still fail loudly in development,
        // and be logged rather than dressed up in production.
        if ($e instanceof DbUnreachable) {
            db_render_unreachable($e);
            return;
        }
        error_log((string)$e);
        db_render_generic($e);
    });
}

/** The database-is-not-answering page. $e->getMessage() is already clean. */
function db_render_unreachable(Throwable $e): void
{
    $reason = $e->getMessage();

    if (db_error_is_driver_init($reason)) {
        $title = 'The application needs restarting';
        $lead  = 'The database is reachable, but this running copy of the'
               . ' application could not start its database driver. This almost'
               . ' always clears by restarting the application - and needs no'
               . ' reboot.';
        $body  = '<p class="reason">' . htmlspecialchars($reason, ENT_QUOTES) . '</p>'
               . db_restart_help_html();
        db_render_page($title, $lead, $body);
        return;
    }

    if (db_error_is_missing_driver($reason)) {
        $title = 'This computer is missing the SQL Server driver';
        $lead  = 'The application is installed and its files are here. What this'
               . ' computer does not have is the small Microsoft component that'
               . ' lets any program talk to SQL Server.';
        $body  = db_driver_help_html();
    } else {
        $title = 'The database is not answering';
        $lead  = 'The application is running, but the database did not respond.'
               . ' This is almost always the database service being stopped, or'
               . ' the server name having changed.';
        $body  = '<p class="reason">' . htmlspecialchars($reason, ENT_QUOTES) . '</p>'
               . '<p>If the database lives on another computer, check that it is'
               . ' switched on and reachable. If it is on this computer, the SQL'
               . ' Server service may simply need to be started.</p>';
    }

    db_render_page($title, $lead, $body);
}

/** How to restart the local server - the fix for a driver that failed to start. */
function db_restart_help_html(): string
{
    return '<h2>To Fix It, Without a Reboot</h2>'
        . '<p>Close the console window titled <strong>PRI Facilities'
        . ' Request</strong> (that black window is the server), then start it'
        . ' again from <strong>start-local.bat</strong>. The new process picks'
        . ' up the current driver and connects.</p>'
        . '<p class="aside">This happens when the SQL Server driver or the'
        . ' Visual C++ runtime is updated by Windows while the server is'
        . ' already running: the update leaves the old process unable to load'
        . ' the driver, but every new process is fine. Reloading this page'
        . ' alone will not help - the server itself has to be restarted.</p>';
}

/** What to do about a missing ODBC driver. The no-reboot script comes first. */
function db_driver_help_html(): string
{
    $sep    = htmlspecialchars(DIRECTORY_SEPARATOR);
    $odbc   = app_root() . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'odbc';
    $script = is_file($odbc . DIRECTORY_SEPARATOR . 'install-driver.cmd');
    $msi    = is_file($odbc . DIRECTORY_SEPARATOR . 'msodbcsql18.msi');

    $h = '<h2>To Fix It, Without a Reboot</h2>';
    if ($script) {
        $h .= '<p>The driver ships with the application, and registering it does'
            . ' <strong>not</strong> need a reboot. Open the application folder,'
            . ' go into <code>tools' . $sep . 'odbc</code>, and run'
            . ' <strong>install-driver.cmd</strong>. Approve the one'
            . ' administrator prompt; it registers the driver in a few seconds.'
            . ' Then start the application again.</p>';
        $h .= '<p class="aside">It does not run the Microsoft installer (that one'
            . ' can demand a reboot when a previous attempt left one pending). It'
            . ' places the driver file locally and points Windows at it, which'
            . ' takes effect immediately.</p>';
    } elseif ($msi) {
        $h .= '<p>Open the application folder, go into <code>tools' . $sep
            . 'odbc</code>, and run <strong>msodbcsql18.msi</strong>. Accept the'
            . ' licence, click through, and start the application again.</p>';
    } else {
        $h .= '<p>Install <strong>Microsoft ODBC Driver 18 for SQL Server</strong>'
            . ' (the 64-bit version). It is a free Microsoft download:</p>'
            . '<p><code>https://go.microsoft.com/fwlink/?linkid=2249006</code></p>';
    }
    $h .= '<p class="aside">This is the one piece the application cannot carry'
        . ' ready-to-run inside its own folder: the driver has to be registered'
        . ' with Windows. It only has to be done once per computer.</p>';
    return $h;
}

/** A last-resort page for an unexpected fault; detail shown only in development. */
function db_render_generic(Throwable $e): void
{
    $dev = false;
    try {
        $dev = app_environment() !== 'production';
    } catch (Throwable $ignore) {
        // Could not even read the config; treat as production and say little.
    }
    $body = $dev
        ? '<p class="reason">' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</p>'
          . '<pre class="trace">' . htmlspecialchars($e->getTraceAsString(), ENT_QUOTES) . '</pre>'
        : '<p>The problem has been logged. Please try again, and tell the system'
          . ' owner if it keeps happening.</p>';
    db_render_page('Something went wrong',
        'The application hit an error it did not expect.', $body);
}

/** The shared shell for every failure page. Self-contained on purpose. */
function db_render_page(string $title, string $lead, string $bodyHtml): void
{
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        header('Retry-After: 30');
    }
    $t = htmlspecialchars($title, ENT_QUOTES);
    $l = htmlspecialchars($lead, ENT_QUOTES);
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$t} - PRIOps</title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="icon" href="assets/favicon.ico" sizes="any">
<style>
  /* The house tokens of facilities/assets/app.css, copied because this page
     renders when the application cannot answer and must not depend on
     anything else loading. The values track section 2 of docs/design.md:
     this block held a palette of its own, with a different paper, ink,
     muted and line, its own body face, and an --accent
     that meant orange where the app's --accent means navy (PFR-12). */
  :root { --accent:#13294b; --navy:var(--accent); --accent2:#FF5F05; --il-altgeld:#C84113;
          --brand-ops:#8FA9C4; --paper:#f5f4f1; --panel:#fff; --ink:#1a1a1a;
          --muted:#666; --line:#d8d5cf; }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--paper); color:var(--ink);
         font:16px/1.45 'Source Sans 3','Source Sans Pro',Arial,sans-serif; }
  header { background:var(--accent); color:#fff; padding:1.1rem 1.4rem;
           border-bottom:4px solid var(--accent2); }
  header .mark { font-weight:800; font-size:1.15rem; letter-spacing:.01em;
                 font-family:Arial,"Segoe UI",sans-serif; }
  /* "Ops" takes the lockup's own blue, as .brandword i does in app.css.
     Orange on navy is the one pairing the orange rule rules out. */
  header .mark span { color:var(--brand-ops); }
  main { max-width:44rem; margin:2.4rem auto; padding:0 1.4rem; }
  h1 { font-size:1.6rem; margin:0 0 .6rem; color:var(--accent); }
  .lead { font-size:1.08rem; margin:0 0 1.4rem; }
  h2 { font-size:1.05rem; margin:1.6rem 0 .5rem; color:var(--accent); }
  p { margin:0 0 .8rem; }
  code { background:var(--panel); border:1px solid var(--line); border-radius:4px;
         padding:.08rem .35rem; font-family:"Cascadia Mono",Consolas,"DejaVu Sans Mono",monospace; font-size:.95em; }
  .reason { background:var(--panel); border:1px solid var(--line); border-left:4px solid var(--accent2);
            border-radius:4px; padding:.7rem .9rem; }
  .aside { color:var(--muted); font-size:.95rem; margin-top:1.2rem; }
  .trace { background:var(--panel); border:1px solid var(--line); border-radius:4px; padding:.7rem .9rem;
           overflow:auto; font-size:.82rem; color:var(--muted); }
  .retry { display:inline-block; margin-top:1.4rem; background:var(--accent); color:#fff;
           text-decoration:none; padding:.55rem 1.2rem; border-radius:4px; font-weight:600; }
</style>
</head>
<body>
<header><div class="mark">PRI<span>Ops</span></div></header>
<main>
  <h1>{$t}</h1>
  <p class="lead">{$l}</p>
  {$bodyHtml}
  <a class="retry" href="">Try again</a>
</main>
</body>
</html>
HTML;
    exit;
}
