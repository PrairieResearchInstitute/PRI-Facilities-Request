<?php
declare(strict_types=1);

/**
 * Safety plans and procedures.
 *
 * The whole point of this register is the review date. A plan that
 * nobody has read in four years is worse than no plan, because it is
 * believed. So every plan has exactly one named owner and exactly one
 * date it falls due, the due date is computed from the kind of plan
 * rather than typed, and reviewing one is a single button that writes a
 * dated line nobody can quietly overwrite.
 *
 * Which rooms a plan covers is kept as room codes rather than as a
 * relationship, because the rooms live in the estate database and no key
 * here crosses that boundary.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/requests2.php';
require_once __DIR__ . '/rooms.php';
require_once __DIR__ . '/settings.php';

function plan_db(): PDO
{
    return db('plans');
}

function plan_types(): array
{
    return plan_db()->query('SELECT * FROM ref.PlanTypes WHERE IsActive = 1 ORDER BY SortOrder')
                    ->fetchAll();
}

/** How long this kind of plan runs before it needs reading again. */
function plan_review_months(string $typeCode): int
{
    $st = plan_db()->prepare('SELECT ReviewMonths FROM ref.PlanTypes WHERE PlanTypeCode = ?');
    $st->execute([$typeCode]);
    return max(1, (int)($st->fetchColumn() ?: 12));
}

/**
 * Register a plan, or save changes to one.
 *
 * The due date is worked out from the effective date and the kind, so
 * nobody sets a chemical hygiene plan to review in 2038 by mistyping.
 */
