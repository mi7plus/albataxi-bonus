<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Alba\App;use Alba\Auth;use Alba\Commands;use Alba\Loyalty;use Alba\Memberships;use Alba\Reports;use Alba\Problem;use Alba\Scope;
if(!str_ends_with((string)App::scalar('SELECT DATABASE()'),'_test')){fwrite(STDERR,"Tests require a separate database ending in _test.\n");exit(1);}
foreach(glob(dirname(__DIR__).'/database/*.sql') as $file){foreach(explode(';',file_get_contents($file)) as $sql){if(trim($sql)!==''){App::db()->exec($sql);}}}
$checks=0;
function check(bool $ok,string $message):void{global $checks;if(!$ok){throw new RuntimeException('FAILED: '.$message);}echo 'PASS '.$message."\n";$checks++;}
function rejects(callable $fn,string $code):void{try{$fn();throw new RuntimeException('Expected '.$code);}catch(Problem $e){check($e->error===$code,'reject '.$code.' (got '.$e->error.')');}}
function account():array{
 $phone='+407'.random_int(10000000,99999999);$u=Auth::create($phone,'Test-only-password-928!',true);
 App::run('UPDATE accounts SET verified=1 WHERE id=?',[$u['id']]);return App::one('SELECT * FROM accounts WHERE id=?',[$u['id']]);
}
function participant(array $admin,string $name):array{
 $p=Commands::execute($admin,'agency-create',['name'=>$name,'funding_terms'=>'Test: agreed contribution terms','daily_limit'=>100],'');
 $i=Memberships::invite($admin,$p['id'],$admin['phone'],'','manager');$m=Memberships::accept($admin,$i['token']);
 Commands::execute($admin,'membership-update',['id'=>$m['membership_id'],'active'=>true,'reason'=>'Test setup'],'');
 Commands::execute($admin,'participant-update',['participant'=>$p['id'],'status'=>'active'],'');return App::one('SELECT * FROM participants WHERE id=?',[$p['id']]);
}
function driver(array $admin,array $p,string $plate):array{
 $u=account();$i=Memberships::invite($admin,(int)$p['id'],$u['phone'],$plate);$m=Memberships::accept($u,$i['token']);
 Commands::execute($admin,'membership-update',['id'=>$m['membership_id'],'active'=>true,'reason'=>'Test setup'],'');
 $qr=App::one('SELECT * FROM qr_pages WHERE membership_id=?',[$m['membership_id']]);return [$u,$m['membership_id'],$qr];
}
function preview(array $passenger,array $driver,mixed $fare):array{
 $req=Loyalty::ready($passenger,$driver[2]['token']);return Loyalty::preview($driver[0],$req['code'],$fare,App::id());
}
function post(array $passenger,array $driver,mixed $fare,mixed $redeem='0'):array{
 $r=preview($passenger,$driver,$fare);if(App::money($redeem)>0){Loyalty::approve($passenger,$r['ride_id'],$redeem);}
 Loyalty::post($driver[0],$r['ride_id'],App::id());return App::one('SELECT * FROM rides WHERE id=?',[$r['ride_id']]);
}
try{
 check(App::money('20,01')===2001&&App::money('0.01')===1,'integer bani parsing');
 foreach(['-1','NaN','20.001','1e3','1.2.3',''] as $v){rejects(fn()=>App::money($v),'INVALID_AMOUNT');}
 check(App::phone('0722 123 456')==='+40722123456','canonical phone');
 foreach(['+40722123456','0722123456','722123456','40722123456','0040 722 123 456'] as $phoneFormat){check(App::phone($phoneFormat)==='+40722123456','Romanian phone prefix normalisation '.$phoneFormat);}
 check(App::phone('+447700900123')==='+447700900123','explicit foreign country code preserved');
 rejects(fn()=>App::phone('72212345'),'INVALID_PHONE');
 $profilePhone='+407'.random_int(10000000,99999999);$profileUser=Auth::create(substr($profilePhone,3),'Profile-test-password-2026',true,false,"  Ana-Maria O'Connor  ",'2000-02-29');
 $storedProfile=App::one('SELECT * FROM account_profiles WHERE account_id=?',[$profileUser['id']]);
 check($profileUser['phone']===$profilePhone&&$storedProfile['full_name']==="Ana-Maria O'Connor"&&$storedProfile['date_of_birth']==='2000-02-29','optional registration profile saved with canonical phone');
 foreach([$profilePhone,'0'.substr($profilePhone,3),substr($profilePhone,3),substr($profilePhone,1),'00'.substr($profilePhone,1)] as $loginPhone){check(Auth::login($loginPhone,'Profile-test-password-2026','prefix-test-'.App::id())['id']===$profileUser['id'],'all phone formats sign into the same account');rejects(fn()=>Auth::create($loginPhone,'Profile-test-password-2026',true),'REGISTRATION_UNAVAILABLE');}
 $withoutProfile=account();check(!App::one('SELECT * FROM account_profiles WHERE account_id=?',[$withoutProfile['id']]),'registration accepts both optional fields omitted');
 $nameOnly=Auth::create('+407'.random_int(10000000,99999999),'Profile-test-password-2026',true,false,'Doar Nume');check(App::one('SELECT date_of_birth FROM account_profiles WHERE account_id=?',[$nameOnly['id']])['date_of_birth']===null,'birth date can be omitted independently');
 $dobOnly=Auth::create('+407'.random_int(10000000,99999999),'Profile-test-password-2026',true,false,'','1995-06-15');check(App::one('SELECT full_name FROM account_profiles WHERE account_id=?',[$dobOnly['id']])['full_name']===null,'full name can be omitted independently');
 foreach(['2023-02-29','1900-02-29','2000-2-29','not-a-date','9999-01-01'] as $badDate){rejects(fn()=>Auth::create('+407'.random_int(10000000,99999999),'Profile-test-password-2026',true,false,'Test',$badDate),'INVALID_BIRTH_DATE');}
 rejects(fn()=>Auth::create('+407'.random_int(10000000,99999999),'Profile-test-password-2026',true,false,str_repeat('a',161)),'INVALID_NAME');
 $admin=account();App::run('UPDATE accounts SET is_admin=1 WHERE id=?',[$admin['id']]);$admin['is_admin']=1;
 $a=participant($admin,'Agency A '.App::id());$b=participant($admin,'Agency B '.App::id());
 $da=driver($admin,$a,'AB-01-AAA');$db=driver($admin,$b,'AB-02-BBB');
 $p1=account();$r=post($p1,$da,'20');check((int)$r['earned']===100,'PRD example 1: 20 lei earns 1 lei');
 foreach(['portrait','landscape'] as $orientation){
  $design=Alba\QrDesign::defaults($orientation);check(Alba\QrDesign::validate($design,$orientation)===$design,'valid default QR design '.$orientation);
  $design['background']='#faeedd';$design['elements']['title']['text']='<script>alert(1)</script>';
  $inheritedDesign=Alba\QrDesign::load($da[1],$orientation);
  Alba\QrDesign::save($da[0],['driver'=>$da[1],'orientation'=>$orientation,'revision'=>0,'design'=>json_encode($design)]);
  check(Alba\QrDesign::load($da[1],$orientation)===$inheritedDesign,'pending driver design does not publish '.$orientation);
  rejects(fn()=>Alba\QrTemplates::review($da[0],['scope'=>'driver','owner'=>$da[1],'orientation'=>$orientation,'revision'=>1,'decision'=>'approve']),'FORBIDDEN');
  Alba\QrTemplates::review($admin,['scope'=>'driver','owner'=>$da[1],'orientation'=>$orientation,'revision'=>1,'decision'=>'approve']);
  check(Alba\QrDesign::load($da[1],$orientation)===$design,'agency approved driver design published '.$orientation);
  check(str_contains(Alba\QrDesign::render($design,['token'=>'test','review_url'=>'https://example.com'],$orientation),'&lt;script&gt;'),'QR design text escaped');
  rejects(fn()=>Alba\QrDesign::save($db[0],['driver'=>$da[1],'orientation'=>$orientation,'design'=>json_encode($design)]),'FORBIDDEN');
  $design['elements']['bonus']['w']=1;rejects(fn()=>Alba\QrDesign::validate($design,$orientation),'INVALID_DESIGN');
  $design=Alba\QrDesign::defaults($orientation);$design['elements']['title']['x']=999;rejects(fn()=>Alba\QrDesign::validate($design,$orientation),'INVALID_DESIGN');
  $design=Alba\QrDesign::defaults($orientation);$design['background']='red; background:url(https://evil.test)';rejects(fn()=>Alba\QrDesign::validate($design,$orientation),'INVALID_DESIGN');
  $design=Alba\QrDesign::defaults($orientation);$design['font']=[];rejects(fn()=>Alba\QrDesign::validate($design,$orientation),'INVALID_DESIGN');
  $design=Alba\QrDesign::defaults($orientation);$design['elements']['review']['x']=$design['elements']['bonus']['x'];$design['elements']['review']['y']=$design['elements']['bonus']['y'];rejects(fn()=>Alba\QrDesign::validate($design,$orientation),'INVALID_DESIGN');
 }
 $templateOrientation='portrait';$savedDefault=Alba\QrTemplates::row('default',0,$templateOrientation);
 $defaultDesign=Alba\QrDesign::defaults($templateOrientation);$defaultDesign['background']='#eeddcc';
 rejects(fn()=>Alba\QrTemplates::save($da[0],['scope'=>'default','owner'=>0,'orientation'=>$templateOrientation,'revision'=>0,'design'=>json_encode($defaultDesign)]),'FORBIDDEN');
 $defaultResult=Alba\QrTemplates::save($admin,['scope'=>'default','owner'=>0,'orientation'=>$templateOrientation,'revision'=>$savedDefault['revision']??0,'design'=>json_encode($defaultDesign)]);
 check(Alba\QrDesign::load($db[1],$templateOrientation)===$defaultDesign,'admin default applies to unaffiliated overrides immediately');
 $agencyDesign=$defaultDesign;$agencyDesign['background']='#ddeeff';
 $agencyResult=Alba\QrTemplates::save($admin,['scope'=>'agency','owner'=>$a['id'],'orientation'=>$templateOrientation,'revision'=>0,'design'=>json_encode($agencyDesign)]);
 $dc=driver($admin,$a,'AB-03-CCC');
 check(Alba\QrDesign::load($dc[1],$templateOrientation)===$defaultDesign,'agency proposal inherits default until admin approval');
 rejects(fn()=>Alba\QrTemplates::review($da[0],['scope'=>'agency','owner'=>$a['id'],'orientation'=>$templateOrientation,'revision'=>1,'decision'=>'approve']),'FORBIDDEN');
 Alba\QrTemplates::review($admin,['scope'=>'agency','owner'=>$a['id'],'orientation'=>$templateOrientation,'revision'=>1,'decision'=>'approve']);
 check(Alba\QrDesign::load($dc[1],$templateOrientation)===$agencyDesign,'approved agency template applies to all inheriting drivers');
 check(Alba\QrDesign::load($db[1],$templateOrientation)===$defaultDesign,'agency template does not leak to another agency');
 check(Alba\QrTemplates::effective($da[1],$templateOrientation)['source']==='driver','approved driver override survives agency updates');
 $nextDriver=$agencyDesign;$nextDriver['background']='#aabbcc';
 Alba\QrTemplates::save($dc[0],['scope'=>'driver','owner'=>$dc[1],'orientation'=>$templateOrientation,'revision'=>0,'design'=>json_encode($nextDriver)]);
 rejects(fn()=>Alba\QrTemplates::review($db[0],['scope'=>'driver','owner'=>$dc[1],'orientation'=>$templateOrientation,'revision'=>1,'decision'=>'approve']),'FORBIDDEN');
 rejects(fn()=>Alba\QrTemplates::review($admin,['scope'=>'driver','owner'=>$dc[1],'orientation'=>$templateOrientation,'revision'=>0,'decision'=>'approve']),'DESIGN_CHANGED');
 Alba\QrTemplates::review($admin,['scope'=>'driver','owner'=>$dc[1],'orientation'=>$templateOrientation,'revision'=>1,'decision'=>'reject','note'=>'Please improve typography']);
 check(Alba\QrDesign::load($dc[1],$templateOrientation)===$agencyDesign,'rejected driver proposal keeps agency design public');
 Alba\QrTemplates::save($dc[0],['scope'=>'driver','owner'=>$dc[1],'orientation'=>$templateOrientation,'revision'=>2,'design'=>json_encode($nextDriver)]);
 rejects(fn()=>Alba\QrTemplates::review($admin,['scope'=>'driver','owner'=>$dc[1],'orientation'=>$templateOrientation,'revision'=>1,'decision'=>'approve']),'DESIGN_CHANGED');
 Alba\QrTemplates::review($admin,['scope'=>'driver','owner'=>$dc[1],'orientation'=>$templateOrientation,'revision'=>3,'decision'=>'approve']);
 check(Alba\QrDesign::load($dc[1],$templateOrientation)===$nextDriver,'resubmitted driver design needs a new approval');
 Alba\QrTemplates::review($dc[0],['scope'=>'driver','owner'=>$dc[1],'orientation'=>$templateOrientation,'revision'=>4,'decision'=>'inherit']);
 check(Alba\QrDesign::load($dc[1],$templateOrientation)===$agencyDesign,'driver can return to approved agency design');
 // Restore the global default so unrelated test scenarios keep their original context.
 App::run('UPDATE qr_templates SET approved_json=?,proposal_json=?,status=?,revision=revision+1 WHERE scope=? AND owner_id=0 AND orientation=?',[$savedDefault['approved_json']??null,$savedDefault['proposal_json']??null,$savedDefault['status']??'inherited','default',$templateOrientation]);
 $p2=account();post($p2,$da,'200');$r=post($p2,$db,'40','10');
 check((int)$r['earned']===150&&(int)$r['redeemed']===1000&&(int)App::scalar('SELECT balance FROM wallets WHERE passenger_id=?',[$p2['id']])===150,'PRD example 2 and cross-partner redemption');
 check((int)App::scalar("SELECT amount FROM fund_entries WHERE ride_id=? AND kind='reimbursement_due'",[$r['id']])===1000,'reimbursement attributed to serving participant');
 $p3=account();post($p3,$da,'200');$r=post($p3,$db,'20','6');check((int)$r['earned']===70&&(int)App::scalar('SELECT balance FROM wallets WHERE passenger_id=?',[$p3['id']])===470,'PRD example 3: final balance 4.70');
 $p4=account();post($p4,$da,'200');$r=preview($p4,$db,'20');
 rejects(fn()=>Loyalty::approve($p4,$r['ride_id'],'6.01'),'INSUFFICIENT_CREDIT');
 rejects(fn()=>Loyalty::approve($p1,$r['ride_id'],'1'),'NOT_FOUND');
 rejects(fn()=>Memberships::rules($da[0],(int)$a['id'],['earn_bps'=>9999]),'FORBIDDEN');
 rejects(fn()=>Scope::managed($da[0],(int)$b['id']),'FORBIDDEN');
 $manager=account();$managerInvite=Memberships::invite($admin,(int)$a['id'],$manager['phone'],'','manager');$joined=Memberships::accept($manager,$managerInvite['token']);
 Commands::execute($admin,'membership-update',['id'=>$joined['membership_id'],'active'=>true,'reason'=>'Manager isolation test'],'');
 rejects(fn()=>Scope::managed($manager,(int)$b['id']),'FORBIDDEN');
 $agencyRevision=Alba\QrTemplates::row('agency',(int)$a['id'],'portrait')['revision'];$existingAgency=Alba\QrDesign::load($dc[1],'portrait');
 $managerDesign=$existingAgency;$managerDesign['background']='#abcdef';
 Alba\QrTemplates::save($manager,['scope'=>'agency','owner'=>$a['id'],'orientation'=>'portrait','revision'=>$agencyRevision,'design'=>json_encode($managerDesign)]);
 check(Alba\QrDesign::load($dc[1],'portrait')===$existingAgency,'agency revision leaves old approved template live');
 rejects(fn()=>Alba\QrTemplates::review($manager,['scope'=>'agency','owner'=>$a['id'],'orientation'=>'portrait','revision'=>$agencyRevision+1,'decision'=>'approve']),'FORBIDDEN');
 Alba\QrTemplates::review($admin,['scope'=>'agency','owner'=>$a['id'],'orientation'=>'portrait','revision'=>$agencyRevision+1,'decision'=>'reject','note'=>'Retain approved appearance']);
 check(Alba\QrDesign::load($dc[1],'portrait')===$existingAgency,'rejected agency revision retains prior approval');
 $outsiderAdmin=account();$outsiderAdmin['is_admin']=1;App::run('UPDATE accounts SET is_admin=1 WHERE id=?',[$outsiderAdmin['id']]);
 $driverRevision=Alba\QrTemplates::row('driver',$dc[1],'portrait')['revision'];
 Alba\QrTemplates::save($manager,['scope'=>'driver','owner'=>$dc[1],'orientation'=>'portrait','revision'=>$driverRevision,'design'=>json_encode($managerDesign)]);
 rejects(fn()=>Alba\QrTemplates::review($outsiderAdmin,['scope'=>'driver','owner'=>$dc[1],'orientation'=>'portrait','revision'=>$driverRevision+1,'decision'=>'approve']),'FORBIDDEN');
 rejects(fn()=>Alba\QrTemplates::review($manager,['scope'=>'driver','owner'=>$dc[1],'orientation'=>'portrait','revision'=>$driverRevision+1,'decision'=>'approve']),'FORBIDDEN');
 Alba\QrTemplates::review($admin,['scope'=>'driver','owner'=>$dc[1],'orientation'=>'portrait','revision'=>$driverRevision+1,'decision'=>'approve']);
 check(Alba\QrDesign::load($dc[1],'portrait')===$managerDesign,'different authorised agency manager may approve exact driver revision');
 $independentParticipant=participant($admin,'Independent QR '.App::id());App::run("UPDATE participants SET kind='independent' WHERE id=?",[$independentParticipant['id']]);$independentDriver=driver($admin,$independentParticipant,'AB-04-DDD');
 Alba\QrTemplates::save($independentDriver[0],['scope'=>'driver','owner'=>$independentDriver[1],'orientation'=>'portrait','revision'=>0,'design'=>json_encode($managerDesign)]);
 Alba\QrTemplates::review($outsiderAdmin,['scope'=>'driver','owner'=>$independentDriver[1],'orientation'=>'portrait','revision'=>1,'decision'=>'approve']);
 check(Alba\QrDesign::load($independentDriver[1],'portrait')===$managerDesign,'independent driver uses administrator approval');
 rejects(fn()=>Memberships::invite($manager,(int)$b['id'],'+40729998888','AB-13-XXX'),'FORBIDDEN');
 rejects(fn()=>Commands::execute($manager,'participant-update',['participant'=>$a['id'],'status'=>'active'],''),'FORBIDDEN');
 rejects(fn()=>Scope::ride($da[0],$r['ride_id']),'NOT_FOUND');
 $scoped=Reports::report($da[0],['start'=>'2000-01-01','end'=>'2099-01-01']);check(count(array_filter($scoped['rows'],fn($row)=>$row[2]===$b['name']))===0,'driver reports exclude other participant');
 rejects(fn()=>Reports::report($da[0],['template'=>'audit']),'FORBIDDEN');
 $sameKey=App::id();Loyalty::approve($p4,$r['ride_id'],'5');
 $first=Loyalty::post($db[0],$r['ride_id'],$sameKey);$second=Loyalty::post($db[0],$r['ride_id'],$sameKey);
 check($first===$second,'same-key post returns same result');
 check((int)App::scalar('SELECT COUNT(*) FROM ledger WHERE ride_id=?',[$r['ride_id']])===2,'retry has exactly two ledger entries');
 rejects(fn()=>Loyalty::post($db[0],'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',$sameKey),'IDEMPOTENCY_CONFLICT');
 // Bound rules remain unchanged after an ordinary update.
 $p5=account();$r=preview($p5,$da,'20');$before=App::one('SELECT * FROM rides WHERE id=?',[$r['ride_id']]);
 Memberships::rules($admin,(int)$a['id'],['earn_bps'=>1000,'daily_limit'=>100]);
 Loyalty::post($da[0],$r['ride_id'],App::id());$after=App::one('SELECT * FROM rides WHERE id=?',[$r['ride_id']]);
 check($before['rule_id']===$after['rule_id']&&(int)$after['earned']===100,'unexpired preview keeps rule version');
 // Suspension overrides bound previews.
 $p6=account();$r=preview($p6,$da,'20');
 Commands::execute($admin,'membership-update',['id'=>$da[1],'active'=>false,'reason'=>'Suspension test'],'');
 rejects(fn()=>Loyalty::post($da[0],$r['ride_id'],App::id()),'PARTICIPANT_SUSPENDED');
 Commands::execute($admin,'membership-update',['id'=>$da[1],'active'=>true,'reason'=>'Resume test'],'');
 App::run('UPDATE rides SET expires_at=? WHERE id=?',[App::after(-1),$r['ride_id']]);
 rejects(fn()=>Loyalty::post($da[0],$r['ride_id'],App::id()),'APPROVAL_EXPIRED');
 // Concurrent spending on different participants must serialise on the programme-wide wallet.
 $con=account();post($con,$db,'200');$ra=preview($con,$da,'30');$rb=preview($con,$db,'30');
 Loyalty::approve($con,$ra['ride_id'],'6');Loyalty::approve($con,$rb['ride_id'],'6');
 $start=microtime(true)+0.5;$processes=[];
 foreach([[$da,$ra],[$db,$rb]] as [$d,$ride]){
  $cmd=[PHP_BINARY,__DIR__.'/redeem_worker.php',(string)$d[0]['id'],$ride['ride_id'],App::id(),(string)$start];
  $pipes=[];$process=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$processes[]=[$process,$pipes];
 }
 $results=[];foreach($processes as [$process,$pipes]){$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);if($exit!==0){throw new RuntimeException('Concurrent worker: '.$stderr);}$results[]=json_decode($stdout,true,512,JSON_THROW_ON_ERROR);}
 check(count(array_filter($results,fn($v)=>($v['status']??'')==='posted'))===1,'concurrent redemption: exactly one committed');
 check(count(array_filter($results,fn($v)=>($v['error']??'')==='BALANCE_CHANGED'))===1,'concurrent redemption: second requires new passenger approval');
 check((int)App::scalar('SELECT balance FROM wallets WHERE passenger_id=?',[$con['id']])>=0,'concurrent wallet never negative');
 // Reversal is compensating, linked, idempotent.
 $rev=account();$original=post($rev,$db,'20');$key=App::id();$x=Loyalty::reverse($admin,$original['id'],'Incorrect fare test',$key);$y=Loyalty::reverse($admin,$original['id'],'Incorrect fare test',$key);
 check($x===$y&&(int)App::scalar('SELECT balance FROM wallets WHERE passenger_id=?',[$rev['id']])===0,'linked idempotent reversal');
 $spent=account();$original=post($spent,$db,'200');post($spent,$db,'40','10');
 $x=Loyalty::reverse($admin,$original['id'],'Already spent correction',App::id());
 check($x['status']==='manual_recovery','spent credit goes to manual recovery without negative balance');
 // CSV preview and partial import, repeat confirmation.
 $phone='+407'.random_int(10000000,99999999);$batch=Memberships::previewImport($admin,(int)$a['id'],"phone,plate\n$phone,AB-11-CCC\n$phone,AB-12-CCC\nbad,\n");
 check(count(array_filter($batch['rows'],fn($r)=>$r['error']!==''))===2,'CSV row-level errors and duplicate detection');
 rejects(fn()=>Memberships::confirmImport($admin,$batch['id'],false),'IMPORT_ERRORS');
 $x=Memberships::confirmImport($admin,$batch['id'],true);$y=Memberships::confirmImport($admin,$batch['id'],true);check($x===$y,'CSV confirmation is resumable and idempotent');
 // Force an error after wallet mutation to verify transaction rollback.
 $atomicPassenger=account();$atomicRide=preview($atomicPassenger,$db,'20');
 $sentinel=App::insert('ledger',['passenger_id'=>$atomicPassenger['id'],'ride_id'=>$atomicRide['ride_id'],'kind'=>'earn','amount'=>0,'created_at'=>App::now()]);
 try{Loyalty::post($db[0],$atomicRide['ride_id'],App::id());throw new RuntimeException('Expected forced ledger conflict');}catch(PDOException $e){check((int)($e->errorInfo[1]??0)===1062,'forced posting failure after wallet mutation');}
 check((int)App::scalar('SELECT balance FROM wallets WHERE passenger_id=?',[$atomicPassenger['id']])===0&&App::scalar('SELECT status FROM rides WHERE id=?',[$atomicRide['ride_id']])==='draft','posting failure rolls back wallet and ride');
 App::run('DELETE FROM ledger WHERE id=?',[$sentinel]);
 // Transfer explicitly accepted; old QR and drafts revoked, historical participant unchanged.
 $oldQr=$da[2]['token'];$history=App::one("SELECT * FROM rides WHERE driver_id=? AND status='posted' LIMIT 1",[$da[1]]);
 $inv=Memberships::invite($admin,(int)$b['id'],$da[0]['phone'],'AB-99-NEW');Memberships::accept($da[0],$inv['token']);
 check(!App::one('SELECT id FROM qr_pages WHERE token=?',[$oldQr]),'transfer revokes previous QR');
 check((int)App::scalar('SELECT participant_id FROM rides WHERE id=?',[$history['id']])===(int)$a['id'],'transfer preserves historical participant');
 rejects(fn()=>Loyalty::ready($p1,$oldQr),'QR_REVOKED');
 // OTP attempts and single-use, reset revokes sessions.
 Auth::requestOtp($p1['phone'],'verify','test-'.App::id());$message=App::one('SELECT * FROM dev_messages WHERE phone=? ORDER BY id DESC LIMIT 1',[$p1['phone']]);preg_match('/cod (\d{6})/',$message['message'],$match);
 $wrong=$match[1]==='000000'?'000001':'000000';rejects(fn()=>Auth::consumeOtp($p1['phone'],'verify',$wrong),'INVALID_OTP');
 check((int)App::scalar('SELECT attempts FROM otps WHERE phone=? ORDER BY id DESC LIMIT 1',[$p1['phone']])===1,'failed OTP attempt persists');
 Auth::consumeOtp($p1['phone'],'verify',$match[1]);rejects(fn()=>Auth::consumeOtp($p1['phone'],'verify',$match[1]),'INVALID_OTP');
 Auth::requestOtp($p1['phone'],'recover','reset-test-'.App::id());$message=App::one('SELECT * FROM dev_messages WHERE phone=? ORDER BY id DESC LIMIT 1',[$p1['phone']]);preg_match('/cod (\d{6})/',$message['message'],$resetCode);
 App::insert('sessions',['token_hash'=>hash('sha256',App::token()),'account_id'=>$p1['id'],'csrf'=>bin2hex(random_bytes(32)),'version'=>1,'context_json'=>'{}','expires_at'=>App::after(3600)]);
 Auth::consumeOtp($p1['phone'],'recover',$resetCode[1],'Changed-test-password-2026');
 check((int)App::scalar('SELECT COUNT(*) FROM sessions WHERE account_id=?',[$p1['id']])===0,'password reset revokes every prior session');
 Auth::requestOtp($p1['phone'],'verify','expiry-test-'.App::id());App::run('UPDATE otps SET expires_at=? WHERE phone=? AND used=0',[App::after(-1),$p1['phone']]);
 rejects(fn()=>Auth::consumeOtp($p1['phone'],'verify','000000'),'INVALID_OTP');
 // Export injection and formats.
 check(str_starts_with(Reports::safe(' =SUM(A1:A2)'),"'"),'CSV neutralises formula injection');
 $png=Alba\Http::qrImage('https://example.com/?page=scan&token=abc');check(str_starts_with($png,"\x89PNG"),'real QR PNG generation');
 foreach(['scan','review'] as $qrPage){$destination='https://bonus.example.com/?page='.$qrPage.'&token=scan-verification';$generated=Alba\Http::qrImage($destination);$decoded=(string)(new chillerlan\QRCode\QRCode())->readFromBlob($generated);check($decoded===$destination,'QR decoder recovers the exact '.$qrPage.' destination');}
 $report=Reports::report($admin,['start'=>'2000-01-01','end'=>'2099-01-01','participant'=>$b['id']]);
 check((int)$report['total']>0&&$report['timezone']==='Europe/Bucharest','report scope, totals and timezone metadata');
 // Settlement snapshots claim records once; later corrections enter following statement.
 $settle=Commands::execute($admin,'settlement-create',['participant'=>$b['id'],'period_end'=>(new DateTimeImmutable('now',new DateTimeZone('Europe/Bucharest')))->format('Y-m-d')],App::id());
 $snapshot=App::one('SELECT * FROM settlements WHERE id=?',[$settle['id']]);check((int)App::scalar('SELECT COUNT(*) FROM fund_entries WHERE settlement_id=?',[$settle['id']])>0,'settlement pins source entries');
 rejects(fn()=>Commands::execute($admin,'settlement-update',['id'=>$settle['id'],'status'=>'paid','amount'=>'1','reference'=>'test'],App::id()),'INVALID_STATE');
 Commands::execute($admin,'settlement-update',['id'=>$settle['id'],'status'=>'under_review'],App::id());
 Commands::execute($admin,'settlement-update',['id'=>$settle['id'],'status'=>'approved'],App::id());
 check(App::scalar('SELECT status FROM settlements WHERE id=?',[$settle['id']])==='approved','settlement approval lifecycle');
 rejects(fn()=>Commands::execute($admin,'fund',['participant'=>$b['id'],'kind'=>'reimbursement_paid','amount'=>'1','reference'=>'outside statement'],App::id()),'STATEMENT_PAYMENT_REQUIRED');
 $statementTotals=json_decode($snapshot['snapshot'],true);$remaining=max(0,($statementTotals['reimbursement_due']??0)-($statementTotals['reimbursement_paid']??0));
 if($remaining>0){$payKey=App::id();$payment=['id'=>$settle['id'],'status'=>'paid','amount'=>number_format($remaining/100,2,'.',''),'reference'=>'Test bank reference'];$firstPayment=Commands::execute($admin,'settlement-update',$payment,$payKey);$secondPayment=Commands::execute($admin,'settlement-update',$payment,$payKey);check($firstPayment===$secondPayment&&$firstPayment['status']==='paid','settlement payment is idempotent and cannot double-pay');}
 // Upgrade: human percentage inputs retain exact integer rule versions.
 $percentRule=Memberships::rules($admin,(int)$b['id'],['earn_percent'=>'5,25','cap_percent'=>'30,00','contribution_percent'=>'4,50','max_fare_lei'=>'999,99','daily_limit'=>100]);
 $percent=App::one('SELECT * FROM rules WHERE id=?',[$percentRule]);
 check((int)$percent['earn_bps']===525&&(int)$percent['contribution_bps']===450&&(int)$percent['max_fare']===99999,'human percentages and lei convert exactly to stored units');
 rejects(fn()=>Memberships::rules($admin,(int)$b['id'],['earn_percent'=>'100.01']),'INVALID_RULE');
 Memberships::rules($admin,(int)$b['id'],['daily_limit'=>100]);
 $configured=App::one('SELECT p.*,(SELECT id FROM rules WHERE participant_id=p.id ORDER BY id DESC LIMIT 1) effective_rule,'.Alba\Setup::COUNTS.' FROM participants p WHERE p.id=?',[$b['id']]);
 $steps=Alba\Setup::steps($configured,'admin');
 check(!array_filter($steps,fn($step)=>$step['required']&&!$step['done']),'guided setup recognises an eligible participant');
 $configured['setup_vehicles']=0;$steps=Alba\Setup::steps($configured,'admin');
 check(count(array_filter($steps,fn($step)=>$step['key']==='vehicle'&&!$step['done']))===1,'guided setup identifies the missing eligible vehicle');
 check(Alba\Labels::state('posted')==='Înregistrat'&&Alba\Labels::state('manual_recovery')==='Recuperare manuală','Romanian labels keep machine states separate');

 // A report must retain both its row membership and its correction state across requests.
 $cohort=account();$cohortRide=post($cohort,$db,'20');
 $filters=['start'=>'2000-01-01','end'=>'2099-01-01','participant'=>(string)$b['id'],'sort'=>'date','columns'=>'id,earned,earned_adjustment,net_earned,net_credit'];
 $fixed=Reports::report($admin,$filters,5000);
 $newPassenger=account();$laterRide=post($newPassenger,$db,'20');
 Loyalty::reverse($admin,$cohortRide['id'],'Correction after report snapshot',App::id());
 $sameReport=Reports::report($admin,$filters+['snapshot'=>$fixed['snapshot']],5000);
 check($fixed['rows']===$sameReport['rows']&&$fixed['totals']===$sameReport['totals'],'saved report excludes later postings and later reversals');
 $fresh=Reports::report($admin,$filters,5000);
 check($fresh['total']===$fixed['total']+1,'fresh report includes newly posted ride');
 $corrected=array_values(array_filter($fresh['rows'],fn($row)=>$row[0]===$cohortRide['id']))[0];
 check($corrected[1]==='1.00'&&$corrected[2]==='-1.00'&&$corrected[3]==='0.00'&&$corrected[4]==='0.00','financial report shows original, correction and net side by side');
 $pageOne=Reports::report($admin,$filters+['snapshot'=>$fixed['snapshot']],2,0);
 $pageTwo=Reports::report($admin,$filters+['snapshot'=>$fixed['snapshot']],2,2);
 check(array_merge($pageOne['rows'],$pageTwo['rows'])===array_slice($fixed['rows'],0,4),'report batches have stable ordering without duplicates or omissions');
 $tampered=$fixed['snapshot'];$tampered[strlen($tampered)-1]=$tampered[strlen($tampered)-1]==='a'?'b':'a';
 rejects(fn()=>Reports::report($admin,$filters+['snapshot'=>$tampered]),'INVALID_SNAPSHOT');
 rejects(fn()=>Reports::report($manager,$filters+['snapshot'=>$fixed['snapshot']]),'SNAPSHOT_SCOPE_CHANGED');
 $changed=$filters;$changed['vehicle']='different';
 rejects(fn()=>Reports::report($admin,$changed+['snapshot'=>$fixed['snapshot']]),'SNAPSHOT_SCOPE_CHANGED');

 // Wait for an in-flight lower-ID posting before capturing the committed frontier.
 $inflightPassenger=account();$inflight=preview($inflightPassenger,$db,'20');
 $pipes=[];$process=proc_open([PHP_BINARY,__DIR__.'/snapshot_worker.php',(string)$db[0]['id'],$inflight['ride_id'],App::id()],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 fclose($pipes[0]);$marker=trim((string)fgets($pipes[1]));
 check($marker==='uncommitted','snapshot test holds a real uncommitted posting');
 $started=microtime(true);$afterWait=Reports::report($admin,$filters,5000);$elapsed=microtime(true)-$started;
 $workerRest=stream_get_contents($pipes[1]);$workerError=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
 check($exit===0&&str_contains($workerRest,'committed')&&$elapsed>=0.25,'report frontier waits for outstanding financial commits');
 check((bool)array_filter($afterWait['rows'],fn($row)=>$row[0]===$inflight['ride_id']),'frontier includes the waited-for committed ride');

 // Page query boundaries: no global ledger scan or unrelated staff/support lists.
 $sessionHash=hash('sha256',App::token());Auth::$session=['token_hash'=>$sessionHash,'context_json'=>'{"role":"admin"}'];
 $oldGet=$_GET;$_GET=['role'=>'admin','section'=>'reports'];$queries=[];App::$queryObserver=function($sql)use(&$queries){$queries[]=$sql;};
 $workspace=Alba\Workspace::data($admin);App::$queryObserver=null;
 check(count($queries)<=5,'report configuration page uses at most five queries');
 check(!array_filter($queries,fn($sql)=>str_contains($sql,'FROM ledger')||str_contains($sql,'FROM support_cases')||str_contains($sql,'FROM rides')||str_contains($sql,'FROM vehicles')),'report page avoids unrelated financial, support and fleet queries');
 $_GET=['role'=>'admin','section'=>'agencies'];$queries=[];App::$queryObserver=function($sql)use(&$queries){$queries[]=$sql;};
 Alba\Workspace::data($admin);App::$queryObserver=null;
 check(!array_filter($queries,fn($sql)=>str_contains($sql,'LEFT JOIN ledger')||str_contains($sql,'FROM support_cases')),'agency navigation does not run reconciliation or support queries');
 $_GET=$oldGet;
 rejects(fn()=>Alba\Readiness::record($manager,['check'=>'hosting','status'=>'passed','notes'=>'Synthetic test']),'FORBIDDEN');
 Alba\Readiness::record($admin,['check'=>'hosting','status'=>'pending','notes'=>'Synthetic check; real provider still needs validation.']);
 check(App::scalar("SELECT status FROM acceptance_checks WHERE check_key='hosting'")==='pending','launch evidence is persisted without falsely certifying a real host');
 $diagnostics=Alba\Readiness::diagnostics();
 check((bool)array_filter($diagnostics,fn($item)=>$item['label']==='SMS real configurat'&&!$item['ok']),'readiness distinguishes the development SMS adapter from real delivery');


 $reconciliation=Loyalty::reconcile();check(!$reconciliation['differences'],'exact wallet / immutable ledger reconciliation after all tests');
 foreach(array_keys(Reports::TEMPLATES) as $template){$templateReport=Reports::report($admin,['template'=>$template,'start'=>'2000-01-01','end'=>'2099-01-01']);check(isset($templateReport['snapshot'])&&is_array($templateReport['rows']),'signed snapshot report template '.$template);}
 foreach(['participant','driver'] as $group){$grouped=Reports::report($admin,['group'=>$group,'start'=>'2000-01-01','end'=>'2099-01-01']);check(isset($grouped['totals']['net_credit']),'grouped financial net totals for '.$group);}
 echo "\n$checks checks passed.\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}

