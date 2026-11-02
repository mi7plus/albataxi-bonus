. "$PSScriptRoot\Common.ps1"
$powershell=Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe';$user=[Security.Principal.WindowsIdentity]::GetCurrent().Name
$principal=New-ScheduledTaskPrincipal -UserId $user -LogonType Interactive -RunLevel Limited
$startAction=New-ScheduledTaskAction -Execute $powershell -Argument ('-NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File "'+$PSScriptRoot+'\Start.ps1" -Automatic') -WorkingDirectory $script:Root
$startSettings=New-ScheduledTaskSettingsSet -StartWhenAvailable -RestartCount 6 -RestartInterval (New-TimeSpan -Minutes 5) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 20)
Register-ScheduledTask -TaskName 'AlbaTaxi-Start' -Action $startAction -Trigger (New-ScheduledTaskTrigger -AtLogOn -User $user) -Principal $principal -Settings $startSettings -Force|Out-Null
$backupAction=New-ScheduledTaskAction -Execute $powershell -Argument ('-NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File "'+$PSScriptRoot+'\Backup.ps1"') -WorkingDirectory $script:Root
$backupSettings=New-ScheduledTaskSettingsSet -StartWhenAvailable -WakeToRun -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -RestartCount 24 -RestartInterval (New-TimeSpan -Hours 1) -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Hours 4)
Register-ScheduledTask -TaskName 'AlbaTaxi-Backup' -Action $backupAction -Trigger (New-ScheduledTaskTrigger -Daily -At '03:00') -Principal $principal -Settings $backupSettings -Force|Out-Null
Write-Host 'Automatic start at Windows sign-in and daily backups are registered.'
