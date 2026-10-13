<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/requests2.php';
require_once __DIR__ . '/rooms.php';
require_once __DIR__ . '/picklists.php';
require_once __DIR__ . '/notify.php';

/**
 * Property updates.
 *
 * There used to be a screen for registering an item, a screen for handing
 * it to somebody else, and a screen for taking it off the books. They are
 * the same act: the inventory says one thing and the world says another,
 * and somebody is correcting it. The only difference between them is
 * which columns move.
 *
 * So there is one request, with a "what happened" on the front of it, and
 * one function that applies it. Everything that changes a property record
 * goes through property_apply() - the form, the admin queue, and the
 * purchase pipeline when a tagged item finally arrives - so the inventory
 * has exactly one door and exactly one history.
 */

/** The columns each kind of change actually uses. Drives the form and the review. */
const PROPERTY_FIELDS = [
    'register' => ['tag', 'description', 'model', 'serial', 'class', 'cost', 'acquired',
                   'funding', 'condition', 'room', 'person'],
    'move'     => ['room'],
    'reassign' => ['person'],
    'correct'  => ['tag', 'description', 'model', 'serial', 'class', 'cost', 'acquired',
                   'funding', 'condition', 'room', 'person'],
    'remove'   => ['removal_reason', 'removed_on', 'salvage'],
];

/**
 * Find an item the way a person remembers it: by its sticker, by what it
 * is, by its serial, by who has it, or by the room it lives in.
 */
