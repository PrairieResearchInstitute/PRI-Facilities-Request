<?php
declare(strict_types=1);

require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/requests2.php';
require_once __DIR__ . '/access.php';

/**
 * Back end screens for the two request types that begin on the front
 * end, the stage definitions, and the list of people who may see any of
 * this at all.
 */

// ---------------------------------------------------------- org chart
/**
 * What the org chart knows, and what it suggests. Suggestions are shown
 * with the evidence behind them and applied one at a time: an org chart
 * says who manages a unit, which is not the same as who signs for space,
 * and only a person can tell the difference.
 */
function av_orgchart(): void
{
    require_once __DIR__ . '/orgimport.php';
    $work = db('work');

    $people = (int)$work->query('SELECT COUNT(*) FROM ref.People WHERE OrgRole IS NOT NULL')->fetchColumn();
    $units = (int)$work->query('SELECT COUNT(DISTINCT OrgPath) FROM ref.OrgUnits')->fetchColumn();
    $last = (string)$work->query("SELECT MAX(UpdatedAt) FROM ref.People
                                  WHERE SourceLabel LIKE 'org chart%'")->fetchColumn();
    $proposals = ($units > 0) ? org_leadership_proposals() : ['directors' => [], 'access' => []];
    ?>
    <div class="panel">
        <h2>Org Chart</h2>
        <p class="muted">Suggestions only. Managing a unit and signing for its space are not
        the same job, so nothing here is applied until you confirm it.</p>
        <p><strong><?= number_format($people) ?></strong> people carry an org role across
           <strong><?= number_format($units) ?></strong> units.
           Last loaded: <?= $last ? h(date('M j, Y g:ia', strtotime($last))) : 'never' ?>.</p>
        <p class="worknote">Loading a fresh org chart is done at
        <a href="admin.php?section=datafeeds">Data &rarr; Where data comes from</a>. What follows
        is what the last one suggests.</p>
    </div>

    <?php if ($proposals['directors']): ?>
    <div class="panel scroll-x">
        <h2>Suggested Survey Leadership</h2>
        <p class="muted">The org chart can propose the head of a unit. It cannot identify the
        administrative assistant, so that one is named here. Nothing changes until you apply it.</p>
        <table>
            <tr><th>Survey</th><th>Person</th><th>Org chart role</th><th>Suggests</th>
                <th>Currently set</th><th></th></tr>
            <?php foreach ($proposals['directors'] as $d): ?>
            <tr>
                <td><strong><?= h($d['survey']) ?></strong></td>
                <td><?= h($d['name']) ?><div class="muted"><?= h($d['email']) ?></div></td>
                <td><?= h($d['role']) ?></td>
                <td class="muted"><?= h($d['suggests']) ?></td>
                <td class="<?= $d['matches'] ? 'good' : 'muted' ?>">
                    <?= h($d['current'] ?: 'unset') ?>
                    <?= $d['matches'] ? ' (matches)' : '' ?></td>
                <td>
                    <form method="post" style="display:inline"><?= csrf_field() ?>
                        <input type="hidden" name="survey_code" value="<?= h($d['survey']) ?>">
                        <input type="hidden" name="person_name" value="<?= h($d['name']) ?>">
                        <input type="hidden" name="person_email" value="<?= h($d['email']) ?>">
                        <input type="hidden" name="person_netid" value="<?= h($d['netid']) ?>">
                        <button name="action" value="org_set_director">Make director</button>
                    </form>
                    <form method="post" style="display:inline"><?= csrf_field() ?>
                        <input type="hidden" name="survey_code" value="<?= h($d['survey']) ?>">
                        <input type="hidden" name="person_name" value="<?= h($d['name']) ?>">
                        <input type="hidden" name="person_email" value="<?= h($d['email']) ?>">
                        <input type="hidden" name="person_netid" value="<?= h($d['netid']) ?>">
                        <button name="action" value="org_set_proxy">Make proxy</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>

    <?php if ($proposals['access']): ?>
    <div class="panel scroll-x">
        <h2>Managers with approval authority and no back end access (<?= count($proposals['access']) ?>)</h2>
        <p class="muted">The org chart marks these people as able to approve for their unit. Grant
        access to whichever should see the administration side; leave the rest.</p>
        <form method="post">
            <?= csrf_field() ?>
            <table>
                <tr><th></th><th>Person</th><th>Survey</th><th>Unit</th><th>Grant as</th></tr>
                <?php foreach ($proposals['access'] as $i => $a): ?>
                <tr>
                    <td><input type="checkbox" name="grant[]" value="<?= h($a['netid']) ?>"></td>
                    <td><?= h($a['name']) ?><div class="muted"><?= h($a['netid']) ?></div></td>
                    <td><?= h($a['survey']) ?></td>
                    <td class="muted"><?= h($a['path']) ?></td>
                    <td>
                        <select name="function_<?= h($a['netid']) ?>">
                            <?php foreach (['proxy' => "Director's proxy", 'facilities' => 'Facilities staff',
                                            'finance' => 'Finance staff', 'director' => 'Director',
                                            'safety' => 'Safety staff', 'fleet' => 'Fleet and vehicles',
                                            'equipment' => 'Equipment and property',
                                            'studio' => 'Studio - may edit the source'] as $k => $label): ?>
                            <option value="<?= h($k) ?>"><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            <div class="actions">
                <button name="action" value="org_grant_access" class="primary">Grant access to the ticked</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="panel scroll-x">
        <h2>Units and Their Managers</h2>
        <?php
        $rows = $work->query(
            "SELECT TOP 60 OrgPath, UnitName, SurveyCode, Depth, ManagerName, ManagerRole, MemberCount
             FROM ref.OrgUnits WHERE ManagerNetID IS NOT NULL
             ORDER BY SurveyCode, Depth, OrgPath")->fetchAll();
        ?>
        <table>
            <tr><th>Unit</th><th>Survey</th><th>Manager</th><th>Role</th><th>People</th></tr>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= h((string)$r['OrgPath']) ?></td>
                <td><?= h((string)$r['SurveyCode']) ?></td>
                <td><?= h((string)$r['ManagerName']) ?></td>
                <td><?= h((string)$r['ManagerRole']) ?></td>
                <td><?= (int)$r['MemberCount'] ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <p class="muted">First 60 of <?= number_format($units) ?> units.</p>
    </div>
    <?php
}

// -------------------------------------------- accounts and people
/**
 * The two directories the forms look things up in, both loaded from
 * exports anybody at PRI can pull for themselves. No feed, no service,
 * nobody else's permission: a report, a file, a button.
 */
function av_accounts(): void
{
    $shop = db('shop');
    $work = db('work');
    $filter = trim((string)($_GET['filter'] ?? ''));

    $accounts = (int)$shop->query('SELECT COUNT(*) FROM ref.CfopAccounts WHERE IsActive = 1')->fetchColumn();
    $complete = (int)$shop->query("SELECT COUNT(*) FROM ref.CfopAccounts
        WHERE IsActive = 1 AND AccountPI <> '' AND FundManager <> ''")->fetchColumn();
    $withNetID = (int)$shop->query('SELECT COUNT(*) FROM ref.CfopAccounts
        WHERE AccountPINetID IS NOT NULL')->fetchColumn();
    $lastAccounts = (string)$shop->query('SELECT MAX(UpdatedAt) FROM ref.CfopAccounts')->fetchColumn();
    $people = (int)$work->query('SELECT COUNT(*) FROM ref.People WHERE IsActive = 1')->fetchColumn();
    $lastPeople = (string)$work->query('SELECT MAX(UpdatedAt) FROM ref.People')->fetchColumn();
    ?>
    <div class="panel">
        <h2>Accounts and People</h2>
        <p class="muted">These are what the forms look things up in: the account picker on a shop
        request, and the person picker on every form. Both load from reports you can pull
        yourself, so keeping them current needs nobody else.</p>
        <table>
            <tr><th></th><th>Rows</th><th>Complete</th><th>Last refreshed</th></tr>
            <tr>
                <td><strong>Accounts</strong> (CFOP)</td>
                <td><?= number_format($accounts) ?></td>
                <td class="<?= $complete === $accounts ? 'good' : 'bad' ?>">
                    <?= number_format($complete) ?> with a PI and fund manager,
                    <?= number_format($withNetID) ?> with the PI's NetID</td>
                <td class="muted"><?= $lastAccounts ? h(date('M j, Y g:ia', strtotime($lastAccounts))) : 'never' ?></td>
            </tr>
            <tr>
                <td><strong>People</strong></td>
                <td><?= number_format($people) ?></td>
                <td class="muted">name, NetID, email, survey</td>
                <td class="muted"><?= $lastPeople ? h(date('M j, Y g:ia', strtotime($lastPeople))) : 'never' ?></td>
            </tr>
        </table>
    </div>

    <p class="worknote">Loading the accounts report and the employee file is done in one
    place, with everything else that comes from campus:
    <a href="admin.php?section=datafeeds">Data &rarr; Where data comes from</a>.</p>

    <div class="panel scroll-x">
        <h2>Look up an Account</h2>
        <form method="get" class="filters">
            <input type="hidden" name="section" value="accounts">
            <label>Account, description or PI
                <input name="filter" value="<?= h($filter) ?>" placeholder="1-594662, tollway, Dreslik">
            </label>
            <button>Search</button>
        </form>
        <?php if ($filter !== ''):
            $stmt = $shop->prepare(
                'SELECT TOP 40 * FROM ref.CfopAccounts
                 WHERE CfopCode LIKE ? OR Description LIKE ? OR AccountPI LIKE ? OR FundManager LIKE ?
                 ORDER BY CfopCode');
            $like = '%' . $filter . '%';
            $stmt->execute([$like, $like, $like, $like]);
            $rows = $stmt->fetchAll(); ?>
        <p class="muted"><?= count($rows) ?> match(es). Anything wrong here is corrected in the
        source report; an edit made below is overwritten by the next load.</p>
        <table>
            <tr><th>Account</th><th>Survey</th><th>PI</th><th>Fund manager</th>
                <th>Fund</th><th>Description</th><th>Ends</th><th></th></tr>
            <?php foreach ($rows as $r): ?>
            <tr>
                <form method="post" style="display:contents">
                    <?= csrf_field() ?>
                    <input type="hidden" name="cfop_code" value="<?= h((string)$r['CfopCode']) ?>">
                    <input type="hidden" name="filter" value="<?= h($filter) ?>">
                    <td><?= h((string)$r['CfopCode']) ?></td>
                    <td><input name="survey" size="5" value="<?= h((string)$r['SurveyCode']) ?>"></td>
                    <td><input name="account_pi" size="16" value="<?= h((string)$r['AccountPI']) ?>">
                        <?php if ($r['AccountPINetID']): ?>
                        <div class="muted"><?= h((string)$r['AccountPINetID']) ?></div><?php endif; ?></td>
                    <td><input name="fund_manager" size="16" value="<?= h((string)$r['FundManager']) ?>"></td>
                    <td><input name="fund_type" size="14" value="<?= h((string)$r['FundType']) ?>"></td>
                    <td><input name="description" size="28" value="<?= h((string)$r['Description']) ?>"></td>
                    <td class="muted"><?= h((string)$r['EndsOn']) ?></td>
                    <td><button name="action" value="cfop_edit">Save</button></td>
                </form>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php else: ?>
        <p class="muted">Search to see accounts.</p>
        <?php endif; ?>
    </div>
    <?php
}

// ---------------------------------------------- space and key requests
function av_requests2(): void
{
    $work = db('work');
    $show = (string)($_GET['show'] ?? 'open');
    $closed = "('closed','denied')";
    $where = $show === 'all' ? '' : " WHERE Status NOT IN $closed";
    ?>
    <div class="panel">
        <h2>Space and Key Requests</h2>
        <p class="muted">Filed on the front end by anybody, decided here by the survey's
        leadership. A granted space request is finished by assigning the room; a granted key
        request is finished by recording the key number.</p>
        <div class="tabs">
            <a href="admin.php?section=requests2&amp;show=open" class="<?= $show === 'open' ? 'active' : '' ?>">Open</a>
            <a href="admin.php?section=requests2&amp;show=all" class="<?= $show === 'all' ? 'active' : '' ?>">All</a>
        </div>
    </div>

    <?php foreach ([['space', 'Space Requests', 'rq.SpaceRequests', 'SpaceRequestID', 'work'],
                    ['key', 'Key Requests', 'rq.KeyRequests', 'KeyRequestID', 'keys']] as
                   [$type, $label, $table, $pk, $dbName]):
        try {
            $rows = db($dbName)->query("SELECT * FROM $table$where ORDER BY CreatedAt DESC")
                ->fetchAll();
        } catch (Throwable $e) {
            $rows = [];
        }
        $flow = status_labels($type);
    ?>
    <div class="panel scroll-x">
        <h2><?= h($label) ?> (<?= count($rows) ?>)</h2>
        <?php if (!$rows): ?>
        <p class="muted">None.</p>
        <?php else: ?>
        <table>
            <tr><th>Ticket</th><th>Filed by</th><th>Survey</th><th>What</th><th>Stage</th>
                <th>Waiting on</th><th>Filed</th><th></th></tr>
            <?php foreach ($rows as $r):
                $stage = rq_current_stage($type, (int)$r[$pk]); ?>
            <tr>
                <td><a href="request.php?type=<?= h($type) ?>&amp;id=<?= (int)$r[$pk] ?>">
                    <?= h((string)$r['TicketNumber']) ?></a></td>
                <td><?= h((string)$r['RequestedByNetID']) ?></td>
                <td><?= h((string)$r['SurveyCode']) ?></td>
                <td><?= h($type === 'space'
                        ? str_replace('_', ' ', (string)$r['RequestKind']) . ' — '
                          . mb_strimwidth((string)$r['Justification'], 0, 60, '…')
                        : (string)$r['HolderName'] . ' — ' . (string)$r['BldgNumber']
                          . ' ' . (string)$r['RoomID']) ?></td>
                <td><?= h($flow[$r['Status']]['Label'] ?? (string)$r['Status']) ?></td>
                <td><?= $stage ? '<strong>' . h(ucwords((string)$stage['StageCode'])) . '</strong>' : '' ?></td>
                <td class="muted"><?= h(date('M j', strtotime((string)$r['CreatedAt']))) ?></td>
                <td><a class="btn" href="request.php?type=<?= h($type) ?>&amp;id=<?= (int)$r[$pk] ?>">Open</a></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>
    <?php endforeach;
}

// ------------------------------------------------------------- stages
function av_stages(): void
{
    $work = db('work');
    $types = ['work' => 'Facilities work', 'shop' => 'Shop services', 'move' => 'Move',
              'space' => 'Space', 'key' => 'Key'];
    ?>
    <div class="panel">
        <h2>Stages</h2>
        <p class="muted">What each request type walks through, and who normally moves it on. The
        front end shows this chart to everybody at <a href="stages.php">stages.php</a>. Changing a
        label or a description here changes it everywhere; the codes themselves are wired into the
        application and are shown for reference.</p>
    </div>
    <?php foreach ($types as $type => $label):
        $stmt = $work->prepare('SELECT * FROM ref.StatusFlow WHERE RequestType = ? ORDER BY StageOrder');
        $stmt->execute([$type]);
        $rows = $stmt->fetchAll(); ?>
    <div class="panel scroll-x">
        <h3><?= h($label) ?></h3>
        <table>
            <tr><th>Order</th><th>Code</th><th>Label</th><th>Description</th><th>Usually moved on by</th>
                <th>Ends it</th><th>Tell the submitter</th><th></th></tr>
            <?php foreach ($rows as $r): ?>
            <tr>
                <form method="post" style="display:contents">
                    <?= csrf_field() ?>
                    <input type="hidden" name="request_type" value="<?= h($type) ?>">
                    <input type="hidden" name="status_code" value="<?= h((string)$r['StatusCode']) ?>">
                    <td><input name="stage_order" size="3" value="<?= (int)$r['StageOrder'] ?>"></td>
                    <td class="muted"><?= h((string)$r['StatusCode']) ?></td>
                    <td><input name="label" size="16" value="<?= h((string)$r['Label']) ?>"></td>
                    <td><input name="description" size="34" value="<?= h((string)$r['Description']) ?>"></td>
                    <td><input name="owner_hint" size="16" value="<?= h((string)$r['OwnerHint']) ?>"></td>
                    <td><input type="checkbox" name="is_terminal" value="1" <?= $r['IsTerminal'] ? 'checked' : '' ?>></td>
                    <td><input type="checkbox" name="notify_submitter" value="1" <?= $r['NotifySubmitter'] ? 'checked' : '' ?>></td>
                    <td><button name="action" value="stage_save">Save</button></td>
                </form>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endforeach;
}

// ------------------------------------------------------- back end access
function av_access(): void
{
    $work = db('work');
    $rows = $work->query('SELECT * FROM app.AdminAccess
                          ORDER BY SurveyCode, AccessFunction, NetID')->fetchAll();
    $surveys = $work->query('SELECT Code, Name FROM ref.Surveys WHERE IsActive = 1 ORDER BY Code')->fetchAll();
    // The back end now has a vehicles section and an equipment section,
    // so "facilities" no longer has to stand for both.
    $functions = ['director' => 'Director', 'proxy' => "Director's proxy",
                  'facilities' => 'Facilities staff', 'safety' => 'Safety staff',
                  'fleet' => 'Fleet and vehicles', 'equipment' => 'Equipment and property',
                  'inventory' => 'Inventory staff', 'it' => 'IT staff',
                  'finance' => 'Finance staff', 'deputy' => 'Deputy director',
                  'executive' => 'Executive director',
                  // Granted separately and to fewer people than the back
                  // end: this one may rewrite the application itself.
                  'studio' => 'Studio - may edit the source'];
    ?>
    <div class="panel">
        <h2>Who May See the Back End</h2>
        <p class="muted">Anyone can file a request. Only these NetIDs see this side of it.</p>
        <p>You are here as <strong><?= h(access_description()) ?></strong>.</p>
        <form method="post" class="grid">
            <?= csrf_field() ?>
            <label>NetID <input name="netid" required></label>
            <label>Survey
                <select name="survey_code">
                    <option value="">Every survey</option>
                    <?php foreach ($surveys as $s): ?>
                    <option value="<?= h((string)$s['Code']) ?>"><?= h($s['Code'] . ' — ' . $s['Name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Function
                <select name="function">
                    <?php foreach ($functions as $k => $label): ?>
                    <option value="<?= h($k) ?>"><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Note <input name="note" placeholder="why they have it"></label>
            <div class="actions" style="grid-column:1/-1">
                <button name="action" value="access_add" class="primary">Grant access</button>
            </div>
        </form>
    </div>

    <div class="panel scroll-x">
        <h2>Access list (<?= count($rows) ?>)</h2>
        <?php if (!$rows): ?>
        <p class="bad">Nobody is listed yet, so only administrators can see this side. Add the
        facilities and safety staff, directors and proxies for each survey.</p>
        <?php else: ?>
        <table>
            <tr><th>NetID</th><th>Survey</th><th>Function</th><th>Note</th><th>Active</th><th></th></tr>
            <?php foreach ($rows as $r): ?>
            <tr class="<?= $r['IsActive'] ? '' : 'muted' ?>">
                <td><?= h((string)$r['NetID']) ?></td>
                <td><?= h((string)($r['SurveyCode'] ?: 'every survey')) ?></td>
                <td><?= h($functions[$r['AccessFunction']] ?? (string)$r['AccessFunction']) ?></td>
                <td class="muted"><?= h((string)$r['Note']) ?></td>
                <td class="<?= $r['IsActive'] ? 'good' : '' ?>"><?= $r['IsActive'] ? 'yes' : 'no' ?></td>
                <td>
                    <form method="post" style="display:inline"><?= csrf_field() ?>
                        <input type="hidden" name="access_id" value="<?= (int)$r['AdminAccessID'] ?>">
                        <button name="action" value="access_toggle"><?= $r['IsActive'] ? 'Suspend' : 'Restore' ?></button>
                    </form>
                    <form method="post" style="display:inline"><?= csrf_field() ?>
                        <input type="hidden" name="access_id" value="<?= (int)$r['AdminAccessID'] ?>">
                        <button name="action" value="access_delete">Remove</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>
    <?php
}
