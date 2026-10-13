<?php
declare(strict_types=1);

/**
 * The databases, drawn the way database diagrams are drawn.
 *
 * Bare, the overview: ten cylinders in two rows, the join-bearing tables
 * under each, dashed labelled lines for the natural keys. With ?db=space
 * one database, stacked: the cylinder on top, its tables in a single
 * column beneath, every drawing the same size so ten slides sit
 * identically.
 *
 * Type sizes are chosen from the back of the room and everything else
 * bends to them: at most six tables drawn per database, and the count in
 * the cylinder lid admits the rest. Each named table is checked against
 * the live catalogue; one that does not exist renders red.
 */

require_once __DIR__ . '/lib/db.php';

if (!app_installed()) {
    header('Location: install.php');
    exit;
}

/** The single-database view: at most six tables, the ones that matter. */
const DETAIL = [
    'work' => ['wr.WorkRequests', 'wr.Approvals', 'wr.DedupDecisions',
               'ref.People', 'ref.OrgUnits', 'ref.Natures'],
    'move' => ['mv.MoveRequests', 'mv.MoveItems', 'mv.Reviews'],
    'shop' => ['sh.ShopRequests', 'sh.Approvals', 'ref.CfopAccounts'],
    'purchasing' => ['rq.PurchaseRequests', 'rq.PurchaseItems', 'rq.PurchaseFunding',
                     'rq.PurchaseEvents', 'ref.BannerAccountCodes', 'ref.CfopAccounts'],
    'keys' => ['gis.RoomKeys', 'gis.KeyEvents', 'rq.KeyRequests', 'rq.Approvals'],
    'fleet' => ['ref.FleetVehicles', 'sh.VehicleReadings', 'sh.VehicleBookings',
                'sh.DriverForms', 'ref.DriverFormTypes'],
    'space' => ['gis.Rooms', 'ref.Buildings', 'est.Agreements',
                'gis.Safety', 'gis.Occupants', 'rq.SpaceRequests'],
    'equipment' => ['gis.Inventory', 'gis.InventoryEvents', 'rq.PropertyUpdates'],
    'incidents' => ['rq.Incidents', 'rq.IncidentPeople', 'rq.IncidentActions',
                    'rq.IncidentEvents'],
    'plans' => ['rq.SafetyPlans', 'rq.PlanRooms', 'rq.PlanReviews', 'ref.PlanTypes'],
];

const SHORT = [
    'work' => 'Work', 'move' => 'Move', 'shop' => 'Shop', 'purchasing' => 'Purchasing',
    'keys' => 'Keys', 'fleet' => 'Fleet', 'space' => 'Space', 'equipment' => 'Equipment',
    'incidents' => 'Incidents', 'plans' => 'Plans',
];

function esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES);
}

function table_exists(string $db, string $table): bool
{
    try {
        return db_has_table($db, $table);
    } catch (Throwable $e) {
        return false;
    }
}

function table_count(string $db): int
{
    try {
        return count(db_tables($db));
    } catch (Throwable $e) {
        return 0;
    }
}

/** A cylinder with the table count sitting in its lid. */
function svg_cylinder(float $cx, float $top, float $w, float $h, int $count,
                      float $sw, float $countSize): string
{
    // The lid is deep enough to hold the table count inside it. At the
    // count's widest the ellipse still clears the cap height: at 0.155w
    // a 9-character label at 48px has 50px of lid under it and needs 35.
    $ry = $w * 0.155;
    $left = $cx - $w / 2;
    $right = $cx + $w / 2;
    $bodyTop = $top + $ry;
    $bodyBot = $top + $h;
    $out = "<path d=\"M $left $bodyTop V $bodyBot A " . ($w / 2) . " $ry 0 0 0 $right $bodyBot "
         . "V $bodyTop\" fill=\"#fff\" stroke=\"#1D3557\" stroke-width=\"$sw\"/>";
    $out .= "<ellipse cx=\"$cx\" cy=\"$bodyTop\" rx=\"" . ($w / 2) . "\" ry=\"$ry\" "
          . "fill=\"#EAF0F6\" stroke=\"#1D3557\" stroke-width=\"$sw\"/>";
    foreach ([0.42, 0.76] as $f) {
        $y = $bodyTop + ($h - $ry) * $f;
        $out .= "<path d=\"M $left $y A " . ($w / 2) . " $ry 0 0 0 $right $y\" fill=\"none\" "
              . "stroke=\"#1D3557\" stroke-width=\"" . ($sw * 0.5) . "\" opacity=\"0.6\"/>";
    }
    // The count sits ON the lid, centred in it. In the body it straddled
    // the ridge arcs and needed a white halo to stay readable, and that
    // halo punched holes through the lid's own edge.
    $out .= "<text x=\"$cx\" y=\"" . ($bodyTop + $countSize * 0.36)
          . "\" text-anchor=\"middle\" font-size=\"$countSize\" font-weight=\"bold\" "
          . 'fill="#1D3557">' . $count . ' tables</text>';
    return $out;
}

