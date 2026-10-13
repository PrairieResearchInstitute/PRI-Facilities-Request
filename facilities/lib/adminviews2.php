<?php
declare(strict_types=1);

require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/approvals.php';
require_once __DIR__ . '/rooms.php';
require_once __DIR__ . '/refsync.php';

/**
 * Approvals queue, people and roles, and space management: adding,
 * editing and retiring buildings and rooms, including moving a group of
 * rooms when a unit relocates and a leased site is given up.
 */

// ------------------------------------------------------------ approvals
function av_approvals(): void
{
    $rows = approval_pending();
    $user = current_user();
    ?>
    <div class="panel">
        <h2>Waiting on a decision (<?= count($rows) ?>)</h2>
        <p class="muted">A chain opens only where money is involved: a shop request opens one at
        intake, and a work request opens one when its estimate crosses the approval threshold
        ($<?= number_format((float)setting('estimate_approval_threshold')) ?>, with the Director
        added above $<?= number_format((float)setting('director_approval_threshold')) ?>).
        <?php if (!approval_may_decide($user)): ?>
        You are acting as <?= h($user['DisplayName']) ?> (<?= h($user['Role']) ?>), so you can see
        this queue but not decide on it.
        <?php endif; ?></p>
        <?php if (!$rows): ?>
        <p>Nothing is awaiting approval.</p>
        <?php else: ?>
        <div class="scroll-x">
        <table>
            <tr><th>Ticket</th><th>Type</th><th>What</th><th>Stage</th><th>Named approver</th>
                <th>Amount</th><th>Opened</th><th></th></tr>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><a href="detail.php?type=<?= h($r['_type']) ?>&amp;id=<?= (int)$r['RequestID'] ?>">
                    <?= h((string)$r['TicketNumber']) ?></a></td>
                <td><?= $r['_type'] === 'work' ? 'Work' : 'Shop' ?></td>
                <td><?= h(mb_strimwidth((string)$r['Title'], 0, 70, '…')) ?></td>
                <td><strong><?= h(approval_stage_label((string)$r['StageCode'])) ?></strong></td>
                <td><?= h((string)$r['ApproverName']) ?></td>
                <td><?= $r['EstimateAmount'] !== null ? '$' . number_format((float)$r['EstimateAmount'], 2) : '' ?></td>
                <td><?= h(date('M j', strtotime((string)$r['CreatedAt']))) ?></td>
                <td><a class="btn" href="detail.php?type=<?= h($r['_type']) ?>&amp;id=<?= (int)$r['RequestID'] ?>">
                    Decide</a></td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php endif; ?>
    </div>
    <?php
}

// ---------------------------------------------------------- people/roles
const APP_ROLES = ['staff', 'coordinator', 'approver', 'admin'];

function av_people(): void
{
    $q = trim((string)($_GET['q'] ?? ''));
    $sql = 'SELECT * FROM app.Users' . ($q !== '' ? ' WHERE NetID LIKE ? OR DisplayName LIKE ?' : '')
         . ' ORDER BY IsActive DESC, DisplayName';
    $stmt = db('work')->prepare($sql);
    $stmt->execute($q !== '' ? ["%$q%", "%$q%"] : []);
    $rows = $stmt->fetchAll();
    ?>
    <div class="panel">
        <h2>Add a Person</h2>
        <p class="muted">Anyone who signs in is provisioned automatically. Add someone here when
        they need a role before their first visit, such as an approver named on a chain.</p>
        <form method="post" class="grid">
            <?= csrf_field() ?>
            <label>NetID <input name="netid" required></label>
            <label>Display name <input name="display_name" required placeholder="Last, First"></label>
            <label>Email <input type="email" name="email"></label>
            <label>Survey <input name="survey" size="8"></label>
            <label>Role
                <select name="role">
                    <?php foreach (APP_ROLES as $r): ?><option><?= h($r) ?></option><?php endforeach; ?>
                </select>
            </label>
            <div class="actions" style="grid-column:1/-1">
                <button name="action" value="user_add" class="primary">Add person</button>
            </div>
        </form>
    </div>

    <div class="panel">
        <h2>People and roles (<?= count($rows) ?>)</h2>
        <form method="get" class="filters">
            <input type="hidden" name="section" value="people">
            <label>Search <input name="q" value="<?= h($q) ?>"></label>
            <button>Filter</button>
        </form>
        <p class="muted">staff file and confirm; coordinator triages, assigns, closes, transfers
        and imports; approver records decisions on chains; admin does everything including
        settings and reference data. A role change writes to all three databases.</p>
        <div class="scroll-x">
        <table>
            <tr><th>Name</th><th>NetID</th><th>Survey</th><th>Role</th><th>Active</th>
                <th>Last seen</th><th></th></tr>
            <?php foreach ($rows as $u): ?>
            <tr>
                <td><?= h((string)$u['DisplayName']) ?><?= $u['IsProvisional'] ? ' <span class="muted">(imported)</span>' : '' ?></td>
                <td><?= h((string)$u['NetID']) ?></td>
                <td><?= h((string)$u['SurveyCode']) ?></td>
                <td>
                    <form method="post" class="filters" style="margin:0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="netid" value="<?= h((string)$u['NetID']) ?>">
                        <select name="role">
                            <?php foreach (APP_ROLES as $r): ?>
                            <option <?= $u['Role'] === $r ? 'selected' : '' ?>><?= h($r) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button name="action" value="user_role">Set</button>
                    </form>
                </td>
                <td class="<?= $u['IsActive'] ? 'good' : 'muted' ?>"><?= $u['IsActive'] ? 'yes' : 'no' ?></td>
                <td class="muted"><?= $u['LastLoginAt'] ? h(date('M j, Y', strtotime((string)$u['LastLoginAt']))) : '' ?></td>
                <td>
                    <form method="post" style="margin:0"><?= csrf_field() ?>
                        <input type="hidden" name="netid" value="<?= h((string)$u['NetID']) ?>">
                        <button name="action" value="user_active"><?= $u['IsActive'] ? 'Deactivate' : 'Reactivate' ?></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
    </div>
    <?php
}

