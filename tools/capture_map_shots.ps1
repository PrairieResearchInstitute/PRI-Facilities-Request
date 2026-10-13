# Captures the building-map shots for the briefing deck using the map
# page's present mode (?present=plan strips the page to the floor plan;
# ?present=room adds the selected room's tab as a pop-out card).
#
# The diagram_ prefix matters: process_shots.ps1 gives those names
# trim-only handling, so the plan is never cut into bands.
#
#   powershell -File tools\capture_map_shots.ps1
#
# Needs the dev server on http://localhost:8080 (php -S over facilities\).

$edge = "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe"
if (-not (Test-Path $edge)) {
    $edge = "$env:ProgramFiles\Microsoft\Edge\Application\msedge.exe"
}
$raw  = Join-Path $PSScriptRoot "..\storage\shots\raw"
$base = "http://localhost:8080/admin.php?section=map&bldg=1073"
$room = "1073_01_1027"       # the collection room the briefing walks through
$safetyRoom = "1073_01_1031" # a room that actually holds safety equipment

$shots = @(
    @{ name = "diagram_map_plan";   url = "$base&present=plan";                                        h = 1300 },
    @{ name = "diagram_map_resp";   url = "$base&sel=$room&tab=responsibility&present=room";           h = 1500 },
    @{ name = "diagram_map_work";   url = "$base&sel=$room&tab=work&present=room";                     h = 1500 },
    @{ name = "diagram_map_inv";    url = "$base&sel=$room&tab=inventory&present=room";                h = 1500 },
    @{ name = "diagram_map_keys";   url = "$base&sel=$room&tab=keys&present=room";                     h = 1500 },
    @{ name = "diagram_map_safety"; url = "$base&sel=$safetyRoom&tab=safety&present=room";             h = 1500 }
)

foreach ($s in $shots) {
    $out = Join-Path $raw ($s.name + ".png")
    & $edge --headless=new --disable-gpu --window-size="1280,$($s.h)" `
        --virtual-time-budget=9000 --screenshot="$out" $s.url 2>$null | Out-Null
    if (Test-Path $out) {
        Write-Output ("captured " + $s.name)
    } else {
        Write-Output ("FAILED   " + $s.name)
    }
}
