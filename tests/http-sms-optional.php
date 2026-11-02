<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
require __DIR__.'/http-browser.php';
use Alba\App;
if(App::$config['environment']!=='development'||App::$config['sms_backend']!=='disabled'){throw new RuntimeException('Isolated no-SMS development fixture only.');}
$base=$argv[1]??'http://albataxi-no-sms-test/';
if($base!=='http://albataxi-no-sms-test/'){throw new RuntimeException('Use the isolated local no-SMS server.');}
$checks=0;
function verifyOptional(bool $condition,string $label):void{global $checks;if(!$condition){throw new RuntimeException($label);}echo "PASS $label\n";$checks++;}
$phone='+407'.random_int(10000000,99999999);$password='No-sms-http-test-password';$client=new Browser($base);
$client->request('?page=register');[$status,$body,$headers]=$client->request('?page=register',['phone'=>substr($phone,3),'password'=>$password,'terms'=>'on']);
verifyOptional($status===303&&!str_contains(implode("\n",$headers),'page=verify'),'registration goes directly to app without SMS');
$user=App::one('SELECT * FROM accounts WHERE phone=?',[$phone]);verifyOptional($user&&!$user['verified'],'registration does not invent verified ownership');
verifyOptional((int)App::scalar('SELECT COUNT(*) FROM dev_messages WHERE phone=?',[$phone])===0,'registration does not send SMS');
[$status,$home]=$client->request('?role=passenger');verifyOptional($status===200&&str_contains($home,'Soldul meu'),'unverified account can use passenger workspace');
[$status,$account]=$client->request('?role=passenger&section=account');verifyOptional($status===200&&str_contains($account,'confirmare SMS neactivată')&&!str_contains($account,'telefon verificat ✓'),'account status is truthful without SMS');
[$status]=$client->request('?page=verify');verifyOptional($status===303,'verify page bypassed in no-SMS mode');
[$status,$recover]=$client->request('?page=recover');verifyOptional($status===200&&str_contains($recover,'Contactează administratorul')&&!str_contains($recover,'name="code"'),'recovery gives assistance instead of unusable SMS form');
[$status]=$client->request('?page=recover',['phone'=>$phone,'password'=>'Unauthorized-changed-password','code'=>'123456','action'=>'submit']);
verifyOptional(password_verify($password,App::scalar('SELECT password_hash FROM accounts WHERE id=?',[$user['id']])),'recovery cannot reset password by phone number alone');
$again=new Browser($base);$again->login('0'.substr($phone,3),$password);[$status]=$again->request('?role=passenger');verifyOptional($status===200,'returning unverified account logs in without SMS');
$qr=App::one("SELECT q.token FROM qr_pages q JOIN memberships m ON m.id=q.membership_id JOIN accounts a ON a.id=m.account_id WHERE a.phone='+40700000003' AND m.ended_at IS NULL");
[$status]=$again->request('?page=scan&token='.$qr['token']);verifyOptional($status===303,'passenger QR scan proceeds without SMS');
$withSms=new Browser('http://web/');$withSms->request('?page=login');[$status,$body,$headers]=$withSms->request('?page=login',['phone'=>$phone,'password'=>$password]);
verifyOptional($status===303&&str_contains(implode("\n",$headers),'page=verify'),'same account needs verification when SMS is enabled');
$withSms->request('?page=verify');
[$status,$body]=$withSms->request('?page=api&action=support',['message'=>'Must not bypass verification'],true);verifyOptional($status===403&&str_contains($body,'PHONE_UNVERIFIED'),'SMS-enabled API rejects unverified session');
echo "$checks no-SMS HTTP checks passed. No external messages sent.\n";
