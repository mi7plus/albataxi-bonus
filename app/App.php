<?php
declare(strict_types=1);
namespace Alba;
final class App{
 public static array $config;
 private static ?\PDO $pdo=null;
 public static ?\Closure $queryObserver=null;
 public static function boot(array $config):void{self::$config=$config;}
 public static function db():\PDO{
  if(!self::$pdo){
   self::$pdo=new \PDO(self::$config['db_dsn'],self::$config['db_user'],self::$config['db_password'],[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC,\PDO::ATTR_EMULATE_PREPARES=>false]);
   self::$pdo->exec("SET time_zone = '+00:00'");
   self::$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
   self::$pdo->exec('SET SESSION innodb_lock_wait_timeout=5');
  }return self::$pdo;
 }
 public static function run(string $sql,array $p=[]):\PDOStatement{if(self::$queryObserver){(self::$queryObserver)($sql);}$q=self::db()->prepare($sql);$q->execute($p);return $q;}
 public static function one(string $sql,array $p=[]):?array{return self::run($sql,$p)->fetch()?:null;}
 public static function all(string $sql,array $p=[]):array{return self::run($sql,$p)->fetchAll();}
 public static function scalar(string $sql,array $p=[]):mixed{return self::run($sql,$p)->fetchColumn();}
 public static function insert(string $table,array $data):int{
  $columns=implode(',',array_map(fn($v)=>'`'.$v.'`',array_keys($data)));
  self::run('INSERT INTO `'.$table.'` ('.$columns.') VALUES ('.implode(',',array_fill(0,count($data),'?')).')',array_values($data));return (int)self::db()->lastInsertId();
 }
 public static function tx(callable $work):mixed{
  if(self::db()->inTransaction()){return $work();}
  for($attempt=0;$attempt<3;$attempt++){
   self::db()->beginTransaction();
   try{$result=$work();self::db()->commit();return $result;}
   catch(\Throwable $e){if(self::db()->inTransaction()){self::db()->rollBack();}if($e instanceof \PDOException&&in_array((int)($e->errorInfo[1]??0),[1205,1213],true)&&$attempt<2){usleep(random_int(20000,80000));continue;}throw $e;}
  }throw new Problem('BUSY','Operațiune concurentă. Verificați rezultatul înainte de reluare.',409);
 }
 public static function now():string{return gmdate('Y-m-d H:i:s');}
 public static function after(int $s):string{return gmdate('Y-m-d H:i:s',time()+$s);}
 public static function token():string{return bin2hex(random_bytes(20));}
 public static function id():string{return bin2hex(random_bytes(16));}
 public static function url(string $path=''):string{return rtrim(self::$config['base_url'],'/').'/'.ltrim($path,'/');}
 public static function audit(?int $actor,string $action,string|int $target,array $detail=[]):void{self::tx(function()use($actor,$action,$target,$detail){self::one('SELECT id FROM programme WHERE id=1 LOCK IN SHARE MODE');self::insert('audit_events',['actor_id'=>$actor,'action'=>$action,'target'=>(string)$target,'detail'=>json_encode($detail,JSON_THROW_ON_ERROR),'created_at'=>self::now()]);});}
 public static function fail(string $code,string $message,int $status=400):never{throw new Problem($code,$message,$status);}
 public static function e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
 public static function ron(mixed $v):string{return number_format((int)$v/100,2,',',' ');}
 public static function local(?string $v):string{return $v?(new \DateTimeImmutable($v,new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Bucharest'))->format('d.m.Y H:i'):'—';}
 public static function money(mixed $input):int{
  if(!is_string($input)&&!is_int($input)){self::fail('INVALID_AMOUNT','Introduceți suma ca număr zecimal, de exemplu 20,00.');}
  $v=trim(str_replace(',','.',(string)$input));if(!preg_match('/^\\d{1,7}(?:\\.\\d{1,2})?$/D',$v)){self::fail('INVALID_AMOUNT','Suma trebuie să fie pozitivă, cu maximum două zecimale.');}
  [$whole,$fraction]=array_pad(explode('.',$v,2),2,'');return (int)$whole*100+(int)str_pad($fraction,2,'0');
 }
 public static function phone(string $v):string{
  $v=preg_replace('/[\\s().-]/','',$v);if(str_starts_with($v,'00')){$v='+'.substr($v,2);}
  if(preg_match('/^07\\d{8}$/D',$v)){$v='+4'.$v;}
  elseif(preg_match('/^7\\d{8}$/D',$v)){$v='+40'.$v;}
  elseif(preg_match('/^407\\d{8}$/D',$v)){$v='+'.$v;}
  if(!preg_match('/^\\+[1-9]\\d{7,14}$/D',$v)){self::fail('INVALID_PHONE','Introduceți un telefon valid, de exemplu 0722123456, 722123456 sau +40722123456.');}return $v;
 }
 public static function masked(string $v):string{return '••• '.substr($v,-4);}
 public static function admin(array $u):void{if(!$u['is_admin']||!$u['active']){self::fail('FORBIDDEN','Este necesar accesul de administrator.',403);}}
 public static function fields(array $d,array $fs):void{foreach($fs as $f){if(!isset($d[$f])||!is_scalar($d[$f])||trim((string)$d[$f])===''){self::fail('INVALID_INPUT','Completați câmpul '.$f.'.');}}}
 public static function bool(mixed $v):bool{return in_array($v,[true,1,'1','on','true'],true);}
}
