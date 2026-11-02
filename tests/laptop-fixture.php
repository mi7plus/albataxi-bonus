<?php
require '/var/www/app/app/bootstrap.php';
use Alba\App;use Alba\Auth;use Alba\Commands;use Alba\Memberships;use Alba\Loyalty;use Alba\QrDesign;use Alba\QrTemplates;
if(getenv('COMPOSE_FIXTURE')!=='isolated-laptop-rehearsal'){throw new RuntimeException('Isolated rehearsal only.');}
$admin=App::one('SELECT * FROM accounts WHERE is_admin=1 LIMIT 1');
$agency=Commands::execute($admin,'agency-create',['name'=>'Recovery rehearsal agency','funding_terms'=>'Synthetic rehearsal terms'],'');
$invite=Memberships::invite($admin,$agency['id'],$admin['phone'],'','manager');$manager=Memberships::accept($admin,$invite['token']);
Commands::execute($admin,'membership-update',['id'=>$manager['membership_id'],'active'=>true,'reason'=>'Rehearsal'],'');
Commands::execute($admin,'participant-update',['participant'=>$agency['id'],'status'=>'active'],'');
$driver=Auth::create('0733000001','Synthetic-driver-password',true);App::run('UPDATE accounts SET verified=1 WHERE id=?',[$driver['id']]);$driver['verified']=1;
$invite=Memberships::invite($admin,$agency['id'],$driver['phone'],'AB-99-TST','driver');$joined=Memberships::accept($driver,$invite['token']);
Commands::execute($admin,'membership-update',['id'=>$joined['membership_id'],'active'=>true,'reason'=>'Rehearsal'],'');
$qr=App::one('SELECT * FROM qr_pages WHERE membership_id=?',[$joined['membership_id']]);
$passenger=Auth::create('0733000002','Synthetic-passenger-password',true,false,'Recovery Test','1990-01-02');App::run('UPDATE accounts SET verified=1 WHERE id=?',[$passenger['id']]);$passenger['verified']=1;
$ready=Loyalty::ready($passenger,$qr['token']);$ride=Loyalty::preview($driver,$ready['code'],'20',App::id());Loyalty::post($driver,$ride['ride_id'],App::id());
$design=QrDesign::defaults('portrait');$design['background']='#ddeeff';QrTemplates::save($driver,['scope'=>'driver','owner'=>$joined['membership_id'],'orientation'=>'portrait','revision'=>0,'design'=>json_encode($design)]);
QrTemplates::review($admin,['scope'=>'driver','owner'=>$joined['membership_id'],'orientation'=>'portrait','revision'=>1,'decision'=>'approve']);
if((int)App::scalar('SELECT balance FROM wallets WHERE passenger_id=?',[$passenger['id']])!==100){throw new RuntimeException('Fixture wallet mismatch.');}
echo "Synthetic ride, wallet credit, profile and approved QR template created.\n";
