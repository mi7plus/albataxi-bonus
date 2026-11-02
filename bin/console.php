<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
use Alba\App;use Alba\Auth;
$command=$argv[1]??'help';
try{
 if($command==='migrate'){
  App::run('CREATE TABLE IF NOT EXISTS schema_migrations(version VARCHAR(100) PRIMARY KEY, applied_at DATETIME NOT NULL) ENGINE=InnoDB');
  foreach(glob(dirname(__DIR__).'/database/*.sql') as $file){
   $name=basename($file);if(App::scalar('SELECT version FROM schema_migrations WHERE version=?',[$name])){continue;}
   foreach(explode(';',file_get_contents($file)) as $sql){if(trim($sql)!==''){App::db()->exec($sql);}}
   App::insert('schema_migrations',['version'=>$name,'applied_at'=>App::now()]);echo "Applied $name\n";
  }
 }elseif($command==='admin-count'){
  echo (int)App::scalar('SELECT COUNT(*) FROM accounts WHERE is_admin=1')."\n";
 }elseif($command==='admin'){
  $phone=App::phone($argv[2]??'');$password=getenv('ALBA_ADMIN_PASSWORD')?:'';
  Auth::password($password);$user=Auth::create($phone,$password,true);
  App::run('UPDATE accounts SET is_admin=1,verified=1 WHERE id=?',[$user['id']]);
  App::audit((int)$user['id'],'admin.bootstrapped',$user['id']);echo "Administrator created. Sign in with the supplied phone and password.\n";
 }elseif($command==='reconcile'){
  $report=Alba\Loyalty::reconcile();echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";exit($report['differences']?1:0);
 }elseif($command==='doctor'){
  $needed=['pdo_mysql','mbstring','dom','gd','zip','xml','xmlreader','xmlwriter','simplexml','fileinfo'];
  $ok=true;foreach($needed as $ext){$has=extension_loaded($ext);$ok=$ok&&$has;echo ($has?'OK ':'MISSING ').$ext."\n";}
  echo 'PHP '.PHP_VERSION."\nDatabase ".App::scalar('SELECT VERSION()')."\n";exit($ok?0:1);
 }elseif($command==='dev-sms'){
  if(App::$config['environment']!=='development'){throw new RuntimeException('Development only.');}
  foreach(App::all('SELECT phone,message FROM dev_messages ORDER BY id DESC LIMIT 10') as $row){echo $row['phone'].' '.$row['message']."\n";}
 }else{echo "Commands: migrate | admin +407... (ALBA_ADMIN_PASSWORD env) | reconcile | doctor | dev-sms\n";}
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}

