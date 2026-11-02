<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
if(Alba\App::$config['environment']!=='development'){throw new RuntimeException('HTTP fixtures require a development environment.');}
// HTTP integration checks use only local synthetic demo accounts.
$base=$argv[1]??'http://localhost:8097/';$password=getenv('ALBA_DEMO_PASSWORD')?:'';
if(!preg_match('~^http://(localhost|127\.0\.0\.1|web|albataxi-shared-release-test)(:\d+)?/$~',$base)){throw new RuntimeException('HTTP test target must be local.');}
require __DIR__.'/http-browser.php';
$checks=0;
function assertHttp(bool $ok,string $label):void{global $checks;if(!$ok){throw new RuntimeException('FAILED '.$label);}echo 'PASS '.$label."\n";$checks++;}
if(Alba\App::$config['sms_backend']!=='database'){throw new RuntimeException('Registration HTTP fixtures require the development database SMS backend.');}
$registration=new Browser($base);[$status,$registrationHtml]=$registration->request('?page=register');
assertHttp($status===200&&(bool)preg_match('/name="phone"[^>]*required/',$registrationHtml)&&(bool)preg_match('/name="password"[^>]*required/',$registrationHtml),'registration keeps phone and password required');
foreach(['full_name','date_of_birth'] as $optionalField){preg_match('/<input[^>]*name="'.$optionalField.'"[^>]*>/',$registrationHtml,$optionalMatch);assertHttp(isset($optionalMatch[0])&&!str_contains($optionalMatch[0],'required'),'registration optional field '.$optionalField);}
$registrationPhone='+407'.random_int(10000000,99999999);
[$status,$invalidRegistration]=$registration->request('?page=register',['phone'=>substr($registrationPhone,3),'password'=>$password,'full_name'=>'Nume <Test>','date_of_birth'=>'2023-02-29','terms'=>'on']);
assertHttp($status===200&&str_contains($invalidRegistration,'Nume &lt;Test&gt;')&&str_contains($invalidRegistration,'dată de naștere validă'),'invalid registration preserves and escapes optional fields');
[$status]=$registration->request('?page=register',['phone'=>substr($registrationPhone,3),'password'=>$password,'full_name'=>'Test HTTP','date_of_birth'=>'2000-02-29','terms'=>'on']);
assertHttp($status===303,'registration accepts phone without country prefix');
$registrationAccount=Alba\App::one('SELECT id FROM accounts WHERE phone=?',[$registrationPhone]);$registrationProfile=Alba\App::one('SELECT * FROM account_profiles WHERE account_id=?',[$registrationAccount['id']]);
assertHttp($registrationProfile['full_name']==='Test HTTP'&&$registrationProfile['date_of_birth']==='2000-02-29','optional profile stored through registration form');
Alba\App::run('UPDATE accounts SET verified=1 WHERE id=?',[$registrationAccount['id']]);
foreach([$registrationPhone,'0'.substr($registrationPhone,3),substr($registrationPhone,3)] as $loginFormat){$prefixBrowser=new Browser($base);$prefixBrowser->login($loginFormat,$password);assertHttp(true,'HTTP login accepts phone format '.$loginFormat);}
$minimalRegistration=new Browser($base);$minimalRegistration->request('?page=register');$minimalPhone='+407'.random_int(10000000,99999999);[$status]=$minimalRegistration->request('?page=register',['phone'=>'0'.substr($minimalPhone,3),'password'=>$password,'terms'=>'on']);assertHttp($status===303,'HTTP registration needs no name or birth date');
$admin=new Browser($base);$admin->login('+40700000001',$password);
[$status,$setupHtml]=$admin->request('?role=admin&section=agencies');assertHttp($status===200&&str_contains($setupHtml,'Pașii pentru prima cursă')&&!str_contains($setupHtml,'(bps)'),'agency setup checklist uses human percentages');
[$status,$reportsHtml]=$admin->request('?role=admin&section=reports');assertHttp($status===200&&str_contains($reportsHtml,'data-report-column')&&!str_contains($reportsHtml,'separate prin virgulă'),'report column chooser replaces technical input');
[$status,$readiness]=$admin->request('?role=admin&section=readiness');assertHttp($status===200&&str_contains($readiness,'Dovezi înainte de lansare'),'administrator launch-readiness workspace');
[$status,$overview]=$admin->request('?role=admin');assertHttp($status===200&&str_contains($overview,'Rulează verificarea')&&!str_contains($overview,'Soldurile corespund registrului.'),'reconciliation is on demand rather than implied by page load');
[$status,$result]=$admin->request('?page=api&action=reconcile');assertHttp($status===200&&json_decode($result,true)['differences']===[],'explicit reconciliation endpoint works');
[$status,$verified]=$admin->request('?page=api&action=verify&entity=accounts');assertHttp($status===200&&!str_contains($verified,'password_hash')&&!str_contains($verified,'session_version'),'admin inspection excludes credential material');
foreach(['overview','agencies','drivers','vehicles','fund','history','reports','audit','help','account'] as $section){[$status,$html]=$admin->request('?role=admin&section='.$section);assertHttp($status===200&&!str_contains($html,'Warning:')&&!str_contains($html,'Fatal error'),'admin screen '.$section);}
foreach(['csv'=>'','xlsx'=>"PK",'pdf'=>'%PDF'] as $format=>$signature){
 [$status,$body,$headers]=$admin->request('?page=download&format='.$format.'&start=2000-01-01&end=2099-01-01');
 assertHttp($status===200&&($format==='csv'?str_contains($body,'AlbaTaxi Bonus'):str_starts_with($body,$signature)),'direct '.$format.' export');
 assertHttp((bool)array_filter($headers,fn($h)=>str_starts_with($h,'Content-Disposition: attachment')),'attachment header '.$format);
}
$manager=new Browser($base);$manager->login('+40700000002',$password);
[$status]=$manager->request('?page=api&action=reconcile');assertHttp($status===403,'manager denied global reconciliation');
[$status]=$manager->request('?role=agency&section=readiness');assertHttp($status===403,'manager denied launch administration');
foreach(['overview','drivers','vehicles','fund','history','reports','help','account'] as $section){[$status]=$manager->request('?role=agency&section='.$section);assertHttp($status===200,'manager screen '.$section);}
[$status]=$manager->request('?page=api&action=controls',['earning_paused'=>true],true);assertHttp($status===403,'manager denied programme controls');
[$status]=$manager->request('?page=api&action=report&template=audit');assertHttp($status===403,'manager denied audit export data');
$driver=new Browser($base);$driver->login('+40700000003',$password);
[$status]=$driver->request('?page=api&action=verify&entity=accounts');assertHttp($status===403,'driver denied administrator inspection');
foreach(['overview','qr','history','reports','help','account'] as $section){[$status]=$driver->request('?role=driver&section='.$section);assertHttp($status===200,'driver screen '.$section);}
[$status,$html]=$driver->request('?role=driver&section=qr');preg_match('/page=display&amp;token=([a-f0-9]+)/',$html,$m);$token=$m[1]??'';
assertHttp($token!=='','display-only link exists');
[$status,$editor]=$driver->request('?page=qr-editor');assertHttp($status===200&&str_contains($editor,'CODURI DEMO')&&!str_contains($editor,'Warning:')&&!str_contains($editor,'Fatal error'),'driver visual QR editor');
preg_match('/data-driver="(\d+)"/',$editor,$dm);$designDriver=(int)($dm[1]??0);
preg_match('/data-revision="(\d+)"/',$editor,$vm);$designRevision=(int)($vm[1]??0);
preg_match('/data-design="([^"]+)"/',$editor,$em);$originalDesign=json_decode(html_entity_decode($em[1]??'',ENT_QUOTES|ENT_HTML5,'UTF-8'),true);
$changedDesign=$originalDesign;$changedDesign['elements']['title']['text']='HTTP visual design test';$changedDesign['background']='#ddeeff';
[$status,$savedProposal]=$driver->request('?page=api&action=qr-design',['driver'=>$designDriver,'revision'=>$designRevision,'orientation'=>'portrait','design'=>json_encode($changedDesign)],true);assertHttp($status===200,'driver submits visual design over CSRF API');$proposalRevision=json_decode($savedProposal,true)['revision'];
[$status,$pendingDisplay]=$driver->request('?page=display&token='.$token);assertHttp(!str_contains($pendingDisplay,'HTTP visual design test'),'pending template cannot change public display');
[$status]=$manager->request('?page=api&action=qr-template-review',['scope'=>'driver','owner'=>$designDriver,'revision'=>$proposalRevision,'orientation'=>'portrait','decision'=>'approve'],true);assertHttp($status===200,'agency manager explicitly approves driver template');
[$status,$customDisplay]=$driver->request('?page=display&token='.$token);assertHttp($status===200&&str_contains($customDisplay,'HTTP visual design test')&&str_contains($customDisplay,'#ddeeff')&&!str_contains($customDisplay,'resize-handle'),'saved design applied without editor controls');
[$status,$restoredProposal]=$driver->request('?page=api&action=qr-design',['driver'=>$designDriver,'revision'=>$proposalRevision+1,'orientation'=>'portrait','design'=>json_encode($originalDesign)],true);assertHttp($status===200,'resubmit original layout after HTTP fixture');
[$status]=$manager->request('?page=api&action=qr-template-review',['scope'=>'driver','owner'=>$designDriver,'revision'=>json_decode($restoredProposal,true)['revision'],'orientation'=>'portrait','decision'=>'approve'],true);assertHttp($status===200,'restored layout also requires approval');
[$status]=$manager->request('?page=qr-editor&driver='.$designDriver);assertHttp($status===200,'manager can edit own agency driver design');
preg_match('/page=qr&amp;token=([a-f0-9]+)/',$html,$qm);$qrToken=$qm[1]??'';
foreach(['portrait','landscape'] as $orientation){
 [$status,$body]=$driver->request('?page=qr-pdf&token='.$token.'&orientation='.$orientation);
 assertHttp($status===200&&str_starts_with($body,'%PDF'),'dual QR PDF '.$orientation);
 [$status,$display]=$driver->request('?page=display&token='.$token.'&orientation='.$orientation);
 assertHttp($status===200&&!str_contains($display,'csrf-token" content=""')&&!str_contains($display,'Ieși din cont')&&!str_contains($display,'passenger_phone'),'display privacy '.$orientation);
 preg_match('/data:image\/png;base64,([A-Za-z0-9+\/=]+)/',$display,$qm);$image=base64_decode($qm[1]??'',true);assertHttp(is_string($image)&&str_starts_with($image,"\x89PNG"),'QR PNG '.$orientation);
}
[$status]=$driver->request('?page=api&action=rules',['participant'=>1,'earn_bps'=>10000],true);assertHttp($status===403,'driver denied rule changes');
$fixturePhone='+407'.random_int(10000000,99999999);$fixture=Alba\Auth::create($fixturePhone,$password,true);Alba\App::run('UPDATE accounts SET verified=1 WHERE id=?',[$fixture['id']]);
$passenger=new Browser($base);$passenger->login($fixturePhone,$password);
[$status]=$passenger->request('?page=qr-editor&driver='.$designDriver);assertHttp($status===403,'passenger denied QR editor');
[$status]=$passenger->request('?page=api&action=qr-design',['driver'=>$designDriver,'orientation'=>'portrait','design'=>json_encode($changedDesign)],true);assertHttp($status===403,'passenger denied QR design writes');
[$status,$initialHome]=$passenger->request('?role=passenger');preg_match('/class="balance">([^<]+)/',$initialHome,$balanceMatch);$initialBalance=(int)round((float)str_replace([' ', ','],['','.'],trim($balanceMatch[1]))*100);
[$status]=$passenger->request('?page=scan&token='.$qrToken);assertHttp($status===303,'scan creates passenger ready context');
[$status,$home]=$passenger->request('?role=passenger');assertHttp($status===200&&str_contains($home,'PREGĂTIT PENTRU CURSĂ'),'passenger ready code displayed');
[$status,$queue]=$driver->request('?role=driver');preg_match('/class="match-code">([A-F0-9]+)/',$home,$qm);$code=$qm[1]??'';
assertHttp($code!==''&&str_contains($queue,'value="'.$code.'"'),'driver refresh reveals the correct passenger request among distinct clients');
[$status,$body]=$driver->request('?page=api&action=preview',['code'=>$code,'fare'=>'20,00'],true,bin2hex(random_bytes(16)));$r=json_decode($body,true);assertHttp($status===200&&!empty($r['ride_id']),'HTTP ride preview');
[$status,$detail]=$driver->request('?page=ride&id='.$r['ride_id']);assertHttp($status===200&&str_contains($detail,'Confirm cursa efectuată'),'driver review screen');
assertHttp(str_contains($detail,'1,00 lei')&&!str_contains($detail,'5% din plata fără credit</b>'),'driver review shows the exact server-calculated bonus');
[$status,$body]=$driver->request('?page=api&action=post',['ride_id'=>$r['ride_id']],true,bin2hex(random_bytes(16)));assertHttp($status===200&&(json_decode($body,true)['status']??'')==='posted','HTTP driver posting');
[$status,$html]=$passenger->request('?role=passenger');$expectedBalance=number_format(($initialBalance+100)/100,2,',',' ');assertHttp($status===200&&str_contains($html,$expectedBalance),'passenger sees committed balance');
[$status]=$passenger->request('?page=scan&token='.$qrToken);[$status,$newReady]=$passenger->request('?role=passenger');preg_match('/class="match-code">([A-F0-9]+)/',$newReady,$expiredCode);
[$status,$body]=$driver->request('?page=api&action=preview',['code'=>$expiredCode[1],'fare'=>'20,00'],true,bin2hex(random_bytes(16)));$expiredRide=json_decode($body,true);
Alba\App::run('UPDATE rides SET expires_at=? WHERE id=?',[Alba\App::after(-1),$expiredRide['ride_id']]);
[$status,$expiredHtml]=$driver->request('?page=ride&id='.$expiredRide['ride_id']);assertHttp($status===200&&str_contains($expiredHtml,'Confirmarea a expirat.')&&!str_contains($expiredHtml,'Confirm cursa efectuată și încasarea'),'expired confirmation is clearly marked and cannot be submitted from the page');
[$status,$body]=$driver->request('?page=api&action=post',['ride_id'=>$expiredRide['ride_id']],true,bin2hex(random_bytes(16)));assertHttp($status===409&&(json_decode($body,true)['error']??'')==='APPROVAL_EXPIRED','expired confirmation is rejected server-side');
$invalid=new Browser($base);$invalid->request('?page=login');$invalid->csrf='bad';
[$status]=$invalid->request('?page=api&action=support',['message'=>'Test request'],true);assertHttp($status===403,'CSRF invalid token denied');
[$status,$body]=$driver->request('?page=api&action=report&template=rides');assertHttp($status===200&&!str_contains($body,'password_hash')&&!str_contains($body,'digest'),'reports exclude authentication secrets');
echo "\n$checks HTTP checks passed.\n";
