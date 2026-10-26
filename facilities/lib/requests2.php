<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/tickets.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/rooms.php';
require_once __DIR__ . '/adminforms.php';

/**
 * Space requests and key requests: filed on the front end by anybody,
 * decided on the back end by the survey's leadership, and then written
 * through to the thing they change — a room assignment, or the key
 * register.
 */

const SPACE_KINDS = [
    'additional'  => 'Additional space',
    'change_use'  => 'Change how a room is used',
    'relocate'    => 'Relocate to different space',
    'release'     => 'Release space we no longer need',
    'shared'      => 'Share space with another unit',
];

/**
 * The kinds of space the institute occupies. The INHS form asks about an
 * annex greenhouse because INHS has one; the other four surveys hold core
 * libraries, curation space, instrument rooms and vehicle bays, and had
 * nowhere to put them. Growing space is one entry so a greenhouse and a
 * growth chamber sit together. They live here rather than on the form
 * because the request view and the committee's queue name them too.
 */
const SPACE_TYPES = [
    'office'      => 'Office or workstation',
    'lab'         => 'Laboratory: wet, analytical, or instrument bench',
    'collections' => 'Collections, curation, or core and specimen storage',
    'storage'     => 'General storage, including large equipment',
    'instrument'  => 'Dedicated instrument or equipment room',
    'processing'  => 'Field, sample, or artifact processing space',
    'shop'        => 'Shop, vehicle bay, or fabrication space',
    'growing'     => 'Greenhouse, growth chamber, or outdoor research plot',
    'meeting'     => 'Meeting, conference, or teaching space',
    'other'       => 'Other',
    // Requests filed before the list was widened to the institute.
    'greenhouse'  => 'Annex greenhouse',
];

/** Still named on old requests, no longer offered on the form. */
const SPACE_TYPES_RETIRED = ['greenhouse'];

/**
 * Table, primary key, ticket prefix, and the database it lives in.
 *
 * The fourth entry is what makes this layer work now that key requests
 * and purchases have databases of their own. A request's approval chain
 * lives in the same database as the request, so nothing here has to join
 * across a boundary.
 */
const RQ_TABLES = [
    'space' => ['rq.SpaceRequests', 'SpaceRequestID', 'ticket_prefix_sp', 'space'],
    'key'   => ['rq.KeyRequests',   'KeyRequestID',   'ticket_prefix_ky', 'keys'],
    // Purchase requests are approved as a grid rather than a queue, so
    // they open no chain here; lib/purchase.php runs their approvals.
    'purchase' => ['rq.PurchaseRequests', 'PurchaseRequestID', 'ticket_prefix_pur', 'purchasing'],
    // A property update is applied by the property office rather than
    // approved by a chain, so it opens no stages either.
    'property' => ['rq.PropertyUpdates', 'PropertyUpdateID', 'ticket_prefix_prp', 'equipment'],
    // An incident is investigated and closed, not approved, and a plan is
    // reviewed on a date rather than at a stage. Both are here for their
    // ticket number and for the connection they live on.
    'incident' => ['rq.Incidents',   'IncidentID',   'ticket_prefix_inc', 'incidents'],
    'plan'     => ['rq.SafetyPlans', 'SafetyPlanID', 'ticket_prefix_pln', 'plans'],
];

/** The connection holding one kind of request, and its approval chain. */
function rq_db(string $type): PDO
{
    return db(RQ_TABLES[$type][3] ?? 'work');
}

const RQ_STAGES = [
    'space' => [['committee', 'Survey space committee', 1], ['director', 'Survey director', 2]],
    'key'   => [['director', 'Survey director or proxy', 1]],
    'purchase' => [],
    'property' => [],
    'incident' => [],
    'plan'     => [],
];

