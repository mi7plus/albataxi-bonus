. "$PSScriptRoot\Common.ps1"
try {Read-Settings|Out-Null;Write-Utf8 (Join-Path $script:State 'paused') 'Deliberately stopped';Wait-Docker;Invoke-Compose stop -t 60 proxy web;Write-Host 'Website stopped. Database and daily backups remain available. Use Start.cmd to resume.';exit 0} catch {Write-Error $_ -ErrorAction Continue;exit 1}
