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

function ui_top(string $title, string $sub = '', string $bodyClass = ''): void
{
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
    if ($sub !== '') {
        echo '<p class="sub">' . h($sub) . '</p>';
    }
    // The back end is only offered to the people who may use it, so
    // staff never meet a link that answers with a refusal.
    echo '</div><nav class="mainnav">'
       . '<a href="index.php">Home</a> '
       . '<a href="digest.php">My work</a> '
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

function ui_bottom(): void
{
    // The lookup script goes on every page from here rather than being
    // remembered form by form. It does nothing unless the page contains a
    // data-typeahead box, and forgetting it on one form was how the
    // admin screens ended up with a weaker mechanism of their own.
    $brand = (string)setting('app_name', 'PRIOps');
    echo '</main><footer class="foot">'
       . (can_admin() ? '<a href="admin.php">Administration</a> &middot; ' : '')
       . '<a href="stages.php">How requests move</a> &middot; '
       . h($brand) . '</footer>'
       . '<script src="assets/typeahead.js"></script>'
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
