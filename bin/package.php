<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__);
if(!is_file($root.'/vendor/autoload.php')){throw new RuntimeException('Install locked dependencies first, or run this command inside the web container.');}
$dir=$root.'/dist';if(!is_dir($dir)){mkdir($dir,0755,true);}
$path=$dir.'/albataxi-shared-hosting.zip';$zip=new ZipArchive();
if($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true){throw new RuntimeException('Cannot create package.');}
$roots=['app','views','public','vendor','database','docs'];
$count=0;
foreach($roots as $folder){
 $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$folder,FilesystemIterator::SKIP_DOTS));
 foreach($iterator as $file){
  if(!$file->isFile()){continue;}$relative=str_replace('\\','/',substr($file->getPathname(),strlen($root)+1));
  if($relative==='public/app-path.php'||str_starts_with($relative,'vendor/vendor/')){continue;}
  $zip->addFile($file->getPathname(),$relative);$count++;
 }
}
foreach(['README.md','config.example.php','composer.json','composer.lock','VERSION','bin/console.php','bin/admin-sql.php','bin/snapshot.php'] as $relative){$zip->addFile($root.'/'.$relative,$relative);$count++;}
$zip->close();$hash=hash_file('sha256',$path);file_put_contents($dir.'/SHA256SUMS',$hash.'  '.basename($path)."\n");
echo basename($path)." | $count files | ".filesize($path)." bytes\nSHA256 $hash\n";

