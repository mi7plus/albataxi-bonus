<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
use Alba\App;
$snapshot=[];
App::db()->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
App::tx(function()use(&$snapshot){
 foreach(['accounts'=>'id','wallets'=>'passenger_id','participants'=>'id','memberships'=>'id','vehicles'=>'id','rules'=>'id','programme'=>'id','risk_settings'=>'id','qr_pages'=>'id','passenger_requests'=>'id','rides'=>'id','ledger'=>'id','fund_entries'=>'id','corrections'=>'id','settlements'=>'id','presets'=>'id','support_cases'=>'id','risk_flags'=>'id','scan_events'=>'id','audit_events'=>'id'] as $table=>$key){
  $hash=hash_init('sha256');$count=0;$query=App::run("SELECT * FROM $table ORDER BY $key");
  while($row=$query->fetch()){hash_update($hash,json_encode($row,JSON_THROW_ON_ERROR)."\n");$count++;}
  $snapshot[$table]=['count'=>$count,'sha256'=>hash_final($hash)];
 }
 $snapshot['reconciliation']=Alba\Loyalty::reconcile();
 $profiles=App::all('SELECT * FROM account_profiles ORDER BY account_id');$snapshot['account_profiles']=['count'=>count($profiles),'sha256'=>hash('sha256',json_encode($profiles,JSON_THROW_ON_ERROR))];
 $designs=App::all('SELECT * FROM qr_designs ORDER BY membership_id,orientation');$snapshot['qr_designs']=['count'=>count($designs),'sha256'=>hash('sha256',json_encode($designs,JSON_THROW_ON_ERROR))];
 $templates=App::all('SELECT * FROM qr_templates ORDER BY scope,owner_id,orientation');$snapshot['qr_templates']=['count'=>count($templates),'sha256'=>hash('sha256',json_encode($templates,JSON_THROW_ON_ERROR))];
 $evidence=App::all('SELECT * FROM acceptance_checks ORDER BY check_key');$snapshot['acceptance_checks']=['count'=>count($evidence),'sha256'=>hash('sha256',json_encode($evidence,JSON_THROW_ON_ERROR))];
});
echo json_encode($snapshot,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
exit($snapshot['reconciliation']['differences']?1:0);

