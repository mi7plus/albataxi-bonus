@echo off
powershell.exe -NoProfile -STA -ExecutionPolicy Bypass -File "%~dp0Choose-Backup-Drive.ps1"
if errorlevel 1 echo Drive selection or backup failed. Read the message above.
pause
