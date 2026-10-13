<?php
declare(strict_types=1);

/**
 * The board (spec Section 13): three tabs across request types, survey
 * and status filters, text search. Work requests sort by live priority;
 * move and shop requests sort by needed-by date with overdue flagged.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/scoring.php';
require_once __DIR__ . '/lib/requests2.php';   // space and key requests
require_once __DIR__ . '/lib/picklists.php';   // the words a status is shown as
require_once __DIR__ . '/lib/access.php';      // whose requests you may see

if (!app_installed()) {
    header('Location: install.php');
    exit;
}
handle_user_switch();
$user = current_user();

/* Directors, their assistants and the OED staff see the whole board.
   Everybody else sees what they filed - which is the front end doing
   what people expect it to do, rather than showing one unit's business
   to another's. The filter is applied to the query rather than to the
   rows, so a count is a count of what you can actually see. */
$seeAll = may_see_all_requests($user);
$me = (string)$user['NetID'];

/* The column that says who filed it, per request type. A work request
   calls it CreatedByNetID; everything else calls it RequestedByNetID;
   a shop request also records who typed it in on somebody's behalf. */
$mineColumns = [
    'work'     => ['CreatedByNetID'],
    'move'     => ['RequestedByNetID'],
    'shop'     => ['RequestedByNetID', 'FiledByNetID'],
    'space'    => ['RequestedByNetID'],
    'key'      => ['RequestedByNetID'],
    'purchase' => ['RequestedByNetID'],
];
$mineClause = function (string $tab, string $prefix = '') use ($mineColumns) {
    $cols = $mineColumns[$tab] ?? ['RequestedByNetID'];
    return '(' . implode(' OR ', array_map(fn($c) => $prefix . $c . ' = ?', $cols)) . ')';
};

$tab = in_array($_GET['tab'] ?? 'work',
                ['work', 'move', 'shop', 'space', 'key', 'purchase'], true)
    ? ($_GET['tab'] ?? 'work') : 'work';
