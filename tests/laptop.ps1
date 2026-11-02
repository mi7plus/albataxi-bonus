# Isolated laptop deployment rehearsal. Does not register Windows tasks or publish ports publicly.
param([switch]$KeepTestInstallation)
. "$PSScriptRoot\..\laptop\Common.ps1"
$root=$script:Root;$state=$script:State
if(Test-Path -LiteralPath $state){throw 'Refusing to replace existing .laptop configuration. Run this test in a fresh checkout.'}
$testFolder=Join-Path $root 'artifacts/laptop-rehearsal';[void][IO.Directory]::CreateDirectory($testFolder)
$env:COMPOSE_PROJECT_NAME='albataxi-laptop-test';$checks=0;$originalVolume='albataxi-laptop-test-'+[Guid]::NewGuid().ToString('N');$restoreVolume=$null
function Assert-Check($Condition,$Message){if(!$Condition){throw $Message};$script:checks++;Write-Host ('PASS '+$Message)}
try {
 [void][IO.Directory]::CreateDirectory($state);$password='rehearsal-recovery-only-2026';$key=ConvertTo-SecureString $password -AsPlainText -Force
 Write-Utf8 (Join-Path $state 'backup-key.dpapi') ($key|ConvertFrom-SecureString)
 $driveId=[Guid]::NewGuid().ToString('N');Write-Utf8 (Join-Path $testFolder '.albataxi-drive-id') $driveId
 Write-Utf8 $script:SettingsFile (@{Domain='localhost';BackupFolder=$testFolder;BackupDriveId=$driveId}|ConvertTo-Json)
 $caddy=Join-Path $testFolder 'Caddyfile';Write-Utf8 $caddy ":80 {`n reverse_proxy web:80`n}`n"
 $caddyPath=$caddy.Replace('\','/')
 Write-Utf8 $script:EnvFile ("DOMAIN=localhost`nACME_EMAIL=test@example.com`nAPP_KEY=$(New-Hex)`nDB_PASSWORD=$(New-Hex)`nDB_ROOT_PASSWORD=$(New-Hex)`nDB_VOLUME=$originalVolume`nLOCAL_PORT=18098`nHTTP_PORT=18099`nHTTPS_PORT=18499`nPUBLIC_BIND=127.0.0.1`nCADDYFILE=$caddyPath`n")
 Write-Utf8 (Join-Path $state 'sms.env') "SMS_BACKEND=disabled`n"
 Invoke-Compose up -d --build --wait --wait-timeout 300 db web proxy
 Invoke-Compose exec -T web php bin/console.php migrate
 $env:ALBA_ADMIN_PASSWORD='Laptop-test-password-2026';Invoke-Compose exec -T -e ALBA_ADMIN_PASSWORD web php bin/console.php admin 0722123456;Remove-Item Env:ALBA_ADMIN_PASSWORD
 $fixtureContainer=(& docker compose --project-directory $root --env-file $script:EnvFile -f (Join-Path $root 'compose.laptop.yaml') ps -q web).Trim()
 & docker cp (Join-Path $root 'tests/laptop-fixture.php') ($fixtureContainer+':/tmp/laptop-fixture.php');if($LASTEXITCODE){throw 'Cannot copy rehearsal fixture.'}
 Invoke-Compose exec -T -e COMPOSE_FIXTURE=isolated-laptop-rehearsal web php /tmp/laptop-fixture.php
 $before=Invoke-Compose exec -T web php bin/snapshot.php;$beforeText=$before -join "`n";Write-Utf8 (Join-Path $testFolder 'before.json') $beforeText
 Assert-Check ((Invoke-WebRequest -Uri 'http://localhost:18099/?page=health' -UseBasicParsing).StatusCode -eq 200) 'proxy serves the isolated app'
 & (Join-Path $root 'laptop/Backup.ps1');Assert-Check ($LASTEXITCODE -eq 0) 'encrypted backup completes'
 $last=Get-LastBackup (Read-Settings);Assert-Check (Test-Path -LiteralPath $last.File) 'completed archive is on configured drive'
 $decoded=Join-Path $testFolder 'verified.zip';Unprotect-Archive $last.File $decoded $password
 try{Unprotect-Archive $last.File ($decoded+'.wrong') 'incorrect-password';throw 'Wrong password accepted'}catch{Assert-Check ($_.Exception.Message -ne 'Wrong password accepted') 'wrong recovery password rejected'}
 $tampered=Join-Path $testFolder 'tampered.albabak';Copy-Item -LiteralPath $last.File -Destination $tampered;$bytes=[IO.File]::ReadAllBytes($tampered);$bytes[50]=$bytes[50] -bxor 1;[IO.File]::WriteAllBytes($tampered,$bytes)
 try{Unprotect-Archive $tampered ($decoded+'.tampered') $password;throw 'Tampering accepted'}catch{Assert-Check ($_.Exception.Message -ne 'Tampering accepted') 'modified archive rejected'}
 $filesBefore=(Get-ChildItem $testFolder -Filter 'albataxi-*.albabak').Count
 & (Join-Path $root 'laptop/Backup.ps1') -IfDue;Assert-Check ((Get-ChildItem $testFolder -Filter 'albataxi-*.albabak').Count -eq $filesBefore) 'startup catch-up skips a fresh successful backup'
 Write-Utf8 (Join-Path $testFolder '.albataxi-drive-id') 'wrong-drive'
 try{Assert-BackupDrive (Read-Settings)|Out-Null;throw 'Wrong drive accepted'}catch{Assert-Check ($_.Exception.Message -ne 'Wrong drive accepted') 'wrong external drive identity rejected'}
 Write-Utf8 (Join-Path $testFolder '.albataxi-drive-id') $driveId
 Invoke-Compose kill -s SIGKILL db web proxy
 Invoke-Compose up -d --wait --wait-timeout 240
 $after=Invoke-Compose exec -T web php bin/snapshot.php
 Assert-Check (($after -join "`n") -eq $beforeText) 'abrupt container loss preserves database snapshot after restart'
 & (Join-Path $root 'laptop/Restore.ps1') -BackupFile $last.File -RecoveryPassword $key -ConfirmRestore -NoScheduledTasks
 Assert-Check ($LASTEXITCODE -eq 0) 'encrypted archive restores into fresh volume'
 $restoreVolume=([regex]::Match((Get-Content $script:EnvFile -Raw),'(?m)^DB_VOLUME=(.+)$')).Groups[1].Value.Trim()
 Assert-Check ($restoreVolume -ne $originalVolume) 'restore preserves original database volume'
 $restored=Invoke-Compose exec -T web php bin/snapshot.php
 Assert-Check (($restored -join "`n") -eq $beforeText) 'restored database exactly matches original snapshot'
 Assert-Check (Test-Path -LiteralPath (Join-Path $state 'paused')) 'restored site waits for explicit start'
 Write-Host "$checks laptop checks passed. Windows task registration and physical power loss were not exercised."
}finally{
 if(!$KeepTestInstallation){
  if(Test-Path -LiteralPath $script:EnvFile){try{Invoke-Compose down}catch{}}
  foreach($volume in @($originalVolume,$restoreVolume)){if($volume -and ($volume.StartsWith('albataxi-laptop-test-') -or $volume.StartsWith('albataxi-laptop-restore-'))){& docker volume rm $volume|Out-Null}}
  $resolved=[IO.Path]::GetFullPath($state);if($resolved -eq (Join-Path $root '.laptop')){Remove-Item -LiteralPath $resolved -Recurse -Force}
 }
 Remove-Item Env:COMPOSE_PROJECT_NAME -ErrorAction SilentlyContinue
}
