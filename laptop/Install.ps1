param([string]$Domain,[string]$BackupFolder,[string]$Email)
. "$PSScriptRoot\Common.ps1"
try {
 if((Test-Path -LiteralPath $script:SettingsFile) -and (Test-Path -LiteralPath (Join-Path $script:State 'installed'))){Write-Host 'Already configured. Existing secrets and data will be preserved.';& "$PSScriptRoot\Register-Tasks.ps1";& "$PSScriptRoot\Start.ps1";exit $LASTEXITCODE}
 Wait-Docker
 $resume=Test-Path -LiteralPath $script:SettingsFile
 if(!$resume){
 $existingVolumes=& docker volume ls --format '{{.Name}}';if($existingVolumes -contains 'albataxi-laptop-database'){throw 'An existing laptop database was found. Recover its .laptop configuration or use Restore.cmd; do not create new secrets over existing data.'}
 if(!$Domain){$Domain=Read-Host 'Domain [bonus.albataxi.net] (press Enter to use this subdomain)';if(!$Domain){$Domain='bonus.albataxi.net'}}
 $Domain=$Domain.Trim().ToLowerInvariant()
 if($Domain -notmatch '^(?=.{1,253}$)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,63}$'){throw 'Enter a valid public domain name.'}
 if(!$Email){$Email=Read-Host 'Email for HTTPS certificate notices'};if($Email -notmatch '^[^\s"''=]+@[^\s"''=]+\.[^\s"''=]+$'){throw 'Enter a valid email address.'}
 if(!$BackupFolder){$BackupFolder=Join-Path (Select-BackupDrive) 'AlbaTaxi-Backups'}
 if($BackupFolder -notmatch '^[A-Za-z]:\\'){throw 'Use an absolute external-drive path, for example E:\AlbaTaxi-Backups.'}
 $BackupFolder=[IO.Path]::GetFullPath($BackupFolder);$driveRoot=[IO.Path]::GetPathRoot($BackupFolder)
 if(!(Test-Path -LiteralPath $driveRoot)){throw 'Connect the external backup drive first.'}
 if($driveRoot -eq [IO.Path]::GetPathRoot($script:Root)){throw 'Choose the external hard drive, not the application drive.'}
 $phone=Read-Host 'Initial administrator phone';$adminSecret=Read-Host 'Administrator password (10-72 characters)' -AsSecureString;$adminPassword=Get-PlainText $adminSecret
 if($adminPassword.Length -lt 10 -or $adminPassword.Length -gt 72){throw 'Administrator password must contain 10-72 characters.'}
 $backupSecret=Read-Host 'Backup recovery password (at least 16 characters; keep it safely outside this laptop)' -AsSecureString;$backupPassword=Get-PlainText $backupSecret
 if($backupPassword.Length -lt 16){throw 'Recovery password must contain at least 16 characters.'}
 $repeat=Get-PlainText (Read-Host 'Repeat backup recovery password' -AsSecureString);if($repeat -cne $backupPassword){throw 'Recovery passwords do not match.'}
 Protect-StateFolder
 $destination=Initialize-BackupFolder $BackupFolder;$BackupFolder=$destination.Folder;$driveId=$destination.Id
 Write-Utf8 (Join-Path $script:State 'backup-key.dpapi') ($backupSecret|ConvertFrom-SecureString)
 Write-Utf8 $script:EnvFile ("DOMAIN=$Domain`nACME_EMAIL=$Email`nAPP_KEY=$(New-Hex)`nDB_PASSWORD=$(New-Hex)`nDB_ROOT_PASSWORD=$(New-Hex)`nDB_VOLUME=albataxi-laptop-database`n")
 Write-Utf8 (Join-Path $script:State 'sms.env') "SMS_BACKEND=disabled`nSMSLINK_CONNECTION_ID=`nSMSLINK_PASSWORD=`nSMSLINK_SENDER=`n"
 Write-Utf8 $script:SettingsFile (@{Domain=$Domain;BackupFolder=$BackupFolder;BackupDriveId=$driveId}|ConvertTo-Json)
 }else{Write-Host 'Resuming incomplete installation with existing configuration and secrets.'}
 Invoke-Compose up -d --build --wait --wait-timeout 300 db web
 Invoke-Compose exec -T web php bin/console.php migrate
 $admins=Invoke-Compose exec -T web php bin/console.php admin-count
 if([int]($admins|Select-Object -Last 1) -eq 0){
 if($resume){$phone=Read-Host 'Initial administrator phone';$adminPassword=Get-PlainText (Read-Host 'Administrator password (10-72 characters)' -AsSecureString)}
 $env:ALBA_ADMIN_PASSWORD=$adminPassword
 try {Invoke-Compose exec -T -e ALBA_ADMIN_PASSWORD web php bin/console.php admin $phone} finally {Remove-Item Env:ALBA_ADMIN_PASSWORD -ErrorAction SilentlyContinue;$adminPassword=$null}
 }
 & "$PSScriptRoot\Register-Tasks.ps1"
 & "$PSScriptRoot\Start.ps1"
 if($LASTEXITCODE){throw 'Startup failed. Use Status.cmd.'}
 Write-Utf8 (Join-Path $script:State 'installed') ([DateTime]::UtcNow.ToString('o'))
 Write-Host 'Installation complete. Daily backup: 03:00 Windows local time, with missed-run catch-up and retries.'
 Write-Host 'Keep your recovery password: it is required if the laptop or Windows account is lost.'
}catch{Write-Error $_ -ErrorAction Continue;exit 1}
