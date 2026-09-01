@echo off
REM MediFlow setup, for double-clicking.
REM
REM setup.ps1 does the work; this exists because a .ps1 cannot be run by
REM double-clicking it, and PowerShell refuses unsigned scripts by default.
REM -ExecutionPolicy Bypass applies to this one run only and changes nothing
REM on the machine.

echo.
echo  MediFlow setup
echo  ==============
echo.

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0setup.ps1"

echo.
echo  Press any key to close this window.
pause >nul
