@echo off
REM PRI Facilities Request setup. Finds PHP, writes config.local.json and
REM points storage at the drive-level data folder when that folder exists.
REM It starts no server and never connects to SQL Server; the database
REM itself is set up through facilities\install.php (see AGENTS.md).

setlocal
cd /d "%~dp0"

echo.
echo PRI Facilities Request setup
echo ----------------------------
echo.

set PHP=
if exist "%~dp0tools\php\php.exe" set PHP=%~dp0tools\php\php.exe
if not defined PHP if exist "%~dp0php-path.txt" set /p PHP=<"%~dp0php-path.txt"
if defined PHP if not exist "%PHP%" set PHP=
if not defined PHP for /f "delims=" %%P in ('where php 2^>nul') do if not defined PHP set PHP=%%P
if not defined PHP (
    echo   PHP was not found: not in tools\php, not in php-path.txt, not on PATH.
    echo   Copy the bundled PHP into tools\php and run SETUP.bat again.
    echo.
    pause
    exit /b 1
)
echo   PHP: %PHP%

REM Portable settings: config.local.json starts as a copy of the template.
"%PHP%" "%~dp0tools\local_config.php" init
if errorlevel 1 goto failed

REM On the drive, storage lives in ..\..\data\pri-facilities-request (D-014).
if exist "%~dp0..\..\data\pri-facilities-request\storage\" (
    "%PHP%" "%~dp0tools\local_config.php" set-data-folder "..\..\data\pri-facilities-request"
    if errorlevel 1 goto failed
) else (
    echo   No drive data folder beside the app; storage stays in the app folder.
)

echo.
echo Setup complete. Start the app with "PRI Facilities Request.vbs".
echo.
pause
endlocal
exit /b 0

:failed
echo.
echo   Setup stopped. The message above says why.
echo.
pause
endlocal
exit /b 1
