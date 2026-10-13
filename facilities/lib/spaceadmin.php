<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rooms.php';
require_once __DIR__ . '/refsync.php';
require_once __DIR__ . '/adminforms.php';

/**
 * Space and people administration: buildings, rooms, relocations, roles,
 * and rekeying. Reference changes publish to all three databases, because
 * a building must mean the same thing everywhere.
 */

function pad_bldg(string $n): string
{
    $n = strtoupper(trim($n));
    return ctype_digit($n) ? str_pad($n, 4, '0', STR_PAD_LEFT) : $n;
}

function sort_key_for(string $name): string
{
    $s = strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $name));
    $s = preg_replace_callback('/\d+/', fn($m) => str_pad($m[0], 4, '0', STR_PAD_LEFT), $s);
    return mb_substr(trim(preg_replace('/\s+/', ' ', $s)), 0, 20);
}

function building_save(array $in, bool $isNew, string $byNetID): string
{
    $num = pad_bldg((string)$in['bldg_number']);
    $name = trim((string)$in['name']);
    if ($num === '' || $name === '') {
        return 'A building number and name are required.';
    }
    $pdo = db('space');
    $original = pad_bldg((string)($in['original'] ?? ''));

    $exists = $pdo->prepare('SELECT COUNT(*) FROM ref.Buildings WHERE BldgNumber = ?');
    $exists->execute([$num]);
    $taken = (int)$exists->fetchColumn() > 0;

    if ($isNew) {
        if ($taken) {
            return 'Building ' . $num . ' already exists.';
        }
        $pdo->prepare(
            'INSERT INTO ref.Buildings (BldgNumber, SortKey, ShortCode, Name, AddressLine, City,
                IsOnCampus, IsRemote, Criticality, RequiresFreeText, IsActive)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1)'
        )->execute([$num, sort_key_for($name), trim((string)($in['short_code'] ?? '')) ?: null,
                    $name, trim((string)($in['address'] ?? '')) ?: null,
                    trim((string)($in['city'] ?? '')) ?: null,
                    !empty($in['on_campus']) ? 1 : 0, !empty($in['remote']) ? 1 : 0,
                    (int)($in['criticality'] ?? 0)]);
        $msg = 'Building ' . $num . ' added.';
    } else {
        if ($num !== $original && $taken) {
            return 'Building ' . $num . ' already exists; renumbering would collide.';
        }
        $pdo->prepare(
            'UPDATE ref.Buildings SET BldgNumber = ?, SortKey = ?, ShortCode = ?, Name = ?,
                AddressLine = ?, City = ?, IsOnCampus = ?, IsRemote = ?, Criticality = ?
             WHERE BldgNumber = ?'
        )->execute([$num, sort_key_for($name), trim((string)($in['short_code'] ?? '')) ?: null,
                    $name, trim((string)($in['address'] ?? '')) ?: null,
                    trim((string)($in['city'] ?? '')) ?: null,
                    !empty($in['on_campus']) ? 1 : 0, !empty($in['remote']) ? 1 : 0,
                    (int)($in['criticality'] ?? 0), $original]);
        if ($num !== $original) {
            // Carry the number change through everything keyed on it.
            foreach ([['work', 'gis.Rooms', 'BldgNumber'], ['work', 'gis.Floors', 'BldgNumber'],
                      ['work', 'gis.Inventory', 'BldgNumber'], ['work', 'gis.Occupants', 'BldgNumber'],
                      ['work', 'gis.Safety', 'BldgNumber'], ['work', 'gis.RoomKeys', 'BldgNumber'],
                      ['work', 'wr.WorkRequests', 'BldgNumber'], ['shop', 'sh.ShopRequests', 'BldgNumber'],
                      ['move', 'mv.MoveItems', 'FromBldgNumber'], ['move', 'mv.MoveItems', 'ToBldgNumber']] as [$d, $t, $c]) {
                try {
                    db($d)->prepare("UPDATE $t SET $c = ? WHERE $c = ?")->execute([$num, $original]);
                } catch (Throwable $e) { /* table absent in that database */ }
            }
        }
        $msg = 'Building ' . $num . ' saved.';
    }
    refsync_publish(null);
    return $msg;
}

function building_set_active(string $num, string $byNetID): string
{
    $pdo = db('space');
    $num = pad_bldg($num);
    $st = $pdo->prepare('SELECT IsActive, Name FROM ref.Buildings WHERE BldgNumber = ?');
    $st->execute([$num]);
    $row = $st->fetch();
    if (!$row) {
        return 'No such building.';
    }
    $to = $row['IsActive'] ? 0 : 1;
    $pdo->prepare('UPDATE ref.Buildings SET IsActive = ? WHERE BldgNumber = ?')->execute([$to, $num]);
    refsync_publish(null);
    return $row['Name'] . ' ' . ($to ? 'restored' : 'retired')
         . ($to ? '.' : '. Its requests and history are kept; it no longer appears on the forms.');
}

