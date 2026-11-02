param([switch]$Automatic)
. "$PSScriptRoot\Common.ps1"
try {
 $settings=Read-Settings;$paused=Join-Path $script:State 'paused'
 if($Automatic -and (Test-Path -LiteralPath $paused)){Write-Host 'Website deliberately stopped. Use Start.cmd to resume.';exit 0}
 Wait-Docker;Invoke-Compose up -d --wait --wait-timeout 240
 if(Test-Path -LiteralPath $paused){Remove-Item -LiteralPath $paused}
 Write-Host ('Website services started: https://'+$settings.Domain+'. Verify public HTTPS before sharing the address.')
 & "$PSScriptRoot\Backup.ps1" -IfDue
 if($LASTEXITCODE -ne 0){Write-Warning 'Website is running, but backup failed. Connect the external drive and use Backup.cmd.'}
 exit 0
} catch {if(Test-Path -LiteralPath $script:State){Write-Utf8 (Join-Path $script:State 'start-error.txt') ([DateTime]::UtcNow.ToString('o')+' '+$_.Exception.Message)};Write-Error $_ -ErrorAction Continue;exit 1}
