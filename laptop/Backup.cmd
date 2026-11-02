@echo off
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0Backup.ps1" %*
if errorlevel 1 echo Operation failed. Read the message above.
pause
