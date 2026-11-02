<?php
declare(strict_types=1);
namespace Alba;
final class Auth {
 public static function phoneVerificationRequired():bool{return (App::$config['sms_backend']??'disabled')!=='disabled';}
 public static function phoneAccepted(array $account):bool{return !self::phoneVerificationRequired()||!empty($account['verified']);}
 public static ?array $session=null;
 public static ?string $cookie=null;
 public static function start():void{
  $token=$_COOKIE['alba_session']??'';
  if(is_string($token)&&preg_match('/^[a-f0-9]{64}$/D',$token)){
   self::$session=App::one('SELECT * FROM sessions WHERE token_hash=? AND expires_at>?',[hash_hmac('sha256',$token,App::$config['app_key']),App::now()]);
  }
  if(!self::$session){self::renew(null,[]);}
 }
 public static function renew(?array $user,array $context=[]):void{
  if(self::$session){App::run('DELETE FROM sessions WHERE token_hash=?',[self::$session['token_hash']]);}
  $token=bin2hex(random_bytes(32));
  self::$session=['token_hash'=>hash_hmac('sha256',$token,App::$config['app_key']),'account_id'=>$user['id']??null,'version'=>$user['session_version']??1,
   'csrf'=>bin2hex(random_bytes(32)),'context_json'=>json_encode($context),'expires_at'=>App::after(43200)];
  App::insert('sessions',self::$session);
  self::$cookie=$token;
  setcookie('alba_session',$token,['expires'=>time()+43200,'path'=>'/','secure'=>App::$config['environment']!=='development','httponly'=>true,'samesite'=>'Lax']);
 }
 public static function user(bool $required=true,bool $verified=true):?array{
  $user=self::$session&&self::$session['account_id']?App::one('SELECT id,phone,verified,active,is_admin,session_version,marketing,created_at FROM accounts WHERE id=?',[self::$session['account_id']]):null;
  if($user&&(!$user['active']||(int)$user['session_version']!==(int)self::$session['version'])){$user=null;}
  if(!$user&&$required){App::fail('AUTH_REQUIRED','Autentificați-vă pentru a continua.',401);}
  if($user&&$verified&&!self::phoneAccepted($user)){App::fail('PHONE_UNVERIFIED','Verificați telefonul pentru a continua.',403);}
  return $user;
 }
 public static function context(?array $value=null):array{
  if($value!==null){self::$session['context_json']=json_encode($value);App::run('UPDATE sessions SET context_json=? WHERE token_hash=?',[self::$session['context_json'],self::$session['token_hash']]);}
  return json_decode(self::$session['context_json'],true);
 }
 public static function csrf():void{
  $provided=$_SERVER['HTTP_X_CSRF_TOKEN']??$_POST['csrf']??'';
  if(!is_string($provided)||!hash_equals(self::$session['csrf']??'', $provided)){App::fail('CSRF','Pagina a expirat. Reîncărcați înainte de a continua.',403);}
 }
 public static function password(string $password):void{
  if(strlen($password)<10||strlen($password)>72||in_array(strtolower($password),['password123','1234567890','qwerty12345'],true)){
   App::fail('WEAK_PASSWORD','Parola trebuie să aibă între 10 și 72 de caractere și să nu fie una comună.');
  }
 }
 public static function create(string $phone,string $password,bool $terms,bool $marketing=false,string $fullName='',string $dateOfBirth=''):array{
  $phone=App::phone($phone);self::password($password);
  $fullName=trim($fullName);$dateOfBirth=trim($dateOfBirth);
  if(mb_strlen($fullName)>160){App::fail('INVALID_NAME','Numele complet poate avea maximum 160 de caractere.');}
  if($dateOfBirth!==''){
   $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$dateOfBirth,new \DateTimeZone('Europe/Bucharest'));
   $today=(new \DateTimeImmutable('now',new \DateTimeZone('Europe/Bucharest')))->format('Y-m-d');
   if(!$date||$date->format('Y-m-d')!==$dateOfBirth||$dateOfBirth<'1000-01-01'||$dateOfBirth>$today){App::fail('INVALID_BIRTH_DATE','Introduceți o dată de naștere validă, care nu este în viitor.');}
  }
  if(!$terms){App::fail('TERMS_REQUIRED','Acceptați termenii programului.');}
  if(App::one('SELECT id FROM accounts WHERE phone=?',[$phone])){App::fail('REGISTRATION_UNAVAILABLE','Înscriere indisponibilă. Încercați autentificarea sau recuperarea parolei.',409);}
  return App::tx(function()use($phone,$password,$marketing,$fullName,$dateOfBirth){
   $id=App::insert('accounts',['phone'=>$phone,'password_hash'=>password_hash($password,PASSWORD_DEFAULT),'terms_at'=>App::now(),'marketing'=>(int)$marketing,'created_at'=>App::now()]);
   if($fullName!==''||$dateOfBirth!==''){App::insert('account_profiles',['account_id'=>$id,'full_name'=>$fullName!==''?$fullName:null,'date_of_birth'=>$dateOfBirth!==''?$dateOfBirth:null]);}
   App::insert('wallets',['passenger_id'=>$id,'balance'=>0]);App::audit($id,'account.registered',$id);
   return App::one('SELECT * FROM accounts WHERE id=?',[$id]);
  });
 }
 public static function throttle(string $key,int $limit=10,int $seconds=900):void{
  $allowed=App::tx(function()use($key,$limit,$seconds){
   $key=hash('sha256',$key);App::run('INSERT IGNORE INTO throttles(throttle_key,attempts,started_at) VALUES(?,0,?)',[$key,App::now()]);
   $row=App::one('SELECT * FROM throttles WHERE throttle_key=? FOR UPDATE',[$key]);
   if(strtotime($row['started_at'].' UTC')<time()-$seconds){$row['attempts']=0;$row['started_at']=App::now();}
   $allowed=$row['attempts']<$limit;
   App::run('UPDATE throttles SET attempts=?,started_at=? WHERE throttle_key=?',[(int)$row['attempts']+1,$row['started_at'],$key]);return $allowed;
  });
  if(!$allowed){App::fail('RATE_LIMIT','Prea multe încercări. Reîncercați mai târziu.',429);}
 }
 public static function login(string $phone,string $password,string $ip):array{
  $phone=App::phone($phone);self::throttle('login-ip:'.$ip,40);self::throttle('login-phone:'.$phone);
  $u=App::one('SELECT * FROM accounts WHERE phone=? AND active=1',[$phone]);
  // A fixed valid bcrypt hash avoids an early return for unknown accounts.
  $hash=$u['password_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
  if(!password_verify($password,$hash)||!$u){App::fail('INVALID_LOGIN','Telefon sau parolă incorectă.');}
  if(password_needs_rehash($u['password_hash'],PASSWORD_DEFAULT)){App::run('UPDATE accounts SET password_hash=? WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$u['id']]);}
  App::audit((int)$u['id'],'account.login',$u['id']);return $u;
 }
 public static function sms(string $phone,string $message):void{
  if(App::$config['sms_backend']==='database'&&App::$config['environment']==='development'){
   App::insert('dev_messages',['phone'=>$phone,'message'=>$message,'created_at'=>App::now()]);return;
  }
  if(App::$config['sms_backend']==='smslink'){SmsLink::send($phone,$message);return;}
  if(App::$config['sms_backend']!=='http'||!str_starts_with(App::$config['sms_url'],'https://')){App::fail('SMS_UNAVAILABLE','Serviciul SMS nu este configurat. Contactați suportul.',503);}
  $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\nAuthorization: Bearer ".App::$config['sms_token']."\r\n",'content'=>json_encode(['to'=>$phone,'message'=>$message]),'timeout'=>10,'ignore_errors'=>true,'follow_location'=>0]]);
  $result=@file_get_contents(App::$config['sms_url'],false,$ctx);
  if($result===false||!preg_match('/\s2\d\d\s/',$http_response_header[0]??'')){App::fail('SMS_UNAVAILABLE','SMS indisponibil temporar. Reîncercați.',503);}
 }
 public static function requestOtp(string $phone,string $purpose,string $ip):void{
  if(!self::phoneVerificationRequired()){App::fail('SMS_DISABLED','Confirmarea prin SMS nu este activată.',409);}
  $phone=App::phone($phone);if(!in_array($purpose,['verify','recover'],true)){App::fail('INVALID_PURPOSE','Operațiune invalidă.');}
  self::throttle('otp-ip:'.$ip,20);self::throttle('otp-phone:'.$phone,3);
  if(!App::one('SELECT id FROM accounts WHERE phone=? AND active=1',[$phone])){return;}
  $code=str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);
  self::sms($phone,'AlbaTaxi Bonus: cod '.$code.'. Expira in 5 minute. Nu il comunicati soferului.');
  App::tx(function()use($phone,$purpose,$code){
   App::one('SELECT id FROM accounts WHERE phone=? FOR UPDATE',[$phone]);
   App::run('UPDATE otps SET used=1 WHERE phone=? AND purpose=? AND used=0',[$phone,$purpose]);
   App::insert('otps',['phone'=>$phone,'purpose'=>$purpose,'digest'=>password_hash($code,PASSWORD_DEFAULT),'expires_at'=>App::after(300),'created_at'=>App::now()]);
  });
 }
 public static function consumeOtp(string $phone,string $purpose,string $code,?string $password=null):void{
  if(!self::phoneVerificationRequired()){App::fail('SMS_DISABLED','Confirmarea prin SMS nu este activată.',409);}
  $phone=App::phone($phone);if(!in_array($purpose,['verify','recover'],true)){App::fail('INVALID_PURPOSE','Operațiune invalidă.');}
  if($purpose==='recover'){self::password($password??'');}
  $ok=App::tx(function()use($phone,$purpose,$code,$password){
   $user=App::one('SELECT * FROM accounts WHERE phone=? FOR UPDATE',[$phone]);
   $otp=App::one('SELECT * FROM otps WHERE phone=? AND purpose=? AND used=0 ORDER BY id DESC LIMIT 1 FOR UPDATE',[$phone,$purpose]);
   if(!$user||!$otp||$otp['expires_at']<=App::now()||$otp['attempts']>=5){return false;}
   $ok=password_verify($code,$otp['digest']);
   App::run('UPDATE otps SET attempts=attempts+1,used=? WHERE id=?',[(int)$ok,$otp['id']]);
   if($ok){
    App::run('UPDATE accounts SET verified=1 WHERE id=?',[$user['id']]);
    if($purpose==='recover'){App::run('UPDATE accounts SET password_hash=?,session_version=session_version+1 WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$user['id']]);App::run('DELETE FROM sessions WHERE account_id=?',[$user['id']]);}
    App::audit((int)$user['id'],'account.'.$purpose,$user['id']);
   }return $ok;
  });
  if(!$ok){App::fail('INVALID_OTP','Cod invalid, expirat sau deja folosit.');}
 }
}
