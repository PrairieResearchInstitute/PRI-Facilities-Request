<?php
declare(strict_types=1);

/**
 * The data audit as a document to read, print, or hand to somebody.
 *
 * The screen at Admin -> Data to fix is for working through. This is for
 * explaining: what the system holds, what is missing, what that costs,
 * and what to do about it - in an order that reads as an argument rather
 * than a list of counts.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/dataaudit.php';
require_once __DIR__ . '/lib/tableexport.php';

if (!app_installed()) {
    header('Location: install.php');
    exit;
}
current_user();
require_role('coordinator');
set_time_limit(300);

$checks = audit_all();
$coverage = audit_coverage();
$work = db('work');
$shop = db('shop');
$count = function (PDO $pdo, string $sql): int {
    try { return (int)$pdo->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; }
};

$byArea = [];
$tally = ['blocker' => 0, 'important' => 0, 'tidy' => 0];
$grandRows = 0;
foreach ($checks as $c) {
    if ($c['error'] || (int)$c['count'] === 0) {
        continue;
    }
    $byArea[$c['category']][] = $c;
    $tally[$c['severity']]++;
    $grandRows += (int)$c['count'];
}
$words = ['blocker' => 'Stops something working',
          'important' => 'Makes something unreliable',
          'tidy' => 'Worth correcting'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PRIOps &mdash; data audit</title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="icon" href="assets/favicon.ico" sizes="any">
<link rel="stylesheet" href="assets/app.css">
<style>
/* A document rather than a screen: one column, generous measure, and a
   print stylesheet so the PDF looks deliberate. */
.doc { max-width: 52rem; margin: 0 auto; padding: 2rem 1.5rem 4rem; }
.doc h1 { margin-bottom: 0.2rem; }
.doc .stamp { color: var(--muted); margin-top: 0; }
.doc h2 { margin-top: 2.4rem; border-bottom: 2px solid var(--line); padding-bottom: 0.3rem; }
.doc h3 { margin-top: 1.6rem; margin-bottom: 0.3rem; }
.doc table { width: 100%; border-collapse: collapse; margin: 0.8rem 0; font-size: var(--fs-md); }
.doc th, .doc td { border-bottom: 1px solid var(--line); padding: 0.35rem 0.5rem; text-align: left; }
.doc th { background: var(--shade); }
.doc .num { text-align: right; }
.doc blockquote { margin: 0.8rem 0; padding: 0.6rem 1rem; border-left: 4px solid var(--accent);
                  background: var(--shade); }
.doc .sev { font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.04em; }
.doc .b-blocker { color: var(--bad); font-weight: bold; }
.doc .b-important { font-weight: bold; }
.doc .b-tidy { color: var(--muted); }
.noprint { margin: 1rem 0 2rem; }
@media print {
    .noprint { display: none; }
    .doc { max-width: none; padding: 0; }
    .doc h2 { page-break-after: avoid; }
    .doc table { page-break-inside: avoid; }
}
</style>
</head>
<body>
<div class="doc">

<div class="noprint">
    <a class="button" href="admin.php?section=audit">&larr; Back to the working list</a>
    <a class="button" href="export_audit_xlsx.php">Download as an Excel workbook</a>
    <a class="button" href="export_audit.php">Download as CSV</a>
    <button class="primary" onclick="window.print()">Print or Save as PDF</button>
</div>

<h1>PRIOps</h1>
<p class="stamp">Data audit &mdash; <?= h(date('F j, Y')) ?></p>

<h2>What This Is</h2>
<p>This system now holds the buildings, rooms, floor plans, property, keys, accounts and people
that PRI facilities work runs on, and it can accept a request, route it to whoever must agree to
it, and follow it to completion. What it does not yet hold is a complete picture: several of the
things it depends on were loaded from campus exports that were never designed to feed a system,
and some of them arrived with gaps.</p>

<p>This report is generated from the data itself, not from anybody's recollection of it. Every
figure below was counted at the moment the page was opened, and every one of them can be
recounted at any time from <em>Administration &rarr; Data &rarr; Data to fix</em>. The intention
is that this document becomes shorter each time it is produced.</p>

<h2>Where We Are</h2>
<table>
    <tr><th>What the system holds</th><th class="num">Count</th></tr>
    <tr><td>Buildings</td><td class="num"><?= number_format($count($work, 'SELECT COUNT(*) FROM ref.Buildings WHERE IsActive = 1')) ?></td></tr>
    <tr><td>Rooms with a floor plan outline</td><td class="num"><?= number_format($count($work, 'SELECT COUNT(*) FROM gis.Rooms')) ?></td></tr>
    <tr><td>Tagged property in service</td><td class="num"><?= number_format($count($work, "SELECT COUNT(*) FROM gis.Inventory WHERE Status = 'active'")) ?></td></tr>
    <tr><td>Key and card assignments</td><td class="num"><?= number_format($count($work, 'SELECT COUNT(*) FROM gis.RoomKeys')) ?></td></tr>
    <tr><td>Safety devices on the register</td><td class="num"><?= number_format($count($work, 'SELECT COUNT(*) FROM gis.Safety')) ?></td></tr>
    <tr><td>Fleet vehicles</td><td class="num"><?= number_format($count($shop, 'SELECT COUNT(*) FROM ref.FleetVehicles WHERE IsActive = 1')) ?></td></tr>
    <tr><td>Accounts that can be charged</td><td class="num"><?= number_format($count($shop, 'SELECT COUNT(*) FROM ref.CfopAccounts WHERE IsActive = 1')) ?></td></tr>
    <tr><td>People in the directory</td><td class="num"><?= number_format($count($work, 'SELECT COUNT(*) FROM ref.People WHERE IsActive = 1')) ?></td></tr>
    <tr><td>Organisational units, with their managers</td><td class="num"><?= number_format($count($work, 'SELECT COUNT(DISTINCT OrgPath) FROM ref.OrgUnits')) ?></td></tr>
