<?php
declare(strict_types=1);
namespace Alba;

/** All financial commands use InnoDB transactions and integer bani. */
final class Loyalty {
 public static function operation(array $u,string $kind,string $key,array $payload,callable $work):array{
  if(!$key||strlen($key)>100){App::fail('IDEMPOTENCY_REQUIRED','Este necesară cheia operațiunii.');}
  return App::tx(function()use($u,$kind,$key,$payload,$work){
   App::one('SELECT id FROM programme WHERE id=1 LOCK IN SHARE MODE');
   App::one('SELECT id FROM accounts WHERE id=? FOR UPDATE',[$u['id']]);
   ksort($payload);$fingerprint=hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR));
   $existing=App::one('SELECT * FROM operations WHERE actor_id=? AND kind=? AND operation_key=?',[$u['id'],$kind,$key]);
   if($existing){if(!hash_equals($existing['fingerprint'],$fingerprint)){App::fail('IDEMPOTENCY_CONFLICT','Cheia a fost folosită cu alte date.',409);}return json_decode($existing['result'],true);}
   $result=$work();
   App::insert('operations',['actor_id'=>$u['id'],'kind'=>$kind,'operation_key'=>$key,'fingerprint'=>$fingerprint,'result'=>json_encode($result,JSON_THROW_ON_ERROR)]);
   return $result;
  });
 }
 public static function driver(int $id,bool $lock=false):array{
  $m=App::one("SELECT m.*,a.verified,a.active account_active,p.status participant_status,p.funding_terms,p.name participant_name FROM memberships m JOIN accounts a ON a.id=m.account_id JOIN participants p ON p.id=m.participant_id WHERE m.id=? AND m.role='driver'".($lock?' FOR UPDATE':''),[$id]);
  if(!$m||!$m['active']||$m['admin_suspended']||$m['ended_at']||!$m['accepted_at']||$m['participant_status']!=='active'){App::fail('PARTICIPANT_SUSPENDED','Șoferul sau participantul nu este activ.',409);}
  if(!$m['verified']||!$m['account_active']){App::fail('PHONE_UNVERIFIED','Telefonul șoferului trebuie verificat.',409);}
  $v=App::one('SELECT * FROM vehicles WHERE membership_id=? AND participant_id=? AND eligible=1',[$id,$m['participant_id']]);
  $r=App::one('SELECT * FROM rules WHERE participant_id=? ORDER BY id DESC LIMIT 1',[$m['participant_id']]);
  if(!$v||!$v['plate']||!$r||!$m['funding_terms']){App::fail('SETUP_INCOMPLETE','Lipsesc vehiculul, regulile sau termenii fondului.',409);}
  return [$m,$v,$r];
 }
 public static function event(int $driver,string $kind,array $payload=[]):void{
  App::insert('scan_events',['driver_id'=>$driver,'kind'=>$kind,'payload'=>json_encode($payload),'created_at'=>App::now()]);
 }
 public static function ready(array $u,string $token):array{
  if(!$u['verified']){App::fail('PHONE_UNVERIFIED','Verificați telefonul.',403);}
  return App::tx(function()use($u,$token){
   App::one('SELECT id FROM accounts WHERE id=? FOR UPDATE',[$u['id']]);
   $qr=App::one('SELECT * FROM qr_pages WHERE token=?',[$token]);if(!$qr){App::fail('QR_REVOKED','Codul QR nu mai este disponibil.',404);}
   self::driver((int)$qr['membership_id']);
   $existing=App::one("SELECT * FROM passenger_requests WHERE passenger_id=? AND driver_id=? AND status IN ('ready','claimed') AND expires_at>? ORDER BY created_at DESC LIMIT 1",[$u['id'],$qr['membership_id'],App::now()]);
   if($existing){return $existing;}
   $id=App::id();$code=strtoupper(bin2hex(random_bytes(4)));
   App::insert('passenger_requests',['id'=>$id,'passenger_id'=>$u['id'],'driver_id'=>$qr['membership_id'],'code'=>$code,'created_at'=>App::now(),'expires_at'=>App::after(900)]);
   self::event((int)$qr['membership_id'],'passenger_ready',['code'=>$code,'suffix'=>substr($u['phone'],-4)]);
   return App::one('SELECT * FROM passenger_requests WHERE id=?',[$id]);
  });
 }
 public static function preview(array $u,string $code,mixed $input,string $key):array{
  $fare=App::money($input);$code=strtoupper(trim($code));
  return self::operation($u,'preview',$key,compact('fare','code'),function()use($u,$fare,$code){
   $m=App::one("SELECT * FROM memberships WHERE account_id=? AND role='driver' AND ended_at IS NULL",[$u['id']]);
   if(!$m){App::fail('FORBIDDEN','Doar șoferii pot confirma curse.',403);}
   App::one('SELECT id FROM participants WHERE id=? FOR UPDATE',[$m['participant_id']]);
   [$m,$v,$rule]=self::driver((int)$m['id'],true);
   $req=App::one('SELECT * FROM passenger_requests WHERE code=? AND driver_id=? FOR UPDATE',[$code,$m['id']]);
   if(!$req||$req['status']!=='ready'||$req['expires_at']<=App::now()){App::fail('REQUEST_UNAVAILABLE','Cererea este expirată sau deja preluată.',409);}
   if($fare<1||$fare>$rule['max_fare']){App::fail('INVALID_FARE','Tariful depășește limitele programului.');}
   $id=App::id();
   App::insert('rides',['id'=>$id,'request_id'=>$req['id'],'passenger_id'=>$req['passenger_id'],'driver_id'=>$m['id'],'participant_id'=>$m['participant_id'],'vehicle_plate'=>$v['plate'],'rule_id'=>$rule['id'],'fare'=>$fare,'expires_at'=>App::after(300),'created_at'=>App::now()]);
   App::run("UPDATE passenger_requests SET status='claimed' WHERE id=?",[$req['id']]);
   App::audit((int)$u['id'],'ride.preview',$id,['rule'=>$rule['id']]);
   return ['ride_id'=>$id,'redirect'=>'?page=ride&id='.$id];
  });
 }
 public static function approve(array $u,string $id,mixed $amount,bool $approve=true):array{
  $redemption=App::money($amount);
  return App::tx(function()use($u,$id,$redemption,$approve){
   App::one('SELECT id FROM accounts WHERE id=? FOR UPDATE',[$u['id']]);
   $ride=App::one('SELECT * FROM rides WHERE id=? AND passenger_id=? FOR UPDATE',[$id,$u['id']]);
   if(!$ride){App::fail('NOT_FOUND','Cursa nu a fost găsită.',404);}
   self::liveDraft($ride);
   if(!$approve){App::run("UPDATE rides SET status='cancelled',approval='declined' WHERE id=?",[$id]);App::run("UPDATE passenger_requests SET status='cancelled' WHERE id=?",[$ride['request_id']]);return ['status'=>'cancelled'];}
   self::driver((int)$ride['driver_id']);
   $rule=App::one('SELECT * FROM rules WHERE id=?',[$ride['rule_id']]);
   $wallet=App::one('SELECT * FROM wallets WHERE passenger_id=? FOR UPDATE',[$u['id']]);
   if($redemption>min((int)$wallet['balance'],intdiv((int)$ride['fare']*(int)$rule['cap_bps'],10000))){App::fail('INSUFFICIENT_CREDIT','Suma depășește soldul sau limita cursei.',409);}
   $controls=App::one('SELECT * FROM programme WHERE id=1 LOCK IN SHARE MODE');
   if($redemption&&$controls['redemption_paused']){App::fail('REDEMPTION_PAUSED','Folosirea creditului este temporar oprită.',409);}
   App::run("UPDATE rides SET redeemed=?,approval_balance=?,approval='approved',status='ready' WHERE id=?",[$redemption,$wallet['balance'],$id]);
   App::audit((int)$u['id'],'redemption.approved',$id,['amount'=>$redemption]);self::event((int)$ride['driver_id'],'approval_ready',['ride_id'=>$id]);
   return ['ride_id'=>$id,'status'=>'ready'];
  });
 }
 private static function liveDraft(array $r):void{
  if(!in_array($r['status'],['draft','ready'],true)||$r['expires_at']<=App::now()){App::fail('APPROVAL_EXPIRED','Confirmarea a expirat. Reluați cererea.',409);}
 }
 public static function post(array $u,string $id,string $key):array{
  return self::operation($u,'post',$key,['ride_id'=>$id],function()use($u,$id){
   $candidate=App::one('SELECT r.* FROM rides r JOIN memberships m ON m.id=r.driver_id WHERE r.id=? AND m.account_id=?',[$id,$u['id']]);
   if(!$candidate){App::fail('NOT_FOUND','Cursa nu a fost găsită.',404);}
   App::one('SELECT id FROM participants WHERE id=? FOR UPDATE',[$candidate['participant_id']]);
   [$m,$vehicle]=self::driver((int)$candidate['driver_id'],true);
   $r=App::one('SELECT * FROM rides WHERE id=? FOR UPDATE',[$id]);
   if($r['status']==='posted'){return ['ride_id'=>$id,'status'=>'posted'];}
   self::liveDraft($r);
   if($vehicle['plate']!==$r['vehicle_plate']||(int)$m['participant_id']!==(int)$r['participant_id']){App::fail('ASSIGNMENT_CHANGED','Alocarea s-a schimbat. Reluați cererea.',409);}
   $w=App::one('SELECT * FROM wallets WHERE passenger_id=? FOR UPDATE',[$r['passenger_id']]);
   $rule=App::one('SELECT * FROM rules WHERE id=?',[$r['rule_id']]);
   if($r['redeemed']){
    if($r['approval']!=='approved'){App::fail('APPROVAL_REQUIRED','Pasagerul trebuie să aprobe creditul.',409);}
    if((int)$w['balance']!==(int)$r['approval_balance']){App::fail('BALANCE_CHANGED','Soldul s-a schimbat. Pasagerul trebuie să aprobe din nou.',409);}
    if($r['redeemed']>min((int)$w['balance'],intdiv((int)$r['fare']*(int)$rule['cap_bps'],10000))){App::fail('INSUFFICIENT_CREDIT','Credit insuficient.',409);}
   }
   $controls=App::one('SELECT * FROM programme WHERE id=1 LOCK IN SHARE MODE');
   if($r['redeemed']&&$controls['redemption_paused']){App::fail('REDEMPTION_PAUSED','Folosirea creditului este temporar oprită.',409);}
   $local=new \DateTimeImmutable('today',new \DateTimeZone('Europe/Bucharest'));
   $start=$local->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
   $end=$local->modify('+1 day')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
   $count=(int)App::scalar("SELECT COUNT(*) FROM rides WHERE passenger_id=? AND status='posted' AND posted_at>=? AND posted_at<?",[$r['passenger_id'],$start,$end]);
   if($count>=$rule['daily_limit']){App::fail('DAILY_LIMIT','Limita zilnică a fost atinsă. Contactați suportul.',409);}
   $paid=(int)$r['fare']-(int)$r['redeemed'];
   $earned=$controls['earning_paused']?0:intdiv($paid*(int)$rule['earn_bps'],10000);
   $contribution=intdiv($paid*(int)$rule['contribution_bps'],10000);
   $balance=(int)$w['balance']+$earned-(int)$r['redeemed'];
   App::run('UPDATE wallets SET balance=? WHERE passenger_id=?',[$balance,$r['passenger_id']]);
   App::insert('ledger',['passenger_id'=>$r['passenger_id'],'ride_id'=>$id,'kind'=>'earn','amount'=>$earned,'created_at'=>App::now()]);
   if($r['redeemed']){
    App::insert('ledger',['passenger_id'=>$r['passenger_id'],'ride_id'=>$id,'kind'=>'redeem','amount'=>-(int)$r['redeemed'],'created_at'=>App::now()]);
    self::fund((int)$u['id'],(int)$r['participant_id'],'reimbursement_due',(int)$r['redeemed'],'',$id);
   }
   self::fund((int)$u['id'],(int)$r['participant_id'],'contribution_due',$contribution,'',$id);
   App::run("UPDATE rides SET earned=?,contribution=?,status='posted',approval='consumed',posted_at=? WHERE id=?",[$earned,$contribution,App::now(),$id]);
   App::run("UPDATE passenger_requests SET status='completed' WHERE id=?",[$r['request_id']]);
   $pair=(int)App::scalar("SELECT COUNT(*) FROM rides WHERE passenger_id=? AND driver_id=? AND status='posted' AND posted_at>=? AND posted_at<?",[$r['passenger_id'],$r['driver_id'],$start,$end]);
   $risk=App::one('SELECT * FROM risk_settings WHERE id=1');$reasons=[];
   if($risk['pair_threshold']>0&&$pair>=$risk['pair_threshold']){$reasons[]='Pereche șofer/pasager repetată peste pragul configurat.';}
   if($risk['frequency_seconds']>0&&(int)App::scalar("SELECT COUNT(*) FROM rides WHERE driver_id=? AND status='posted' AND posted_at>=?",[$r['driver_id'],App::after(-(int)$risk['frequency_seconds'])])>1){$reasons[]='Confirmări prea apropiate în timp: verificați cursele.';}
   if($risk['fare_repeat_threshold']>0&&(int)App::scalar("SELECT COUNT(*) FROM rides WHERE driver_id=? AND fare=? AND status='posted' AND posted_at>=? AND posted_at<?",[$r['driver_id'],$r['fare'],$start,$end])>=$risk['fare_repeat_threshold']){$reasons[]='Tarif repetat peste pragul configurat.';}
   if($risk['daily_award_bani']>0&&(int)App::scalar("SELECT COALESCE(SUM(earned),0) FROM rides WHERE passenger_id=? AND status='posted' AND posted_at>=? AND posted_at<?",[$r['passenger_id'],$start,$end])>=$risk['daily_award_bani']){$reasons[]='Credit zilnic peste pragul de alertare.';}
   foreach($reasons as $reason){App::insert('risk_flags',['ride_id'=>$id,'reason'=>$reason,'created_at'=>App::now()]);}
   self::event((int)$r['driver_id'],'ride_posted',['ride_id'=>$id]);
   App::audit((int)$u['id'],'ride.posted',$id,['earned'=>$earned,'redeemed'=>$r['redeemed'],'rule'=>$rule['id']]);
   return ['ride_id'=>$id,'status'=>'posted','balance'=>$balance];
  });
 }
 public static function fund(int $actor,int $participant,string $kind,int $amount,string $reference='',?string $ride=null,?int $settlement=null):int{
  return App::insert('fund_entries',['participant_id'=>$participant,'ride_id'=>$ride,'kind'=>$kind,'amount'=>$amount,'reference'=>$reference,'settlement_id'=>$settlement,'actor_id'=>$actor,'created_at'=>App::now()]);
 }
 public static function reverse(array $u,string $id,string $reason,string $key):array{
  App::admin($u);if(mb_strlen(trim($reason))<5){App::fail('REASON_REQUIRED','Introduceți motivul corecției.');}
  return self::operation($u,'reverse',$key,compact('id','reason'),function()use($u,$id,$reason){
   $candidate=App::one('SELECT * FROM rides WHERE id=?',[$id]);if(!$candidate){App::fail('NOT_FOUND','Cursa nu a fost găsită.',404);}
   App::one('SELECT id FROM participants WHERE id=? FOR UPDATE',[$candidate['participant_id']]);
   $r=App::one('SELECT * FROM rides WHERE id=? FOR UPDATE',[$id]);
   if($r['status']!=='posted'||App::one('SELECT id FROM corrections WHERE ride_id=?',[$id])){App::fail('ALREADY_CORRECTED','Cursa nu este înregistrată sau corecția există deja.',409);}
   $w=App::one('SELECT * FROM wallets WHERE passenger_id=? FOR UPDATE',[$r['passenger_id']]);
   $delta=(int)$r['redeemed']-(int)$r['earned'];$status=(int)$w['balance']+$delta<0?'manual_recovery':'posted';
   App::insert('corrections',['ride_id'=>$id,'reason'=>$reason,'actor_id'=>$u['id'],'status'=>$status,'created_at'=>App::now()]);
   if($status==='manual_recovery'){App::insert('risk_flags',['ride_id'=>$id,'reason'=>'Credit deja cheltuit: recuperare manuală necesară.','created_at'=>App::now()]);}
   else{
    App::run('UPDATE wallets SET balance=balance+? WHERE passenger_id=?',[$delta,$r['passenger_id']]);
    App::insert('ledger',['passenger_id'=>$r['passenger_id'],'ride_id'=>$id,'kind'=>'reversal','amount'=>$delta,'created_at'=>App::now()]);
    self::fund((int)$u['id'],(int)$r['participant_id'],'reimbursement_due',-(int)$r['redeemed'],$reason,$id);
    self::fund((int)$u['id'],(int)$r['participant_id'],'contribution_due',-(int)$r['contribution'],$reason,$id);
   }
   App::audit((int)$u['id'],'correction.'.$status,$id,['reason'=>$reason,'delta'=>$delta]);
   return ['ride_id'=>$id,'status'=>$status];
  });
 }
 public static function reconcile():array{
  $diff=App::all('SELECT w.passenger_id,w.balance,COALESCE(SUM(l.amount),0) ledger FROM wallets w LEFT JOIN ledger l ON l.passenger_id=w.passenger_id GROUP BY w.passenger_id,w.balance HAVING w.balance<>COALESCE(SUM(l.amount),0)');
  $fund=[];foreach(App::all('SELECT kind,SUM(amount) total FROM fund_entries GROUP BY kind') as $r){$fund[$r['kind']]=(int)$r['total'];}
  return ['differences'=>$diff,'credit'=>(int)App::scalar('SELECT COALESCE(SUM(balance),0) FROM wallets'),'cash'=>($fund['contribution_received']??0)-($fund['reimbursement_paid']??0),'fund'=>$fund];
 }
}
