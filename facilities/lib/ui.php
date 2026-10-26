<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/studio.php';
require_once __DIR__ . '/demofill.php';

// ?demo=<key> renders a form pre-filled with faux data for the briefing's
// job-aid slides. GET only, and it writes nothing - see demofill.php.
demo_fill_apply();

/**
 * Shared page chrome. Every page carries the nav (Home, Open Requests,
 * Admin) and either the NetID the university sign-in passed (AUTH_MODE
 * proxy) or, on this computer outside production, the acting-user switcher.
 *
 * Developed by M. J. Dreslik, with Claude Opus 5 (drez-ui-elements rule 27).
 */

function h(mixed $s): string
{
    // Typed ?string, this threw a TypeError under strict_types the moment
    // it met an integer id - h($row['RequestID']) was a fatal waiting for
    // the right page. ENT_SUBSTITUTE keeps a malformed byte from blanking
    // the whole string.
    if ($s === null || is_bool($s)) {
        return $s === true ? '1' : '';
    }
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * $bodyClass exists for one reason: a data sheet is a different kind of
 * page from a form. Most pages here are a column you read down. A sheet
 * is a page you take in at once, which needs the width the reading
 * measure deliberately withholds. Passing "sheet" widens the page and
 * tightens the fields; everything else is unchanged.
 */

/**
 * The PRIOps lockup: a lanternfly - the genus is Pyrops, which is the
 * joke and the point - beside the wordmark, PRI bright and Ops muted,
 * the same family resemblance as PRIDE's lion banner.
 */
function ui_brand_mark(int $h = 30): string
{
    // The mark is the user-supplied Pyrops silhouette, recoloured white
    // on transparent by tools/make_logo_asset.ps1. Swapping the animal
    // means swapping one PNG, not redrawing anything.
    $w = (int)($h * 1.57);
    return '<span class="brandmark">'
        . '<img src="assets/priops.png" width="' . $w . '" height="' . $h . '" '
        . 'alt="" style="vertical-align:middle">'
        . '<span class="brandword"><b>PRI</b><i>Ops</i></span></span>';
}

/**
 * The page chrome. $sub is plain text and is escaped here.
 *
 * $backHref and $backLabel are the back link, because the subtitle used to
 * carry one as markup. Commit 1e2ec49 made the subtitle escape its argument,
 * which was right for safety and turned seventeen pages' back links into
 * visible tags (PFR-01). A link is now a parameter rather than a string the
 * caller builds, so no page has to choose between an escaped subtitle and a
 * working link. The link reads the same on every page: an arrow, then where
 * it goes.
 */
function ui_top(
    string $title,
    string $sub = '',
    string $bodyClass = '',
    string $backHref = '',
    string $backLabel = ''
): void {
    $u = current_user();
    // The system's name is a setting, because it earned a rename once
    // already - Facilities Request System became PRIOps when safety,
    // property, the estate and the fleet moved in.
    $brand = (string)setting('app_name', 'PRIOps');
    $pageTitle = str_contains($title, $brand) ? $title : $title . ' - ' . $brand;
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . h($pageTitle) . '</title>'
       . '<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">'
       . '<link rel="icon" href="assets/favicon.ico" sizes="any">'
       . '<link rel="stylesheet" href="assets/app.css"></head>'
       . '<body' . (($c = trim($bodyClass . (demo_fill_phone() ? ' phone demo' : ''))) !== ''
           ? ' class="' . h($c) . '"' : '') . '>';
    // On the front page the title IS the brand, and printing it under the
    // lockup said PRIOps twice. There the lockup becomes the heading;
    // everywhere else it sits above the page's own title as before.
    $sameAsBrand = strcasecmp(trim($title), trim($brand)) === 0;
    echo '<header class="site"><div class="bar">'
       . '<div>'
       . ($sameAsBrand
          ? '<h1 class="brandonly">' . ui_brand_mark(44) . '</h1>'
          : ui_brand_mark(44) . '<h1>' . h($title) . '</h1>');
    if ($backHref !== '' || $sub !== '') {
        echo '<p class="sub">';
        // No class of its own: header.site .sub already styles the line and
        // the link inside it, and a class with no rule behind it is the very
        // fault this pass is clearing (PFR-02 to PFR-07).
        if ($backHref !== '') {
            echo '<a href="' . h($backHref) . '">&larr; '
               . h($backLabel !== '' ? $backLabel : 'back') . '</a>';
            if ($sub !== '') {
                echo ' &middot; ';
            }
        }
        if ($sub !== '') {
            echo h($sub);
        }
        echo '</p>';
    }
    // The back end is only offered to the people who may use it, so
    // staff never meet a link that answers with a refusal.
    echo '</div><nav class="mainnav">'
       . '<a href="index.php">Home</a> '
       . '<a href="digest.php">My Work</a> '
       // Open requests is reached from its own card on the front page, so
       // it is not repeated here.
       . (can_admin() ? '<a href="admin.php">Admin</a>' : '')
       // The Studio is granted separately and to fewer people, so the
       // link only exists for them - an administrator does not see it
       // unless they hold the grant itself.
       . (function_exists('can_studio') && can_studio()
          ? '<a href="studio.php" class="navstudio">Studio</a>' : '')
       . '</nav></div>';
    if (auth_mode() === 'proxy') {
        // NetID sign-in: the proxy owns the session, so the header names
        // the person and offers the proxy's own sign-out.
        echo '<p class="devswitch">Signed in as ' . h($u['DisplayName'] . ' (' . $u['NetID'] . ')')
           . ' <a href="logout.php">Sign out</a></p>';
    } elseif (auth_can_switch()) {
        echo '<form method="post" class="devswitch">' . csrf_field()
           . '<input type="hidden" name="dev_switch" value="1">'
           . '<label>Acting as <select name="dev_netid" onchange="this.form.submit()">';
        foreach (all_users() as $row) {
            $sel = $row['NetID'] === $u['NetID'] ? ' selected' : '';
            echo '<option value="' . h($row['NetID']) . '"' . $sel . '>'
               . h($row['DisplayName'] . ' (' . $row['NetID'] . ', ' . $row['Role']
                   . ($row['SurveyCode'] ? ', ' . $row['SurveyCode'] : '') . ')')
               . '</option>';
        }
        echo '</select></label></form>';
    }
    echo '</header><main>';
}

/**
 * Ask before an irreversible action, on the server (Q-27, PFR-39).
 *
 * `data-confirm` and PriDialog ask in the browser, which is where the
 * question belongs when JavaScript runs. A handler that writes or deletes
 * cannot rely on that: a form posted with scripting off, or by anything
 * other than the page, arrives with no question asked. This renders the
 * question as a page of its own and posts the same fields back with
 * confirmed=1, so the handler reaches its work only on the second pass.
 *
 * $fields is the name and value pairs to carry forward, usually the parts
 * of $_POST the handler needs. Values are escaped here; pass them raw.
 */
function ui_confirm_page(
    string $title,
    string $question,
    string $okLabel,
    array $fields,
    string $cancelHref,
    bool $danger = false
): void {
    ui_top($title);
    echo '<div class="panel">'
       . '<p>' . h($question) . '</p>'
       . '<form method="post">' . csrf_field()
       . '<input type="hidden" name="confirmed" value="1">';
    foreach ($fields as $name => $value) {
        if (is_array($value)) {
            foreach ($value as $v) {
                echo '<input type="hidden" name="' . h((string)$name) . '[]" value="' . h((string)$v) . '">';
            }
            continue;
        }
        echo '<input type="hidden" name="' . h((string)$name) . '" value="' . h((string)$value) . '">';
    }
    echo '<div class="actions">'
       . '<a class="btn secondary" href="' . h($cancelHref) . '">Cancel</a>'
       . '<button class="' . ($danger ? 'danger' : 'primary') . '">' . h($okLabel) . '</button>'
       . '</div></form></div>';
    ui_bottom();
    exit;
}

/**
 * The footer and the scripts. $scripts names a page's own files in
 * `assets\`, for example `ui_bottom(['location.js', 'work.js'])`.
 *
 * A page used to print its scripts itself, which put them inside `<main>`
 * where no script belongs (PFR-22), and three pages loaded typeahead.js a
 * second time on top of the copy this function already sends. Naming them
 * here puts every script in one place, after the content, in the order the
 * page asked for.
 */
/**
 * Ask for a script from inside an include, which cannot reach the page's
 * own `ui_bottom()` call. `lib/mapview.php` asks for `map.js` this way, so
 * the floor plan carries its own requirement and the admin page does not
 * have to know which section it is showing.
 */
function ui_script(string $file): void
{
    $GLOBALS['ui_scripts'][] = $file;
}

function ui_bottom(array $scripts = []): void
{
    $scripts = array_merge($scripts, (array)($GLOBALS['ui_scripts'] ?? []));
    $scripts = array_values(array_unique($scripts));
    // The lookup script goes on every page from here rather than being
    // remembered form by form. It does nothing unless the page contains a
    // data-typeahead box, and forgetting it on one form was how the
    // admin screens ended up with a weaker mechanism of their own.
    $brand = (string)setting('app_name', 'PRIOps');
    $own = '';
    foreach ($scripts as $file) {
        $name = (string)$file;
        $defer = '';
        if (str_ends_with($name, ' defer')) {
            $name = substr($name, 0, -6);
            $defer = ' defer';
        }
        // Only a plain file name in assets\, so a page cannot reach out of it.
        if (preg_match('/^[A-Za-z0-9._-]+\.js$/', $name) !== 1) {
            continue;
        }
        $own .= '<script src="assets/' . h($name) . '"' . $defer . '></script>';
    }
    echo '</main><footer class="foot">'
       . (can_admin() ? '<a href="admin.php">Administration</a> &middot; ' : '')
       . '<a href="stages.php">How Requests Move</a> &middot; '
       . h($brand) . ' &middot; '
       // The authorship credit (drez-ui-elements rule 27); tools/check.js looks for it.
       . '<span class="credit">Developed by M. J. Dreslik, with Claude Opus 5</span></footer>'
       . $own
       . '<script src="assets/typeahead.js"></script>'
       // Same reasoning as the lookup script: the dialog helper goes on
       // every page from here, so a form that asks before it removes
       // something never has to remember to load it (Q-27).
       . '<script src="assets/dialog.js"></script>'
       // Emits nothing unless ?open= is present (capture aid).
       . (function_exists('demo_open_selects') ? demo_open_selects() : '')
       . (function_exists('demo_capture_frame') ? demo_capture_frame() : '')
       . '</body></html>';
}

function flash_set(string $kind, string $msg): void
{
    app_session_start();
    $_SESSION['flash'] = ['kind' => $kind, 'msg' => $msg];
}

function flash_show(): void
{
    app_session_start();
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        $cls = $f['kind'] === 'ok' ? 'panel ok' : 'panel err';
        echo '<div class="' . $cls . '"><p>' . h($f['msg']) . '</p></div>';
    }
}

