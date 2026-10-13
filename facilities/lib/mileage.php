<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/picklists.php';

/**
 * Odometer readings.
 *
 * A reading is accepted or it is not, and the interesting cases are in
 * between: a number lower than the last one, or one implying a fortnight's
 * driving in a day. Those are refused and flagged respectively, because a
 * reading that went backwards is certainly a mistake and a reading that
 * is merely surprising might be a trip to Chicago.
 *
 * Nothing here computes the last reading by scanning the history. The
 * vehicle carries its own, so a list of three hundred and twenty six
 * vehicles is one query.
 */

/** One vehicle by its fleet number, with its last reading. */
function mileage_vehicle(string $fleetNumber): ?array
{
    $st = db('fleet')->prepare(
        'SELECT * FROM ref.FleetVehicles WHERE FleetNumber = ? AND IsActive = 1');
    $st->execute([trim($fleetNumber)]);
    return $st->fetch() ?: null;
}

/** The last reading on record, or null. */
function mileage_last(string $fleetNumber): ?array
{
    $st = db('fleet')->prepare(
        'SELECT TOP 1 * FROM sh.VehicleReadings WHERE FleetNumber = ?
         ORDER BY ReadOn DESC, ReadingID DESC');
    $st->execute([trim($fleetNumber)]);
    return $st->fetch() ?: null;
}

/** This vehicle's readings, newest first. */
function mileage_history(string $fleetNumber, int $limit = 40): array
{
    $st = db('fleet')->prepare(
        'SELECT TOP ' . max(1, min(500, $limit)) . ' * FROM sh.VehicleReadings
         WHERE FleetNumber = ? ORDER BY ReadOn DESC, ReadingID DESC');
    $st->execute([trim($fleetNumber)]);
    return $st->fetchAll();
}

/**
 * Record a reading.
 *
 * Returns ['id'=>…, 'queried'=>bool, 'note'=>string] or ['error'=>…].
 * $in: fleet, miles, read_on, source, note, suggested (the number the
 * photograph proposed, if one did), was_suggested, photo_id.
 */
function mileage_record(array $in, array $user): array
{
    $fleet = trim((string)($in['fleet'] ?? ''));
    $vehicle = mileage_vehicle($fleet);
    if (!$vehicle) {
        // No escaping here: a library returns text, and the page that
        // shows it is the one that knows it is going into HTML.
        return ['error' => 'There is no active vehicle with fleet number ' . $fleet . '.'];
    }

    $raw = preg_replace('/[^0-9]/', '', (string)($in['miles'] ?? '')) ?? '';
    if ($raw === '') {
        return ['error' => 'Type the odometer reading.'];
    }
    $miles = (int)$raw;
    if ($miles > 2000000) {
        return ['error' => number_format($miles) . ' miles is not a reading. Check the digits.'];
    }

    $readOn = trim((string)($in['read_on'] ?? ''));
    $ts = $readOn !== '' ? strtotime($readOn) : time();
    if ($ts === false) {
        return ['error' => 'That date could not be read.'];
    }
    if ($ts > strtotime('tomorrow')) {
        return ['error' => 'That date is in the future.'];
    }
    $readDate = new DateTimeImmutable(date('Y-m-d', $ts));

    // An odometer does not go backwards. If this one appears to, either
    // the vehicle is wrong or the number is, and both are worth stopping
    // for rather than quietly recording.
    $last = mileage_last($fleet);
    $sinceMiles = null;
    $sinceDays = null;
    $queried = false;
    $queryNote = '';

    if ($last) {
        $lastMiles = (int)$last['Miles'];
        $lastOn = new DateTimeImmutable((string)$last['ReadOn']);
        if ($miles < $lastMiles) {
            return ['error' => number_format($miles) . ' is lower than the last reading of '
                             . number_format($lastMiles) . ' on '
                             . $lastOn->format('M j, Y') . '. Check you have the right vehicle, '
                             . 'and the right number of digits.'];
        }
        $sinceMiles = $miles - $lastMiles;
        $sinceDays = max(0, (int)$lastOn->diff($readDate)->days);

        $perDay = (int)setting('mileage_max_per_day', '600');
        $span = max(1, $sinceDays);
        if ($perDay > 0 && $sinceMiles / $span > $perDay) {
            $queried = true;
            $queryNote = number_format($sinceMiles) . ' miles in '
                . ($sinceDays === 0 ? 'the same day' : $sinceDays . ' day'
                    . ($sinceDays === 1 ? '' : 's'))
                . ', against a limit of ' . number_format($perDay) . ' a day.';
        }
    }

    $source = in_array((string)($in['source'] ?? ''),
                       ['qr', 'manual', 'booking', 'shop', 'import'], true)
        ? (string)$in['source'] : 'manual';

    $suggested = preg_replace('/[^0-9]/', '', (string)($in['suggested'] ?? '')) ?? '';

    $pdo = db('fleet');
    $pdo->prepare(
        'INSERT INTO sh.VehicleReadings (FleetNumber, Miles, ReadOn, ReadByNetID, ReadByName,
            SurveyCode, Source, WasSuggested, SuggestedMiles, PhotoAttachmentID, Note,
            MilesSinceLast, DaysSinceLast, IsQueried, QueryNote)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $fleet, $miles, $readDate->format('Y-m-d'), $user['NetID'],
        $user['DisplayName'] ?? null,
        (string)($vehicle['SurveyCode'] ?? '') ?: ($user['SurveyCode'] ?? null),
        $source,
        !empty($in['was_suggested']) ? 1 : 0,
        $suggested !== '' ? (int)$suggested : null,
        (int)($in['photo_id'] ?? 0) ?: null,
        trim((string)($in['note'] ?? '')) ?: null,
        $sinceMiles, $sinceDays,
        $queried ? 1 : 0, $queryNote ?: null,
    ]);
    $id = (int)$pdo->lastInsertId();

    // Only move the vehicle's own copy forward if this really is the
    // newest reading - somebody catching up on a notebook from last month
    // should not roll it back.
    $pdo->prepare(
        'UPDATE ref.FleetVehicles SET LastMiles = ?, LastMilesOn = ?
         WHERE FleetNumber = ? AND (LastMilesOn IS NULL OR LastMilesOn <= ?)')
        ->execute([$miles, $readDate->format('Y-m-d'), $fleet, $readDate->format('Y-m-d')]);

    return ['id' => $id, 'queried' => $queried, 'note' => $queryNote,
            'since' => $sinceMiles, 'days' => $sinceDays];
}

/** The address a sticker should point at. */
function mileage_base_url(): string
{
    $set = trim((string)setting('mileage_base_url', ''));
    if ($set !== '') {
        return rtrim($set, '/') . '/';
    }
    // With no request to infer from there is nothing to guess with, and a
    // guess would end up printed on three hundred stickers. Better an
    // address that is obviously wrong than one that looks right.
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') {
        return 'SET-THIS-IN-SETTINGS/';
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $dir = rtrim(dirname(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    if ($dir === '.' || $dir === '/') {
        $dir = '';
    }
    return $scheme . '://' . $host . $dir . '/';
}

/** What goes in one vehicle's QR code. */
function mileage_sticker_url(string $fleetNumber): string
{
    return mileage_base_url() . 'm.php?v=' . rawurlencode($fleetNumber);
}

/** Vehicles, with how stale their reading is. */
function mileage_fleet(string $q = '', string $survey = ''): array
{
    $where = ['IsActive = 1'];
    $params = [];
    if (trim($q) !== '') {
        $where[] = '(FleetNumber LIKE ? OR Description LIKE ? OR PlateNumber LIKE ?
                     OR Make LIKE ? OR Model LIKE ? OR AssignedTo LIKE ?)';
        $like = '%' . trim($q) . '%';
        $params = array_fill(0, 6, $like);
    }
    if (trim($survey) !== '') {
        $where[] = 'SurveyCode = ?';
        $params[] = trim($survey);
    }
    $st = db('fleet')->prepare(
        'SELECT *, DATEDIFF(day, LastMilesOn, CAST(SYSDATETIME() AS DATE)) AS DaysSince
         FROM ref.FleetVehicles WHERE ' . implode(' AND ', $where)
         . ' ORDER BY SurveyCode, FleetNumber');
    $st->execute($params);
    return $st->fetchAll();
}

/** Readings somebody should look at. */
function mileage_queried(int $limit = 50): array
{
    return db('fleet')->query(
        'SELECT TOP ' . max(1, min(200, $limit)) . ' * FROM sh.VehicleReadings
         WHERE IsQueried = 1 ORDER BY CreatedAt DESC')->fetchAll();
}

/**
 * How often the photograph's suggestion was right.
 *
 * The point of keeping both numbers is to be able to answer this. A
 * suggestion that is usually wrong is worse than no suggestion, and the
 * only way to know which it is, is to count.
 */
function mileage_suggestion_record(): array
{
    $row = db('fleet')->query(
        'SELECT COUNT(*) AS n,
                SUM(CASE WHEN SuggestedMiles = Miles THEN 1 ELSE 0 END) AS matched,
                SUM(CASE WHEN WasSuggested = 1 THEN 1 ELSE 0 END) AS accepted
         FROM sh.VehicleReadings WHERE SuggestedMiles IS NOT NULL')->fetch();
    $n = (int)($row['n'] ?? 0);
    return [
        'offered'  => $n,
        'matched'  => (int)($row['matched'] ?? 0),
        'accepted' => (int)($row['accepted'] ?? 0),
        'percent'  => $n > 0 ? round((int)$row['matched'] * 100 / $n) : null,
    ];
}