function room_add(array $in, string $byNetID): string
{
    $bldg = pad_bldg((string)$in['bldg_number']);
    $floor = trim((string)$in['floor']);
    $roomId = trim((string)$in['room_id']);
    if ($bldg === '' || $floor === '' || $roomId === '') {
        return 'Building, floor and room number are required.';
    }
    $code = $bldg . '_' . $floor . '_' . $roomId;
    $pdo = db('space');
    $st = $pdo->prepare('SELECT COUNT(*) FROM gis.Rooms WHERE RoomCode = ?');
    $st->execute([$code]);
    if ((int)$st->fetchColumn() > 0) {
        return 'Room ' . $code . ' already exists.';
    }
    $pdo->prepare(
        'INSERT INTO gis.Rooms (RoomCode, BldgNumber, FloorCode, RoomID, RoomName, CategoryCode,
            CategoryDesc, AreaSqFt, SurveyCode)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$code, $bldg, $floor, $roomId, trim((string)($in['room_name'] ?? '')) ?: null,
                trim((string)($in['category'] ?? '')) ?: null,
                trim((string)($in['category_desc'] ?? '')) ?: null,
                is_numeric($in['area'] ?? null) ? (float)$in['area'] : null,
                trim((string)($in['survey'] ?? '')) ?: null]);
    room_assign_event($code, 'room_added', 'Added by hand', $byNetID);
    rooms_publish();
    return 'Room ' . $code . ' added. It has no outline until the geodatabase includes it.';
}

function room_remove(string $roomCode, string $byNetID): string
{
    $pdo = db('space');
    // What is in the room is the inventory's business, so it is asked
    // separately rather than joined to.
    $st = db('equipment')->prepare('SELECT COUNT(*) FROM gis.Inventory WHERE RoomCode = ?');
    $st->execute([$roomCode]);
    $items = (int)$st->fetchColumn();
    $st = $pdo->prepare('SELECT COUNT(*) FROM gis.Occupants WHERE RoomCode = ? AND EndedOn IS NULL');
    $st->execute([$roomCode]);
    $people = (int)$st->fetchColumn();
    if ($items || $people) {
        return 'Room ' . $roomCode . ' still holds ' . $items . ' item(s) and ' . $people
             . ' occupant(s). Move or remove them first, so nothing is orphaned.';
    }
    $pdo->prepare('DELETE FROM gis.Rooms WHERE RoomCode = ?')->execute([$roomCode]);
    room_assign_event($roomCode, 'room_removed', 'Removed by hand', $byNetID);
    rooms_publish();
    return 'Room ' . $roomCode . ' removed.';
}

/**
 * A unit relocates: the rooms it occupied move to another building, and
 * everything anchored to those rooms follows. Used when a leased site is
 * given up.
 */
function rooms_move(array $roomCodes, string $toBldg, bool $retireSource, string $byNetID): string
{
    $toBldg = pad_bldg($toBldg);
    if (!$roomCodes || $toBldg === '') {
        return 'Choose at least one room and a destination building.';
    }
    $pdo = db('space');
    $moved = 0;
    $sources = [];
    foreach ($roomCodes as $old) {
        $st = $pdo->prepare('SELECT * FROM gis.Rooms WHERE RoomCode = ?');
        $st->execute([$old]);
        $room = $st->fetch();
        if (!$room) {
            continue;
        }
        $sources[$room['BldgNumber']] = true;
        $new = $toBldg . '_' . $room['FloorCode'] . '_' . $room['RoomID'];

        $exists = $pdo->prepare('SELECT COUNT(*) FROM gis.Rooms WHERE RoomCode = ?');
        $exists->execute([$new]);
        if ((int)$exists->fetchColumn() > 0) {
            $new .= '-' . substr((string)$room['BldgNumber'], -2);   // keep both rooms distinct
        }

        $pdo->prepare('UPDATE gis.Rooms SET RoomCode = ?, BldgNumber = ? WHERE RoomCode = ?')
            ->execute([$new, $toBldg, $old]);
        foreach (['gis.Occupants', 'gis.Inventory', 'gis.Safety', 'gis.RoomKeys'] as $t) {
            $pdo->prepare("UPDATE $t SET RoomCode = ?, BldgNumber = ? WHERE RoomCode = ?")
                ->execute([$new, $toBldg, $old]);
        }
        $pdo->prepare('UPDATE gis.RoomResponsibility SET RoomCode = ? WHERE RoomCode = ?')
            ->execute([$new, $old]);
        // Requests keep pointing at the room they were filed against.
        $pdo->prepare('UPDATE wr.WorkRequests SET RoomCode = ? WHERE RoomCode = ?')
            ->execute([$new, $old]);
        try {
            db('shop')->prepare('UPDATE sh.ShopRequests SET RoomCode = ? WHERE RoomCode = ?')
                ->execute([$new, $old]);
            db('move')->prepare('UPDATE mv.MoveItems SET FromRoomCode = ? WHERE FromRoomCode = ?')
                ->execute([$new, $old]);
            db('move')->prepare('UPDATE mv.MoveItems SET ToRoomCode = ? WHERE ToRoomCode = ?')
                ->execute([$new, $old]);
        } catch (Throwable $e) { /* other database unavailable */ }

        room_assign_event($new, 'room_moved', 'Moved from ' . $old . ' to building ' . $toBldg, $byNetID);
        $moved++;
    }
    rooms_publish();

    $msg = $moved . ' room(s) moved into building ' . $toBldg
         . '. Occupancy, equipment, keys and safety fixtures moved with them.';
    if ($retireSource) {
        foreach (array_keys($sources) as $src) {
            $left = $pdo->prepare('SELECT COUNT(*) FROM gis.Rooms WHERE BldgNumber = ?');
            $left->execute([$src]);
            if ((int)$left->fetchColumn() === 0) {
                $pdo->prepare('UPDATE ref.Buildings SET IsActive = 0 WHERE BldgNumber = ?')->execute([$src]);
                $msg .= ' Building ' . $src . ' retired.';
            } else {
                $msg .= ' Building ' . $src . ' still holds rooms, so it was left active.';
            }
        }
        refsync_publish(null);
    }
    return $msg;
}

