<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/peopleimport.php';

/**
 * The org chart export. Ten columns, no heading row:
 *
 *   0 first name   1 last name   2 email   3 UIN   4 unit
 *   5 org path     6 role        7 flag    8 since 9 unused
 *
 * The role column is the useful one. Most people are Member; some are
 * Manager, and a few are "Manager approve", which is the closest thing
 * PRI has to a written statement of who may sign for a unit.
 *
 * Loading it updates the people directory and builds a unit table. It
 * does not grant anybody anything: proposals come from
 * org_leadership_proposals() and are applied only when somebody says so.
 */

function org_parse(string $blobOrPath, bool $isFile = false): array
{
    $grid = [];
    if ($isFile) {
        $fh = @fopen($blobOrPath, 'r');
        if (!$fh) {
            return ['rows' => [], 'error' => 'That file could not be read.'];
        }
        while (($row = fgetcsv($fh)) !== false) {
            if (count($row) >= 7) {
                $grid[] = $row;
            }
        }
        fclose($fh);
    } else {
        foreach (preg_split('/\r\n|\r|\n/', trim($blobOrPath)) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_contains($line, "\t") ? explode("\t", $line) : str_getcsv($line);
            if (count($cells) >= 7) {
                $grid[] = $cells;
            }
        }
    }
    if (!$grid) {
        return ['rows' => [], 'error' => 'No rows with at least seven columns were found.'];
    }

    // The export has no headings, but a first row of headings would do no
    // harm: it simply fails the email test and is skipped.
    $rows = [];
    foreach ($grid as $r) {
        $email = strtolower(trim((string)($r[2] ?? '')));
        $netid = people_netid_from_email($email);
        if ($netid === null) {
            continue;
        }
        $path = trim((string)($r[5] ?? ''));
        $rows[] = [
            'NetID'     => $netid,
            'FirstName' => trim((string)$r[0]),
            'LastName'  => trim((string)$r[1]),
            'Email'     => $email,
            'UIN'       => trim((string)($r[3] ?? '')) ?: null,
            'OrgUnit'   => trim((string)($r[4] ?? '')) ?: null,
            'OrgPath'   => $path ?: null,
            'OrgRole'   => trim((string)($r[6] ?? '')) ?: null,
            'OrgSince'  => trim((string)($r[8] ?? '')) ?: null,
            'OrgDepth'  => $path !== '' ? substr_count($path, '.') : null,
            'SurveyCode' => $path !== '' ? people_survey_from_org(str_replace('.', ' ', $path)) : null,
        ];
    }
    return ['rows' => $rows];
}

function org_import(string $blobOrPath, bool $isFile, string $byNetID): array
{
    $parsed = org_parse($blobOrPath, $isFile);
    if (!empty($parsed['error'])) {
        return ['error' => $parsed['error']];
    }
    if (!$parsed['rows']) {
        return ['error' => 'No usable rows. The export should have an email in the third column.'];
    }

    $pdo = db('work');
    $chk = $pdo->prepare('SELECT COUNT(*) FROM ref.People WHERE NetID = ?');
    $ins = $pdo->prepare(
        'INSERT INTO ref.People (NetID, DisplayName, Email, UIN, SurveyCode, Org, FirstName,
            LastName, OrgPath, OrgUnit, OrgRole, OrgDepth, OrgSince, SourceLabel, UpdatedAt)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, SYSDATETIME())');
    $label = 'org chart ' . date('Y-m-d');
    $added = $updated = 0;
    $units = [];

    foreach ($parsed['rows'] as $r) {
        // "Last, First" matches how every other source writes a name.
        $display = trim($r['LastName'] . ', ' . $r['FirstName'], ', ');
        $chk->execute([$r['NetID']]);
        if ((int)$chk->fetchColumn() === 0) {
            $ins->execute([$r['NetID'], $display ?: $r['NetID'], $r['Email'], $r['UIN'],
                           $r['SurveyCode'], $r['OrgPath'], $r['FirstName'], $r['LastName'],
                           $r['OrgPath'], $r['OrgUnit'], $r['OrgRole'], $r['OrgDepth'],
                           $r['OrgSince'], $label]);
            $added++;
        } else {
            // The employee file writes a better display name, so it is
            // left alone; the org chart adds what only it knows.
            $pdo->prepare(
                'UPDATE ref.People SET FirstName = ?, LastName = ?, OrgPath = ?, OrgUnit = ?,
                    OrgRole = ?, OrgDepth = ?, OrgSince = ?, UIN = COALESCE(?, UIN),
                    SurveyCode = COALESCE(SurveyCode, ?), IsActive = 1, UpdatedAt = SYSDATETIME()
                 WHERE NetID = ?'
            )->execute([$r['FirstName'], $r['LastName'], $r['OrgPath'], $r['OrgUnit'],
                        $r['OrgRole'], $r['OrgDepth'], $r['OrgSince'], $r['UIN'],
                        $r['SurveyCode'], $r['NetID']]);
            $updated++;
        }

        $path = (string)$r['OrgPath'];
        if ($path === '') {
            continue;
        }
        if (!isset($units[$path])) {
            $units[$path] = ['members' => 0, 'managers' => [], 'survey' => $r['SurveyCode'],
                             'unit' => $r['OrgUnit'], 'depth' => (int)$r['OrgDepth']];
        }
        $units[$path]['members']++;
        if (stripos((string)$r['OrgRole'], 'manager') !== false) {
            // A person often holds two roles on one unit: plain Manager,
            // which marks the head of it, and "Manager approve", which
            // marks fiscal authority. They mean different things, so
            // both are kept and only exact repeats are dropped.
            $key = $r['NetID'] . '|' . (string)$r['OrgRole'];
            $units[$path]['managers'][$key] = ['netid' => (string)$r['NetID'], 'name' => $display,
                                               'role' => (string)$r['OrgRole']];
        }
    }

    // Rebuild the unit table from what was just read.
    $pdo->exec('DELETE FROM ref.OrgUnits');
    $insUnit = $pdo->prepare(
        'INSERT INTO ref.OrgUnits (OrgPath, UnitName, SurveyCode, Depth, ManagerNetID,
            ManagerName, ManagerRole, MemberCount, UpdatedAt)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, SYSDATETIME())');
    $unitRows = 0;
    foreach ($units as $path => $u) {
        if (!$u['managers']) {
            $insUnit->execute([$path, $u['unit'], $u['survey'], $u['depth'], null, null, null,
                               $u['members']]);
            $unitRows++;
            continue;
        }
        foreach ($u['managers'] as $m) {
            $insUnit->execute([$path, $u['unit'], $u['survey'], $u['depth'], $m['netid'],
                               $m['name'], $m['role'], $u['members']]);
            $unitRows++;
        }
    }

    return ['added' => $added, 'updated' => $updated, 'units' => $unitRows,
            'people' => count($parsed['rows'])];
}

