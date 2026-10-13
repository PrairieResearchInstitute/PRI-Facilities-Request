<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/requests2.php';
require_once __DIR__ . '/lookups.php';
require_once __DIR__ . '/picklists.php';
require_once __DIR__ . '/rooms.php';
require_once __DIR__ . '/notify.php';

/**
 * Purchase requests: the PRI Purchase Request Application as a request
 * type in this system rather than an application beside it.
 *
 * The approval model is the part worth understanding. A purchase can be
 * paid for out of several funds at once, split by dollar, by percentage
 * or by item number, and EVERY fund needs two separate agreements: the
 * principal investigator on that account, and its fund manager. So this
 * is not a queue with one person at each step - it is a grid, and the
 * request only moves on when every cell of it is agreed.
 *
 * The principal investigator's proxy may act for them. At PRI that is
 * the unit's administrative assistant, which is why the submitted view
 * shows "cathyb" against an INHS professor.
 */

const PURCHASE_SPLITS = ['dollar' => 'Divide cost by $',
                         'percent' => 'Divide cost by %',
                         'item' => 'Divide cost by item #'];

/**
 * The CFOPA split into its parts.
 *
 * "1-200250-375002-375170-A01" is chart 1, fund 200250, organisation
 * 375002, program 375170, activity A01. The university system wants
 * those separately when a requisition is injected into it, so they are
 * derived here on write rather than asked for twice: the whole code
 * stays the one thing anybody types or picks, and the parts cannot
 * disagree with it because nobody can edit them.
 */
function purchase_split_cfop(?string $code): array
{
    $parts = array_values(array_filter(explode('-', strtoupper(trim((string)$code))),
                                       fn($p) => $p !== ''));
    return [
        'coa'      => $parts[0] ?? null,
        'fund'     => $parts[1] ?? null,
        'org'      => $parts[2] ?? null,
        'program'  => $parts[3] ?? null,
        'activity' => $parts[4] ?? null,
    ];
}

/** The account, as the directory holds it, or null. */
function purchase_account(string $cfop): ?array
{
    return lookup_cfop_one($cfop);
}

/**
 * Who may act for a principal investigator on this account.
 *
 * The unit's administrative assistant. It is looked up rather than typed
 * so that when an assistant changes, every future purchase follows
 * without anybody editing a list.
 */
function purchase_pi_proxy(?string $survey): array
{
    $survey = strtoupper(trim((string)$survey));
    if ($survey === '') {
        return ['netid' => null, 'name' => null];
    }
    static $cache = [];
    if (!isset($cache[$survey])) {
        $st = db('purchasing')->prepare('SELECT ProxyName, ProxyEmail FROM ref.Surveys WHERE Code = ?');
        $st->execute([$survey]);
        $row = $st->fetch() ?: [];
        $email = (string)($row['ProxyEmail'] ?? '');
        $cache[$survey] = [
            'name'  => (string)($row['ProxyName'] ?? '') ?: null,
            'netid' => $email !== '' ? strtolower(explode('@', $email)[0]) : null,
        ];
    }
    return $cache[$survey];
}

/** Total of the item lines. */
function purchase_total(array $items): float
{
    $t = 0.0;
    foreach ($items as $i) {
        $t += (float)($i['Amount'] ?? 0);
    }
    return round($t, 2);
}

/**
 * File a purchase request. $items and $funds are lists of arrays already
 * cleaned by the form; the account is re-read here rather than trusted,
 * because the people who must agree are read off it.
 */
