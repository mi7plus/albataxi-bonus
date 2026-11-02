. "$PSScriptRoot\Common.ps1"
try {
 $s=Read-Settings;Write-Host ('Website: https://'+$s.Domain);Write-Host ('Backup folder: '+$s.BackupFolder)
 if(Test-Path -LiteralPath (Join-Path $script:State 'paused')){Write-Warning 'Website intentionally stopped.'}
 try{Assert-BackupDrive $s|Out-Null;Write-Host 'External backup drive: connected'}catch{Write-Warning $_.Exception.Message}
 $last=Get-LastBackup $s
 if($last){Write-Host ('Last successful backup (UTC): '+$last.CompletedUtc);if(([DateTime]::UtcNow-([DateTime]$last.CompletedUtc).ToUniversalTime()).TotalHours -gt 24){Write-Warning 'BACKUP OVERDUE: run Backup.cmd now.'}}else{Write-Warning 'NO SUCCESSFUL BACKUP YET: run Backup.cmd.'}
 Wait-Docker;Invoke-Compose ps
 foreach($task in @('AlbaTaxi-Start','AlbaTaxi-Backup')){try{$info=Get-ScheduledTaskInfo -TaskName $task;Write-Host ($task+': last result '+$info.LastTaskResult+'; next run '+$info.NextRunTime)}catch{Write-Warning ($task+' not installed.')}}
}catch{Write-Error $_ -ErrorAction Continue;exit 1}
