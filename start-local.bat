@echo off
rem PRI Facilities Request System - local web server.
rem
rem PHP is SEARCHED for, not assumed. The path used to be written into
rem this file, so the day the app was opened on another computer it
rem said only that PHP was missing, without saying where it looked.
rem The copy inside the app folder is tried first, because that one
rem travels with the folder.

setlocal
set APPDIR=%~dp0
set PHP=

rem 1. Bundled with the app - works on any machine the folder reaches.
if exist "%APPDIR%tools\php\php.exe" set PHP=%APPDIR%tools\php\php.exe

rem 2. A path written in php-path.txt beside this file, if you keep PHP
rem    somewhere of your own.
if not defined PHP if exist "%APPDIR%php-path.txt" (
    set /p PHP=<"%APPDIR%php-path.txt"
)
if defined PHP if not exist "%PHP%" set PHP=

rem 3. Anything on PATH.
rem    A path on one developer's C: drive used to sit between these two.
rem    It went on 8 Oct 2026: the app must run from the drive with nothing
rem    of its own on the computer, and the bundled copy above carries
rem    pdo_sqlsrv, so the fallback only ever hid a missing bundle.
if not defined PHP (
    for /f "delims=" %%P in ('where php 2^>nul') do if not defined PHP set PHP=%%P
)

if not defined PHP (
    echo PHP was not found. Looked in:
    echo   1. %APPDIR%tools\php\php.exe   ^(bundled with the app^)
    echo   2. the path inside %APPDIR%php-path.txt
    echo   3. php on PATH
    echo.
    echo Install PHP 8.2+ with the pdo_sqlsrv extension, or write its full
    echo path into a file called php-path.txt next to this one.
    pause
    exit /b 1
)

rem Which engine holds the registers (dataMode in config.local.json). On
rem PostgreSQL the ODBC driver and LocalDB are not needed, and PHP gets
rem pdo_pgsql, which the bundled php.ini does not load.
set ENGINE=
set PGEXT=
for /f "usebackq delims=" %%E in (`cmd /c ^""%PHP%" "%APPDIR%tools\data_mode.php"^"`) do set ENGINE=%%E
if /i "%ENGINE%"=="postgres" set PGEXT=-d extension=pdo_pgsql
if /i "%ENGINE%"=="postgres" goto odbcok

rem The SQL Server ODBC driver is the one thing the app cannot carry inside
rem its own folder - it registers itself with Windows. But it does NOT need
rem the MSI (which can demand a reboot). tools\odbc\install-driver.cmd
rem registers the bundled driver DLL with one administrator prompt and no
rem reboot. Check for the driver; if it is absent, register it and go on.
:checkodbc
set ODBCOK=
reg query "HKLM\SOFTWARE\ODBC\ODBCINST.INI\ODBC Driver 18 for SQL Server" >nul 2>&1 && set ODBCOK=1
if not defined ODBCOK reg query "HKLM\SOFTWARE\ODBC\ODBCINST.INI\ODBC Driver 17 for SQL Server" >nul 2>&1 && set ODBCOK=1
if defined ODBCOK goto odbcok

echo The Microsoft ODBC Driver for SQL Server is not registered on this
echo computer. Registering it now - approve the administrator prompt.
echo This takes a moment and needs NO reboot.
echo.
if not exist "%APPDIR%tools\odbc\install-driver.cmd" goto odbcmissing
powershell -NoProfile -Command "Start-Process -FilePath '%APPDIR%tools\odbc\install-driver.cmd' -ArgumentList 'auto' -Verb RunAs -Wait" 2>nul
rem Re-check now that registration has had its chance to run.
set ODBCOK=
reg query "HKLM\SOFTWARE\ODBC\ODBCINST.INI\ODBC Driver 18 for SQL Server" >nul 2>&1 && set ODBCOK=1
if defined ODBCOK echo Driver registered. Continuing...
if defined ODBCOK goto odbcok

:odbcmissing
echo.
echo The driver still is not registered. If you cancelled the prompt, run
echo start-local.bat again, or right-click tools\odbc\install-driver.cmd
echo and choose Run as administrator.
pause
exit /b 1

:odbcok