function plan_save(array $in, array $user, int $id = 0): array
{
    $type = trim((string)($in['type'] ?? ''));
    $title = trim((string)($in['title'] ?? ''));
    $owner = trim((string)($in['owner'] ?? ''));

    if ($type === '') {
        return ['error' => 'Say what kind of plan this is.'];
    }
    if ($title === '') {
        return ['error' => 'Give it a title.'];
    }
    if ($owner === '') {
        return ['error' => 'Name the person who answers for this plan. A plan with no owner '
                         . 'is a plan nobody reviews.'];
    }

    $effective = trim((string)($in['effective'] ?? ''));
    $effectiveTs = $effective !== '' ? strtotime($effective) : time();
    if ($effectiveTs === false) {
        return ['error' => 'That effective date could not be read.'];
    }
    $due = date('Y-m-d', strtotime('+' . plan_review_months($type) . ' months', $effectiveTs));

    $pdo = plan_db();
    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            $pdo->prepare('UPDATE rq.SafetyPlans
                              SET PlanTypeCode = ?, Title = ?, Reference = ?, Version = ?,
                                  OwnerName = ?, OwnerNetID = ?, SurveyCode = ?, UnitName = ?,
                                  EffectiveOn = ?, ReviewDueOn = ?, Summary = ?,
                                  UpdatedAt = SYSDATETIME()
                            WHERE SafetyPlanID = ?')
                ->execute([
                    $type, $title,
                    trim((string)($in['reference'] ?? '')) ?: null,
                    trim((string)($in['version'] ?? '')) ?: null,
                    $owner,
                    trim((string)($in['owner_netid'] ?? '')) ?: null,
                    trim((string)($in['survey'] ?? '')) ?: null,
                    trim((string)($in['unit'] ?? '')) ?: null,
                    date('Y-m-d', $effectiveTs), $due,
                    trim((string)($in['summary'] ?? '')) ?: null,
                    $id,
                ]);
            $ticket = (string)$pdo->query("SELECT TicketNumber FROM rq.SafetyPlans
                                            WHERE SafetyPlanID = $id")->fetchColumn();
        } else {
            $ticket = rq_ticket('plan');
            $pdo->prepare('INSERT INTO rq.SafetyPlans
                              (TicketNumber, PlanTypeCode, Title, Reference, Version,
                               OwnerName, OwnerNetID, SurveyCode, UnitName,
                               EffectiveOn, ReviewDueOn, Summary, Status, FiledByNetID)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,\'current\',?)')
                ->execute([
                    $ticket, $type, $title,
                    trim((string)($in['reference'] ?? '')) ?: null,
                    trim((string)($in['version'] ?? '')) ?: null,
                    $owner,
                    trim((string)($in['owner_netid'] ?? '')) ?: null,
                    trim((string)($in['survey'] ?? '')) ?: null,
                    trim((string)($in['unit'] ?? '')) ?: null,
                    date('Y-m-d', $effectiveTs), $due,
                    trim((string)($in['summary'] ?? '')) ?: null,
                    (string)$user['NetID'],
                ]);
            $id = (int)$pdo->lastInsertId();
        }

        // The coverage is rewritten wholesale, because a plan that used to
        // cover four rooms and now covers three has to lose the fourth.
        $pdo->prepare('DELETE FROM rq.PlanRooms WHERE SafetyPlanID = ?')->execute([$id]);
        $ins = $pdo->prepare('INSERT INTO rq.PlanRooms
                                 (SafetyPlanID, RoomCode, BldgNumber, CoversWholeBuilding, Note)
                              VALUES (?,?,?,?,?)');
        foreach ((array)($in['rooms'] ?? []) as $r) {
            $bldg = trim((string)($r['bldg'] ?? ''));
            $room = trim((string)($r['room'] ?? ''));
            $whole = !empty($r['whole']);
            if ($bldg === '') {
                continue;
            }
            $code = (!$whole && $room !== '') ? room_resolve('plans', $bldg, $room) : null;
            $ins->execute([$id, $code, $bldg, $whole ? 1 : 0,
                           trim((string)($r['note'] ?? '')) ?: null]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['error' => 'The plan could not be saved: ' . $e->getMessage()];
    }

    return ['id' => $id, 'ticket' => $ticket];
}

function plan_get(int $id): ?array
{
    $pdo = plan_db();
    $st = $pdo->prepare('SELECT p.*, t.Title AS TypeTitle, t.ReviewMonths
                           FROM rq.SafetyPlans p
                           LEFT JOIN ref.PlanTypes t ON t.PlanTypeCode = p.PlanTypeCode
                          WHERE p.SafetyPlanID = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    $s = $pdo->prepare('SELECT * FROM rq.PlanRooms WHERE SafetyPlanID = ? ORDER BY PlanRoomID');
    $s->execute([$id]);
    $row['rooms'] = $s->fetchAll();
    $s = $pdo->prepare('SELECT * FROM rq.PlanReviews WHERE SafetyPlanID = ? ORDER BY ReviewedOn DESC');
    $s->execute([$id]);
    $row['reviews'] = $s->fetchAll();
    return $row;
}

/**
 * The register.
 *
 * DueDays is negative once a plan is overdue, which is what the list
 * sorts on, so the worst thing is always at the top without anybody
 * choosing a sort order.
 */
function plan_list(array $filter = [], int $limit = 500): array
{
    $where = [];
    $args = [];
    if (!empty($filter['owner'])) {
        $where[] = 'p.OwnerNetID = ?';
        $args[] = (string)$filter['owner'];
    }
    if (!empty($filter['survey'])) {
        $where[] = 'p.SurveyCode = ?';
        $args[] = (string)$filter['survey'];
    }
    if (!empty($filter['type'])) {
        $where[] = 'p.PlanTypeCode = ?';
        $args[] = (string)$filter['type'];
    }
    if (!empty($filter['due'])) {
        $warn = (int)setting('plan_warn_days', 60);
        $where[] = "p.Status = 'current' AND p.ReviewDueOn IS NOT NULL"
                 . " AND p.ReviewDueOn <= DATEADD(day, $warn, CAST(SYSDATETIME() AS DATE))";
    }
    if (empty($filter['withdrawn'])) {
        $where[] = "p.Status <> 'withdrawn'";
    }
    $n = max(1, min(2000, $limit));
    $sql = "SELECT TOP ($n) p.*, t.Title AS TypeTitle,
                   DATEDIFF(day, CAST(SYSDATETIME() AS DATE), p.ReviewDueOn) AS DueDays,
                   (SELECT COUNT(*) FROM rq.PlanRooms r WHERE r.SafetyPlanID = p.SafetyPlanID) AS Rooms
              FROM rq.SafetyPlans p
              LEFT JOIN ref.PlanTypes t ON t.PlanTypeCode = p.PlanTypeCode"
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY CASE WHEN p.ReviewDueOn IS NULL THEN 1 ELSE 0 END, p.ReviewDueOn';
    $st = plan_db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/**
 * Somebody read it again. Writes a dated line and moves the due date on
 * from today, not from the old due date - a plan reviewed six months
 * late is next due a full interval from when it was actually read.
 */
function plan_review(int $id, string $outcome, string $note, array $user): string
{
    $plan = plan_get($id);
    if (!$plan) {
        return 'No such plan.';
    }
    $pdo = plan_db();
    $ends = in_array($outcome, ['withdrawn', 'replaced'], true);

    // The review date is stamped by the server's clock, so the date it
    // falls due next has to be counted from that same clock. Computing it
    // in PHP put the two a day apart whenever the two machines disagreed
    // about what day it was, which they did.
    // The driver types a bound integer as nvarchar, which DATEADD refuses,
    // so the interval is clamped here and written into the statement.
    $months = max(1, min(600, (int)($plan['ReviewMonths'] ?: 12)));
    $next = $ends ? 'NULL' : "DATEADD(month, $months, CAST(SYSDATETIME() AS DATE))";

    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO rq.PlanReviews
                          (SafetyPlanID, ReviewedOn, ReviewedByNetID, ReviewedByName,
                           Outcome, Note, NextDueOn)
                       VALUES (?, CAST(SYSDATETIME() AS DATE), ?, ?, ?, ?, $next)")
            ->execute([$id, (string)$user['NetID'], (string)($user['DisplayName'] ?? ''),
                       $outcome, trim($note) !== '' ? trim($note) : null]);

        $pdo->prepare("UPDATE rq.SafetyPlans
                          SET LastReviewedOn = CAST(SYSDATETIME() AS DATE),
                              ReviewDueOn = $next, Status = ?, UpdatedAt = SYSDATETIME()
                        WHERE SafetyPlanID = ?")
            ->execute([$ends ? 'withdrawn' : 'current', $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return 'The review could not be saved: ' . $e->getMessage();
    }

    if ($ends) {
        return 'Recorded. This plan is now withdrawn.';
    }
    $st = $pdo->prepare('SELECT ReviewDueOn FROM rq.SafetyPlans WHERE SafetyPlanID = ?');
    $st->execute([$id]);
    return 'Recorded. Next due ' . ui_date(strtotime((string)$st->fetchColumn())) . '.';
}

/**
 * Everything falling due inside the warning window, worst first. This is
 * what the safety office looks at, and what the reminder mail sends.
 */
function plan_due_soon(): array
{
    return plan_list(['due' => true]);
}

/** Which plans cover a room. Asked by the room detail tab. */
function plans_for_room(string $roomCode, string $bldgNumber = ''): array
{
    $st = plan_db()->prepare(
        "SELECT DISTINCT p.SafetyPlanID, p.TicketNumber, p.Title, p.OwnerName,
                p.ReviewDueOn, t.Title AS TypeTitle
           FROM rq.SafetyPlans p
           JOIN rq.PlanRooms r ON r.SafetyPlanID = p.SafetyPlanID
           LEFT JOIN ref.PlanTypes t ON t.PlanTypeCode = p.PlanTypeCode
          WHERE p.Status = 'current'
            AND (r.RoomCode = ? OR (r.CoversWholeBuilding = 1 AND r.BldgNumber = ?))
          ORDER BY t.Title, p.Title");
    $st->execute([$roomCode, $bldgNumber !== '' ? $bldgNumber : substr($roomCode, 0, 4)]);
    return $st->fetchAll();
}

function plan_counts(): array
{
    $pdo = plan_db();
    $warn = (int)setting('plan_warn_days', 60);
    return [
        'current' => (int)$pdo->query("SELECT COUNT(*) FROM rq.SafetyPlans WHERE Status = 'current'")
                              ->fetchColumn(),
        'due'     => (int)$pdo->query("SELECT COUNT(*) FROM rq.SafetyPlans
                                        WHERE Status = 'current' AND ReviewDueOn IS NOT NULL
                                          AND ReviewDueOn <= DATEADD(day, $warn,
                                                                     CAST(SYSDATETIME() AS DATE))")
                              ->fetchColumn(),
        'overdue' => (int)$pdo->query("SELECT COUNT(*) FROM rq.SafetyPlans
                                        WHERE Status = 'current' AND ReviewDueOn IS NOT NULL
                                          AND ReviewDueOn < CAST(SYSDATETIME() AS DATE)")
                              ->fetchColumn(),
    ];
}
