<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/dashboard.php';
require_once __DIR__ . '/access.php';

/**
 * The standing summary. Somebody who holds up a queue is told about it on
 * a rhythm they choose, and the message points at a page of their own
 * that shows the current state rather than the state when it was sent —
 * so it empties itself as they work through it.
 */

function digest_prefs(string $netid): array
{
    $pdo = db('work');
    $stmt = $pdo->prepare('SELECT * FROM app.DigestPreferences WHERE NetID = ?');
    $stmt->execute([$netid]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }
    $pdo->prepare('INSERT INTO app.DigestPreferences (NetID, Frequency, Token) VALUES (?, ?, ?)')
        ->execute([$netid, (string)setting('digest_default_frequency', 'weekly'),
                   bin2hex(random_bytes(24))]);
    $stmt->execute([$netid]);
    return $stmt->fetch();
}

function digest_save_prefs(string $netid, array $in): string
{
    digest_prefs($netid);   // make sure a row and a token exist
    $freq = in_array($in['frequency'] ?? '', ['off', 'daily', 'weekly'], true)
        ? $in['frequency'] : 'weekly';
    db('work')->prepare(
        'UPDATE app.DigestPreferences SET Frequency = ?, SendDay = ?, SendHour = ?, IncludeMine = ?
         WHERE NetID = ?'
    )->execute([$freq, max(0, min(6, (int)($in['send_day'] ?? 1))),
                max(0, min(23, (int)($in['send_hour'] ?? 7))),
                !empty($in['include_mine']) ? 1 : 0, $netid]);

    return $freq === 'off'
        ? 'Digest turned off. You will still be emailed about your own requests as they move.'
        : 'Digest set to ' . $freq . '.';
}

/** A person by NetID, in the shape the dashboard functions expect. */
function digest_user(string $netid): ?array
{
    $stmt = db('work')->prepare('SELECT * FROM app.Users WHERE NetID = ? AND IsActive = 1');
    $stmt->execute([$netid]);
    return $stmt->fetch() ?: null;
}

/**
 * Everything one person could act on: decisions waiting on them, work
 * their function is expected to move along, and their own open requests.
 */