/**
 * Dates a person reads (Q-016, owner 17 Oct 2026).
 *
 * One order everywhere: "Oct 17, 2026" on screens, "October 17, 2026" on a
 * printed safety report. The safety family used to run day first, "17 Oct
 * 2026" and "17 October 2026", against month first on every other page
 * (PFR-17). $when takes a timestamp or anything strtotime() reads; an
 * empty value gives an empty string rather than 1 Jan 1970.
 */
function ui_when($when): ?int
{
    if ($when === null || $when === '' || $when === false) {
        return null;
    }
    if (is_int($when)) {
        return $when;
    }
    $t = strtotime((string)$when);
    return $t === false ? null : $t;
}

/** Oct 17, 2026 */
function ui_date($when): string
{
    $t = ui_when($when);
    return $t === null ? '' : date('M j, Y', $t);
}

/** Oct 17, 2026, 3:04pm */
function ui_datetime($when): string
{
    $t = ui_when($when);
    return $t === null ? '' : date('M j, Y, g:ia', $t);
}

/** October 17, 2026, for a printed report. */
function ui_date_long($when): string
{
    $t = ui_when($when);
    return $t === null ? '' : date('F j, Y', $t);
}

/**
 * Title Case, for anything a person reads as a name rather than a
 * sentence: a status, a stage, a section.
 *
 * Small joining words stay lowercase unless they lead, which is what
 * makes "With the Fund Approvers" read better than "With The Fund
 * Approvers" while still giving "On Hold" rather than "on hold".
 */
