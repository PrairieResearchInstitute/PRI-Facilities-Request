<?php
declare(strict_types=1);

require_once __DIR__ . '/adminforms.php';

/**
 * Rendering for the administrative forms. Each screen filters first, then
 * offers the actions that apply to what is on the screen, so the person
 * never types a building number twice.
 */

function fv_building_select(string $name, string $current, bool $mappedOnly = false): void
{
    echo '<label>Building<select name="' . h($name) . '" onchange="this.form.submit()">'
       . '<option value="">--Select Building--</option>';
    foreach (form_buildings() as $b) {
        if ($mappedOnly && (int)$b['Rooms'] === 0) {
            continue;
        }
        $sel = $b['BldgNumber'] === $current ? ' selected' : '';
        echo '<option value="' . h($b['BldgNumber']) . '"' . $sel . '>'
           . h($b['BldgNumber'] . ' — ' . $b['Name']) . '</option>';
    }
    echo '</select></label>';
}

function fv_room_select(string $name, string $bldg, string $current, string $label = 'Room'): void
{
    $rooms = form_rooms($bldg);
    echo '<label>' . h($label);
    if ($rooms) {
        echo '<select name="' . h($name) . '"><option value="">--Select Room--</option>';
        foreach ($rooms as $r) {
            $sel = (string)$r['RoomID'] === $current ? ' selected' : '';
            echo '<option value="' . h($r['RoomID']) . '"' . $sel . '>'
               . h('Floor ' . $r['FloorCode'] . ' · ' . $r['RoomID']
                   . ($r['RoomName'] ? ' — ' . $r['RoomName'] : '')) . '</option>';
        }
        echo '</select>';
    } else {
        echo '<input name="' . h($name) . '" value="' . h($current)
           . '" placeholder="room number">';
    }
    echo '</label>';
}

