<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Alba\App;use Alba\SmsLink;use Alba\Problem;
$checks=0;
function verify(bool $ok,string $label):void{global $checks;if(!$ok){throw new RuntimeException($label);}echo "PASS $label\n";$checks++;}
App::boot(['smslink_connection_id'=>'test-id','smslink_password'=>'test&secret=+','smslink_sender'=>'']);
$captured=[];
$transport=function($url,$body)use(&$captured){parse_str($body,$captured);verify($url===SmsLink::ENDPOINT,'fixed HTTPS endpoint');return [200,'MESSAGE;1;Accepted;123,0040722123456,numeric'];};
SmsLink::send('+40722123456','Test + & =',$transport);
verify($captured['to']==='0040722123456','international phone format');
verify($captured['password']==='test&secret=+'&&$captured['message']==='Test + & =','form encoding preserves credentials and content');
verify(!isset($captured['sender']),'default sender omitted');
App::$config['smslink_sender']='AlbaTaxi';
SmsLink::send('0722 123 456','Test',$transport);
verify($captured['sender']==='AlbaTaxi','configured approved sender');
foreach([[200,'ERROR;10;Invalid password'],[200,'MESSAGE;2;Balance;123,0'],[200,'MESSAGE;1;Accepted;'],[200,'<html>Error</html>'],[503,'MESSAGE;1;Accepted;123'],[302,'MESSAGE;1;Accepted;123'],[0,'']] as $response){
 try{SmsLink::send('+40722123456','Test',fn()=> $response);throw new RuntimeException('Unexpected success');}
 catch(Problem $e){verify($e->error==='SMS_UNAVAILABLE'&&!str_contains($e->getMessage(),'test&secret'),'provider failure rejected without leaking secrets');}
}
App::$config['smslink_password']='';
verify(!SmsLink::configured(),'missing credentials not ready');
try{SmsLink::send('+40722123456','Test',function(){throw new RuntimeException('Must not send');});throw new RuntimeException('Unexpected success');}
catch(Problem $e){verify($e->error==='SMS_UNAVAILABLE','missing credentials fail before network');}
echo "$checks SMSLink checks passed; no external messages sent.\n";
