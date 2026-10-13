@echo off
setlocal
rem Register the Microsoft ODBC Driver 18 for SQL Server WITHOUT the MSI
rem and WITHOUT a reboot. The driver is one DLL plus a few registry lines.
rem This copies the DLL (and the VC++ runtime it needs) to a stable local
rem folder and points the ODBC registry at it. Takes effect immediately.
rem
rem Pass 'auto' to skip the closing pause (the launcher does this).

set "SRC=%~dp0x64"
set "DEST=%ProgramData%\PRIOps\odbc"
set "DRV=ODBC Driver 18 for SQL Server"
set "INST=HKLM\SOFTWARE\ODBC\ODBCINST.INI"

rem The machine-wide driver registry needs administrator rights. If we do
rem not have them, relaunch this same script elevated and stop this copy.
net session >nul 2>&1
if %errorlevel% neq 0 (
    echo Requesting administrator rights ^(no reboot needed^)...
    powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -ArgumentList '%*' -Verb RunAs"
    exit /b
)

if not exist "%SRC%\msodbcsql18.dll" (
    echo Could not find the driver files next to this script:
    echo   %SRC%\msodbcsql18.dll
    echo Make sure the whole tools\odbc folder came across with the app.
    if /i not "%~1"=="auto" pause
    exit /b 1
)

echo Copying the driver to %DEST% ...
if not exist "%DEST%" mkdir "%DEST%"
copy /y "%SRC%\*.dll" "%DEST%" >nul

echo Registering "%DRV%" ...
reg add "%INST%\%DRV%" /f /v Driver /t REG_SZ /d "%DEST%\msodbcsql18.dll" >nul
reg add "%INST%\%DRV%" /f /v Setup  /t REG_SZ /d "%DEST%\msodbcsql18.dll" >nul
reg add "%INST%\%DRV%" /f /v UsageCount /t REG_DWORD /d 1 >nul
rem The capability attributes a real install writes, matched exactly so the
rem driver enumerates and answers SQLGetInfo the same way.
reg add "%INST%\%DRV%" /f /v APILevel /t REG_SZ /d "2" >nul
reg add "%INST%\%DRV%" /f /v ConnectFunctions /t REG_SZ /d "YYY" >nul
reg add "%INST%\%DRV%" /f /v CPTimeout /t REG_SZ /d "60" >nul
reg add "%INST%\%DRV%" /f /v DriverODBCVer /t REG_SZ /d "03.80" >nul
reg add "%INST%\%DRV%" /f /v FileUsage /t REG_SZ /d "0" >nul
reg add "%INST%\%DRV%" /f /v SQLLevel /t REG_SZ /d "1" >nul
reg add "%INST%\ODBC Drivers" /f /v "%DRV%" /t REG_SZ /d "Installed" >nul
if %errorlevel% neq 0 (
    echo Registration failed. Try running this file as administrator by
    echo right-clicking it and choosing Run as administrator.
    if /i not "%~1"=="auto" pause
    exit /b 1
)

echo.
echo Done. "%DRV%" is registered and points at:
echo   %DEST%\msodbcsql18.dll
echo No reboot needed - start the application now.
if /i not "%~1"=="auto" pause
exit /b 0
