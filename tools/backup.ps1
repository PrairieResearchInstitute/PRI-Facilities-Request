<#
.SYNOPSIS
    Verified backups of the ten PRIOps databases.

.DESCRIPTION
    Each database is backed up with BACKUP DATABASE ... WITH COPY_ONLY,
    CHECKSUM, INIT, then checked with RESTORE VERIFYONLY ... WITH CHECKSUM.
    A backup that fails either step is deleted rather than kept, because a
    backup nobody can restore is worse than none: it is the one you find
    out about on the day you need it.

    The .mdf and .ldf files are never copied. They are attached and being
    written to; a file copy of a live database is not a backup.

    Row counts are recorded beside each backup, as they were AT THE MOMENT
    the backup was taken. The restore drill compares a restored copy
    against that record, never against the live database - live has moved
    on by then, and comparing against it reports failures that are not
    real.

.PARAMETER Drill
    After backing up, restore one database under a different name to
    scratch files, compare every table's row count against the record
    taken at backup time, then drop the drill copy.

.PARAMETER Destination
    Where backups go. Default: a backups folder in the data folder named by
    config.local.json, which is outside the repository and on the same
    drive as the app, so a mistake inside the folder cannot take the
    backups with it. Without config.local.json the default is
    storage\backups in the app folder. The old default was
    %LOCALAPPDATA%\PRIOps\backups, which put ten databases of real records
    on the computer; standard v2 section B ended that. Override permanently
    with a path in backup-path.txt in the data folder, or in the older
    data\backup-path.txt beside the app.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tools\backup.ps1
    powershell -ExecutionPolicy Bypass -File tools\backup.ps1 -Drill
#>

[CmdletBinding()]
param(
    [switch]$Drill,
    [string]$Destination,
    [int]$KeepDailyDays = 14,
    [int]$KeepMonthlyMonths = 12
)

$ErrorActionPreference = 'Stop'

# Roots are derived from this script's location, never written in. A
# hardcoded root is what let a sibling app write its output into a stale
# copy of itself for weeks.
$ToolsDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$AppRoot  = Split-Path -Parent $ToolsDir

$Databases = @(
    'PRI_FacilitiesWork', 'PRI_FacilitiesMove', 'PRI_FacilitiesShop',
    'PRI_FacilitiesPurchasing', 'PRI_FacilitiesKeys', 'PRI_FacilitiesFleet',
    'PRI_FacilitiesSpace', 'PRI_FacilitiesEquipment',
    'PRI_FacilitiesIncidents', 'PRI_FacilitiesPlans'
)

# ---------------------------------------------------------------- paths
# The data folder named in config.local.json, resolved against the app
# folder, the same way facilities\lib\paths.php resolves it. Empty when
# this copy has no config.local.json, which is the pre-drive layout.
function Get-DataRoot {
    $file = Join-Path $AppRoot 'config.local.json'
    if (-not (Test-Path $file)) { return '' }
    try {
        $folder = ((Get-Content $file -Raw) -replace "^\xEF\xBB\xBF", '' | ConvertFrom-Json).dataFolder
    } catch { return '' }
    if (-not $folder) { return '' }
    $path = if ($folder -match '^([A-Za-z]:[\\/]|[\\/])') { $folder } else { Join-Path $AppRoot $folder }
    try { return (Resolve-Path -LiteralPath $path -ErrorAction Stop).Path } catch { return '' }
}

$DataRoot = Get-DataRoot

# Once dataMode is postgres the live registers are in PostgreSQL and the
# LocalDB databases are the frozen copy from before the switch. Backing them
# up then would look like a backup of the live data and is not one.
$ModeFile = Join-Path $AppRoot 'config.local.json'
$DataMode = ''
if (Test-Path $ModeFile) {
    try { $DataMode = [string]((Get-Content $ModeFile -Raw) -replace "^\xEF\xBB\xBF", '' | ConvertFrom-Json).dataMode } catch { $DataMode = '' }
}
if ($DataMode.Trim().ToLower() -eq 'postgres') {
    Write-Host 'dataMode is postgres: the live registers are in PostgreSQL. Back them up with'
    Write-Host '  tools\php\php.exe -d extension=pdo_pgsql tools\pg\backup.php --database live'
    exit 2
}
if (-not $Destination) {
    $overrides = @()
    if ($DataRoot) { $overrides += (Join-Path $DataRoot 'backup-path.txt') }
    $overrides += (Join-Path $AppRoot 'data\backup-path.txt')
    foreach ($o in $overrides) {
        if (Test-Path $o) { $Destination = (Get-Content $o -First 1).Trim(); break }
    }
}
if (-not $Destination) {
    # Standard v2 section B: nothing on the computer. The default used to be
    # %LOCALAPPDATA%\PRIOps\backups, chosen when the app folder was inside
    # OneDrive and anything beside the app was at risk from a sync. The app
    # now lives on the drive and its data folder sits outside the
    # repository, so backups of ten databases no longer need the profile,
    # and leaving them there put real records on the computer.
    $Destination = if ($DataRoot) { Join-Path $DataRoot 'backups' } else { Join-Path $AppRoot 'storage\backups' }
}
$LogDir = Join-Path $AppRoot 'storage\logs'
foreach ($d in @($Destination, $LogDir)) {
    if (-not (Test-Path $d)) { New-Item -ItemType Directory -Path $d -Force | Out-Null }
}

