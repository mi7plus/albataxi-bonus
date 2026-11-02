<?php
declare(strict_types=1);
namespace Alba;
final class Memberships {
 public static function reviewUrl(string $v):string{
  if($v!==''&&(!filter_var($v,FILTER_VALIDATE_URL)||parse_url($v,PHP_URL_SCHEME)!=='https'||!in_array(strtolower((string)parse_url($v,PHP_URL_HOST)),['g.page','maps.app.goo.gl','www.google.com','google.com','maps.google.com'],true))){App::fail('INVALID_REVIEW_URL','Folosiți un link HTTPS Google Maps / Reviews.');}return $v;
 }
 public static function rules(array $u,int $participant,array $d):int{
  App::admin($u);
  return App::tx(function()use($u,$participant,$d){
   if(!App::one('SELECT id FROM participants WHERE id=? FOR UPDATE',[$participant])){App::fail('NOT_FOUND','Participant inexistent.',404);}
   $values=[];
   foreach(['earn_bps'=>500,'cap_bps'=>3000,'contribution_bps'=>500,'daily_limit'=>5,'max_fare'=>100000] as $k=>$default){
    $percentKey=str_replace('_bps','_percent',$k);
    $raw=str_ends_with($k,'_bps')&&isset($d[$percentKey])?App::money($d[$percentKey]):($k==='max_fare'&&isset($d['max_fare_lei'])?App::money($d['max_fare_lei']):($d[$k]??$default));
    $v=filter_var($raw,FILTER_VALIDATE_INT);
    if($v===false||$v<0||(str_ends_with($k,'bps')&&$v>10000)||(in_array($k,['daily_limit','max_fare'],true)&&($v<1||$v>10000000))){App::fail('INVALID_RULE','Procentele trebuie să fie între 0 și 100%. Limitele trebuie să fie numere pozitive.');}$values[$k]=$v;
   }
   $id=App::insert('rules',$values+['participant_id'=>$participant,'actor_id'=>$u['id'],'created_at'=>App::now()]);
   App::audit((int)$u['id'],'rules.created',$id,$values+['participant'=>$participant]);return $id;
  });
 }
 public static function invite(array $u,int $participant,string $phone,string $plate='',string $role='driver'):array{
  Scope::managed($u,$participant);$phone=App::phone($phone);
  if(!in_array($role,['driver','manager'],true)||($role==='manager'&&!$u['is_admin'])){App::fail('FORBIDDEN','Doar administratorul invită manageri.',403);}
  if(mb_strlen($plate)>20){App::fail('INVALID_PLATE','Număr de înmatriculare prea lung.');}
  return App::tx(function()use($u,$participant,$phone,$plate,$role){
   App::one('SELECT id FROM participants WHERE id=? FOR UPDATE',[$participant]);
   $member=App::one('SELECT m.* FROM memberships m JOIN accounts a ON a.id=m.account_id WHERE a.phone=? AND m.participant_id=? AND m.role=? AND m.ended_at IS NULL',[$phone,$participant,$role]);
   if($member){App::fail('DUPLICATE_MEMBER','Contul este deja înscris aici.',409);}
   $existing=App::one("SELECT * FROM invitations WHERE participant_id=? AND phone=? AND role=? AND status='pending' AND expires_at>?",[$participant,$phone,$role,App::now()]);
   if($existing){return $existing;}
   $id=App::insert('invitations',['token'=>App::token(),'participant_id'=>$participant,'phone'=>$phone,'plate'=>strtoupper(trim($plate)),'role'=>$role,'expires_at'=>App::after(7*86400),'actor_id'=>$u['id']]);
   App::audit((int)$u['id'],'invitation.created',$id,['participant'=>$participant]);
   return App::one('SELECT * FROM invitations WHERE id=?',[$id]);
  });
 }
 public static function accept(array $u,string $token):array{
  return App::tx(function()use($u,$token){
   App::one('SELECT id FROM accounts WHERE id=? FOR UPDATE',[$u['id']]);
   $invite=App::one("SELECT * FROM invitations WHERE token=? AND status='pending' FOR UPDATE",[$token]);
   if(!$invite||$invite['expires_at']<=App::now()||$invite['phone']!==$u['phone']||!$u['verified']){App::fail('INVITATION_INVALID','Invitația a expirat sau telefonul verificat nu corespunde.',409);}
   $p=App::one('SELECT * FROM participants WHERE id=? FOR UPDATE',[$invite['participant_id']]);
   if(in_array($p['status'],['suspended','closed'],true)){App::fail('PARTICIPANT_SUSPENDED','Participantul nu acceptă înscrieri.',409);}
   if($invite['role']==='driver'){
    $old=App::one("SELECT * FROM memberships WHERE account_id=? AND role='driver' AND ended_at IS NULL FOR UPDATE",[$u['id']]);
    if($old&&(int)$old['participant_id']!==(int)$p['id']){
     App::run('UPDATE memberships SET active=0,ended_at=? WHERE id=?',[App::now(),$old['id']]);
     App::run('UPDATE qr_pages SET token=?,display_token=? WHERE membership_id=?',[App::token(),App::token(),$old['id']]);
     App::run("UPDATE rides SET status='cancelled',approval='revoked' WHERE driver_id=? AND status IN ('draft','ready')",[$old['id']]);
     App::run("UPDATE passenger_requests SET status='cancelled' WHERE driver_id=? AND status IN ('ready','claimed')",[$old['id']]);
    }
   }
   $member=App::one('SELECT * FROM memberships WHERE account_id=? AND participant_id=? AND role=?',[$u['id'],$p['id'],$invite['role']]);
   if($member){$id=(int)$member['id'];App::run('UPDATE memberships SET active=0,ended_at=NULL,accepted_at=? WHERE id=?',[App::now(),$id]);}
   else{$id=App::insert('memberships',['account_id'=>$u['id'],'participant_id'=>$p['id'],'role'=>$invite['role'],'accepted_at'=>App::now()]);}
   if($invite['role']==='driver'){
    App::run('INSERT INTO vehicles(membership_id,participant_id,plate,eligible) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE plate=VALUES(plate),eligible=VALUES(eligible)',[$id,$p['id'],$invite['plate'],(int)($invite['plate']!=='')]);
    if(!App::one('SELECT id FROM qr_pages WHERE membership_id=?',[$id])){App::insert('qr_pages',['membership_id'=>$id,'token'=>App::token(),'display_token'=>App::token()]);}
   }
   App::run("UPDATE invitations SET status='accepted' WHERE id=?",[$invite['id']]);App::audit((int)$u['id'],'membership.accepted',$id,['participant'=>$p['id']]);
   return ['membership_id'=>$id];
  });
 }
 public static function previewImport(array $u,int $participant,string $csv):array{
  Scope::managed($u,$participant);
  if(strlen($csv)>1500000){App::fail('IMPORT_TOO_LARGE','Împărțiți importul în fișiere mai mici de 1,5 MB.');}
  $stream=fopen('php://memory','r+');fwrite($stream,ltrim($csv,"\xEF\xBB\xBF"));rewind($stream);
  $header=fgetcsv($stream,0,',','"','');if(!$header||!in_array('phone',$header,true)||!in_array('plate',$header,true)){App::fail('INVALID_CSV','Antetul CSV trebuie să conțină phone,plate.');}
  $rows=[];$seen=[];$line=1;
  while(($row=fgetcsv($stream,0,',','"',''))!==false){
   $line++;if($row===[null]){continue;}
   $d=count($row)===count($header)?array_combine($header,$row):[];$error='';$phone=$d['phone']??'';
   try{
    $phone=App::phone($phone);
    if(isset($seen[$phone])){App::fail('DUPLICATE','Duplicat în fișier.');}
    if(empty($d['plate'])||mb_strlen($d['plate'])>20){App::fail('INVALID_PLATE','Vehicul lipsă sau număr prea lung.');}
    if(App::one("SELECT m.id FROM memberships m JOIN accounts a ON a.id=m.account_id WHERE a.phone=? AND m.role='driver' AND m.ended_at IS NULL",[$phone])){App::fail('EXISTING_MEMBERSHIP','Afiliere existentă. Folosiți invitația individuală pentru transfer.');}
   }catch(Problem $e){$error=$e->getMessage();}
   $seen[$phone]=true;$rows[]=['row'=>$line,'phone'=>$phone,'plate'=>$d['plate']??'','error'=>$error,'invitation'=>null];
  }fclose($stream);
  if(!$rows){App::fail('EMPTY_IMPORT','Fișier gol.');}
  $id=App::id();App::insert('imports',['id'=>$id,'actor_id'=>$u['id'],'participant_id'=>$participant,'rows_json'=>json_encode($rows),'created_at'=>App::now()]);
  App::audit((int)$u['id'],'import.preview',$id,['rows'=>count($rows)]);return ['id'=>$id,'rows'=>$rows];
 }
 public static function confirmImport(array $u,string $id,bool $partial):array{
  return App::tx(function()use($u,$id,$partial){
   $batch=App::one('SELECT * FROM imports WHERE id=? AND actor_id=? FOR UPDATE',[$id,$u['id']]);
   if(!$batch){App::fail('NOT_FOUND','Import inexistent.',404);}Scope::managed($u,(int)$batch['participant_id']);
   $rows=json_decode($batch['rows_json'],true);
   if($batch['status']==='completed'){return ['id'=>$id,'rows'=>$rows];}
   if(!$partial&&array_filter($rows,fn($r)=>$r['error']!=='')){App::fail('IMPORT_ERRORS','Corectați erorile sau alegeți explicit importul parțial.');}
   foreach($rows as &$row){
    if($row['error']||$row['invitation']){continue;}
    try{$inv=self::invite($u,(int)$batch['participant_id'],$row['phone'],$row['plate']);$row['invitation']=$inv['id'];}
    catch(Problem $e){if(!$partial){throw $e;}$row['error']=$e->getMessage();}
   }unset($row);
   App::run("UPDATE imports SET rows_json=?,status='completed' WHERE id=?",[json_encode($rows),$id]);App::audit((int)$u['id'],'import.completed',$id);return ['id'=>$id,'rows'=>$rows];
  });
 }
}
