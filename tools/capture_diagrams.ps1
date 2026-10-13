# Captures every live-drawn diagram for the briefing deck: the overview,
# the ten per-database drawings, and the eleven flowcharts. The diagram_
# prefix gives them trim-only processing in process_shots.ps1.
#
#   powershell -File tools\capture_diagrams.ps1
#
# Needs the dev server on http://localhost:8080.

$edge = "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe"
if (-not (Test-Path $edge)) {
    $edge = "$env:ProgramFiles\Microsoft\Edge\Application\msedge.exe"
}
$raw  = Join-Path $PSScriptRoot "..\storage\shots\raw"
$base = "http://localhost:8080/diagram.php"

$shots = @(
    @{ name = "diagram_dbs"; url = $base; w = 3320; h = 1440 }
)
foreach ($db in "work","move","shop","space","keys","incidents","plans","purchasing","equipment","fleet") {
    $shots += @{ name = "diagram_db_$db"; url = "$base`?db=$db"; w = 960; h = 1400 }
}
foreach ($fl in "work","move","space","key","estate","incident","plan","purchase","mileage","dataload","launch") {
    $shots += @{ name = "diagram_flow_$fl"; url = "$base`?flow=$fl"; w = 2460; h = 700 }
}

foreach ($s in $shots) {
    $out = Join-Path $raw ($s.name + ".png")
    $prof = Join-Path $env:TEMP ("edgecap-" + $s.name)
    & $edge --headless=new --disable-gpu --no-first-run `
        --user-data-dir="$prof" `
        --window-size="$($s.w),$($s.h)" --virtual-time-budget=6000 `
        --screenshot="$out" $s.url 2>$null | Out-Null
    Remove-Item -Recurse -Force $prof -ErrorAction SilentlyContinue
    if (Test-Path $out) {
        Write-Output ("captured " + $s.name)
    } else {
        Write-Output ("FAILED   " + $s.name)
    }
}
