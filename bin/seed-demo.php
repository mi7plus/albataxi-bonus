<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){exit;}
require dirname(__DIR__).'/app/bootstrap.php';
use Alba\App;use Alba\Auth;use Alba\Commands;use Alba\Memberships;use Alba\Loyalty;
if(App::$config['environment']!=='development'){throw new RuntimeException('Development only.');}
$password=getenv('ALBA_DEMO_PASSWORD')?:'';Auth::password($password);
$phones=['admin'=>'+40700000001','manager'=>'+40700000002','driver'=>'+40700000003','passenger'=>'+40700000004'];
$users=[];
foreach($phones as $role=>$phone){
 $user=App::one('SELECT * FROM accounts WHERE phone=?',[$phone]);
 if(!$user){$user=Auth::create($phone,$password,true);App::run('UPDATE accounts SET verified=1,is_admin=? WHERE id=?',[(int)($role==='admin'),$user['id']]);}
 $users[$role]=App::one('SELECT * FROM accounts WHERE phone=?',[$phone]);
}
$admin=$users['admin'];
$p=App::one("SELECT * FROM participants WHERE name='AlbaTaxi · Agenție demonstrativă'");
if(!$p){
 $result=Commands::execute($admin,'agency-create',['name'=>'AlbaTaxi · Agenție demonstrativă','funding_terms'=>'DEMO: contribuție 5% din plata fără credit; decont lunar.','review_url'=>'https://www.google.com/maps'],'');
 $p=App::one('SELECT * FROM participants WHERE id=?',[$result['id']]);
 foreach(['manager','driver'] as $role){
  $inv=Memberships::invite($admin,(int)$p['id'],$phones[$role],$role==='driver'?'AB-01-TAX':'',$role);$result=Memberships::accept($users[$role],$inv['token']);
  if($role==='manager'){Commands::execute($admin,'membership-update',['id'=>$result['membership_id'],'active'=>true,'reason'=>'Configurare demonstrație'],'');}
 }
 Commands::execute($admin,'participant-update',['participant'=>$p['id'],'status'=>'active'],'');
 $driver=App::one("SELECT * FROM memberships WHERE account_id=? AND role='driver' AND ended_at IS NULL",[$users['driver']['id']]);
 Commands::execute($admin,'membership-update',['id'=>$driver['id'],'active'=>true,'reason'=>'Configurare demonstrație'],'');
 $qr=App::one('SELECT * FROM qr_pages WHERE membership_id=?',[$driver['id']]);
 Loyalty::ready($users['passenger'],$qr['token']);
}
foreach($phones as $role=>$phone){echo "$role: $phone\n";}echo "Password: supplied ALBA_DEMO_PASSWORD (development only)\n";

