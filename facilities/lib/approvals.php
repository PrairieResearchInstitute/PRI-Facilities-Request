<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/tickets.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/notify.php';

/**
 * Approval decisions (spec Section 9). Approval exists to authorise
 * spending, so a chain exists only where money does: a shop request opens
 * one at intake, and a work request opens one when an estimate crosses a
 * threshold.
 *
 * An approver chooses approve, deny, or return. A denial requires a
 * comment and stops the request; a denied request does not reopen, the
 * submitter files a new one. A return requires a comment and a target
 * stage, defaulting to the preceding stage rather than to the submitter,
 * because a budget question about a bad estimate belongs with whoever
 * wrote the estimate.
 */

const APPROVAL_TABLES = [
    'work' => ['wr.Approvals', 'WorkRequestID', 'wr.WorkRequests'],
    'shop' => ['sh.Approvals', 'ShopRequestID', 'sh.ShopRequests'],
];

function approval_stage_label(string $code): string
{
    return [
        'unit_head'       => 'Unit head',
        'fund_manager'    => 'Fund manager',
        'director'        => 'Director',
        'account_pi'      => 'Account PI',
        'shop_supervisor' => 'Shop supervisor',
    ][$code] ?? ucwords(str_replace('_', ' ', $code));
}

/** The stage awaiting a decision, or null when the chain is finished. */
function approval_current(string $type, int $id): ?array
{
    [$table, $pk] = APPROVAL_TABLES[$type];
    $stmt = db($type)->prepare(
        "SELECT TOP 1 * FROM $table WHERE $pk = ? AND Decision IS NULL AND IsRequired = 1
         ORDER BY StageOrder");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function approval_chain(string $type, int $id): array
{
    [$table, $pk] = APPROVAL_TABLES[$type];
    $stmt = db($type)->prepare("SELECT * FROM $table WHERE $pk = ? ORDER BY StageOrder");
    $stmt->execute([$id]);
    return $stmt->fetchAll();
}

/**
 * May this person decide the stage in front of them? An approver decides
 * any stage; an administrator may act on their behalf, which the comment
 * records.
 */
function approval_may_decide(array $user): bool
{
    return in_array($user['Role'], ['approver', 'admin'], true);
}

/**
 * Record one decision. Returns a message for the person who made it.
 */
function approval_decide(string $type, int $requestId, int $approvalId, string $decision,
                         string $comment, string $returnTo, array $user): string
{
    if (!in_array($decision, ['approved', 'denied', 'returned'], true)) {
        return 'Unknown decision.';
    }
    if ($decision !== 'approved' && trim($comment) === '') {
        return 'A comment is required when denying or returning a request.';
    }

    [$table, $pk, $reqTable] = APPROVAL_TABLES[$type];
    $pdo = db($type);
    $localUid = ensure_user($type, $user);

    $stmt = $pdo->prepare("SELECT * FROM $table WHERE ApprovalID = ? AND $pk = ?");
    $stmt->execute([$approvalId, $requestId]);
    $stage = $stmt->fetch();
    if (!$stage) {
        return 'That approval stage no longer exists.';
    }
    if ($stage['Decision'] !== null) {
        return 'That stage already carries a decision.';
    }

    $pdo->prepare(
        "UPDATE $table SET Decision = ?, DecisionAt = SYSDATETIME(), Comment = ?,
            ReturnToStage = ?, ApproverUserID = ?, ApproverName = COALESCE(ApproverName, ?)
         WHERE ApprovalID = ?"
    )->execute([$decision, trim($comment) ?: null, $decision === 'returned' ? $returnTo : null,
                $localUid, $user['DisplayName'], $approvalId]);

    $ticket = $pdo->prepare("SELECT TicketNumber, Status FROM $reqTable WHERE $pk = ?");
    $ticket->execute([$requestId]);
    $req = $ticket->fetch();
    $label = approval_stage_label((string)$stage['StageCode']);

    if ($decision === 'denied') {
        approval_set_status($type, $requestId, $type === 'shop' ? 'denied' : 'declined',
            $label . ' denied: ' . $comment, $localUid);
        approval_notify($type, $requestId, (string)$req['TicketNumber'], 'denied', $label, $comment);
        return $label . ' denied ' . $req['TicketNumber'] . '. The request is closed; a new one '
             . 'must be filed.';
    }

    if ($decision === 'returned') {
        // Reopen the target stage so the chain resumes there.
        $pdo->prepare(
            "UPDATE $table SET Decision = NULL, DecisionAt = NULL, Comment = NULL
             WHERE $pk = ? AND StageCode = ?"
        )->execute([$requestId, $returnTo]);
        approval_set_status($type, $requestId, 'awaiting_approval',
            $label . ' returned to ' . approval_stage_label($returnTo) . ': ' . $comment, $localUid);
        approval_notify($type, $requestId, (string)$req['TicketNumber'], 'returned', $label, $comment);
        return $label . ' returned ' . $req['TicketNumber'] . ' to '
             . approval_stage_label($returnTo) . '.';
    }

    // Approved: advance, or release the request when the chain is done.
    $next = approval_current($type, $requestId);
    if ($next) {
        approval_set_status($type, $requestId, 'awaiting_approval',
            $label . ' approved; awaiting ' . approval_stage_label((string)$next['StageCode']), $localUid);
        return $label . ' approved ' . $req['TicketNumber'] . '. Now with '
             . approval_stage_label((string)$next['StageCode']) . '.';
    }
    $released = $type === 'shop' ? 'approved' : 'triaged';
    approval_set_status($type, $requestId, $released, 'Approval chain complete', $localUid);
    approval_notify($type, $requestId, (string)$req['TicketNumber'], 'approved', $label, $comment);
    return $label . ' approved ' . $req['TicketNumber'] . '. The chain is complete and the '
         . 'request is released for work.';
}

function approval_set_status(string $type, int $id, string $status, string $comment, int $localUid): void
{
    [, $pk, $reqTable] = APPROVAL_TABLES[$type];
    $pdo = db($type);
    $cur = $pdo->prepare("SELECT Status FROM $reqTable WHERE $pk = ?");
    $cur->execute([$id]);
    $from = (string)$cur->fetchColumn();
    $closed = in_array($status, ['denied', 'declined'], true);
    $pdo->prepare("UPDATE $reqTable SET Status = ?, UpdatedAt = SYSDATETIME()"
        . ($closed ? ', ClosedAt = SYSDATETIME()' : '') . " WHERE $pk = ?")
        ->execute([$status, $id]);
    record_status($type, $id, $from, $status, $localUid, $comment);
}

function approval_notify(string $type, int $id, string $ticket, string $decision,
                         string $stage, string $comment): void
{
    $to = notify_recipients($type === 'shop' ? 'notify_shop' : 'notify_work');
    if (!$to) {
        return;
    }
    $subject = ucfirst($decision) . ': ' . $ticket;
    $body = $ticket . ' was ' . $decision . ' at the ' . $stage . " stage.\n\n"
          . ($comment !== '' ? "Comment:\n" . $comment . "\n\n" : '')
          . notify_base_url() . 'detail.php?type=' . $type . '&id=' . $id;
    notify_send($type, 'approval_' . $decision, $to, $subject, $body, $id, $ticket);
}

/**
 * Corroboration bypass (spec Section 9.1): a work request skips unit head
 * review once it reaches the high band, or once enough people from enough
 * surveys confirm it. The stage persists with the reason, and the unit
 * head is notified, so the record reaches them even though the queue did
 * not wait.
 */
function approval_apply_bypass(int $workRequestId): ?string
{
    $pdo = db('work');
    $stage = $pdo->prepare("SELECT TOP 1 * FROM wr.Approvals
                            WHERE WorkRequestID = ? AND StageCode = 'unit_head'
                              AND Decision IS NULL AND IsRequired = 1");
    $stage->execute([$workRequestId]);
    $stage = $stage->fetch();
    if (!$stage) {
        return null;
    }

    $req = $pdo->prepare('SELECT UrgencyBand, TicketNumber FROM wr.WorkRequests WHERE WorkRequestID = ?');
    $req->execute([$workRequestId]);
    $req = $req->fetch();

    $c = $pdo->prepare('SELECT COUNT(*) AS N, COUNT(DISTINCT SurveyCode) AS S
                        FROM wr.Corroborations WHERE WorkRequestID = ?');
    $c->execute([$workRequestId]);
    $c = $c->fetch();

    $needConfirm = (int)setting('bypass_confirm_count', 3);
    $needSurveys = (int)setting('bypass_survey_count', 2);
    $byBand = in_array($req['UrgencyBand'], ['high', 'critical'], true);
    $byCount = ((int)$c['N'] >= $needConfirm) && ((int)$c['S'] >= $needSurveys);
    if (!$byBand && !$byCount) {
        return null;
    }

    $reason = $byBand
        ? 'Reached the ' . $req['UrgencyBand'] . ' band'
        : $c['N'] . ' confirmations across ' . $c['S'] . ' surveys';
    $pdo->prepare('UPDATE wr.Approvals SET IsRequired = 0, SkipReason = ? WHERE ApprovalID = ?')
        ->execute([mb_substr('Bypassed: ' . $reason, 0, 120), (int)$stage['ApprovalID']]);
    return $reason;
}

/** Stages waiting on anybody, for the approvals queue. */
function approval_pending(): array
{
    $out = [];
    foreach (['work', 'shop'] as $type) {
        [$table, $pk, $reqTable] = APPROVAL_TABLES[$type];
        $titleCol = $type === 'work' ? 'r.Title' : 'r.WorkDescription';
        try {
            $sql = "SELECT a.ApprovalID, a.StageCode, a.StageOrder, a.ApproverName, a.CreatedAt,
                           r.$pk AS RequestID, r.TicketNumber, $titleCol AS Title, r.Status,
                           r.EstimateAmount
                    FROM $table a JOIN $reqTable r ON r.$pk = a.$pk
                    WHERE a.Decision IS NULL AND a.IsRequired = 1
                      AND NOT EXISTS (SELECT 1 FROM $table b WHERE b.$pk = a.$pk
                                      AND b.Decision IS NULL AND b.IsRequired = 1
                                      AND b.StageOrder < a.StageOrder)
                    ORDER BY r.TicketNumber";
            foreach (db($type)->query($sql) as $row) {
                $row['_type'] = $type;
                $out[] = $row;
            }
        } catch (Throwable $e) { /* database unavailable */ }
    }
    return $out;
}
