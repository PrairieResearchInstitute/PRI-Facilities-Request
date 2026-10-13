<#
    PRI Facilities Request — refresh the three campus exports.

    The server cannot pull these reports: they sit behind Shibboleth and
    asking for a service account is the conversation this project avoids.
    A desktop can, because the person sitting at it is already signed in.

    So this script works the way a person does. It opens each report in
    the default browser, which downloads it under the existing session,
    waits for the file to appear, and posts whatever landed to the app.
    Files already loaded are recognised by the app and skipped, so running
    this daily is harmless.

    Run it once by hand to approve the browser prompts, then add it to
    Task Scheduler. It needs the machine unlocked and the browser signed
    in, which is why it belongs on a desktop and not on the server.

        powershell -ExecutionPolicy Bypass -File pri-refresh.ps1 `
            -Server "https://facilities.prairie.illinois.edu/facilities" `
            -Token  "the key from the Data refresh screen"

    -NoDownload skips the browser entirely and just posts whatever
    matching files are already sitting in the downloads folder, which is
    the right mode once somebody has pulled the reports by hand.
#>

param(
    [Parameter(Mandatory = $true)][string] $Server,
    [Parameter(Mandatory = $true)][string] $Token,
    [string] $DownloadDir,
    [int]    $WaitSeconds = 90,
    [switch] $NoDownload,
    [switch] $Quiet
)

$ErrorActionPreference = 'Stop'

# The reports are not listed here. The app holds them, so a corrected
# address entered on the Data refresh screen reaches this task without
# anybody touching a desktop. These are only the fallback for a server
# that cannot be reached.
$fallback = @(
    @{ key = 'accounts'; label = 'CFOP accounts';
       url = 'https://pride.prairie.illinois.edu/pride/accounts/activeGrantsReport.php?excel=1';
       pattern = 'activeGrants*.csv' },
    @{ key = 'people'; label = 'People directory'; url = ''; pattern = 'export-employee-file-*.csv' },
    @{ key = 'orgchart'; label = 'Org chart'; url = ''; pattern = 'orgChart-*.csv' }
)

function Say($msg) { if (-not $Quiet) { Write-Host $msg } }

<#
    A multipart post built by hand. Invoke-RestMethod grew a -Form
    parameter in PowerShell 6, but campus desktops run Windows PowerShell
    5.1, so the body is assembled here instead. The file is treated as
    bytes and stitched in as latin-1 so nothing is re-encoded on the way
    through.
#>
function Send-Export {
    param([string] $Uri, [string] $Token, [string] $As, [System.IO.FileInfo] $File)

    $boundary = 'pri' + [Guid]::NewGuid().ToString('N')
    $LF = "`r`n"
    $enc = [System.Text.Encoding]::GetEncoding('iso-8859-1')
    $bytes = [System.IO.File]::ReadAllBytes($File.FullName)

    $body = "--$boundary$LF" +
            "Content-Disposition: form-data; name=`"token`"$LF$LF$Token$LF" +
            "--$boundary$LF" +
            "Content-Disposition: form-data; name=`"as`"$LF$LF$As$LF" +
            "--$boundary$LF" +
            "Content-Disposition: form-data; name=`"file`"; filename=`"$($File.Name)`"$LF" +
            "Content-Type: text/csv$LF$LF" +
            $enc.GetString($bytes) + $LF +
            "--$boundary--$LF"

    Invoke-RestMethod -Uri $Uri -Method Post -TimeoutSec 300 `
        -ContentType "multipart/form-data; boundary=$boundary" `
        -Body $enc.GetBytes($body)
}

$ingest = ($Server.TrimEnd('/')) + '/api/ingest.php'
$started = Get-Date

