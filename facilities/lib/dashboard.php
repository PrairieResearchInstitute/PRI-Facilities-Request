<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/approvals.php';
require_once __DIR__ . '/requests2.php';

/**
 * What is waiting on the person signed in, and what they have asked for.
 * The point is that somebody who holds up a queue can see that they are
 * holding it up without going looking.
 */

function dash_waiting_on_me(array $user): array
{
    $out = [];

    // Space and key requests, decided by the survey's leadership.
    /* Space requests and key requests are in different databases now, and
       each one's approval chain sits beside it. So this asks each database
       the same question and puts the answers together, rather than joining
       across a boundary. */
    $waiting = [];
    foreach ([['space', 'space', 'rq.SpaceRequests', 'SpaceRequestID', 'LEFT(r.Justification, 90)'],
              ['key', 'keys', 'rq.KeyRequests', 'KeyRequestID', 'r.HolderName']] as
             [$type, $dbName, $table, $pk, $summary]) {
        try {
            $q = db($dbName)->prepare(
                "SELECT a.ApprovalID, a.RequestType, a.RequestID, a.StageCode, a.CreatedAt,
                        r.TicketNumber, r.SurveyCode, r.RequestedByNetID AS FiledBy,
                        $summary AS Summary
                 FROM rq.Approvals a
                 JOIN $table r ON r.$pk = a.RequestID
                 WHERE a.RequestType = ? AND a.Decision IS NULL AND a.IsRequired = 1
                   AND NOT EXISTS (SELECT 1 FROM rq.Approvals b
                                   WHERE b.RequestType = a.RequestType
                                     AND b.RequestID = a.RequestID
                                     AND b.Decision IS NULL AND b.IsRequired = 1
                                     AND b.StageOrder < a.StageOrder)");
            $q->execute([$type]);
            foreach ($q as $row) {
                $waiting[] = $row;
            }
        } catch (Throwable $e) { /* that database is unavailable */ }
    }

    try {
        foreach ($waiting as $row) {
            $survey = (string)$row['SurveyCode'];
            $may = $row['StageCode'] === 'director'
                ? may_decide_director($survey, $user)
                : may_decide_committee($survey, $user);
            if (!$may) {
                continue;
            }
            $out[] = [
                'kind'    => $row['RequestType'] === 'space' ? 'Space request' : 'Key request',
                'ticket'  => (string)$row['TicketNumber'],
                'stage'   => ucwords((string)$row['StageCode']),
                'survey'  => $survey,
                'filed_by'=> (string)$row['FiledBy'],
                'summary' => (string)$row['Summary'],
                'since'   => (string)$row['CreatedAt'],
                'url'     => 'request.php?type=' . $row['RequestType'] . '&id=' . (int)$row['RequestID'],
            ];
        }
    } catch (Throwable $e) { /* tables arrive with 14_workflow */ }

    // Work and shop approvals, which any approver may decide.
    if (approval_may_decide($user)) {
        foreach (approval_pending() as $row) {
            $out[] = [
                'kind'    => $row['_type'] === 'work' ? 'Work request' : 'Shop request',
                'ticket'  => (string)$row['TicketNumber'],
                'stage'   => approval_stage_label((string)$row['StageCode']),
                'survey'  => '',
                'filed_by'=> '',
                'summary' => mb_strimwidth((string)$row['Title'], 0, 90, '…'),
                'since'   => (string)$row['CreatedAt'],
                'url'     => 'detail.php?type=' . $row['_type'] . '&id=' . (int)$row['RequestID'],
            ];
        }
    }

    usort($out, fn($a, $b) => strcmp((string)$a['since'], (string)$b['since']));
    return $out;
}

/** Everything this person has filed that is still moving. */
function dash_my_requests(array $user): array
{
    $out = [];
    $netid = $user['NetID'];

    $sets = [
        ['work', 'SELECT WorkRequestID AS ID, TicketNumber, Title AS Summary, Status, CreatedAt
                  FROM wr.WorkRequests WHERE CreatedByNetID = ?
                    AND Status NOT IN (\'closed\',\'declined\',\'merged\',\'verified\')',
         'Work request', 'detail.php?type=work&id='],
        ['shop', 'SELECT ShopRequestID AS ID, TicketNumber, WorkDescription AS Summary, Status, CreatedAt
                  FROM sh.ShopRequests WHERE RequestedByNetID = ?
                    AND Status NOT IN (\'closed\',\'denied\',\'complete\')',
         'Shop request', 'detail.php?type=shop&id='],
        ['move', 'SELECT MoveRequestID AS ID, TicketNumber, AdditionalNotes AS Summary, Status, CreatedAt
                  FROM mv.MoveRequests WHERE RequestedByNetID = ?
                    AND Status NOT IN (\'closed\',\'declined\',\'complete\')',
         'Move request', 'detail.php?type=move&id='],
    ];
    foreach ($sets as [$dbName, $sql, $label, $url]) {
        try {
            $stmt = db($dbName)->prepare($sql);
            $stmt->execute([$netid]);
            foreach ($stmt as $row) {
                $out[] = [
                    'kind' => $label, 'ticket' => (string)$row['TicketNumber'],
                    'summary' => mb_strimwidth((string)$row['Summary'], 0, 80, '…'),
                    'status' => (string)$row['Status'], 'since' => (string)$row['CreatedAt'],
                    'url' => $url . (int)$row['ID'], 'type' => $dbName,
                ];
            }
        } catch (Throwable $e) { /* database unavailable */ }
    }

    foreach ([['space', 'space', 'rq.SpaceRequests', 'SpaceRequestID', 'Justification', 'Space request'],
              ['key', 'keys', 'rq.KeyRequests', 'KeyRequestID', 'HolderName', 'Key request']] as
             [$type, $dbName, $table, $pk, $summary, $label]) {
        try {
            $stmt = db($dbName)->prepare(
                "SELECT $pk AS ID, TicketNumber, $summary AS Summary, Status, CreatedAt
                 FROM $table WHERE RequestedByNetID = ? AND Status NOT IN ('closed','denied')");
            $stmt->execute([$netid]);
            foreach ($stmt as $row) {
                $out[] = [
                    'kind' => $label, 'ticket' => (string)$row['TicketNumber'],
                    'summary' => mb_strimwidth((string)$row['Summary'], 0, 80, '…'),
                    'status' => (string)$row['Status'], 'since' => (string)$row['CreatedAt'],
                    'url' => 'request.php?type=' . $type . '&id=' . (int)$row['ID'], 'type' => $type,
                ];
            }
        } catch (Throwable $e) { /* tables arrive with 14_workflow */ }
    }

    usort($out, fn($a, $b) => strcmp((string)$b['since'], (string)$a['since']));
    return $out;
}

function dash_days_since(string $ts): int
{
    return (int)floor((time() - strtotime($ts)) / 86400);
}
