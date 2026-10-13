<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Put a NetID against the people named on an account.
 *
 * The active grants report names the principal investigator and the fund
 * manager, but only sometimes carries a NetID for the first and never
 * for the second. Without one, neither of them can be reached, told, or
 * matched to a person signing in - so the fund manager half of every
 * purchase approval has nobody who can answer it.
 *
 * The people directory has 1,100-odd NetIDs. This matches the names to
 * it, and it is deliberately cautious: an exact match on "Last, First"
 * only, and only when that matches exactly one person. A wrong NetID
 * here would route somebody else's spending to the wrong approver, which
 * is worse than a blank.
 */

/** "Deany, Adam F" and "Deany, Adam" both reduce to "deany|adam". */
function acct_name_key(?string $name): string
{
    $name = trim((string)$name);
    if ($name === '') {
        return '';
    }
    if (str_contains($name, ',')) {
        [$last, $first] = array_pad(explode(',', $name, 2), 2, '');
    } else {
        // "Adam Deany" as well as "Deany, Adam".
        $bits = preg_split('/\s+/', $name) ?: [];
        $last = array_pop($bits) ?: '';
        $first = implode(' ', $bits);
    }
    // Only the first forename counts: the report carries middle initials
    // and the directory usually does not.
    $first = preg_split('/\s+/', trim($first))[0] ?? '';
    $norm = fn(string $s) => strtolower(preg_replace('/[^a-z]/i', '', $s) ?? '');
    return $norm($last) . '|' . $norm($first);
}

/**
 * Fill in the missing NetIDs. Returns what it did, and what it could not
 * do, so the gap is visible rather than silently smaller.
 */
function acct_match_people(bool $apply = true): array
{
    $work = db('work');
    $shop = db('shop');

    // The directory, keyed by name. A name held by two people is dropped:
    // there is no safe way to choose between them here.
    $byName = [];
    $dupes = [];
    $q = $work->query("SELECT NetID, DisplayName, Email FROM ref.People
                       WHERE IsActive = 1 AND ISNULL(NetID, '') <> ''
                         AND ISNULL(SourceLabel, '') <> 'EXAMPLE-DATA'");
    foreach ($q as $r) {
        $k = acct_name_key((string)$r['DisplayName']);
        if ($k === '|') {
            continue;
        }
        if (isset($byName[$k]) && $byName[$k]['netid'] !== $r['NetID']) {
            $dupes[$k] = true;
            continue;
        }
        $byName[$k] = ['netid' => (string)$r['NetID'], 'email' => (string)$r['Email']];
    }
    foreach (array_keys($dupes) as $k) {
        unset($byName[$k]);
    }

    $rows = $shop->query(
        "SELECT CfopID, CfopCode, AccountPI, AccountPINetID, AccountPIEmail,
                FundManager, FundManagerNetID, FundManagerEmail
         FROM ref.CfopAccounts WHERE IsActive = 1")->fetchAll();

    $upd = $shop->prepare(
        'UPDATE ref.CfopAccounts SET AccountPINetID = ?, AccountPIEmail = ?,
             FundManagerNetID = ?, FundManagerEmail = ? WHERE CfopID = ?');

    $out = ['accounts' => count($rows), 'pi_filled' => 0, 'fm_filled' => 0,
            'pi_unmatched' => [], 'fm_unmatched' => [], 'ambiguous' => count($dupes)];

    foreach ($rows as $r) {
        $piNet = (string)$r['AccountPINetID'];
        $piMail = (string)$r['AccountPIEmail'];
        $fmNet = (string)$r['FundManagerNetID'];
        $fmMail = (string)$r['FundManagerEmail'];
        $touched = false;

        if ($piNet === '' && trim((string)$r['AccountPI']) !== '') {
            $hit = $byName[acct_name_key((string)$r['AccountPI'])] ?? null;
            if ($hit) {
                $piNet = $hit['netid'];
                $touched = true;
                $out['pi_filled']++;
            } else {
                $out['pi_unmatched'][(string)$r['AccountPI']] =
                    ($out['pi_unmatched'][(string)$r['AccountPI']] ?? 0) + 1;
            }
        }
        if ($fmNet === '' && trim((string)$r['FundManager']) !== '') {
            $hit = $byName[acct_name_key((string)$r['FundManager'])] ?? null;
            if ($hit) {
                $fmNet = $hit['netid'];
                $touched = true;
                $out['fm_filled']++;
            } else {
                $out['fm_unmatched'][(string)$r['FundManager']] =
                    ($out['fm_unmatched'][(string)$r['FundManager']] ?? 0) + 1;
            }
        }
        // An address only if the directory had one; otherwise the NetID
        // is enough for this system to find them.
        if ($piMail === '' && $piNet !== '') {
            $piMail = ($byName[acct_name_key((string)$r['AccountPI'])]['email'] ?? '')
                ?: $piNet . '@illinois.edu';
            $touched = true;
        }
        if ($fmMail === '' && $fmNet !== '') {
            $fmMail = ($byName[acct_name_key((string)$r['FundManager'])]['email'] ?? '')
                ?: $fmNet . '@illinois.edu';
            $touched = true;
        }

        if ($touched && $apply) {
            $upd->execute([$piNet ?: null, $piMail ?: null, $fmNet ?: null, $fmMail ?: null,
                           (int)$r['CfopID']]);
        }
    }
    arsort($out['pi_unmatched']);
    arsort($out['fm_unmatched']);
    return $out;
}