$survey = trim((string)($_GET['survey'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$showClosed = ($_GET['closed'] ?? '') === '1';

$surveys = db('work')->query('SELECT Code, Name FROM ref.Surveys WHERE IsActive = 1 ORDER BY Code')->fetchAll();

$statusLists = [
    'work' => ['open', 'triaged', 'awaiting_approval', 'scheduled', 'in_progress', 'on_hold',
               'work_done', 'verified', 'closed', 'merged', 'handed_off', 'declined'],
    'move' => ['open', 'it_review', 'inventory_review', 'facilities_review', 'scheduled',
               'complete', 'closed', 'declined'],
    'shop' => ['submitted', 'awaiting_approval', 'approved', 'in_shop', 'complete', 'closed', 'denied'],
    'space' => ['submitted', 'committee_review', 'director_review', 'approved', 'assigned',
                'closed', 'denied'],
    'key' => ['submitted', 'director_review', 'approved', 'issued', 'closed', 'denied'],
    'purchase' => ['submitted', 'fund_review', 'changes_required', 'finance_review',
                   'approved', 'ordered', 'received', 'closed', 'denied'],
];
$closedStatuses = ['closed', 'merged', 'declined', 'denied', 'complete', 'verified'];

$where = [];
$params = [];
if (!$seeAll) {
    $where[] = $mineClause($tab);
    $params = array_merge($params, array_fill(0, count($mineColumns[$tab] ?? ['x']), $me));
}
if ($survey !== '') {
    $where[] = 'SurveyCode = ?';
    $params[] = $survey;
}
if ($status !== '') {
    $where[] = 'Status = ?';
    $params[] = $status;
} elseif (!$showClosed) {
    $where[] = 'Status NOT IN (' . str_repeat('?,', count($closedStatuses) - 1) . '?)';
    $params = array_merge($params, $closedStatuses);
}

$rows = [];
if ($tab === 'work') {
    if ($q !== '') {
        $where[] = '(SearchBlob LIKE ? OR TicketNumber LIKE ?)';
        $params[] = '%' . mb_strtolower($q) . '%';
        $params[] = '%' . $q . '%';
    }
    $sql = 'SELECT w.*, b.Name AS BuildingName,
                   (SELECT COUNT(*) FROM wr.Corroborations c WHERE c.WorkRequestID = w.WorkRequestID) AS Confirmations
            FROM wr.WorkRequests w JOIN ref.Buildings b ON b.BuildingID = w.BuildingID'
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
    $stmt = db('work')->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['_priority'] = scoring_current($r);
        $r['_band'] = scoring_band($r['_priority']);
    }
    unset($r);
    usort($rows, fn($a, $b) => $b['_priority'] <=> $a['_priority']);
} elseif ($tab === 'move') {
    if ($q !== '') {
        $where[] = '(TicketNumber LIKE ? OR AdditionalNotes LIKE ? OR EXISTS
                     (SELECT 1 FROM mv.MoveItems i WHERE i.MoveRequestID = m.MoveRequestID
                      AND (i.Description LIKE ? OR i.PTag LIKE ?)))';
        array_push($params, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
    }
    $sql = 'SELECT m.*,
                   (SELECT COUNT(*) FROM mv.MoveItems i WHERE i.MoveRequestID = m.MoveRequestID) AS ItemCount
            FROM mv.MoveRequests m'
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY NeededByDate ASC';
    $stmt = db('move')->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} else {
    if ($q !== '') {
        $where[] = '(TicketNumber LIKE ? OR WorkDescription LIKE ? OR CfopCode LIKE ?)';
        array_push($params, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
    }
    $sql = 'SELECT s.* FROM sh.ShopRequests s'
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY NeededByDate ASC';
    $stmt = db('shop')->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}

// Space and key requests live in the Work database under rq, and are
// filtered the same way as the other three so the board behaves the same
// wherever you are on it.
if ($tab === 'space' || $tab === 'key') {
    $where = [];
    $params = [];
    if (!$seeAll) {
        $where[] = 'RequestedByNetID = ?';
        $params[] = $me;
    }
    if ($survey !== '') {
        $where[] = 'SurveyCode = ?';
        $params[] = $survey;
    }
    if ($status !== '') {
        $where[] = 'Status = ?';
        $params[] = $status;
    } elseif (!$showClosed) {
        $where[] = "Status NOT IN ('closed', 'denied')";
    }
    if ($q !== '') {
        $where[] = $tab === 'space'
            ? '(TicketNumber LIKE ? OR Justification LIKE ? OR RequestedChange LIKE ?'
              . ' OR SubmittedByNames LIKE ?)'
            : '(TicketNumber LIKE ? OR Reason LIKE ? OR RequestedByNetID LIKE ?'
              . ' OR RoomCode LIKE ?)';
        array_push($params, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
    }
    $table = $tab === 'space' ? 'rq.SpaceRequests' : 'rq.KeyRequests';
    $sql = "SELECT * FROM $table"
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY CreatedAt DESC';
    // Each request type is in the database of the register it writes to.
    $stmt = db($tab === 'space' ? 'space' : 'keys')->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}

// Purchases sit in the Work database beside space and key, and read
// the same way, except that the useful number is how many of the
// required approvals are in rather than which single desk it is on.
if ($tab === 'purchase') {
    $where = [];
    $params = [];
    if (!$seeAll) {
        /* A purchase is also yours if it is waiting on you: the account's
           principal investigator, their assistant, or its fund manager
           all need to find it without being told a ticket number. */
        $where[] = "(r.RequestedByNetID = ? OR r.StaffRequestingNetID = ?
                     OR EXISTS (SELECT 1 FROM rq.PurchaseFunding f
                                 WHERE f.PurchaseRequestID = r.PurchaseRequestID
                                   AND ? IN (f.PiNetID, f.FmNetID, f.PiProxyNetID)))";
        array_push($params, $me, $me, $me);
    }
    if ($survey !== '') {
        $where[] = 'r.SurveyCode = ?';
        $params[] = $survey;
    }
    if ($status !== '') {
        $where[] = 'r.Status = ?';
        $params[] = $status;
    } elseif (!$showClosed) {
        $where[] = "r.Status NOT IN ('closed', 'denied')";
    }
    if ($q !== '') {
        $where[] = '(r.TicketNumber LIKE ? OR r.VendorName LIKE ? OR r.StaffRequesting LIKE ?'
                 . ' OR r.BusinessJustification LIKE ?)';
        array_push($params, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
    }
    $sql = "SELECT r.*,
                (SELECT COUNT(*) * 2 FROM rq.PurchaseFunding f
                  WHERE f.PurchaseRequestID = r.PurchaseRequestID) AS SeatsNeeded,
                (SELECT COUNT(*) FROM rq.PurchaseFunding f
                  WHERE f.PurchaseRequestID = r.PurchaseRequestID AND f.PiStatus = 'approved') +
                (SELECT COUNT(*) FROM rq.PurchaseFunding f
                  WHERE f.PurchaseRequestID = r.PurchaseRequestID AND f.FmStatus = 'approved')
                  AS SeatsIn
            FROM rq.PurchaseRequests r"
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY r.CreatedAt DESC';
    $stmt = db('purchasing')->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}

function qs(array $overrides): string
{
    return h('?' . http_build_query(array_merge($_GET, $overrides)));
}

ui_top('Open Requests');
flash_show();
if (!$seeAll) {
    echo '<p class="muted">These are the requests you filed. Anything waiting on you is on '
       . '<a href="digest.php">My Work</a>.</p>';
}
?>
<div class="tabs">
    <a href="<?= qs(['tab' => 'work', 'status' => '']) ?>" class="<?= $tab === 'work' ? 'active' : '' ?>">Work Requests</a>
    <a href="<?= qs(['tab' => 'move', 'status' => '']) ?>" class="<?= $tab === 'move' ? 'active' : '' ?>">Move Requests</a>
    <a href="<?= qs(['tab' => 'shop', 'status' => '']) ?>" class="<?= $tab === 'shop' ? 'active' : '' ?>">Shop Requests</a>
    <a href="<?= qs(['tab' => 'space', 'status' => '']) ?>" class="<?= $tab === 'space' ? 'active' : '' ?>">Space Requests</a>
    <a href="<?= qs(['tab' => 'key', 'status' => '']) ?>" class="<?= $tab === 'key' ? 'active' : '' ?>">Key Requests</a>
    <a href="<?= qs(['tab' => 'purchase', 'status' => '']) ?>" class="<?= $tab === 'purchase' ? 'active' : '' ?>">Purchase Requests</a>
</div>

<form method="get" class="filters">
    <input type="hidden" name="tab" value="<?= h($tab) ?>">
    <label>Survey
        <select name="survey">
            <option value="">All</option>
            <?php foreach ($surveys as $s): ?>
            <option value="<?= h($s['Code']) ?>" <?= $s['Code'] === $survey ? 'selected' : '' ?>><?= h($s['Code']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Status
        <select name="status">
            <option value="">All open</option>
            <?php foreach ($statusLists[$tab] as $st): ?>
            <option value="<?= h($st) ?>" <?= $st === $status ? 'selected' : '' ?>><?= h(nice_code($st)) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Search
        <input name="q" value="<?= h($q) ?>" placeholder="ticket, words, tag">
    </label>
    <label class="check"><input type="checkbox" name="closed" value="1" <?= $showClosed ? 'checked' : '' ?>> include closed</label>
    <button>Filter</button>
    <a class="btn" href="export.php<?= h('?' . http_build_query(array_merge($_GET, ['tab' => $tab]))) ?>">Download CSV</a>
</form>

<div class="panel scroll-x">
<?php if (!$rows): ?>
    <p>No requests match. <a href="index.php">File a new request</a>.</p>
<?php elseif ($tab === 'work'): ?>
    <table>
        <tr><th>Priority</th><th>Ticket</th><th>Title</th><th>Building</th><th>Where</th>
            <th>Type / severity</th><th>Confirmations</th><th>Status</th><th>Age</th></tr>
        <?php foreach ($rows as $r):
            $age = (int)floor((time() - strtotime((string)$r['CreatedAt'])) / 86400); ?>
        <tr class="rowlink" onclick="location='detail.php?type=work&amp;id=<?= (int)$r['WorkRequestID'] ?>'">
            <td><span class="band band-<?= h($r['_band']) ?>"><?= h($r['_band']) ?></span> <?= number_format($r['_priority'], 1) ?></td>
            <td><a href="detail.php?type=work&amp;id=<?= (int)$r['WorkRequestID'] ?>"><?= h($r['TicketNumber']) ?></a></td>
            <td><?= h($r['Title']) ?></td>
            <td><?= h($r['BuildingName']) ?></td>
            <td><?= h($r['RoomNumber'] ? 'Rm ' . $r['RoomNumber'] : (string)$r['WrittenLocation']) ?></td>
            <td><?= h((string)($r['TriagedSeverity'] ?? '') !== '' ? 'sev ' . $r['TriagedSeverity'] : 'sev ' . $r['ReportedSeverity']) ?></td>
            <td><?= (int)$r['Confirmations'] ?></td>
            <td><?= h(nice_code($r['Status'])) ?></td>
            <td><?= $age ?>d</td>
        </tr>
        <?php endforeach; ?>
    </table>
<?php elseif ($tab === 'move'): ?>
    <table>
        <tr><th>Ticket</th><th>Requested by</th><th>Survey</th><th>Items</th><th>Needed by</th><th>Status</th></tr>
        <?php foreach ($rows as $r):
            $overdue = strtotime((string)$r['NeededByDate']) < time()
                && !in_array($r['Status'], ['complete', 'closed', 'declined'], true); ?>
        <tr class="rowlink" onclick="location='detail.php?type=move&amp;id=<?= (int)$r['MoveRequestID'] ?>'">
            <td><a href="detail.php?type=move&amp;id=<?= (int)$r['MoveRequestID'] ?>"><?= h($r['TicketNumber']) ?></a></td>
            <td><?= h($r['RequestedByNetID']) ?></td>
            <td><?= h((string)$r['SurveyCode']) ?></td>
            <td><?= (int)$r['ItemCount'] ?></td>
            <td class="<?= $overdue ? 'overdue' : '' ?>"><?= h((string)$r['NeededByDate']) ?><?= $overdue ? ' (overdue)' : '' ?></td>
            <td><?= h(nice_code($r['Status'])) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
<?php elseif ($tab === 'space'): ?>
    <table>
        <tr><th>Ticket</th><th>Requested by</th><th>Survey</th><th>Asking for</th>
            <th>Type of space</th><th>Account</th><th>Needed by</th><th>Status</th></tr>
        <?php foreach ($rows as $r): ?>
        <tr class="rowlink" onclick="location='request.php?type=space&amp;id=<?= (int)$r['SpaceRequestID'] ?>'">
            <td><a href="request.php?type=space&amp;id=<?= (int)$r['SpaceRequestID'] ?>"><?= h((string)$r['TicketNumber']) ?></a></td>
            <td><?= h((string)($r['SubmittedByNames'] ?: $r['RequestedByNetID'])) ?></td>
            <td><?= h((string)$r['SurveyCode']) ?></td>
            <td><?= h(SPACE_KINDS[(string)$r['RequestKind']] ?? str_replace('_', ' ', (string)$r['RequestKind'])) ?></td>
            <td><?= h((string)$r['SpaceType'] === 'other'
                ? (string)$r['SpaceTypeOther']
                : (SPACE_TYPES[(string)$r['SpaceType']] ?? '')) ?></td>
            <td><?= h((string)$r['FundingCfop']) ?></td>
            <td><?= h((string)$r['NeededByDate']) ?></td>
            <td><?= h(nice_code((string)$r['Status'])) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
<?php elseif ($tab === 'purchase'): ?>
    <table>
        <tr><th>Ticket</th><th>Buyer</th><th>Survey</th><th>Vendor</th><th>Total</th>
            <th>Approvals in</th><th>Filed</th><th>Status</th></tr>
        <?php foreach ($rows as $r):
            $need = (int)$r['SeatsNeeded'];
            $in = (int)$r['SeatsIn']; ?>
        <tr class="rowlink" onclick="location='purchase_view.php?id=<?= (int)$r['PurchaseRequestID'] ?>'">
            <td><a href="purchase_view.php?id=<?= (int)$r['PurchaseRequestID'] ?>"><?= h((string)$r['TicketNumber']) ?></a></td>
            <td><?= h((string)$r['StaffRequesting']) ?></td>
            <td><?= h((string)$r['SurveyCode']) ?></td>
            <td><?= h((string)$r['VendorName']) ?></td>
            <td>$<?= number_format((float)$r['TotalCost'], 2) ?></td>
            <td class="<?= ($need > 0 && $in >= $need) ? 'good' : '' ?>"><?= $in ?> of <?= $need ?></td>
            <td><?= h((string)$r['PurchaseReqDate']) ?></td>
            <td><?= h(picklist_label('purchase_status', (string)$r['Status'])) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
<?php elseif ($tab === 'key'): ?>
    <table>
        <tr><th>Ticket</th><th>For</th><th>Survey</th><th>Building</th><th>Room</th>
            <th>Type</th><th>Qty</th><th>Needed by</th><th>Status</th></tr>
        <?php foreach ($rows as $r): ?>
        <tr class="rowlink" onclick="location='request.php?type=key&amp;id=<?= (int)$r['KeyRequestID'] ?>'">
            <td><a href="request.php?type=key&amp;id=<?= (int)$r['KeyRequestID'] ?>"><?= h((string)$r['TicketNumber']) ?></a></td>
            <td><?= h((string)($r['HolderName'] ?: $r['RequestedByNetID'])) ?></td>
            <td><?= h((string)$r['SurveyCode']) ?></td>
            <td><?= h((string)$r['BldgNumber']) ?></td>
            <td><?= h((string)$r['RoomID']) ?></td>
            <td><?= h((string)$r['KeyType']) ?></td>
            <td><?= (int)$r['Quantity'] ?></td>
            <td><?= h((string)$r['NeededByDate']) ?></td>
            <td><?= h(nice_code((string)$r['Status'])) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
<?php else: ?>
    <table>
        <tr><th>Ticket</th><th>Requested by</th><th>Survey</th><th>CFOP</th><th>Work</th><th>Needed by</th><th>Status</th></tr>
        <?php foreach ($rows as $r):
            $overdue = strtotime((string)$r['NeededByDate']) < time()
                && !in_array($r['Status'], ['complete', 'closed', 'denied'], true); ?>
        <tr class="rowlink" onclick="location='detail.php?type=shop&amp;id=<?= (int)$r['ShopRequestID'] ?>'">
            <td><a href="detail.php?type=shop&amp;id=<?= (int)$r['ShopRequestID'] ?>"><?= h($r['TicketNumber']) ?></a></td>
            <td><?= h($r['RequestedByNetID']) ?></td>
            <td><?= h($r['SurveyCode']) ?></td>
            <td><?= h($r['CfopCode']) ?></td>
            <td><?= h(mb_substr((string)$r['WorkDescription'], 0, 60)) ?><?= mb_strlen((string)$r['WorkDescription']) > 60 ? '…' : '' ?></td>
            <td class="<?= $overdue ? 'overdue' : '' ?>"><?= h((string)$r['NeededByDate']) ?><?= $overdue ? ' (overdue)' : '' ?></td>
            <td><?= h(nice_code($r['Status'])) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>
</div>
<?php ui_bottom(); ?>
