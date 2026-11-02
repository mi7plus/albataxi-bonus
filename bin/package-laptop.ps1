$ErrorActionPreference='Stop'
$root=Split-Path $PSScriptRoot -Parent;$dist=Join-Path $root 'dist';[void][IO.Directory]::CreateDirectory($dist)
$stage=Join-Path $dist ('laptop-package-'+[Guid]::NewGuid().ToString('N'));[void][IO.Directory]::CreateDirectory($stage)
try {
 foreach($entry in @('app','bin','database','docs','public','laptop','Dockerfile','compose.laptop.yaml','composer.json','composer.lock','VERSION','README.md','.dockerignore','.gitignore','Laptop-Install.cmd')){Copy-Item -LiteralPath (Join-Path $root $entry) -Destination $stage -Recurse}
 foreach($file in @('config.local.php','public/app-path.php')){$path=Join-Path $stage $file;if(Test-Path -LiteralPath $path){Remove-Item -LiteralPath $path -Force}}
 $output=Join-Path $dist 'albataxi-laptop.zip';if(Test-Path -LiteralPath $output){Remove-Item -LiteralPath $output -Force}
 Add-Type -AssemblyName System.IO.Compression.FileSystem;[IO.Compression.ZipFile]::CreateFromDirectory($stage,$output)
 $hash=(Get-FileHash -LiteralPath $output -Algorithm SHA256).Hash.ToLowerInvariant();[IO.File]::WriteAllText((Join-Path $dist 'LAPTOP-SHA256SUMS'),$hash+'  albataxi-laptop.zip'+"`n")
 Write-Host ('Created '+$output+'; SHA256 '+$hash)
}finally{
 $resolved=[IO.Path]::GetFullPath($stage);$base=[IO.Path]::GetFullPath($dist)+[IO.Path]::DirectorySeparatorChar
 if($resolved.StartsWith($base,[StringComparison]::OrdinalIgnoreCase) -and (Test-Path -LiteralPath $resolved)){Remove-Item -LiteralPath $resolved -Recurse -Force}
}
