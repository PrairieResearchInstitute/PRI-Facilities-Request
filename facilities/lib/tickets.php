<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';

/**
 * Ticket numbers: PREFIX-FY27-00001. State fiscal year runs July 1 to
 * June 30 (2026-08-06 ruling), so August 2026 is FY27. Counters reset
 * when the fiscal year rolls.
 */

function fiscal_year(?int $ts = null): int
{
    $ts = $ts ?? time();
    $y = (int)date('Y', $ts);
    return ((int)date('n', $ts) >= 7) ? $y + 1 : $y;
}

function next_ticket_number(string $dbName): string
{
    $prefixKey = ['work' => 'ticket_prefix_wr', 'move' => 'ticket_prefix_mv', 'shop' => 'ticket_prefix_sh'][$dbName];
    $prefix = (string)setting($prefixKey);
    $fy = fiscal_year();
    $pdo = db($dbName);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT CounterYear, CurrentValue FROM app.Counters WITH (UPDLOCK, HOLDLOCK) WHERE CounterName = ?');
        $stmt->execute([$prefix]);
        $row = $stmt->fetch();
        if ($row === false) {
            $pdo->prepare('INSERT INTO app.Counters (CounterName, CounterYear, CurrentValue) VALUES (?, ?, 0)')
                ->execute([$prefix, $fy]);
            $row = ['CounterYear' => $fy, 'CurrentValue' => 0];
        }
        if ((int)$row['CounterYear'] !== $fy) {
            $pdo->prepare('UPDATE app.Counters SET CounterYear = ?, CurrentValue = 0 WHERE CounterName = ?')
                ->execute([$fy, $prefix]);
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

/** Append a status history row in the given database. */
function record_status(string $dbName, int $requestId, ?string $from, string $to, ?int $localUserId, ?string $comment = null): void
{
    db($dbName)->prepare('INSERT INTO app.StatusHistory (RequestID, FromStatus, ToStatus, UserID, Comment)
                          VALUES (?, ?, ?, ?, ?)')
        ->execute([$requestId, $from, $to, $localUserId, $comment]);
}
