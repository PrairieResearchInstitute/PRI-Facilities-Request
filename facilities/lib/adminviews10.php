<?php
declare(strict_types=1);

/**
 * The two safety back end sections.
 *
 * Both are lists that lead somewhere. The work of an incident happens on
 * its own page, because a report is a document rather than a row, and
 * the work of a plan is one button on its own page for the same reason.
 * What belongs here is the overview: what is open, what is late, and
 * where the gaps are.
 */

require_once __DIR__ . '/incidents.php';
require_once __DIR__ . '/plans.php';
require_once __DIR__ . '/picklists.php';

function av_incidents(): void
{
    $counts = incident_counts();
    $show = (string)($_GET['show'] ?? 'open');
    $rows = incident_list($show === 'all' ? [] : ['open' => true], 400);

    // The pattern that matters is not one report; it is the same room or
    // the same kind of thing coming up again. A list cannot show that, so
    // it is counted here.
    $byKind = db('incidents')->query(
        "SELECT IncidentKind, COUNT(*) AS N,
                SUM(CASE WHEN OccurredAt >= DATEADD(year, -1, SYSDATETIME()) THEN 1 ELSE 0 END) AS Recent
           FROM rq.Incidents GROUP BY IncidentKind ORDER BY N DESC")->fetchAll();
    $repeat = db('incidents')->query(
        "SELECT TOP (10) RoomCode, COUNT(*) AS N
           FROM rq.Incidents
          WHERE RoomCode IS NOT NULL
            AND OccurredAt >= DATEADD(year, -2, SYSDATETIME())
          GROUP BY RoomCode HAVING COUNT(*) > 1 ORDER BY N DESC")->fetchAll();
    ?>
    <div class="panel stats">
        <div><span class="big"><?= (int)$counts['open'] ?></span> open</div>
        <div><span class="big"><?= (int)$counts['actions'] ?></span> corrective actions outstanding</div>
        <div><span class="big"><?= (int)$counts['year'] ?></span> in the last twelve months</div>
    </div>

    <div class="panel actions">
        <a class="btn secondary<?= $show !== 'all' ? ' on' : '' ?>"
           href="?section=incidents&amp;show=open">Still Open</a>
        <a class="btn secondary<?= $show === 'all' ? ' on' : '' ?>"
           href="?section=incidents&amp;show=all">Everything</a>
        <a class="btn" href="new_incident.php">Report One</a>
    </div>

    <?php if ($repeat): ?>
    <div class="panel warn">
        <h3>Rooms With More Than One Report In Two Years</h3>
        <p>Not necessarily a problem, but the place to look first.</p>
        <ul>
        <?php foreach ($repeat as $r): ?>
            <li><strong><?= h((string)$r['RoomCode']) ?></strong> &mdash;
                <?= (int)$r['N'] ?> reports</li>
        <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <div class="panel scroll-x">
        <h2><?= $show === 'all' ? 'Every Report' : 'Open Reports' ?></h2>
        <?php if (!$rows): ?>
        <p class="muted"><?= $show === 'all'
            ? 'Nothing has been reported.'
            : 'Nothing is open. Everything reported has been closed out.' ?></p>
        <?php else: ?>
        <table>
            <tr><th>Ticket</th><th>What</th><th>When</th><th>Where</th><th>Survey</th>
                <th>Hurt</th><th>Actions open</th><th>Severity</th><th>Status</th></tr>
            <?php foreach ($rows as $r): ?>
            <?php
                $where = (string)$r['RoomCode'];
                if ($where === '') {
                    $where = trim((string)$r['BldgNumber'] . ' ' . (string)$r['RoomID']);
                }
                if ($where === '') {
                    $where = (string)$r['WrittenLocation'];
                }
            ?>
            <tr>
                <td><a href="incident.php?id=<?= (int)$r['IncidentID'] ?>"><?=
                    h((string)$r['TicketNumber']) ?></a></td>
                <td><?= h(picklist_label('incident_kind', (string)$r['IncidentKind'])) ?></td>
                <td><?= h(date('j M Y', strtotime((string)$r['OccurredAt']))) ?></td>
                <td><?= h($where) ?></td>
                <td><?= h((string)$r['SurveyCode']) ?></td>
                <td class="center"><?= (int)$r['Hurt'] ?: '' ?></td>
                <td class="center"><?= (int)$r['ActionsOpen'] ?: '' ?></td>
                <td><?= $r['Severity']
                        ? h(picklist_label('incident_severity', (string)$r['Severity']))
                        : '<span class="muted">not graded</span>' ?></td>
                <td><?= h(nice_code((string)$r['Status'])) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>

    <?php if ($byKind): ?>
    <div class="panel">
        <h2>By Kind</h2>
        <table>
            <tr><th>What</th><th>All time</th><th>Last twelve months</th></tr>
            <?php foreach ($byKind as $k): ?>
            <tr>
                <td><?= h(picklist_label('incident_kind', (string)$k['IncidentKind'])) ?></td>
                <td class="center"><?= (int)$k['N'] ?></td>
                <td class="center"><?= (int)$k['Recent'] ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>
    <?php
}

function av_plans(): void
{
    $counts = plan_counts();
    $rows = plan_list([], 500);

    // A plan is only as good as somebody owning it, and the register is
    // only as good as its coverage. Both gaps are worth naming out loud
    // rather than leaving somebody to notice.
    $unowned = array_values(array_filter($rows, fn($r) => trim((string)$r['OwnerNetID']) === ''));
    $uncovered = array_values(array_filter($rows, fn($r) => (int)$r['Rooms'] === 0));
    ?>
    <div class="panel stats">
        <div><span class="big"><?= (int)$counts['current'] ?></span> current</div>
        <div><span class="big<?= $counts['due'] ? ' warn' : '' ?>"><?= (int)$counts['due'] ?></span>
             due for review</div>
        <div><span class="big<?= $counts['overdue'] ? ' bad' : '' ?>"><?= (int)$counts['overdue'] ?></span>
             overdue</div>
    </div>

    <div class="panel actions">
        <a class="btn" href="plans.php">The Register, and the Form to Add to It</a>
    </div>

    <div class="panel scroll-x">
        <h2>Everything Current</h2>
        <?php if (!$rows): ?>
        <p class="muted">No plans registered yet.</p>
        <?php else: ?>
        <table>
            <tr><th>Reference</th><th>Kind</th><th>Title</th><th>Owner</th><th>Unit</th>
                <th>Covers</th><th>Last read</th><th>Next review</th></tr>
            <?php foreach ($rows as $r): ?>
            <?php
                $days = $r['DueDays'] === null ? null : (int)$r['DueDays'];
                $cls = $days === null ? '' : ($days < 0 ? 'bad' : ($days <= 60 ? 'warn' : ''));
            ?>
            <tr>
                <td><a href="plan.php?id=<?= (int)$r['SafetyPlanID'] ?>"><?=
                    h((string)($r['Reference'] ?: $r['TicketNumber'])) ?></a></td>
                <td><?= h((string)$r['TypeTitle']) ?></td>
                <td><?= h((string)$r['Title']) ?></td>
                <td><?= h((string)$r['OwnerName']) ?></td>
                <td><?= h((string)($r['UnitName'] ?: $r['SurveyCode'])) ?></td>
                <td class="center"><?= (int)$r['Rooms'] ?: '<span class="muted">none</span>' ?></td>
                <td><?= $r['LastReviewedOn']
                        ? h(date('j M Y', strtotime((string)$r['LastReviewedOn'])))
                        : '<span class="muted">never</span>' ?></td>
                <td class="<?= $cls ?>"><?= $r['ReviewDueOn']
                        ? h(date('j M Y', strtotime((string)$r['ReviewDueOn'])))
                          . ($days !== null && $days < 0
                             ? ' <strong>' . abs($days) . ' late</strong>' : '')
                        : '&mdash;' ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>

    <?php if ($unowned || $uncovered): ?>
    <div class="panel warn">
        <h3>Gaps</h3>
        <?php if ($unowned): ?>
        <p><strong><?= count($unowned) ?></strong> plan<?= count($unowned) === 1 ? ' has' : 's have' ?>
           an owner written down but no NetID, so no reminder can reach them:
           <?= h(implode(', ', array_map(fn($r) => (string)$r['Title'], $unowned))) ?>.</p>
        <?php endif; ?>
        <?php if ($uncovered): ?>
        <p><strong><?= count($uncovered) ?></strong> plan<?= count($uncovered) === 1 ? '' : 's' ?>
           name<?= count($uncovered) === 1 ? 's' : '' ?> no room or building, so no room can say
           which plan applies to it:
           <?= h(implode(', ', array_map(fn($r) => (string)$r['Title'], $uncovered))) ?>.</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php
}

/**
 * The estate: what PRI occupies and does not own.
 *
 * University buildings are left out deliberately. What matters here is
 * the twenty-odd leases, sponsor arrangements and legacy occupations
 * where somebody else owns the roof, because those are the ones that
 * expire, that cost money, and that nobody currently has a list of.
 */
function av_estate(): void
{
    $space = db('space');
    $warn = (int)setting('agreement_warn_days', 180);

    $rows = $space->query(
        "SELECT a.*, b.Name, b.LocationClass, b.Ownership, b.BuildingType, b.Condition,
                DATEDIFF(day, CAST(SYSDATETIME() AS DATE), a.EndsOn) AS DueDays
           FROM est.Agreements a
           JOIN ref.Buildings b ON b.BuildingID = a.BuildingID
          ORDER BY CASE WHEN a.EndsOn IS NULL THEN 1 ELSE 0 END, a.EndsOn, b.Name")->fetchAll();

    // A building somebody else owns, with no agreement written down, is
    // the gap worth naming: it is occupied on terms nobody can produce.
    $noAgreement = $space->query(
        "SELECT b.BldgNumber, b.Name, b.Ownership, b.LocationClass
           FROM ref.Buildings b
          WHERE b.IsActive = 1
            AND ISNULL(b.Ownership, '') NOT IN ('', 'UI')
            AND NOT EXISTS (SELECT 1 FROM est.Agreements a WHERE a.BuildingID = b.BuildingID)
          ORDER BY b.Name")->fetchAll();

    $noOwner = (int)$space->query(
        "SELECT COUNT(*) FROM ref.Buildings
          WHERE IsActive = 1 AND ISNULL(Ownership, '') = ''
            AND ISNULL(LocationClass, '') <> 'On campus'")->fetchColumn();

    $expired = count(array_filter($rows, fn($r) => $r['Status'] === 'expired'));
    $undated = count(array_filter($rows, fn($r) => $r['EndsOn'] === null));
    ?>
    <div class="panel stats">
        <div><span class="big"><?= count($rows) ?></span> agreements</div>
        <div><span class="big<?= $expired ? ' bad' : '' ?>"><?= $expired ?></span> expired</div>
        <div><span class="big<?= $undated ? ' warn' : '' ?>"><?= $undated ?></span> with no end date</div>
        <div><span class="big<?= $noAgreement ? ' warn' : '' ?>"><?= count($noAgreement) ?></span>
             occupied with nothing on file</div>
    </div>

    <div class="panel note">
        <p>University owned buildings are not listed. These are the places somebody else owns:
           leases, ground leases, sponsor arrangements and legacy occupations. Everything here
           came off the estate workbook and had never been loaded before.</p>
    </div>

    <?php if ($expired): ?>
    <div class="panel error">
        <h3>Past Their End Date</h3>
        <p>Each of these is being occupied on an agreement that has run out, or on a renewal
           nobody has recorded.</p>
        <ul>
        <?php foreach ($rows as $r): ?>
            <?php if ($r['Status'] !== 'expired') { continue; } ?>
            <li><strong><?= h((string)$r['Name']) ?></strong>
                &mdash; <?= h((string)$r['Reference']) ?>,
                ended <?= h(date('j F Y', strtotime((string)$r['EndsOn']))) ?>
                (<?= abs((int)$r['DueDays']) ?> days ago)</li>
        <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <div class="panel scroll-x">
        <h2>Agreements</h2>
        <?php if (!$rows): ?>
        <p class="muted">Nothing recorded.</p>
        <?php else: ?>
        <table>
            <tr><th>Building</th><th>Kind</th><th>Reference</th><th>Owner</th>
                <th>Terms</th><th>Lease cost</th><th>Running cost</th>
                <th>Contract / ICR</th><th>Ends</th></tr>
            <?php foreach ($rows as $r): ?>
            <?php
                $days = $r['DueDays'] === null ? null : (int)$r['DueDays'];
                $cls = $days === null ? '' : ($days < 0 ? 'bad' : ($days <= $warn ? 'warn' : ''));
            ?>
            <tr>
                <td><?= h((string)$r['Name']) ?>
                    <span class="muted"><?= h((string)$r['BldgNumber']) ?></span></td>
                <td><?= h(picklist_label('agreement_kind', (string)$r['AgreementKind'])) ?></td>
                <td><?= h((string)$r['Reference']) ?></td>
                <td><?= h((string)$r['Ownership']) ?></td>
                <td><?= h((string)$r['TermsBasis']) ?></td>
                <td><?= h((string)$r['LeaseCostBasis']) ?></td>
                <td><?= h((string)$r['OmCostBasis']) ?></td>
                <td><?= $r['ContractShare'] !== null
                        ? round((float)$r['ContractShare'] * 100) . '% / '
                          . round((float)$r['IcrShare'] * 100) . '%'
                        : '<span class="muted">not split</span>' ?></td>
                <td class="<?= $cls ?>"><?= $r['EndsOn']
                        ? h(date('j M Y', strtotime((string)$r['EndsOn'])))
                        : '<span class="muted">not recorded</span>' ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>

    <?php if ($noAgreement || $noOwner): ?>
    <div class="panel warn">
        <h3>Gaps</h3>
        <?php if ($noAgreement): ?>
        <p><strong><?= count($noAgreement) ?></strong> building<?= count($noAgreement) === 1 ? '' : 's' ?>
           owned by somebody else with no agreement on file:</p>
        <ul>
        <?php foreach ($noAgreement as $b): ?>
            <li><?= h((string)$b['Name']) ?>
                <span class="muted"><?= h((string)$b['Ownership']) ?>,
                <?= h((string)$b['LocationClass']) ?></span></li>
        <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <?php if ($noOwner): ?>
        <p><strong><?= $noOwner ?></strong> building<?= $noOwner === 1 ? '' : 's' ?> off or near
           campus with no owner recorded at all, so it cannot be told whether an agreement is
           needed.</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php
}