# Where the downloaded reports are read from. Standard v2 section B asks
# that the app keep nothing on the computer, so the drive folder comes
# first: point the browser at it once and no campus export ever lands in
# the profile. The browser decides where it saves, though, and until it is
# pointed at the drive the files arrive in the profile Downloads folder, so
# that folder stays as a named fallback with a line of output saying it was
# used. Nothing is hidden, and the task keeps working today.
if (-not $DownloadDir) {
    $appRoot = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
    $driveDir = ''
    $localCfg = Join-Path $appRoot 'config.local.json'
    if (Test-Path $localCfg) {
        try {
            $folder = ((Get-Content $localCfg -Raw) -replace "^\xEF\xBB\xBF", '' | ConvertFrom-Json).dataFolder
            if ($folder) {
                $p = if ($folder -match '^([A-Za-z]:[\\/]|[\\/])') { $folder } else { Join-Path $appRoot $folder }
                $p = (Resolve-Path -LiteralPath $p -ErrorAction Stop).Path
                $driveDir = Join-Path $p 'imports\incoming'
            }
        } catch { $driveDir = '' }
    }
    if ($driveDir) {
        if (-not (Test-Path $driveDir)) { New-Item -ItemType Directory -Path $driveDir -Force | Out-Null }
        $DownloadDir = $driveDir
        $hasFile = @(Get-ChildItem -Path $driveDir -File -ErrorAction SilentlyContinue).Count -gt 0
        $profileDir = Join-Path $env:USERPROFILE 'Downloads'
        if (-not $hasFile -and (Test-Path $profileDir)) {
            $DownloadDir = $profileDir
            Say "Reading the reports from $profileDir on this computer, because $driveDir is empty."
            Say "Point the browser's download folder at $driveDir and nothing lands on the computer again."
        }
    } else {
        $DownloadDir = Join-Path $env:USERPROFILE 'Downloads'
    }
}

if (-not (Test-Path $DownloadDir)) {
    throw "No downloads folder at $DownloadDir."
}

# Ask the app what it wants and how stale each source is.
try {
    $listed = Invoke-RestMethod -Uri ($ingest + '?token=' + [Uri]::EscapeDataString($Token)) `
                                -Method Get -TimeoutSec 60
    $sources = $listed.sources
    Say "$($sources.Count) sources listed by the app."
} catch {
    Write-Warning "Could not ask the app what to fetch; using the built in list. $($_.Exception.Message)"
    $sources = $fallback
}

if (-not $NoDownload) {
    Say "Opening the reports in the default browser..."
    foreach ($s in $sources) {
        # A source with no address cannot be fetched automatically, but a
        # file already downloaded by hand is still picked up below.
        if (-not $s.url) {
            Say "  $($s.label): no report address on record - will only post a file already downloaded."
            continue
        }
        Start-Process $s.url
        Start-Sleep -Seconds 3
    }
    # A download is not instant and the browser may ask where to put it,
    # so the wait is for files to stop arriving rather than a fixed pause.
    Say "Waiting up to $WaitSeconds seconds for the downloads to land..."
    $deadline = (Get-Date).AddSeconds($WaitSeconds)
    $seen = 0
    while ((Get-Date) -lt $deadline) {
        $fresh = 0
        foreach ($s in $sources) {
            $f = Get-ChildItem -Path $DownloadDir -Filter $s.pattern -File -ErrorAction SilentlyContinue |
                 Where-Object { $_.LastWriteTime -ge $started }
            if ($f) { $fresh++ }
        }
        if ($fresh -ge $sources.Count) { break }
        if ($fresh -gt $seen) { $seen = $fresh; $deadline = (Get-Date).AddSeconds(20) }
        Start-Sleep -Seconds 2
    }
}

$posted = 0
$failed = 0

foreach ($s in $sources) {
    # Newest matching file wins. When the browser has been used before,
    # that is "activeGrants (3).csv" rather than the original name, which
    # is why the app identifies a file by its shape and not its name.
    $file = Get-ChildItem -Path $DownloadDir -Filter $s.pattern -File -ErrorAction SilentlyContinue |
            Sort-Object LastWriteTime -Descending | Select-Object -First 1
    if (-not $file) {
        Say "  $($s.label): nothing matching $($s.pattern) in $DownloadDir - skipped."
        continue
    }

    try {
        $resp = Send-Export -Uri $ingest -Token $Token -As "$env:USERNAME (scheduled)" -File $file
        if ($resp.skipped) {
            Say "  $($s.label): already current."
        } else {
            Say "  $($s.label): $($resp.message)"
            $posted++
        }
    } catch {
        # The app answers a refusal with JSON saying why, which is worth
        # more than "403 Forbidden". Windows PowerShell 5.1 leaves that
        # body on the response stream rather than in ErrorDetails, so
        # both places are tried.
        $detail = $_.Exception.Message
        $raw = $_.ErrorDetails.Message
        if (-not $raw -and $_.Exception.Response) {
            try {
                $reader = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
                $raw = $reader.ReadToEnd()
                $reader.Close()
            } catch { }
        }
        if ($raw) { try { $detail = (ConvertFrom-Json $raw).error } catch { } }
        Write-Warning "  $($s.label): $detail"
        $failed++
    }
}

Say ""
Say "$posted refreshed, $failed failed."
if ($failed -gt 0) { exit 1 }
