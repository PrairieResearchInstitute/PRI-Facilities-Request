# ensure-running.ps1: make sure PRIOps is up, then optionally open it.
#
#   powershell -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File tools\ensure-running.ps1 -Open
#
# The desktop shortcut runs it through drez-harness\tools\open-app.vbs, which
# starts PowerShell hidden, so no console shows. start-local.bat stays as the
# variant with a visible console, for seeing errors. Replaces
# "PRI Facilities Request.vbs" (D-018) with every step and message kept.
#
# Safe to run any number of times: a server already serving this folder is
# reused, never doubled. Three things this launcher will not do, because each
# has cost a day:
#
#   It does not assume where PHP is. It searches, starting with the copy inside
#   the app folder, which travels, and names every place it looked.
#
#   It does not assume that something answering the port is this app. It asks
#   health.php which folder answers, because a leftover server from another
#   copy once served stale code and looked like an app bug.
#
#   It does not treat "nothing answered" as "the port is free". Windows lets
#   php -S bind a port another server already holds, then hands connections to
#   either at random. Only a TCP listener check settles whether a port is free.

param([switch]$Open)

$ErrorActionPreference = 'Continue'
$appDir   = Split-Path -Parent $PSScriptRoot
$docRoot  = Join-Path $appDir 'facilities'
$rootSlash = $docRoot.Replace('\', '/')
$basePort = 8080
$maxPort  = 8089

# Every message box the old .vbs showed, as a WScript.Shell popup (48 is the
# exclamation icon), and on the console too for a caller that has one.
function Show-Problem([string]$msg) {
    Write-Host ''
    Write-Host ('  ' + $msg.Replace("`r`n", "`r`n  ")) -ForegroundColor Red
    Write-Host ''
    try { (New-Object -ComObject WScript.Shell).Popup($msg, 0, 'PRIOps', 48) | Out-Null } catch { }
}

# One quoted command-line argument for Start-Process, which does no quoting of
# its own (this folder sits under paths with spaces).
function Quote([string]$s) { '"' + $s + '"' }

# One -d argument, as -d "key=\"value\"". PHP parses a -d value as ini text, so
# an unquoted path breaks on any character the ini syntax claims: a short name
# such as ...\Users\SAUGAD~1\... was truncated at the tilde and PHP carried on
# with the wrong folder. A space does the same (proven 8 Oct 2026).
function IniArg([string]$k, [string]$v) { '-d "' + $k + '=\"' + $v + '\""' }

# ---- find PHP ---------------------------------------------------------
$tried = New-Object System.Collections.Generic.List[string]
function Try-Php([string]$p) {
    if (-not $p) { return '' }
    $tried.Add('  ' + $p)
    if (Test-Path -LiteralPath $p -PathType Leaf) { return $p }
    return ''
}
function Find-Php {
    # 1. Bundled with the app, so it works wherever the folder is.
    $p = Try-Php (Join-Path $appDir 'tools\php\php.exe')
    if ($p) { return $p }
    # 2. A path written in php-path.txt beside the app.
    $txt = Join-Path $appDir 'php-path.txt'
    if (Test-Path -LiteralPath $txt) {
        $line = (Get-Content -LiteralPath $txt -TotalCount 1)
        if ($line) { $p = Try-Php ([string]$line).Trim() }
        if ($p) { return $p }
    }
    # 3. Anything on PATH. A developer's C: path used to sit here; it went on
    #    8 Oct 2026, because the bundled copy carries pdo_sqlsrv and the
    #    fallback only ever hid a missing bundle.
    $cmd = Get-Command php.exe -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($cmd) { $p = Try-Php $cmd.Source }
    return $p
}

$php = Find-Php
if (-not $php) {
    Show-Problem ("PHP was not found. Looked in:`r`n`r`n" + ($tried -join "`r`n") + "`r`n`r`n" +
        "Install PHP 8.2+ with the pdo_sqlsrv extension, or write its`r`n" +
        "full path into a file called php-path.txt beside this launcher.")
    exit 1
}

# ---- which engine holds the registers --------------------------------
# dataMode in config.local.json, read by tools\data_mode.php. On PostgreSQL
# every LocalDB step is skipped and PHP gets pdo_pgsql, which the bundled
# php.ini does not load.
$engine = ''
try { $engine = ([string](& $php (Join-Path $appDir 'tools\data_mode.php') 2>$null | Select-Object -First 1)).Trim().ToLower() } catch { }
$cfgText = ''
$cfgFile = Join-Path $appDir 'config.php'
if (Test-Path -LiteralPath $cfgFile) { $cfgText = [string](Get-Content -LiteralPath $cfgFile -Raw) }
$useLocalDb = ($engine -ne 'postgres') -and ($cfgText -match '\(localdb\)')
$pgExt = if ($engine -eq 'postgres') { '-d extension=pdo_pgsql' } else { '' }

# ---- wake LocalDB before PHP ever touches it --------------------------
# LocalDB stops itself after idling. A server whose first connection met a
# stopped instance failed with "Login failed" and never recovered (17 Sep).
# When the instance will not start because an orphan sqlservr.exe holds it
# (AGENTS.md rule 4), stop that one process and start again.
if ($useLocalDb) {
    try {
        $info = (sqllocaldb info MSSQLLocalDB 2>$null) -join ' '
        if ($info -notmatch 'Running') {
            sqllocaldb start MSSQLLocalDB 2>$null | Out-Null
            $info = (sqllocaldb info MSSQLLocalDB 2>$null) -join ' '
            if ($info -notmatch 'Running') {
                Get-CimInstance Win32_Process -Filter "Name='sqlservr.exe'" |
                    Where-Object { $_.CommandLine -like '*LocalDB*' } |
                    ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
                sqllocaldb start MSSQLLocalDB 2>$null | Out-Null
            }
        }
    } catch { }

    # Running is not the same as answering. Two processes making first contact
    # while the instance settles is how a second engine gets spawned and lost.
    # Wait for one proven query, briefly, and never let the wait stop the app.
    $dbready = Join-Path $appDir 'tools\dbready.php'
    foreach ($i in 1..6) {
        & $php $dbready 5 2>$null | Out-Null
        if ($LASTEXITCODE -eq 0) { break }
        Start-Sleep -Milliseconds 1500
    }
}

# ---- PostgreSQL on the drive -----------------------------------------
# The estate cluster on the drive (platform D-028, app D-011): 127.0.0.1 port
# 5433. tools\pg\cluster.php runs node estate-pg.js or the drive's pg_ctl. It
# gets a hidden console of its own, as under the old .vbs, and the call waits
# for the cluster to answer.
if ($engine -eq 'postgres') {
    $code = 1
    try {
        $p = Start-Process -FilePath $php -ArgumentList (Quote (Join-Path $appDir 'tools\pg\cluster.php')), 'start' `
             -WindowStyle Hidden -Wait -PassThru
        if ($p) { $code = $p.ExitCode }
    } catch { $code = 1 }
    if ($code -ne 0) {
        Show-Problem ("PostgreSQL on the drive did not start, so the application`r`n" +
            "cannot open its registers.`r`n`r`n" +
            "Run start-local.bat to see what the drive's PostgreSQL reports.")
        exit 1
    }
}

# ---- ports ------------------------------------------------------------
# 127.0.0.1 explicitly, never localhost: localhost resolves to ::1 first on
# Windows, so one URL can reach two different servers on the same port.
function Port-Url([int]$n) { "http://127.0.0.1:$n/" }

# Is this folder the one answering? health.php names the folder and touches
# no database, so it replies even when the database is down.
function Test-Mine([int]$n) {
    try {
        $req = [System.Net.HttpWebRequest]::Create((Port-Url $n) + 'health.php')
        $req.Timeout = 1500
        $req.ReadWriteTimeout = 1500
        $req.Proxy = $null
        $res = $req.GetResponse()
        try {
            $body = (New-Object System.IO.StreamReader($res.GetResponseStream())).ReadToEnd()
        } finally { $res.Close() }
        return ($body.IndexOf($rootSlash, [StringComparison]::OrdinalIgnoreCase) -ge 0)
    } catch { return $false }
}

# A port is free only if nothing listens on it, in any address family. One
# question to Windows for the whole range, because a knock at a dead port
# costs seconds of timeout.
$busy = @(Get-NetTCPConnection -State Listen -ErrorAction SilentlyContinue |
          Where-Object { $_.LocalPort -ge $basePort -and $_.LocalPort -le $maxPort } |
          Select-Object -ExpandProperty LocalPort -Unique)

# 1. Already running from this folder? Only occupied ports can be this app.
foreach ($n in $basePort..$maxPort) {
    if (($busy -contains $n) -and (Test-Mine $n)) {
        Write-Host ("PRIOps is already running from this folder at " + (Port-Url $n))
        if ($Open) { Start-Process (Port-Url $n) }
        exit 0
    }
}

# 2. Otherwise take the first port with nothing listening on it at all.
$freePort = 0
foreach ($n in $basePort..$maxPort) {
    if ($busy -notcontains $n) { $freePort = $n; break }
}
if ($freePort -eq 0) {
    Show-Problem ("Every port from $basePort to $maxPort is in use by`r`n" +
        "another program, so there is nowhere to start the server.`r`n`r`n" +
        "Close one of them and launch this again.")
    exit 1
}

# The scratch folders the server must use (standard v2 section B: nothing on
# the computer). sys_temp_dir and upload_tmp_dir cannot be set from a page. An
# unreachable drive gives fewer than three lines, and the server then starts
# without the overrides rather than not at all.
$rtArgs = @()
try {
    $rt = @(& $php (Join-Path $appDir 'tools\runtime_dirs.php') 2>$null | ForEach-Object { ([string]$_).Trim() } | Where-Object { $_ })
    if ($rt.Count -ge 3) {
        $rtArgs = @((IniArg 'sys_temp_dir' $rt[0]), (IniArg 'upload_tmp_dir' $rt[1]), (IniArg 'session.save_path' $rt[2]))
    }
} catch { }

# Hidden and detached: Start-Process gives the server a console of its own, so
# it outlives this script, as it outlived the .vbs.
$serverArgs = @()
if ($pgExt) { $serverArgs += $pgExt }
$serverArgs += $rtArgs
$serverArgs += @('-S', "127.0.0.1:$freePort", '-t', (Quote $docRoot))
Start-Process -FilePath $php -ArgumentList ($serverArgs -join ' ') -WindowStyle Hidden | Out-Null

$started = $false
foreach ($i in 1..10) {
    Start-Sleep -Milliseconds 400
    if (Test-Mine $freePort) { $started = $true; break }
}
if (-not $started) {
    Show-Problem ("The server did not come up on port $freePort.`r`n`r`n" +
        "PHP was found at:`r`n  $php`r`n`r`n" +
        "Run start-local.bat to see the error it printed.")
    exit 1
}

Write-Host ("PRIOps started from this folder at " + (Port-Url $freePort))
if ($Open) { Start-Process (Port-Url $freePort) }

# Keep LocalDB attached while the application is open. Without it LocalDB
# idles out, restarts with a new pipe, and the running server keeps the old
# one: the recurring "Login failed". The keep-alive exits with the server.
if ($useLocalDb) {
    Start-Process -FilePath $php -ArgumentList (Quote (Join-Path $appDir 'tools\keepalive.php')), "$freePort" `
        -WindowStyle Hidden | Out-Null
}

# On PostgreSQL a hidden watcher stops the drive's cluster cleanly once this
# server stops answering, unless another app is still connected, so the drive
# can be ejected after the last app closes.
if ($engine -eq 'postgres') {
    Start-Process -FilePath $php -ArgumentList '-d extension=pdo_pgsql', (Quote (Join-Path $appDir 'tools\pg\cluster.php')), 'watch', "$freePort" `
        -WindowStyle Hidden | Out-Null
}
exit 0
