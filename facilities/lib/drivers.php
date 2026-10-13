<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/requests2.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/picklists.php';
require_once __DIR__ . '/notify.php';

/**
 * Driver paperwork, and the question the booking form asks it.
 *
 * Nobody drives a University vehicle without their forms on file. Today
 * that is somebody's memory and a folder, which means the check happens
 * when there is a reason to look - after an incident - rather than before
 * the keys are handed over.
 *
 * So the forms are filed here with an expiry, and driver_clearance() is
 * the one question everything else asks: is this person allowed to drive
 * today, and if not, what is missing. It returns the reason rather than a
 * yes or no, because "not cleared" is useless to somebody standing at the
 * booking form and "your MVR authorisation expired in March" is not.
 *
 * Which forms exist, how long they last and where to get them are data.
 * Nothing here knows any particular form by name.
 */

/** Every kind of form the unit asks for. */
function driver_types(bool $activeOnly = true): array
{
    $sql = 'SELECT * FROM ref.DriverFormTypes'
         . ($activeOnly ? ' WHERE IsActive = 1' : '')
         . ' ORDER BY SortOrder, Title';
    return db('fleet')->query($sql)->fetchAll();
}

/** One kind, or null. */
function driver_type(string $code): ?array
{
    $st = db('fleet')->prepare('SELECT * FROM ref.DriverFormTypes WHERE FormCode = ?');
    $st->execute([$code]);
    return $st->fetch() ?: null;
}

/**
 * Everything on file for one person, newest first.
 *
 * Expiry is read off the row rather than recomputed, so changing a type's
 * validity period does not silently re-date paperwork that was already
 * accepted under the old one.
 */
function driver_forms(string $netid, bool $acceptedOnly = false): array
{
    $sql = 'SELECT f.*, t.Title, t.ValidMonths, t.IsRequired
            FROM sh.DriverForms f
            LEFT JOIN ref.DriverFormTypes t ON t.FormCode = f.FormCode
            WHERE f.NetID = ?'
         . ($acceptedOnly ? " AND f.Status = 'accepted'" : '')
         . ' ORDER BY f.CompletedOn DESC, f.DriverFormID DESC';
    $st = db('fleet')->prepare($sql);
    $st->execute([strtolower(trim($netid))]);
    return $st->fetchAll();
}

/**
 * May this person drive, and if not, why not.
 *
 * Returns:
 *   ok        - nothing required is missing or lapsed
 *   missing   - required forms never filed, or filed and rejected
 *   expired   - required forms that have run out
 *   expiring  - accepted and still good, but not for much longer
 *   waiting   - filed and not yet checked; not clearance, but worth saying
 *   forms     - the best row on file for each type, whatever its state
 */
function driver_clearance(string $netid): array
{
    $netid = strtolower(trim($netid));
    $today = new DateTimeImmutable('today');
    $warnDays = max(0, (int)setting('driver_warn_days', '45'));

    $best = [];
    foreach (driver_forms($netid) as $f) {
        $code = (string)$f['FormCode'];
        // Newest first, so the first row seen for a type is the one that
        // counts - except that an accepted form beats an unchecked one
        // filed later, because only an accepted form clears anybody.
        if (!isset($best[$code])
            || ($f['Status'] === 'accepted' && $best[$code]['Status'] !== 'accepted')) {
            $best[$code] = $f;
        }
    }

    $out = ['ok' => true, 'missing' => [], 'expired' => [], 'expiring' => [],
            'waiting' => [], 'forms' => $best, 'netid' => $netid];

    foreach (driver_types() as $t) {
        $code = (string)$t['FormCode'];
        $required = (int)$t['IsRequired'] === 1;
        $f = $best[$code] ?? null;

        if (!$f || $f['Status'] === 'rejected') {
            if ($required) {
                $out['missing'][] = ['code' => $code, 'title' => (string)$t['Title'],
                                     'why' => $f ? 'was rejected' : 'has never been filed',
                                     'where' => (string)$t['WhereToGetIt']];
                $out['ok'] = false;
            }
            continue;
        }

        if ($f['Status'] === 'submitted') {
            $out['waiting'][] = ['code' => $code, 'title' => (string)$t['Title']];
            if ($required) {
                // Filed but unchecked is not cleared. Saying otherwise
                // would make the check a formality anybody could pass by
                // uploading a blank page.
                $out['missing'][] = ['code' => $code, 'title' => (string)$t['Title'],
                                     'why' => 'is filed but has not been checked yet',
                                     'where' => (string)$t['WhereToGetIt']];
                $out['ok'] = false;
            }
            continue;
        }

        $expires = $f['ExpiresOn'] ? new DateTimeImmutable((string)$f['ExpiresOn']) : null;
        if ($expires && $expires < $today) {
            if ($required) {
                $out['expired'][] = ['code' => $code, 'title' => (string)$t['Title'],
                                     'on' => $expires->format('M j, Y'),
                                     'where' => (string)$t['WhereToGetIt']];
                $out['ok'] = false;
            }
            continue;
        }
        if ($expires && $warnDays > 0
            && $expires <= $today->modify('+' . $warnDays . ' days')) {
            $out['expiring'][] = ['code' => $code, 'title' => (string)$t['Title'],
                                  'on' => $expires->format('M j, Y'),
                                  'days' => (int)$today->diff($expires)->days];
        }
    }
    return $out;
}

