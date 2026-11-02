$ErrorActionPreference = 'Stop'
$script:Root = Split-Path $PSScriptRoot -Parent
$script:State = Join-Path $script:Root '.laptop'
$script:EnvFile = Join-Path $script:State 'server.env'
$script:SettingsFile = Join-Path $script:State 'settings.json'

function Write-Utf8($Path, $Text) { [IO.File]::WriteAllText($Path, $Text, (New-Object Text.UTF8Encoding($false))) }
function Get-BackupDriveChoices {
 $excluded=@([IO.Path]::GetPathRoot($script:Root),($env:SystemDrive+'\'))
 foreach($drive in [IO.DriveInfo]::GetDrives()){
  if($drive.IsReady -and $drive.DriveType -in @([IO.DriveType]::Fixed,[IO.DriveType]::Removable) -and $drive.Name -notin $excluded){
   [pscustomobject]@{Root=$drive.Name;Label=('{0}  {1}  ({2:N1} GB free)' -f $drive.Name,$drive.VolumeLabel,($drive.AvailableFreeSpace/1GB))}
  }
 }
}
function Select-BackupDrive {
 Add-Type -AssemblyName System.Windows.Forms
 $form=New-Object Windows.Forms.Form;$form.Text='AlbaTaxi - Select external backup drive';$form.Width=600;$form.Height=225;$form.StartPosition='CenterScreen';$form.FormBorderStyle='FixedDialog';$form.MaximizeBox=$false;$form.MinimizeBox=$false
 $label=New-Object Windows.Forms.Label;$label.Text='Choose your external hard drive. The AlbaTaxi-Backups folder will be created automatically.';$label.SetBounds(18,18,550,40)
 $list=New-Object Windows.Forms.ComboBox;$list.DropDownStyle='DropDownList';$list.SetBounds(18,65,440,28);$list.DisplayMember='Label'
 $refresh=New-Object Windows.Forms.Button;$refresh.Text='Refresh';$refresh.SetBounds(470,65,95,28)
 $ok=New-Object Windows.Forms.Button;$ok.Text='Select drive';$ok.SetBounds(330,125,115,32);$ok.DialogResult=[Windows.Forms.DialogResult]::OK
 $cancel=New-Object Windows.Forms.Button;$cancel.Text='Cancel';$cancel.SetBounds(455,125,110,32);$cancel.DialogResult=[Windows.Forms.DialogResult]::Cancel
 $reload={ $list.Items.Clear();foreach($choice in @(Get-BackupDriveChoices)){[void]$list.Items.Add($choice)};if($list.Items.Count){$list.SelectedIndex=0};$ok.Enabled=$list.Items.Count -gt 0 }
 $refresh.Add_Click($reload);$form.Controls.AddRange(@($label,$list,$refresh,$ok,$cancel));$form.AcceptButton=$ok;$form.CancelButton=$cancel
 try {& $reload;if($form.ShowDialog() -ne [Windows.Forms.DialogResult]::OK -or !$list.SelectedItem){throw 'Drive selection cancelled. Connect the external hard drive and retry.'};return $list.SelectedItem.Root}finally{$form.Dispose()}
}
function Initialize-BackupFolder([string]$Folder) {
 $full=[IO.Path]::GetFullPath($Folder);$root=[IO.Path]::GetPathRoot($full)
 if(!(Test-Path -LiteralPath $root)){throw 'Connect the selected drive first.'}
 [void][IO.Directory]::CreateDirectory($full)
 $marker=Join-Path $full '.albataxi-drive-id'
 if(Test-Path -LiteralPath $marker){$id=(Get-Content -LiteralPath $marker -Raw).Trim();if(!$id){throw 'The backup identity marker is empty. Check the selected folder.'}}
 else {$id=[Guid]::NewGuid().ToString('N');Write-Utf8 $marker $id}
 return [pscustomobject]@{Folder=$full;Id=$id}
}
function Protect-StateFolder {
 [void][IO.Directory]::CreateDirectory($script:State);$sid=[Security.Principal.WindowsIdentity]::GetCurrent().User.Value
 & icacls $script:State /inheritance:r /grant:r ('*'+$sid+':(OI)(CI)F') '*S-1-5-18:(OI)(CI)F' /Q | Out-Null
 if($LASTEXITCODE){throw 'Cannot restrict local configuration permissions.'}
}
function Read-Settings {
 if (!(Test-Path -LiteralPath $script:SettingsFile)) { throw 'Run Install.cmd first.' }
 Get-Content -LiteralPath $script:SettingsFile -Raw | ConvertFrom-Json
}
function Invoke-Compose {
 & docker compose --project-directory $script:Root --env-file $script:EnvFile -f (Join-Path $script:Root 'compose.laptop.yaml') @args
 if ($LASTEXITCODE -ne 0) { throw 'Docker operation failed. Check Docker Desktop and Status.cmd.' }
}
function Wait-Docker {
 if (!(Get-Command docker -ErrorAction SilentlyContinue)) { throw 'Install Docker Desktop with its WSL 2 backend, restart Windows if requested, then run Install.cmd again. https://docs.docker.com/desktop/setup/install/windows-install/' }
 $previous=$ErrorActionPreference;$ErrorActionPreference='Continue'
 try {& docker info --format '{{.ServerVersion}}' 2>$null | Out-Null} finally {$ErrorActionPreference=$previous}
 if ($LASTEXITCODE -ne 0) {
  $desktop = Join-Path $env:ProgramFiles 'Docker\Docker\Docker Desktop.exe'
  if (Test-Path -LiteralPath $desktop) { Start-Process -FilePath $desktop -WindowStyle Hidden }
  $deadline = (Get-Date).AddMinutes(4)
  do { Start-Sleep -Seconds 5; $previous=$ErrorActionPreference;$ErrorActionPreference='Continue';try{& docker info --format '{{.ServerVersion}}' 2>$null | Out-Null}finally{$ErrorActionPreference=$previous}; if ($LASTEXITCODE -eq 0) { return } } while ((Get-Date) -lt $deadline)
  throw 'Docker Desktop did not become ready. Open it and check WSL 2, then retry.'
 }
}
function Get-PlainText([Security.SecureString]$Secret) {
 $ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($Secret)
 try { [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr) } finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr) }
}
function New-Hex([int]$Bytes=32) {
 $buffer=New-Object byte[] $Bytes; $rng=[Security.Cryptography.RandomNumberGenerator]::Create()
 try {$rng.GetBytes($buffer)} finally {$rng.Dispose()}; -join ($buffer | ForEach-Object {$_.ToString('x2')})
}
function Get-BackupPassword {
 $path=Join-Path $script:State 'backup-key.dpapi'
 if (!(Test-Path -LiteralPath $path)) { throw 'Backup recovery password is not configured. Run Install.cmd.' }
 Get-PlainText (ConvertTo-SecureString (Get-Content -LiteralPath $path -Raw))
}
function Get-MaintenanceLock {
 [IO.File]::Open((Join-Path $script:State 'operation.lock'),[IO.FileMode]::OpenOrCreate,[IO.FileAccess]::ReadWrite,[IO.FileShare]::None)
}
function Assert-BackupDrive($Settings) {
 $folder=[IO.Path]::GetFullPath($Settings.BackupFolder)
 $root=[IO.Path]::GetPathRoot($folder)
 if (!(Test-Path -LiteralPath $root)) { throw 'The backup drive is disconnected. Connect the external drive and retry Backup.cmd.' }
 if (!(Test-Path -LiteralPath $folder)) { throw 'The configured backup folder is missing. Reconnect the correct drive; do not silently use another location.' }
 $marker=Join-Path $folder '.albataxi-drive-id'
 if (!(Test-Path -LiteralPath $marker) -or (Get-Content -LiteralPath $marker -Raw).Trim() -ne $Settings.BackupDriveId) { throw 'Wrong backup drive or folder. Its AlbaTaxi identity marker does not match.' }
 return $folder
}
function Get-LastBackup($Settings) {
 $path=Join-Path $script:State 'last-backup.json'
 if (Test-Path -LiteralPath $path) { return (Get-Content -LiteralPath $path -Raw | ConvertFrom-Json) }
 return $null
}
function Get-BackupMac($Path, [byte[]]$Key, [long]$Length) {
 $h=New-Object Security.Cryptography.HMACSHA256; $h.Key=$Key
 $s=[IO.File]::OpenRead($Path);$buffer=New-Object byte[] 65536
 try {while($Length -gt 0){$n=$s.Read($buffer,0,[int][Math]::Min($buffer.Length,$Length));if(!$n){throw 'Truncated backup.'};[void]$h.TransformBlock($buffer,0,$n,$buffer,0);$Length-=$n};[void]$h.TransformFinalBlock((New-Object byte[] 0),0,0);return ,$h.Hash} finally {$s.Dispose();$h.Dispose()}
}
function Get-ArchiveKeys([string]$Password,[byte[]]$Salt) {
 $k=New-Object Security.Cryptography.Rfc2898DeriveBytes($Password,$Salt,200000,[Security.Cryptography.HashAlgorithmName]::SHA256)
 try { return ,$k.GetBytes(64) } finally {$k.Dispose()}
}
function Protect-Archive($InputPath,$OutputPath,[string]$Password) {
 $salt=New-Object byte[] 16;$iv=New-Object byte[] 16;$rng=[Security.Cryptography.RandomNumberGenerator]::Create();$rng.GetBytes($salt);$rng.GetBytes($iv);$rng.Dispose()
 $keys=Get-ArchiveKeys $Password $salt;$aes=[Security.Cryptography.Aes]::Create();$aes.Key=[byte[]]$keys[0..31];$aes.IV=$iv
 $input=[IO.File]::OpenRead($InputPath);$output=[IO.File]::Create($OutputPath)
 try {$header=[Text.Encoding]::ASCII.GetBytes('ALBABAK1');$output.Write($header,0,8);$output.Write($salt,0,16);$output.Write($iv,0,16);$crypto=New-Object Security.Cryptography.CryptoStream($output,$aes.CreateEncryptor(),[Security.Cryptography.CryptoStreamMode]::Write);try {$input.CopyTo($crypto);$crypto.FlushFinalBlock()} finally {$crypto.Dispose()}} finally {$input.Dispose();$output.Dispose();$aes.Dispose()}
 $mac=Get-BackupMac $OutputPath ([byte[]]$keys[32..63]) (Get-Item -LiteralPath $OutputPath).Length
 $output=[IO.File]::Open($OutputPath,[IO.FileMode]::Append);try {$output.Write($mac,0,$mac.Length)} finally {$output.Dispose();[Array]::Clear($keys,0,$keys.Length)}
}
function Unprotect-Archive($InputPath,$OutputPath,[string]$Password) {
 $input=[IO.File]::OpenRead($InputPath)
 try {
  if($input.Length -lt 88){throw 'Invalid backup.'};$header=New-Object byte[] 8;$salt=New-Object byte[] 16;$iv=New-Object byte[] 16
  [void]$input.Read($header,0,8);[void]$input.Read($salt,0,16);[void]$input.Read($iv,0,16)
  if([Text.Encoding]::ASCII.GetString($header) -ne 'ALBABAK1'){throw 'Unsupported backup format.'}
  $keys=Get-ArchiveKeys $Password $salt;$mac=Get-BackupMac $InputPath ([byte[]]$keys[32..63]) ($input.Length-32)
  [void]$input.Seek(-32,[IO.SeekOrigin]::End);$stored=New-Object byte[] 32;[void]$input.Read($stored,0,32);$diff=0;for($i=0;$i -lt 32;$i++){$diff=$diff -bor ($stored[$i] -bxor $mac[$i])}
  if($diff -ne 0){throw 'Wrong recovery password or damaged backup. Nothing has been restored.'}
  $aes=[Security.Cryptography.Aes]::Create();$aes.Key=[byte[]]$keys[0..31];$aes.IV=$iv;$output=[IO.File]::Create($OutputPath)
  try {$crypto=New-Object Security.Cryptography.CryptoStream($output,$aes.CreateDecryptor(),[Security.Cryptography.CryptoStreamMode]::Write);try {[void]$input.Seek(40,[IO.SeekOrigin]::Begin);$left=$input.Length-72;$buffer=New-Object byte[] 65536;while($left -gt 0){$n=$input.Read($buffer,0,[int][Math]::Min($left,$buffer.Length));if(!$n){throw 'Truncated backup.'};$crypto.Write($buffer,0,$n);$left-=$n};$crypto.FlushFinalBlock()} finally {$crypto.Dispose()}} finally {$output.Dispose();$aes.Dispose()}
 } finally {$input.Dispose();if($keys){[Array]::Clear($keys,0,$keys.Length)}}
}
function Expand-SafeZip($Path,$Destination) {
 Add-Type -AssemblyName System.IO.Compression.FileSystem
 $base=[IO.Path]::GetFullPath($Destination)+[IO.Path]::DirectorySeparatorChar;[void][IO.Directory]::CreateDirectory($Destination);$zip=[IO.Compression.ZipFile]::OpenRead($Path)
 try {foreach($entry in $zip.Entries){$target=[IO.Path]::GetFullPath((Join-Path $Destination $entry.FullName));if(!$target.StartsWith($base,[StringComparison]::OrdinalIgnoreCase)){throw 'Unsafe archive path.'};if($entry.Name -eq ''){[void][IO.Directory]::CreateDirectory($target)}else{[void][IO.Directory]::CreateDirectory((Split-Path $target));[IO.Compression.ZipFileExtensions]::ExtractToFile($entry,$target,$true)}}} finally {$zip.Dispose()}
}
function Remove-Staging($Path) {
 $full=[IO.Path]::GetFullPath($Path);$base=[IO.Path]::GetFullPath($script:State)+[IO.Path]::DirectorySeparatorChar
 if(!$full.StartsWith($base,[StringComparison]::OrdinalIgnoreCase)){throw 'Refusing to clean outside laptop state directory.'}
 if(Test-Path -LiteralPath $full){Remove-Item -LiteralPath $full -Recurse -Force}
}
