<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * THE TWO LOOKUPS. Every form in this system that offers a person or an
 * account calls one of these, through api/lookup.php and
 * assets/typeahead.js. Nothing does its own query, and nothing preloads a
 * list into the page.
 *
 * That matters because these are the two things we do not really own.
 * Today they read the directories loaded from the PRIDE exports. When the
 * app sits behind Shibboleth on a campus server and the real directory is
 * reachable, ONLY the body of these two functions changes: the JSON shape
 * they return, the JavaScript, and every form stay exactly as they are.
 *
 * The shape each match must keep:
 *
 *   person: value, netid, email, survey, label, hint
 *   cfop:   value, label, survey, account_pi, fund_manager, activity,
 *           description, fund_type, hint
 *
 * A form asks for the fields it wants with data-fill="key:elementId",
 * so adding a key here is safe and removing one is not.
 */

const LOOKUP_LIMIT = 15;

/**
 * People by name or NetID.
 *
 * REPLACEMENT POINT: swap the body for a query against the campus
 * directory. Keep the keys.
 */
function lookup_people(string $q, int $limit = LOOKUP_LIMIT): array
{
    $like = '%' . $q . '%';
    $out = [];

    // The directory loaded from the employee export comes first: it is
    // the only source that carries a NetID for people who have never
    // signed in here.
    try {
        $stmt = db('work')->prepare(
            'SELECT TOP ' . (int)$limit . ' NetID, DisplayName, Email, SurveyCode, Org
             FROM ref.People
             WHERE IsActive = 1 AND (DisplayName LIKE ? OR NetID LIKE ?)
             ORDER BY DisplayName');
        $stmt->execute([$like, $like]);
        foreach ($stmt as $r) {
            $out[strtolower((string)$r['NetID'])] = [
                'value'  => (string)$r['DisplayName'],
                'netid'  => (string)$r['NetID'],
                'email'  => (string)$r['Email'],
                'survey' => (string)($r['SurveyCode'] ?: ''),
                'label'  => (string)$r['DisplayName'],
                'hint'   => trim((string)($r['SurveyCode'] ?: '') . ' ' . (string)($r['Org'] ?: '')),
            ];
        }
    } catch (Throwable $e) {
        // The directory has not been loaded yet; fall through to users.
    }

    // Then anybody who has signed in but is not in the directory.
    try {
        $stmt = db('work')->prepare(
            'SELECT TOP ' . (int)$limit . ' NetID, DisplayName, Email, SurveyCode
             FROM app.Users
             WHERE IsActive = 1 AND (DisplayName LIKE ? OR NetID LIKE ?) ORDER BY DisplayName');
        $stmt->execute([$like, $like]);
        foreach ($stmt as $r) {
            $key = strtolower((string)$r['NetID']);
            if (!isset($out[$key])) {
                $out[$key] = [
                    'value'  => (string)$r['DisplayName'],
                    'netid'  => (string)$r['NetID'],
                    'email'  => (string)$r['Email'],
                    'survey' => (string)($r['SurveyCode'] ?: ''),
                    'label'  => (string)$r['DisplayName'],
                    'hint'   => (string)($r['SurveyCode'] ?: ''),
                ];
            }
        }
    } catch (Throwable $e) { /* nothing more to offer */ }

    return array_slice(array_values($out), 0, $limit);
}

/**
 * Accounts by code, activity or purpose.
 *
 * REPLACEMENT POINT: swap the body for a query against the campus
 * account system. Keep the keys, and keep the label shape - people read
 * it as "code --activity purpose", the way the current forms show it.
 */
function lookup_cfop(string $q, int $limit = LOOKUP_LIMIT): array
{
    $like = '%' . $q . '%';
    $out = [];
    try {
        $stmt = db('shop')->prepare(
            'SELECT TOP ' . (int)$limit . ' CfopCode, SurveyCode, AccountPI, FundManager,
                    ActivityCode, Description, FundType, AccountPINetID, FundManagerNetID
             FROM ref.CfopAccounts
             WHERE IsActive = 1 AND (CfopCode LIKE ? OR Description LIKE ? OR ActivityCode LIKE ?)
             ORDER BY CfopCode');
        $stmt->execute([$like, $like, $like]);
        foreach ($stmt as $r) {
            $label = (string)$r['CfopCode'];
            if ($r['ActivityCode']) {
                $label .= ' --' . $r['ActivityCode'];
            }
            if ($r['Description']) {
                $label .= ' ' . $r['Description'];
            }
            $out[] = [
                'value'        => (string)$r['CfopCode'],
                'label'        => $label,
                'survey'       => (string)$r['SurveyCode'],
                'account_pi'   => (string)$r['AccountPI'],
                'fund_manager' => (string)$r['FundManager'],
                'activity'     => (string)$r['ActivityCode'],
                'description'  => (string)$r['Description'],
                'fund_type'    => (string)$r['FundType'],
                // The two people a purchase has to ask, so a form can
                // route to them without a second lookup.
                'account_pi_netid'   => (string)($r['AccountPINetID'] ?? ''),
                'fund_manager_netid' => (string)($r['FundManagerNetID'] ?? ''),
                'hint'         => trim((string)$r['SurveyCode'] . ' · ' . (string)$r['AccountPI']),
            ];
        }
    } catch (Throwable $e) {
        return [];
    }
    return $out;
}

/**
 * One account, verified. Forms that accept a typed account must check it
 * rather than trust the box, because the box can be typed into.
 */
function lookup_cfop_one(string $code): ?array
{
    $stmt = db('shop')->prepare(
        'SELECT CfopCode, SurveyCode, AccountPI, FundManager, Description, FundType,
                ActivityCode, AccountPINetID, FundManagerNetID, AccountPIEmail, FundManagerEmail
         FROM ref.CfopAccounts WHERE CfopCode = ?');
    $stmt->execute([strtoupper(trim($code))]);
    return $stmt->fetch() ?: null;
}

/**
 * The markup for a lookup box, so no form has to remember the attribute
 * names. $fill maps a key of the match to the id of the field it fills.
 */
function lookup_field(string $what, string $name, string $id, array $fill = [],
                      string $value = '', array $opts = []): string
{
    $spec = [];
    foreach ($fill as $key => $target) {
        $spec[] = $key . ':' . $target;
    }
    return '<input name="' . h($name) . '" id="' . h($id) . '"'
         . ' data-typeahead="' . h($what) . '"'
         . ($spec ? ' data-fill="' . h(implode(',', $spec)) . '"' : '')
         . ' value="' . h($value) . '" autocomplete="off"'
         . (!empty($opts['required']) ? ' required' : '')
         . (!empty($opts['placeholder']) ? ' placeholder="' . h((string)$opts['placeholder']) . '"' : '')
         . '>';
}