// ------------------------------------------------------ space management
function av_space(): void
{
    // Buildings, rooms and who is in them are the estate.
    $work = db('space');
    $bldg = trim((string)($_GET['bldg'] ?? ''));
    $editB = trim((string)($_GET['edit_bldg'] ?? ''));

    $buildings = $work->query('SELECT b.*,
            (SELECT COUNT(*) FROM gis.Rooms r WHERE r.BldgNumber = b.BldgNumber) AS MappedRooms
         FROM ref.Buildings b ORDER BY b.IsActive DESC, b.SortKey')->fetchAll();

    $edit = null;
    foreach ($buildings as $b) {
        if ($b['BldgNumber'] === $editB) {
            $edit = $b;
        }
    }
    ?>
    <div class="panel">
        <h2><?= $edit ? 'Edit building ' . h($edit['BldgNumber']) : 'Add a building' ?></h2>
        <form method="post" class="grid">
            <?= csrf_field() ?>
            <?php if ($edit): ?><input type="hidden" name="original" value="<?= h($edit['BldgNumber']) ?>"><?php endif; ?>
            <label>Building number
                <input name="bldg_number" required value="<?= h((string)($edit['BldgNumber'] ?? '')) ?>"
                       placeholder="0109">
            </label>
            <label>Name <input name="name" required value="<?= h((string)($edit['Name'] ?? '')) ?>"></label>
            <label>Short code <input name="short_code" value="<?= h((string)($edit['ShortCode'] ?? '')) ?>"></label>
            <label>Address <input name="address" value="<?= h((string)($edit['AddressLine'] ?? '')) ?>"></label>
            <label>City <input name="city" value="<?= h((string)($edit['City'] ?? '')) ?>"></label>
            <label>Criticality
                <input name="criticality" size="4" value="<?= h((string)($edit['Criticality'] ?? '0')) ?>">
            </label>
            <label class="check" style="align-self:end">
                <input type="checkbox" name="on_campus" value="1"
                    <?= ($edit['IsOnCampus'] ?? 1) ? 'checked' : '' ?>> On campus</label>
            <label class="check" style="align-self:end">
                <input type="checkbox" name="remote" value="1"
                    <?= ($edit['IsRemote'] ?? 0) ? 'checked' : '' ?>> Remote site (priority bump)</label>
            <div class="actions" style="grid-column:1/-1">
                <button name="action" value="<?= $edit ? 'bldg_edit' : 'bldg_add' ?>" class="primary">
                    <?= $edit ? 'Save building' : 'Add building' ?></button>
                <?php if ($edit): ?><a class="btn" href="admin.php?section=space">Cancel</a><?php endif; ?>
            </div>
        </form>
        <p class="muted">Building numbers carry the Facilities and Services leading zero and are
        padded to four digits automatically. Retiring a building keeps its history and its
        requests; it simply stops appearing on the forms.</p>
    </div>

    <div class="panel scroll-x">
        <h2>Buildings (<?= count($buildings) ?>)</h2>
        <table>
            <tr><th>Number</th><th>Name</th><th>Where</th><th>Mapped rooms</th>
                <th>Remote</th><th>Active</th><th></th></tr>
            <?php foreach ($buildings as $b): ?>
            <tr class="<?= $b['IsActive'] ? '' : 'muted' ?>">
                <td><?= h((string)$b['BldgNumber']) ?></td>
                <td><?= h((string)$b['Name']) ?></td>
                <td><?= h(trim(((string)$b['AddressLine']) . ' ' . ((string)$b['City']))) ?></td>
                <td><?= (int)$b['MappedRooms'] ?></td>
                <td><?= $b['IsRemote'] ? 'yes' : '' ?></td>
                <td class="<?= $b['IsActive'] ? 'good' : '' ?>"><?= $b['IsActive'] ? 'yes' : 'retired' ?></td>
                <td>
                    <a class="btn" href="admin.php?section=space&amp;edit_bldg=<?= h((string)$b['BldgNumber']) ?>">Edit</a>
                    <a class="btn" href="admin.php?section=space&amp;bldg=<?= h((string)$b['BldgNumber']) ?>">Rooms</a>
                    <form method="post" style="display:inline"><?= csrf_field() ?>
                        <input type="hidden" name="bldg_number" value="<?= h((string)$b['BldgNumber']) ?>">
                        <button name="action" value="bldg_active"><?= $b['IsActive'] ? 'Retire' : 'Restore' ?></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <?php if ($bldg !== ''):
        $rooms = $work->prepare('SELECT * FROM gis.Rooms WHERE BldgNumber = ? ORDER BY FloorCode, RoomID');
        $rooms->execute([$bldg]);
        $rooms = $rooms->fetchAll();
    ?>
    <div class="panel">
        <h2>Rooms in <?= h($bldg) ?> (<?= count($rooms) ?>)</h2>
        <h4>Add a room</h4>
        <p class="muted">For space the drawings have not caught up with. A room added here has no
        outline, so it appears in lists and reports but not on the plan until the geodatabase
        includes it.</p>
        <form method="post" class="grid">
            <?= csrf_field() ?>
            <input type="hidden" name="bldg_number" value="<?= h($bldg) ?>">
            <label>Floor <input name="floor" required size="4" placeholder="01"></label>
            <label>Room number <input name="room_id" required></label>
            <label>Room name <input name="room_name"></label>
            <label>Category code <input name="category" size="6" placeholder="300"></label>
            <label>Category description <input name="category_desc" placeholder="Office"></label>
            <label>Area sq ft <input name="area" size="8"></label>
            <label>Survey <input name="survey" size="8"></label>
            <div class="actions" style="grid-column:1/-1">
                <button name="action" value="room_add" class="primary">Add room</button>
            </div>
        </form>

        <h4>Move rooms to another building</h4>
        <p class="muted">For a relocation: choose the rooms that moved and the building they moved
        into. Occupancy, equipment, keys and the room list follow, and the change is recorded
        against every room.</p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="bldg_number" value="<?= h($bldg) ?>">
            <div class="filters">
                <label>Move into
                    <select name="to_bldg" required>
                        <option value="">--Select Building--</option>
                        <?php foreach ($buildings as $b): if (!$b['IsActive'] || $b['BldgNumber'] === $bldg) continue; ?>
                        <option value="<?= h((string)$b['BldgNumber']) ?>">
                            <?= h($b['BldgNumber'] . ' — ' . $b['Name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="check"><input type="checkbox" name="retire_source" value="1">
                    Retire <?= h($bldg) ?> afterwards (a leased site given up)</label>
                <button name="action" value="rooms_move">Move selected rooms</button>
            </div>
            <div class="scroll-x">
            <table>
                <tr><th></th><th>Floor</th><th>Room</th><th>Name</th><th>Category</th>
                    <th>Area</th><th>Occupants</th><th>Items</th><th></th></tr>
                <?php foreach ($rooms as $r):
                    $oc = $work->prepare('SELECT COUNT(*) FROM gis.Occupants WHERE RoomCode = ? AND EndedOn IS NULL');
                    $oc->execute([$r['RoomCode']]);
                    $iv = $work->prepare('SELECT COUNT(*) FROM gis.Inventory WHERE RoomCode = ?');
                    $iv->execute([$r['RoomCode']]);
                ?>
                <tr>
                    <td><input type="checkbox" name="room_codes[]" value="<?= h((string)$r['RoomCode']) ?>"></td>
                    <td><?= h((string)$r['FloorCode']) ?></td>
                    <td><?= h((string)$r['RoomID']) ?></td>
                    <td><?= h((string)$r['RoomName']) ?></td>
                    <td><?= h(trim((string)$r['CategoryCode'] . ' ' . (string)$r['CategoryDesc'])) ?></td>
                    <td><?= $r['AreaSqFt'] !== null ? number_format((float)$r['AreaSqFt']) : '' ?></td>
                    <td><?= (int)$oc->fetchColumn() ?></td>
                    <td><?= (int)$iv->fetchColumn() ?></td>
                    <td>
                        <button name="action" value="room_remove"
                                onclick="this.form.single_room.value='<?= h((string)$r['RoomCode']) ?>'">Remove</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            </div>
            <input type="hidden" name="single_room" value="">
        </form>
    </div>
    <?php endif;
}

// ------------------------------------------------------ safety manifest
function av_safety(): void
{
    // Where the eyewashes and extinguishers are is a fact about rooms,
    // and rooms are the estate.
    $work = db('space');
    $bldg = trim((string)($_GET['bldg'] ?? ''));
    $kind = trim((string)($_GET['kind'] ?? ''));

    $where = [];
    $params = [];
    if ($bldg !== '') {
        $where[] = 's.BldgNumber = ?';
        $params[] = $bldg;
    }
    if ($kind !== '') {
        $where[] = 's.Kind = ?';
        $params[] = $kind;
    }
    $sql = 'SELECT s.*, r.FloorCode, r.RoomName, b.Name AS BuildingName
            FROM gis.Safety s
            LEFT JOIN gis.Rooms r ON r.RoomCode = s.RoomCode
            LEFT JOIN ref.Buildings b ON b.BldgNumber = s.BldgNumber'
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY s.BldgNumber, r.FloorCode, s.RoomID, s.Kind';
    $stmt = $work->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $counts = $work->query('SELECT Kind, COUNT(*) AS N FROM gis.Safety GROUP BY Kind')->fetchAll();
    ?>
    <div class="panel">
        <h2>Safety Equipment Manifest</h2>
        <p class="muted">Where every fixture is, so a person can be sent to the right room. Testing
        schedules and reminders are a separate build; this is the location record they will run on.</p>
        <p>
        <?php foreach ($counts as $c): ?>
            <strong><?= (int)$c['N'] ?></strong> <?= h(nice_code((string)$c['Kind'])) ?>&nbsp;&nbsp;
        <?php endforeach; ?>
        </p>
        <form method="get" class="filters">
            <input type="hidden" name="section" value="safety">
            <label>Building
                <select name="bldg" onchange="this.form.submit()">
                    <option value="">All buildings</option>
                    <?php foreach ($work->query('SELECT DISTINCT s.BldgNumber, b.Name FROM gis.Safety s
                                    LEFT JOIN ref.Buildings b ON b.BldgNumber = s.BldgNumber
                                    ORDER BY s.BldgNumber') as $b): ?>
                    <option value="<?= h((string)$b['BldgNumber']) ?>" <?= $b['BldgNumber'] === $bldg ? 'selected' : '' ?>>
                        <?= h($b['BldgNumber'] . ' — ' . (string)$b['Name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Kind
                <select name="kind" onchange="this.form.submit()">
                    <option value="">All kinds</option>
                    <?php foreach ($counts as $c): ?>
                    <option value="<?= h((string)$c['Kind']) ?>" <?= $c['Kind'] === $kind ? 'selected' : '' ?>>
                        <?= h(nice_code((string)$c['Kind'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <a class="btn" href="export_safety.php<?= h('?' . http_build_query(['bldg' => $bldg, 'kind' => $kind])) ?>">
                Download manifest</a>
        </form>
        <div class="scroll-x">
        <table>
            <tr><th>Building</th><th>Floor</th><th>Room</th><th>Kind</th><th>Tag</th>
                <th>Brand</th><th>Model</th><th>Serial</th><th>Status</th><th>Last tested</th></tr>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= h((string)$r['BldgNumber']) ?></td>
                <td><?= h((string)$r['FloorCode']) ?></td>
                <td><?= h((string)$r['RoomID']) ?><?= $r['RoomName'] ? ' <span class="muted">' . h((string)$r['RoomName']) . '</span>' : '' ?></td>
                <td><?= h(nice_code((string)$r['Kind'])) ?></td>
                <td><?= h((string)$r['Tag']) ?></td>
                <td><?= h((string)$r['Brand']) ?></td>
                <td><?= h((string)$r['Model']) ?></td>
                <td><?= h((string)$r['SerialNumber']) ?></td>
                <td><?= h((string)$r['Status']) ?></td>
                <td><?= h((string)$r['LastTested']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <p class="muted"><?= count($rows) ?> fixture(s) listed.</p>
    </div>
    <?php
}
