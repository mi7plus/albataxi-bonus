<?php
declare(strict_types=1);
namespace Alba;

final class Setup {
 /** These bounded aggregate subqueries avoid fetching staff lists or an N+1 query per card. */
 public const COUNTS = "
  (SELECT COUNT(*) FROM memberships sm JOIN accounts sa ON sa.id=sm.account_id WHERE sm.participant_id=p.id AND sm.role='manager' AND sm.accepted_at IS NOT NULL AND sm.ended_at IS NULL AND sm.active=1 AND sm.admin_suspended=0 AND sa.verified=1 AND sa.active=1) setup_managers,
  (SELECT COUNT(*) FROM memberships sm WHERE sm.participant_id=p.id AND sm.role='driver' AND sm.accepted_at IS NOT NULL AND sm.ended_at IS NULL) setup_drivers,
  (SELECT COUNT(*) FROM vehicles sv JOIN memberships sm ON sm.id=sv.membership_id WHERE sv.participant_id=p.id AND sv.eligible=1 AND sv.plate<>'' AND sm.ended_at IS NULL AND sm.accepted_at IS NOT NULL) setup_vehicles,
  (SELECT COUNT(*) FROM memberships sm JOIN accounts sa ON sa.id=sm.account_id WHERE sm.participant_id=p.id AND sm.role='driver' AND sm.active=1 AND sm.admin_suspended=0 AND sm.ended_at IS NULL AND sa.verified=1 AND sa.active=1) setup_active_drivers";
 public static function steps(array $p, string $role): array {
  $link=fn(string $section)=>'?role='.urlencode($role).'&section='.$section.'&participant='.$p['id'];
  $agency=$p['kind']==='agency';
  $steps=[];
  if($agency){$steps[]=['key'=>'manager','label'=>'Manager invitat, telefon verificat și afiliere activată','done'=>(int)($p['setup_managers']??0)>0,'required'=>true,'url'=>$link('drivers'),'action'=>'Invită sau activează managerul'];}
  $steps[]=['key'=>'rules','label'=>'Reguli de câștig și utilizare stabilite','done'=>!empty($p['effective_rule']),'required'=>true,'url'=>$role==='admin'?$link('agencies').'#rules-'.$p['id']:$link('account'),'action'=>$role==='admin'?'Configurează regulile':'Solicită configurarea administratorului'];
  $steps[]=['key'=>'funding','label'=>'Termenii fondului înregistrați','done'=>trim($p['funding_terms'])!=='','required'=>true,'url'=>$role==='admin'?$link('agencies').'#participation-'.$p['id']:$link('account'),'action'=>$role==='admin'?'Completează termenii':'Solicită termenii administratorului'];
  $steps[]=['key'=>'drivers','label'=>'Cel puțin un șofer a acceptat afilierea','done'=>(int)($p['setup_drivers']??0)>0,'required'=>true,'url'=>$link('drivers'),'action'=>'Invită un șofer'];
  $steps[]=['key'=>'vehicle','label'=>'Vehicul eligibil alocat unui șofer','done'=>(int)($p['setup_vehicles']??0)>0,'required'=>true,'url'=>$link('vehicles'),'action'=>'Verifică vehiculele'];
  $steps[]=['key'=>'participant','label'=>'Participarea este activă','done'=>$p['status']==='active','required'=>true,'url'=>$role==='admin'?$link('agencies').'#participation-'.$p['id']:$link('account'),'action'=>$role==='admin'?'Activează participarea':'Solicită activarea administratorului'];
  $steps[]=['key'=>'active_driver','label'=>'Cel puțin un șofer poate confirma curse','done'=>(int)($p['setup_active_drivers']??0)>0,'required'=>true,'url'=>$link('drivers'),'action'=>'Activează afilierea șoferului'];
  $steps[]=['key'=>'review','label'=>'Pagina Google Reviews este configurată (opțional)','done'=>$p['review_url']!=='','required'=>false,'url'=>$role==='admin'?$link('agencies').'#participation-'.$p['id']:$link('account'),'action'=>'Configurează recenziile'];
  $order=['manager'=>0,'rules'=>1,'funding'=>2,'participant'=>3,'drivers'=>4,'vehicle'=>5,'active_driver'=>6,'review'=>7];
  usort($steps,fn($a,$b)=>$order[$a['key']]<=>$order[$b['key']]);
  return $steps;
 }
}
