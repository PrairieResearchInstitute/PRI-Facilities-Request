<?php
declare(strict_types=1);

/**
 * Digest runner for a real scheduled task.
 *
 *     php tools\send_digests.php            send to everyone due
 *     php tools\send_digests.php --force    send regardless of schedule
 *     php tools\send_digests.php --dry      report who is due, send nothing
 *
 * On a host with a scheduler, run this hourly and turn digest_auto_run
 * off in Settings so page views stop carrying the work.
 */

require_once __DIR__ . '/../facilities/lib/digest.php';

$force = in_array('--force', $argv, true);
$dry = in_array('--dry', $argv, true);

if ($dry) {
    $stmt = db('work')->query(
        'SELECT p.*, u.DisplayName, u.Email FROM app.DigestPreferences p
         JOIN app.Users u ON u.NetID = p.NetID WHERE u.IsActive = 1');
    $due = 0;
    foreach ($stmt as $pref) {
        $is = $force || digest_due($pref);
        if ($is) {
            $due++;
            $user = digest_user((string)$pref['NetID']);
            $n = $user ? digest_count(digest_items($user, (bool)$pref['IncludeMine'])) : 0;
            printf("  due  %-12s %-8s %d item(s)\n", $pref['NetID'], $pref['Frequency'], $n);
        }
    }
    echo "$due due.\n";
    exit(0);
}

$report = digest_run(500, $force);
foreach ($report['sent'] as $s) {
    printf("  sent %-12s %d item(s)\n", $s['netid'], $s['items']);
}
echo count($report['sent']) . " sent, " . $report['skipped'] . " skipped.\n";
echo 'Delivery mode: ' . setting('notify_mode', 'log') . "\n";
