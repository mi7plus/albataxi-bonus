$ErrorActionPreference='Stop'
. "$PSScriptRoot\..\laptop\Common.ps1"
$work=Join-Path $script:Root ('artifacts/crypto-'+[Guid]::NewGuid().ToString('N'));[void][IO.Directory]::CreateDirectory($work)
$checks=0
function Assert-Crypto($Ok,$Message){if(!$Ok){throw $Message};$script:checks++;Write-Host ('PASS '+$Message)}
try {
 $inputPath=Join-Path $work 'input';$archive=Join-Path $work 'backup.albabak';$output=Join-Path $work 'output'
 [IO.File]::WriteAllText($inputPath,('Synthetic data '+('x'*200000)))
 Protect-Archive $inputPath $archive 'portable-recovery-password-2026'
 Unprotect-Archive $archive $output 'portable-recovery-password-2026'
 Assert-Crypto ((Get-FileHash $inputPath).Hash -eq (Get-FileHash $output).Hash) 'large streaming encryption roundtrip'
 try{Unprotect-Archive $archive (Join-Path $work 'bad') 'wrong-password';throw 'Accepted wrong password'}catch{Assert-Crypto ($_.Exception.Message -ne 'Accepted wrong password') 'wrong password rejected before decryption'}
 $bytes=[IO.File]::ReadAllBytes($archive);$bytes[45]=$bytes[45] -bxor 1;[IO.File]::WriteAllBytes($archive,$bytes)
 try{Unprotect-Archive $archive (Join-Path $work 'tampered') 'portable-recovery-password-2026';throw 'Accepted tampered backup'}catch{Assert-Crypto ($_.Exception.Message -ne 'Accepted tampered backup') 'modified backup rejected'}
 [IO.File]::WriteAllText($inputPath,'');Protect-Archive $inputPath $archive 'portable-recovery-password-2026';Unprotect-Archive $archive $output 'portable-recovery-password-2026'
 Assert-Crypto ((Get-Item $output).Length -eq 0) 'empty-file encryption roundtrip'
 $secret=ConvertTo-SecureString 'dpapi-test-secret' -AsPlainText -Force;$protected=$secret|ConvertFrom-SecureString
 Assert-Crypto ((Get-PlainText (ConvertTo-SecureString $protected)) -ceq 'dpapi-test-secret') 'Windows user protection roundtrip'
 $newDestination=Join-Path $work 'new-drive/AlbaTaxi-Backups';$created=Initialize-BackupFolder $newDestination
 Assert-Crypto ((Test-Path -LiteralPath $created.Folder) -and (Test-Path -LiteralPath (Join-Path $created.Folder '.albataxi-drive-id'))) 'missing backup folder and identity marker created automatically'
 $again=Initialize-BackupFolder $newDestination;Assert-Crypto ($again.Id -ceq $created.Id) 'reselecting existing backup folder preserves its identity'
 $tokens=$null;$parseErrors=$null
 Get-ChildItem (Join-Path $script:Root 'laptop') -Filter '*.ps1'|ForEach-Object {[void][Management.Automation.Language.Parser]::ParseFile($_.FullName,[ref]$tokens,[ref]$parseErrors);Assert-Crypto (!$parseErrors) ('PowerShell parses '+$_.Name)}
 # Inspect definitions without executing registration on the host.
 $definition=Get-Content (Join-Path $script:Root 'laptop/Register-Tasks.ps1') -Raw
 Assert-Crypto ($definition.Contains("-TaskName 'AlbaTaxi-Start'") -and $definition.Contains('-AtLogOn')) 'startup task is triggered by sign-in'
 Assert-Crypto ($definition.Contains('-StartWhenAvailable') -and $definition.Contains('-RestartCount 24')) 'backup task catches missed runs and retries failures'
 Assert-Crypto ($definition.Contains("-Daily -At '03:00'") -and $definition.Contains('-LogonType Interactive')) 'daily backup runs in installation user session'
 Write-Host "$checks Windows PowerShell checks passed. No scheduled tasks installed."
}finally{
 $full=[IO.Path]::GetFullPath($work);$base=[IO.Path]::GetFullPath((Join-Path $script:Root 'artifacts'))+[IO.Path]::DirectorySeparatorChar
 if($full.StartsWith($base,[StringComparison]::OrdinalIgnoreCase)){Remove-Item -LiteralPath $full -Recurse -Force}
}
