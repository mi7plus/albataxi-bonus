<?php
declare(strict_types=1);
namespace Alba;

final class SmsLink {
 public const ENDPOINT='https://secure.smslink.ro/sms/gateway/communicate/index.php';
 public static function configured():bool{
  return trim((string)(App::$config['smslink_connection_id']??''))!==''&&trim((string)(App::$config['smslink_password']??''))!=='';
 }
 /** Transport override is for isolated protocol tests; never accepted from HTTP input. */
 public static function send(string $phone,string $message,?callable $transport=null):void{
  if(!self::configured()){App::fail('SMS_UNAVAILABLE','Serviciul SMSLink nu este configurat. Contactați suportul.',503);}
  $phone=App::phone($phone);
  $fields=['connection_id'=>App::$config['smslink_connection_id'],'password'=>App::$config['smslink_password'],'to'=>'00'.substr($phone,1),'message'=>$message];
  $sender=trim((string)(App::$config['smslink_sender']??''));
  if($sender!==''){$fields['sender']=$sender;}
  $body=http_build_query($fields,'','&',PHP_QUERY_RFC3986);
  [$status,$result]=$transport?$transport(self::ENDPOINT,$body):self::request($body);
  $parts=explode(';',trim((string)$result),4);
  $id=explode(',',$parts[3]??'')[0];
  // A 2xx can contain ERROR, a balance response, or malformed content.
  if($status<200||$status>=300||($parts[0]??'')!=='MESSAGE'||($parts[1]??'')!=='1'||!preg_match('/^[1-9][0-9]*$/D',$id)){
   App::fail('SMS_UNAVAILABLE','SMS indisponibil temporar. Reîncercați mai târziu.',503);
  }
 }
 private static function request(string $body):array{
  $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/x-www-form-urlencoded\r\n",'content'=>$body,'timeout'=>10,'ignore_errors'=>true,'follow_location'=>0],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
  // Do not log credentials, recipient, response body or OTP. No automatic retries:
  // a timeout can occur after the provider has already accepted the message.
  $result=@file_get_contents(self::ENDPOINT,false,$ctx,0,8192);
  preg_match('/^HTTP\/\S+\s+(\d{3})\b/',$http_response_header[0]??'',$match);
  return [(int)($match[1]??0),$result===false?'':$result];
 }
}
