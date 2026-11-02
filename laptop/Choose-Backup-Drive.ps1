. "$PSScriptRoot\Common.ps1"
$lock=$null
try {
 $settings=Read-Settings;$folder=Join-Path (Select-BackupDrive) 'AlbaTaxi-Backups';$lock=Get-MaintenanceLock
 $destination=Initialize-BackupFolder $folder
 $settings.BackupFolder=$destination.Folder;$settings.BackupDriveId=$destination.Id
 Write-Utf8 $script:SettingsFile ($settings|ConvertTo-Json)
 $last=Join-Path $script:State 'last-backup.json';if(Test-Path -LiteralPath $last){Remove-Item -LiteralPath $last -Force}
 Write-Host ('Backup drive selected: '+$destination.Folder+'. Existing backups on other drives have not been deleted.')
}catch{Write-Error $_ -ErrorAction Continue;exit 1}finally{if($lock){$lock.Dispose()}}
& "$PSScriptRoot\Backup.ps1"
exit $LASTEXITCODE
