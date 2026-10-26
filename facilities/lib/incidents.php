<?php
declare(strict_types=1);

/**
 * Incident reports.
 *
 * Two things shape this file.
 *
 * The first is that the report has to be quick. Somebody is filling it
 * in shortly after a bad moment, sometimes on a phone, sometimes for
 * somebody else who has gone to a clinic. So the required set is small -
 * what happened, when, where, and a description - and everything the
 * safety office needs to grade it is added afterwards by the safety
 * office. A form that demands a severity rating from the person who was
 * hurt gets filled in wrongly or not at all.
 *
 * The second is that a near miss must be as easy to report as an injury.
 * The near misses are the useful ones; they are the incidents that have
 * not happened yet. If reporting one is any harder than reporting an
 * injury, nobody bothers, and the system only ever hears about the
 * failures it was too late to prevent.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/tickets.php';
require_once __DIR__ . '/requests2.php';
require_once __DIR__ . '/rooms.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/attachments.php';
require_once __DIR__ . '/picklists.php';

/** Kinds that always want somebody named, because somebody was hurt. */
const INCIDENT_KINDS_WITH_PEOPLE = ['injury', 'exposure'];

function incident_db(): PDO
{
    return db('incidents');
}

/**
 * File a report. Returns ['id' => int, 'ticket' => string] or ['error' => string].
 *
 * $in['people'] is a list of rows from the form; blank rows are dropped
 * rather than rejected, because a form with three name boxes and one
 * person in the room should not be an error.
 */
function incident_file(array $in, array $user): array
{
    $kind = trim((string)($in['kind'] ?? ''));
    $when = trim((string)($in['occurred_at'] ?? ''));
    $what = trim((string)($in['description'] ?? ''));

    if ($kind === '') {
        return ['error' => 'Say what kind of thing happened.'];
    }
    if ($what === '') {
        return ['error' => 'Describe what happened, in your own words.'];
    }
    if ($when === '') {
        return ['error' => 'Say when it happened. An approximate time is fine.'];
    }
    $ts = strtotime($when);
    if ($ts === false) {
        return ['error' => 'That date and time could not be read.'];
    }
    // A report dated in the future is a typo every time, and a wrong date
    // is worse than a missing one because nothing later questions it.
    if ($ts > time() + 3600) {
        return ['error' => 'That is in the future. Check the date.'];
    }

    $offCampus = !empty($in['off_campus']);
    $bldg = trim((string)($in['bldg'] ?? ''));
    $roomId = trim((string)($in['room'] ?? ''));
    $written = trim((string)($in['written_location'] ?? ''));

    if (!$offCampus && $bldg === '' && $written === '') {
        return ['error' => 'Say where it happened - a building and room, or a description '
                         . 'of the place.'];
    }

    $pdo = incident_db();
    $roomCode = ($bldg !== '' && $roomId !== '')
        ? room_resolve('incidents', $bldg, $roomId)
        : null;

    $people = incident_clean_people((array)($in['people'] ?? []));
    if (in_array($kind, INCIDENT_KINDS_WITH_PEOPLE, true) && !$people) {
        return ['error' => 'Name at least one person for this kind of report. If you would '
                         . 'rather not put a name in, choose "Near miss" and describe it.'];
    }

    $ticket = rq_ticket('incident');
    $localUser = ensure_user('incidents', $user);

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            'INSERT INTO rq.Incidents
                (TicketNumber, IncidentKind, OccurredAt, BldgNumber, RoomID, RoomCode,
                 WrittenLocation, IsOffCampus, FleetNumber, SurveyCode,
                 ReportedByNetID, ReportedByName, ReportedByUserID,
                 Description, ImmediateAction, Status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'reported\')');
        $st->execute([
            $ticket, $kind, date('Y-m-d H:i:s', $ts),
            $bldg !== '' ? $bldg : null,
            $roomId !== '' ? $roomId : null,
            $roomCode,
            $written !== '' ? $written : null,
            $offCampus ? 1 : 0,
            trim((string)($in['fleet_number'] ?? '')) !== ''
                ? trim((string)$in['fleet_number']) : null,
            trim((string)($in['survey'] ?? '')) !== '' ? trim((string)$in['survey']) : null,
            (string)$user['NetID'], (string)($user['DisplayName'] ?? ''), $localUser,
            $what,
            trim((string)($in['immediate_action'] ?? '')) !== ''
                ? trim((string)$in['immediate_action']) : null,
        ]);
        $id = (int)$pdo->lastInsertId();

        $ins = $pdo->prepare(
            'INSERT INTO rq.IncidentPeople
                (IncidentID, PersonName, NetID, Involvement, IsPriStaff,
                 InjuryDescription, BodyPart, TreatmentLevel, TreatedAt)
             VALUES (?,?,?,?,?,?,?,?,?)');
        foreach ($people as $p) {
            $ins->execute([
                $id, $p['name'], $p['netid'] ?: null, $p['involvement'], $p['staff'] ? 1 : 0,
                $p['injury'] ?: null, $p['body_part'] ?: null,
                $p['treatment'] ?: null, $p['treated_at'] ?: null,
            ]);
        }

        incident_event($pdo, $id, 'reported',
            'Reported by ' . ($user['DisplayName'] ?: $user['NetID']), $user);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['error' => 'The report could not be saved: ' . $e->getMessage()];
    }

    record_status('incidents', $id, null, 'reported', $localUser, 'Reported');

    if (!empty($_FILES['files']['name'][0])) {
        attach_store_many('incidents', $id, 'files', 'incident', $user, 'incident');
    }

    // The safety office hears about every one of these the moment it is
    // filed, injury or not. Grading it is their job; being told is not
    // conditional on how bad the reporter thought it was.
    notify_send('incidents', 'incident_reported',
        notify_audience('incident_reported', ['survey' => (string)($in['survey'] ?? '')]),
        'Incident reported: ' . $ticket,
        incident_notice_body($ticket, $kind, $ts, $roomCode ?: $written, $what,
                             (string)($user['DisplayName'] ?: $user['NetID'])),
        $id, $ticket);

    return ['id' => $id, 'ticket' => $ticket];
}

