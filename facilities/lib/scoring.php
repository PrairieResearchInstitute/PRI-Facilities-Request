<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';

/**
 * Priority computation (spec Section 7). Every constant reads from
 * app.Settings. The base score persists on the row; the age term is
 * evaluated at read time so the board stays correct without a nightly
 * job.
 */

function scoring_base(array $req, array $type, array $building, int $confirmations, int $distinctSurveys): float
{
    $severity = max(
        (int)($req['TriagedSeverity'] ?? 0) ?: (int)$req['ReportedSeverity'],
        (int)$type['SeverityFloor']
    );
    $severity = max(1, min(4, $severity));

    $base = (float)setting('severity_points_' . $severity)
          + (!empty($req['LifeSafety'])   ? (float)setting('life_safety_points')   : 0)
          + (!empty($req['SpecimenRisk']) ? (float)setting('specimen_risk_points') : 0)
          + (float)$type['CategoryWeight']
          + (float)$building['Criticality']
          + (!empty($building['IsRemote']) ? (float)setting('remote_site_points') : 0);

    $v = $confirmations;                       // excludes the original reporter by construction
    $scale = (string)setting('corroboration_scale');
    $f = match ($scale) {
        'linear' => (float)$v,
        'log'    => log(1 + $v, 2),
        default  => sqrt((float)$v),
    };
    $corro = min((float)setting('corroboration_cap'), (float)setting('corroboration_points') * $f);

    $u = max(1, $distinctSurveys);
    $spread = min((float)setting('distinct_unit_cap'), (float)setting('distinct_unit_points') * ($u - 1));

    return round($base + $corro + $spread, 2);
}

/** Statuses during which age accrues (spec Section 7). */
const AGE_STATUSES = ['open', 'triaged', 'awaiting_approval', 'on_hold'];

function scoring_age_term(string $status, string $createdAt): float
{
    if (!in_array($status, AGE_STATUSES, true)) {
        return 0.0;
    }
    $days = (time() - strtotime($createdAt)) / 86400;
    return round(min((float)setting('age_cap'), (float)setting('age_points_per_day') * max(0, $days)), 2);
}

function scoring_band(float $priority): string
{
    if ($priority >= (float)setting('band_critical')) {
        return 'critical';
    }
    if ($priority >= (float)setting('band_high')) {
        return 'high';
    }
    if ($priority >= (float)setting('band_elevated')) {
        return 'elevated';
    }
    return 'routine';
}

/**
 * Recompute and persist the stored base and band for one work request.
 */
function scoring_recompute(int $workRequestId): void
{
    $pdo = db('work');
    $req = $pdo->prepare('SELECT * FROM wr.WorkRequests WHERE WorkRequestID = ?');
    $req->execute([$workRequestId]);
    $r = $req->fetch();
    if (!$r) {
        return;
    }
    $type = $pdo->prepare('SELECT * FROM ref.WorkTypes WHERE WorkTypeID = ?');
    $type->execute([$r['WorkTypeID']]);
    $t = $type->fetch();
    $bld = $pdo->prepare('SELECT * FROM ref.Buildings WHERE BuildingID = ?');
    $bld->execute([$r['BuildingID']]);
    $b = $bld->fetch();

    $c = $pdo->prepare('SELECT COUNT(*) FROM wr.Corroborations WHERE WorkRequestID = ?');
    $c->execute([$workRequestId]);
    $confirmations = (int)$c->fetchColumn();

    // Distinct surveys among reporter and confirmers.
    $s = $pdo->prepare(
        'SELECT COUNT(DISTINCT SurveyCode) FROM (
            SELECT SurveyCode FROM wr.Corroborations WHERE WorkRequestID = ? AND SurveyCode IS NOT NULL
            UNION
            SELECT SurveyCode FROM wr.WorkRequests WHERE WorkRequestID = ? AND SurveyCode IS NOT NULL
         ) x'
    );
    $s->execute([$workRequestId, $workRequestId]);
    $distinct = max(1, (int)$s->fetchColumn());

    $base = scoring_base($r, $t, $b, $confirmations, $distinct);
    $band = scoring_band($base + scoring_age_term($r['Status'], (string)$r['CreatedAt']));
    $pdo->prepare('UPDATE wr.WorkRequests SET PriorityBase = ?, UrgencyBand = ?, UpdatedAt = SYSDATETIME()
                   WHERE WorkRequestID = ?')
        ->execute([$base, $band, $workRequestId]);
}

/** Priority as displayed right now: stored base plus the live age term. */
function scoring_current(array $row): float
{
    return round((float)$row['PriorityBase'] + scoring_age_term($row['Status'], (string)$row['CreatedAt']), 2);
}