function rq_ticket(string $type): string
{
    $prefix = (string)setting(RQ_TABLES[$type][2]);
    $fy = fiscal_year();
    $pdo = db('work');
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT CounterYear, CurrentValue FROM app.Counters
                               WITH (UPDLOCK, HOLDLOCK) WHERE CounterName = ?');
        $stmt->execute([$prefix]);
        $row = $stmt->fetch();
        if ($row === false) {
            $pdo->prepare('INSERT INTO app.Counters (CounterName, CounterYear, CurrentValue)
                           VALUES (?, ?, 0)')->execute([$prefix, $fy]);
            $row = ['CounterYear' => $fy, 'CurrentValue' => 0];
        }
        if ((int)$row['CounterYear'] !== $fy) {
            $pdo->prepare('UPDATE app.Counters SET CounterYear = ?, CurrentValue = 0
                           WHERE CounterName = ?')->execute([$fy, $prefix]);
            $row['CurrentValue'] = 0;
        }
        $next = (int)$row['CurrentValue'] + 1;
        $pdo->prepare('UPDATE app.Counters SET CurrentValue = ? WHERE CounterName = ?')
            ->execute([$next, $prefix]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return sprintf('%s-FY%02d-%05d', $prefix, $fy % 100, $next);
}

function rq_open_chain(string $type, int $id, string $survey): void
{
    $pdo = rq_db($type);
    $ins = $pdo->prepare('INSERT INTO rq.Approvals (RequestType, RequestID, StageCode, StageOrder,
                          ApproverName) VALUES (?, ?, ?, ?, ?)');
    foreach (RQ_STAGES[$type] as [$code, $label, $order]) {
        $named = null;
        if ($code === 'director' && $survey !== '') {
            $s = $pdo->prepare('SELECT DirectorName FROM ref.Surveys WHERE Code = ?');
            $s->execute([$survey]);
            $named = $s->fetchColumn() ?: null;
        }
        $ins->execute([$type, $id, $code, $order, $named]);
    }
}

function rq_get(string $type, int $id): ?array
{
    [$table, $pk] = RQ_TABLES[$type];
    $stmt = rq_db($type)->prepare("SELECT * FROM $table WHERE $pk = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function rq_chain(string $type, int $id): array
{
    $stmt = rq_db($type)->prepare('SELECT * FROM rq.Approvals WHERE RequestType = ? AND RequestID = ?
                                 ORDER BY StageOrder');
    $stmt->execute([$type, $id]);
    return $stmt->fetchAll();
}

function rq_current_stage(string $type, int $id): ?array
{
    $stmt = rq_db($type)->prepare('SELECT TOP 1 * FROM rq.Approvals
                                 WHERE RequestType = ? AND RequestID = ? AND Decision IS NULL
                                   AND IsRequired = 1 ORDER BY StageOrder');
    $stmt->execute([$type, $id]);
    return $stmt->fetch() ?: null;
}

/** May the signed-in person decide the stage in front of them? */
function rq_may_decide(string $type, array $req, ?array $stage): bool
{
    if (!$stage) {
        return false;
    }
    $survey = (string)($req['SurveyCode'] ?? '');
    return $stage['StageCode'] === 'director'
        ? may_decide_director($survey)
        : may_decide_committee($survey);
}

function rq_set_status(string $type, int $id, string $status, string $note, array $user): void
{
    [$table, $pk] = RQ_TABLES[$type];
    $pdo = rq_db($type);
    $cur = $pdo->prepare("SELECT Status, TicketNumber, RequestedByNetID FROM $table WHERE $pk = ?");
    $cur->execute([$id]);
    $row = $cur->fetch();
    $terminal = in_array($status, ['closed', 'denied'], true);
    $pdo->prepare("UPDATE $table SET Status = ?, UpdatedAt = SYSDATETIME()"
        . ($terminal ? ', ClosedAt = SYSDATETIME()' : '') . " WHERE $pk = ?")
        ->execute([$status, $id]);

    rq_notify_submitter($type, $id, (string)$row['TicketNumber'],
        (string)$row['RequestedByNetID'], (string)$row['Status'], $status, $note);
}

/**
 * The person who filed a request hears about every change to it. This is
 * the promise the old forms never kept.
 */
function rq_notify_submitter(string $type, int $id, string $ticket, string $netid,
                             string $from, string $to, string $note): void
{
    $email = rq_email_for($netid);
    if ($email === null || $from === $to) {
        return;
    }
    $labels = status_labels($type);
    // A stage can be marked as one the submitter need not hear about.
    if (isset($labels[$to]) && !$labels[$to]['NotifySubmitter']) {
        return;
    }
    // Work, shop and move requests are read on the detail page.
    $page = in_array($type, ['space', 'key'], true)
        ? 'request.php?type=' . $type . '&id=' . $id
        : 'detail.php?type=' . $type . '&id=' . $id;
    $subject = 'PRIOps: ' . $ticket . ' is now ' . ($labels[$to]['Label'] ?? $to);
    $body = 'Your request ' . $ticket . " has moved on.\n\n"
          . 'From: ' . ($labels[$from]['Label'] ?? $from) . "\n"
          . 'To: '   . ($labels[$to]['Label'] ?? $to) . "\n"
          . ($note !== '' ? "\n" . $note . "\n" : '')
          . "\n" . notify_base_url() . $page;
    $dbName = in_array($type, ['space', 'key'], true) ? 'work' : $type;
    notify_send($dbName, 'status_changed', [$email], $subject, $body, $id, $ticket);
}

function rq_email_for(string $netid): ?string
{
    $stmt = db('work')->prepare('SELECT Email FROM app.Users WHERE NetID = ?');
    $stmt->execute([$netid]);
    $email = $stmt->fetchColumn();
    return ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) ? (string)$email : null;
}

/** The configured stages for a request type, keyed by status code. */
function status_labels(string $type): array
{
    static $cache = [];
    if (isset($cache[$type])) {
        return $cache[$type];
    }
    try {
        $stmt = db('work')->prepare('SELECT * FROM ref.StatusFlow WHERE RequestType = ? AND IsActive = 1
                                     ORDER BY StageOrder');
        $stmt->execute([$type]);
        $out = [];
        foreach ($stmt as $row) {
            $out[(string)$row['StatusCode']] = $row;
        }
        return $cache[$type] = $out;
    } catch (Throwable $e) {
        return $cache[$type] = [];
    }
}

/**
 * Record a decision on a space or key request, and carry out what the
 * decision means.
 */
function rq_decide(string $type, int $id, int $approvalId, string $decision,
                   string $comment, array $user): string
{
    if ($decision !== 'approved' && trim($comment) === '') {
        return 'A comment is required when denying or returning a request.';
    }
    // The chain is in the same database as the request it belongs to.
    $pdo = rq_db($type);
    $req = rq_get($type, $id);
    if (!$req) {
        return 'That request no longer exists.';
    }
    $stage = rq_current_stage($type, $id);
    if (!$stage || (int)$stage['ApprovalID'] !== $approvalId) {
        return 'That stage is not the one awaiting a decision.';
    }
    if (!rq_may_decide($type, $req, $stage)) {
        return 'Your access does not cover that decision.';
    }

    $pdo->prepare('UPDATE rq.Approvals SET Decision = ?, DecisionAt = SYSDATETIME(), Comment = ?,
                   ApproverUserID = ?, ApproverName = COALESCE(ApproverName, ?)
                   WHERE ApprovalID = ?')
        ->execute([$decision, trim($comment) ?: null,
                   ensure_user(RQ_TABLES[$type][3] ?? 'work', $user),
                   $user['DisplayName'], $approvalId]);

    $ticket = (string)$req['TicketNumber'];
    if ($decision === 'denied') {
        rq_set_status($type, $id, 'denied', trim($comment), $user);
        return $ticket . ' denied. The person who filed it has been told.';
    }
    if ($decision === 'returned') {
        rq_set_status($type, $id, 'submitted', 'Returned: ' . trim($comment), $user);
        return $ticket . ' returned to the submitter with your comment.';
    }

    $next = rq_current_stage($type, $id);
    if ($next) {
        rq_set_status($type, $id,
            $next['StageCode'] === 'director' ? ($type === 'space' ? 'director_review' : 'director_review')
                                              : 'committee_review',
            'Approved at ' . $stage['StageCode'], $user);
        return $ticket . ' approved. Now with the ' . $next['StageCode'] . '.';
    }

    rq_set_status($type, $id, 'approved', 'Approved', $user);
    return $ticket . ' approved. '
        . ($type === 'space'
            ? 'Assign the room on this screen to finish it.'
            : 'Issue the key on this screen to finish it.');
}

/**
 * A granted space request writes through to the room assignment, which
 * is the point of asking in the first place.
 */
function rq_space_assign(int $id, string $roomCode, array $in, array $user): string
{
    $req = rq_get('space', $id);
    if (!$req) {
        return 'That request no longer exists.';
    }
    if ($roomCode === '') {
        return 'Choose the room being assigned.';
    }
    room_assign($roomCode, [
        'survey'     => $req['SurveyCode'],
        'pi_name'    => $in['pi_name'] ?? null,
        'notes'      => 'Assigned by ' . $req['TicketNumber']
                      . (trim((string)($in['notes'] ?? '')) !== '' ? ': ' . $in['notes'] : ''),
        'use_override' => $req['SpaceUse'],
    ], $user['NetID']);

    if (!empty($in['assign_occupant'])) {
        room_occupant_add($roomCode, [
            'person_name'   => $req['RequestedByNetID'],
            'person_netid'  => $req['RequestedByNetID'],
            'org'           => $req['SurveyCode'],
            'primary'       => 1,
        ], $user['NetID']);
    }

    db('space')->prepare('UPDATE rq.SpaceRequests SET AssignedRoomCode = ? WHERE SpaceRequestID = ?')
        ->execute([$roomCode, $id]);
    rq_set_status('space', $id, 'assigned', 'Room ' . $roomCode . ' assigned', $user);
    return 'Room ' . $roomCode . ' assigned against ' . $req['TicketNumber'] . '.';
}

/** A granted key request writes through to the key register. */
function rq_key_issue(int $id, array $in, array $user): string
{
    $req = rq_get('key', $id);
    if (!$req) {
        return 'That request no longer exists.';
    }
    $msg = keys_issue([
        'holder_name'  => $req['HolderName'],
        'holder_netid' => $req['HolderNetID'],
        'emp_status'   => $req['EmpStatus'],
        'survey'       => $req['SurveyCode'],
        'bldg'         => $req['BldgNumber'],
        'floor'        => $req['FloorCode'],
        'room'         => $req['RoomID'],
        'key_code'     => trim((string)($in['key_code'] ?? '')),
        'key_type'     => $req['KeyType'] ?: 'Key',
        'qty'          => $req['Quantity'],
        'notes'        => 'Issued against ' . $req['TicketNumber'],
    ], $user['NetID']);

    $newId = (int)db('keys')->query('SELECT MAX(RoomKeyID) FROM gis.RoomKeys')->fetchColumn();
    db('keys')->prepare('UPDATE rq.KeyRequests SET IssuedRoomKeyID = ? WHERE KeyRequestID = ?')
        ->execute([$newId, $id]);
    rq_set_status('key', $id, 'issued', $msg, $user);
    return $msg;
}