/** One tilted grid panel with its name above; dashed = kept elsewhere. */
function svg_panel(float $cx, float $y, float $w, float $h, float $shear, string $name,
                   bool $ok, float $fontSize, bool $dash = false): string
{
    $x = $cx - ($w + $shear) / 2;
    $blx = $x;              $bly = $y + $h;
    $brx = $x + $w;
    $tlx = $x + $shear;
    $trx = $x + $shear + $w;
    $stroke = $ok ? '#1D3557' : '#B3261E';
    $ink = $ok ? '#2B2B2B' : '#B3261E';
    $label = $name . ($ok ? '' : '  (missing)');
    $dd = $dash ? ' stroke-dasharray="12 9"' : '';
    $out = "<polygon points=\"$tlx,$y $trx,$y $brx,$bly $blx,$bly\" fill=\"#fff\" "
         . "stroke=\"$stroke\" stroke-width=\"2.4\"$dd/>";
    for ($c = 1; $c < 5; $c++) {
        $out .= '<line x1="' . ($tlx + $w * $c / 5) . "\" y1=\"$y\" x2=\""
              . ($blx + $w * $c / 5) . "\" y2=\"$bly\" stroke=\"#1D3557\" "
              . 'stroke-width="1" opacity="0.35"/>';
    }
    for ($r = 1; $r < 3; $r++) {
        $yy = $y + $h * $r / 3;
        $out .= '<line x1="' . ($tlx - $shear * $r / 3) . "\" y1=\"$yy\" x2=\""
              . ($trx - $shear * $r / 3) . "\" y2=\"$yy\" stroke=\"#1D3557\" "
              . 'stroke-width="1" opacity="0.35"/>';
    }
    $out .= '<text x="' . ($x + ($w + $shear) / 2) . '" y="' . ($y - 12) . '" '
          . "text-anchor=\"middle\" font-size=\"$fontSize\" font-weight=\"bold\" "
          . "fill=\"$ink\">" . esc($label) . '</text>';
    return $out;
}

header('Content-Type: text/html; charset=utf-8');

// ============================================================ processes
/**
 * The flows: [steps, lands]. A step is [label, sub, drop]; a label
 * starting '?' renders as a decision diamond, and 'drop' is the branch
 * that leaves the happy path, drawn beneath.
 */
require_once __DIR__ . '/lib/flowdefs.php';