rem On PostgreSQL the registers live in the estate cluster on the drive
rem (platform D-028, app D-011): 127.0.0.1 port 5433, program and data on
rem the drive, nothing installed on the computer. Start it before the
rem server. tools\pg\cluster.php runs node estate-pg.js when a node.exe is
rem on the drive or on PATH, and the drive's pg_ctl otherwise. It runs in a
rem hidden window of its own: PostgreSQL on Windows shuts down when the
rem console it started in closes, and closing this window must not stop a
rem cluster other apps may be using.
if /i not "%ENGINE%"=="postgres" goto pgstarted
powershell -NoProfile -Command "$p = Start-Process -FilePath $env:PHP -ArgumentList ('\"' + $env:APPDIR + 'tools\pg\cluster.php\" start') -WindowStyle Hidden -Wait -PassThru; if (-not $p) { exit 1 }; exit $p.ExitCode"
if not errorlevel 1 goto pgstarted
echo PostgreSQL on the drive did not start. What it knows:
"%PHP%" "%APPDIR%tools\pg\cluster.php" status
echo The line log: above names the cluster's own log, which says why.
pause
exit /b 1
:pgstarted

rem LocalDB stops itself after idling, and a server whose first connection
rem has to cold-start it can fail and never recover. Wake it first - only
rem when config.php actually points at LocalDB.
if /i not "%ENGINE%"=="postgres" findstr /i /c:"(localdb)" "%APPDIR%config.php" >nul 2>&1 && sqllocaldb start MSSQLLocalDB >nul 2>&1

rem 8080 is not ours by right. Another project's dev server sat on it for
rem a day, and the launcher could only report that the server "did not come
rem up". Take the first port that is actually free and say which one.
set PORT=
for /f "usebackq delims=" %%P in (`powershell -NoProfile -Command "$p=8080; while($p -le 8089 -and (Get-NetTCPConnection -LocalPort $p -State Listen -ErrorAction SilentlyContinue)){$p++}; if($p -le 8089){$p}"`) do set PORT=%%P

if not defined PORT (
    echo Every port from 8080 to 8089 is already in use.
    echo Close one of the servers using them, then run this again.
    pause
    exit /b 1
)

if not "%PORT%"=="8080" (
    echo Port 8080 is in use by another program, so this is starting on %PORT% instead.
)

rem The scratch folders go on the drive, not on the computer (standard v2
rem section B). sys_temp_dir and upload_tmp_dir are PHP_INI_SYSTEM, so no
rem page can set them: left to PHP, every uploaded photograph lands in the
rem profile temp folder on C: on its way into storage. An unreachable drive
rem leaves RT empty and the server starts without the overrides, because
rem the drive already has its own message.
set RT=
set RTTMP=
set RTUP=
set RTSESS=
rem cmd /c with the ^" wrapping, because this is the only form that
rem survives a space in the PHP path AND in the app path. The app folder
rem it came from is "...\OneDrive - University of Illinois - Urbana\...",
rem so a form that needs an unquoted command is no use here. Tested on
rem 8 Oct 2026 against a copy reached through both kinds of path.
for /f "usebackq delims=" %%R in (`cmd /c ^""%PHP%" "%APPDIR%tools\runtime_dirs.php"^"`) do (
    if not defined RTTMP (set RTTMP=%%R) else if not defined RTUP (set RTUP=%%R) else if not defined RTSESS set RTSESS=%%R
)
rem The inner \" quotes are not decoration: PHP reads a -d value as ini
rem text, so an unquoted path breaks on any character the ini syntax claims.
rem A short Windows name such as %USERPROFILE% written as ...\Users\SAUGAD~1\...
rem was truncated at the tilde and PHP carried on with ...\Users\SAUGAD. A space
rem does the same.
if defined RTSESS set RT=-d "sys_temp_dir=\"%RTTMP%\"" -d "upload_tmp_dir=\"%RTUP%\"" -d "session.save_path=\"%RTSESS%\""
if not defined RTSESS echo Note: the drive scratch folders could not be made, so PHP will use its own temp folder.

echo Using PHP at %PHP%
echo Starting the PRI Facilities Request System on port %PORT%...
echo Leave this window open. Close it to stop the server.
rem On PostgreSQL a hidden watcher stops the drive's cluster cleanly once
rem this server stops answering, unless another app is still connected.
if /i "%ENGINE%"=="postgres" powershell -NoProfile -Command "Start-Process -FilePath $env:PHP -ArgumentList ('-d extension=pdo_pgsql \"' + $env:APPDIR + 'tools\pg\cluster.php\" watch ' + $env:PORT) -WindowStyle Hidden"
start "" http://127.0.0.1:%PORT%/
rem 127.0.0.1, not localhost: localhost is ::1 first and 127.0.0.1
rem second on Windows, so one URL can reach two different servers.
"%PHP%" %PGEXT% %RT% -S 127.0.0.1:%PORT% -t "%APPDIR%facilities"