function purchase_create(array $head, array $items, array $funds, array $user): array
{
    $pdo = db('purchasing');
    $ticket = rq_ticket('purchase');
    $total = purchase_total($items);

    $pdo->prepare(
        'INSERT INTO rq.PurchaseRequests (TicketNumber, RequestedByNetID, RequestedByUserID,
            SurveyCode, StaffRequesting, StaffRequestingNetID, PurchaseReqDate, PurchaseType,
            Destination, DeliverBldgNumber, DeliverRoomID, DeliverRoomCode,
            BusinessJustification, AdditionalNotes,
            VendorName, VendorWeb, VendorAddress1, VendorAddress2, VendorCity, VendorState,
            VendorZip, VendorPhone, VendorNotes, SplitMethod, TotalCost, Status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                 \'fund_review\')'
    )->execute([
        $ticket, $user['NetID'], ensure_user('purchasing', $user), $head['survey'],
        $head['staff_name'], $head['staff_netid'], $head['req_date'], $head['purchase_type'],
        $head['destination'], $head['bldg'] ?: null, $head['room'] ?: null,
        $head['bldg'] !== '' ? room_resolve('purchasing', $head['bldg'], $head['room']) : null,
        $head['justification'], $head['notes'] ?: null,
        $head['vendor_name'], $head['vendor_web'] ?: null, $head['vendor_addr1'] ?: null,
        $head['vendor_addr2'] ?: null, $head['vendor_city'] ?: null, $head['vendor_state'] ?: null,
        $head['vendor_zip'] ?: null, $head['vendor_phone'] ?: null, $head['vendor_notes'] ?: null,
        $head['split'], $total,
    ]);
    $id = (int)$pdo->lastInsertId();

    $insItem = $pdo->prepare(
        'INSERT INTO rq.PurchaseItems (PurchaseRequestID, ItemNo, CatalogNumber, Description,
            Quantity, UnitsPerItem, UnitCost, Amount, AccountCode, ProductUrl,
            IsEquipment, ExpectedFor, ExpectedForNetID)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $n = 0;
    $anyEquipment = false;
    foreach ($items as $i) {
        $isEquip = !empty($i['is_equipment']);
        $anyEquipment = $anyEquipment || $isEquip;
        $insItem->execute([$id, ++$n, $i['catalog'] ?: null, $i['description'],
                           $i['quantity'], $i['units'] ?: null, $i['unit_cost'],
                           $i['Amount'], $i['account_code'] ?: null, $i['url'] ?: null,
                           $isEquip ? 1 : 0,
                           $isEquip ? $head['staff_name'] : null,
                           $isEquip ? $head['staff_netid'] : null]);
    }
    if ($anyEquipment) {
        $pdo->prepare('UPDATE rq.PurchaseRequests SET HasEquipment = 1 WHERE PurchaseRequestID = ?')
            ->execute([$id]);
    }

    $insFund = $pdo->prepare(
        'INSERT INTO rq.PurchaseFunding (PurchaseRequestID, FundNo, CfopCode, ActivityCode,
            AccountDescription, Coa, FundCode, OrgCode, ProgramCode,
            SurveyCode, AmountValue, AmountKind,
            PiName, PiNetID, PiEmail, PiProxyNetID, PiProxyName, PiStatus,
            FmName, FmNetID, FmEmail, FmStatus)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'pending\',
                 ?, ?, ?, \'pending\')');
    $f = 0;
    foreach ($funds as $fund) {
        $acct = $fund['_account'];
        $proxy = purchase_pi_proxy($acct['SurveyCode'] ?? $head['survey']);
        // Derived, never typed, so a requisition can be injected into the
        // university system without anybody keying the account twice.
        //
        // The code itself is the authority, including for the activity.
        // The directory carries an activity field too, and where the two
        // disagree the directory is stale - it is a column somebody
        // maintains, while the fifth segment is part of the thing the
        // requester actually chose. The directory only answers when the
        // code has no fifth segment to answer with.
        $p = purchase_split_cfop((string)$acct['CfopCode']);
        $insFund->execute([
            $id, ++$f, $acct['CfopCode'], $p['activity'] ?: ($acct['ActivityCode'] ?: null),
            $acct['Description'] ?? null,
            $p['coa'], $p['fund'], $p['org'], $p['program'],
            $acct['SurveyCode'] ?? null,
            $fund['amount'], $head['split'],
            $acct['AccountPI'] ?? null, $fund['pi_netid'] ?: null, $fund['pi_email'] ?: null,
            $proxy['netid'], $proxy['name'],
            $acct['FundManager'] ?? null, $fund['fm_netid'] ?: null, $fund['fm_email'] ?: null,
        ]);
    }

    purchase_event($id, null, 'submitted', 'Order submitted', $user);
    purchase_notify_pending($id);
    return ['id' => $id, 'ticket' => $ticket, 'total' => $total];
}

/** Append to the approval timeline. */
function purchase_event(int $id, ?int $fundingId, string $code, string $detail, array $user): void
{
    db('purchasing')->prepare(
        'INSERT INTO rq.PurchaseEvents (PurchaseRequestID, PurchaseFundingID, EventCode,
            Detail, ByNetID, ByName) VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$id, $fundingId, $code, mb_substr($detail, 0, 600),
                $user['NetID'] ?? null, $user['DisplayName'] ?? null]);
}