/**
 * What the org chart suggests about leadership and access. Nothing here
 * is applied: a manager in an org chart is not automatically the person
 * who signs for space, and that judgement belongs to a human.
 */
function org_leadership_proposals(): array
{
    $pdo = db('work');
    $out = ['directors' => [], 'access' => []];

    // Plain Manager at the survey level marks the head of the survey.
    // "Manager approve" at the same level is fiscal authority, which is
    // the natural proxy rather than the director.
    $stmt = $pdo->query(
        "SELECT o.SurveyCode, o.ManagerNetID, o.ManagerName, o.ManagerRole,
                p.Email, s.DirectorEmail, s.DirectorName, s.ProxyName
         FROM ref.OrgUnits o
         LEFT JOIN ref.People p ON p.NetID = o.ManagerNetID
         LEFT JOIN ref.Surveys s ON s.Code = o.SurveyCode
         WHERE o.Depth = 0 AND o.ManagerNetID IS NOT NULL AND o.SurveyCode IS NOT NULL
         ORDER BY o.SurveyCode,
                  CASE WHEN o.ManagerRole = 'Manager' THEN 0
                       WHEN o.ManagerRole LIKE '%approve%' THEN 1 ELSE 2 END,
                  o.ManagerName");
    foreach ($stmt as $r) {
        $role = (string)$r['ManagerRole'];
        $out['directors'][] = [
            'survey'   => (string)$r['SurveyCode'],
            'netid'    => (string)$r['ManagerNetID'],
            'name'     => (string)$r['ManagerName'],
            'email'    => (string)($r['Email'] ?: $r['ManagerNetID'] . '@illinois.edu'),
            'role'     => $role,
            // What the org chart is actually asserting, and no more.
            //
            // "Manager approve" means fiscal authority. It was read here
            // as "the director's proxy" and that was wrong: at PRI the
            // proxy is the administrative assistant, and the org chart
            // does not mark them. Two of the real ones - Kathleen Henry
            // at ISGS and Sharon Jackson at ISTC - are recorded as
            // "Manager view", which this had dismissed as read-only.
            //
            // So the head of a unit can be proposed and the assistant
            // cannot. That is a question for a person.
            'suggests' => $role === 'Manager' ? 'head of this unit'
                : (stripos($role, 'approve') !== false
                    ? 'fiscal authority - NOT necessarily the proxy'
                    : 'oversight - may still be the administrative assistant'),
            'current'  => (string)($r['DirectorName'] ?: ''),
            'current_proxy' => (string)($r['ProxyName'] ?: ''),
            'matches'  => strcasecmp((string)$r['DirectorEmail'], (string)$r['Email']) === 0,
        ];
    }

    // People the org chart says may approve, who have no back end access.
    $stmt = $pdo->query(
        "SELECT DISTINCT p.NetID, p.DisplayName, p.SurveyCode, p.OrgPath, p.OrgRole
         FROM ref.People p
         WHERE p.OrgRole LIKE '%approve%' AND p.IsActive = 1
           AND NOT EXISTS (SELECT 1 FROM app.AdminAccess a
                           WHERE a.NetID = p.NetID AND a.IsActive = 1)
         ORDER BY p.SurveyCode, p.DisplayName");
    foreach ($stmt as $r) {
        $out['access'][] = [
            'netid'  => (string)$r['NetID'],
            'name'   => (string)$r['DisplayName'],
            'survey' => (string)$r['SurveyCode'],
            'path'   => (string)$r['OrgPath'],
            'role'   => (string)$r['OrgRole'],
        ];
    }
    return $out;
}