</table>

<h2>How Complete It Is</h2>
<p>Counting rows says how much there is; it does not say how much of it is usable. The figures
below are the ones that matter, because each is a percentage of records that can actually do
their job.</p>
<table>
    <tr><th>Measure</th><th class="num">Filled in</th><th class="num">Of</th><th class="num">Coverage</th></tr>
    <?php foreach ($coverage as $label => $m): ?>
    <tr>
        <td><?= h((string)$label) ?></td>
        <td class="num"><?= number_format($m['have']) ?></td>
        <td class="num"><?= number_format($m['total']) ?></td>
        <td class="num <?= $m['pct'] >= 90 ? 'good' : ($m['pct'] >= 50 ? '' : 'bad') ?>"><?= $m['pct'] ?>%</td>
    </tr>
    <?php endforeach; ?>
</table>

<h2>The Findings</h2>
<p><?= $tally['blocker'] ?> findings stop something working, <?= $tally['important'] ?> make
something unreliable, and <?= $tally['tidy'] ?> are worth correcting. Together they cover
<?= number_format($grandRows) ?> individual records. Every one names the screen that fixes it, and
the accompanying workbook has a tab per area with every row listed.</p>

<?php foreach ($byArea as $area => $items): ?>
<h3><?= h((string)$area) ?></h3>
<table>
    <tr><th>Finding</th><th class="num">How many</th><th>How serious</th><th>Where it gets fixed</th></tr>
    <?php foreach ($items as $c): ?>
    <tr>
        <td><?= h($c['title']) ?><br><span class="muted"><?= h($c['why']) ?></span></td>
        <td class="num"><?= number_format((int)$c['count']) ?></td>
        <td class="sev b-<?= h($c['severity']) ?>"><?= h($words[$c['severity']]) ?></td>
        <td><?= h($c['fix_where']) ?></td>
    </tr>
    <?php endforeach; ?>
</table>
<?php endforeach; ?>

<h2>What We Would Ask For</h2>
<p>Most of what is missing cannot be fixed from inside this system, because it was never collected
in the first place. The list below is what would have to come from elsewhere, and from whom.</p>

<table>
    <tr><th>What is needed</th><th>Who holds it</th><th>What it unlocks</th></tr>
    <tr>
        <td>The relay details for institute email: host, port, and whether it authenticates or
            allows this server to relay.</td>
        <td>Campus IT</td>
        <td>Every notification and digest. Until this exists the system records what it would have
            sent and sends nothing.</td>
    </tr>
    <tr>
        <td>A safety contact for each room, or a rule for inheriting one from the survey.</td>
        <td>Survey safety staff, with the OED safety office</td>
        <td>Inspection findings, spills and safety questions having somebody to go to. This is the
            single emptiest field in the system.</td>
    </tr>
    <tr>
        <td>Year, plate and VIN for the fleet, and a first odometer reading for each vehicle.</td>
        <td>Facilities Services</td>
        <td>Service schedules, age and mileage reporting, and a replacement argument with a number
            attached.</td>
    </tr>
    <tr>
        <td>Building numbers for the property currently recorded against buildings we do not
            have.</td>
        <td>Property inventory, with Facilities</td>
        <td>Placing that property on a floor plan, and moving it through a move request.</td>
    </tr>
    <tr>
        <td>Floor plans for the buildings that have none.</td>
        <td>ISGS geographic information staff</td>
        <td>Rooms in those buildings being selectable rather than typed, and everything that hangs
            off a room code.</td>
    </tr>
    <tr>
        <td>An extinguisher, AED and first aid inventory.</td>
        <td>OED safety office</td>
        <td>The safety register covering what people actually reach for, and inspection rounds
            that mean something.</td>
    </tr>
    <tr>
        <td>The university property extract, so acquisition, purchase order and contract come
            across with the tag.</td>
        <td>Property accounting</td>
        <td>Registering equipment by entering a tag rather than retyping the purchase.</td>
    </tr>
</table>

<blockquote>
    <p>Every one of these is expected to arrive as a file somebody downloads and drops into the
    system, not as a feed. The system is built for that: it identifies an export by its shape,
    refuses one it has already seen, records what changed, and says when a source has gone stale.
    Adding a new source is a morning's work, not a project.</p>
</blockquote>

<h2>What Happens Next</h2>
<ol>
    <li><strong>Fill the seats.</strong> The survey directors and their proxies are now the real
        people from the PRI org chart, and OED facilities, shop, safety, fleet and inventory staff
        can see the administration side. This was the largest single blocker and it is done.</li>
    <li><strong>Turn on email.</strong> Nothing reaches anybody until the relay is configured.</li>
    <li><strong>Run a key amnesty.</strong> The register says a large number of keys are still out
        with people who have left. Each needs returning, voiding, or the room rekeying.</li>
    <li><strong>Assign safety contacts.</strong> Nothing in the safety plan works without them.</li>
    <li><strong>Complete the fleet record</strong> so vehicles can be scheduled rather than
        remembered.</li>
    <li><strong>Reconcile the property</strong> that sits in buildings we do not have.</li>
</ol>

<p class="muted">Generated by PRIOps on
<?= h(date('F j, Y \a\t g:ia')) ?>. Every figure is recomputed each time this page is opened.</p>

</div>
</body>
</html>
