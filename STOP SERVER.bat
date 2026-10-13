@echo off
rem PRI Facilities Request System - stop the local web server and, on
rem PostgreSQL, the drive's database cluster.
rem
rem The server PRI Facilities Request.vbs starts has no window, so this is
rem the way to close it. It ends only the PHP servers serving this folder,
rem then, when dataMode is postgres, stops the estate cluster on the drive
rem cleanly (platform D-028, app D-011) unless another app is still
rem connected. Run it before ejecting the drive.

setlocal
set APPDIR=%~dp0
set PHP=
if exist "%APPDIR%tools\php\php.exe" set PHP=%APPDIR%tools\php\php.exe
if not defined PHP if exist "%APPDIR%php-path.txt" set /p PHP=<"%APPDIR%php-path.txt"
if defined PHP if not exist "%PHP%" set PHP=
if not defined PHP for /f "delims=" %%P in ('where php 2^>nul') do if not defined PHP set PHP=%%P
if not defined PHP (
    echo PHP was not found: not in tools\php, not in php-path.txt, not on PATH.
    pause
    exit /b 1
)

rem Only php.exe processes running the built-in server on this folder's
rem document root: another app's server, or another copy of this one, is
rem left alone.
powershell -NoProfile -Command "$d = ($env:APPDIR + 'facilities').ToLower(); $n = 0; Get-CimInstance Win32_Process -Filter \"Name='php.exe'\" | Where-Object { $_.CommandLine -and $_.CommandLine.ToLower().Contains(' -s 127.0.0.1:') -and $_.CommandLine.ToLower().Contains($d) } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force; $n++ }; 'Stopped ' + $n + ' server process(es) for this folder.'"

set ENGINE=
for /f "usebackq delims=" %%E in (`cmd /c ^""%PHP%" "%APPDIR%tools\data_mode.php"^"`) do set ENGINE=%%E
if /i not "%ENGINE%"=="postgres" goto done

"%PHP%" -d extension=pdo_pgsql "%APPDIR%tools\pg\cluster.php" stop
if errorlevel 1 (
    echo.
    echo The drive's PostgreSQL was left running; the line above says why.
    pause
    exit /b 1
)

:done
echo Done. The drive can be ejected once every app on it is closed.
pause
endlocal
exit /b 0