function title_case(string $s): string
{
    static $small = ['a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'from', 'in', 'into',
                     'nor', 'of', 'on', 'onto', 'or', 'per', 'the', 'to', 'up', 'via', 'with'];
    $words = preg_split('/(\s+)/u', trim($s), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
    $out = '';
    $index = 0;
    $last = count($words) - 1;
    // A step number is not a word for this purpose. Without this, "1. The
    // Item" came back as "1. the Item", because "The" was no longer first.
    if (isset($words[0]) && preg_match('/^\d+[.)]?$/', $words[0])) {
        $index = -1;
    }
    foreach ($words as $i => $w) {
        if (trim($w) === '') {
            $out .= $w;
            continue;
        }
        // Anything already carrying an inner capital is somebody's
        // spelling - iCard, F&S, NetID, PRI - and is left alone.
        if (preg_match('/[A-Z]/', substr($w, 1)) || preg_match('/^[A-Z&]+$/', $w)) {
            $out .= $w;
            $index++;
            continue;
        }
        $lower = mb_strtolower($w);
        $out .= ($index > 0 && $i < $last && in_array(rtrim($lower, ',.'), $small, true))
            ? $lower
            : mb_strtoupper(mb_substr($lower, 0, 1)) . mb_substr($lower, 1);
        $index++;
    }
    return $out;
}

/** A raw code as a readable name: "on_hold" becomes "On Hold". */
function nice_code(?string $code): string
{
    return title_case(str_replace('_', ' ', (string)$code));
}