/** The clearance as one sentence, for a log or an email. */
function driver_clearance_note(array $c): string
{
    if ($c['ok']) {
        $bits = ['paperwork on file'];
        foreach ($c['expiring'] as $e) {
            $bits[] = $e['title'] . ' expires ' . $e['on'];
        }
        return implode('; ', $bits);
    }
    $bits = [];
    foreach ($c['missing'] as $m) {
        $bits[] = $m['title'] . ' ' . $m['why'];
    }
    foreach ($c['expired'] as $e) {
        $bits[] = $e['title'] . ' expired ' . $e['on'];
    }
    return implode('; ', $bits);
}

/**
 * File a form. The expiry is worked out here, from what the person signed
 * and how long that kind of form lasts.
 */
function driver_file(array $in, array $user): array
{
    $code = trim((string)($in['form_code'] ?? ''));
    $type = driver_type($code);
    if (!$type) {
        return ['error' => 'Choose which form this is.'];
    }
    $netid = strtolower(trim((string)($in['netid'] ?? ''))) ?: (string)$user['NetID'];
    $name = trim((string)($in['name'] ?? '')) ?: (string)$user['DisplayName'];

    $completed = trim((string)($in['completed_on'] ?? ''));
    $ts = $completed !== '' ? strtotime($completed) : false;
    if ($ts === false) {
        return ['error' => 'Give the date on the form, as the person signed it.'];
    }
    $completedOn = new DateTimeImmutable(date('Y-m-d', $ts));
    if ($completedOn > new DateTimeImmutable('today')) {
        return ['error' => 'That date is in the future. Use the date the form was signed.'];
    }

    $expires = (int)$type['ValidMonths'] > 0
        ? $completedOn->modify('+' . (int)$type['ValidMonths'] . ' months')
        : null;
    if ($expires && $expires < new DateTimeImmutable('today')) {
        return ['error' => 'A ' . $type['Title'] . ' signed on ' . $completedOn->format('M j, Y')
                         . ' ran out on ' . $expires->format('M j, Y')
                         . '. File the current one instead.'];
    }

    $ticket = rq_ticket('driver');
    $pdo = db('fleet');
    $pdo->prepare(
        'INSERT INTO sh.DriverForms (TicketNumber, NetID, PersonName, SurveyCode, FormCode,
            CompletedOn, ExpiresOn, LicenceState, LicenceNumberLast4, Note,
            SubmittedByNetID, Status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'submitted\')'
    )->execute([
        $ticket, $netid, $name,
        strtoupper(trim((string)($in['survey'] ?? ''))) ?: (string)($user['SurveyCode'] ?? ''),
        $code, $completedOn->format('Y-m-d'), $expires ? $expires->format('Y-m-d') : null,
        strtoupper(trim((string)($in['licence_state'] ?? ''))) ?: null,
        substr(preg_replace('/\D/', '', (string)($in['licence_last4'] ?? '')) ?? '', -4) ?: null,
        trim((string)($in['note'] ?? '')) ?: null,
        $user['NetID'],
    ]);
    $id = (int)$pdo->lastInsertId();

    // An older one of the same kind is no longer the answer for anybody.
    $pdo->prepare(
        "UPDATE sh.DriverForms SET Status = 'superseded', UpdatedAt = SYSDATETIME()
          WHERE NetID = ? AND FormCode = ? AND DriverFormID <> ?
            AND Status IN ('submitted', 'accepted')")
        ->execute([$netid, $code, $id]);

    driver_notify_filed($id);
    return ['id' => $id, 'ticket' => $ticket, 'expires' => $expires];
}

/** One filed form. */
function driver_get(int $id): ?array
{
    $st = db('fleet')->prepare(
        'SELECT f.*, t.Title, t.ValidMonths, t.IsRequired, t.WhereToGetIt
         FROM sh.DriverForms f
         LEFT JOIN ref.DriverFormTypes t ON t.FormCode = f.FormCode
         WHERE f.DriverFormID = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** The fleet office checks it against the paper. */
function driver_review(int $id, string $decision, string $note, array $user): string
{
    if (!in_array($decision, ['accepted', 'rejected'], true)) {
        return 'That is not a decision this records.';
    }
    $f = driver_get($id);
    if (!$f) {
        return 'That form is gone.';
    }
    if ($f['Status'] !== 'submitted') {
        return 'That form was already ' . $f['Status'] . '.';
    }
    if ($decision === 'rejected' && trim($note) === '') {
        return 'Say why, so they know what to file instead.';
    }
    db('fleet')->prepare(
        'UPDATE sh.DriverForms SET Status = ?, ReviewNote = ?, ReviewedByNetID = ?,
             ReviewedAt = SYSDATETIME(), UpdatedAt = SYSDATETIME()
         WHERE DriverFormID = ?')
        ->execute([$decision, trim($note) ?: null, $user['NetID'], $id]);
    driver_notify_reviewed($id, $decision);
    return '';
}

/**
 * Everyone the fleet office needs to look at: unchecked forms, and people
 * whose paperwork is about to lapse.
 */
function driver_waiting(): array
{
    return db('fleet')->query(
        "SELECT f.*, t.Title FROM sh.DriverForms f
         LEFT JOIN ref.DriverFormTypes t ON t.FormCode = f.FormCode
         WHERE f.Status = 'submitted' ORDER BY f.CreatedAt")->fetchAll();
}

/** Accepted paperwork running out, soonest first. */
function driver_lapsing(?int $days = null): array
{
    $days = $days ?? max(0, (int)setting('driver_warn_days', '45'));
    $st = db('fleet')->prepare(
        "SELECT f.*, t.Title FROM sh.DriverForms f
         LEFT JOIN ref.DriverFormTypes t ON t.FormCode = f.FormCode
         WHERE f.Status = 'accepted' AND f.ExpiresOn IS NOT NULL
           AND f.ExpiresOn <= DATEADD(day, " . (int)$days . ", CAST(SYSDATETIME() AS DATE))
         ORDER BY f.ExpiresOn");
    $st->execute();
    return $st->fetchAll();
}

/**
 * Everybody who has ever filed anything, with where they stand. This is
 * the archive: one row per person, not one per form.
 */
function driver_roster(string $q = ''): array
{
    $sql = "SELECT DISTINCT NetID, PersonName, SurveyCode FROM sh.DriverForms";
    $params = [];
    if (trim($q) !== '') {
        $sql .= ' WHERE NetID LIKE ? OR PersonName LIKE ?';
        $params = ['%' . trim($q) . '%', '%' . trim($q) . '%'];
    }
    $sql .= ' ORDER BY PersonName, NetID';
    $st = db('fleet')->prepare($sql);
    $st->execute($params);

    $out = [];
    foreach ($st as $row) {
        $c = driver_clearance((string)$row['NetID']);
        $out[] = [
            'netid'   => (string)$row['NetID'],
            'name'    => (string)$row['PersonName'],
            'survey'  => (string)$row['SurveyCode'],
            'ok'      => $c['ok'],
            'missing' => count($c['missing']),
            'expired' => count($c['expired']),
            'expiring' => count($c['expiring']),
            'note'    => driver_clearance_note($c),
        ];
    }
    return $out;
}

function driver_notify_filed(int $id): void
{
    $f = driver_get($id);
    if (!$f) {
        return;
    }
    $to = notify_audience('driver_form_filed', ['survey' => (string)$f['SurveyCode']]);
    if (!$to) {
        return;
    }
    $nl = "\n";
    notify_send('fleet', 'driver_form_filed', $to,
        'Driver form filed: ' . $f['PersonName'] . ' — ' . $f['Title'],
        $f['PersonName'] . ' (' . $f['NetID'] . ') has filed a ' . $f['Title'] . '.' . $nl . $nl
        . 'Signed: ' . date('M j, Y', strtotime((string)$f['CompletedOn'])) . $nl
        . ($f['ExpiresOn']
            ? 'Runs out: ' . date('M j, Y', strtotime((string)$f['ExpiresOn'])) . $nl : '')
        . $nl . 'It does not clear them to drive until it has been checked:' . $nl
        . notify_base_url() . 'admin.php?section=drivers',
        $id, (string)$f['TicketNumber']);
}

function driver_notify_reviewed(int $id, string $decision): void
{
    $f = driver_get($id);
    if (!$f) {
        return;
    }
    $st = db('fleet')->prepare('SELECT Email, DisplayName FROM app.Users WHERE NetID = ?');
    $st->execute([(string)$f['NetID']]);
    $me = $st->fetch();
    if (!$me || !$me['Email']) {
        return;
    }
    $nl = "\n";
    notify_send('fleet', 'driver_form_reviewed',
        [['email' => (string)$me['Email'], 'name' => (string)$me['DisplayName'],
          'why' => 'this is your driver paperwork']],
        'Your ' . $f['Title'] . ' was ' . $decision,
        'Your ' . $f['Title'] . ' was ' . $decision . '.' . $nl
        . ($f['ReviewNote'] ? $nl . $f['ReviewNote'] . $nl : '')
        . ($decision === 'accepted' && $f['ExpiresOn']
            ? $nl . 'It is good until ' . date('M j, Y', strtotime((string)$f['ExpiresOn']))
              . '.' . $nl : '')
        . $nl . notify_base_url() . 'new_driver.php',
        $id, (string)$f['TicketNumber']);
}
