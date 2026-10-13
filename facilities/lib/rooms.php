<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * The room connector. A request records the room as free text, because a
 * reporter types what is on the door; ref.Rooms turns that into the
 * canonical RoomCode, which every room level report then joins on.
 *
 * ref.Rooms is replicated into all three databases for the same reason
 * the rest of the reference data is: a foreign key cannot cross a
 * database boundary, and each database must stay meaningful on its own.
 */

/** Comparable room key: upper case, no separators, no leading zeros. */
function room_normal(?string $s): string
{
    $t = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$s));
    $t = ltrim($t, '0');
    return $t === '' ? '' : $t;
}

/** Resolve a building number and typed room to a RoomCode, or null. */
function room_resolve(string $dbName, ?string $bldgNumber, ?string $roomText): ?string
{
    $bldg = trim((string)$bldgNumber);
    $key = room_normal($roomText);
    if ($bldg === '' || $key === '') {
        return null;
    }
    static $cache = [];
    $ck = $dbName . '|' . $bldg . '|' . $key;
    if (array_key_exists($ck, $cache)) {
        return $cache[$ck];
    }
    try {
        $stmt = db($dbName)->prepare(
            'SELECT TOP 2 RoomCode FROM ref.Rooms WHERE BldgNumber = ? AND RoomKey = ?');
        $stmt->execute([$bldg, $key]);
        $hits = $stmt->fetchAll();
    } catch (Throwable $e) {
        return $cache[$ck] = null;   // ref.Rooms not published yet
    }
    // One match is a match. Two means the same room number on two floors,
    // which the typed text cannot distinguish, so leave it unresolved
    // rather than attach the request to the wrong floor.
    $cache[$ck] = (count($hits) === 1) ? (string)$hits[0]['RoomCode'] : null;
    return $cache[$ck];
}

/**
 * Publish gis.Rooms into ref.Rooms in all three databases. Run after the
 * space data loads, and by the reference publish action.
 */
function rooms_publish(): array
{
    // The estate is where rooms come from now. Every other database gets
    // a copy in ref.Rooms so it can offer a room on a form without
    // reaching across.
    $src = db('space');
    $rows = $src->query(
        'SELECT RoomCode, BldgNumber, FloorCode, RoomID, RoomName, SurveyCode FROM gis.Rooms'
    )->fetchAll();

    $report = [];
    foreach (APP_DB_NAMES as $name) {
        $pdo = db($name);
        try {
            $pdo->exec('DELETE FROM ref.Rooms');
            $stmt = $pdo->prepare('INSERT INTO ref.Rooms
                (RoomCode, BldgNumber, FloorCode, RoomID, RoomKey, RoomName, SurveyCode)
                VALUES (?, ?, ?, ?, ?, ?, ?)');
            $pdo->beginTransaction();
            $n = 0;
            foreach ($rows as $r) {
                $stmt->execute([$r['RoomCode'], $r['BldgNumber'], $r['FloorCode'], $r['RoomID'],
                                room_normal((string)$r['RoomID']), $r['RoomName'], $r['SurveyCode']]);
                $n++;
            }
            $pdo->commit();
            $report[$name] = $n;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $report[$name] = 'failed: ' . $e->getMessage();
        }
    }
    return $report;
}

/**
 * Fill RoomCode on rows written before the connector existed, and on any
 * row whose room text was typed before the room list was published.
 */
function rooms_backfill(): array
{
    $out = [];

    $work = db('work');
    $n = 0;
    $rows = $work->query('SELECT WorkRequestID, BldgNumber, RoomNumber FROM wr.WorkRequests
                          WHERE RoomCode IS NULL AND RoomNumber IS NOT NULL')->fetchAll();
    $upd = $work->prepare('UPDATE wr.WorkRequests SET RoomCode = ? WHERE WorkRequestID = ?');
    foreach ($rows as $r) {
        $code = room_resolve('work', $r['BldgNumber'], $r['RoomNumber']);
        if ($code !== null) {
            $upd->execute([$code, (int)$r['WorkRequestID']]);
            $n++;
        }
    }
    $out['work requests'] = $n . ' of ' . count($rows) . ' resolved';

    $shop = db('shop');
    $n = 0;
    $rows = $shop->query('SELECT ShopRequestID, BldgNumber, RoomNumber FROM sh.ShopRequests
                          WHERE RoomCode IS NULL AND RoomNumber IS NOT NULL')->fetchAll();
    $upd = $shop->prepare('UPDATE sh.ShopRequests SET RoomCode = ? WHERE ShopRequestID = ?');
    foreach ($rows as $r) {
        $code = room_resolve('shop', $r['BldgNumber'], $r['RoomNumber']);
        if ($code !== null) {
            $upd->execute([$code, (int)$r['ShopRequestID']]);
            $n++;
        }
    }
    $out['shop requests'] = $n . ' of ' . count($rows) . ' resolved';

    $move = db('move');
    $n = 0;
    $rows = $move->query('SELECT MoveItemID, FromBldgNumber, FromRoom, ToBldgNumber, ToRoom
                          FROM mv.MoveItems
                          WHERE (FromRoomCode IS NULL AND FromRoom IS NOT NULL)
                             OR (ToRoomCode IS NULL AND ToRoom IS NOT NULL)')->fetchAll();
    $upd = $move->prepare('UPDATE mv.MoveItems SET FromRoomCode = ?, ToRoomCode = ? WHERE MoveItemID = ?');
    foreach ($rows as $r) {
        $from = room_resolve('move', $r['FromBldgNumber'], $r['FromRoom']);
        $to = room_resolve('move', $r['ToBldgNumber'], $r['ToRoom']);
        if ($from !== null || $to !== null) {
            $upd->execute([$from, $to, (int)$r['MoveItemID']]);
            $n++;
        }
    }
    $out['move items'] = $n . ' of ' . count($rows) . ' resolved';

    return $out;
}