function purchase_get(int $id): ?array
{
    $st = db('purchasing')->prepare('SELECT * FROM rq.PurchaseRequests WHERE PurchaseRequestID = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function purchase_items(int $id): array
{
    $st = db('purchasing')->prepare('SELECT * FROM rq.PurchaseItems WHERE PurchaseRequestID = ?
                               ORDER BY ItemNo');
    $st->execute([$id]);
    return $st->fetchAll();
}

function purchase_funds(int $id): array
{
    $st = db('purchasing')->prepare('SELECT * FROM rq.PurchaseFunding WHERE PurchaseRequestID = ?
                               ORDER BY FundNo');
    $st->execute([$id]);
    return $st->fetchAll();
}

function purchase_timeline(int $id): array
{
    $st = db('purchasing')->prepare('SELECT * FROM rq.PurchaseEvents WHERE PurchaseRequestID = ?
                               ORDER BY PurchaseEventID');
    $st->execute([$id]);
    return $st->fetchAll();
}

/**
 * May this person decide this fund line, and in which seat?
 * Returns 'pi', 'fm', or '' - a proxy answers as the PI.
 */
function purchase_seat(array $fund, array $user): string
{
    $me = strtolower((string)($user['NetID'] ?? ''));
    if ($me === '') {
        return '';
    }
    if ($me === strtolower((string)$fund['PiNetID'])
        || $me === strtolower((string)$fund['PiProxyNetID'])) {
        return 'pi';
    }
    if ($me === strtolower((string)$fund['FmNetID'])) {
        return 'fm';
    }
    // An administrator can unstick anything, and it is recorded as them.
    if (($user['Role'] ?? '') === 'admin') {
        return $fund['PiStatus'] === 'pending' ? 'pi' : 'fm';
    }
    return '';
}

/**
 * Record one decision on one fund line, then see whether the whole
 * request can move.
 */
function purchase_decide(int $id, int $fundingId, string $seat, string $decision,
                         string $comment, array $user): string
{
    if (!in_array($decision, ['approved', 'changes_required'], true)) {
        return 'That is not a decision this form can record.';
    }
    if ($decision === 'changes_required' && trim($comment) === '') {
        return 'Say what needs changing. A request sent back without a reason cannot be acted on.';
    }
    $col = $seat === 'pi' ? 'Pi' : 'Fm';
    db('purchasing')->prepare(
        "UPDATE rq.PurchaseFunding
         SET {$col}Status = ?, {$col}DecidedByNetID = ?, {$col}DecidedAt = SYSDATETIME(),
             {$col}Comment = ?
         WHERE PurchaseFundingID = ? AND PurchaseRequestID = ?"
    )->execute([$decision, $user['NetID'], trim($comment) ?: null, $fundingId, $id]);

    $who = $seat === 'pi' ? 'Principal investigator' : 'Fund manager';
    purchase_event($id, $fundingId, $seat . '_' . $decision,
        $who . ': ' . picklist_label('approval_status', $decision)
        . (trim($comment) !== '' ? ' - ' . trim($comment) : ''), $user);

    purchase_advance($id, $user);
    return '';
}

/**
 * Move the request on if the grid allows it.
 *
 * Any "changes required" anywhere sends the whole thing back, because a
 * purchase is one purchase: there is no sense approving two thirds of it.
 *
 * There are no dollar thresholds here. These are routine purchases -
 * supplies, equipment, services - and exactly three parties approve
 * them: the principal investigator on each account, that account's fund
 * manager, and then Finance. Thresholds and directors belong to the
 * larger work orders and facilities projects, not to this.
 */
function purchase_advance(int $id, array $user): void
{
    $req = purchase_get($id);
    if (!$req || in_array($req['Status'], ['closed', 'denied', 'ordered', 'received'], true)) {
        return;
    }
    $funds = purchase_funds($id);
    $changes = false;
    $allAgreed = true;
    foreach ($funds as $f) {
        foreach (['PiStatus', 'FmStatus'] as $c) {
            if ($f[$c] === 'changes_required') {
                $changes = true;
            } elseif ($f[$c] !== 'approved') {
                $allAgreed = false;
            }
        }
    }

    $pdo = db('purchasing');
    if ($changes) {
        $pdo->prepare("UPDATE rq.PurchaseRequests SET Status = 'changes_required',
                       UpdatedAt = SYSDATETIME() WHERE PurchaseRequestID = ?")->execute([$id]);
        purchase_event($id, null, 'returned', 'Sent back to the requester for changes', $user);
        purchase_notify_submitter($id, 'changes_required');
        return;
    }
    if (!$allAgreed) {
        return;
    }

    // Every account has agreed, so it goes to Finance for the last word.
    if ($req['Status'] !== 'finance_review') {
        $pdo->prepare("UPDATE rq.PurchaseRequests SET Status = 'finance_review',
                       UpdatedAt = SYSDATETIME() WHERE PurchaseRequestID = ?")->execute([$id]);
        purchase_event($id, null, 'to_finance',
            'Every account agreed; with Finance for the last approval', $user);
        purchase_notify_finance($id);
        purchase_notify_submitter($id, 'finance_review');
    }
}

/** Finance gives the last approval, and the request is done. */
function purchase_finance_decide(int $id, string $decision, string $comment, array $user): string
{
    if (!in_array($decision, ['approved', 'changes_required'], true)) {
        return 'That is not a decision this form can record.';
    }
    if ($decision === 'changes_required' && trim($comment) === '') {
        return 'Say what needs changing.';
    }
    $pdo = db('purchasing');
    if ($decision === 'changes_required') {
        $pdo->prepare("UPDATE rq.PurchaseRequests SET Status = 'changes_required',
                       UpdatedAt = SYSDATETIME() WHERE PurchaseRequestID = ?")->execute([$id]);
        purchase_event($id, null, 'finance_changes',
            'Finance: Changes Required - ' . trim($comment), $user);
        purchase_notify_submitter($id, 'changes_required');
        return '';
    }
    $pdo->prepare("UPDATE rq.PurchaseRequests SET Status = 'approved',
                   UpdatedAt = SYSDATETIME() WHERE PurchaseRequestID = ?")->execute([$id]);
    purchase_event($id, null, 'finance_approved',
        'Finance: Approved' . (trim($comment) !== '' ? ' - ' . trim($comment) : ''), $user);
    purchase_notify_submitter($id, 'approved');

    // Anything ticked as equipment is now expected, so the property
    // inventory hears about it before it turns up rather than after.
    purchase_notify_equipment($id, $user);
    return '';
}

/**
 * Equipment on an approved purchase becomes an expected arrival.
 *
 * The point is that the inventory manager hears about a thing while it
 * is still on its way, and has somewhere to write its number the day it
 * lands, instead of discovering it months later on a shelf.
 */
function purchase_notify_equipment(int $id, array $user): void
{
    $req = purchase_get($id);
    if (!$req || !(int)$req['HasEquipment']) {
        return;
    }
    $lines = [];
    foreach (purchase_items($id) as $i) {
        if ((int)$i['IsEquipment']) {
            $lines[] = '  ' . rtrim(rtrim(number_format((float)$i['Quantity'], 2), '0'), '.')
                     . ' x ' . $i['Description']
                     . ' ($' . number_format((float)$i['Amount'], 2) . ')'
                     . ($i['ExpectedFor'] ? ' for ' . $i['ExpectedFor'] : '');
        }
    }
    if (!$lines) {
        return;
    }
    $nl = "\n";
    $to = notify_audience('purchase_equipment_expected', ['survey' => (string)$req['SurveyCode']]);
    notify_send('purchasing', 'purchase_equipment_expected', $to,
        'Equipment expected: ' . $req['TicketNumber'],
        'A purchase request has been approved and includes equipment that will need an '
        . 'inventory number when it arrives.' . $nl . $nl
        . 'Request: ' . $req['TicketNumber'] . $nl
        . 'For: ' . $req['StaffRequesting'] . ' (' . $req['SurveyCode'] . ')' . $nl
        . 'Vendor: ' . $req['VendorName'] . $nl
        . 'Deliver to: ' . $req['Destination'] . $nl
        . ($req['DeliverBldgNumber']
            ? 'Building and room: ' . $req['DeliverBldgNumber'] . ' '
              . $req['DeliverRoomID'] . $nl : '')
        . $nl . 'Equipment on this order:' . $nl . implode($nl, $lines) . $nl . $nl
        . 'It is waiting under Property awaiting a number:' . $nl
        . notify_base_url() . 'admin.php?section=expected',
        $id, (string)$req['TicketNumber']);
    purchase_event($id, null, 'equipment_expected',
        count($lines) . ' equipment line(s) sent to the property inventory', $user);
}

/** Whoever holds the finance function, rather than a name in the code. */
function purchase_notify_finance(int $id): void
{
    $req = purchase_get($id);
    if (!$req) {
        return;
    }
    $rows = db('purchasing')->query(
        "SELECT a.NetID, u.DisplayName, u.Email FROM app.AdminAccess a
         LEFT JOIN app.Users u ON u.NetID = a.NetID
         WHERE a.IsActive = 1 AND a.AccessFunction = 'finance'")->fetchAll();
    $to = [];
    foreach ($rows as $r) {
        $email = (string)($r['Email'] ?: $r['NetID'] . '@illinois.edu');
        $to[strtolower($email)] = ['email' => $email,
            'name' => (string)($r['DisplayName'] ?: $r['NetID']),
            'why' => 'Finance approval on purchases'];
    }
    if (!$to) {
        return;
    }
    $nl = "\n";
    notify_send('purchasing', 'purchase_finance_review', array_values($to),
        'Purchase request ' . $req['TicketNumber'] . ' needs Finance approval',
        'Every account on this purchase has agreed. It is now with Finance.' . $nl . $nl
        . 'Requested by: ' . $req['StaffRequesting'] . $nl
        . 'Vendor: ' . $req['VendorName'] . $nl
        . 'Total: $' . number_format((float)$req['TotalCost'], 2) . $nl . $nl
        . notify_base_url() . 'purchase_view.php?id=' . $id,
        $id, (string)$req['TicketNumber']);
}

/** Equipment on its way, with no inventory number against it yet. */
function purchase_expected_equipment(): array
{
    return db('purchasing')->query(
        "SELECT i.*, r.TicketNumber, r.StaffRequesting, r.SurveyCode, r.VendorName,
                r.Destination, r.DeliverBldgNumber, r.DeliverRoomID, r.Status,
                r.OrderReference, r.OrderedAt
         FROM rq.PurchaseItems i
         JOIN rq.PurchaseRequests r ON r.PurchaseRequestID = i.PurchaseRequestID
         WHERE i.IsEquipment = 1 AND ISNULL(i.PropertyTag, '') = ''
           AND r.Status IN ('approved', 'ordered', 'received')
         ORDER BY r.OrderedAt, r.TicketNumber, i.ItemNo")->fetchAll();
}

/*
 * There used to be a purchase_tag_item() here, which wrote a property
 * record straight from a purchase line. lib/property.php does that now,
 * through the same request and the same history as every other change to
 * the inventory - so "it arrived" from a purchase and "it arrived" from
 * anywhere else are the same act, recorded the same way.
 */

/** Tell the people who are being waited on. */
function purchase_notify_pending(int $id): void
{
    $req = purchase_get($id);
    if (!$req) {
        return;
    }
    $to = [];
    foreach (purchase_funds($id) as $f) {
        foreach ([[$f['PiEmail'], $f['PiName'], 'principal investigator'],
                  [$f['FmEmail'], $f['FmName'], 'fund manager']] as [$email, $name, $role]) {
            if ($email) {
                $to[strtolower((string)$email)] = ['email' => (string)$email,
                    'name' => (string)$name,
                    'why' => $role . ' on ' . $f['CfopCode']];
            }
        }
    }
    if (!$to) {
        return;
    }
    notify_send('purchasing', 'purchase_request_created', array_values($to),
        'Purchase request ' . $req['TicketNumber'] . ' needs your approval',
        "A purchase request has been filed and is waiting on you.\n\n"
        . 'Requested by: ' . $req['StaffRequesting'] . "\n"
        . 'Vendor: ' . $req['VendorName'] . "\n"
        . 'Total: $' . number_format((float)$req['TotalCost'], 2) . "\n"
        . 'Purchase type: ' . $req['PurchaseType'] . "\n\n"
        . "Justification:\n" . $req['BusinessJustification'] . "\n\n"
        . notify_base_url() . 'request.php?type=purchase&id=' . $id,
        $id, (string)$req['TicketNumber']);
}

function purchase_notify_submitter(int $id, string $status): void
{
    $req = purchase_get($id);
    if (!$req) {
        return;
    }
    $st = db('purchasing')->prepare('SELECT Email, DisplayName FROM app.Users WHERE NetID = ?');
    $st->execute([(string)$req['RequestedByNetID']]);
    $me = $st->fetch();
    if (!$me || !$me['Email']) {
        return;
    }
    notify_send('purchasing', 'purchase_status_changed',
        [['email' => (string)$me['Email'], 'name' => (string)$me['DisplayName'],
          'why' => 'you filed this request']],
        'Purchase request ' . $req['TicketNumber'] . ': '
            . picklist_label('purchase_status', $status),
        "Your purchase request has moved.\n\n"
        . 'Now: ' . picklist_label('purchase_status', $status) . "\n"
        . 'Vendor: ' . $req['VendorName'] . "\n"
        . 'Total: $' . number_format((float)$req['TotalCost'], 2) . "\n\n"
        . notify_base_url() . 'request.php?type=purchase&id=' . $id,
        $id, (string)$req['TicketNumber']);
}

/**
 * Copy a request into a new draft: "Replicate As New Purchase Request".
 * The items and the funding come across; the approvals do not, because
 * nobody has agreed to the new one yet.
 */
function purchase_replicate(int $id, array $user): array
{
    $req = purchase_get($id);
    if (!$req) {
        return ['error' => 'That request is gone.'];
    }
    $head = [
        'survey' => (string)$req['SurveyCode'],
        'staff_name' => (string)$req['StaffRequesting'],
        'staff_netid' => (string)$req['StaffRequestingNetID'],
        'req_date' => date('n/j/Y'),
        'purchase_type' => (string)$req['PurchaseType'],
        'destination' => (string)$req['Destination'],
        'bldg' => (string)$req['DeliverBldgNumber'],
        'room' => (string)$req['DeliverRoomID'],
        'justification' => (string)$req['BusinessJustification'],
        'notes' => (string)$req['AdditionalNotes'],
        'vendor_name' => (string)$req['VendorName'],
        'vendor_web' => (string)$req['VendorWeb'],
        'vendor_addr1' => (string)$req['VendorAddress1'],
        'vendor_addr2' => (string)$req['VendorAddress2'],
        'vendor_city' => (string)$req['VendorCity'],
        'vendor_state' => (string)$req['VendorState'],
        'vendor_zip' => (string)$req['VendorZip'],
        'vendor_notes' => (string)$req['VendorNotes'],
        'vendor_phone' => (string)$req['VendorPhone'],
        'split' => (string)$req['SplitMethod'],
    ];
    $items = [];
    foreach (purchase_items($id) as $i) {
        $items[] = ['catalog' => (string)$i['CatalogNumber'], 'description' => (string)$i['Description'],
                    'quantity' => $i['Quantity'], 'units' => (string)$i['UnitsPerItem'],
                    'unit_cost' => $i['UnitCost'], 'Amount' => $i['Amount'],
                    'account_code' => (string)$i['AccountCode'], 'url' => (string)$i['ProductUrl'],
                    'is_equipment' => (int)$i['IsEquipment'] === 1];
    }
    $funds = [];
    foreach (purchase_funds($id) as $f) {
        $acct = purchase_account((string)$f['CfopCode']);
        if (!$acct) {
            continue;
        }
        $funds[] = ['_account' => $acct, 'amount' => $f['AmountValue'],
                    'pi_netid' => (string)$f['PiNetID'], 'pi_email' => (string)$f['PiEmail'],
                    'fm_netid' => (string)$f['FmNetID'], 'fm_email' => (string)$f['FmEmail']];
    }
    if (!$items || !$funds) {
        return ['error' => 'That request cannot be copied: its items or its accounts are gone.'];
    }
    $new = purchase_create($head, $items, $funds, $user);
    purchase_event($new['id'], null, 'replicated',
        'Copied from ' . $req['TicketNumber'], $user);
    return $new;
}

/* ---------------------------------------------------------------------
   Administration.

   The three functions below are not approvals. They exist because a
   process that only works when everybody is reachable and nobody leaves
   is not a process, it is a hope. Every one of them writes who did it and
   that it was done on somebody's behalf, so a purchase can always answer
   the question "who actually said yes to this".
   --------------------------------------------------------------------- */

/**
 * Put the right person in a seat. Used when the account directory has a
 * name and no NetID, or when the person it names has left.
 */
function purchase_set_seat(int $id, int $fundingId, string $seat, string $name, string $netid,
                           array $user): string
{
    if (!in_array($seat, ['pi', 'fm'], true)) {
        return 'That is not a seat.';
    }
    $name = trim($name);
    $netid = strtolower(trim($netid));
    if ($netid === '' && $name === '') {
        return 'Name the person, and pick them from the list so their NetID comes with them.';
    }
    if ($netid === '') {
        return 'That name did not come with a NetID. Pick the person from the list rather than '
             . 'typing the name, or the request will still have nobody to ask.';
    }

    $col = $seat === 'pi' ? 'Pi' : 'Fm';
    $st = db('purchasing')->prepare(
        "SELECT {$col}Name AS WasName, {$col}NetID AS WasNetID, {$col}Status AS WasStatus,
                CfopCode, FundNo
         FROM rq.PurchaseFunding WHERE PurchaseFundingID = ? AND PurchaseRequestID = ?");
    $st->execute([$fundingId, $id]);
    $was = $st->fetch();
    if (!$was) {
        return 'That fund line is not on this request.';
    }
    if ($was['WasStatus'] !== 'pending') {
        return 'That seat has already answered. Changing it now would erase their decision.';
    }

    db('purchasing')->prepare(
        "UPDATE rq.PurchaseFunding
            SET {$col}Name = ?, {$col}NetID = ?, {$col}Email = ?
          WHERE PurchaseFundingID = ? AND PurchaseRequestID = ?")
        ->execute([$name ?: $netid, $netid, $netid . '@illinois.edu', $fundingId, $id]);

    purchase_event($id, $fundingId, $seat . '_reassigned',
        ($seat === 'pi' ? 'Principal investigator' : 'Fund manager') . ' on '
        . $was['CfopCode'] . ' changed from '
        . ((string)($was['WasName'] ?: $was['WasNetID']) ?: 'nobody')
        . ' to ' . ($name ?: $netid), $user);

    purchase_notify_pending($id);
    return '';
}

/**
 * Record a decision somebody gave outside the system - on the phone, in a
 * meeting, by email to the wrong address. The comment is required, because
 * "how you know" is the whole value of the record.
 */
function purchase_decide_on_behalf(int $id, int $fundingId, string $seat, string $decision,
                                   string $comment, array $user): string
{
    if (!in_array($seat, ['pi', 'fm'], true)) {
        return 'That is not a seat.';
    }
    if (trim($comment) === '') {
        return 'Say how you know they agreed. A decision recorded for somebody else without a '
             . 'reason is not a record of anything.';
    }
    $col = $seat === 'pi' ? 'Pi' : 'Fm';
    $st = db('purchasing')->prepare(
        "SELECT {$col}Name AS WhoName, {$col}NetID AS WhoNetID, {$col}Status AS WasStatus, CfopCode
         FROM rq.PurchaseFunding WHERE PurchaseFundingID = ? AND PurchaseRequestID = ?");
    $st->execute([$fundingId, $id]);
    $row = $st->fetch();
    if (!$row) {
        return 'That fund line is not on this request.';
    }
    if ($row['WasStatus'] !== 'pending') {
        return 'That seat has already answered.';
    }
    if (!in_array($decision, ['approved', 'changes_required'], true)) {
        return 'That is not a decision this form can record.';
    }

    $who = (string)($row['WhoName'] ?: $row['WhoNetID']) ?: 'the seat';
    db('purchasing')->prepare(
        "UPDATE rq.PurchaseFunding
            SET {$col}Status = ?, {$col}DecidedByNetID = ?, {$col}DecidedAt = SYSDATETIME(),
                {$col}Comment = ?
          WHERE PurchaseFundingID = ? AND PurchaseRequestID = ?")
        ->execute([$decision, $user['NetID'],
                   'Recorded by ' . $user['NetID'] . ' on their behalf: ' . trim($comment),
                   $fundingId, $id]);

    purchase_event($id, $fundingId, $seat . '_' . $decision,
        ($seat === 'pi' ? 'Principal investigator' : 'Fund manager') . ' on ' . $row['CfopCode']
        . ': ' . picklist_label('approval_status', $decision)
        . ' - recorded by ' . ($user['DisplayName'] ?? $user['NetID'])
        . ' on behalf of ' . $who . '. ' . trim($comment), $user);

    purchase_advance($id, $user);
    return '';
}

/**
 * Ordered, received, closed, cancelled. The states that are facts about
 * the world rather than decisions about the money.
 */
function purchase_set_status(int $id, string $to, string $note, array $user): string
{
    $allowed = ['ordered', 'received', 'closed', 'denied'];
    if (!in_array($to, $allowed, true)) {
        return 'That is not a state this can be put into from here.';
    }
    $req = purchase_get($id);
    if (!$req) {
        return 'That purchase is gone.';
    }
    if ($to === 'denied' && trim($note) === '') {
        return 'Say why it was cancelled.';
    }
    if ($req['Status'] === $to) {
        return 'It is already ' . picklist_label('purchase_status', $to) . '.';
    }

    $stamp = $to === 'closed' || $to === 'denied' ? ', ClosedAt = SYSDATETIME()' : '';
    db('purchasing')->prepare(
        "UPDATE rq.PurchaseRequests SET Status = ?, UpdatedAt = SYSDATETIME(),
             DecisionNote = COALESCE(NULLIF(?, ''), DecisionNote){$stamp}
         WHERE PurchaseRequestID = ?")->execute([$to, trim($note), $id]);

    purchase_event($id, null, $to,
        picklist_label('purchase_status', $to)
        . (trim($note) !== '' ? ' - ' . trim($note) : ''), $user);
    purchase_notify_submitter($id, $to);
    return '';
}

/* ---------------------------------------------------------------------
   Reconciliation for Emburse Enterprise.

   Approving a purchase is only half of it. The card charge then has to be
   reconciled in the campus system, broken down by Banner account code,
   and Emburse gives you one free-text notes box per code rather than any
   way to enter the lines. So the itemisation is retyped - from a form
   that already holds every line, every quantity and every unit cost.

   That retyping is the whole reason people hate the second step, and it
   is entirely avoidable: this purchase already knows the answer. Here it
   is, grouped by code and formatted exactly as the notes box wants it,
   ready to be copied.

       1 - Wireless Headphones @ $69.99ea.
       4 - HDMI Cables @ $29.99ea.

   The format is not ours and must not drift. It came from the workbook
   people are pasting out of today, so what is copied from here is
   character for character what they are already pasting.
   --------------------------------------------------------------------- */

/** "4", not "4.00"; "1.5" stays "1.5". */
function purchase_qty(float $q): string
{
    return rtrim(rtrim(number_format($q, 2, '.', ''), '0'), '.');
}

/** One line, exactly as the notes box wants it. */
function purchase_reconcile_line(array $item): string
{
    return purchase_qty((float)$item['Quantity']) . ' - ' . trim((string)$item['Description'])
         . ' @ $' . number_format((float)$item['UnitCost'], 2) . 'ea.';
}

/**
 * The purchase broken into one block per Banner account code.
 *
 * Returns a list of ['code', 'title', 'total', 'lines', 'text'], plus the
 * grand total, and names any line with no code - because a line with no
 * code cannot be reconciled and is better found here than in Emburse.
 */
function purchase_reconcile(int $id): array
{
    $items = purchase_items($id);
    $groups = [];
    $uncoded = [];
    $grand = 0.0;

    foreach ($items as $i) {
        $amount = (float)$i['Amount'];
        $grand += $amount;
        $code = strtoupper(trim((string)$i['AccountCode']));
        if ($code === '') {
            $uncoded[] = $i;
            continue;
        }
        if (!isset($groups[$code])) {
            $groups[$code] = ['code' => $code, 'title' => '', 'total' => 0.0, 'lines' => []];
        }
        $groups[$code]['total'] += $amount;
        $groups[$code]['lines'][] = purchase_reconcile_line($i);
    }

    // The code's own title, so the person reconciling can see they are
    // pasting into the right charge rather than matching digits by eye.
    if ($groups) {
        $in = implode(',', array_fill(0, count($groups), '?'));
        $st = db('purchasing')->prepare(
            "SELECT AccountCode, FullTitle, BannerTitle FROM ref.BannerAccountCodes
              WHERE AccountCode IN ($in)");
        $st->execute(array_keys($groups));
        foreach ($st as $r) {
            $c = strtoupper((string)$r['AccountCode']);
            if (isset($groups[$c])) {
                $groups[$c]['title'] = (string)($r['FullTitle'] ?: $r['BannerTitle']);
            }
        }
    }

    // Largest first: the biggest charge is the one being reconciled now.
    uasort($groups, fn($a, $b) => $b['total'] <=> $a['total']);
    foreach ($groups as $code => $g) {
        $groups[$code]['text'] = implode("\n", $g['lines']);
    }

    return [
        'groups'  => array_values($groups),
        'uncoded' => $uncoded,
        'grand'   => round($grand, 2),
    ];
}
