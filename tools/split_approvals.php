<?php
declare(strict_types=1);

/**
 * A request's approval chain belongs in the same database as the request.
 *
 * rq.Approvals is keyed by (RequestType, RequestID) and served space and
 * key requests both. Both have since moved into databases of their own,
 * so each chain moved with its request - otherwise the dashboard's
 * "waiting on me" would have to join across a database boundary to find
 * out what it is waiting on, and that is the join this system does not do.
 *
 *   php tools/split_approvals.php keys --apply
 *   php tools/split_approvals.php space --apply
 */

require_once __DIR__ . '/../facilities/lib/db.php';
db_localdb_only('tools/split_approvals.php');

$apply = in_array('--apply', $argv, true);

$work = db('work');
$target = db($argv[1] ?? 'keys');

$type = ($argv[1] ?? 'keys') === 'space' ? 'space' : 'key';
$moving = (int)$work->query(
    "SELECT COUNT(*) FROM rq.Approvals WHERE RequestType = '$type'")->fetchColumn();
$staying = (int)$work->query(
    "SELECT COUNT(*) FROM rq.Approvals WHERE RequestType <> '$type'")->fetchColumn();

echo "rq.Approvals in Work: $moving for key requests, $staying for everything else\n";

$has = (int)$target->query(
    "SELECT COUNT(*) FROM sys.tables t JOIN sys.schemas s ON s.schema_id = t.schema_id
      WHERE s.name = 'rq' AND t.name = 'Approvals'")->fetchColumn();

if (!$apply) {
    echo $has
        ? "The keys database already has rq.Approvals.\n"
        : "Would create rq.Approvals in the keys database and move $moving row(s).\n";
    echo "Dry run. Add --apply to do it.\n";
    exit;
}

if (!$has) {
    $target->exec("SELECT * INTO rq.Approvals FROM [PRI_FacilitiesWork].rq.Approvals WHERE 1 = 0");

    foreach ($work->query(
        "SELECT c.name AS col, d.definition AS def
           FROM sys.default_constraints d
           JOIN sys.columns c ON c.object_id = d.parent_object_id
                             AND c.column_id = d.parent_column_id
          WHERE d.parent_object_id = OBJECT_ID('rq.Approvals')") as $d) {
        $target->exec("ALTER TABLE rq.Approvals ADD DEFAULT {$d['def']} FOR [{$d['col']}]");
    }
    foreach ($work->query(
        "SELECT name, definition FROM sys.check_constraints
          WHERE parent_object_id = OBJECT_ID('rq.Approvals')") as $c) {
        $target->exec("ALTER TABLE rq.Approvals ADD CONSTRAINT [{$c['name']}_{$type}] CHECK {$c['definition']}");
    }
    echo "Created rq.Approvals in the keys database.\n";
}

$already = (int)$target->query("SELECT COUNT(*) FROM rq.Approvals")->fetchColumn();
if ($already > 0) {
    echo "It already holds $already row(s); nothing copied.\n";
} elseif ($moving > 0) {
    // Identity has to be turned off to keep the original ApprovalID, and
    // the ids matter: a decision recorded against approval 41 has to stay
    // approval 41.
    $cols = [];
    foreach ($work->query(
        "SELECT name FROM sys.columns WHERE object_id = OBJECT_ID('rq.Approvals')
          ORDER BY column_id") as $c) {
        $cols[] = '[' . $c['name'] . ']';
    }
    $list = implode(', ', $cols);
    $target->exec('SET IDENTITY_INSERT rq.Approvals ON');
    $target->exec("INSERT INTO rq.Approvals ($list)
                 SELECT $list FROM [PRI_FacilitiesWork].rq.Approvals WHERE RequestType = '$type'");
    $target->exec('SET IDENTITY_INSERT rq.Approvals OFF');
    $moved = (int)$target->query("SELECT COUNT(*) FROM rq.Approvals")->fetchColumn();
    echo "Moved $moved of $moving row(s).\n";
} else {
    echo "No key approvals to move.\n";
}

echo "\nThe originals are still in Work. tools/drop_moved_tables.php clears them.\n";