$flow = (string)($_GET['flow'] ?? '');
if ($flow !== '' && isset(FLOWS[$flow])) {
    [$steps, $lands] = FLOWS[$flow];
    $n = count($steps);
    $W = 2400;
    $hasDrop = false;
    foreach ($steps as $st) {
        if ($st[2] !== null) {
            $hasDrop = true;
        }
    }
    $H = $hasDrop ? 640 : 480;
    $margin = 50;
    $gap = 92;
    $bw = (int)(($W - 2 * $margin - $gap * ($n - 1)) / $n);
    $bh = 170;
    $topY = 80;

    $two = function (string $t): array {
        if (strlen($t) <= 26) {
            return [$t];
        }
        $mid = (int)(strlen($t) / 2);
        $sp = strrpos(substr($t, 0, $mid + 6), ' ');
        return $sp === false ? [$t] : [substr($t, 0, $sp), substr($t, $sp + 1)];
    };
    ?><!DOCTYPE html>
<html><head><meta charset="utf-8"><title><?= esc($flow) ?></title>
<style>html,body{margin:0;background:#fff;overflow:hidden}</style></head><body>
<svg width="<?= $W ?>" height="<?= $H ?>" viewBox="0 0 <?= $W ?> <?= $H ?>"
     xmlns="http://www.w3.org/2000/svg" font-family="Calibri, sans-serif">
<defs><marker id="a" markerWidth="12" markerHeight="10" refX="10" refY="5" orient="auto">
<path d="M0,0 L12,5 L0,10 z" fill="#1D3557"/></marker>
<marker id="o" markerWidth="12" markerHeight="10" refX="10" refY="5" orient="auto">
<path d="M0,0 L12,5 L0,10 z" fill="#C7461F"/></marker></defs>

<?php foreach ($steps as $i => [$label, $sub, $drop]): ?>
<?php
    $x = $margin + $i * ($bw + $gap);
    $cx = $x + $bw / 2;
    $isQ = str_starts_with($label, '?');
    $text = $isQ ? trim($label, '?') : $label;
?>
    <?php if ($i > 0): ?>
    <line x1="<?= $x - $gap + 6 ?>" y1="<?= $topY + $bh / 2 ?>" x2="<?= $x - 8 ?>"
          y2="<?= $topY + $bh / 2 ?>" stroke="#1D3557" stroke-width="4" marker-end="url(#a)"/>
    <?php endif; ?>
    <?php if ($isQ): ?>
    <polygon points="<?= $cx ?>,<?= $topY - 14 ?> <?= $x + $bw + 22 ?>,<?= $topY + $bh / 2 ?>
                     <?= $cx ?>,<?= $topY + $bh + 14 ?> <?= $x - 22 ?>,<?= $topY + $bh / 2 ?>"
             fill="#FDF6F2" stroke="#C7461F" stroke-width="3.5"/>
    <?php else: ?>
    <rect x="<?= $x ?>" y="<?= $topY ?>" width="<?= $bw ?>" height="<?= $bh ?>" rx="16"
          fill="#EAF0F6" stroke="#1D3557" stroke-width="3.5"/>
    <?php endif; ?>
    <text x="<?= $cx ?>" y="<?= $topY + ($sub ? 72 : 95) ?>" text-anchor="middle"
          font-size="38" font-weight="bold" font-family="Calibri, sans-serif"
          fill="#1D3557"><?= esc($text) ?></text>
    <?php if ($sub): ?>
    <?php foreach ($two($sub) as $li => $line): ?>
    <text x="<?= $cx ?>" y="<?= $topY + 108 + $li * 30 ?>" text-anchor="middle"
          font-size="32" fill="#444"><?= esc($line) ?></text>
    <?php endforeach; ?>
    <?php endif; ?>
    <?php if ($drop !== null): ?>
    <line x1="<?= $cx ?>" y1="<?= $topY + $bh + 16 ?>" x2="<?= $cx ?>" y2="<?= $topY + $bh + 92 ?>"
          stroke="#C7461F" stroke-width="3.5" stroke-dasharray="10 8" marker-end="url(#o)"/>
    <rect x="<?= $cx - $bw / 2 - 20 ?>" y="<?= $topY + $bh + 100 ?>" width="<?= $bw + 40 ?>"
          height="96" rx="14" fill="#fff" stroke="#C7461F" stroke-width="2.6"
          stroke-dasharray="10 8"/>
    <?php foreach ($two($drop) as $li => $line): ?>
    <text x="<?= $cx ?>" y="<?= $topY + $bh + 140 + $li * 30 ?>" text-anchor="middle"
          font-size="32" fill="#8a3a12"><?= esc($line) ?></text>
    <?php endforeach; ?>
    <?php endif; ?>
<?php endforeach; ?>

<rect x="<?= $W - 640 ?>" y="<?= $H - 74 ?>" width="640" height="56" rx="28"
      fill="#fff" stroke="#1D3557" stroke-width="2.5"/>
<text x="<?= $W - 340 ?>" y="<?= $H - 39 ?>" text-anchor="middle" font-size="32"
      font-weight="bold" fill="#1D3557">Data lands in  <?= esc($lands) ?></text>
</svg>
</body></html>
<?php
    exit;
}

// ============================================================ one database
$one = (string)($_GET['db'] ?? '');
if ($one !== '' && isset(DETAIL[$one])) {
    $tables = DETAIL[$one];
    $total = table_count($one);
    $n = count($tables);

    // The canvas hugs its content - three tables make a short drawing,
    // six a tall one - so no slide carries a page of blank air.
    $W = 900;
    $pitch = 142;
    $firstY = 356;
    $H = $firstY + ($n - 1) * $pitch + 76 + 66;
    $cx = $W / 2;
    ?><!DOCTYPE html>
<html><head><meta charset="utf-8"><title><?= esc(SHORT[$one]) ?></title>
<style>html,body{margin:0;background:#fff;overflow:hidden}</style></head><body>
<svg width="<?= $W ?>" height="<?= $H ?>" viewBox="0 0 <?= $W ?> <?= $H ?>"
     xmlns="http://www.w3.org/2000/svg" font-family="Calibri, sans-serif">

    <?php // the stem the panels hang off, behind everything ?>
    <line x1="<?= $cx ?>" y1="300" x2="<?= $cx ?>"
          y2="<?= $firstY + ($n - 1) * $pitch + 30 ?>"
          stroke="#1D3557" stroke-width="2.5" opacity="0.45"/>

    <?= svg_cylinder($cx, 14, 300, 160, $total, 4, 27) ?>
    <text x="<?= $cx ?>" y="276" text-anchor="middle" font-size="52"
          font-family="Calibri, sans-serif" font-weight="bold"
          fill="#1D3557">PRI_Facilities<?= esc(SHORT[$one]) ?></text>

    <?php foreach ($tables as $i => $t): ?>
    <?= svg_panel($cx, $firstY + $i * $pitch, 580, 72, 50, $t,
                  table_exists($one, $t), 38) ?>
    <?php endforeach; ?>

    <text x="<?= $cx ?>" y="<?= $H - 26 ?>" text-anchor="middle" font-size="30"
          fill="#6B7280">+ <?= $total - $n ?> shared reference and history tables</text>
</svg>
</body></html>
<?php
    exit;
}

// ============================================================ the overview
$columns = [
    'keys'       => [1, 1, ['gis.RoomKeys']],
    'equipment'  => [1, 2, ['gis.Inventory']],
    'space'      => [1, 3, ['gis.Rooms', 'ref.Buildings', 'est.Agreements']],
    'work'       => [1, 4, ['wr.WorkRequests']],
    'incidents'  => [1, 5, ['rq.Incidents']],
    'move'       => [2, 1, ['mv.MoveItems']],
    'purchasing' => [2, 2, ['rq.PurchaseRequests', 'rq.PurchaseItems']],
    'shop'       => [2, 3, ['sh.ShopRequests']],
    'fleet'      => [2, 4, ['ref.FleetVehicles']],
    'plans'      => [2, 5, ['rq.SafetyPlans', 'rq.PlanRooms']],
];

require_once __DIR__ . '/lib/joinmap.php';

/**
 * The joins, drawn in full.
 *
 * Every shared name gets its own horizontal lane in the band between the
 * two rows, in its own colour, and every database that carries that name
 * drops a straight vertical stub onto it. Orthogonal only: a lane is
 * horizontal, a stub is vertical, and no line ever crosses a panel. That
 * is the difference between this and the version that read as clutter -
 * there, lines ran diagonally wherever they liked and collided.
 *
 * A filled dot marks the register that OWNS the name. Everything else on
 * that lane joins to the dot, not to its neighbours: Keys and Incidents
 * both sit on the RoomCode lane, and both are joining gis.Rooms.
 *
 * Sizing note: the drawing is height-bound in its slide box, so every
 * pixel of height costs placed type size. The whole layout is derived
 * from FS below and checked against the 12pt floor after capture.
 */
// Placed size is (font px / drawing height) x box height. At 48px this
// drawing landed at 12.47pt - above the floor, but the floor is not the
// target. 50px puts it at 13pt with room to spare.
const FS = 50;                 // panel labels, tray and legend
const FS_NAME = 56;            // the database names
const FS_CYL = 48;             // the table count in the lid

const PANEL_W = 430;
const PANEL_H = 76;
const SHEAR = 44;
const PITCH = 142;
const CYL_W = 270;
const CYL_H = 112;
const LANE_PITCH = 30;

const ROW1_CYL_TOP = 12;
const ROW1_NAME = ROW1_CYL_TOP + CYL_H + 80;          // 204
const ROW1_PANEL = ROW1_NAME + 70;                    // 274
const ROW1_BOTTOM = ROW1_PANEL + 2 * PITCH + PANEL_H; // deepest column: 3 panels
const LANE_TOP = ROW1_BOTTOM + 26;
const LANE_BOTTOM = LANE_TOP + 6 * LANE_PITCH;        // seven lanes, 0..6
const ROW2_CYL_TOP = LANE_BOTTOM + 30;
const ROW2_NAME = ROW2_CYL_TOP + CYL_H + 80;
const ROW2_PANEL = ROW2_NAME + 70;
const ROW2_BOTTOM = ROW2_PANEL + PITCH + PANEL_H;     // deepest: 2 panels
const NOTE_Y = ROW2_BOTTOM + 58;

// The right-hand column: the reference tray, and under it the key to the
// lanes. The legend used to run across the foot, which cost the whole
// drawing a band of height - and height is what sets placed type size on
// a drawing this shape. Standing it up in the margin under the tray uses
// space that was white anyway, and the schema grows into what it frees.
const RIGHT_X = 2610;
// Wide enough for the longest legend line on ONE line. Two lines per
// entry read worse, not better: at a legal type size the owner line
// ends up equidistant between its own name and the next one.
const RIGHT_W = 900;
const TRAY_Y = 90;
const TRAY_H = 250 + 2 * PITCH + PANEL_H + 46;
const TRAY_X = RIGHT_X;
const TRAY_W = RIGHT_W;

const FS_LEG = 48;                                    // the legend line
const LEGEND_X = RIGHT_X;
const LEGEND_W = RIGHT_W;
const LEGEND_Y = TRAY_Y + TRAY_H + 44;
const LEGEND_PITCH = 68;
const LEGEND_H = 30 + 7 * LEGEND_PITCH + 20;

const DW = RIGHT_X + RIGHT_W + 20;
// Whichever column runs deeper sets the canvas. A ternary, not max():
// a constant expression cannot call a function.
const DH = (NOTE_Y + 30 > LEGEND_Y + LEGEND_H + 20)
         ? NOTE_Y + 30 : LEGEND_Y + LEGEND_H + 20;

$centers = [1 => 262, 2 => 774, 3 => 1286, 4 => 1798, 5 => 2310];
$rowCylTop = [1 => ROW1_CYL_TOP, 2 => ROW2_CYL_TOP];
$rowPanelTop = [1 => ROW1_PANEL, 2 => ROW2_PANEL];

$panelXY = function (string $db, int $i) use ($columns, $centers, $rowPanelTop): array {
    [$row, $col] = [$columns[$db][0], $columns[$db][1]];
    $x = $centers[$col] - (PANEL_W + SHEAR) / 2;
    $y = $rowPanelTop[$row] + $i * PITCH;
    return [$x + (PANEL_W + SHEAR) / 2, $y + PANEL_H / 2, $y];
};

// One lane per shared name, in the order the legend reads.
$laneOrder = array_keys(JOIN_KEYS);
$map = join_map();
// Which drawn column owns each name, so the hub dot lands on it.
$ownerCol = ['RoomCode' => 'space', 'BldgNumber' => 'space',
             'PropertyTag' => 'equipment', 'FleetNumber' => 'fleet'];
// The two names owned by a table in the tray, and which tray panel it is,
// so the line ends on ref.People itself rather than pointing vaguely at
// the box it happens to sit in. ref.Surveys is PRI's own published
// reference - it is not in the tray, so its lane simply ends in its dot.
$ownerTray = ['NetID' => 2, 'CfopCode' => 1];
$trayPanelY = fn(int $i): float => TRAY_Y + 250 + $i * PITCH + PANEL_H / 2;
$ownerShort = ['RoomCode' => 'gis.Rooms', 'BldgNumber' => 'ref.Buildings',
               'NetID' => 'ref.People', 'SurveyCode' => 'ref.Surveys',
               'CfopCode' => 'ref.CfopAccounts', 'PropertyTag' => 'gis.Inventory',
               'FleetNumber' => 'ref.FleetVehicles'];

/** Where a column's stub for a given lane leaves its own shape. */
$stubX = function (string $db, int $lane) use ($columns, $centers): float {
    return $centers[$columns[$db][1]] + ($lane - 3) * 36;
};
$columnFoot = function (string $db) use ($columns, $rowPanelTop): float {
    // Row 1 stubs leave the bottom of the column's lowest panel; row 2
    // stubs land on the top of its cylinder. Either way the line starts
    // on the database it belongs to and never crosses another shape.
    [$row, , $tables] = $columns[$db];
    return $row === 1
        ? $rowPanelTop[1] + (count($tables) - 1) * PITCH + PANEL_H
        : ROW2_CYL_TOP;
};
?><!DOCTYPE html>
<html><head><meta charset="utf-8"><title>The Ten Databases</title>
<style>html,body{margin:0;background:#fff;overflow:hidden}</style></head><body>
<svg width="<?= DW ?>" height="<?= DH ?>" viewBox="0 0 <?= DW ?> <?= DH ?>"
     xmlns="http://www.w3.org/2000/svg" font-family="Calibri, sans-serif">

<?php // ---- the joins: one lane per shared name, drawn under everything ?>
<?php foreach ($laneOrder as $lane => $key): ?>
<?php
    $colr = '#' . (JOIN_COLORS[$key] ?? '1D3557');
    $ly = LANE_TOP + $lane * LANE_PITCH;
    $carriers = array_keys($map[$key]['carriers'] ?? []);
    $carriers = array_values(array_filter($carriers, fn($d) => isset($columns[$d])));
    if (count($carriers) < 2) {
        continue;
    }
    $xs = array_map(fn($d) => $stubX($d, $lane), $carriers);
?>
    <line x1="<?= min($xs) ?>" y1="<?= $ly ?>" x2="<?= max($xs) ?>" y2="<?= $ly ?>"
          stroke="<?= $colr ?>" stroke-width="5"/>
    <?php foreach ($carriers as $d): ?>
    <?php $sx = $stubX($d, $lane); $fy = $columnFoot($d); ?>
    <line x1="<?= $sx ?>" y1="<?= $fy ?>" x2="<?= $sx ?>" y2="<?= $ly ?>"
          stroke="<?= $colr ?>" stroke-width="5"/>
    <?php if (($ownerCol[$key] ?? null) === $d): ?>
    <circle cx="<?= $sx ?>" cy="<?= $fy ?>" r="14" fill="<?= $colr ?>"/>
    <?php endif; ?>
    <?php endforeach; ?>
    <?php // A name owned in the tray runs on to the exact table that owns
          // it - out along its lane, one right-angle turn, in to the panel. ?>
    <?php if (isset($ownerTray[$key])): ?>
    <?php $ex = TRAY_X - 210 + $lane * 30; $ty = $trayPanelY($ownerTray[$key]); ?>
    <line x1="<?= max($xs) ?>" y1="<?= $ly ?>" x2="<?= $ex ?>" y2="<?= $ly ?>"
          stroke="<?= $colr ?>" stroke-width="5" stroke-dasharray="14 10"/>
    <line x1="<?= $ex ?>" y1="<?= $ly ?>" x2="<?= $ex ?>" y2="<?= $ty ?>"
          stroke="<?= $colr ?>" stroke-width="5" stroke-dasharray="14 10"/>
    <line x1="<?= $ex ?>" y1="<?= $ty ?>" x2="<?= TRAY_X + 60 ?>" y2="<?= $ty ?>"
          stroke="<?= $colr ?>" stroke-width="5" stroke-dasharray="14 10"/>
    <circle cx="<?= TRAY_X + 60 ?>" cy="<?= $ty ?>" r="14" fill="<?= $colr ?>"/>
    <?php elseif (!isset($ownerCol[$key])): ?>
    <?php // ref.Surveys is published to all ten and drawn nowhere: the
          // lane ends in its own dot and the legend names it. ?>
    <circle cx="<?= max($xs) ?>" cy="<?= $ly ?>" r="14" fill="<?= $colr ?>"/>
    <?php endif; ?>
<?php endforeach; ?>

<?php foreach ($columns as $name => [$row, $col, $tables]): ?>
<?php
    $cx = $centers[$col];
    $top = $rowCylTop[$row];
    $bodyBot = $top + CYL_H;
?>
    <?php foreach ($tables as $i => $t): ?>
    <?php [$px, $py] = $panelXY($name, $i); ?>
    <line x1="<?= $cx ?>" y1="<?= $bodyBot + 100 ?>" x2="<?= $px ?>" y2="<?= $py - 12 ?>"
          stroke="#1D3557" stroke-width="2" opacity="0.5"/>
    <?php endforeach; ?>
    <?= svg_cylinder($cx, $top, CYL_W, CYL_H, table_count($name), 3, FS_CYL) ?>
    <text x="<?= $cx ?>" y="<?= $bodyBot + 80 ?>" text-anchor="middle"
          font-size="<?= FS_NAME ?>" font-family="Calibri, sans-serif"
          font-weight="bold" fill="#1D3557"><?= esc(SHORT[$name]) ?></text>
    <?php foreach ($tables as $i => $t): ?>
    <?php [, , $py0] = $panelXY($name, $i); ?>
    <?= svg_panel($cx, $py0, PANEL_W, PANEL_H, SHEAR, $t, table_exists($name, $t), FS) ?>
    <?php endforeach; ?>
<?php endforeach; ?>

<?php // Data maintained outside PRIOps: the export-fed reference tray,
      // reading down the right margin the two rows do not need. ?>
<rect x="<?= TRAY_X ?>" y="<?= TRAY_Y ?>" width="<?= TRAY_W ?>" height="<?= TRAY_H ?>"
      rx="18" fill="none" stroke="#44536A" stroke-width="3" stroke-dasharray="16 12"/>
<text x="<?= TRAY_X + TRAY_W / 2 ?>" y="<?= TRAY_Y + 66 ?>" text-anchor="middle"
      font-size="<?= FS ?>" font-weight="bold" fill="#2B2B2B">Maintained outside</text>
<text x="<?= TRAY_X + TRAY_W / 2 ?>" y="<?= TRAY_Y + 120 ?>" text-anchor="middle"
      font-size="<?= FS ?>" font-weight="bold" fill="#2B2B2B">PRIOps</text>
<text x="<?= TRAY_X + TRAY_W / 2 ?>" y="<?= TRAY_Y + 174 ?>" text-anchor="middle"
      font-size="<?= FS ?>" fill="#2B2B2B">from campus exports</text>
<?php foreach ([['ref.OrgUnits', 'work'], ['ref.CfopAccounts', 'shop'],
                ['ref.People', 'work']] as $i => [$refT, $refDb]): ?>
<?= svg_panel(TRAY_X + TRAY_W / 2, TRAY_Y + 250 + $i * PITCH, PANEL_W, PANEL_H,
              SHEAR, $refT, table_exists($refDb, $refT), FS, true) ?>
<?php endforeach; ?>

<?php // ---- the key to the lanes, standing in the right-hand margin ?>
<rect x="<?= LEGEND_X ?>" y="<?= LEGEND_Y ?>" width="<?= LEGEND_W ?>"
      height="<?= LEGEND_H ?>" rx="18" fill="none" stroke="#C9CDD4" stroke-width="3"/>
<?php foreach ($laneOrder as $lane => $key): ?>
<?php
    $colr = '#' . (JOIN_COLORS[$key] ?? '1D3557');
    $ey = LEGEND_Y + 30 + $lane * LEGEND_PITCH;
?>
    <line x1="<?= LEGEND_X + 26 ?>" y1="<?= $ey + 16 ?>" x2="<?= LEGEND_X + 96 ?>"
          y2="<?= $ey + 16 ?>" stroke="<?= $colr ?>" stroke-width="9"/>
    <circle cx="<?= LEGEND_X + 96 ?>" cy="<?= $ey + 16 ?>" r="12" fill="<?= $colr ?>"/>
    <text x="<?= LEGEND_X + 124 ?>" y="<?= $ey + 32 ?>" font-size="<?= FS_LEG ?>"
          fill="#2B2B2B"><tspan font-weight="bold"
          fill="<?= $colr ?>"><?= esc($key) ?></tspan> &#8594; <?= esc($ownerShort[$key] ?? '') ?></text>
<?php endforeach; ?>
<text x="30" y="<?= NOTE_Y ?>" font-size="<?= FS_LEG ?>" fill="#6B7280">
    Every line runs to the dot - the register that owns the name. Two databases on one
    lane do not join each other.</text>
</svg>
</body></html>
