<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';

/**
 * Duplicate candidate search and similarity (spec Section 6.5).
 *
 *   similarity = w_place   * place
 *              + w_wording * jaccard(tokens_new, tokens_candidate)
 *              + w_type    * (work_type_match ? 1 : 0)
 *
 *   The weights are settings (dup_weight_place, dup_weight_wording,
 *   dup_weight_type), defaulting to 0.35 / 0.40 / 0.25 - the values that
 *   used to be written into the expression.
 *
 * Per the 2026-08-06 ruling the gate is a universal review: the person is
 * blocked only until they have looked at the open requests pulled for
 * their building, then may confirm one or file new. The server refuses
 * (409) only a submission that skipped the review, i.e. one that does not
 * acknowledge the candidates the server itself computes.
 */

const OPEN_STATUSES = ['open', 'triaged', 'awaiting_approval', 'scheduled', 'in_progress', 'on_hold'];

const STOPWORDS = ['the', 'a', 'an', 'in', 'on', 'at', 'of', 'is', 'are', 'to', 'and', 'or',
                   'for', 'with', 'my', 'our', 'it', 'its', 'there', 'this', 'that', 'has',
                   'have', 'was', 'were', 'be', 'been', 'not', 'no', 'near', 'by'];

function dedupe_synonym_map(): array
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (db('work')->query('SELECT GroupKey, Term FROM ref.TermSynonyms') as $row) {
            $map[mb_strtolower(trim($row['Term']))] = 'GRP:' . $row['GroupKey'];
        }
    }
    return $map;
}

/** lowercase, strip punctuation, drop stopwords, collapse synonyms. */
function dedupe_tokens(string $text): array
{
    $map = dedupe_synonym_map();
    $norm = mb_strtolower($text);
    $norm = preg_replace('/[^a-z0-9\s]/u', ' ', $norm);

    // Multi-word synonym phrases collapse first.
    foreach ($map as $term => $group) {
        if (str_contains($term, ' ') && str_contains($norm, $term)) {
            $norm = str_replace($term, ' ' . $group . ' ', $norm);
        }
    }
    $out = [];
    foreach (preg_split('/\s+/', trim($norm)) as $tok) {
        if ($tok === '' || in_array($tok, STOPWORDS, true)) {
            continue;
        }
        $out[$map[$tok] ?? $tok] = true;
    }
    return array_keys($out);
}

function dedupe_jaccard(array $a, array $b): float
{
    if (!$a || !$b) {
        return 0.0;
    }
    $ia = array_intersect($a, $b);
    $ua = array_unique(array_merge($a, $b));
    return count($ia) / count($ua);
}

function dedupe_place(array $new, array $cand): float
{
    if (!empty($new['lat']) && !empty($new['lon']) && !empty($cand['Latitude']) && !empty($cand['Longitude'])) {
        $d = dedupe_distance_m((float)$new['lat'], (float)$new['lon'], (float)$cand['Latitude'], (float)$cand['Longitude']);
        return 1 - min(1, $d / max(1, (float)setting('dup_radius_meters')));
    }
    if (!empty($new['room']) && !empty($cand['RoomNumber'])) {
        if (strcasecmp(trim($new['room']), trim($cand['RoomNumber'])) === 0) {
            return 1.0;
        }
        if (!empty($new['floor']) && !empty($cand['FloorCode'])
            && strcasecmp(trim($new['floor']), trim($cand['FloorCode'])) === 0) {
            return 0.5;
        }
        return 0.0;
    }
    if (!empty($new['location']) && !empty($cand['WrittenLocation'])) {
        return dedupe_jaccard(dedupe_tokens($new['location']), dedupe_tokens($cand['WrittenLocation']));
    }
    return 0.25;   // same building is all we know
}

function dedupe_distance_m(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $r = 6371000;
    $p1 = deg2rad($lat1);
    $p2 = deg2rad($lat2);
    $dp = deg2rad($lat2 - $lat1);
    $dl = deg2rad($lon2 - $lon1);
    $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
    return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/**
 * Candidates for a prospective work request. $input keys: building_id,
 * type_id, text (title or one-line), room, floor, location, lat, lon.
 * Returns rows sorted by similarity descending, each with '_similarity'.
 */
function dedupe_candidates(array $input): array
{
    $pdo = db('work');
    $window = (int)setting('dup_window_days');
    $place = str_repeat('?,', count(OPEN_STATUSES) - 1) . '?';
    // $window is an integer setting, interpolated because a bound parameter
    // inside DATEADD is typed as nvarchar by the driver and rejected.
    $sql = "SELECT WorkRequestID, TicketNumber, Title, Description, RoomNumber, FloorCode,
                   WrittenLocation, Latitude, Longitude, WorkTypeID, Status, CreatedAt,
                   (SELECT COUNT(*) FROM wr.Corroborations c WHERE c.WorkRequestID = w.WorkRequestID) AS Confirmations
            FROM wr.WorkRequests w
            WHERE BuildingID = ? AND Status IN ($place)
              AND CreatedAt >= DATEADD(day, -$window, SYSDATETIME())";
    $params = array_merge([(int)$input['building_id']], OPEN_STATUSES);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $newTokens = dedupe_tokens((string)($input['text'] ?? ''));

    // How much each part of the comparison counts. These were written into
    // the expression, which put them beyond reach of anyone tuning the
    // matching - against rule 10, where every weight is a setting. The
    // defaults are the values that were hardcoded, so nothing changes
    // until somebody deliberately changes it; adding the three keys to
    // app.Settings is enough to take over.
    $wPlace = (float)setting('dup_weight_place', 0.35);
    $wWords = (float)setting('dup_weight_wording', 0.40);
    $wType  = (float)setting('dup_weight_type', 0.25);
    if ($wPlace + $wWords + $wType <= 0) {
        [$wPlace, $wWords, $wType] = [0.35, 0.40, 0.25];
    }

    $out = [];
    foreach ($stmt as $cand) {
        $candTokens = dedupe_tokens($cand['Title'] . ' ' . mb_substr((string)$cand['Description'], 0, 400));
        $sim = $wPlace * dedupe_place($input, $cand)
             + $wWords * dedupe_jaccard($newTokens, $candTokens)
             + $wType  * ((int)($input['type_id'] ?? 0) === (int)$cand['WorkTypeID'] ? 1 : 0);
        $cand['_similarity'] = round($sim, 3);
        $out[] = $cand;
    }
    usort($out, fn($a, $b) => $b['_similarity'] <=> $a['_similarity']);
    return $out;
}

/**
 * The subset of candidates the server insists were acknowledged before a
 * create is accepted (spec 6.3). Threshold from settings.
 */
function dedupe_blocking(array $candidates): array
{
    // NOTE: this gates the COMBINED score - place, wording and work type
    // together - not the wording alone, despite the setting being labelled
    // "Duplicate text threshold" in Administration. An administrator
    // raising it to make the wording match stricter is in fact moving the
    // bar on the whole blend. Behaviour is left as it is because requests
    // have been judged against it; the label is what is wrong, and
    // correcting it means editing app.Settings, which is being left alone
    // until the move to Radiant. dup_score_threshold takes precedence if
    // somebody adds it, which is the clean way out.
    $t = (float)setting('dup_score_threshold', (float)setting('dup_text_threshold'));
    return array_values(array_filter($candidates, fn($c) => $c['_similarity'] >= $t));
}
