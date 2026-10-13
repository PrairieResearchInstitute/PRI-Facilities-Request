<?php
declare(strict_types=1);

require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/gisload.php';

/**
 * The building map section of the admin screen: a ten thousand foot view
 * of a building and its floors, with every room clickable and everything
 * known about that room gathered behind it.
 */

function mapview_render(): void
{
    $space = db('space');
    $haveRooms = false;
    try {
        $haveRooms = (int)$space->query('SELECT COUNT(*) FROM gis.Rooms')->fetchColumn() > 0;
    } catch (Throwable $e) {
        $haveRooms = false;
    }

    if (!$haveRooms) {
        mapview_loader_panel();
        return;
    }
    if (($_GET['panel'] ?? '') === 'data') {
        mapview_loader_panel();
        return;
    }

    $buildings = $space->query(
        'SELECT r.BldgNumber,
                COALESCE(b.Name, i.Name1, r.BldgNumber) AS Name,
                COUNT(DISTINCT r.FloorCode) AS Floors, COUNT(*) AS Rooms
         FROM gis.Rooms r
         LEFT JOIN ref.Buildings b ON b.BldgNumber = r.BldgNumber
         LEFT JOIN gis.BuildingInfo i ON i.BldgNumber = r.BldgNumber
         GROUP BY r.BldgNumber, b.Name, i.Name1
         ORDER BY COALESCE(b.Name, i.Name1, r.BldgNumber)'
    )->fetchAll();

    $bldg = (string)($_GET['bldg'] ?? ($buildings[0]['BldgNumber'] ?? ''));
    $floors = $space->prepare('SELECT DISTINCT FloorCode FROM gis.Rooms WHERE BldgNumber = ? ORDER BY FloorCode');
    $floors->execute([$bldg]);
    $floors = array_column($floors->fetchAll(), 'FloorCode');
    $floor = (string)($_GET['floor'] ?? ($floors[0] ?? ''));

    // Building level summary, the ten thousand foot part.
    $sum = $space->prepare(
        'SELECT COUNT(*) AS Rooms, SUM(AreaSqFt) AS Area FROM gis.Rooms WHERE BldgNumber = ?');
    $sum->execute([$bldg]);
    $sum = $sum->fetch();

    // What is in the building is the inventory's answer, not the estate's.
    $invSum = db('equipment')->prepare(
        'SELECT COUNT(*) AS N, SUM(Cost) AS Cost, SUM(DepreciatedValue) AS Val
                              FROM gis.Inventory WHERE BldgNumber = ?');
    $invSum->execute([$bldg]);
    $invSum = $invSum->fetch();

    $occSum = $space->prepare('SELECT COUNT(DISTINCT NetID) AS N FROM gis.Occupants WHERE BldgNumber = ?');
    $occSum->execute([$bldg]);
    $occN = (int)$occSum->fetchColumn();

    $wrSum = db('work')->prepare(
        "SELECT COUNT(*) AS N,
                SUM(CASE WHEN Status NOT IN ('closed','verified','declined','merged') THEN 1 ELSE 0 END) AS OpenN,
                SUM(EstimateAmount) AS Amount
         FROM wr.WorkRequests WHERE BldgNumber = ?");
    $wrSum->execute([$bldg]);
    $wrSum = $wrSum->fetch();

    $info = $space->prepare('SELECT * FROM gis.BuildingInfo WHERE BldgNumber = ?');
    $info->execute([$bldg]);
    $info = $info->fetch() ?: [];

    ?>
    <div class="panel">
        <h2>Building map
            <a class="btn" style="float:right;font-size:0.8rem"
               href="admin.php?section=map&amp;panel=data">Data sources and keys</a></h2>
        <form method="get" class="filters">
            <input type="hidden" name="section" value="map">
            <label>Building
                <select name="bldg" onchange="this.form.submit()">
                    <?php foreach ($buildings as $b): ?>
                    <option value="<?= h($b['BldgNumber']) ?>" <?= $b['BldgNumber'] === $bldg ? 'selected' : '' ?>>
                        <?= h($b['Name']) ?> (<?= h($b['BldgNumber']) ?>) &mdash;
                        <?= (int)$b['Rooms'] ?> rooms, <?= (int)$b['Floors'] ?> floor(s)
                    </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if ($info): ?>
            <span class="muted"><?= h(trim(($info['AddressLine'] ?? '') . ' ' . ($info['City'] ?? ''))) ?>
                <?php if (!empty($info['Latitude'])): ?>
                &middot; <?= h(number_format((float)$info['Latitude'], 5)) ?>,
                          <?= h(number_format((float)$info['Longitude'], 5)) ?>
                <?php endif; ?>
            </span>
            <?php endif; ?>
        </form>

        <table>
            <tr><th>Rooms</th><th>Mapped area</th><th>People assigned</th>
                <th>Tagged property</th><th>Property value</th>
                <th>Work orders</th><th>Estimated spend</th></tr>
            <tr>
                <td><?= (int)$sum['Rooms'] ?></td>
                <td><?= number_format((float)$sum['Area']) ?> sq ft</td>
                <td><?= $occN ?></td>
                <td><?= (int)$invSum['N'] ?></td>
                <td>$<?= number_format((float)$invSum['Val'], 2) ?></td>
                <td><?= (int)$wrSum['N'] ?> (<?= (int)$wrSum['OpenN'] ?> open)</td>
                <td>$<?= number_format((float)$wrSum['Amount'], 2) ?></td>
            </tr>
        </table>
    </div>

    <div class="panel">
        <div class="tabs">
            <?php foreach ($floors as $f): ?>
            <a href="admin.php?section=map&amp;bldg=<?= h($bldg) ?>&amp;floor=<?= h($f) ?>"
               class="<?= $f === $floor ? 'active' : '' ?>">Floor <?= h($f) ?></a>
            <?php endforeach; ?>
        </div>

        <div class="maplayout">
            <div class="mapcanvas">
                <?php mapview_svg($space, $bldg, $floor); ?>
                <p class="muted">Click a room to see everything known about it. Colour shows the
                space category; rooms with open work orders are outlined.</p>
            </div>
            <div class="mapside" id="roompanel">
                <p class="muted">No room selected.</p>
            </div>
        </div>
    </div>
    <script src="assets/map.js"></script>
    <?php
    // A capture view for the briefing deck: ?present=plan strips the
    // page down to the floor plan alone; ?present=room shows the whole
    // plan beside the selected room's tab as a pop-out card. Nothing a
    // person uses day to day - it exists so the presentation can show
    // the entire building uncut, with the tab detail alongside.
    $present = (string)($_GET['present'] ?? '');
    if ($present !== '') {
        echo '<style>
            header.site, form.devswitch, nav.adminnav, footer.foot, .worknote,
            .adminbody > .panel:first-of-type, .panel > .tabs,
            .mapcanvas > p.muted, #roompanel > p.muted { display: none !important; }
            body { background: #fff; }
            main, .adminbody { margin: 0 !important; padding: 10px !important;
                               max-width: none !important; display: block !important; }
            .panel { border: 0 !important; box-shadow: none !important;
                     padding: 0 !important; margin: 0 !important; }
            .maplayout { display: flex !important; align-items: flex-start !important;
                         gap: 0 !important; }
            .mapcanvas { flex: 1 1 auto !important; max-width: none !important; }';
        if ($present === 'plan') {
            echo '.mapside { display: none !important; }
                  .mapcanvas svg { width: 100% !important; height: 1150px !important;
                                   max-height: none !important; }';
        } else {
            echo '.mapcanvas svg { width: 100% !important; height: 640px !important;
                                   max-height: none !important; }
            .mapcanvas .maplegend { display: none !important; }
            .mapside {
                flex: 0 0 540px !important; width: 540px !important;
                font-size: 1.12em !important;
                background: #fff !important; border: 2px solid #13294B !important;
                border-radius: 10px !important; padding: 12px 16px !important;
                margin: 24px 0 0 -56px !important; position: relative; z-index: 2;
                box-shadow: 0 14px 30px rgba(16,24,40,.30) !important;
                max-height: 660px; overflow: hidden;
            }';
        }
        echo '</style>';
    }
}

/** The floor plan: walls behind, room polygons in front. */
function mapview_svg(PDO $space, string $bldg, string $floor): void
{
    $fl = $space->prepare('SELECT * FROM gis.Floors WHERE BldgNumber = ? AND FloorCode = ?');
    $fl->execute([$bldg, $floor]);
    $fl = $fl->fetch();

    $rooms = $space->prepare('SELECT RoomCode, RoomID, RoomName, CategoryCode, CategoryDesc,
                                    AreaSqFt, CentroidX, CentroidY, PathData
                             FROM gis.Rooms WHERE BldgNumber = ? AND FloorCode = ?');
    $rooms->execute([$bldg, $floor]);
    $rooms = $rooms->fetchAll();

    if (!$rooms) {
        echo '<p>No mapped rooms on this floor.</p>';
        return;
    }

    // Rooms carrying an open work request are outlined on the plan.
    $open = [];
    $stmt = db('work')->prepare("SELECT RoomNumber FROM wr.WorkRequests
                            WHERE BldgNumber = ? AND Status NOT IN ('closed','verified','declined','merged')");
    $stmt->execute([$bldg]);
    foreach ($stmt as $r) {
        $k = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$r['RoomNumber']));
        $open[ltrim($k, '0') ?: '0'] = true;
    }

    // The published category colour key, so the plan matches the scheme
    // the facilities group already uses.
    $colours = [];
    try {
        foreach ($space->query("SELECT CategoryCode, CategoryName, Red, Green, Blue
                               FROM gis.CategoryColors WHERE Scope = 'detail'") as $c) {
            $colours[(string)$c['CategoryCode']] = $c;
        }
        foreach ($space->query("SELECT CategoryCode, CategoryName, Red, Green, Blue
                               FROM gis.CategoryColors WHERE Scope = 'general'") as $c) {
            $colours += [(string)$c['CategoryCode'] => $c];
        }
    } catch (Throwable $e) {
        $colours = [];
    }

    // Rooms holding safety equipment, and what kind.
    $safety = [];
    $stmt = $space->prepare('SELECT RoomCode, Kind, COUNT(*) AS N FROM gis.Safety
                            WHERE BldgNumber = ? AND RoomCode IS NOT NULL
                            GROUP BY RoomCode, Kind');
    $stmt->execute([$bldg]);
    foreach ($stmt as $r) {
        $safety[(string)$r['RoomCode']][(string)$r['Kind']] = (int)$r['N'];
    }

    if ($fl && $fl['MinX'] !== null) {
        $minX = (float)$fl['MinX'];
        $minY = (float)$fl['MinY'];
        $w = max(1, (float)$fl['MaxX'] - $minX);
        $hgt = max(1, (float)$fl['MaxY'] - $minY);
    } else {
        $minX = $minY = 0;
        $w = $hgt = 1000;
    }
    $pad = max($w, $hgt) * 0.02;

    echo '<svg id="floorplan" viewBox="' . round($minX - $pad, 1) . ' ' . round($minY - $pad, 1)
       . ' ' . round($w + 2 * $pad, 1) . ' ' . round($hgt + 2 * $pad, 1) . '" '
       . 'preserveAspectRatio="xMidYMid meet" role="img" '
       . 'aria-label="Floor plan of building ' . h($bldg) . ' floor ' . h($floor) . '">';

    if ($fl && !empty($fl['WallPath'])) {
        echo '<path class="walls" d="' . h($fl['WallPath']) . '" />';
    }

    $fontSize = max(3, min(12, $w / 120));
    $usedCats = [];
    foreach ($rooms as $r) {
        $k = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$r['RoomID']));
        $k = ltrim($k, '0') ?: '0';
        $cls = 'room';
        if (isset($open[$k])) {
            $cls .= ' hasopen';
        }
        $cat = (string)$r['CategoryCode'];
        $fill = '';
        if (isset($colours[$cat])) {
            $c = $colours[$cat];
            $fill = ' fill="rgb(' . (int)$c['Red'] . ',' . (int)$c['Green'] . ',' . (int)$c['Blue'] . ')"';
            $usedCats[$cat] = $c;
        }
        $safeBits = [];
        foreach ($safety[(string)$r['RoomCode']] ?? [] as $kind => $n) {
            $safeBits[] = $n . ' ' . str_replace('_', ' ', $kind) . ($n > 1 ? 's' : '');
        }
        if ($safeBits) {
            $cls .= ' hassafety';
        }
        $label = trim(($r['RoomName'] ? $r['RoomName'] . ', ' : '') . 'room ' . $r['RoomID']
               . ($r['CategoryDesc'] ? ', ' . $r['CategoryDesc'] : '')
               . ($r['AreaSqFt'] ? ', ' . number_format((float)$r['AreaSqFt']) . ' square feet' : '')
               . ($safeBits ? '. Safety equipment: ' . implode(', ', $safeBits) : ''));
        echo '<path class="' . h($cls) . '"' . $fill . ' d="' . h((string)$r['PathData']) . '"'
           . ' data-code="' . h($r['RoomCode']) . '" tabindex="0" role="button"'
           . ' aria-label="' . h($label) . '"><title>' . h($label) . '</title></path>';
    }
    // Safety equipment marker, so a room with an eyewash or a fume hood is
    // findable without opening it.
    foreach ($rooms as $r) {
        $bits = $safety[(string)$r['RoomCode']] ?? [];
        if (!$bits || $r['CentroidX'] === null) {
            continue;
        }
        echo '<text class="safetymark" x="' . h((string)$r['CentroidX'])
           . '" y="' . (float)$r['CentroidY'] - $fontSize . '" font-size="' . ($fontSize * 1.2) . '">&#9679;</text>';
    }
    foreach ($rooms as $r) {
        if ($r['CentroidX'] === null || (float)$r['AreaSqFt'] < 60) {
            continue;
        }
        echo '<text class="roomlabel" x="' . h((string)$r['CentroidX']) . '" y="' . h((string)$r['CentroidY'])
           . '" font-size="' . $fontSize . '">' . h((string)$r['RoomID']) . '</text>';
    }
    echo '</svg>';

    if ($usedCats) {
        ksort($usedCats);
        echo '<div class="maplegend">';
        foreach ($usedCats as $code => $c) {
            echo '<span class="legenditem"><span class="swatch" style="background:rgb('
               . (int)$c['Red'] . ',' . (int)$c['Green'] . ',' . (int)$c['Blue'] . ')"></span>'
               . h($code . ' ' . (string)$c['CategoryName']) . '</span>';
        }
        echo '<span class="legenditem"><span class="swatch safetyswatch">&#9679;</span>'
           . 'safety equipment in room</span>';
        echo '<span class="legenditem"><span class="swatch openswatch"></span>'
           . 'open work order</span>';
        echo '</div>';
    }
}

function mapview_loader_panel(): void
{
    $status = gis_extract_status();
    ?>
    <div class="panel">
        <h2>Building Map</h2>
        <p>The map draws from the facilities geodatabase and the C3PO exports, combined into one
        room table. Run the extract, then load it here.</p>
        <ol>
            <li>From the application folder run <code>py -3 tools\gis_etl.py</code>. It reads the
            geodatabase and the C3PO CSV exports and writes extracts to storage\gis.</li>
            <li>Load them below. Loading replaces the space data and leaves anything assigned in
            this application, such as a room's principal investigator, untouched.</li>
        </ol>
        <table>
            <tr><th>Extract</th><th>Rows</th><th>Written</th></tr>
            <?php foreach ($status as $file => $st): ?>
            <tr>
                <td><?= h($file) ?></td>
                <td><?= $st ? (int)$st['rows'] : '&mdash;' ?></td>
                <td class="<?= $st ? '' : 'muted' ?>"><?= $st ? h($st['when']) : 'not present' ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <p class="muted">Loading these, and the key register, is done in one place:
        <a href="admin.php?section=datafeeds">Data &rarr; Where data comes from</a>. This panel
        only reports what is currently in the extracts.</p>
    </div>
    <?php
}

