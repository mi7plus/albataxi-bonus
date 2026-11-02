<?php
declare(strict_types=1);
namespace Alba;

/**
 * A signed committed frontier, not a saved file or a background export.
 * Finance operations/audit writers hold a shared programme lock. Capturing an
 * exclusive lock waits for their commits before reading monotonic entry IDs.
 * This avoids admitting late commits below a previously captured high-water ID.
 */
final class ReportSnapshot {
 private static function encode(string $value): string { return rtrim(strtr(base64_encode($value),'+/','-_'),'='); }
 public static function resolve(array $u,array $filters,string $token=''): array {
  $scope=Scope::signature($u);$hash=hash('sha256',json_encode($filters,JSON_THROW_ON_ERROR));
  if($token!==''){
   $parts=explode('.',$token);
   if(count($parts)!==2||!hash_equals(hash_hmac('sha256',$parts[0],App::$config['app_key']),$parts[1])){App::fail('INVALID_SNAPSHOT','Momentul raportului nu este valid. Previzualizați din nou.',409);}
   $payload=base64_decode(strtr($parts[0],'-_','+/'),true);$s=$payload===false?null:json_decode($payload,true);
   if(!is_array($s)||($s['owner']??0)!==(int)$u['id']||($s['filters']??'')!==$hash||($s['scope']??'')!==$scope){App::fail('SNAPSHOT_SCOPE_CHANGED','Filtrele sau permisiunile s-au schimbat. Previzualizați din nou.',409);}
   if(($s['expires']??0)<time()){App::fail('SNAPSHOT_EXPIRED','Raportul a expirat. Generați o previzualizare nouă.',409);}
   return $s+['token'=>$token];
  }
  $s=App::tx(function()use($u,$hash,$scope){
   App::one('SELECT id FROM programme WHERE id=1 FOR UPDATE');
   return ['owner'=>(int)$u['id'],'scope'=>$scope,'filters'=>$hash,'created'=>App::now(),'expires'=>time()+3600,
    'ledger'=>(int)App::scalar('SELECT COALESCE(MAX(id),0) FROM ledger'),
    'fund'=>(int)App::scalar('SELECT COALESCE(MAX(id),0) FROM fund_entries'),
    'audit'=>(int)App::scalar('SELECT COALESCE(MAX(id),0) FROM audit_events')];
  });
  $payload=self::encode(json_encode($s,JSON_THROW_ON_ERROR));
  return $s+['token'=>$payload.'.'.hash_hmac('sha256',$payload,App::$config['app_key'])];
 }
}