function property_find(string $q, int $limit = 25): array
{
    $q = trim($q);
    if ($q === '') {
        return [];
    }
    $like = '%' . $q . '%';
    $stmt = db('equipment')->prepare(
        'SELECT TOP ' . max(1, min(200, $limit)) . ' InventoryID, PropertyTag, Description, Model,
                SerialNumber, Person, BldgNumber, RoomID, RoomCode, SurveyCode, Status, Cost
         FROM gis.Inventory
         WHERE PropertyTag LIKE ? OR Description LIKE ? OR SerialNumber LIKE ?
            OR Person LIKE ? OR RoomCode LIKE ? OR Model LIKE ?
         ORDER BY CASE WHEN PropertyTag = ? THEN 0 ELSE 1 END, Description, PropertyTag');
    $stmt->execute([$like, $like, $like, $like, $like, $like, strtoupper($q)]);
    return $stmt->fetchAll();
}

/** One item, or null. */
function property_item(int $id): ?array
{
    $st = db('equipment')->prepare('SELECT * FROM gis.Inventory WHERE InventoryID = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** One request, with the item it points at. */
function property_get(int $id): ?array
{
    $st = db('equipment')->prepare('SELECT * FROM rq.PropertyUpdates WHERE PropertyUpdateID = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** What a change reads as in a sentence. */
function property_kind_label(string $kind): string
{
    return picklist_label('property_change', $kind);
}

/**
 * File the change. It is written down whether or not the filer may apply
 * it, because the record of who noticed is worth as much as the record of
 * who typed.
 */
function property_file(array $in, array $user): array
{
    $kind = (string)($in['kind'] ?? '');
    if (!isset(PROPERTY_FIELDS[$kind])) {
        return ['error' => 'Say what happened to the item.'];
    }

    // The form hides the boxes a kind does not use, but a form is only a
    // suggestion. Anything outside the kind is dropped here, so a request
    // never records a change it is not asking for.
    $uses = PROPERTY_FIELDS[$kind];
    $owner = ['bldg' => 'room', 'room' => 'room', 'survey' => 'room',
              'person' => 'person', 'person_netid' => 'person'];
    foreach (['tag', 'description', 'model', 'serial', 'class', 'cost', 'acquired', 'funding',
              'condition', 'bldg', 'room', 'survey', 'person', 'person_netid',
              'removal_reason', 'removed_on', 'salvage'] as $field) {
        if (!in_array($owner[$field] ?? $field, $uses, true)) {
            $in[$field] = '';
        }
    }

    $invId = (int)($in['inventory_id'] ?? 0);
    $item = $invId > 0 ? property_item($invId) : null;

    if ($kind !== 'register' && !$item) {
        return ['error' => 'Choose the item this is about. Search by tag, description, serial '
                         . 'number, or the person holding it.'];
    }
    if ($kind === 'register' && trim((string)($in['tag'] ?? '')) === '') {
        return ['error' => 'A registration needs the university inventory number.'];
    }
    if ($kind === 'register' && trim((string)($in['description'] ?? '')) === '') {
        return ['error' => 'Say what the item is.'];
    }
    if ($kind === 'remove' && trim((string)($in['removal_reason'] ?? '')) === '') {
        return ['error' => 'Say how it left: surplus, disposal, loss, or transfer.'];
    }
    if ($kind === 'move' && trim((string)($in['bldg'] ?? '')) === ''
        && trim((string)($in['room'] ?? '')) === '') {
        return ['error' => 'Say where it is now.'];
    }
    if ($kind === 'reassign' && trim((string)($in['person'] ?? '')) === '') {
        return ['error' => 'Name the person who is responsible for it now.'];
    }

    // A tag has to be unique, and finding that out three weeks later is
    // finding it out from the university rather than from us.
    $tag = strtoupper(trim((string)($in['tag'] ?? '')));
    if ($tag !== '') {
        $dupe = db('equipment')->prepare(
            'SELECT COUNT(*) FROM gis.Inventory WHERE PropertyTag = ? AND InventoryID <> ?');
        $dupe->execute([$tag, $invId]);
        if ((int)$dupe->fetchColumn() > 0) {
            return ['error' => 'Inventory number ' . $tag . ' is already on something else.'];
        }
    }

    $bldg = trim((string)($in['bldg'] ?? ''));
    $room = trim((string)($in['room'] ?? ''));
    $code = ($bldg !== '' && $room !== '') ? room_resolve('equipment', $bldg, $room) : null;
    $survey = strtoupper(trim((string)($in['survey'] ?? '')))
        ?: (string)($item['SurveyCode'] ?? $user['SurveyCode'] ?? '');

    $ticket = rq_ticket('property');
    $pdo = db('equipment');
    $pdo->prepare(
        'INSERT INTO rq.PropertyUpdates (TicketNumber, RequestedByNetID, RequestedByUserID,
            SurveyCode, ChangeKind, InventoryID, PropertyTag, PurchaseItemID,
            Description, Model, SerialNumber, EquipClass, Cost, AcquiredOn, FundingSource,
            ConditionCode, ToBldgNumber, ToRoomID, ToRoomCode, ToPerson, ToPersonNetID,
            RemovalReason, RemovedOn, SalvageValue, Reason, Status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                 \'submitted\')'
    )->execute([
        $ticket, $user['NetID'], ensure_user('equipment', $user), $survey ?: null, $kind,
        $invId ?: null, $tag ?: ($item['PropertyTag'] ?? null),
        (int)($in['purchase_item_id'] ?? 0) ?: null,
        trim((string)($in['description'] ?? '')) ?: null,
        trim((string)($in['model'] ?? '')) ?: null,
        trim((string)($in['serial'] ?? '')) ?: null,
        trim((string)($in['class'] ?? '')) ?: null,
        property_money($in['cost'] ?? ''),
        trim((string)($in['acquired'] ?? '')) ?: null,
        trim((string)($in['funding'] ?? '')) ?: null,
        trim((string)($in['condition'] ?? '')) ?: null,
        $bldg ?: null, $room ?: null, $code,
        trim((string)($in['person'] ?? '')) ?: null,
        strtolower(trim((string)($in['person_netid'] ?? ''))) ?: null,
        trim((string)($in['removal_reason'] ?? '')) ?: null,
        trim((string)($in['removed_on'] ?? '')) ?: null,
        property_money($in['salvage'] ?? ''),
        trim((string)($in['reason'] ?? '')) ?: null,
    ]);
    $id = (int)$pdo->lastInsertId();

    // Somebody trusted with the inventory has just told us what happened
    // to something in it. Making them wait for themselves would be a
    // ceremony, so it is applied in the same motion.
    if (has_function(['equipment', 'inventory'], null, $user)) {
        $err = property_apply($id, $user, 'Filed and applied by the property inventory.');
        return $err
            ? ['id' => $id, 'ticket' => $ticket, 'applied' => false, 'warning' => $err]
            : ['id' => $id, 'ticket' => $ticket, 'applied' => true];
    }

    property_notify_filed($id);
    return ['id' => $id, 'ticket' => $ticket, 'applied' => false];
}

/** "$1,250.00" and "1250" both mean the same thing; blank means nothing. */
function property_money($v): ?float
{
    $v = trim(str_replace([',', '$'], '', (string)$v));
    return $v === '' ? null : (float)$v;
}

/**
 * Apply the change to the inventory. THE one door: every path that alters
 * a property record comes through here, so the event trail is complete by
 * construction rather than by everybody remembering to write one.
 *
 * Returns '' on success, or why not.
 */
function property_apply(int $id, array $user, string $note = ''): string
{
    $req = property_get($id);
    if (!$req) {
        return 'That request is gone.';
    }
    if ($req['Status'] !== 'submitted') {
        return 'That request was already ' . $req['Status'] . '.';
    }
    $pdo = db('equipment');
    $kind = (string)$req['ChangeKind'];
    $item = $req['InventoryID'] ? property_item((int)$req['InventoryID']) : null;

    if ($kind !== 'register' && !$item) {
        return 'The item this request points at is no longer in the inventory.';
    }

    if ($kind === 'register') {
        $tag = strtoupper(trim((string)$req['PropertyTag']));
        $dupe = $pdo->prepare('SELECT COUNT(*) FROM gis.Inventory WHERE PropertyTag = ?');
        $dupe->execute([$tag]);
        if ((int)$dupe->fetchColumn() > 0) {
            return 'Inventory number ' . $tag . ' has been used on something else since this '
                 . 'was filed.';
        }
        $pdo->prepare(
            'INSERT INTO gis.Inventory (RoomCode, BldgNumber, RoomID, PropertyTag, Description,
                Model, SerialNumber, EquipClass, Person, AcquiredOn, Cost, Condition,
                Status, SurveyCode, FundingSource, IsManaged, LastMovedAt,
                UpdatedAt, UpdatedByNetID)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'active\', ?, ?, 1, SYSDATETIME(),
                     SYSDATETIME(), ?)'
        )->execute([
            $req['ToRoomCode'], $req['ToBldgNumber'], $req['ToRoomID'], $tag,
            $req['Description'], $req['Model'], $req['SerialNumber'], $req['EquipClass'],
            $req['ToPerson'], $req['AcquiredOn'], $req['Cost'], $req['ConditionCode'],
            $req['SurveyCode'], $req['FundingSource'], $user['NetID'],
        ]);
        $invId = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE rq.PropertyUpdates SET InventoryID = ? WHERE PropertyUpdateID = ?')
            ->execute([$invId, $id]);
        property_event($invId, $tag, 'registered', null, (string)$req['ToRoomCode'],
            null, (string)$req['ToBldgNumber'],
            trim((string)$req['Description'] . ' registered on ' . $req['TicketNumber']), $user);

        // A registration that answers a purchase line closes that
        // expectation, so the same arrival is not chased twice.
        if ($req['PurchaseItemID']) {
            /* The purchase lives in its own database now. Property
               records stay here; the two are tied by the item id, which
               is a number both sides agree on. */
            db_for('rq.PurchaseItems')->prepare(
                'UPDATE rq.PurchaseItems SET PropertyTag = ?, InventoryID = ?,
                     ReceivedAt = SYSDATETIME(), TaggedByNetID = ?, TaggedAt = SYSDATETIME()
                 WHERE PurchaseItemID = ?')
                ->execute([$tag, $invId, $user['NetID'], (int)$req['PurchaseItemID']]);
        }

    } elseif ($kind === 'move') {
        $pdo->prepare(
            'UPDATE gis.Inventory SET BldgNumber = ?, RoomID = ?, RoomCode = ?,
                 LastMovedAt = SYSDATETIME(), UpdatedAt = SYSDATETIME(), UpdatedByNetID = ?
             WHERE InventoryID = ?')
            ->execute([$req['ToBldgNumber'], $req['ToRoomID'], $req['ToRoomCode'],
                       $user['NetID'], (int)$item['InventoryID']]);
        property_event((int)$item['InventoryID'], (string)$item['PropertyTag'], 'moved',
            (string)$item['RoomCode'], (string)$req['ToRoomCode'],
            (string)$item['BldgNumber'], (string)$req['ToBldgNumber'],
            'Moved on ' . $req['TicketNumber'], $user);

    } elseif ($kind === 'reassign') {
        $pdo->prepare(
            'UPDATE gis.Inventory SET Person = ?, UpdatedAt = SYSDATETIME(), UpdatedByNetID = ?
             WHERE InventoryID = ?')
            ->execute([$req['ToPerson'], $user['NetID'], (int)$item['InventoryID']]);
        property_event((int)$item['InventoryID'], (string)$item['PropertyTag'], 'reassigned',
            null, null, null, null,
            'Responsibility moved from ' . ((string)$item['Person'] ?: 'nobody recorded')
            . ' to ' . $req['ToPerson'] . ' on ' . $req['TicketNumber'], $user);

    } elseif ($kind === 'correct') {
        // Only what was actually filled in is written. A blank box on a
        // correction means "leave it alone", not "erase it".
        $set = [];
        $args = [];
        $map = ['PropertyTag' => 'PropertyTag', 'Description' => 'Description',
                'Model' => 'Model', 'SerialNumber' => 'SerialNumber',
                'EquipClass' => 'EquipClass', 'Cost' => 'Cost', 'AcquiredOn' => 'AcquiredOn',
                'FundingSource' => 'FundingSource'];
        foreach ($map as $col => $field) {
            if ($req[$field] !== null && trim((string)$req[$field]) !== '') {
                $set[] = "$col = ?";
                $args[] = $req[$field];
            }
        }
        if ($req['ConditionCode'] !== null && trim((string)$req['ConditionCode']) !== '') {
            $set[] = 'Condition = ?';
            $args[] = $req['ConditionCode'];
        }
        if ($req['ToPerson'] !== null && trim((string)$req['ToPerson']) !== '') {
            $set[] = 'Person = ?';
            $args[] = $req['ToPerson'];
        }
        if ($req['ToRoomID'] !== null && trim((string)$req['ToRoomID']) !== '') {
            array_push($set, 'BldgNumber = ?', 'RoomID = ?', 'RoomCode = ?');
            array_push($args, $req['ToBldgNumber'], $req['ToRoomID'], $req['ToRoomCode']);
        }
        if (!$set) {
            return 'That correction changes nothing. Fill in the values that should be different.';
        }
        $args[] = $user['NetID'];
        $args[] = (int)$item['InventoryID'];
        $pdo->prepare('UPDATE gis.Inventory SET ' . implode(', ', $set)
                      . ', UpdatedAt = SYSDATETIME(), UpdatedByNetID = ?
                         WHERE InventoryID = ?')->execute($args);
        property_event((int)$item['InventoryID'], (string)$item['PropertyTag'], 'corrected',
            null, null, null, null,
            count($set) . ' field(s) corrected on ' . $req['TicketNumber'], $user);

    } else {   // remove
        $pdo->prepare(
            'UPDATE gis.Inventory SET Status = ?, RemovalReason = ?, RemovedOn = ?,
                 SalvageValue = ?, UpdatedAt = SYSDATETIME(), UpdatedByNetID = ?
             WHERE InventoryID = ?')
            ->execute([(string)$req['RemovalReason'],
                       (string)($req['Reason'] ?: picklist_label('removal_reason',
                                                    (string)$req['RemovalReason'])),
                       (string)($req['RemovedOn'] ?: date('M j, Y')),
                       $req['SalvageValue'], $user['NetID'], (int)$item['InventoryID']]);
        property_event((int)$item['InventoryID'], (string)$item['PropertyTag'], 'removed',
            (string)$item['RoomCode'], null, (string)$item['BldgNumber'], null,
            picklist_label('removal_reason', (string)$req['RemovalReason'])
            . ' on ' . $req['TicketNumber'], $user);
    }

    $pdo->prepare(
        "UPDATE rq.PropertyUpdates SET Status = 'applied', AppliedByNetID = ?,
             AppliedAt = SYSDATETIME(), UpdatedAt = SYSDATETIME(),
             DecisionNote = COALESCE(NULLIF(?, ''), DecisionNote)
         WHERE PropertyUpdateID = ?")->execute([$user['NetID'], $note, $id]);

    property_notify_decided($id, 'applied');
    return '';
}

/** Turn it down, with a reason the filer can act on. */
function property_decline(int $id, string $note, array $user): string
{
    $req = property_get($id);
    if (!$req) {
        return 'That request is gone.';
    }
    if ($req['Status'] !== 'submitted') {
        return 'That request was already ' . $req['Status'] . '.';
    }
    if (trim($note) === '') {
        return 'Say why, so the person who filed it knows what to do instead.';
    }
    db('equipment')->prepare(
        "UPDATE rq.PropertyUpdates SET Status = 'declined', DecisionNote = ?,
             AppliedByNetID = ?, AppliedAt = SYSDATETIME(), UpdatedAt = SYSDATETIME()
         WHERE PropertyUpdateID = ?")->execute([trim($note), $user['NetID'], $id]);
    property_notify_decided($id, 'declined');
    return '';
}

/** One line of an item's history. */
function property_event(int $invId, string $tag, string $code, ?string $fromRoom, ?string $toRoom,
                        ?string $fromBldg, ?string $toBldg, string $detail, array $user): void
{
    db('equipment')->prepare(
        'INSERT INTO gis.InventoryEvents (InventoryID, PropertyTag, EventCode, FromRoomCode,
            ToRoomCode, FromBldgNumber, ToBldgNumber, Detail, ByNetID)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$invId, $tag ?: null, $code, $fromRoom ?: null, $toRoom ?: null,
                   $fromBldg ?: null, $toBldg ?: null, $detail, $user['NetID']]);
}

/** Everything that has happened to one item. */
function property_history(int $invId): array
{
    $st = db('equipment')->prepare(
        'SELECT * FROM gis.InventoryEvents WHERE InventoryID = ? ORDER BY CreatedAt DESC,
                InventoryEventID DESC');
    $st->execute([$invId]);
    return $st->fetchAll();
}

/** What is waiting on the property office. */
function property_pending(): array
{
    return db('equipment')->query(
        "SELECT u.*, i.Description AS ItemDescription, i.RoomCode AS ItemRoomCode,
                i.Person AS ItemPerson, i.Status AS ItemStatus
         FROM rq.PropertyUpdates u
         LEFT JOIN gis.Inventory i ON i.InventoryID = u.InventoryID
         WHERE u.Status = 'submitted'
         ORDER BY u.CreatedAt")->fetchAll();
}

/** Recently settled, so a decision can be seen rather than assumed. */
function property_settled(int $limit = 40): array
{
    return db('equipment')->query(
        "SELECT TOP " . max(1, min(200, $limit)) . " u.*, i.Description AS ItemDescription
         FROM rq.PropertyUpdates u
         LEFT JOIN gis.Inventory i ON i.InventoryID = u.InventoryID
         WHERE u.Status <> 'submitted'
         ORDER BY u.AppliedAt DESC")->fetchAll();
}

/** Tell whoever holds the property inventory that there is something to apply. */
function property_notify_filed(int $id): void
{
    $req = property_get($id);
    if (!$req) {
        return;
    }
    $nl = "\n";
    $to = notify_audience('property_update_filed', ['survey' => (string)$req['SurveyCode']]);
    if (!$to) {
        return;
    }
    notify_send('equipment', 'property_update_filed', $to,
        'Property update ' . $req['TicketNumber'] . ' needs applying',
        $req['RequestedByNetID'] . ' reports: '
        . property_kind_label((string)$req['ChangeKind']) . $nl . $nl
        . 'Item: ' . ($req['PropertyTag'] ?: '(not yet numbered)') . ' '
        . ($req['Description'] ?: '') . $nl
        . ($req['ToRoomCode'] ? 'Room: ' . $req['ToRoomCode'] . $nl : '')
        . ($req['ToPerson'] ? 'Person: ' . $req['ToPerson'] . $nl : '')
        . ($req['RemovalReason']
            ? 'How it left: ' . picklist_label('removal_reason',
                                    (string)$req['RemovalReason']) . $nl : '')
        . ($req['Reason'] ? $nl . $req['Reason'] . $nl : '')
        . $nl . notify_base_url() . 'admin.php?section=property',
        $id, (string)$req['TicketNumber']);
}

/** And tell the person who filed it what became of it. */
function property_notify_decided(int $id, string $outcome): void
{
    $req = property_get($id);
    if (!$req) {
        return;
    }
    $st = db('equipment')->prepare('SELECT Email, DisplayName FROM app.Users WHERE NetID = ?');
    $st->execute([(string)$req['RequestedByNetID']]);
    $me = $st->fetch();
    if (!$me || !$me['Email']) {
        return;
    }
    notify_send('equipment', 'property_update_decided',
        [['email' => (string)$me['Email'], 'name' => (string)$me['DisplayName'],
          'why' => 'you filed this property update']],
        'Property update ' . $req['TicketNumber'] . ' ' . $outcome,
        'Your property update - ' . property_kind_label((string)$req['ChangeKind'])
        . ' - was ' . $outcome . ".\n"
        . ($req['DecisionNote'] ? "\n" . $req['DecisionNote'] . "\n" : '')
        . "\n" . notify_base_url() . 'admin.php?section=property',
        $id, (string)$req['TicketNumber']);
}