$Stamp   = Get-Date -Format 'yyyy-MM-dd_HHmmss'
$RunDir  = Join-Path $Destination $Stamp
New-Item -ItemType Directory -Path $RunDir -Force | Out-Null
$LogFile = Join-Path $LogDir 'backup.log'

function Write-Log {
    param([string]$Level, [string]$Message)
    $line = '{0}  {1,-7} {2}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Level, $Message
    Add-Content -Path $LogFile -Value $line -Encoding UTF8
    if ($Level -eq 'FAIL') { Write-Host $line -ForegroundColor Red }
    elseif ($Level -eq 'WARN') { Write-Host $line -ForegroundColor Yellow }
    else { Write-Host $line }
}

# ------------------------------------------------------------- database
$ConnString = 'Server=(localdb)\MSSQLLocalDB;Integrated Security=true;Database=master;Connect Timeout=60'

function Invoke-Sql {
    param([string]$Sql, [int]$TimeoutSeconds = 1800)
    $conn = New-Object System.Data.SqlClient.SqlConnection $ConnString
    try {
        $conn.Open()
        $cmd = $conn.CreateCommand()
        $cmd.CommandText = $Sql
        $cmd.CommandTimeout = $TimeoutSeconds
        $null = $cmd.ExecuteNonQuery()
    } finally { $conn.Close() }
}

function Invoke-SqlRows {
    param([string]$Sql, [int]$TimeoutSeconds = 600)
    $conn = New-Object System.Data.SqlClient.SqlConnection $ConnString
    try {
        $conn.Open()
        $cmd = $conn.CreateCommand()
        $cmd.CommandText = $Sql
        $cmd.CommandTimeout = $TimeoutSeconds
        $reader = $cmd.ExecuteReader()
        $rows = @()
        while ($reader.Read()) {
            $row = @{}
            for ($i = 0; $i -lt $reader.FieldCount; $i++) {
                $row[$reader.GetName($i)] = $reader.GetValue($i)
            }
            $rows += [pscustomobject]$row
        }
        $reader.Close()
        return $rows
    } finally { $conn.Close() }
}

<#  Row counts for every base table, exact (not sys.partitions estimates,
    which drift). This is the record the drill is measured against. #>
function Get-RowCounts {
    param([string]$Database)
    $sql = @"
SELECT s.name AS [schema], t.name AS [table], SUM(p.rows) AS [rows]
FROM [$Database].sys.tables t
JOIN [$Database].sys.schemas s ON s.schema_id = t.schema_id
JOIN [$Database].sys.partitions p ON p.object_id = t.object_id AND p.index_id IN (0,1)
GROUP BY s.name, t.name
ORDER BY s.name, t.name
"@
    return Invoke-SqlRows -Sql $sql
}

# --------------------------------------------------------------- backup
Write-Log 'INFO' "Backup run $Stamp -> $RunDir"
$failed = @()
$taken  = @()

foreach ($db in $Databases) {
    $bak = Join-Path $RunDir "$db.bak"
    try {
        $counts = Get-RowCounts -Database $db
        Invoke-Sql -Sql "BACKUP DATABASE [$db] TO DISK = N'$bak' WITH COPY_ONLY, CHECKSUM, INIT, FORMAT, NAME = N'$db PRIOps'"
        Invoke-Sql -Sql "RESTORE VERIFYONLY FROM DISK = N'$bak' WITH CHECKSUM"

        $counts | ConvertTo-Json -Depth 4 |
            Set-Content -Path (Join-Path $RunDir "$db.rowcounts.json") -Encoding UTF8
        $mb = [math]::Round((Get-Item $bak).Length / 1MB, 1)
        $total = ($counts | Measure-Object -Property rows -Sum).Sum
        Write-Log 'OK' ("{0,-26} {1,7} MB  {2} tables, {3} rows" -f $db, $mb, $counts.Count, $total)
        $taken += [pscustomobject]@{ Database = $db; Path = $bak }
    }
    catch {
        Write-Log 'FAIL' "$db : $($_.Exception.Message)"
        # A backup that did not verify is not kept. Keeping it is how a
        # folder fills with files that cannot be restored.
        if (Test-Path $bak) { Remove-Item $bak -Force -ErrorAction SilentlyContinue }
        $failed += $db
    }
}

