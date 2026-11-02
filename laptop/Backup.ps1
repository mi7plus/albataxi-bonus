param([switch]$IfDue)
. "$PSScriptRoot\Common.ps1"
$lock=$null;$stage=$null;$remote=$null;$partial=$null
try {
 $settings=Read-Settings;$last=Get-LastBackup $settings
 if($IfDue -and $last -and ([DateTime]::UtcNow-([DateTime]$last.CompletedUtc).ToUniversalTime()).TotalHours -lt 24){Write-Host 'A successful backup is less than 24 hours old.';exit 0}
 $lock=Get-MaintenanceLock;$folder=Assert-BackupDrive $settings;Wait-Docker
 $id=[Guid]::NewGuid().ToString('N');$stage=Join-Path $script:State ('backup-work-'+$id);[void][IO.Directory]::CreateDirectory($stage)
 $remote='/tmp/alba-backup-'+$id
 Invoke-Compose exec -T db sh -c ('MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb-dump -uroot --single-transaction --quick --hex-blob --routines --events --triggers --databases albataxi > '+$remote+'.sql && gzip -c '+$remote+'.sql > '+$remote+'.sql.gz')
 $container=(& docker compose --project-directory $script:Root --env-file $script:EnvFile -f (Join-Path $script:Root 'compose.laptop.yaml') ps -q db).Trim();if(!$container){throw 'Database is not running.'}
 & docker cp ($container+':'+$remote+'.sql.gz') (Join-Path $stage 'database.sql.gz');if($LASTEXITCODE){throw 'Database dump copy failed.'}
 Copy-Item -LiteralPath $script:EnvFile -Destination (Join-Path $stage 'server.env');Copy-Item -LiteralPath (Join-Path $script:State 'sms.env') -Destination (Join-Path $stage 'sms.env')
 Copy-Item -LiteralPath $script:SettingsFile -Destination (Join-Path $stage 'settings.json')
 # Include the source needed to rebuild, without local state or other backups.
 $source=Join-Path $stage 'source';[void][IO.Directory]::CreateDirectory($source)
 foreach($entry in @('app','bin','database','docs','public','laptop','Dockerfile','compose.laptop.yaml','composer.json','composer.lock','VERSION','README.md','.dockerignore','.gitignore')){Copy-Item -LiteralPath (Join-Path $script:Root $entry) -Destination $source -Recurse}
 foreach($file in @('config.local.php','public/app-path.php')){$unwanted=Join-Path $source $file;if(Test-Path -LiteralPath $unwanted){Remove-Item -LiteralPath $unwanted -Force}}
 $hashes=@{};Get-ChildItem -LiteralPath $stage -File -Recurse | ForEach-Object {$relative=$_.FullName.Substring($stage.Length+1);$hashes[$relative]=(Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash}
 Write-Utf8 (Join-Path $stage 'manifest.json') (@{Format=1;CreatedUtc=[DateTime]::UtcNow.ToString('o');Version=(Get-Content (Join-Path $script:Root 'VERSION') -Raw).Trim();Hashes=$hashes}|ConvertTo-Json -Depth 5)
 Add-Type -AssemblyName System.IO.Compression.FileSystem;$zip=Join-Path $script:State ('backup-'+$id+'.zip');[IO.Compression.ZipFile]::CreateFromDirectory($stage,$zip)
 $fileName='albataxi-'+[DateTime]::UtcNow.ToString('yyyyMMdd-HHmmss')+'-'+$id.Substring(0,8)+'.albabak';$final=Join-Path $folder $fileName;$partial=$final+'.partial'
 try {Protect-Archive $zip $partial (Get-BackupPassword);Move-Item -LiteralPath $partial -Destination $final;Write-Utf8 (Join-Path $script:State 'last-backup.json') (@{CompletedUtc=[DateTime]::UtcNow.ToString('o');File=$final;SHA256=(Get-FileHash -LiteralPath $final).Hash}|ConvertTo-Json)} finally {if(Test-Path -LiteralPath $zip){Remove-Item -LiteralPath $zip -Force}}
 Write-Host "Backup complete: $final";exit 0
} catch {
 if(Test-Path -LiteralPath $script:State){Write-Utf8 (Join-Path $script:State 'backup-error.txt') ([DateTime]::UtcNow.ToString('o')+' '+$_.Exception.Message)}
 Write-Error $_ -ErrorAction Continue;exit 1
} finally {
 if($remote){try{Invoke-Compose exec -T db rm -f ($remote+'.sql') ($remote+'.sql.gz')}catch{}}
 if($stage){Remove-Staging $stage};if($partial -and (Test-Path -LiteralPath $partial)){Remove-Item -LiteralPath $partial -Force};if($lock){$lock.Dispose()}
}
