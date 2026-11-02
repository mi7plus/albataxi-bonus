param([string]$BackupFile,[Security.SecureString]$RecoveryPassword,[switch]$ConfirmRestore,[switch]$NoScheduledTasks)
. "$PSScriptRoot\Common.ps1"
$stage=$null;$lock=$null
try {
 if(!$BackupFile){$BackupFile=Read-Host 'Full path to the .albabak file'}
 $BackupFile=[IO.Path]::GetFullPath($BackupFile);if(!(Test-Path -LiteralPath $BackupFile -PathType Leaf)){throw 'Backup file not found.'}
 if(!$RecoveryPassword){$RecoveryPassword=Read-Host 'Backup recovery password' -AsSecureString}
 Protect-StateFolder;$lock=Get-MaintenanceLock
 $stage=Join-Path $script:State ('restore-work-'+[Guid]::NewGuid().ToString('N'));[void][IO.Directory]::CreateDirectory($stage)
 $zip=Join-Path $stage 'backup.zip';Unprotect-Archive $BackupFile $zip (Get-PlainText $RecoveryPassword)
 $payload=Join-Path $stage 'payload';Expand-SafeZip $zip $payload
 $manifest=Get-Content -LiteralPath (Join-Path $payload 'manifest.json') -Raw|ConvertFrom-Json
 if($manifest.Format -ne 1){throw 'Unsupported manifest.'}
 foreach($entry in $manifest.Hashes.PSObject.Properties){
  $file=[IO.Path]::GetFullPath((Join-Path $payload $entry.Name));$base=[IO.Path]::GetFullPath($payload)+[IO.Path]::DirectorySeparatorChar
  if(!$file.StartsWith($base,[StringComparison]::OrdinalIgnoreCase) -or !(Test-Path -LiteralPath $file) -or (Get-FileHash -LiteralPath $file).Hash -ne $entry.Value){throw 'Backup file integrity check failed.'}
 }
 foreach($required in @('database.sql.gz','server.env','sms.env','settings.json','source/Dockerfile','source/compose.laptop.yaml')){if(!(Test-Path -LiteralPath (Join-Path $payload $required))){throw 'Incomplete backup.'}}
 Write-Host ('Verified backup from '+$manifest.CreatedUtc+'; application '+$manifest.Version)
 if(!$ConfirmRestore -and (Read-Host 'Type RESTORE to switch this installation to the backed-up data') -cne 'RESTORE'){throw 'Restore cancelled. Existing data is unchanged.'}
 Wait-Docker
 $oldSettings=$null;if(Test-Path -LiteralPath $script:SettingsFile){$oldSettings=Read-Settings}
 if(!$oldSettings -and [IO.Path]::GetPathRoot($BackupFile) -eq [IO.Path]::GetPathRoot($script:Root)){throw 'On a fresh installation, select the archive directly from the external drive so future backups remain off the laptop.'}
 $stamp=[DateTime]::UtcNow.ToString('yyyyMMdd-HHmmss')
 if(Test-Path -LiteralPath $script:EnvFile){Copy-Item -LiteralPath $script:EnvFile -Destination (Join-Path $script:State ('before-restore-'+$stamp+'.env'));Invoke-Compose stop -t 60 proxy web}
 Write-Utf8 (Join-Path $script:State 'paused') 'Restore in progress; do not publish until verified'
 # A fresh volume avoids overwriting the current database or relying on its old root password.
 $newVolume='albataxi-laptop-restore-'+[Guid]::NewGuid().ToString('N')
 $envText=Get-Content -LiteralPath (Join-Path $payload 'server.env') -Raw
 $envText=[regex]::Replace($envText,'(?m)^DB_VOLUME=.*\r?\n?','');$envText=$envText.TrimEnd()+"`nDB_VOLUME=$newVolume`n"
 Write-Utf8 $script:EnvFile $envText
 Copy-Item -LiteralPath (Join-Path $payload 'sms.env') -Destination (Join-Path $script:State 'sms.env') -Force
 $settings=Get-Content -LiteralPath (Join-Path $payload 'settings.json') -Raw|ConvertFrom-Json
 if($oldSettings){$settings.BackupFolder=$oldSettings.BackupFolder;$settings.BackupDriveId=$oldSettings.BackupDriveId}
 else {$settings.BackupFolder=Split-Path $BackupFile;$marker=Join-Path $settings.BackupFolder '.albataxi-drive-id';if(Test-Path -LiteralPath $marker){$settings.BackupDriveId=(Get-Content -LiteralPath $marker -Raw).Trim()}else{$settings.BackupDriveId=[Guid]::NewGuid().ToString('N');Write-Utf8 $marker $settings.BackupDriveId}}
 Write-Utf8 $script:SettingsFile ($settings|ConvertTo-Json)
 Write-Utf8 (Join-Path $script:State 'backup-key.dpapi') ($RecoveryPassword|ConvertFrom-SecureString)
 # Restore matching application files as well as database/configuration.
 Get-ChildItem -LiteralPath (Join-Path $payload 'source') -Force|ForEach-Object {Copy-Item -LiteralPath $_.FullName -Destination $script:Root -Recurse -Force}
 Invoke-Compose up -d --force-recreate --wait --wait-timeout 240 db
 $container=(& docker compose --project-directory $script:Root --env-file $script:EnvFile -f (Join-Path $script:Root 'compose.laptop.yaml') ps -q db).Trim()
 & docker cp (Join-Path $payload 'database.sql.gz') ($container+':/tmp/alba-restore.sql.gz');if($LASTEXITCODE){throw 'Cannot copy restore data.'}
 Invoke-Compose exec -T db sh -c 'gzip -dc /tmp/alba-restore.sql.gz > /tmp/alba-restore.sql && MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb -uroot < /tmp/alba-restore.sql'
 Invoke-Compose exec -T db rm -f /tmp/alba-restore.sql /tmp/alba-restore.sql.gz
 Invoke-Compose build web
 Invoke-Compose up -d --wait --wait-timeout 240 web
 Invoke-Compose exec -T web php bin/console.php reconcile
 Invoke-Compose exec -T web php bin/console.php doctor
 if(!$NoScheduledTasks){& "$PSScriptRoot\Register-Tasks.ps1"}
 Write-Utf8 (Join-Path $script:State 'installed') ([DateTime]::UtcNow.ToString('o'))
 $lastBackup=Join-Path $script:State 'last-backup.json';if(Test-Path -LiteralPath $lastBackup){Remove-Item -LiteralPath $lastBackup -Force}
 Write-Host ('Restore complete. Run Start.cmd to publish https://'+$settings.Domain)
 Write-Host ('Restored database volume: '+$newVolume+'. Previous volumes have not been deleted.')
 Write-Host 'Check login, balances, reports and both QR layouts. Run Backup.cmd to create a fresh recovery point.'
 exit 0
}catch{Write-Error $_ -ErrorAction Continue;Write-Warning 'If restore had begun, the website remains paused. Use Status.cmd and the recovery guide.';exit 1}
finally{if($stage){Remove-Staging $stage};if($lock){$lock.Dispose()}}
