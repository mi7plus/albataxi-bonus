<?php
declare(strict_types=1);
namespace Alba;
final class Commands {
 public static function execute(array $u,string $action,array $d,string $key):array{
  if(App::$config['maintenance']){App::fail('MAINTENANCE','Programul este în mentenanță. Reîncercați mai târziu.',503);}
  return match($action){
   'ready'=>Loyalty::ready($u,(string)($d['token']??'')),
   'preview'=>Loyalty::preview($u,(string)($d['code']??''),$d['fare']??'',$key),
   'approve'=>Loyalty::approve($u,(string)($d['ride_id']??''),$d['amount']??'0',($d['decision']??'')!=='decline'),
   'post'=>Loyalty::post($u,(string)($d['ride_id']??''),$key),
   'reverse'=>Loyalty::reverse($u,(string)($d['ride_id']??''),(string)($d['reason']??''),$key),
   'rules'=>['rule_id'=>Memberships::rules($u,(int)($d['participant']??0),$d)],
   'invite'=>self::invite($u,$d),
   'import-preview'=>Memberships::previewImport($u,(int)($d['participant']??0),(string)($d['csv']??'')),
   'import-confirm'=>Memberships::confirmImport($u,(string)($d['id']??''),App::bool($d['partial']??false)),
   'agency-create'=>self::createAgency($u,$d),
   'participant-update'=>self::participant($u,$d),
   'membership-update'=>self::membership($u,$d),
   'vehicle'=>self::vehicle($u,$d),
   'independent'=>self::independent($u,$d),
   'cancel'=>self::cancel($u,$d),
   'qr-revoke'=>self::qr($u,$d),
   'controls'=>self::controls($u,$d),
   'fund'=>self::fund($u,$d,$key),
   'settlement-create'=>self::settlementCreate($u,$d,$key),
   'settlement-update'=>self::settlementUpdate($u,$d,$key),
   'support'=>self::support($u,$d),
   'support-resolve'=>self::resolveSupport($u,$d),
   'risk-resolve'=>self::resolveRisk($u,$d),
   'preset'=>self::preset($u,$d),
   'revoke-invitation'=>self::revokeInvitation($u,$d),
   'maintenance-cleanup'=>self::cleanup($u),
   'readiness-check'=>Readiness::record($u,$d),
   'test-sms'=>Readiness::sms($u,$d,$key),
   'qr-design'=>QrTemplates::save($u,$d),
   'qr-template-review'=>QrTemplates::review($u,$d),
   default=>throw new Problem('NOT_FOUND','Operațiune necunoscută.',404)
  };
 }
 private static function invite(array $u,array $d):array{
  $i=Memberships::invite($u,(int)($d['participant']??0),(string)($d['phone']??''),(string)($d['plate']??''),(string)($d['role']??'driver'));
  return ['id'=>$i['id'],'url'=>App::url('?page=invitation&token='.$i['token'])];
 }
 private static function createAgency(array $u,array $d):array{
  App::admin($u);App::fields($d,['name']);
  return App::tx(function()use($u,$d){
   $id=App::insert('participants',['name'=>mb_substr(trim($d['name']),0,160),'kind'=>'agency','funding_terms'=>$d['funding_terms']??'','review_url'=>Memberships::reviewUrl($d['review_url']??''),'created_at'=>App::now()]);
   Memberships::rules($u,$id,$d);App::audit((int)$u['id'],'agency.created',$id);return ['id'=>$id];
  });
 }
 private static function participant(array $u,array $d):array{
  $id=(int)($d['participant']??0);Scope::managed($u,$id);
  return App::tx(function()use($u,$d,$id){
   $p=App::one('SELECT * FROM participants WHERE id=? FOR UPDATE',[$id]);Scope::managed($u,$id);
   $url=Memberships::reviewUrl($d['review_url']??$p['review_url']);$status=$p['status'];$terms=$p['funding_terms'];
   if($u['is_admin']){
    $status=$d['status']??$status;$terms=$d['funding_terms']??$terms;
    if(!in_array($status,['pending','active','suspended','closed'],true)){App::fail('INVALID_STATE','Stare invalidă.');}
    if($status==='active'){
     if(!$terms||!App::one('SELECT id FROM rules WHERE participant_id=?',[$id])){App::fail('SETUP_INCOMPLETE','Completați regulile și termenii fondului.');}
     if($p['kind']==='agency'&&!App::one("SELECT id FROM memberships WHERE participant_id=? AND role='manager' AND accepted_at IS NOT NULL AND ended_at IS NULL",[$id])){App::fail('MANAGER_REQUIRED','Managerul trebuie să accepte invitația.');}
    }
    if(in_array($status,['suspended','closed'],true)&&empty(trim($d['reason']??''))){App::fail('REASON_REQUIRED','Motivați suspendarea sau închiderea.');}
   }elseif(isset($d['status'])||isset($d['funding_terms'])){App::fail('FORBIDDEN','Doar administratorul schimbă participarea și termenii.',403);}
   App::run('UPDATE participants SET status=?,funding_terms=?,review_url=? WHERE id=?',[$status,$terms,$url,$id]);
   App::audit((int)$u['id'],'participant.updated',$id,['status'=>$status,'reason'=>$d['reason']??'']);return ['id'=>$id];
  });
 }
 private static function membership(array $u,array $d):array{
  App::fields($d,['reason']);$id=(int)($d['id']??0);
  return App::tx(function()use($u,$d,$id){
   $m=App::one('SELECT * FROM memberships WHERE id=?',[$id]);if(!$m){App::fail('NOT_FOUND','Afiliere inexistentă.',404);}
   App::one('SELECT id FROM participants WHERE id=? FOR UPDATE',[$m['participant_id']]);Scope::managed($u,(int)$m['participant_id']);
   $m=App::one('SELECT * FROM memberships WHERE id=? FOR UPDATE',[$id]);
   if($m['role']==='manager'&&!$u['is_admin']){App::fail('FORBIDDEN','Doar administratorul modifică managerii.',403);}
   if($m['admin_suspended']&&!$u['is_admin']){App::fail('ADMIN_SUSPENSION','Suspendare impusă de administrator.',403);}
   $active=App::bool($d['active']??false);
   if($active&&(!$m['accepted_at']||$m['ended_at'])){App::fail('ACCEPTANCE_REQUIRED','Afilierea trebuie acceptată și curentă.');}
   if($active&&$m['role']==='driver'){
    $p=App::one('SELECT * FROM participants WHERE id=?',[$m['participant_id']]);$a=App::one('SELECT verified,active FROM accounts WHERE id=?',[$m['account_id']]);
    if($p['status']!=='active'||!$p['funding_terms']||!$a['verified']||!$a['active']||!App::one('SELECT id FROM rules WHERE participant_id=?',[$p['id']])||!App::one("SELECT id FROM vehicles WHERE membership_id=? AND eligible=1 AND plate<>''",[$id])){App::fail('SETUP_INCOMPLETE','Verificați participantul, regulile, telefonul și vehiculul.');}
   }
   $suspended=$u['is_admin']?(int)!$active:(int)$m['admin_suspended'];
   App::run('UPDATE memberships SET active=?,admin_suspended=? WHERE id=?',[(int)$active,$suspended,$id]);App::audit((int)$u['id'],'membership.updated',$id,['active'=>$active,'reason'=>$d['reason']]);return ['id'=>$id];
  });
 }
 private static function vehicle(array $u,array $d):array{
  App::fields($d,['driver','plate']);$id=(int)$d['driver'];$plate=strtoupper(trim($d['plate']));
  if(strlen($plate)>20){App::fail('INVALID_PLATE','Număr de înmatriculare prea lung.');}
  return App::tx(function()use($u,$d,$id,$plate){
   $m=App::one("SELECT * FROM memberships WHERE id=? AND role='driver' AND ended_at IS NULL",[$id]);if(!$m){App::fail('NOT_FOUND','Șofer inexistent.',404);}
   App::one('SELECT id FROM participants WHERE id=? FOR UPDATE',[$m['participant_id']]);Scope::managed($u,(int)$m['participant_id']);
   App::run('INSERT INTO vehicles(membership_id,participant_id,plate,eligible) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE plate=VALUES(plate),eligible=VALUES(eligible)',[$id,$m['participant_id'],$plate,(int)App::bool($d['eligible']??false)]);
   App::audit((int)$u['id'],'vehicle.updated',$id,['plate'=>$plate]);return ['id'=>$id];
  });
 }
 private static function independent(array $u,array $d):array{
  App::fields($d,['name','plate']);if(strlen($d['plate'])>20){App::fail('INVALID_PLATE','Număr de înmatriculare prea lung.');}
  return App::tx(function()use($u,$d){
   App::one('SELECT id FROM accounts WHERE id=? FOR UPDATE',[$u['id']]);
   if(App::one("SELECT id FROM memberships WHERE account_id=? AND role='driver' AND ended_at IS NULL",[$u['id']])){App::fail('EXISTING_MEMBERSHIP','Aveți deja o afiliere de șofer.',409);}
   $p=App::insert('participants',['name'=>mb_substr($d['name'],0,160),'kind'=>'independent','funding_terms'=>'','review_url'=>Memberships::reviewUrl($d['review_url']??''),'created_at'=>App::now()]);
   $m=App::insert('memberships',['participant_id'=>$p,'account_id'=>$u['id'],'role'=>'driver','accepted_at'=>App::now()]);
   App::insert('vehicles',['membership_id'=>$m,'participant_id'=>$p,'plate'=>strtoupper($d['plate'])]);
   App::insert('qr_pages',['membership_id'=>$m,'token'=>App::token(),'display_token'=>App::token()]);
   App::audit((int)$u['id'],'independent.applied',$p);return ['id'=>$p];
  });
 }
 private static function cancel(array $u,array $d):array{
  return App::tx(function()use($u,$d){
   $r=Scope::ride($u,(string)($d['ride_id']??''));$r=App::one('SELECT r.*,m.account_id driver_account FROM rides r JOIN memberships m ON m.id=r.driver_id WHERE r.id=? FOR UPDATE',[$r['id']]);
   if((int)$r['passenger_id']!==(int)$u['id']&&(int)$r['driver_account']!==(int)$u['id']){App::fail('FORBIDDEN','Doar pasagerul sau șoferul anulează confirmarea.',403);}
   if(!in_array($r['status'],['draft','ready'],true)){App::fail('INVALID_STATE','Cursa nu poate fi anulată.',409);}
   App::run("UPDATE rides SET status='cancelled',approval='revoked' WHERE id=?",[$r['id']]);App::run("UPDATE passenger_requests SET status='cancelled' WHERE id=?",[$r['request_id']]);App::audit((int)$u['id'],'ride.cancelled',$r['id']);return ['status'=>'cancelled'];
  });
 }
 private static function qr(array $u,array $d):array{
  $m=App::one('SELECT * FROM memberships WHERE id=?',[(int)($d['driver']??0)]);if(!$m){App::fail('NOT_FOUND','Șofer inexistent.',404);}
  if((int)$m['account_id']!==(int)$u['id']){Scope::managed($u,(int)$m['participant_id']);}
  App::run('UPDATE qr_pages SET token=?,display_token=? WHERE membership_id=?',[App::token(),App::token(),$m['id']]);App::audit((int)$u['id'],'qr.reissued',$m['id']);return ['ok'=>true];
 }
 private static function controls(array $u,array $d):array{
  App::admin($u);return App::tx(function()use($u,$d){
   $earn=App::bool($d['earning_paused']??false);$redeem=App::bool($d['redemption_paused']??false);
   $risk=App::one('SELECT * FROM risk_settings WHERE id=1');$riskValues=[];
   foreach(['pair_threshold','frequency_seconds','fare_repeat_threshold','daily_award_bani'] as $field){$value=filter_var($d[$field]??$risk[$field],FILTER_VALIDATE_INT);if($value===false||$value<0||$value>10000000){App::fail('INVALID_LIMIT','Pragurile trebuie să fie numere întregi pozitive sau zero (dezactivat).');}$riskValues[]=$value;}
   App::run('UPDATE risk_settings SET pair_threshold=?,frequency_seconds=?,fare_repeat_threshold=?,daily_award_bani=? WHERE id=1',$riskValues);
   App::run('UPDATE programme SET earning_paused=?,redemption_paused=? WHERE id=1',[(int)$earn,(int)$redeem]);App::audit((int)$u['id'],'programme.controls',1,compact('earn','redeem','riskValues'));return ['ok'=>true];
  });
 }
 private static function fund(array $u,array $d,string $key):array{
  App::admin($u);App::fields($d,['participant','kind','amount','reference']);
  if(!in_array($d['kind'],['contribution_received','reimbursement_paid'],true)){App::fail('INVALID_FUND_ENTRY','Tip invalid.');}
  $amount=App::money($d['amount']);if($amount<1){App::fail('INVALID_AMOUNT','Suma trebuie să fie mai mare de zero.');}
  return Loyalty::operation($u,'fund',$key,$d,function()use($u,$d,$amount){
   Scope::managed($u,(int)$d['participant']);App::one('SELECT id FROM participants WHERE id=? FOR UPDATE',[(int)$d['participant']]);
   if($d['kind']==='reimbursement_paid'){
    if(App::one("SELECT id FROM settlements WHERE participant_id=? AND status<>'paid'",[(int)$d['participant']])){App::fail('STATEMENT_PAYMENT_REQUIRED','Înregistrați plata în decontul deschis al participantului.',409);}
    self::checkOutstanding((int)$d['participant'],$amount);
   }
   $id=Loyalty::fund((int)$u['id'],(int)$d['participant'],$d['kind'],$amount,mb_substr($d['reference'],0,255));
   App::audit((int)$u['id'],'fund.recorded',$id,['amount'=>$amount,'kind'=>$d['kind']]);return ['id'=>$id];
  });
 }
 private static function settlementCreate(array $u,array $d,string $key):array{
  App::admin($u);$date=$d['period_end']??'';$parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
  if(!$parsed||$parsed->format('Y-m-d')!==$date){App::fail('INVALID_DATE','Dată invalidă.');}
  return Loyalty::operation($u,'settlement-create',$key,$d,function()use($u,$d,$date){
   $p=Scope::managed($u,(int)($d['participant']??0));App::one('SELECT id FROM participants WHERE id=? FOR UPDATE',[$p['id']]);
   $end=(new \DateTimeImmutable($date,new \DateTimeZone('Europe/Bucharest')))->modify('+1 day')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
   $entries=App::all('SELECT id,kind,amount FROM fund_entries WHERE participant_id=? AND settlement_id IS NULL AND created_at<? FOR UPDATE',[$p['id'],$end]);
   $totals=[];foreach($entries as $e){$totals[$e['kind']]=($totals[$e['kind']]??0)+(int)$e['amount'];}
   if(!$entries){App::fail('EMPTY_SETTLEMENT','Nu există înregistrări nedecontate în această perioadă.');}
   $id=App::insert('settlements',['participant_id'=>$p['id'],'period_end'=>$date,'snapshot'=>json_encode($totals),'created_at'=>App::now()]);
   App::run('UPDATE fund_entries SET settlement_id=? WHERE participant_id=? AND settlement_id IS NULL AND created_at<?',[$id,$p['id'],$end]);
   App::audit((int)$u['id'],'settlement.created',$id,['snapshot'=>$totals]);return ['id'=>$id];
  });
 }
 private static function settlementUpdate(array $u,array $d,string $key):array{
  App::admin($u);
  return Loyalty::operation($u,'settlement-update',$key,$d,function()use($u,$d){
   $s=App::one('SELECT * FROM settlements WHERE id=? FOR UPDATE',[(int)($d['id']??0)]);if(!$s){App::fail('NOT_FOUND','Decont inexistent.',404);}
   $status=$d['status']??'';$next=['draft'=>['under_review'],'under_review'=>['approved'],'approved'=>['partially_paid','paid'],'partially_paid'=>['partially_paid','paid']];
   if(!in_array($status,$next[$s['status']]??[],true)){App::fail('INVALID_STATE','Tranziție de decont invalidă.',409);}
   $paid=(int)$s['paid'];$reference=$d['reference']??$s['reference'];
   if(in_array($status,['partially_paid','paid'],true)){
    App::one('SELECT id FROM participants WHERE id=? FOR UPDATE',[$s['participant_id']]);
    $amount=App::money($d['amount']??'');$totals=json_decode($s['snapshot'],true);$due=max(0,($totals['reimbursement_due']??0)-($totals['reimbursement_paid']??0));
    if($amount<1||$paid+$amount>$due||!trim($reference)){App::fail('INVALID_PAYMENT','Verificați suma și referința.');}
    self::checkOutstanding((int)$s['participant_id'],$amount);
    $paid+=$amount;$status=$paid===$due?'paid':'partially_paid';
    // Payment is already attributed to this statement; never pick it up again in a later statement.
    Loyalty::fund((int)$u['id'],(int)$s['participant_id'],'reimbursement_paid',$amount,$reference,null,(int)$s['id']);
   }
   App::run('UPDATE settlements SET status=?,paid=?,reference=? WHERE id=?',[$status,$paid,mb_substr($reference,0,255),$s['id']]);App::audit((int)$u['id'],'settlement.updated',$s['id'],['status'=>$status]);return ['id'=>$s['id'],'status'=>$status];
  });
 }
 private static function checkOutstanding(int $participant,int $amount):void{
  $available=(int)App::scalar("SELECT COALESCE(SUM(CASE WHEN kind='reimbursement_due' THEN amount WHEN kind='reimbursement_paid' THEN -amount ELSE 0 END),0) FROM fund_entries WHERE participant_id=?",[$participant]);
  if($amount>$available){App::fail('PAYMENT_EXCEEDS_DUE','Plata depășește rambursarea restantă. Verificați corecțiile și plățile existente.',409);}
 }
 private static function support(array $u,array $d):array{
  App::fields($d,['message']);if(mb_strlen($d['message'])<5){App::fail('MESSAGE_REQUIRED','Descrieți problema în minimum 5 caractere.');}
  $ride=empty($d['ride_id'])?null:Scope::ride($u,(string)$d['ride_id']);
  $id=App::insert('support_cases',['owner_id'=>$u['id'],'ride_id'=>$ride['id']??null,'message'=>mb_substr($d['message'],0,5000),'resolution'=>'','created_at'=>App::now()]);App::audit((int)$u['id'],'support.created',$id);return ['id'=>$id];
 }
 private static function resolveSupport(array $u,array $d):array{
  App::admin($u);App::fields($d,['id','resolution']);
  App::run("UPDATE support_cases SET status='resolved',resolution=? WHERE id=?",[mb_substr($d['resolution'],0,5000),(int)$d['id']]);App::audit((int)$u['id'],'support.resolved',$d['id']);return ['ok'=>true];
 }
 private static function resolveRisk(array $u,array $d):array{
  App::admin($u);App::fields($d,['id','reason']);App::run('UPDATE risk_flags SET resolved=1 WHERE id=?',[(int)$d['id']]);App::audit((int)$u['id'],'risk.resolved',$d['id'],['reason'=>$d['reason']]);return ['ok'=>true];
 }
 private static function preset(array $u,array $d):array{
  App::fields($d,['name']);$filters=Reports::filters($d);Reports::report($u,$filters,1);
  return ['id'=>App::insert('presets',['owner_id'=>$u['id'],'name'=>mb_substr($d['name'],0,100),'filters'=>json_encode($filters)])];
 }
 private static function revokeInvitation(array $u,array $d):array{
  $i=App::one('SELECT * FROM invitations WHERE id=?',[(int)($d['id']??0)]);if(!$i){App::fail('NOT_FOUND','Invitație inexistentă.',404);}Scope::managed($u,(int)$i['participant_id']);
  App::run("UPDATE invitations SET status='revoked' WHERE id=?",[$i['id']]);App::audit((int)$u['id'],'invitation.revoked',$i['id']);return ['ok'=>true];
 }
 private static function cleanup(array $u):array{
  App::admin($u);
  $sessions=App::run('DELETE FROM sessions WHERE expires_at<?',[App::now()])->rowCount();
  App::run('DELETE FROM throttles WHERE started_at<?',[App::after(-86400)]);
  App::run('DELETE FROM otps WHERE expires_at<?',[App::after(-86400)]);
  App::audit((int)$u['id'],'maintenance.cleaned','expired',['sessions'=>$sessions]);return ['sessions_removed'=>$sessions];
 }
}