// ---------------------------------------------------------------- keys
function fv_keys(): void
{
    $bldg = trim((string)($_GET['bldg'] ?? ''));
    $holder = trim((string)($_GET['holder'] ?? ''));
    $state = trim((string)($_GET['state'] ?? 'Active'));
    $editId = (int)($_GET['edit'] ?? 0);

    $where = [];
    $params = [];
    if ($bldg !== '') {
        $where[] = 'BldgNumber = ?';
        $params[] = $bldg;
    }
    if ($holder !== '') {
        $where[] = '(HolderName LIKE ? OR HolderNetID LIKE ?)';
        $params[] = '%' . $holder . '%';
        $params[] = '%' . $holder . '%';
    }
    if ($state !== '') {
        $where[] = 'KeyState = ?';
        $params[] = $state;
    }
    $sql = 'SELECT TOP 200 * FROM gis.RoomKeys'
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY HolderName, KeyCode';
    $stmt = db('keys')->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $edit = null;
    if ($editId) {
        $st = db('keys')->prepare('SELECT * FROM gis.RoomKeys WHERE RoomKeyID = ?');
        $st->execute([$editId]);
        $edit = $st->fetch() ?: null;
    }
    ?>
    <div class="panel">
        <h2><?= $edit ? 'Edit key assignment' : 'Issue a key' ?></h2>
        <form method="post" class="grid">
            <?= csrf_field() ?>
            <?php if ($edit): ?><input type="hidden" name="key_id" value="<?= (int)$edit['RoomKeyID'] ?>"><?php endif; ?>
            <label>Employee name
                <input name="holder_name" id="k_holder" data-typeahead="person"
                       data-fill="netid:k_netid,survey:k_survey" required
                       value="<?= h((string)($edit['HolderName'] ?? '')) ?>"
                       placeholder="Last, First" autocomplete="off">
            </label>
            <label>NetID
                <input name="holder_netid" id="k_netid" value="<?= h((string)($edit['HolderNetID'] ?? '')) ?>">
            </label>
            <label>Employee status
                <select name="emp_status">
                    <option value="">--</option>
                    <?php foreach (EMP_STATUSES as $s): ?>
                    <option <?= ($edit['EmpStatus'] ?? '') === $s ? 'selected' : '' ?>><?= h($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Survey
                <input name="survey" id="k_survey" size="8" value="<?= h((string)($edit['SurveyCode'] ?? '')) ?>">
            </label>
            <label>Building
                <select name="bldg">
                    <option value="">--Select Building--</option>
                    <?php foreach (form_buildings() as $b): ?>
                    <option value="<?= h($b['BldgNumber']) ?>"
                        <?= ($edit['BldgNumber'] ?? '') === $b['BldgNumber'] ? 'selected' : '' ?>>
                        <?= h($b['BldgNumber'] . ' — ' . $b['Name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Floor <input name="floor" size="6" value="<?= h((string)($edit['FloorText'] ?? '')) ?>"></label>
            <label>Room <input name="room" value="<?= h((string)($edit['RoomID'] ?? '')) ?>"></label>
            <label>Key number
                <input name="key_code" required value="<?= h((string)($edit['KeyCode'] ?? '')) ?>">
            </label>
            <label>Type
                <select name="key_type">
                    <?php foreach (KEY_TYPES as $t): ?>
                    <option <?= ($edit['KeyType'] ?? 'Key') === $t ? 'selected' : '' ?>><?= h($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Quantity <input name="qty" size="4" value="<?= h((string)($edit['Quantity'] ?? '1')) ?>"></label>
            <label>Assigned on
                <input name="issued_on" value="<?= h((string)($edit['IssuedOn'] ?? date('M j, Y'))) ?>">
            </label>
            <label>Assigned by
                <input name="assigned_by" value="<?= h((string)($edit['AssignedBy'] ?? current_user()['DisplayName'])) ?>">
            </label>
            <?php if ($edit): ?>
            <label>Key state
                <select name="key_state">
                    <?php foreach (KEY_STATES as $s): ?>
                    <option <?= ($edit['KeyState'] ?? '') === $s ? 'selected' : '' ?>><?= h($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php endif; ?>
            <label style="grid-column:1/-1">Comments
                <input name="notes" value="<?= h((string)($edit['Notes'] ?? '')) ?>">
            </label>
            <div class="actions" style="grid-column:1/-1">
                <button name="action" value="<?= $edit ? 'key_edit' : 'key_issue' ?>" class="primary">
                    <?= $edit ? 'Save changes' : 'Issue key' ?></button>
                <?php if ($edit): ?><a class="btn" href="admin.php?section=keys">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>

    <div class="panel">
        <h2>Rekey a Room</h2>
        <p class="muted">When a lock is changed every key to that room stops working. This voids
        the active assignments with the reason and moves the room's key code on, so the register
        stops implying that old keys open the door.</p>
        <form method="post" class="filters">
            <?= csrf_field() ?>
            <label>Room
                <select name="room_code" required>
                    <option value="">--Select Room--</option>
                    <?php
                    /* Both sides are in the keys database: gis.Rooms is
                       in Work, but ref.Rooms is published everywhere and
                       carries the same building, floor, room and name. */
                    $keyed = db('keys')->query(
                        'SELECT DISTINCT k.RoomCode, r.BldgNumber, r.FloorCode, r.RoomID, r.RoomName,
                                COUNT(*) OVER (PARTITION BY k.RoomCode) AS N
                         FROM gis.RoomKeys k JOIN ref.Rooms r ON r.RoomCode = k.RoomCode
                         WHERE k.RoomCode IS NOT NULL AND k.KeyState = \'Active\'
                         ORDER BY r.BldgNumber, r.FloorCode, r.RoomID')->fetchAll();
                    foreach ($keyed as $r): ?>
                    <option value="<?= h((string)$r['RoomCode']) ?>">
                        <?= h($r['BldgNumber'] . ' · ' . $r['RoomID']
                            . ($r['RoomName'] ? ' — ' . $r['RoomName'] : '')
                            . ' (' . $r['N'] . ' active key(s))') ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>New room key code <input name="new_code" size="10"></label>
            <label>Reason <input name="reason" size="24" placeholder="lock changed, key lost"></label>
            <button name="action" value="room_rekey">Rekey room</button>
        </form>
        <?php if (!$keyed): ?><p class="muted">No room currently holds an active key.</p><?php endif; ?>
    </div>

    <div class="panel">
        <h2>Key Register</h2>
        <form method="get" class="filters">
            <input type="hidden" name="section" value="keys">
            <?php fv_building_select('bldg', $bldg); ?>
            <label>Holder <input name="holder" value="<?= h($holder) ?>"></label>
            <label>Key state
                <select name="state">
                    <option value="">All</option>
                    <?php foreach (KEY_STATES as $s): ?>
                    <option <?= $state === $s ? 'selected' : '' ?>><?= h($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button>Filter</button>
        </form>
        <div class="scroll-x">
        <table>
            <tr><th>Assignee</th><th>Status</th><th>Survey</th><th>State</th><th>Building</th>
                <th>Floor</th><th>Room</th><th>Key #</th><th>Type</th><th>Qty</th>
                <th>Assigned by</th><th>Assigned on</th><th>Actions</th></tr>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= h((string)$r['HolderName']) ?><?php if ($r['HolderNetID']): ?>
                    <div class="muted"><?= h((string)$r['HolderNetID']) ?></div><?php endif; ?></td>
                <td><?= h((string)$r['EmpStatus']) ?></td>
                <td><?= h((string)$r['SurveyCode']) ?></td>
                <td class="<?= $r['KeyState'] === 'Lost' ? 'bad' : ($r['KeyState'] === 'Active' ? 'good' : '') ?>">
                    <?= h((string)$r['KeyState']) ?></td>
                <td><?= h((string)($r['BldgNumber'] ?: $r['BuildingText'])) ?></td>
                <td><?= h((string)$r['FloorText']) ?></td>
                <td><?= h((string)$r['RoomID']) ?><?php if ($r['RoomCode']): ?>
                    <div class="muted">mapped</div><?php endif; ?></td>
                <td><?= h((string)$r['KeyCode']) ?></td>
                <td><?= h((string)$r['KeyType']) ?></td>
                <td><?= h((string)$r['Quantity']) ?></td>
                <td><?= h((string)$r['AssignedBy']) ?></td>
                <td><?= h((string)$r['IssuedOn']) ?></td>
                <td>
                    <?php if ($r['KeyState'] === 'Active'): ?>
                    <form method="post" style="display:inline"><?= csrf_field() ?>
                        <input type="hidden" name="key_id" value="<?= (int)$r['RoomKeyID'] ?>">
                        <input type="hidden" name="key_state" value="Returned">
                        <button name="action" value="key_state">Return key</button>
                    </form>
                    <form method="post" style="display:inline"><?= csrf_field() ?>
                        <input type="hidden" name="key_id" value="<?= (int)$r['RoomKeyID'] ?>">
                        <input type="hidden" name="key_state" value="Lost">
                        <button name="action" value="key_state">Mark lost</button>
                    </form>
                    <?php endif; ?>
                    <a class="btn" href="admin.php?section=keys&amp;edit=<?= (int)$r['RoomKeyID'] ?>">Edit</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php if (!$rows): ?><p class="muted">No key assignments match.</p><?php endif; ?>
    </div>
    <?php
}

// ----------------------------------------------------------- equipment
function fv_equipment(): void
{
    $bldg = trim((string)($_GET['bldg'] ?? ''));
    $q = trim((string)($_GET['q'] ?? ''));
    $status = trim((string)($_GET['status'] ?? 'active'));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $pick = (int)($_GET['item'] ?? 0);
    $perPage = 100;

    $where = [];
    $params = [];
    if ($bldg !== '') {
        $where[] = 'BldgNumber = ?';
        $params[] = $bldg;
    }
    if ($q !== '') {
        $where[] = '(PropertyTag LIKE ? OR Description LIKE ? OR SerialNumber LIKE ? OR Person LIKE ?)';
        array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
    }
    if ($status !== '') {
        $where[] = 'Status = ?';
        $params[] = $status;
    }
    $clause = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $cnt = db('equipment')->prepare('SELECT COUNT(*) AS n, ISNULL(SUM(Cost), 0) AS c FROM gis.Inventory' . $clause);
    $cnt->execute($params);
    $totals = $cnt->fetch();
    $total = (int)$totals['n'];
    $totalCost = (float)$totals['c'];
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);

    // Paged in the database rather than poured into the page. Two and a
    // half thousand items is not a screen, it is a filing cabinet, and the
    // old design gave every one of them its own card and two forms.
    $stmt = db('equipment')->prepare(
        'SELECT * FROM gis.Inventory' . $clause
        . ' ORDER BY Description, InventoryID
           OFFSET ' . (($page - 1) * $perPage) . ' ROWS
           FETCH NEXT ' . $perPage . ' ROWS ONLY');
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // The forms belong to whichever item was picked, so there is one set
    // of them on the page instead of one set per row.
    $item = null;
    if ($pick > 0) {
        $st = db('equipment')->prepare('SELECT * FROM gis.Inventory WHERE InventoryID = ?');
        $st->execute([$pick]);
        $item = $st->fetch() ?: null;
    }

    $link = function (array $over) use ($bldg, $q, $status, $page) {
        return h('?' . http_build_query(array_merge(
            ['section' => 'equipment', 'bldg' => $bldg, 'q' => $q,
             'status' => $status, 'page' => $page], $over)));
    };

    // This screen does two things to one item. Listing all two and a half
    // thousand is not useful for either, and twenty-four pages of it is a
    // worse way to find something than typing part of its tag. So nothing
    // is listed until it has been narrowed down. Browsing what is in a
    // room is a different question, and the building map already answers
    // it room by room.
    $narrowed = ($bldg !== '' || $q !== '');
    ?>
    <div class="panel">
        <h2>Inventory</h2>
        <p class="muted">Search by tag, description, serial number or the person holding it,
        or narrow to a building. Room by room is on the
        <a href="admin.php?section=map">building map</a>.</p>
        <form method="get" class="filters">
            <input type="hidden" name="section" value="equipment">
            <?php fv_building_select('bldg', $bldg); ?>
            <label>Search <input name="q" value="<?= h($q) ?>" placeholder="tag, description, serial, person"></label>
            <label>Status
                <select name="status">
                    <option value="">All</option>
                    <?php foreach (['active', 'transferred', 'surplus', 'trashed', 'lost'] as $s): ?>
                    <option <?= $status === $s ? 'selected' : '' ?>><?= h($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button>Filter</button>
        </form>
        <?php if ($narrowed): ?>
        <p><strong><?= number_format($total) ?></strong> item<?= $total === 1 ? '' : 's' ?>
           match<?= $total === 1 ? 'es' : '' ?><?= $totalCost > 0
               ? ', worth $' . number_format($totalCost, 2) . ' at purchase' : '' ?>.</p>
        <?php else: ?>
        <p><strong><?= number_format($total) ?></strong> item<?= $total === 1 ? '' : 's' ?>
           on the books<?= $totalCost > 0
               ? ', worth $' . number_format($totalCost, 2) . ' at purchase' : '' ?>.
           Search or pick a building to work on one.</p>
        <?php endif; ?>
    </div>

    <?php if (!$narrowed): ?>
    <div class="panel scroll-x">
        <h2>Where the Property Is</h2>
        <p class="muted">A count per building, so it is clear what exists without listing it.</p>
        <?php
        $byBldg = db('equipment')->prepare(
            'SELECT i.BldgNumber, MAX(b.Name) AS Name, COUNT(*) AS n, ISNULL(SUM(i.Cost), 0) AS c,
                    SUM(CASE WHEN i.RoomCode IS NULL THEN 1 ELSE 0 END) AS unmapped
             FROM gis.Inventory i
             LEFT JOIN ref.Buildings b ON b.BldgNumber = i.BldgNumber
             WHERE i.Status = ?
             GROUP BY i.BldgNumber ORDER BY COUNT(*) DESC');
        $byBldg->execute([$status !== '' ? $status : 'active']);
        ?>
        <table>
            <tr><th>Building</th><th>Name</th><th>Items</th><th>Not in a mapped room</th>
                <th>Value at purchase</th><th></th></tr>
            <?php foreach ($byBldg as $b): ?>
            <tr>
                <td><?= h((string)$b['BldgNumber']) ?></td>
                <td><?= h((string)($b['Name'] ?: 'not in the buildings list')) ?></td>
                <td><?= number_format((int)$b['n']) ?></td>
                <td class="<?= (int)$b['unmapped'] ? 'muted' : '' ?>"><?= number_format((int)$b['unmapped']) ?></td>
                <td>$<?= number_format((float)$b['c'], 2) ?></td>
                <td><a href="<?= $link(['bldg' => (string)$b['BldgNumber'], 'page' => 1]) ?>">Open</a></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div class="panel scroll-x">
        <h2>Recently Changed</h2>
        <?php
        $recent = db('equipment')->query(
            'SELECT TOP 15 e.*, i.Description FROM gis.InventoryEvents e
             LEFT JOIN gis.Inventory i ON i.InventoryID = e.InventoryID
             ORDER BY e.InventoryEventID DESC')->fetchAll();
        ?>
        <?php if (!$recent): ?>
        <p class="muted">Nothing has been transferred or retired through this screen yet.</p>
        <?php else: ?>
        <table>
            <tr><th>When</th><th>What</th><th>Item</th><th>Detail</th><th>By</th></tr>
            <?php foreach ($recent as $e): ?>
            <tr>
                <td><?= h(date('M j, Y g:ia', strtotime((string)$e['CreatedAt']))) ?></td>
                <td><?= h((string)$e['EventCode']) ?></td>
                <td><?= h(trim((string)$e['PropertyTag'] . ' ' . (string)$e['Description'])) ?></td>
                <td class="muted"><?= h((string)$e['Detail']) ?></td>
                <td><?= h((string)$e['ByNetID']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($item): ?>
    <div class="panel">
        <h2><?= h((string)($item['PropertyTag'] ?: 'Untagged')) ?> &mdash;
            <?= h((string)$item['Description']) ?></h2>
        <p class="muted">
            <?= h(trim((string)($item['BldgNumber'] ?? '') . ' '
                . (string)($item['RoomID'] ?: $item['BannerRoom'] ?: ''))) ?>
            <?= $item['RoomCode'] ? ' &middot; ' . h((string)$item['RoomCode'])
                                  : ' &middot; not linked to a mapped room' ?>
            <?= $item['SerialNumber'] ? ' &middot; serial ' . h((string)$item['SerialNumber']) : '' ?>
            &middot; held by <?= h((string)($item['Person'] ?: 'nobody recorded')) ?>
            &middot; <?= h((string)$item['Status']) ?>
            <?= $item['Cost'] !== null ? ' &middot; $' . number_format((float)$item['Cost'], 2) : '' ?>
        </p>
        <p><a class="btn primary" href="new_property.php?item=<?= (int)$item['InventoryID'] ?>">Change
           this record</a>
           <span class="muted">it moved, somebody else has it, the record is wrong, or it has
           gone &mdash; all on one form</span></p>
        <p><a href="<?= $link(['item' => '']) ?>">Close this item</a></p>
    </div>
    <?php endif; ?>

    <?php if ($narrowed): ?>
    <div class="panel scroll-x">
        <?php
        $pager = function () use ($pages, $page, $link) {
            if ($pages < 2) {
                return;
            }
            echo '<p class="muted">Page ' . $page . ' of ' . $pages . ' ';
            if ($page > 1) {
                echo '<a href="' . $link(['page' => $page - 1]) . '">&larr; previous</a> ';
            }
            if ($page < $pages) {
                echo '<a href="' . $link(['page' => $page + 1]) . '">next &rarr;</a>';
            }
            echo '</p>';
        };
        $pager();
        ?>
        <table>
            <tr><th>Tag</th><th>Description</th><th>Building</th><th>Room</th><th>Room code</th>
                <th>Held by</th><th>Status</th><th>Cost</th><th></th></tr>
            <?php foreach ($rows as $r): ?>
            <tr<?= (int)$r['InventoryID'] === $pick ? ' class="selected"' : '' ?>>
                <td><?= h((string)($r['PropertyTag'] ?: '—')) ?></td>
                <td><?= h((string)$r['Description']) ?></td>
                <td><?= h((string)$r['BldgNumber']) ?></td>
                <td><?= h((string)($r['RoomID'] ?: $r['BannerRoom'] ?: '')) ?></td>
                <td class="muted"><?= $r['RoomCode'] ? h((string)$r['RoomCode']) : 'not mapped' ?></td>
                <td><?= h((string)$r['Person']) ?></td>
                <td><?= h((string)$r['Status']) ?></td>
                <td><?= $r['Cost'] !== null ? '$' . number_format((float)$r['Cost'], 2) : '' ?></td>
                <td><a href="<?= $link(['item' => (int)$r['InventoryID']]) ?>">Act on it</a></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php if (!$rows): ?><p class="muted">No equipment matches.</p><?php endif; ?>
        <?php $pager(); ?>
    </div>
    <?php endif; ?>

    <p><a class="btn" href="new_property.php?kind=register">Add something to the inventory</a>
       <span class="muted">for property that arrives outside a move: a new purchase, or something
       found during an audit that the inventory never had. The same form, with nothing to find
       first.</span></p>
    <?php
}

// ------------------------------------------------------ room assignment
function fv_roomassign(): void
{
    $bldg = trim((string)($_GET['bldg'] ?? ''));
    $roomCode = trim((string)($_GET['room_code'] ?? ''));
    $work = db('space');

    $room = null;
    if ($roomCode !== '') {
        $st = $work->prepare('SELECT * FROM gis.Rooms WHERE RoomCode = ?');
        $st->execute([$roomCode]);
        $room = $st->fetch() ?: null;
    }
    ?>
    <div class="panel">
        <h2>Room Assignment</h2>
        <form method="get" class="filters">
            <input type="hidden" name="section" value="roomassign">
            <?php fv_building_select('bldg', $bldg, true); ?>
            <?php if ($bldg !== ''): ?>
            <label>Room
                <select name="room_code" onchange="this.form.submit()">
                    <option value="">--Select Room--</option>
                    <?php foreach (form_rooms($bldg) as $r): ?>
                    <option value="<?= h($r['RoomCode']) ?>" <?= $r['RoomCode'] === $roomCode ? 'selected' : '' ?>>
                        <?= h('Floor ' . $r['FloorCode'] . ' · ' . $r['RoomID']
                            . ($r['RoomName'] ? ' — ' . $r['RoomName'] : '')) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php endif; ?>
            <button>Show</button>
        </form>
    </div>

    <?php if (!$room): ?>
        <div class="panel"><p class="muted">Choose a building and room.</p></div>
        <?php return;
    endif;

    $st = $work->prepare('SELECT * FROM gis.RoomResponsibility WHERE RoomCode = ?');
    $st->execute([$roomCode]);
    $resp = $st->fetch() ?: [];

    $st = $work->prepare('SELECT * FROM gis.Occupants WHERE RoomCode = ? AND EndedOn IS NULL
                          ORDER BY PrimaryOffice DESC, PersonName');
    $st->execute([$roomCode]);
    $occ = $st->fetchAll();

    $st = $work->prepare('SELECT TOP 15 * FROM gis.RoomAssignmentEvents WHERE RoomCode = ?
                          ORDER BY RoomAssignmentEventID DESC');
    $st->execute([$roomCode]);
    $events = $st->fetchAll();
    ?>
    <div class="panel">
        <h3>Room <?= h((string)$room['RoomID']) ?><?= $room['RoomName'] ? ' — ' . h((string)$room['RoomName']) : '' ?></h3>
        <p class="muted"><?= h($roomCode) ?> · <?= h((string)$room['CategoryDesc']) ?>
            · <?= number_format((float)$room['AreaSqFt']) ?> sq ft
            · space survey from data: <?= h((string)($room['SurveyCode'] ?: 'unknown')) ?></p>
        <form method="post" class="grid">
            <?= csrf_field() ?>
            <input type="hidden" name="room_code" value="<?= h($roomCode) ?>">
            <label>Survey
                <input name="survey" size="8" value="<?= h((string)($resp['SurveyCode'] ?? $room['SurveyCode'] ?? '')) ?>">
            </label>
            <label>Principal investigator
                <input name="pi_name" id="pi_name" data-typeahead="person"
                       data-fill="netid:pi_netid"
                       value="<?= h((string)($resp['PrincipalInvestigator'] ?? '')) ?>"
                       autocomplete="off">
            </label>
            <label>PI NetID
                <input name="pi_netid" id="pi_netid" value="<?= h((string)($resp['PrincipalInvestigatorNetID'] ?? '')) ?>">
            </label>
            <label>Safety contact
                <input name="safety_name" id="safety_name" data-typeahead="person"
                       data-fill="netid:safety_netid"
                       value="<?= h((string)($resp['SafetyContact'] ?? '')) ?>"
                       autocomplete="off">
            </label>
            <label>Safety contact NetID
                <input name="safety_netid" id="safety_netid" value="<?= h((string)($resp['SafetyContactNetID'] ?? '')) ?>">
            </label>
            <label>Primary contact NetID
                <input name="primary_netid" value="<?= h((string)($resp['PrimaryContactNetID'] ?? '')) ?>">
            </label>
            <label>Room use override
                <input name="use_override" value="<?= h((string)($resp['RoomUseOverride'] ?? '')) ?>">
            </label>
            <label>Access restriction
                <input name="access" value="<?= h((string)($resp['AccessRestriction'] ?? '')) ?>"
                       placeholder="e.g. keyed access, escort required">
            </label>
            <label style="grid-column:1/-1">Notes
                <input name="notes" value="<?= h((string)($resp['Notes'] ?? '')) ?>">
            </label>
            <div class="actions" style="grid-column:1/-1">
                <button name="action" value="room_assign" class="primary">Save assignment</button>
            </div>
        </form>
    </div>

    <div class="panel">
        <h3>Occupants (<?= count($occ) ?>)</h3>
        <table>
            <tr><th>Name</th><th>NetID</th><th>Org</th><th>Type</th><th>Primary</th><th>Source</th><th></th></tr>
            <?php foreach ($occ as $o): ?>
            <tr>
                <td><?= h((string)$o['PersonName']) ?></td>
                <td><?= h((string)$o['NetID']) ?></td>
                <td><?= h((string)$o['Org']) ?></td>
                <td><?= h((string)$o['EmpType']) ?></td>
                <td><?= $o['PrimaryOffice'] === 'Y' ? 'yes' : '' ?></td>
                <td class="muted"><?= $o['IsManaged'] ? 'assigned here' : 'C3PO' ?></td>
                <td>
                    <form method="post" style="margin:0"><?= csrf_field() ?>
                        <input type="hidden" name="occupant_id" value="<?= (int)$o['OccupantID'] ?>">
                        <button name="action" value="occupant_remove">Remove</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <h4>Assign someone to this room</h4>
        <form method="post" class="grid">
            <?= csrf_field() ?>
            <input type="hidden" name="room_code" value="<?= h($roomCode) ?>">
            <label>Name
                <input name="person_name" id="occ_name" data-typeahead="person"
                       data-fill="netid:occ_netid,survey:occ_org" required autocomplete="off"></label>
            <label>NetID <input name="person_netid" id="occ_netid"></label>
            <label>Org <input name="org" id="occ_org"></label>
            <label>Type <input name="emp_type" placeholder="AP, Civil Service, Student"></label>
            <label class="check" style="align-self:end">
                <input type="checkbox" name="primary" value="1"> Primary office</label>
            <div class="actions" style="grid-column:1/-1">
                <button name="action" value="occupant_add">Assign to room</button>
            </div>
        </form>
    </div>

    <?php if ($events): ?>
    <div class="panel">
        <h3>Assignment history</h3>
        <ul class="stagechain">
            <?php foreach ($events as $e): ?>
            <li><?= h(date('M j, Y g:ia', strtotime((string)$e['CreatedAt']))) ?> &mdash;
                <?= h(str_replace('_', ' ', (string)$e['EventCode'])) ?>:
                <?= h((string)$e['Detail']) ?>
                <span class="muted">by <?= h((string)$e['ByNetID']) ?></span></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif;
}