// ---------------------------------------------------------- people
function user_add(array $in, string $byNetID): string
{
    $netid = strtolower(trim((string)$in['netid']));
    if ($netid === '') {
        return 'A NetID is required.';
    }
    $role = in_array($in['role'] ?? '', ['staff', 'coordinator', 'approver', 'admin'], true)
        ? $in['role'] : 'staff';
    $added = 0;
    foreach (APP_DB_NAMES as $n) {
        $pdo = db($n);
        $st = $pdo->prepare('SELECT COUNT(*) FROM app.Users WHERE NetID = ?');
        $st->execute([$netid]);
        if ((int)$st->fetchColumn() > 0) {
            continue;
        }
        $pdo->prepare('INSERT INTO app.Users (NetID, DisplayName, Email, SurveyCode, Role)
                       VALUES (?, ?, ?, ?, ?)')
            ->execute([$netid, trim((string)$in['display_name']),
                       trim((string)($in['email'] ?? '')) ?: $netid . '@illinois.edu',
                       trim((string)($in['survey'] ?? '')) ?: null, $role]);
        $added++;
    }
    return $added ? ($netid . ' added as ' . $role . ' in ' . $added . ' database(s).')
                  : ($netid . ' already exists.');
}

function user_set_role(string $netid, string $role, string $byNetID): string
{
    if (!in_array($role, ['staff', 'coordinator', 'approver', 'admin'], true)) {
        return 'Unknown role.';
    }
    if ($netid === $byNetID && $role !== 'admin') {
        return 'Change another administrator first; this would remove your own access.';
    }
    foreach (APP_DB_NAMES as $n) {
        db($n)->prepare('UPDATE app.Users SET Role = ? WHERE NetID = ?')->execute([$role, $netid]);
    }
    return $netid . ' is now ' . $role . ' in all three databases.';
}

function user_toggle_active(string $netid, string $byNetID): string
{
    if ($netid === $byNetID) {
        return 'You cannot deactivate the account you are using.';
    }
    $st = db('space')->prepare('SELECT IsActive FROM app.Users WHERE NetID = ?');
    $st->execute([$netid]);
    $to = ((int)$st->fetchColumn()) ? 0 : 1;
    foreach (APP_DB_NAMES as $n) {
        db($n)->prepare('UPDATE app.Users SET IsActive = ? WHERE NetID = ?')->execute([$to, $netid]);
    }
    return $netid . ' ' . ($to ? 'reactivated' : 'deactivated') . '.';
}

// ---------------------------------------------------------- rekey
/**
 * A lock is changed: every key to that room stops working. The old
 * assignments are voided with the reason, and the room's key code moves
 * on, so the register does not keep implying that old keys open the door.
 */
function room_rekey(string $roomCode, string $newCode, string $reason, string $byNetID): string
{
    $pdo = db('space');
    // The keys are in the keys database; the room they open is in
    // Work. They meet on the room code, which is what it is for.
    $st = db('keys')->prepare('SELECT RoomKeyID, KeyCode, HolderName FROM gis.RoomKeys
                         WHERE RoomCode = ? AND KeyState = \'Active\'');
    $st->execute([$roomCode]);
    $rows = $st->fetchAll();
    foreach ($rows as $r) {
        keys_set_state((int)$r['RoomKeyID'], 'Void',
            'Rekeyed' . ($reason !== '' ? ': ' . $reason : ''), $byNetID);
    }
    if (trim($newCode) !== '') {
        $pdo->prepare('UPDATE gis.Rooms SET KeyCodeRoom = ? WHERE RoomCode = ?')
            ->execute([trim($newCode), $roomCode]);
    }
    room_assign_event($roomCode, 'rekeyed',
        count($rows) . ' key(s) voided' . ($newCode !== '' ? ', new code ' . $newCode : ''), $byNetID);
    return 'Room ' . $roomCode . ' rekeyed: ' . count($rows) . ' active key(s) voided'
         . ($newCode !== '' ? ', room key code is now ' . $newCode : '')
         . '. Reissue keys to whoever still needs access.';
}