function digest_items(array $user, bool $includeMine = true): array
{
    $out = ['decisions' => dash_waiting_on_me($user), 'queue' => [], 'mine' => []];

    // Work the person's function owns. Facilities and safety staff are
    // told about tickets sitting in a stage nobody has moved.
    if (can_admin($user)) {
        $surveys = access_surveys($user);
        try {
            $rows = db('work')->query(
                "SELECT w.WorkRequestID, w.TicketNumber, w.Title, w.Status, w.SurveyCode,
                        w.UpdatedAt, b.Name AS BuildingName
                 FROM wr.WorkRequests w JOIN ref.Buildings b ON b.BuildingID = w.BuildingID
                 WHERE w.Status IN ('open','triaged','scheduled','in_progress','on_hold','work_done')")
                ->fetchAll();
            foreach ($rows as $r) {
                if ($surveys && $r['SurveyCode'] && !in_array(strtoupper((string)$r['SurveyCode']), $surveys, true)) {
                    continue;
                }
                $out['queue'][] = [
                    'kind' => 'Work request', 'ticket' => (string)$r['TicketNumber'],
                    'summary' => mb_strimwidth((string)$r['Title'], 0, 70, '…'),
                    'status' => (string)$r['Status'], 'since' => (string)$r['UpdatedAt'],
                    'url' => 'detail.php?type=work&id=' . (int)$r['WorkRequestID'], 'type' => 'work',
                ];
            }
        } catch (Throwable $e) { /* unavailable */ }

        foreach ([['shop', 'sh.ShopRequests', 'ShopRequestID', 'WorkDescription',
                   "('approved','in_shop')", 'Shop request'],
                  ['move', 'mv.MoveRequests', 'MoveRequestID', 'AdditionalNotes',
                   "('open','it_review','inventory_review','facilities_review','scheduled')", 'Move request']]
                 as [$dbName, $table, $pk, $summary, $states, $label]) {
            try {
                foreach (db($dbName)->query(
                    "SELECT $pk AS ID, TicketNumber, $summary AS Summary, Status, SurveyCode, UpdatedAt
                     FROM $table WHERE Status IN $states") as $r) {
                    if ($surveys && $r['SurveyCode'] && !in_array(strtoupper((string)$r['SurveyCode']), $surveys, true)) {
                        continue;
                    }
                    $out['queue'][] = [
                        'kind' => $label, 'ticket' => (string)$r['TicketNumber'],
                        'summary' => mb_strimwidth((string)$r['Summary'], 0, 70, '…'),
                        'status' => (string)$r['Status'], 'since' => (string)$r['UpdatedAt'],
                        'url' => 'detail.php?type=' . $dbName . '&id=' . (int)$r['ID'], 'type' => $dbName,
                    ];
                }
            } catch (Throwable $e) { /* unavailable */ }
        }
        usort($out['queue'], fn($a, $b) => strcmp((string)$a['since'], (string)$b['since']));
    }

    if ($includeMine) {
        $out['mine'] = dash_my_requests($user);
    }
    return $out;
}

function digest_count(array $items): int
{
    return count($items['decisions']) + count($items['queue']) + count($items['mine']);
}

/** Is a digest due for this person now? */
function digest_due(array $pref, ?int $now = null): bool
{
    $now = $now ?? time();
    if ($pref['Frequency'] === 'off') {
        return false;
    }
    $last = $pref['LastSentAt'] ? strtotime((string)$pref['LastSentAt']) : 0;
    $hour = (int)$pref['SendHour'];

    if ($pref['Frequency'] === 'daily') {
        // Due once the send hour has passed and nothing went today.
        return (int)date('G', $now) >= $hour && date('Y-m-d', $last) !== date('Y-m-d', $now);
    }
    // Weekly: on the chosen day, after the hour, and not already this week.
    if ((int)date('w', $now) !== (int)$pref['SendDay'] || (int)date('G', $now) < $hour) {
        return false;
    }
    return $last < strtotime('today midnight', $now);
}

/** The message. Plain text, because it is read on a phone in a corridor. */
function digest_render(array $user, array $items, string $token): string
{
    $lines = ['Outstanding work in the PRI Facilities Request System', ''];
    $base = notify_base_url();

    if ($items['decisions']) {
        $lines[] = 'WAITING ON YOUR DECISION (' . count($items['decisions']) . ')';
        foreach ($items['decisions'] as $d) {
            $days = dash_days_since($d['since']);
            $lines[] = '  ' . $d['ticket'] . '  ' . $d['kind'] . ' at ' . $d['stage']
                     . ' — ' . $d['summary'] . '  (' . $days . ' day' . ($days === 1 ? '' : 's') . ')';
        }
        $lines[] = '';
    }
    if ($items['queue']) {
        $lines[] = 'IN YOUR QUEUE (' . count($items['queue']) . ')';
        foreach (array_slice($items['queue'], 0, 25) as $q) {
            $lines[] = '  ' . $q['ticket'] . '  ' . str_replace('_', ' ', $q['status'])
                     . ' — ' . $q['summary'];
        }
        if (count($items['queue']) > 25) {
            $lines[] = '  ... and ' . (count($items['queue']) - 25) . ' more';
        }
        $lines[] = '';
    }
    if ($items['mine']) {
        $lines[] = 'YOUR OWN REQUESTS STILL OPEN (' . count($items['mine']) . ')';
        foreach ($items['mine'] as $m) {
            $lines[] = '  ' . $m['ticket'] . '  ' . str_replace('_', ' ', $m['status'])
                     . ' — ' . $m['summary'];
        }
        $lines[] = '';
    }

    // The email is written from the same three lists My work shows, and
    // links straight at the tab that has something on it.
    $tab = $items['decisions'] ? 'decisions' : ($items['queue'] ? 'queue' : 'mine');
    $lines[] = 'My work, always current:';
    $lines[] = $base . 'digest.php?t=' . $token . '&tab=' . $tab;
    $lines[] = '';
    $lines[] = 'It empties itself as you work through it, so there is nothing to tick off.';
    $lines[] = 'Change how often this arrives: ' . $base . 'preferences.php';
    return implode("\n", $lines);
}

/**
 * Send to everyone due. Returns a report. Bounded, because this may be
 * running inside somebody's page view on a host with no scheduler.
 */
function digest_run(int $limit = 25, bool $force = false, ?string $onlyNetID = null): array
{
    $pdo = db('work');
    $sent = [];
    $skipped = 0;

    $sql = 'SELECT p.*, u.DisplayName, u.Email, u.Role, u.SurveyCode
            FROM app.DigestPreferences p JOIN app.Users u ON u.NetID = p.NetID
            WHERE u.IsActive = 1' . ($onlyNetID !== null ? ' AND p.NetID = ?' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute($onlyNetID !== null ? [$onlyNetID] : []);

    foreach ($stmt->fetchAll() as $pref) {
        if (count($sent) >= $limit) {
            break;
        }
        if (!$force && !digest_due($pref)) {
            $skipped++;
            continue;
        }
        if (!filter_var((string)$pref['Email'], FILTER_VALIDATE_EMAIL)) {
            $skipped++;
            continue;
        }
        $user = digest_user((string)$pref['NetID']);
        if (!$user) {
            $skipped++;
            continue;
        }
        $items = digest_items($user, (bool)$pref['IncludeMine']);
        $count = digest_count($items);
        if ($count === 0) {
            // Nothing to say. Silence is the correct message, but the
            // clock still moves on so the next one is not immediate.
            $pdo->prepare('UPDATE app.DigestPreferences SET LastSentAt = SYSDATETIME(),
                           LastItemCount = 0 WHERE NetID = ?')->execute([$pref['NetID']]);
            $skipped++;
            continue;
        }

        $subject = 'PRI facilities: ' . count($items['decisions']) . ' waiting on you, '
                 . $count . ' item(s) in total';
        notify_send('work', 'digest', [(string)$pref['Email']], $subject,
            digest_render($user, $items, (string)$pref['Token']), null, null);

        $pdo->prepare('UPDATE app.DigestPreferences SET LastSentAt = SYSDATETIME(),
                       LastItemCount = ? WHERE NetID = ?')->execute([$count, $pref['NetID']]);
        $sent[] = ['netid' => (string)$pref['NetID'], 'items' => $count];
    }

    foreach (APP_DB_NAMES as $n) {
        try {
            db($n)->prepare('UPDATE app.Settings SET SettingValue = ? WHERE SettingKey = ?')
                  ->execute([date('c'), 'digest_last_run']);
        } catch (Throwable $e) { /* ignore */ }
    }
    return ['sent' => $sent, 'skipped' => $skipped];
}

/**
 * Shared hosting offers no scheduler, so a page view carries the work
 * occasionally: at most once an hour, a handful at a time, and only when
 * the administrator has left it switched on.
 */
function digest_maybe_run(): void
{
    try {
        if ((int)setting('digest_auto_run', 1) !== 1) {
            return;
        }
        $last = (string)setting('digest_last_run', '');
        if ($last !== '' && (time() - strtotime($last)) < 3600) {
            return;
        }
        digest_run(5);
    } catch (Throwable $e) {
        // A digest must never break the page somebody actually asked for.
    }
}

function digest_by_token(string $token): ?array
{
    if (strlen($token) < 20) {
        return null;
    }
    $stmt = db('work')->prepare(
        'SELECT p.*, u.DisplayName, u.Email, u.Role, u.SurveyCode, u.UserID
         FROM app.DigestPreferences p JOIN app.Users u ON u.NetID = p.NetID
         WHERE p.Token = ? AND u.IsActive = 1');
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}
