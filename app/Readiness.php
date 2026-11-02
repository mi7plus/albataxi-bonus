<?php
declare(strict_types=1);
namespace Alba;

final class Readiness {
 public const CHECKS=[
  'hosting'=>'Verificare pe planul de găzduire ales',
  'sms'=>'Livrare și recuperare prin SMS real',
  'phone_portrait'=>'Telefon de la 320 px · portret și tastatură',
  'tablet_rotation'=>'Tabletă · peisaj și rotire fără pierdere de date',
  'accessibility'=>'Tastatură și cititor de ecran',
  'printed_qr'=>'Scanare QR tipărit și afișat pe dispozitive reale',
  'restore'=>'Restaurare și migrare pe mediul de destinație',
  'capacity'=>'Capacitate măsurată pe planul ales',
  'documents'=>'Termeni, fond, confidențialitate și suport aprobate',
 ];
 public static function diagnostics(): array {
  $extensions=['pdo_mysql','mbstring','dom','gd','zip','xmlreader','xmlwriter','simplexml','fileinfo','openssl'];
  $result=[['label'=>'Versiune PHP','ok'=>version_compare(PHP_VERSION,'8.2','>='),'detail'=>PHP_VERSION]];
  foreach($extensions as $ext){$result[]=['label'=>'Extensia '.$ext,'ok'=>extension_loaded($ext),'detail'=>extension_loaded($ext)?'Disponibilă':'Lipsește'];}
  $result[]=['label'=>'Bază de date','ok'=>true,'detail'=>(string)App::scalar('SELECT VERSION()')];
  $https=parse_url(App::$config['base_url'],PHP_URL_SCHEME)==='https';
  $result[]=['label'=>'Domeniu HTTPS configurat','ok'=>$https,'detail'=>$https?'Verificați certificatul în browserul de producție.':'Adresa curentă folosește HTTP. Acceptabil doar pentru testarea locală.'];
  $production=App::$config['environment']!=='development';
  $result[]=['label'=>'Mod de producție','ok'=>$production,'detail'=>$production?'Cookie-uri Secure activate.':'Mediu local de dezvoltare.'];
  $sms=(App::$config['sms_backend']==='smslink'&&SmsLink::configured())||(App::$config['sms_backend']==='http'&&str_starts_with(App::$config['sms_url'],'https://')&&App::$config['sms_token']!=='');
  $result[]=['label'=>'SMS real configurat','ok'=>$sms,'detail'=>$sms?'Gateway configurat; livrarea trebuie verificată separat.':'Niciun gateway SMS real verificat.'];
  $result[]=['label'=>'Acces HTTPS extern pentru SMS','ok'=>(bool)ini_get('allow_url_fopen'),'detail'=>ini_get('allow_url_fopen')?'Disponibil în PHP; verificați accesul permis de furnizor.':'Activați accesul extern sau configurați un adaptor cURL.'];
  return $result;
 }
 public static function record(array $u,array $d): array {
  App::admin($u);$key=(string)($d['check']??'');$status=(string)($d['status']??'');
  if(!isset(self::CHECKS[$key])||!in_array($status,['pending','passed','failed'],true)||mb_strlen(trim((string)($d['notes']??'')))<5){App::fail('EVIDENCE_REQUIRED','Alegeți verificarea și notați rezultatul, mediul și dovada (minimum 5 caractere).');}
  return App::tx(function()use($u,$d,$key,$status){
   App::run('INSERT INTO acceptance_checks(check_key,status,notes,actor_id,checked_at) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),notes=VALUES(notes),actor_id=VALUES(actor_id),checked_at=VALUES(checked_at)',[$key,$status,mb_substr($d['notes'],0,5000),$u['id'],App::now()]);
   App::audit((int)$u['id'],'readiness.recorded',$key,['status'=>$status,'notes'=>mb_substr($d['notes'],0,5000)]);
   return ['ok'=>true];
  });
 }
 public static function sms(array $u,array $d,string $key): array {
  App::admin($u);$phone=App::phone((string)($d['phone']??''));
  Auth::throttle('readiness-sms:'.$u['id'],3,3600);
  // Explicit user action only; no invitation upload or diagnostic page sends messages.
  return Loyalty::operation($u,'test-sms',$key,['phone'=>$phone],function()use($u,$phone){
   Auth::sms($phone,'AlbaTaxi Bonus: mesaj de test solicitat de administrator. Nu este un cod de autentificare.');
   App::audit((int)$u['id'],'readiness.sms_requested','test',['suffix'=>substr($phone,-4)]);
   return ['message'=>'Mesajul a fost acceptat de adaptor. Confirmați primirea pe telefon și înregistrați dovada.'];
  });
 }
}