# ------------------------------------------------------------ the drill
$drillResult = 'not run'
if ($Drill -and $taken.Count -gt 0) {
    $pick = $taken | Sort-Object { (Get-Item $_.Path).Length } | Select-Object -First 1
    $db   = $pick.Database
    $copy = "${db}_DrillCopy"
    $scratch = Join-Path $RunDir 'drill'
    New-Item -ItemType Directory -Path $scratch -Force | Out-Null
    Write-Log 'INFO' "Restore drill: $db -> $copy"
    try {
        $files = Invoke-SqlRows -Sql "RESTORE FILELISTONLY FROM DISK = N'$($pick.Path)'"
        $moves = @()
        foreach ($f in $files) {
            $ext = if ($f.Type -eq 'L') { '.ldf' } else { '.mdf' }
            $to  = Join-Path $scratch ($f.LogicalName + $ext)
            $moves += "MOVE N'$($f.LogicalName)' TO N'$to'"
        }
        Invoke-Sql -Sql "RESTORE DATABASE [$copy] FROM DISK = N'$($pick.Path)' WITH $($moves -join ', '), RECOVERY, REPLACE"

        $expected = Get-Content (Join-Path $RunDir "$db.rowcounts.json") -Raw | ConvertFrom-Json
        $actual   = Get-RowCounts -Database $copy
        $map = @{}
        foreach ($r in $actual) { $map["$($r.'schema').$($r.'table')"] = [int64]$r.rows }

        $bad = @()
        foreach ($e in $expected) {
            $key = "$($e.'schema').$($e.'table')"
            $was = [int64]$e.rows
            $now = if ($map.ContainsKey($key)) { $map[$key] } else { -1 }
            if ($now -ne $was) { $bad += "$key expected $was, restored $now" }
        }
        if ($bad.Count -eq 0) {
            $drillResult = "PASS - $db restored as $copy, $($expected.Count) tables, all row counts match the record taken at backup time"
            Write-Log 'OK' $drillResult
        } else {
            $drillResult = "FAIL - $($bad.Count) table(s) differ: " + ($bad -join '; ')
            Write-Log 'FAIL' $drillResult
            $failed += "$db (drill)"
        }
    }
    catch {
        $drillResult = "FAIL - $($_.Exception.Message)"
        Write-Log 'FAIL' "drill: $($_.Exception.Message)"
        $failed += "$db (drill)"
    }
    finally {
        try {
            Invoke-Sql -Sql "IF DB_ID('$copy') IS NOT NULL BEGIN ALTER DATABASE [$copy] SET SINGLE_USER WITH ROLLBACK IMMEDIATE; DROP DATABASE [$copy]; END"
            Write-Log 'INFO' "drill copy $copy dropped"
        } catch { Write-Log 'WARN' "could not drop $copy : $($_.Exception.Message)" }
        Remove-Item $scratch -Recurse -Force -ErrorAction SilentlyContinue
    }
}

# --------------------------------------------------------- retention
# Dailies for a fortnight; after that only the first run of each month.
$cutDaily = (Get-Date).AddDays(-$KeepDailyDays)
$cutMonth = (Get-Date).AddMonths(-$KeepMonthlyMonths)
$kept = @{}
Get-ChildItem $Destination -Directory -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -match '^\d{4}-\d{2}-\d{2}_\d{6}$' } |
    Sort-Object Name |
    ForEach-Object {
        $when = [datetime]::ParseExact($_.Name, 'yyyy-MM-dd_HHmmss', $null)
        $monthKey = $when.ToString('yyyy-MM')
        $isFirstOfMonth = -not $kept.ContainsKey($monthKey)
        $kept[$monthKey] = $true
        if ($when -ge $cutDaily) { return }                       # recent: keep
        if ($isFirstOfMonth -and $when -ge $cutMonth) { return }  # monthly: keep
        Write-Log 'INFO' "retention: removing $($_.Name)"
        Remove-Item $_.FullName -Recurse -Force -ErrorAction SilentlyContinue
    }

# ------------------------------------------------------------- outcome
$summary = "{0} of {1} databases backed up and verified" -f ($Databases.Count - $failed.Count), $Databases.Count
if ($failed.Count -gt 0) {
    Write-Log 'FAIL' "$summary; failed: $($failed -join ', ')"
    exit 1
}
Write-Log 'OK' $summary
if ($Drill) { Write-Log 'INFO' "drill: $drillResult" }
exit 0
