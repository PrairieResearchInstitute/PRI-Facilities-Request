<?php
declare(strict_types=1);

/**
 * Administration back end. Sections: queue actions (advance, close, hand
 * off to the campus F&S system), data tables, export, import with staging
 * and reversal, settings, reference publish and consistency, and the
 * example data loader used while evaluating the interface.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/refsync.php';
require_once __DIR__ . '/lib/csvio.php';
require_once __DIR__ . '/lib/scoring.php';
require_once __DIR__ . '/lib/tickets.php';
require_once __DIR__ . '/lib/notify.php';
require_once __DIR__ . '/lib/mapview.php';
require_once __DIR__ . '/lib/formviews.php';
require_once __DIR__ . '/lib/adminviews2.php';
require_once __DIR__ . '/lib/adminviews3.php';
require_once __DIR__ . '/lib/adminviews4.php';
require_once __DIR__ . '/lib/adminviews5.php';
require_once __DIR__ . '/lib/adminviews6.php';
require_once __DIR__ . '/lib/adminviews7.php';
require_once __DIR__ . '/lib/adminviews8.php';
require_once __DIR__ . '/lib/adminviews9.php';
require_once __DIR__ . '/lib/adminviews10.php';
require_once __DIR__ . '/lib/spaceadmin.php';

if (!app_installed()) {
    header('Location: install.php');
    exit;
}
handle_user_switch();
$user = current_user();
require_admin_access();

$section = (string)($_GET['section'] ?? 'queue');
$pdoWork = db('work');

// ---------------- actions ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = (string)($_POST['action'] ?? '');
    // One list screen holds every row of a list in a single form, so a
    // hidden remove_id per row all carried the same name and PHP kept the
    // last. Clicking Remove on any row asked about that row and deleted
    // the bottom one. The button now carries its own id in its value, and
    // a value only exists for the button that was pressed.
    if (isset($_POST['list_remove'])) {
        $action = 'list_remove';
    }

    if ($action === 'status') {
        $type = (string)$_POST['type'];
        $id = (int)$_POST['id'];
        $to = (string)$_POST['status'];
        $comment = trim((string)($_POST['comment'] ?? ''));
        $pdo = db($type);
        [$table, $pk] = ['work' => ['wr.WorkRequests', 'WorkRequestID'],
                         'move' => ['mv.MoveRequests', 'MoveRequestID'],
                         'shop' => ['sh.ShopRequests', 'ShopRequestID']][$type];
        $cur = $pdo->prepare("SELECT Status FROM $table WHERE $pk = ?");
        $cur->execute([$id]);
        $from = (string)$cur->fetchColumn();
        $closed = in_array($to, ['closed', 'complete', 'declined', 'denied', 'verified'], true);
        $pdo->prepare("UPDATE $table SET Status = ?, UpdatedAt = SYSDATETIME()"
            . ($closed ? ', ClosedAt = SYSDATETIME()' : '') . " WHERE $pk = ?")
            ->execute([$to, $id]);
        record_status($type, $id, $from, $to, ensure_user($type, $user), $comment ?: null);
        if ($type === 'work') {
            scoring_recompute($id);
        }
        // Whoever filed it hears about every move, and about closeout.
        $who = $pdo->prepare("SELECT TicketNumber, "
            . ($type === 'work' ? 'CreatedByNetID' : 'RequestedByNetID')
            . " AS Filer FROM $table WHERE $pk = ?");
        $who->execute([$id]);
        if ($row = $who->fetch()) {
            require_once __DIR__ . '/lib/requests2.php';
            rq_notify_submitter($type, $id, (string)$row['TicketNumber'], (string)$row['Filer'],
                $from, $to, $comment);
        }
        flash_set('ok', 'Status changed to ' . nice_code($to)
            . '. The person who filed it has been told.');

    } elseif ($action === 'handoff') {
        // Transfer to the campus Facilities and Services work management
        // system. The ticket stays visible here so duplicate suppression
        // keeps working (spec Section 2); the F&S reference is recorded.
        $id = (int)$_POST['id'];
        $ref = trim((string)($_POST['handoff_reference'] ?? ''));
        $system = trim((string)($_POST['handoff_system'] ?? 'UIUC Facilities and Services'));
        $cur = $pdoWork->prepare('SELECT Status FROM wr.WorkRequests WHERE WorkRequestID = ?');
        $cur->execute([$id]);
        $from = (string)$cur->fetchColumn();
        $pdoWork->prepare('UPDATE wr.WorkRequests SET Status = \'handed_off\', HandoffReference = ?,
                           HandoffSystem = ?, HandoffAt = SYSDATETIME(), UpdatedAt = SYSDATETIME()
                           WHERE WorkRequestID = ?')
            ->execute([$ref ?: null, $system, $id]);
        record_status('work', $id, $from, 'handed_off', ensure_user('work', $user),
            'Transferred to ' . $system . ($ref ? ' as ' . $ref : ''));
        flash_set('ok', 'Request transferred to ' . $system . ($ref ? ' (' . $ref . ')' : '') . '.');

    } elseif ($action === 'triage') {
        require_role('coordinator');
        $id = (int)$_POST['id'];
        $sev = (int)$_POST['triaged_severity'];
        $est = trim((string)($_POST['estimate'] ?? ''));
        $pdoWork->prepare('UPDATE wr.WorkRequests SET TriagedSeverity = ?, EstimateAmount = ?,
                           TriagedAt = SYSDATETIME(), UpdatedAt = SYSDATETIME() WHERE WorkRequestID = ?')
            ->execute([$sev ?: null, $est !== '' ? (float)$est : null, $id]);
        // Funding gate (spec Section 9): stages open only when money crosses a threshold.
        if ($est !== '') {
            $amount = (float)$est;
            $lower = (float)setting('estimate_approval_threshold');
            $upper = (float)setting('director_approval_threshold');
            $existing = $pdoWork->prepare('SELECT COUNT(*) FROM wr.Approvals WHERE WorkRequestID = ?');
            $existing->execute([$id]);
            if ((int)$existing->fetchColumn() === 0 && $amount > $lower) {
                $ins = $pdoWork->prepare('INSERT INTO wr.Approvals (WorkRequestID, StageCode, StageOrder) VALUES (?, ?, ?)');
                $ins->execute([$id, 'fund_manager', 1]);
                if ($amount > $upper) {
                    $ins->execute([$id, 'director', 2]);
                }
                $pdoWork->prepare('UPDATE wr.WorkRequests SET Status = \'awaiting_approval\' WHERE WorkRequestID = ?')
                    ->execute([$id]);
                record_status('work', $id, 'triaged', 'awaiting_approval', ensure_user('work', $user),
                    'Estimate $' . number_format($amount, 2) . ' crossed the approval threshold');
            }
        }
        scoring_recompute($id);
        flash_set('ok', 'Triage saved.');

    } elseif ($action === 'setting') {
        require_role('admin');
        $key = (string)$_POST['key'];
        $value = (string)$_POST['value'];
        foreach (APP_DB_NAMES as $n) {
            db($n)->prepare('UPDATE app.Settings SET SettingValue = ? WHERE SettingKey = ?')
                  ->execute([$value, $key]);
        }
        flash_set('ok', 'Setting ' . $key . ' set to ' . $value . ' in all three databases.');

    } elseif ($action === 'publish') {
        require_role('admin');
        $_SESSION['publish_report'] = refsync_publish(null);

    } elseif ($action === 'import_stage') {
        require_role('admin');
        $_SESSION['import'] = import_stage();

    } elseif ($action === 'import_commit') {
        require_role('admin');
        flash_set('ok', import_commit((int)$_POST['batch_id'], (string)$_POST['dataset']));

    } elseif ($action === 'import_reverse') {
        require_role('admin');
        flash_set('ok', import_reverse((int)$_POST['batch_id'], (string)$_POST['dataset']));

    } elseif ($action === 'gis_load') {
        require_role('admin');
        try {
            $rep = gis_load_all();
            $parts = [];
            foreach ($rep as $t => $n) {
                if (is_array($n)) {
                    $inner = [];
                    foreach ($n as $k => $v) {
                        $inner[] = $k . ' ' . (is_array($v) ? json_encode($v) : $v);
                    }
                    $parts[] = $t . ': ' . implode(', ', $inner);
                } else {
                    $parts[] = $t . ': ' . ($n < 0 ? 'no extract file' : $n . ' rows');
                }
            }
            flash_set('ok', 'Space data loaded. ' . implode('; ', $parts));
        } catch (Throwable $e) {
            flash_set('err', 'Load failed: ' . $e->getMessage());
        }

    } elseif ($action === 'bldg_add' || $action === 'bldg_edit') {
        require_role('admin');
        flash_set('ok', building_save($_POST, $action === 'bldg_add', $user['NetID']));
        header('Location: admin.php?section=space');
        exit;

    } elseif ($action === 'bldg_active') {
        require_role('admin');
        flash_set('ok', building_set_active((string)$_POST['bldg_number'], $user['NetID']));
        header('Location: admin.php?section=space');
        exit;

    } elseif ($action === 'room_add') {
        require_role('admin');
        flash_set('ok', room_add($_POST, $user['NetID']));
        header('Location: admin.php?section=space&bldg=' . urlencode(pad_bldg((string)$_POST['bldg_number'])));
        exit;

    } elseif ($action === 'room_remove') {
        require_role('admin');
        flash_set('ok', room_remove((string)$_POST['single_room'], $user['NetID']));
        header('Location: admin.php?section=space&bldg=' . urlencode(pad_bldg((string)$_POST['bldg_number'])));
        exit;

    } elseif ($action === 'rooms_move') {
        require_role('admin');
        flash_set('ok', rooms_move((array)($_POST['room_codes'] ?? []), (string)$_POST['to_bldg'],
            !empty($_POST['retire_source']), $user['NetID']));
        header('Location: admin.php?section=space&bldg=' . urlencode((string)$_POST['to_bldg']));
        exit;

    } elseif ($action === 'user_add') {
        require_role('admin');
        flash_set('ok', user_add($_POST, $user['NetID']));
        header('Location: admin.php?section=people');
        exit;

    } elseif ($action === 'user_role') {
        require_role('admin');
        flash_set('ok', user_set_role((string)$_POST['netid'], (string)$_POST['role'], $user['NetID']));
        header('Location: admin.php?section=people');
        exit;

    } elseif ($action === 'user_active') {
        require_role('admin');
        flash_set('ok', user_toggle_active((string)$_POST['netid'], $user['NetID']));
        header('Location: admin.php?section=people');
        exit;

    } elseif ($action === 'room_rekey') {
        require_role('coordinator');
        flash_set('ok', room_rekey((string)$_POST['room_code'], (string)($_POST['new_code'] ?? ''),
            (string)($_POST['reason'] ?? ''), $user['NetID']));
        header('Location: admin.php?section=keys');
        exit;

    } elseif ($action === 'key_issue') {
        require_role('coordinator');
        flash_set('ok', keys_issue($_POST, $user['NetID']));
        header('Location: admin.php?section=keys');
        exit;

    } elseif ($action === 'key_edit') {
        require_role('coordinator');
        flash_set('ok', keys_edit((int)$_POST['key_id'], $_POST, $user['NetID']));
        header('Location: admin.php?section=keys');
        exit;

    } elseif ($action === 'key_state') {
        require_role('coordinator');
        flash_set('ok', keys_set_state((int)$_POST['key_id'], (string)$_POST['key_state'],
            trim((string)($_POST['note'] ?? '')), $user['NetID']));
        header('Location: admin.php?section=keys');
        exit;

    /* equip_ingest, equip_reassign and equip_remove used to live here.
       They are new_property.php now - one form, one request, one history.
       equip_apply_move stays, because that is a move request applying
       itself to many records at once rather than a person editing one. */
    } elseif ($action === 'equip_apply_move') {
        require_role('coordinator');
        $r = equipment_apply_move((int)$_POST['move_id'], $user['NetID']);
        flash_set('ok', 'Move applied to the equipment records: ' . $r['moved'] . ' moved, '
            . $r['retired'] . ' retired, ' . $r['unmatched'] . ' of ' . $r['items']
            . ' item(s) had no matching property tag.');
        header('Location: detail.php?type=move&id=' . (int)$_POST['move_id']);
        exit;

    } elseif ($action === 'room_assign') {
        require_role('coordinator');
        flash_set('ok', room_assign((string)$_POST['room_code'], $_POST, $user['NetID']));
        header('Location: admin.php?section=roomassign&room_code=' . urlencode((string)$_POST['room_code']));
        exit;

    } elseif ($action === 'occupant_add') {
        require_role('coordinator');
        flash_set('ok', room_occupant_add((string)$_POST['room_code'], $_POST, $user['NetID']));
        header('Location: admin.php?section=roomassign&room_code=' . urlencode((string)$_POST['room_code']));
        exit;

    } elseif ($action === 'occupant_remove') {
        require_role('coordinator');
        flash_set('ok', room_occupant_remove((int)$_POST['occupant_id'], $user['NetID']));
        header('Location: admin.php?section=roomassign');
        exit;

    } elseif ($action === 'cfop_upload' || $action === 'people_upload') {
        require_role('admin');
        require_once __DIR__ . '/lib/cfoppaste.php';
        require_once __DIR__ . '/lib/peopleimport.php';
        if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            flash_set('err', 'No file was uploaded.');
        } else {
            $res = $action === 'cfop_upload'
                ? cfop_import_file($_FILES['file']['tmp_name'], $user['NetID'])
                : people_import_file($_FILES['file']['tmp_name'], $user['NetID']);
            if (isset($res['error'])) {
                flash_set('err', $res['error']);
            } elseif ($action === 'cfop_upload') {
                flash_set('ok', 'Accounts loaded from the ' . $res['mode'] . ': ' . $res['added']
                    . ' added, ' . $res['updated'] . ' updated'
                    . ($res['incomplete'] ? ', ' . $res['incomplete']
                        . ' still lacking a PI or fund manager' : ', all with a PI and fund manager')
                    . '.');
            } else {
                flash_set('ok', 'People loaded: ' . $res['added'] . ' added, ' . $res['updated']
                    . ' updated, ' . $res['total'] . ' in the directory.');
            }
        }
        header('Location: admin.php?section=accounts');
        exit;

    } elseif ($action === 'people_paste') {
        require_role('admin');
        require_once __DIR__ . '/lib/peopleimport.php';
        $res = people_import((string)($_POST['people_blob'] ?? ''), $user['NetID']);
        flash_set(isset($res['error']) ? 'err' : 'ok', $res['error']
            ?? ('People loaded: ' . $res['added'] . ' added, ' . $res['updated'] . ' updated.'));
        header('Location: admin.php?section=accounts');
        exit;

    } elseif ($action === 'cfop_paste') {
        require_role('admin');
        require_once __DIR__ . '/lib/cfoppaste.php';
        $res = cfop_import((string)($_POST['cfop_blob'] ?? ''), $user['NetID']);
        if (isset($res['error'])) {
            flash_set('err', $res['error']);
        } else {
            flash_set('ok', 'Accounts loaded from ' . $res['mode'] . ': ' . $res['added']
                . ' added, ' . $res['updated'] . ' updated.'
                . ($res['incomplete'] ? ' ' . $res['incomplete']
                    . ' account(s) still lack a PI or fund manager.' : ''));
        }
        header('Location: admin.php?section=accounts');
        exit;

    } elseif ($action === 'cfop_edit') {
        require_role('coordinator');
        db('shop')->prepare(
            'UPDATE ref.CfopAccounts SET SurveyCode = ?, AccountPI = ?, FundManager = ?,
                Description = ?, FundType = ?, UpdatedAt = SYSDATETIME() WHERE CfopCode = ?'
        )->execute([
            trim((string)($_POST['survey'] ?? '')) ?: null,
            trim((string)($_POST['account_pi'] ?? '')),
            trim((string)($_POST['fund_manager'] ?? '')),
            trim((string)($_POST['description'] ?? '')) ?: null,
            trim((string)($_POST['fund_type'] ?? '')) ?: null,
            (string)$_POST['cfop_code'],
        ]);
        flash_set('ok', 'Account ' . $_POST['cfop_code'] . ' updated.');
        header('Location: admin.php?section=accounts&filter=' . urlencode((string)($_POST['filter'] ?? '')));
        exit;

    } elseif ($action === 'unit_contact_save') {
        require_role('admin');
        require_once __DIR__ . '/lib/unitcontacts.php';
        unit_contact_save((string)$_POST['unit_code'], (string)$_POST['role_key'],
                          (string)($_POST['contact_name'] ?? ''),
                          (string)($_POST['contact_netid'] ?? ''),
                          (string)($_POST['contact_email'] ?? ''), $user['NetID']);
        flash_set('ok', 'Saved. The forms show this to people in that unit from now on.');
        header('Location: admin.php?section=people');
        exit;

    } elseif ($action === 'list_save') {
        require_role('admin');
        require_once __DIR__ . '/lib/picklists.php';
        $n = picklist_save((string)$_POST['list_key'], (array)($_POST['label'] ?? []),
                           (array)($_POST['sort'] ?? []), (array)($_POST['active'] ?? []),
                           $user['NetID']);
        flash_set('ok', $n . ' entr' . ($n === 1 ? 'y' : 'ies') . ' saved. The forms offer the '
                      . 'new wording immediately.');
        header('Location: admin.php?section=lists&list=' . urlencode((string)$_POST['list_key']));
        exit;

    } elseif ($action === 'list_add') {
        require_role('admin');
        require_once __DIR__ . '/lib/picklists.php';
        $err = picklist_add((string)$_POST['list_key'], (string)($_POST['new_code'] ?? ''),
                            (string)($_POST['new_label'] ?? ''),
                            (int)($_POST['new_sort'] ?? 50), $user['NetID']);
        flash_set($err ? 'err' : 'ok', $err ?: 'Added, and offered on the form from now on.');
        header('Location: admin.php?section=lists&list=' . urlencode((string)$_POST['list_key']));
        exit;

    } elseif ($action === 'list_remove') {
        require_role('admin');
        require_once __DIR__ . '/lib/picklists.php';
        // The pressed button carries the id; remove_id is what the server
        // confirm page posts back on its second pass.
        $removeId = (int)($_POST['list_remove'] ?? $_POST['remove_id'] ?? 0);
        // data-confirm asks in the browser; this asks when it cannot.
        if (empty($_POST['confirmed'])) {
            ui_confirm_page('Remove From List', 'Remove this entry from the list?', 'Remove',
                ['action' => 'list_remove', 'remove_id' => $removeId,
                 'list_key' => (string)($_POST['list_key'] ?? '')],
                'admin.php?section=lists&list=' . urlencode((string)($_POST['list_key'] ?? '')), true);
        }
        $err = picklist_remove($removeId, $user['NetID']);
        flash_set($err ? 'err' : 'ok', $err ?: 'Removed from the list.');
        header('Location: admin.php?section=lists&list=' . urlencode((string)$_POST['list_key']));
        exit;

    } elseif ($action === 'ds_upload') {
        require_role('admin');
        $files = $_FILES['files'] ?? null;
        $ok = $err = [];
        for ($i = 0; $i < count((array)($files['tmp_name'] ?? [])); $i++) {
            if (!is_uploaded_file((string)$files['tmp_name'][$i])) {
                continue;
            }
            $res = ds_load_file((string)$files['tmp_name'][$i], (string)$files['name'][$i],
                                $user['NetID'], 'upload');
            if (isset($res['error'])) {
                $err[] = $res['error'];
            } else {
                $ok[] = $res['message'];
            }
        }
        if (!$ok && !$err) {
            flash_set('err', 'No file was uploaded.');
        } else {
            flash_set($err && !$ok ? 'err' : 'ok',
                      implode(' ', array_merge($ok, $err)));
        }
        header('Location: admin.php?section=datafeeds');
        exit;

    } elseif ($action === 'ds_source_edit') {
        require_role('admin');
        db('work')->prepare(
            'UPDATE ref.DataSources SET SourceUrl = ?, RefreshDays = ?, FilePattern = ?
             WHERE SourceKey = ?'
        )->execute([
            trim((string)($_POST['source_url'] ?? '')) ?: null,
            max(1, min(365, (int)($_POST['refresh_days'] ?? 30))),
            trim((string)($_POST['file_pattern'] ?? '')) ?: null,
            (string)$_POST['source_key'],
        ]);
        flash_set('ok', 'Saved. The refresh screen and the desktop script both read this, so the '
                      . 'scheduled task will follow the new address without being edited.');
        header('Location: admin.php?section=datafeeds');
        exit;

    } elseif ($action === 'ds_token_new') {
        require_role('admin');
        ds_token_clear();
        $tok = ds_token(true);
        flash_set('ok', 'A new key was generated. Any scheduled task still using the old one will '
                      . 'stop working until it is given this: ' . $tok);
        header('Location: admin.php?section=datafeeds');
        exit;

    } elseif ($action === 'ds_token_clear') {
        require_role('admin');
        if (empty($_POST['confirmed'])) {
            ui_confirm_page('Clear the Key',
                'Clear the key? Any scheduled task using it will stop working.', 'Clear Key',
                ['action' => 'ds_token_clear'], 'admin.php?section=datafeeds', true);
        }
        ds_token_clear();
        flash_set('ok', 'Unattended loading is off. Exports can still be dropped here by hand.');
        header('Location: admin.php?section=datafeeds');
        exit;

    } elseif ($action === 'org_upload') {
        require_role('admin');
        require_once __DIR__ . '/lib/orgimport.php';
        if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            flash_set('err', 'No file was uploaded.');
        } else {
            $res = org_import($_FILES['file']['tmp_name'], true, $user['NetID']);
            flash_set(isset($res['error']) ? 'err' : 'ok', $res['error']
                ?? ('Org chart loaded: ' . $res['people'] . ' rows, ' . $res['added'] . ' people added, '
                    . $res['updated'] . ' updated, ' . $res['units'] . ' unit rows built. '
                    . 'The suggestions below are not applied until you apply them.'));
        }
        header('Location: admin.php?section=orgchart');
        exit;

    } elseif ($action === 'org_set_director' || $action === 'org_set_proxy') {
        require_role('admin');
        $isDirector = $action === 'org_set_director';
        $name = trim((string)($_POST['person_name'] ?? ''));
        $email = trim((string)($_POST['person_email'] ?? ''));
        $code = (string)($_POST['survey_code'] ?? '');
        // Surveys live in every database, so all three are written; the
        // survey code is the natural key that ties them together.
        foreach (['work', 'move', 'shop'] as $conn) {
            db($conn)->prepare($isDirector
                ? 'UPDATE ref.Surveys SET DirectorName = ?, DirectorEmail = ? WHERE Code = ?'
                : 'UPDATE ref.Surveys SET ProxyName = ?, ProxyEmail = ? WHERE Code = ?'
            )->execute([$name, $email, $code]);
        }
        flash_set('ok', $name . ' is now the ' . ($isDirector ? 'director' : "director's proxy")
            . ' for ' . $code . '. Notifications and approvals will route to ' . $email . '.');
        header('Location: admin.php?section=orgchart');
        exit;

    } elseif ($action === 'org_grant_access') {
        require_role('admin');
        $granted = [];
        foreach ((array)($_POST['grant'] ?? []) as $netid) {
            $netid = trim((string)$netid);
            if ($netid === '') {
                continue;
            }
            $fn = trim((string)($_POST['function_' . $netid] ?? 'proxy'));
            $row = db('work')->prepare('SELECT DisplayName, SurveyCode, OrgRole FROM ref.People WHERE NetID = ?');
            $row->execute([$netid]);
            $p = $row->fetch() ?: ['DisplayName' => $netid, 'SurveyCode' => null, 'OrgRole' => ''];
            $note = trim((string)$p['DisplayName']) . ' — org chart says "'
                  . (string)$p['OrgRole'] . '", granted by ' . $user['NetID'];
            $exists = db('work')->prepare('SELECT COUNT(*) FROM app.AdminAccess WHERE NetID = ?');
            $exists->execute([$netid]);
            if ((int)$exists->fetchColumn() > 0) {
                db('work')->prepare(
                    'UPDATE app.AdminAccess SET IsActive = 1, AccessFunction = ?, Note = ?
                     WHERE NetID = ?')->execute([$fn, $note, $netid]);
            } else {
                db('work')->prepare(
                    'INSERT INTO app.AdminAccess (NetID, SurveyCode, AccessFunction, Note,
                        IsActive, GrantedAt, GrantedByNetID)
                     VALUES (?, ?, ?, ?, 1, SYSDATETIME(), ?)'
                )->execute([$netid, $p['SurveyCode'], $fn, $note, $user['NetID']]);
            }
            $granted[] = $netid;
        }
        flash_set($granted ? 'ok' : 'err', $granted
            ? count($granted) . ' person(s) granted back end access: ' . implode(', ', $granted) . '.'
            : 'Nobody was ticked, so nothing was granted.');
        header('Location: admin.php?section=orgchart');
        exit;

    } elseif ($action === 'people_reload') {
        require_role('admin');
        require_once __DIR__ . '/lib/gisload.php';
        $n = gis_load_people();
        flash_set($n < 0 ? 'err' : 'ok', $n < 0
            ? 'No roster export found. Run tools\gis_etl.py first.'
            : $n . ' people loaded from the C3PO export, each with a NetID.');
        header('Location: admin.php?section=accounts');
        exit;

    } elseif ($action === 'keys_paste') {
        require_role('admin');
        require_once __DIR__ . '/lib/keyspaste.php';
        $res = keys_import((string)($_POST['keys_blob'] ?? ''));
        if (isset($res['error'])) {
            flash_set('err', $res['error']);
        } else {
            flash_set('ok', 'Key assignments loaded: ' . $res['loaded'] . ' row(s), '
                . $res['linked'] . ' linked to a mapped room'
                . ($res['unresolved_building'] ? ', ' . $res['unresolved_building']
                    . ' with a building name that did not resolve' : '')
                . '. Columns recognised: ' . implode(', ', $res['columns']) . '.');
        }
        header('Location: admin.php?section=map&panel=data');
        exit;

    } elseif ($action === 'fleet_import') {
        require_role('admin');
        flash_set('ok', fleet_import());

    } elseif ($action === 'fleet_toggle') {
        require_role('admin');
        db('fleet')->prepare('UPDATE ref.FleetVehicles SET IsActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END
                             WHERE FleetVehicleID = ?')->execute([(int)$_POST['id']]);
        flash_set('ok', 'Vehicle updated.');

    } elseif ($action === 'access_add') {
        require_role('admin');
        $netid = strtolower(trim((string)$_POST['netid']));
        try {
            db('work')->prepare(
                'INSERT INTO app.AdminAccess (NetID, SurveyCode, AccessFunction, Note, GrantedByNetID)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$netid, trim((string)($_POST['survey_code'] ?? '')) ?: null,
                        (string)$_POST['function'], trim((string)($_POST['note'] ?? '')) ?: null,
                        $user['NetID']]);
            flash_set('ok', $netid . ' may now see the back end.');
        } catch (Throwable $e) {
            flash_set('err', $netid . ' already holds that access.');
        }
        header('Location: admin.php?section=access');
        exit;

    } elseif ($action === 'access_toggle') {
        require_role('admin');
        db('work')->prepare('UPDATE app.AdminAccess
                             SET IsActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END
                             WHERE AdminAccessID = ?')->execute([(int)$_POST['access_id']]);
        flash_set('ok', 'Access updated.');
        header('Location: admin.php?section=access');
        exit;

    } elseif ($action === 'access_delete') {
        require_role('admin');
        db('work')->prepare('DELETE FROM app.AdminAccess WHERE AdminAccessID = ?')
            ->execute([(int)$_POST['access_id']]);
        flash_set('ok', 'Access removed.');
        header('Location: admin.php?section=access');
        exit;

    } elseif ($action === 'stage_save') {
        require_role('admin');
        foreach (APP_DB_NAMES as $n) {
            try {
                db($n)->prepare(
                    'UPDATE ref.StatusFlow SET StageOrder = ?, Label = ?, Description = ?,
                        OwnerHint = ?, IsTerminal = ?, NotifySubmitter = ?
                     WHERE RequestType = ? AND StatusCode = ?'
                )->execute([(int)$_POST['stage_order'], (string)$_POST['label'],
                            trim((string)($_POST['description'] ?? '')) ?: null,
                            trim((string)($_POST['owner_hint'] ?? '')) ?: null,
                            isset($_POST['is_terminal']) ? 1 : 0,
                            isset($_POST['notify_submitter']) ? 1 : 0,
                            (string)$_POST['request_type'], (string)$_POST['status_code']]);
            } catch (Throwable $e) { /* table absent in that database */ }
        }
        flash_set('ok', 'Stage updated in all three databases.');
        header('Location: admin.php?section=stages');
        exit;

    } elseif ($action === 'digest_run' || $action === 'digest_run_force') {
        require_role('admin');
        require_once __DIR__ . '/lib/digest.php';
        $r = digest_run(500, $action === 'digest_run_force');
        flash_set('ok', count($r['sent']) . ' digest(s) generated, ' . $r['skipped']
            . ' skipped as not due or with nothing outstanding. Delivery is '
            . setting('notify_mode', 'log') . '.');
        header('Location: admin.php?section=people');
        exit;

    } elseif ($action === 'smtp_save') {
        require_role('admin');
        require_once __DIR__ . '/lib/smtp.php';
        flash_set('ok', smtp_save_config($_POST));
        header('Location: admin.php?section=people');
        exit;

    } elseif ($action === 'smtp_test') {
        require_role('admin');
        require_once __DIR__ . '/lib/smtp.php';
        $to = trim((string)($_POST['test_to'] ?? ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            flash_set('err', 'That is not a valid email address.');
        } elseif (!smtp_configured()) {
            flash_set('err', 'Set the SMTP host and the send-as address first.');
        } else {
            $log = [];
            $ok = smtp_send([$to],
                'PRIOps test',
                "This is a test from PRIOps.\n\n"
                . 'Sent by ' . current_user()['DisplayName'] . ' at ' . date('r') . ".\n"
                . "If this arrived, set notify_mode to smtp in Settings and notices will go out.\n",
                $log);
            // The conversation is the useful part when it fails.
            flash_set($ok ? 'ok' : 'err', $ok
                ? 'Test accepted by the server for ' . $to . '. Check the mailbox.'
                : 'The server refused it: ' . mb_substr(implode('  ', array_slice($log, -8)), 0, 700));
        }
        header('Location: admin.php?section=people');
        exit;

    } elseif ($action === 'rule_add') {
        require_role('admin');
        $email = trim((string)($_POST['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash_set('err', 'That is not a valid email address.');
        } else {
            db('work')->prepare(
                'INSERT INTO app.NotifyRules (EventCode, MatchType, MatchValue, Email,
                    DisplayName, Note, CreatedByNetID)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([(string)$_POST['event_code'], (string)$_POST['match_type'],
                        trim((string)($_POST['match_value'] ?? '')) ?: null, $email,
                        trim((string)($_POST['display_name'] ?? '')) ?: null,
                        trim((string)($_POST['note'] ?? '')) ?: null, $user['NetID']]);
            flash_set('ok', $email . ' added.');
        }
        header('Location: admin.php?section=people');
        exit;

    } elseif ($action === 'rule_toggle') {
        require_role('admin');
        db('work')->prepare('UPDATE app.NotifyRules
                             SET IsActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END
                             WHERE RuleID = ?')->execute([(int)$_POST['rule_id']]);
        flash_set('ok', 'Recipient updated.');
        header('Location: admin.php?section=people');
        exit;

    } elseif ($action === 'rule_delete') {
        require_role('admin');
        db('work')->prepare('DELETE FROM app.NotifyRules WHERE RuleID = ?')
            ->execute([(int)$_POST['rule_id']]);
        flash_set('ok', 'Recipient removed.');
        header('Location: admin.php?section=people');
        exit;

    } elseif ($action === 'survey_contacts') {
        require_role('admin');
        foreach (APP_DB_NAMES as $n) {
            db($n)->prepare(
                'UPDATE ref.Surveys SET DirectorName = ?, DirectorEmail = ?, ProxyName = ?,
                    ProxyEmail = ?, NotifyDirector = ? WHERE Code = ?'
            )->execute([
                trim((string)($_POST['director_name'] ?? '')) ?: null,
                trim((string)($_POST['director_email'] ?? '')) ?: null,
                trim((string)($_POST['proxy_name'] ?? '')) ?: null,
                trim((string)($_POST['proxy_email'] ?? '')) ?: null,
                isset($_POST['notify_director']) ? 1 : 0,
                (string)$_POST['survey_code'],
            ]);
        }
        flash_set('ok', 'Leadership for ' . (string)$_POST['survey_code'] . ' saved in all three databases.');
        header('Location: admin.php?section=people');
        exit;

    } elseif ($action === 'notify_preview') {
        $ctx = [
            'survey'       => trim((string)($_POST['test_survey'] ?? '')),
            'request_kind' => trim((string)($_POST['test_kind'] ?? '')),
            'severity'     => (int)($_POST['test_severity'] ?? 0),
        ];
        $to = notify_audience((string)$_POST['test_event'], $ctx);
        $reasons = $GLOBALS['notify_last_reasons'] ?? [];
        if (!$to) {
            flash_set('err', 'Nobody would be notified for that combination.');
        } else {
            $parts = [];
            foreach ($to as $addr) {
                $parts[] = $addr . ' — ' . ($reasons[strtolower($addr)] ?? 'named on the request');
            }
            flash_set('ok', count($to) . ' recipient(s): ' . implode('; ', $parts));
        }
        header('Location: admin.php?section=people');
        exit;

    } elseif ($action === 'notify_test') {
        require_role('admin');
        $to = notify_recipients((string)$_POST['list_key']);
        if (!$to) {
            flash_set('err', 'That recipient list is empty. Add addresses in Settings first.');
        } else {
            notify_send('work', 'test', $to, 'PRIOps test notification',
                "This is a test notification from PRIOps.\n\n"
                . 'Sent by ' . $user['DisplayName'] . ' at ' . date('c') . ".\n"
                . 'Delivery mode: ' . setting('notify_mode', 'log') . '.');
            flash_set('ok', 'Test notification recorded for: ' . implode(', ', $to));
        }

    } elseif ($action === 'property_apply') {
        require_function(['equipment', 'inventory']);
        require_once __DIR__ . '/lib/property.php';
        $err = property_apply((int)($_POST['pu_id'] ?? 0), $user,
                              (string)($_POST['note'] ?? ''));
        flash_set($err ? 'err' : 'ok', $err ?: 'Applied. The inventory says so now, and it is on '
                                             . "that item's history.");

    } elseif ($action === 'property_decline') {
        require_function(['equipment', 'inventory']);
        require_once __DIR__ . '/lib/property.php';
        $err = property_decline((int)($_POST['pu_id'] ?? 0), (string)($_POST['note'] ?? ''), $user);
        flash_set($err ? 'err' : 'ok', $err ?: 'Declined, and the person who filed it has been told '
                                             . 'why.');

    } elseif ($action === 'pur_order') {
        require_function(['finance', 'facilities', 'executive', 'deputy']);
        require_once __DIR__ . '/lib/purchase.php';
        $prId = (int)($_POST['pr_id'] ?? 0);
        db('purchasing')->prepare('UPDATE rq.PurchaseRequests SET OrderReference = ?,
                                 OrderedAt = SYSDATETIME(), UpdatedAt = SYSDATETIME()
                             WHERE PurchaseRequestID = ?')
            ->execute([trim((string)($_POST['order_reference'] ?? '')) ?: null, $prId]);
        $err = purchase_set_status($prId, 'ordered',
                   'Order ' . (string)($_POST['order_reference'] ?? ''), $user);
        flash_set($err ? 'err' : 'ok', $err ?: 'Recorded as ordered.');

    } elseif ($action === 'pur_status') {
        require_function(['finance', 'facilities', 'executive', 'deputy']);
        require_once __DIR__ . '/lib/purchase.php';
        $err = purchase_set_status((int)($_POST['pr_id'] ?? 0), (string)($_POST['to'] ?? ''),
                                   (string)($_POST['note'] ?? ''), $user);
        flash_set($err ? 'err' : 'ok', $err ?: 'Recorded, and the person who filed it has been told.');

    } elseif ($action === 'pur_nudge') {
        require_function(['finance', 'facilities', 'executive', 'deputy']);
        require_once __DIR__ . '/lib/purchase.php';
        $prId = (int)($_POST['pr_id'] ?? 0);
        $req = purchase_get($prId);
        if ($req && $req['Status'] === 'finance_review') {
            purchase_notify_finance($prId);
        } else {
            purchase_notify_pending($prId);
        }
        purchase_event($prId, null, 'nudged', 'Asked again by '
            . ($user['DisplayName'] ?? $user['NetID']), $user);
        flash_set('ok', 'Asked again. Nothing else changed.');

    } elseif ($action === 'pur_seat') {
        require_function(['finance', 'facilities', 'executive', 'deputy']);
        require_once __DIR__ . '/lib/purchase.php';
        $err = purchase_set_seat((int)($_POST['pr_id'] ?? 0), (int)($_POST['funding_id'] ?? 0),
                                 (string)($_POST['seat'] ?? ''), (string)($_POST['person'] ?? ''),
                                 (string)($_POST['person_netid'] ?? ''), $user);
        flash_set($err ? 'err' : 'ok', $err ?: 'Changed, and they have been asked.');

    } elseif ($action === 'pur_onbehalf') {
        require_function(['finance', 'facilities', 'executive', 'deputy']);
        require_once __DIR__ . '/lib/purchase.php';
        $err = purchase_decide_on_behalf((int)($_POST['pr_id'] ?? 0),
                   (int)($_POST['funding_id'] ?? 0), (string)($_POST['seat'] ?? ''),
                   (string)($_POST['decision'] ?? ''), (string)($_POST['comment'] ?? ''), $user);
        flash_set($err ? 'err' : 'ok', $err ?: 'Recorded on their behalf, with your name on it.');

    } elseif ($action === 'examples_load') {
        require_role('admin');
        require_once __DIR__ . '/lib/examples.php';
        flash_set('ok', examples_load());

    } elseif ($action === 'examples_clear') {
        require_role('admin');
        require_once __DIR__ . '/lib/examples.php';
        flash_set('ok', examples_clear());
    }
    header('Location: admin.php?section=' . urlencode($section));
    exit;
}

// ---------------- import helpers ----------------
function import_stage(): array
{
    $dataset = (string)($_POST['dataset'] ?? 'work');
    $dbName = $dataset;
    if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        return ['error' => 'No file was uploaded.'];
    }
    $storeDir = storage_dir('imports');
    if (!is_dir($storeDir)) {
        mkdir($storeDir, 0770, true);
    }
    $stored = $storeDir . DIRECTORY_SEPARATOR . date('Ymd_His') . '_' . basename($_FILES['file']['name']);
    move_uploaded_file($_FILES['file']['tmp_name'], $stored);

    [$header, $rows] = csv_parse($stored);
    $pdo = db($dbName);
    $uid = ensure_user($dbName);
    // StoredPath keeps the path relative to the storage folder ("imports/<file>"),
    // never the drive letter of the day; storage_from_stored() reads it back.
    $pdo->prepare('INSERT INTO app.ImportBatches (SourceSystem, FileName, StoredPath, RowsRead, Status, LoadedByUserID)
                   VALUES (?, ?, ?, ?, \'staged\', ?)')
        ->execute([(string)($_POST['source_system'] ?? 'csv'), basename($_FILES['file']['name']),
                   storage_to_stored($stored), count($rows), $uid]);
    $batchId = (int)$pdo->lastInsertId();

    // Validate without writing (spec 12.2).
    $errors = 0;
    $ins = $pdo->prepare('INSERT INTO app.ImportRows (ImportBatchID, RowNumber, RawJson, ErrorText)
                          VALUES (?, ?, ?, ?)');
    foreach ($rows as $i => $row) {
        $assoc = [];
        foreach ($header as $c => $name) {
            $assoc[$name] = $row[$c] ?? null;
        }
        $err = import_validate_row($dataset, $assoc, $pdo);
        if ($err !== null) {
            $errors++;
        }
        $ins->execute([$batchId, $i + 2, json_encode($assoc), $err]);
    }
    $pdo->prepare('UPDATE app.ImportBatches SET Status = \'validated\', RowsAccepted = ?, RowsRejected = ?
                   WHERE ImportBatchID = ?')
        ->execute([count($rows) - $errors, $errors, $batchId]);

    return ['batch_id' => $batchId, 'dataset' => $dataset, 'read' => count($rows),
            'rejected' => $errors, 'accepted' => count($rows) - $errors];
}

/**
 * Fleet vehicle loader. Accepts the fleet listing as CSV; matches on the
 * fleet number so reloading an updated listing refreshes rather than
 * duplicates.
 */
function fleet_import(): string
{
    if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        return 'No file was uploaded.';
    }
    [$header, $rows] = csv_parse($_FILES['file']['tmp_name']);
    $map = [];
    foreach ($header as $i => $name) {
        $map[strtolower(trim((string)$name))] = $i;
    }
    $pick = function (array $row, array $names) use ($map) {
        foreach ($names as $n) {
            if (isset($map[$n]) && trim((string)($row[$map[$n]] ?? '')) !== '') {
                return trim((string)$row[$map[$n]]);
            }
        }
        return null;
    };
    $pdo = db('fleet');
    $ins = $pdo->prepare('INSERT INTO ref.FleetVehicles (FleetNumber, Description, VehicleYear, Make,
                          Model, Color, PlateNumber, VIN, HomeBldgNumber, SurveyCode, AssignedTo)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $upd = $pdo->prepare('UPDATE ref.FleetVehicles SET Description = ?, VehicleYear = ?, Make = ?,
                          Model = ?, Color = ?, PlateNumber = ?, VIN = ?, HomeBldgNumber = ?,
                          SurveyCode = ?, AssignedTo = ?, IsActive = 1 WHERE FleetNumber = ?');
    $chk = $pdo->prepare('SELECT COUNT(*) FROM ref.FleetVehicles WHERE FleetNumber = ?');
    $added = $updated = $skipped = 0;
    foreach ($rows as $row) {
        $fleet = $pick($row, ['fleet number', 'fleetnumber', 'fleet #', 'fleet', 'vehicle number', 'number']);
        if ($fleet === null) {
            $skipped++;
            continue;
        }
        $desc = $pick($row, ['description', 'vehicle', 'vehicle description']);
        $year = $pick($row, ['year', 'vehicle year']);
        $make = $pick($row, ['make']);
        $model = $pick($row, ['model']);
        $color = $pick($row, ['color', 'colour']);
        $plate = $pick($row, ['plate', 'plate number', 'license', 'license plate']);
        $vin = $pick($row, ['vin']);
        $bldg = $pick($row, ['building number', 'home building', 'building', 'bldg']);
        $survey = $pick($row, ['survey', 'survey code']);
        $assigned = $pick($row, ['assigned to', 'custodian', 'driver']);
        if ($desc === null) {
            $desc = trim(($year ?? '') . ' ' . ($color ?? '') . ' ' . ($make ?? '') . ' ' . ($model ?? '')) ?: $fleet;
        }
        $vals = [$desc, $year !== null ? (int)$year : null, $make, $model, $color, $plate, $vin,
                 $bldg, $survey, $assigned];
        $chk->execute([$fleet]);
        if ((int)$chk->fetchColumn() > 0) {
            $upd->execute(array_merge($vals, [$fleet]));
            $updated++;
        } else {
            $ins->execute(array_merge([$fleet], $vals));
            $added++;
        }
    }
    return "Fleet listing loaded: $added added, $updated updated"
         . ($skipped ? ", $skipped row(s) skipped with no fleet number" : '') . '.';
}

function import_validate_row(string $dataset, array $r, PDO $pdo): ?string
{
    $problems = [];
    if ($dataset === 'work') {
        if (trim((string)($r['Title'] ?? '')) === '') {
            $problems[] = 'Title is empty';
        }
        $bldText = (string)($r['Building Number'] ?? $r['Building Name'] ?? '');
        if (csv_resolve_building($pdo, $bldText) === null) {
            $problems[] = 'Building "' . $bldText . '" does not resolve through buildings or aliases';
        }
        $sev = (int)($r['Reported Severity'] ?? 2);
        if ($sev < 1 || $sev > 4) {
            $problems[] = 'Reported Severity must be 1 to 4';
        }
        $ticket = trim((string)($r['Ticket Number'] ?? ''));
        if ($ticket !== '') {
            $st = $pdo->prepare('SELECT COUNT(*) FROM wr.WorkRequests WHERE TicketNumber = ?');
            $st->execute([$ticket]);
            if ((int)$st->fetchColumn() > 0) {
                $problems[] = 'Ticket ' . $ticket . ' already exists';
            }
        }
    } else {
        if (trim((string)($r['Ticket Number'] ?? '')) === '') {
            $problems[] = 'Ticket Number is empty';
        }
    }
    return $problems ? implode('; ', $problems) : null;
}

function import_commit(int $batchId, string $dataset): string
{
    $pdo = db($dataset);
    $stmt = $pdo->prepare('SELECT * FROM app.ImportRows WHERE ImportBatchID = ? AND ErrorText IS NULL ORDER BY RowNumber');
    $stmt->execute([$batchId]);
    // Materialize before writing: ticket numbering opens a transaction, and
    // SQL Server refuses one while a result cursor is still open.
    $rows = $stmt->fetchAll();
    $written = 0;
    foreach ($rows as $row) {
        $r = json_decode((string)$row['RawJson'], true);
        if ($dataset !== 'work') {
            continue;   // work is the migration path exercised here
        }
        $bld = csv_resolve_building($pdo, (string)($r['Building Number'] ?? $r['Building Name'] ?? ''));
        $netid = trim((string)($r['Requester NetID'] ?? 'imported'));
        $uid = import_user($pdo, $netid);
        $typeId = (int)$pdo->query('SELECT TOP 1 WorkTypeID FROM ref.WorkTypes ORDER BY WorkTypeID DESC')->fetchColumn();
        $ownerId = (int)$pdo->query('SELECT TOP 1 OwnerID FROM ref.Owners ORDER BY OwnerID')->fetchColumn();
        $ticket = trim((string)($r['Ticket Number'] ?? '')) ?: next_ticket_number('work');
        $pdo->prepare(
            'INSERT INTO wr.WorkRequests (TicketNumber, Title, Description, BuildingID, BldgNumber,
                WrittenLocation, RoomNumber, WorkTypeID, OwnerID, SurveyCode, ReportedSeverity,
                Status, CreatedByUserID, CreatedByNetID, SearchBlob, ImportBatchID, LegacyReference)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $ticket, (string)($r['Title'] ?? 'Imported request'), (string)($r['Description'] ?? ''),
            $bld['BuildingID'], $bld['BldgNumber'], (string)($r['Written Location'] ?? '') ?: null,
            (string)($r['Room'] ?? '') ?: null, $typeId, $ownerId,
            (string)($r['Survey Code'] ?? '') ?: null, (int)($r['Reported Severity'] ?? 2),
            (string)($r['Status'] ?? 'open'), $uid, $netid,
            mb_strtolower((string)($r['Title'] ?? '') . ' ' . (string)($r['Description'] ?? '')),
            $batchId, (string)($r['Ticket Number'] ?? '') ?: null,
        ]);
        $newId = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE app.ImportRows SET ResultingID = ? WHERE ImportRowID = ?')
            ->execute([$newId, (int)$row['ImportRowID']]);
        scoring_recompute($newId);
        $written++;
    }
    $pdo->prepare('UPDATE app.ImportBatches SET Status = \'committed\', CommittedAt = SYSDATETIME()
                   WHERE ImportBatchID = ?')->execute([$batchId]);
    return 'Batch ' . $batchId . ' committed: ' . $written . ' row(s) written.';
}

function import_user(PDO $pdo, string $netid): int
{
    $st = $pdo->prepare('SELECT UserID FROM app.Users WHERE NetID = ?');
    $st->execute([$netid]);
    $id = $st->fetchColumn();
    if ($id !== false) {
        return (int)$id;
    }
    $pdo->prepare('INSERT INTO app.Users (NetID, DisplayName, Email, IsProvisional) VALUES (?, ?, ?, 1)')
        ->execute([$netid, $netid . ' (imported)', $netid . '@illinois.edu']);
    $st->execute([$netid]);
    return (int)$st->fetchColumn();
}

function import_reverse(int $batchId, string $dataset): string
{
    $pdo = db($dataset);
    $n = 0;
    if ($dataset === 'work') {
        // Children first so nothing is orphaned (acceptance test 16).
        $ids = $pdo->prepare('SELECT WorkRequestID FROM wr.WorkRequests WHERE ImportBatchID = ?');
        $ids->execute([$batchId]);
        foreach ($ids->fetchAll() as $row) {
            $wid = (int)$row['WorkRequestID'];
            $pdo->prepare('DELETE FROM app.Notes WHERE RequestID = ?')->execute([$wid]);
            $pdo->prepare('DELETE FROM app.Attachments WHERE RequestID = ?')->execute([$wid]);
            $pdo->prepare('DELETE FROM app.StatusHistory WHERE RequestID = ?')->execute([$wid]);
            $pdo->prepare('DELETE FROM wr.Corroborations WHERE WorkRequestID = ?')->execute([$wid]);
            $pdo->prepare('DELETE FROM wr.DedupDecisions WHERE CandidateID = ? OR ResultingID = ?')->execute([$wid, $wid]);
            $pdo->prepare('DELETE FROM wr.Approvals WHERE WorkRequestID = ?')->execute([$wid]);
            $pdo->prepare('DELETE FROM wr.WorkRequests WHERE WorkRequestID = ?')->execute([$wid]);
            $n++;
        }
    }
    $pdo->prepare('UPDATE app.ImportBatches SET Status = \'reversed\', ReversedAt = SYSDATETIME()
                   WHERE ImportBatchID = ?')->execute([$batchId]);
    return 'Batch ' . $batchId . ' reversed: ' . $n . ' row(s) removed, no orphans left behind.';
}

// ---------------- render ----------------
/**
 * Grouped so a person looks in one place: what is happening now, the
 * space itself, who may go where, what is in the rooms, and the plumbing
 * underneath. Every ingestion path lives in the last group, never on a
 * public form.
 */
$sectionGroups = [
    'Requests' => [
        'queue'     => ['Queue and Triage', 'Triage severity and cost, advance, close, hand off to F&S'],
        'approvals' => ['Approvals', 'Everything waiting on a decision, and the decision itself'],
        'requests2' => ['Space and Key Requests', 'Filed on the front end, decided here'],
        'stages'    => ['Stages', 'The steps each request type walks through'],
        'transfer'  => ['Transfers To F&S', 'Requests handed to the campus system, with their reference'],
    ],
    'Space' => [
        'map'        => ['Building Map', 'Floor plans, and everything known about a room'],
        'space'      => ['Buildings and Rooms', 'Add, edit, retire, and move rooms between buildings'],
        'roomassign' => ['Room Assignment', 'Survey, principal investigator, safety contact, occupants'],
        'safety'     => ['Safety Manifest', 'Where every eyewash, shower and fume hood is'],
        'estate'     => ['Leases and Agreements',
                         'What PRI occupies and does not own, on what terms, until when'],
    ],
    'Safety' => [
        'incidents' => ['Incidents', 'Every report, what is being done about it, and what closed it'],
        'plans'     => ['Safety Plans', 'Plans and procedures, their owners, and what falls due'],
    ],
    'People and Access' => [
        'people'   => ['People, Roles and Notifications',
                       'Directors, administrative assistants, who may act, and who is told'],
        'access'   => ['Back End Access', 'The NetIDs that may see this side at all'],
        'orgchart' => ['Leadership from the Org Chart', 'Who it says manages each unit, proposed for you to confirm'],
        'keys'     => ['Key Assignment', 'Issue, return, mark lost, rekey a room'],
    ],
    'Property' => [
        'equipment' => ['Equipment', 'Search the property inventory: what is where, and who holds it'],
        'property'  => ['Property Updates', 'Arrivals, moves, handovers and disposals waiting to be applied'],
        'expected'  => ['Property Awaiting a Number', 'Equipment bought and not yet given an inventory number'],
        'purchases' => ['Purchase Requests', 'Where every purchase is sitting, and how to unstick it'],
        'fleet'     => ['Fleet Vehicles', 'The vehicle list behind shop maintenance requests'],
        'mileage'   => ['Mileage', 'Odometer readings, the vehicle stickers, and what looks wrong'],
        'accounts'  => ['Accounts Directory', 'The CFOP accounts and people the forms look up'],
    ],
    // One group for data, because the old arrangement scattered the same
    // three questions - where does this come from, how do I get it out,
    // how do I search it - across half a dozen screens.
    'Data' => [
        'audit'     => ['Data to Fix', 'Everything wrong with the data, and the screen that fixes it'],
        'datafeeds' => ['Where Data Comes From', 'Every source, how current it is, and the one place to load it'],
        'tables'    => ['Download and Browse', 'Any table as CSV, everything at once, and a search over each'],
        'import'    => ['Import Past Data', 'Stage, validate, commit, reverse'],
        'refdata'   => ['Publish and Check', 'Copy reference data to all three databases and check for drift'],
        'examples'  => ['Example Data', 'Load or remove the evaluation set'],
    ],
    'Configuration' => [
        'lists'    => ['Lists the Forms Offer', 'Every dropdown in the system, editable in one place'],
        'settings' => ['Settings', 'Every scoring weight, threshold, and dollar limit'],
    ],
];

$sections = [];
foreach ($sectionGroups as $groupLabel => $items) {
    foreach ($items as $key => $meta) {
        $sections[$key] = $meta[0];
    }
}

ui_top('Administration', 'Back end — you are acting as ' . $user['DisplayName'] . ' (' . $user['Role'] . ')');
flash_show();

// Campus exports go out of date quietly, so they are made to say so.
$dsNote = ds_stale_note();
if ($dsNote !== '' && $section !== 'datafeeds') {
    echo '<p class="worknote">' . h($dsNote)
       . ' <a href="admin.php?section=datafeeds">Refresh the data</a></p>';
}

// Only the group holding the current section starts open, so the page
// opens on the work rather than on a wall of links.
echo '<nav class="adminnav" aria-label="Administration sections">';
foreach ($sectionGroups as $groupLabel => $items) {
    $isOpen = array_key_exists($section, $items);
    echo '<details class="navgroup"' . ($isOpen ? ' open' : '') . '>'
       . '<summary>' . h($groupLabel)
       . '<span class="navcount">' . count($items) . '</span></summary><ul>';
    foreach ($items as $key => $meta) {
        $cls = $key === $section ? ' class="active"' : '';
        echo '<li><a href="admin.php?section=' . h($key) . '"' . $cls . '>'
           . '<strong>' . h($meta[0]) . '</strong>'
           . '<span class="navhint">' . h($meta[1]) . '</span></a></li>';
    }
    echo '</ul></details>';
}
echo '</nav>';
echo '<div class="adminbody">';

if ($section === 'map') {
    mapview_render();

} elseif ($section === 'approvals') {
    av_approvals();

} elseif ($section === 'accounts') {
    av_accounts();

} elseif ($section === 'requests2') {
    av_requests2();

} elseif ($section === 'stages') {
    av_stages();

} elseif ($section === 'access') {
    require_role('admin');
    av_access();

} elseif ($section === 'orgchart') {
    require_role('admin');
    av_orgchart();

} elseif ($section === 'datafeeds') {
    require_role('admin');
    av_datafeeds();

} elseif ($section === 'audit') {
    av_audit();

} elseif ($section === 'lists') {
    require_role('admin');
    av_lists();

} elseif ($section === 'safety') {
    av_safety();

} elseif ($section === 'incidents') {
    // A report can name a person and describe an injury, so this is the
    // one back end section with its own rule rather than the general one.
    if (!may_read_incidents()) {
        http_response_code(403);
        echo '<div class="panel error"><p>Incident reports are not open to this account.</p></div>';
    } else {
        av_incidents();
    }

} elseif ($section === 'plans') {
    av_plans();

} elseif ($section === 'estate') {
    av_estate();

} elseif ($section === 'space') {
    require_role('admin');
    av_space();

} elseif ($section === 'keys') {
    fv_keys();

} elseif ($section === 'mileage') {
    av_mileage();

} elseif ($section === 'property') {
    av_property();

} elseif ($section === 'expected') {
    av_expected();

} elseif ($section === 'purchases') {
    av_purchases();

} elseif ($section === 'equipment') {
    fv_equipment();

} elseif ($section === 'roomassign') {
    fv_roomassign();

} elseif ($section === 'queue') {
    $qSurvey = trim((string)($_GET['survey'] ?? ''));
    $qStatus = trim((string)($_GET['qstatus'] ?? ''));
    $qBand = trim((string)($_GET['band'] ?? ''));
    $qText = trim((string)($_GET['q'] ?? ''));
    $pickId = (int)($_GET['ticket'] ?? 0);

    $rows = $pdoWork->query(
        'SELECT w.*, b.Name AS BuildingName,
                (SELECT COUNT(*) FROM wr.Corroborations c WHERE c.WorkRequestID = w.WorkRequestID) AS Confirmations
         FROM wr.WorkRequests w JOIN ref.Buildings b ON b.BuildingID = w.BuildingID
         WHERE w.Status NOT IN (\'closed\', \'merged\', \'declined\')'
    )->fetchAll();
    foreach ($rows as &$r) {
        $r['_p'] = scoring_current($r);
        $r['_band'] = scoring_band($r['_p']);
    }
    unset($r);
    usort($rows, fn($a, $b) => $b['_p'] <=> $a['_p']);

    $openCount = count($rows);
    // Filtering happens after scoring, because the score is computed at
    // read time and the whole point of this screen is the ranking.
    $rows = array_values(array_filter($rows, function ($r) use ($qSurvey, $qStatus, $qBand, $qText) {
        if ($qSurvey !== '' && (string)$r['SurveyCode'] !== $qSurvey) {
            return false;
        }
        if ($qStatus !== '' && (string)$r['Status'] !== $qStatus) {
            return false;
        }
        if ($qBand !== '' && (string)$r['_band'] !== $qBand) {
            return false;
        }
        if ($qText !== '') {
            $hay = mb_strtolower((string)$r['TicketNumber'] . ' ' . (string)$r['Title'] . ' '
                 . (string)$r['BuildingName'] . ' ' . (string)$r['RoomNumber']);
            if (!str_contains($hay, mb_strtolower($qText))) {
                return false;
            }
        }
        return true;
    }));

    // One ticket is worked at a time, so there is one set of forms on the
    // page rather than three forms against every open request.
    $pick = null;
    foreach ($rows as $r) {
        if ((int)$r['WorkRequestID'] === $pickId) {
            $pick = $r;
            break;
        }
    }

    $qlink = function (array $over) use ($qSurvey, $qStatus, $qBand, $qText) {
        return h('?' . http_build_query(array_merge(
            ['section' => 'queue', 'survey' => $qSurvey, 'qstatus' => $qStatus,
             'band' => $qBand, 'q' => $qText], $over)));
    };
    $statuses = ['triaged', 'scheduled', 'in_progress', 'on_hold', 'work_done',
                 'verified', 'closed', 'declined'];
    ?>
    <div class="panel">
        <h2>Work Request Queue</h2>
        <p class="muted">Ranked by priority, highest first. Triage sets severity and the estimate;
        an estimate above the approval threshold opens the funding chain automatically. Pick a
        ticket to triage it, move it along, or hand it to Facilities and Services.</p>
        <form method="get" class="filters">
            <input type="hidden" name="section" value="queue">
            <label>Survey
                <select name="survey">
                    <option value="">All</option>
                    <?php foreach ($pdoWork->query('SELECT Code FROM ref.Surveys WHERE IsActive = 1 ORDER BY Code') as $s): ?>
                    <option <?= $qSurvey === $s['Code'] ? 'selected' : '' ?>><?= h($s['Code']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Urgency
                <select name="band">
                    <option value="">All</option>
                    <?php // scoring_band()'s own names; 'medium' and 'low' matched no request. ?>
                    <?php foreach (['critical', 'high', 'elevated', 'routine'] as $b): ?>
                    <option <?= $qBand === $b ? 'selected' : '' ?>><?= h($b) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Status
                <select name="qstatus">
                    <option value="">All open</option>
                    <?php foreach ($statuses as $st): ?>
                    <option value="<?= h($st) ?>" <?= $qStatus === $st ? 'selected' : '' ?>><?= h(nice_code($st)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Search <input name="q" value="<?= h($qText) ?>" placeholder="ticket, title, building"></label>
            <button>Filter</button>
        </form>
        <p><strong><?= count($rows) ?></strong> shown of <?= $openCount ?> open.</p>
    </div>

    <?php if ($pick): ?>
    <div class="panel">
        <h2>
            <span class="band band-<?= h((string)$pick['_band']) ?>"><?= h((string)$pick['_band']) ?></span>
            <a href="detail.php?type=work&amp;id=<?= (int)$pick['WorkRequestID'] ?>"><?= h((string)$pick['TicketNumber']) ?></a>
            &mdash; <?= h((string)$pick['Title']) ?>
        </h2>
        <p class="muted"><?= h((string)$pick['BuildingName']) ?>
            <?= $pick['RoomNumber'] ? ' &middot; Rm ' . h((string)$pick['RoomNumber']) : '' ?>
            &middot; priority <?= number_format((float)$pick['_p'], 1) ?>
            &middot; <?= h(nice_code((string)$pick['Status'])) ?>
            &middot; <?= (int)$pick['Confirmations'] ?> confirmation(s)
            &middot; severity <?= (int)($pick['TriagedSeverity'] ?? $pick['ReportedSeverity']) ?></p>

        <div class="grid">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$pick['WorkRequestID'] ?>">
                <label>Triaged severity
                    <select name="triaged_severity">
                        <option value="">Choose one</option>
                        <?php for ($s = 1; $s <= 4; $s++): ?>
                        <option value="<?= $s ?>" <?= (int)$pick['TriagedSeverity'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                        <?php endfor; ?>
                    </select>
                </label>
                <label>Estimate $ <input name="estimate" value="<?= h((string)$pick['EstimateAmount']) ?>"></label>
                <div class="actions"><button name="action" value="triage" class="primary">Save Triage</button></div>
            </form>

            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="type" value="work">
                <input type="hidden" name="id" value="<?= (int)$pick['WorkRequestID'] ?>">
                <label>Change status
                    <select name="status">
                        <?php foreach ($statuses as $st): ?>
                        <option value="<?= h($st) ?>" <?= $st === $pick['Status'] ? 'selected' : '' ?>><?= h(nice_code($st)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Comment <input name="comment"></label>
                <div class="actions"><button name="action" value="status">Apply</button></div>
            </form>

            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$pick['WorkRequestID'] ?>">
                <label>Transfer to <input name="handoff_system" value="UIUC Facilities and Services"></label>
                <label>Their reference <input name="handoff_reference" placeholder="F&amp;S work order #"></label>
                <div class="actions"><button name="action" value="handoff">Transfer</button></div>
            </form>
        </div>
        <p><a href="<?= $qlink(['ticket' => '']) ?>">Close this ticket</a></p>
    </div>
    <?php endif; ?>

    <div class="panel scroll-x">
        <table>
            <tr><th>Priority</th><th>Ticket</th><th>Title</th><th>Building</th><th>Room</th>
                <th>Survey</th><th>Sev</th><th>Confirmed</th><th>Estimate</th><th>Status</th>
                <th>Age</th><th></th></tr>
            <?php foreach ($rows as $r):
                $age = (int)floor((time() - strtotime((string)$r['CreatedAt'])) / 86400); ?>
            <tr<?= (int)$r['WorkRequestID'] === $pickId ? ' class="selected"' : '' ?>>
                <td><span class="band band-<?= h((string)$r['_band']) ?>"><?= number_format((float)$r['_p'], 1) ?></span></td>
                <td><a href="detail.php?type=work&amp;id=<?= (int)$r['WorkRequestID'] ?>"><?= h((string)$r['TicketNumber']) ?></a></td>
                <td><?= h((string)$r['Title']) ?></td>
                <td><?= h((string)$r['BuildingName']) ?></td>
                <td><?= h((string)$r['RoomNumber']) ?></td>
                <td><?= h((string)$r['SurveyCode']) ?></td>
                <td><?= (int)($r['TriagedSeverity'] ?? $r['ReportedSeverity']) ?></td>
                <td><?= (int)$r['Confirmations'] ?></td>
                <td><?= $r['EstimateAmount'] !== null && $r['EstimateAmount'] !== ''
                        ? '$' . number_format((float)$r['EstimateAmount'], 2) : '' ?></td>
                <td><?= h(nice_code((string)$r['Status'])) ?></td>
                <td><?= $age ?>d</td>
                <td><a href="<?= $qlink(['ticket' => (int)$r['WorkRequestID']]) ?>">Work on it</a></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php if (!$rows): ?><p class="muted">Nothing matches that filter.</p><?php endif; ?>
    </div>
    <?php

} elseif ($section === 'tables') {
    require_once __DIR__ . '/lib/tableexport.php';
    $catalogue = export_catalogue();
    /* The picker sends database and table as one value, because they are
       one choice. The older two-parameter form still works, so a link
       somebody saved keeps working. */
    $pickDb = in_array($_GET['db'] ?? '', APP_DB_NAMES, true) ? (string)$_GET['db'] : 'work';
    $pick = (string)($_GET['table'] ?? 'wr.WorkRequests');
    if (isset($_GET['pick']) && str_contains((string)$_GET['pick'], '|')) {
        [$maybeDb, $maybeTable] = explode('|', (string)$_GET['pick'], 2);
        if (in_array($maybeDb, APP_DB_NAMES, true)) {
            $pickDb = $maybeDb;
            $pick = $maybeTable;
        }
    }
    if (!export_table_allowed($pickDb, $pick)) {
        $pick = (string)(array_key_first($catalogue[$pickDb]) ?? '');
    }
    ?>
    <?php
    require_once __DIR__ . '/lib/bulkexport.php';
    $manifest = bulk_export_manifest();
    $tableCount = count($manifest);
    $rowTotal = array_sum(array_column($manifest, 'rows'));
    ?>
    <?php
    require_once __DIR__ . '/lib/datamap.php';
    $subjects = data_subjects_complete($catalogue);
    $summary = data_subject_summary($catalogue);
    $replicated = data_replicated_tables($catalogue);
    ?>
    <div class="panel">
        <h2>What Is Stored, and Where</h2>
        <p class="muted">Three databases, because that is how the system is built; everything in
        it lives inside them as schemas. This is the same data listed by subject rather than by
        storage, so the question &ldquo;is that even being kept&rdquo; has an answer without
        opening anything.</p>
        <div class="scroll-x">
            <table>
                <tr><th>Subject</th><th>Tables</th><th>Rows</th><th>Held in</th></tr>
                <?php foreach ($summary as $subject => $s): ?>
                <tr<?= (int)$s['rows'] === 0 ? ' class="warn"' : '' ?>>
                    <th><?= h($subject) ?></th>
                    <td><?= (int)$s['tables'] ?></td>
                    <td><?= number_format((int)$s['rows']) ?><?= (int)$s['rows'] === 0
                        ? ' <span class="muted">nothing yet</span>' : '' ?></td>
                    <td><?= h(implode(', ', $s['databases'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php if ($replicated): ?>
        <p class="muted"><strong><?= count($replicated) ?></strong> reference tables are
        deliberately copied into all three databases &mdash; buildings, rooms, people, surveys and
        the settings &mdash; so a request in any of them can resolve a building without reaching
        across. The same name appearing three times in the list below is that, not a mistake.</p>
        <?php endif; ?>
    </div>

    <div class="panel">
        <h2>Download Everything</h2>
        <p class="muted">All <?= $tableCount ?> tables across the three databases, about
        <?= number_format($rowTotal) ?> rows, as one CSV per table with a heading row, in a single
        archive. For a backup, a handover, or work in a spreadsheet away from the app.</p>
        <div class="actions">
            <a class="button" href="export_all.php">Download every table as CSV</a>
        </div>
        <p class="muted">The plain-language reports are separate and easier to read: the
        <a href="export.php?tab=work">work</a>, <a href="export.php?tab=move">move</a>,
        <a href="export.php?tab=shop">shop</a>, <a href="export.php?tab=space">space</a> and
        <a href="export.php?tab=key">key</a> request downloads, and the
        <a href="export_safety.php">safety manifest</a>.</p>
    </div>

    <div class="panel">
        <h2>One Table at a Time</h2>
        <p class="muted">Every table in all three databases, searchable here and downloadable as
        CSV. The reports above carry natural keys for people to read; a table download carries the
        table exactly as it stands, for moving data to another environment.</p>
        <form method="get" class="filters">
            <input type="hidden" name="section" value="tables">
            <?php /* One picker, grouped by subject. Two flat dropdowns made
                     a person choose a database before they could see
                     whether the thing they wanted existed at all. */ ?>
            <label>Table
                <select name="pick" onchange="this.form.submit()">
                    <?php foreach ($subjects as $subject => $tables): ?>
                    <optgroup label="<?= h($subject) ?>">
                        <?php foreach ($tables as [$db, $table, $what]):
                            if (!isset($catalogue[$db][$table])) { continue; } ?>
                        <option value="<?= h($db . '|' . $table) ?>"
                            <?= ($db === $pickDb && $table === $pick) ? 'selected' : '' ?>>
                            <?= h($what !== '' ? $what : $table) ?>
                            &nbsp;&mdash;&nbsp;<?= h($table) ?>
                            (<?= number_format((int)$catalogue[$db][$table]) ?>)</option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endforeach; ?>
                </select>
            </label>
            <a class="btn" href="export_table.php?db=<?= h($pickDb) ?>&amp;table=<?= h($pick) ?>">
                Download this table</a>
        </form>
        <p class="muted">Showing <strong><?= h($pick) ?></strong> in
        <strong><?= h(APP_DB_LABELS[$pickDb] ?? $pickDb) ?></strong>.</p>
        <?php
        if ($pick !== '') {
            [$schema, $name] = explode('.', $pick, 2);
            $quoted = '[' . str_replace(']', ']]', $schema) . '].[' . str_replace(']', ']]', $name) . ']';
            $rows = db($pickDb)->query("SELECT TOP 200 * FROM $quoted")->fetchAll();
            echo '<p class="muted">Showing ' . count($rows) . ' of '
               . number_format($catalogue[$pickDb][$pick]) . ' row(s); the download carries all of them.</p>';
            if ($rows) {
                echo '<div class="scroll-x"><table><tr>';
                foreach (array_keys($rows[0]) as $c) {
                    echo '<th>' . h($c) . '</th>';
                }
                echo '</tr>';
                foreach ($rows as $r) {
                    echo '<tr>';
                    foreach ($r as $v) {
                        $s = (string)$v;
                        echo '<td>' . h(mb_strlen($s) > 80 ? mb_substr($s, 0, 80) . '…' : $s) . '</td>';
                    }
                    echo '</tr>';
                }
                echo '</table></div>';
            } else {
                echo '<p>No rows yet.</p>';
            }
        }
        ?>
    </div>

    <div class="panel">
        <h2>Everything at a Glance</h2>
        <p class="muted">Row counts and a download for every table we own, across the three
        databases. Reference tables appear in all three because reference data replicates.</p>
        <?php foreach ($catalogue as $n => $tables): ?>
        <h3><?= h(APP_DB_LABELS[$n]) ?> &mdash; <?= count($tables) ?> tables</h3>
        <div class="scroll-x">
        <table>
            <tr><th>Table</th><th>Rows</th><th></th></tr>
            <?php foreach ($tables as $t => $count): ?>
            <tr>
                <td><a href="admin.php?section=tables&amp;db=<?= h($n) ?>&amp;table=<?= h($t) ?>"><?= h($t) ?></a></td>
                <td><?= number_format($count) ?></td>
                <td><a class="btn" href="export_table.php?db=<?= h($n) ?>&amp;table=<?= h($t) ?>">CSV</a></td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="panel">
        <h2>Reports for People</h2>
        <p class="muted">These carry natural keys and plain language headings rather than raw
        columns, for a spreadsheet somebody will actually read.</p>
        <p>
            <a class="btn" href="export.php?tab=work">Work Requests</a>
            <a class="btn" href="export.php?tab=move">Move Items</a>
            <a class="btn" href="export.php?tab=shop">Shop Requests</a>
            <a class="btn" href="export_safety.php">Safety Manifest</a>
        </p>
    </div>
    <?php

} elseif ($section === 'transfer') {
    $rows = $pdoWork->query("SELECT TicketNumber, Title, HandoffSystem, HandoffReference, HandoffAt, Status
                             FROM wr.WorkRequests WHERE Status = 'handed_off' ORDER BY HandoffAt DESC")->fetchAll();
    ?>
    <div class="panel">
        <h2>Transferred to Campus Facilities and Services</h2>
        <p class="muted">A transferred request stays on the board so duplicate suppression keeps
        working across the boundary; it carries the reference number the receiving system gave it.
        Transfer any open work request from the queue section.</p>
        <?php if ($rows): ?>
        <table>
            <tr><th>Ticket</th><th>Title</th><th>Destination</th><th>Their reference</th><th>Transferred</th></tr>
            <?php foreach ($rows as $r): ?>
            <tr><td><?= h($r['TicketNumber']) ?></td><td><?= h($r['Title']) ?></td>
                <td><?= h((string)$r['HandoffSystem']) ?></td><td><?= h((string)$r['HandoffReference']) ?></td>
                <td><?= h($r['HandoffAt'] ? date('M j, Y', strtotime((string)$r['HandoffAt'])) : '') ?></td></tr>
            <?php endforeach; ?>
        </table>
        <?php else: ?>
        <p>Nothing has been transferred yet.</p>
        <?php endif; ?>
    </div>
    <?php

} elseif ($section === 'people') {
    /* Roles and notifications on one screen. They were two, and both were
       lists of the same people: the directors, their administrative
       assistants, and whoever else needs telling. Deciding that somebody
       may approve and deciding that they hear about it are the same
       decision, so they are made in the same place. */
    require_role('admin');
    av_people();

    $events = [
        'work_request_created' => 'A work request is filed',
        'move_request_created' => 'A move request is filed',
        'shop_request_created' => 'A shop request is filed',
        'approval_decision'    => 'An approval is decided',
        'any'                  => 'Any of the above',
    ];
    $matchTypes = [
        'always'        => 'always',
        'survey'        => 'survey is',
        'work_type'     => 'work type contains',
        'owner'         => 'owner is (FS, IT, SAFETY)',
        'request_kind'  => 'request kind is (vehicle, general)',
        'severity_min'  => 'severity at least',
        'building'      => 'building number is',
        'life_safety'   => 'life safety is flagged',
        'specimen_risk' => 'specimen risk is flagged',
    ];
    $rules = db('work')->query('SELECT * FROM app.NotifyRules ORDER BY EventCode, MatchType, Email')->fetchAll();
    $surveys = db('work')->query('SELECT * FROM ref.Surveys WHERE IsActive = 1 ORDER BY Code')->fetchAll();
    ?>
    <div class="panel">
        <h2>Who Gets Notified</h2>
        <p class="muted">Recipients are rules, not a single list: several may apply to one request
        and the addresses merge. Every notice also reaches the director and proxy of the survey the
        request belongs to, and a shop request always reaches its account PI and fund manager.
        Delivery is currently <strong><?= h((string)setting('notify_mode', 'log')) ?></strong>
        from <strong><?= h((string)setting('notify_from', '')) ?></strong>; change that in Settings.</p>

        <h3>Add a Recipient</h3>
        <form method="post" class="grid">
            <?= csrf_field() ?>
            <label>When
                <select name="event_code">
                    <?php foreach ($events as $k => $label): ?>
                    <option value="<?= h($k) ?>"><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Only if
                <select name="match_type">
                    <?php foreach ($matchTypes as $k => $label): ?>
                    <option value="<?= h($k) ?>"><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Value <input name="match_value" placeholder="INHS, vehicle, 3, 1073 ..."></label>
            <label>Email <input type="email" name="email" required></label>
            <label>Name <input name="display_name"></label>
            <label>Why <input name="note" placeholder="fleet maintenance, collections lead"></label>
            <div class="actions span-all">
                <button name="action" value="rule_add" class="primary">Add Recipient</button>
            </div>
        </form>
    </div>

    <div class="panel scroll-x">
        <h2>Recipient rules (<?= count($rules) ?>)</h2>
        <table>
            <tr><th>When</th><th>Only if</th><th>Recipient</th><th>Why</th><th>Active</th><th></th></tr>
            <?php foreach ($rules as $r): ?>
            <tr class="<?= $r['IsActive'] ? '' : 'muted' ?>">
                <td><?= h($events[$r['EventCode']] ?? (string)$r['EventCode']) ?></td>
                <td><?= h($matchTypes[$r['MatchType']] ?? (string)$r['MatchType']) ?>
                    <?= $r['MatchValue'] !== null ? '<strong>' . h((string)$r['MatchValue']) . '</strong>' : '' ?></td>
                <td><?= h((string)$r['Email']) ?>
                    <?php if ($r['DisplayName']): ?><div class="muted"><?= h((string)$r['DisplayName']) ?></div><?php endif; ?></td>
                <td class="muted"><?= h((string)$r['Note']) ?></td>
                <td class="<?= $r['IsActive'] ? 'good' : '' ?>"><?= $r['IsActive'] ? 'yes' : 'no' ?></td>
                <td>
                    <form method="post" class="inline-block"><?= csrf_field() ?>
                        <input type="hidden" name="rule_id" value="<?= (int)$r['RuleID'] ?>">
                        <button name="action" value="rule_toggle"><?= $r['IsActive'] ? 'Disable' : 'Enable' ?></button>
                    </form>
                    <form method="post" class="inline-block"><?= csrf_field() ?>
                        <input type="hidden" name="rule_id" value="<?= (int)$r['RuleID'] ?>">
                        <button name="action" value="rule_delete" class="danger"
                                data-confirm="Remove this notification rule? Nobody will be told about these requests any more."
                                data-confirm-title="Remove Rule" data-confirm-ok="Remove" data-confirm-danger>Remove</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div class="panel scroll-x">
        <h2>Directors and Administrative Assistants</h2>
        <p class="muted">Every request tells the director of the unit it belongs to, and their
        administrative assistant. Clear the tick to stop that for one unit.</p>
        <table>
            <tr><th>Unit</th><th>Director</th><th>Director email</th><th>Administrative assistant</th>
                <th>Assistant email</th><th>Notify</th><th></th></tr>
            <?php foreach ($surveys as $s): ?>
            <tr>
                <form method="post" class="passthrough">
                    <?= csrf_field() ?>
                    <input type="hidden" name="survey_code" value="<?= h((string)$s['Code']) ?>">
                    <td><strong><?= h((string)$s['Code']) ?></strong>
                        <div class="muted"><?= h((string)$s['Name']) ?></div>
                        <div class="muted"><?= ($s['UnitKind'] ?? 'survey') === 'survey'
                            ? 'survey' : 'administration' ?></div></td>
                    <td><input name="director_name" size="16" value="<?= h((string)$s['DirectorName']) ?>"></td>
                    <td><input name="director_email" size="22" value="<?= h((string)$s['DirectorEmail']) ?>"></td>
                    <td><input name="proxy_name" size="16" value="<?= h((string)$s['ProxyName']) ?>"></td>
                    <td><input name="proxy_email" size="22" value="<?= h((string)$s['ProxyEmail']) ?>"></td>
                    <td><input type="checkbox" name="notify_director" value="1"
                        <?= $s['NotifyDirector'] ? 'checked' : '' ?>></td>
                    <td><button name="action" value="survey_contacts">Save</button></td>
                </form>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <?php require_once __DIR__ . '/lib/unitcontacts.php'; $ucg = unit_contact_grid(); ?>
    <div class="panel scroll-x">
        <h2>Who to Ask in Each Unit</h2>
        <p class="muted">A form shows the reader their own unit's contact, and nothing where
        none is recorded. Roles are editable under
        <a href="admin.php?section=lists&amp;list=unit_contact_role">Lists the Forms Offer</a>.</p>
        <?php foreach ($ucg['roles'] as $roleKey => $roleLabel): ?>
        <h3><?= h((string)$roleLabel) ?></h3>
        <table>
            <tr><th>Unit</th><th>Name</th><th>NetID</th><th>Email</th><th></th></tr>
            <?php foreach ($ucg['units'] as $u):
                $c = $ucg['have'][$u['Code'] . '|' . $roleKey] ?? null; ?>
            <tr>
                <form method="post" class="passthrough">
                    <?= csrf_field() ?>
                    <input type="hidden" name="unit_code" value="<?= h((string)$u['Code']) ?>">
                    <input type="hidden" name="role_key" value="<?= h((string)$roleKey) ?>">
                    <td><strong><?= h((string)$u['Code']) ?></strong>
                        <div class="muted"><?= ($u['UnitKind'] ?? 'survey') === 'survey'
                            ? 'survey' : 'administration' ?></div></td>
                    <td><input name="contact_name" size="22" data-typeahead="person"
                               data-fill="netid:uc_<?= h($roleKey . '_' . $u['Code']) ?>_netid,email:uc_<?= h($roleKey . '_' . $u['Code']) ?>_email"
                               value="<?= h((string)($c['PersonName'] ?? '')) ?>" autocomplete="off"></td>
                    <td><input name="contact_netid" size="12"
                               id="uc_<?= h($roleKey . '_' . $u['Code']) ?>_netid"
                               value="<?= h((string)($c['NetID'] ?? '')) ?>"></td>
                    <td><input name="contact_email" size="26"
                               id="uc_<?= h($roleKey . '_' . $u['Code']) ?>_email"
                               value="<?= h((string)($c['Email'] ?? '')) ?>"></td>
                    <td><button name="action" value="unit_contact_save">Save</button></td>
                </form>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endforeach; ?>
        <p class="muted">Clearing both the name and the email removes the contact.</p>
    </div>

    <?php require_once __DIR__ . '/lib/smtp.php'; $smtp = smtp_config(); ?>
    <div class="panel">
        <h2>Sending Mail Through Outlook</h2>
        <p class="muted">These settings live in config.php beside the database connections, not in
        a table, because every table here downloads as CSV and a password in a table is a password
        in a spreadsheet. Ask campus IT for a relay host and a service mailbox to send as.</p>
        <form method="post" class="grid">
            <?= csrf_field() ?>
            <label>SMTP host
                <input name="host" value="<?= h((string)$smtp['host']) ?>"
                       placeholder="smtp.office365.com or the campus relay">
            </label>
            <label>Port <input name="port" size="6" value="<?= h((string)$smtp['port']) ?>"></label>
            <label>Security
                <select name="security">
                    <?php foreach (['starttls' => 'STARTTLS (port 587)', 'tls' => 'TLS (port 465)',
                                    'none' => 'none (internal relay)'] as $k => $label): ?>
                    <option value="<?= h($k) ?>" <?= $smtp['security'] === $k ? 'selected' : '' ?>>
                        <?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Username (blank for an unauthenticated relay)
                <input name="username" value="<?= h((string)$smtp['username']) ?>">
            </label>
            <label>Password
                <input type="password" name="password" placeholder="<?= $smtp['password'] !== '' ? 'unchanged' : '' ?>">
            </label>
            <label>Send as
                <input name="from" value="<?= h((string)$smtp['from']) ?>"
                       placeholder="configure-me@example.edu">
            </label>
            <label>Display name
                <input name="from_name" value="<?= h((string)$smtp['from_name']) ?>">
            </label>
            <label class="check end">
                <input type="checkbox" name="verify_peer" value="1"
                    <?= $smtp['verify_peer'] ? 'checked' : '' ?>> Verify the server certificate
            </label>
            <div class="actions span-all">
                <button name="action" value="smtp_save" class="primary">Save Mail Settings</button>
            </div>
        </form>
        <form method="post" class="filters">
            <?= csrf_field() ?>
            <label>Send a real test to <input type="email" name="test_to" required
                   value="<?= h((string)current_user()['Email']) ?>"></label>
            <button name="action" value="smtp_test">Send Test Email</button>
        </form>
        <p class="muted">Delivery mode is <strong><?= h((string)setting('notify_mode', 'log')) ?></strong>.
        Set notify_mode to <strong>smtp</strong> in Settings once a test arrives; leave it on
        <strong>log</strong> and everything is recorded but nothing leaves the server.</p>
    </div>

    <?php
    require_once __DIR__ . '/lib/digest.php';
    $digests = db('work')->query(
        'SELECT p.*, u.DisplayName, u.Email FROM app.DigestPreferences p
         JOIN app.Users u ON u.NetID = p.NetID WHERE u.IsActive = 1
         ORDER BY p.Frequency, p.NetID')->fetchAll();
    $due = 0;
    foreach ($digests as $d) {
        if (digest_due($d)) {
            $due++;
        }
    }
    ?>
    <div class="panel">
        <h2>Digests</h2>
        <p class="muted">Each person gets a standing summary of what is waiting on them, as often
        as they choose, linking to a page of their own that empties as they work through it.
        People choose their own frequency at <a href="preferences.php">preferences</a>; the
        default for somebody who has never chosen is
        <strong><?= h((string)setting('digest_default_frequency', 'weekly')) ?></strong>.</p>
        <p><?= count($digests) ?> people have a preference,
           <strong><?= $due ?></strong> due now. Last run:
           <?= h((string)setting('digest_last_run', '') ?: 'never') ?>.
           <?php if ((int)setting('digest_auto_run', 1) === 1): ?>
           Page views carry the sending, at most once an hour, because shared hosting offers no
           scheduler. With a real scheduled task running
           <code>tools\send_digests.php</code>, set digest_auto_run to 0.
           <?php else: ?>
           Automatic sending is off; a scheduled task must run
           <code>tools\send_digests.php</code>.
           <?php endif; ?>
        </p>
        <form method="post" class="filters">
            <?= csrf_field() ?>
            <button name="action" value="digest_run">Send Digests Due Now</button>
            <button name="action" value="digest_run_force">Send to Everybody Now</button>
        </form>
        <div class="scroll-x">
        <table>
            <tr><th>Person</th><th>Frequency</th><th>When</th><th>Own requests</th>
                <th>Last sent</th><th>Items then</th><th>Due</th></tr>
            <?php $dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
            foreach ($digests as $d): ?>
            <tr>
                <td><?= h((string)$d['DisplayName']) ?><div class="muted"><?= h((string)$d['NetID']) ?></div></td>
                <td><?= h((string)$d['Frequency']) ?></td>
                <td class="muted"><?= $d['Frequency'] === 'weekly'
                        ? h($dayNames[(int)$d['SendDay']] . ' ' . date('g a', mktime((int)$d['SendHour'], 0)))
                        : ($d['Frequency'] === 'daily' ? h(date('g a', mktime((int)$d['SendHour'], 0))) : '') ?></td>
                <td><?= $d['IncludeMine'] ? 'yes' : 'no' ?></td>
                <td class="muted"><?= $d['LastSentAt'] ? h(date('M j g:ia', strtotime((string)$d['LastSentAt']))) : 'never' ?></td>
                <td><?= $d['LastItemCount'] !== null ? (int)$d['LastItemCount'] : '' ?></td>
                <td class="<?= digest_due($d) ? 'bad' : 'muted' ?>"><?= digest_due($d) ? 'yes' : '' ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
    </div>

    <div class="panel">
        <h2>Test the Routing</h2>
        <p class="muted">Shows exactly who a request of a given shape would reach, and why,
        without sending anything.</p>
        <form method="post" class="filters">
            <?= csrf_field() ?>
            <label>Event
                <select name="test_event">
                    <?php foreach ($events as $k => $label): if ($k === 'any') continue; ?>
                    <option value="<?= h($k) ?>"><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Survey <input name="test_survey" size="6" placeholder="INHS"></label>
            <label>Kind <input name="test_kind" size="8" placeholder="vehicle"></label>
            <label>Severity <input name="test_severity" size="3" placeholder="3"></label>
            <button name="action" value="notify_preview">Show Recipients</button>
        </form>
    </div>

    <div class="panel">
        <h2>Notification History</h2>
        <p class="muted">Everything the system generated, newest first, across all three databases.</p>
        <?php
        $all = [];
        foreach (APP_DB_NAMES as $n) {
            try {
                foreach (db($n)->query('SELECT TOP 40 * FROM app.Notifications ORDER BY NotificationID DESC') as $row) {
                    $row['_db'] = $n;
                    $all[] = $row;
                }
            } catch (Throwable $e) { /* table arrives with 06_upgrade */ }
        }
        usort($all, fn($a, $b) => strcmp((string)$b['CreatedAt'], (string)$a['CreatedAt']));
        if (!$all) {
            echo '<p>Nothing has been generated yet.</p>';
        } else {
            echo '<table><tr><th>When</th><th>Event</th><th>Ticket</th><th>Recipients</th>'
               . '<th>Subject</th><th>Status</th></tr>';
            foreach (array_slice($all, 0, 40) as $r) {
                echo '<tr><td>' . h(date('M j g:ia', strtotime((string)$r['CreatedAt']))) . '</td>'
                   . '<td>' . h($r['EventCode']) . '</td>'
                   . '<td>' . h((string)$r['TicketNumber']) . '</td>'
                   . '<td class="muted">' . h(mb_strimwidth((string)$r['Recipients'], 0, 60, '…')) . '</td>'
                   . '<td>' . h(mb_strimwidth((string)$r['Subject'], 0, 60, '…')) . '</td>'
                   . '<td class="' . ($r['Status'] === 'failed' ? 'bad' : 'good') . '">'
                   . h($r['Status']) . '</td></tr>';
            }
            echo '</table>';
        }
        ?>
    </div>
    <?php

} elseif ($section === 'fleet') {
    $vehicles = [];
    try {
        $vehicles = db('fleet')->query('SELECT * FROM ref.FleetVehicles ORDER BY FleetNumber')->fetchAll();
    } catch (Throwable $e) { /* table arrives with 06_upgrade */ }
    ?>
    <div class="panel">
        <h2>Fleet Vehicles</h2>
        <p>Vehicle maintenance requests pick from this list rather than describing a vehicle in
        free text, so the work is searchable by fleet number. Load the fleet listing as CSV; rows
        match on fleet number, so reloading an updated listing refreshes rather than duplicates.</p>
        <form method="post" enctype="multipart/form-data" class="filters">
            <?= csrf_field() ?>
            <label>Fleet listing CSV <input type="file" name="file" accept=".csv" required></label>
            <button name="action" value="fleet_import" class="primary">Load Fleet Listing</button>
        </form>
        <p class="muted">Recognized column headings: fleet number (or vehicle number), description,
        year, make, model, color, plate, VIN, building number, survey, assigned to. Anything the
        file does not carry is left empty; a missing description is built from year, color, make,
        and model.</p>
    </div>
    <div class="panel scroll-x">
        <h2><?= count($vehicles) ?> vehicle(s)</h2>
        <?php if ($vehicles): ?>
        <table>
            <tr><th>Fleet #</th><th>Description</th><th>Year</th><th>Make</th><th>Model</th>
                <th>Plate</th><th>Building</th><th>Survey</th><th>Assigned</th><th>Active</th><th></th></tr>
            <?php foreach ($vehicles as $v): ?>
            <tr>
                <td><?= h($v['FleetNumber']) ?></td><td><?= h($v['Description']) ?></td>
                <td><?= h((string)$v['VehicleYear']) ?></td><td><?= h((string)$v['Make']) ?></td>
                <td><?= h((string)$v['Model']) ?></td><td><?= h((string)$v['PlateNumber']) ?></td>
                <td><?= h((string)$v['HomeBldgNumber']) ?></td><td><?= h((string)$v['SurveyCode']) ?></td>
                <td><?= h((string)$v['AssignedTo']) ?></td>
                <td class="<?= $v['IsActive'] ? 'good' : 'muted' ?>"><?= $v['IsActive'] ? 'yes' : 'no' ?></td>
                <td>
                    <form method="post" class="flat"><?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$v['FleetVehicleID'] ?>">
                        <button name="action" value="fleet_toggle"><?= $v['IsActive'] ? 'Retire' : 'Restore' ?></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php else: ?>
        <p>No vehicles loaded yet. Until the listing is loaded, the shop form tells people to ask
        an administrator rather than offering an empty picker.</p>
        <?php endif; ?>
    </div>
    <?php

} elseif ($section === 'import') {
    $staged = $_SESSION['import'] ?? null;
    unset($_SESSION['import']);
    ?>
    <div class="panel">
        <h2>Import Past Data</h2>
        <p>Uploads never write on the first pass. The file stages, every row validates, and the
        report below names each row that would fail and why. Only then can you commit, and a
        committed batch reverses by identifier.</p>
        <form method="post" enctype="multipart/form-data" class="filters">
            <?= csrf_field() ?>
            <label>Dataset
                <select name="dataset">
                    <option value="work">Work requests</option>
                    <option value="move">Move requests</option>
                    <option value="shop">Shop requests</option>
                </select>
            </label>
            <label>Source system <input name="source_system" value="legacy csv" size="18"></label>
            <label>CSV file <input type="file" name="file" accept=".csv" required></label>
            <button name="action" value="import_stage" class="primary">Stage and Validate</button>
        </form>
        <p class="muted">Column names match the export files, so a downloaded export edits and
        re-imports directly. Buildings resolve by number or through the alias table.</p>
    </div>

    <?php if ($staged && empty($staged['error'])): ?>
    <div class="panel <?= $staged['rejected'] ? 'err' : 'ok' ?>">
        <h2>Validation report &mdash; batch <?= (int)$staged['batch_id'] ?></h2>
        <p><?= (int)$staged['read'] ?> row(s) read, <?= (int)$staged['accepted'] ?> would load,
           <?= (int)$staged['rejected'] ?> rejected.</p>
        <?php
        $errs = db($staged['dataset'])->prepare('SELECT RowNumber, ErrorText FROM app.ImportRows
                                                 WHERE ImportBatchID = ? AND ErrorText IS NOT NULL ORDER BY RowNumber');
        $errs->execute([$staged['batch_id']]);
        $errs = $errs->fetchAll();
        if ($errs): ?>
        <table><tr><th>Row</th><th>Why it would fail</th></tr>
        <?php foreach ($errs as $e): ?>
            <tr><td><?= (int)$e['RowNumber'] ?></td><td><?= h((string)$e['ErrorText']) ?></td></tr>
        <?php endforeach; ?>
        </table>
        <?php endif; ?>
        <form method="post" class="filters">
            <?= csrf_field() ?>
            <input type="hidden" name="batch_id" value="<?= (int)$staged['batch_id'] ?>">
            <input type="hidden" name="dataset" value="<?= h($staged['dataset']) ?>">
            <button name="action" value="import_commit" class="primary">Commit the Valid Rows</button>
        </form>
    </div>
    <?php elseif ($staged): ?>
        <div class="panel err"><p><?= h((string)$staged['error']) ?></p></div>
    <?php endif; ?>

    <div class="panel">
        <h2>Batches</h2>
        <?php
        $any = false;
        foreach (APP_DB_NAMES as $n) {
            $b = db($n)->query('SELECT TOP 20 * FROM app.ImportBatches ORDER BY ImportBatchID DESC')->fetchAll();
            if (!$b) {
                continue;
            }
            $any = true;
            echo '<h3>' . h(APP_DB_LABELS[$n]) . '</h3><table><tr><th>ID</th><th>File</th><th>Source</th>'
               . '<th>Read</th><th>Accepted</th><th>Rejected</th><th>Status</th><th></th></tr>';
            foreach ($b as $row) {
                echo '<tr><td>' . (int)$row['ImportBatchID'] . '</td><td>' . h($row['FileName']) . '</td>'
                   . '<td>' . h($row['SourceSystem']) . '</td><td>' . (int)$row['RowsRead'] . '</td>'
                   . '<td>' . (int)$row['RowsAccepted'] . '</td><td>' . (int)$row['RowsRejected'] . '</td>'
                   . '<td>' . h($row['Status']) . '</td><td>';
                if ($row['Status'] === 'committed') {
                    echo '<form method="post">' . csrf_field()
                       . '<input type="hidden" name="batch_id" value="' . (int)$row['ImportBatchID'] . '">'
                       . '<input type="hidden" name="dataset" value="' . h($n) . '">'
                       . '<button name="action" value="import_reverse">Reverse</button></form>';
                }
                echo '</td></tr>';
            }
            echo '</table>';
        }
        if (!$any) {
            echo '<p>No import batches yet.</p>';
        }
        ?>
    </div>
    <?php

} elseif ($section === 'settings') {
    $rows = $pdoWork->query('SELECT * FROM app.Settings ORDER BY SortOrder')->fetchAll();
    ?>
    <div class="panel">
        <h2>Settings</h2>
        <p class="muted">Every scoring weight, threshold, and dollar limit lives here and takes
        effect immediately, with no code change. Saving writes the value to all three databases.</p>
        <table>
            <tr><th>Setting</th><th>Value</th><th>Meaning</th><th></th></tr>
            <?php foreach ($rows as $s): ?>
            <tr>
                <td><?= h($s['Label']) ?><div class="muted"><?= h($s['SettingKey']) ?></div></td>
                <td>
                    <form method="post" class="filters flat">
                        <?= csrf_field() ?>
                        <input type="hidden" name="key" value="<?= h($s['SettingKey']) ?>">
                        <input name="value" value="<?= h($s['SettingValue']) ?>" size="10">
                </td>
                <td><?= h((string)$s['HelpText']) ?></td>
                <td><button name="action" value="setting">Save</button></form></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <p class="muted">Developed by M. J. Dreslik, with Claude Opus 5</p>
    </div>
    <?php

} elseif ($section === 'refdata') {
    $publishReport = $_SESSION['publish_report'] ?? null;
    unset($_SESSION['publish_report']);
    $consistency = refsync_consistency();
    ?>
    <div class="panel">
        <h2>Reference Data Publish</h2>
        <p class="muted">Pushes the canonical reference copy in PRI_FacilitiesWork to the Move and
        Shop databases, matching rows on natural keys. Idempotent; safe to rerun.</p>
        <form method="post"><?= csrf_field() ?>
            <button name="action" value="publish" class="primary">Publish Reference Data</button></form>
        <?php if ($publishReport): ?>
            <?php foreach ($publishReport['warnings'] as $wmsg): ?>
                <p class="bad"><?= h($wmsg) ?></p>
            <?php endforeach; ?>
            <?php foreach ($publishReport['targets'] as $name => $tables): ?>
                <h3><?= h(APP_DB_LABELS[$name]) ?> &mdash;
                    <?= $tables['_status'] === 'ok' ? 'published' : 'FAILED: ' . h((string)($tables['_error'] ?? '')) ?></h3>
                <?php if ($tables['_status'] === 'ok'): ?>
                <table><tr><th>Table</th><th>Inserted</th><th>Updated</th><th>Deleted</th></tr>
                <?php foreach ($tables as $t => $r): if ($t[0] === '_') continue; ?>
                    <tr><td><?= h($t) ?></td><td><?= (int)$r['inserted'] ?></td>
                        <td><?= (int)$r['updated'] ?></td><td><?= (int)$r['deleted'] ?></td></tr>
                <?php endforeach; ?>
                </table>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <div class="panel">
        <h2>Cross-database Consistency</h2>
        <table>
            <tr><th>Table</th><th>Work rows</th><th>Move</th><th>Shop</th></tr>
            <?php foreach ($consistency as $table => $entry): ?>
            <tr>
                <td><?= h($table) ?></td><td><?= (int)$entry['work_rows'] ?></td>
                <?php foreach (['move', 'shop'] as $n): $e = $entry[$n]; ?>
                <td class="<?= $e['clean'] ? 'good' : 'bad' ?>"><?= (int)$e['rows'] ?> rows<?php
                    if (!$e['clean']) {
                        echo ', ' . count($e['missing']) . ' missing, ' . count($e['extra'])
                           . ' extra, ' . count($e['differs']) . ' differ';
                    } ?></td>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php

} elseif ($section === 'examples') {
    $counts = [
        'work requests' => (int)db('work')->query('SELECT COUNT(*) FROM wr.WorkRequests')->fetchColumn(),
        'move requests' => (int)db('move')->query('SELECT COUNT(*) FROM mv.MoveRequests')->fetchColumn(),
        'shop requests' => (int)db('shop')->query('SELECT COUNT(*) FROM sh.ShopRequests')->fetchColumn(),
    ];
    ?>
    <div class="panel">
        <h2>Example Data</h2>
        <p>Loads realistic example tickets of all three types, plus example staff and CFOP
        accounts, so every screen can be evaluated with content in it. Clearing removes only
        example rows and leaves anything you filed yourself untouched.</p>
        <p class="muted">Currently:
            <?php foreach ($counts as $k => $v): ?><?= (int)$v ?> <?= h($k) ?>&nbsp;&nbsp;<?php endforeach; ?></p>
        <form method="post" class="filters">
            <?= csrf_field() ?>
            <button name="action" value="examples_load" class="primary">Load Example Data</button>
            <button name="action" value="examples_clear" class="danger"
                data-confirm="Remove the example data? Every example request, person and room goes."
                data-confirm-title="Remove Example Data" data-confirm-ok="Remove It" data-confirm-danger>Remove Example Data</button>
        </form>
    </div>
    <?php
}

echo '</div>';
ui_bottom();