/** Form rows in, usable rows out. A row with no name is not a person. */
function incident_clean_people(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        $name = trim((string)($r['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $out[] = [
            'name'        => $name,
            'netid'       => trim((string)($r['netid'] ?? '')),
            'involvement' => trim((string)($r['involvement'] ?? '')) ?: 'involved',
            'staff'       => !empty($r['staff']),
            'injury'      => trim((string)($r['injury'] ?? '')),
            'body_part'   => trim((string)($r['body_part'] ?? '')),
            'treatment'   => trim((string)($r['treatment'] ?? '')),
            'treated_at'  => trim((string)($r['treated_at'] ?? '')),
        ];
    }
    return $out;
}

function incident_event(PDO $pdo, int $id, string $code, string $detail, array $user): void
{
    $pdo->prepare('INSERT INTO rq.IncidentEvents (IncidentID, EventCode, Detail, ByNetID, ByName)
                   VALUES (?,?,?,?,?)')
        ->execute([$id, $code, $detail, (string)$user['NetID'], (string)($user['DisplayName'] ?? '')]);
}

/** One report with everything hanging off it. */
function incident_get(int $id): ?array
{
    $pdo = incident_db();
    $st = $pdo->prepare('SELECT * FROM rq.Incidents WHERE IncidentID = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    foreach ([
        'people'  => 'SELECT * FROM rq.IncidentPeople WHERE IncidentID = ? ORDER BY IncidentPersonID',
        'actions' => 'SELECT * FROM rq.IncidentActions WHERE IncidentID = ?
                       ORDER BY CASE WHEN DoneOn IS NULL THEN 0 ELSE 1 END, DueOn, IncidentActionID',
        'events'  => 'SELECT * FROM rq.IncidentEvents WHERE IncidentID = ? ORDER BY IncidentEventID',
    ] as $key => $sql) {
        $s = $pdo->prepare($sql);
        $s->execute([$id]);
        $row[$key] = $s->fetchAll();
    }
    $row['attachments'] = attach_list('incidents', $id, 'incident');
    return $row;
}

/**
 * The list, filtered.
 *
 * $filter['mine'] limits it to reports the given NetID filed, which is
 * what the front end shows: your own reports, and nobody else's, because
 * an incident can name a person and describe an injury.
 */
function incident_list(array $filter = [], int $limit = 200): array
{
    $where = [];
    $args = [];
    if (!empty($filter['mine'])) {
        $where[] = 'ReportedByNetID = ?';
        $args[] = (string)$filter['mine'];
    }
    if (!empty($filter['status'])) {
        $where[] = 'Status = ?';
        $args[] = (string)$filter['status'];
    }
    if (!empty($filter['kind'])) {
        $where[] = 'IncidentKind = ?';
        $args[] = (string)$filter['kind'];
    }
    if (!empty($filter['open'])) {
        $where[] = "Status <> 'closed'";
    }
    if (!empty($filter['room'])) {
        $where[] = 'RoomCode = ?';
        $args[] = (string)$filter['room'];
    }
    // The driver types a bound TOP as nvarchar, so it is clamped and
    // written in rather than bound.
    $n = max(1, min(2000, $limit));
    $sql = "SELECT TOP ($n) i.*,
                   (SELECT COUNT(*) FROM rq.IncidentPeople p
                     WHERE p.IncidentID = i.IncidentID AND p.InjuryDescription IS NOT NULL) AS Hurt,
                   (SELECT COUNT(*) FROM rq.IncidentActions a
                     WHERE a.IncidentID = i.IncidentID AND a.DoneOn IS NULL) AS ActionsOpen
              FROM rq.Incidents i"
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY i.OccurredAt DESC';
    $st = incident_db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/** The safety office's grading, added after the fact. */
function incident_grade(int $id, string $severity, bool $reportable, string $reportedTo,
                        ?string $reportedOn, array $user): string
{
    $pdo = incident_db();
    $st = $pdo->prepare('UPDATE rq.Incidents
                            SET Severity = ?, IsReportable = ?, ReportedTo = ?,
                                ReportedOutwardOn = ?, UpdatedAt = SYSDATETIME()
                          WHERE IncidentID = ?');
    $st->execute([
        $severity !== '' ? $severity : null,
        $reportable ? 1 : 0,
        $reportedTo !== '' ? $reportedTo : null,
        $reportedOn !== null && $reportedOn !== '' ? $reportedOn : null,
        $id,
    ]);
    incident_event($pdo, $id, 'graded',
        'Severity ' . ($severity ?: 'not set') . ($reportable ? ', reportable' : ''), $user);
    return 'Recorded.';
}

function incident_add_action(int $id, array $in, array $user): string
{
    $what = trim((string)($in['action'] ?? ''));
    if ($what === '') {
        return 'Say what is being done.';
    }
    $pdo = incident_db();
    $pdo->prepare('INSERT INTO rq.IncidentActions
                      (IncidentID, Action, OwnerName, OwnerNetID, DueOn, WorkTicketNumber)
                   VALUES (?,?,?,?,?,?)')
        ->execute([
            $id, $what,
            trim((string)($in['owner'] ?? '')) ?: null,
            trim((string)($in['owner_netid'] ?? '')) ?: null,
            trim((string)($in['due'] ?? '')) ?: null,
            trim((string)($in['work_ticket'] ?? '')) ?: null,
        ]);
    // An incident with an open action is not merely reported any more.
    $pdo->prepare("UPDATE rq.Incidents SET Status = 'actions_open', UpdatedAt = SYSDATETIME()
                    WHERE IncidentID = ? AND Status IN ('reported', 'investigating')")
        ->execute([$id]);
    incident_event($pdo, $id, 'action_added', $what, $user);
    return 'Added.';
}

function incident_close_action(int $actionId, string $note, array $user): string
{
    $pdo = incident_db();
    $st = $pdo->prepare('SELECT IncidentID, Action FROM rq.IncidentActions WHERE IncidentActionID = ?');
    $st->execute([$actionId]);
    $row = $st->fetch();
    if (!$row) {
        return 'No such action.';
    }
    $pdo->prepare('UPDATE rq.IncidentActions SET DoneOn = CAST(SYSDATETIME() AS DATE), DoneNote = ?
                    WHERE IncidentActionID = ?')
        ->execute([$note !== '' ? $note : null, $actionId]);
    incident_event($pdo, (int)$row['IncidentID'], 'action_done', (string)$row['Action'], $user);
    return 'Marked done.';
}

/**
 * Close it. Refused while an action is still open, because a report
 * closed over the top of its own unfinished corrective action is the
 * failure mode this whole record exists to avoid.
 */
function incident_close(int $id, string $findings, array $user): string
{
    if (!may_decide_incidents($user)) {
        return 'You may read this report but not close it.';
    }
    $pdo = incident_db();
    $open = (int)$pdo->query("SELECT COUNT(*) FROM rq.IncidentActions
                               WHERE IncidentID = $id AND DoneOn IS NULL")->fetchColumn();
    if ($open > 0) {
        return $open === 1
            ? 'One corrective action is still open. Close it first, or remove it.'
            : "$open corrective actions are still open. Close them first, or remove them.";
    }
    if (trim($findings) === '') {
        return 'Write what was concluded before closing it.';
    }
    $st = $pdo->prepare("UPDATE rq.Incidents
                            SET Status = 'closed', ClosedAt = SYSDATETIME(), ClosedByNetID = ?,
                                Findings = ?, UpdatedAt = SYSDATETIME()
                          WHERE IncidentID = ?");
    $st->execute([(string)$user['NetID'], trim($findings), $id]);
    incident_event($pdo, $id, 'closed', trim($findings), $user);
    record_status('incidents', $id, null, 'closed', ensure_user('incidents', $user), 'Closed');
    return 'Closed.';
}

/** Counts for the back end card, so the number is right before you click. */
function incident_counts(): array
{
    $pdo = incident_db();
    return [
        'open'    => (int)$pdo->query("SELECT COUNT(*) FROM rq.Incidents WHERE Status <> 'closed'")
                              ->fetchColumn(),
        'actions' => (int)$pdo->query('SELECT COUNT(*) FROM rq.IncidentActions WHERE DoneOn IS NULL')
                              ->fetchColumn(),
        'year'    => (int)$pdo->query('SELECT COUNT(*) FROM rq.Incidents
                                        WHERE OccurredAt >= DATEADD(year, -1, SYSDATETIME())')
                              ->fetchColumn(),
    ];
}

function incident_notice_body(string $ticket, string $kind, int $ts, string $where,
                              string $what, string $by): string
{
    return $ticket . ' - ' . picklist_label('incident_kind', $kind) . "\n"
         . 'When:     ' . ui_datetime($ts) . "\n"
         . 'Where:    ' . ($where !== '' ? $where : 'not recorded') . "\n"
         . 'Reported: ' . $by . "\n\n"
         . $what . "\n";
}
