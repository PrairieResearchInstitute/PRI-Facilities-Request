<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

/**
 * Who sees the back end. A role says what a person may do once inside;
 * this says whether they get in at all. The list is by NetID, kept per
 * survey, and is normally the survey's facilities and safety staff, the
 * director, and the director's proxy. The Office of the Executive
 * Director adds finance and facilities staff, the deputy and executive
 * directors, and their proxies.
 *
 * An administrator always has access, so the system cannot be locked
 * away from the person who administers it.
 */

function access_rows(?string $netid = null): array
{
    $netid = $netid ?? current_user()['NetID'];
    static $cache = [];
    if (isset($cache[$netid])) {
        return $cache[$netid];
    }
    try {
        $stmt = db('work')->prepare(
            'SELECT * FROM app.AdminAccess WHERE NetID = ? AND IsActive = 1');
        $stmt->execute([$netid]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        $rows = [];
    }
    // The campus groups the proxy passed belong to the person signed in,
    // so their grants join that person's rows and nobody else's. A command
    // line run (digests) has no proxy identity and never reaches here.
    $signedIn = auth_identity()['netid'];
    if ($signedIn !== null && strcasecmp((string)$netid, $signedIn) === 0) {
        $rows = array_merge($rows, access_group_rows(current_user()));
    }
    return $cache[$netid] = $rows;
}

/**
 * The access functions a person's campus groups grant, shaped as
 * app.AdminAccess rows so every check below reads them the same way.
 */
function access_group_rows(array $user): array
{
    $out = [];
    foreach ($user['GroupGrants'] ?? [] as $g) {
        if (($g['AccessFunction'] ?? null) === null || $g['AccessFunction'] === '') {
            continue;
        }
        $out[] = [
            'AdminAccessID'  => null,
            'NetID'          => $user['NetID'],
            'SurveyCode'     => ($g['SurveyCode'] ?? '') === '' ? null : $g['SurveyCode'],
            'AccessFunction' => strtolower((string)$g['AccessFunction']),
            'Note'           => 'campus group ' . $g['GroupName'],
            'IsActive'       => '1',
            'GrantedAt'      => null,
            'GrantedByNetID' => null,
            'Source'         => 'group',
        ];
    }
    return $out;
}

/** May this person see the administration side at all? */
function can_admin(?array $user = null): bool
{
    $user = $user ?? current_user();
    if (($user['Role'] ?? '') === 'admin') {
        return true;
    }
    return access_rows($user['NetID']) !== [];
}

/** Does this person hold one of these functions, optionally for a survey? */
function has_function(array $functions, ?string $survey = null, ?array $user = null): bool
{
    $user = $user ?? current_user();
    if (($user['Role'] ?? '') === 'admin') {
        return true;
    }
    foreach (access_rows($user['NetID']) as $row) {
        if (!in_array($row['AccessFunction'], $functions, true)) {
            continue;
        }
        // A row with no survey covers every survey.
        if ($survey === null || $row['SurveyCode'] === null
            || strcasecmp((string)$row['SurveyCode'], $survey) === 0) {
            return true;
        }
    }
    return false;
}

/** The surveys this person covers, for filtering the back end. */
function access_surveys(?array $user = null): array
{
    $user = $user ?? current_user();
    $out = [];
    foreach (access_rows($user['NetID']) as $row) {
        if ($row['SurveyCode'] === null) {
            return [];   // every survey; no filter
        }
        $out[strtoupper((string)$row['SurveyCode'])] = true;
    }
    return array_keys($out);
}

/** A short description of why somebody is in the back end. */
function access_description(?array $user = null): string
{
    $user = $user ?? current_user();
    if (($user['Role'] ?? '') === 'admin') {
        return 'system administrator';
    }
    $bits = [];
    foreach (access_rows($user['NetID']) as $row) {
        $bits[] = trim(((string)$row['SurveyCode'] ?: 'all surveys') . ' ' . (string)$row['AccessFunction']);
    }
    return $bits ? implode(', ', $bits) : 'no back end access';
}

/** Refuse with 403 rather than redirect (spec rule 5). */
function require_admin_access(): void
{
    if (!can_admin()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "The administration side is limited to the facilities and safety staff, the "
           . "director and the director's proxy for each survey.\n\n"
           . 'You are signed in as ' . current_user()['NetID'] . ". If you should have access, "
           . "ask an administrator to add your NetID under People and roles.";
        exit;
    }
}

/** May this person decide the director stage for a survey? */
function may_decide_director(string $survey, ?array $user = null): bool
{
    return has_function(['director', 'proxy', 'executive', 'deputy'], $survey, $user);
}

/** May this person decide the committee stage for a survey? */
function may_decide_committee(string $survey, ?array $user = null): bool
{
    return has_function(['director', 'proxy', 'facilities', 'executive', 'deputy'], $survey, $user);
}

/** The same question as has_function, asked where the answer must stop the request. */
function require_function(array $functions, ?string $survey = null): void
{
    if (!has_function($functions, $survey)) {
        http_response_code(403);
        header('Content-Type: text/plain');
        echo 'That belongs to another office: ' . implode(' or ', $functions) . '.';
        exit;
    }
}

/**
 * Whose requests does this person see on the front end?
 *
 * Directors, their administrative assistants and the OED staff who run
 * these programmes see everything - which is what can_admin() already
 * means, since that list is exactly those people. Everybody else sees
 * what they filed.
 *
 * This is a front-end rule. The back end has always been gated by
 * can_admin() and is unchanged.
 */
function may_see_all_requests(?array $user = null): bool
{
    return can_admin($user);
}

/**
 * Who may read a safety incident.
 *
 * Everybody with back end access, because that list is already exactly
 * the people named: every survey's director and administrative
 * assistant, and the OED point people who run each programme. There is
 * no separate, narrower list to draw - drawing one would have excluded
 * the fleet and property leads, who are OED point people granted across
 * all surveys rather than tagged to OED.
 *
 * An incident report can name a person and describe an injury, so this
 * is written down as its own rule even though it currently answers the
 * same as can_admin(). If the ruling ever narrows, it narrows here, and
 * every screen that asks follows.
 */
function may_read_incidents(?array $user = null): bool
{
    return can_admin($user);
}

/**
 * Who may record what came of one. Reading an incident and closing it
 * are different questions: the safety office investigates and decides,
 * and the executive and deputy directors stand behind them.
 */
function may_decide_incidents(?array $user = null): bool
{
    return has_function(['safety', 'executive', 'deputy'], null, $user);
}
