<?php
declare(strict_types=1);
namespace Alba;

final class Workspace {
 public static function data(array $u):array {
  $user=$u;
  $members=App::all('SELECT m.*,p.name,p.status participant_status FROM memberships m JOIN participants p ON p.id=m.participant_id WHERE m.account_id=? AND m.ended_at IS NULL',[$u['id']]);
  $driver=null;$manager=false;
  foreach($members as $m){if($m['role']==='driver'){$driver=$m;}if($m['role']==='manager'&&$m['active']&&!$m['admin_suspended']&&$m['participant_status']==='active'){$manager=true;}}
  $roles=['passenger'];if($driver){$roles[]='driver';}if($manager){$roles[]='agency';}if($u['is_admin']){$roles[]='admin';}
  $context=Auth::context();$role=$_GET['role']??$context['role']??end($roles);
  if(!in_array($role,$roles,true)){$role=end($roles);}
  if(($context['role']??null)!==$role){$context['role']=$role;Auth::context($context);}
  $section=is_string($_GET['section']??null)?$_GET['section']:'overview';
  $allowed=['overview','history','help','account'];
  if($role==='driver'){$allowed[]='qr';}
  if($role!=='passenger'){$allowed[]='reports';}
  if(in_array($role,['agency','admin'],true)){$allowed=array_merge($allowed,['drivers','vehicles','fund']);}
  if($role==='admin'){$allowed=array_merge($allowed,['agencies','audit','readiness']);}
  if(!in_array($section,$allowed,true)){App::fail('FORBIDDEN','Această secțiune nu este disponibilă în spațiul ales.',403);}
  $offset=max(0,((int)($_GET['p']??1)-1)*25);
  $search=mb_substr((string)($_GET['search']??''),0,160);
  $selectedParticipant=(int)($_GET['participant']??0);
  if($selectedParticipant){Scope::managed($u,$selectedParticipant);}
  $rides=$queue=$drafts=$events=$requests=$expiredRequests=$expiredQueue=$approvals=[];
  $participants=$people=$vehicles=$invitations=$settlements=$batches=$presets=$support=$audit=$flags=[];
  $total=$participantTotal=$peopleTotal=$vehicleTotal=$settlementTotal=$queueTotal=0;
  $stats=['count'=>0,'fare'=>0,'earned'=>0,'redeemed'=>0];$balance=0;$qr=$driverRule=null;
  $reconciliation=null;$diagnostics=[];$checks=[];
  $controls=['earning_paused'=>0,'redemption_paused'=>0];

  if($section==='history'||($section==='overview'&&in_array($role,['agency','admin'],true))){
   [$where,$params]=Scope::sql($u);
   if($role==='passenger'){$where='r.passenger_id=?';$params=[$u['id']];}
   elseif($role==='driver'){$where='r.driver_id=?';$params=[$driver['id']];}
   elseif($role==='agency'){[$where,$params]=Scope::sql($u,'r',false);}
   $rides=App::all("SELECT r.*,p.name participant_name FROM rides r JOIN participants p ON p.id=r.participant_id WHERE $where ORDER BY r.created_at DESC,r.id LIMIT 25 OFFSET $offset",$params);
   $total=(int)App::scalar("SELECT COUNT(*) FROM rides r WHERE $where",$params);
   if($section==='overview'){
    $stats=App::one("SELECT COUNT(*) count,COALESCE(SUM(fare),0) fare,COALESCE(SUM(earned),0) earned,COALESCE(SUM(redeemed),0) redeemed FROM rides r WHERE $where AND status='posted'",$params);
   }
  }

  if($role==='passenger'&&$section==='overview'){
   $balance=(int)App::scalar('SELECT balance FROM wallets WHERE passenger_id=?',[$u['id']]);
   $requests=App::all("SELECT q.*,p.name FROM passenger_requests q JOIN memberships m ON m.id=q.driver_id JOIN participants p ON p.id=m.participant_id WHERE q.passenger_id=? AND q.status IN ('ready','claimed') AND q.expires_at>? ORDER BY q.created_at DESC LIMIT 25",[$u['id'],App::now()]);
   $approvals=App::all("SELECT r.*,p.name,ru.cap_bps,ru.earn_bps FROM rides r JOIN participants p ON p.id=r.participant_id JOIN rules ru ON ru.id=r.rule_id WHERE r.passenger_id=? AND r.status IN ('draft','ready') AND r.expires_at>? ORDER BY r.created_at DESC LIMIT 25",[$u['id'],App::now()]);
   $expiredRequests=App::all("SELECT q.*,p.name,qr.token FROM passenger_requests q JOIN memberships m ON m.id=q.driver_id JOIN participants p ON p.id=m.participant_id JOIN qr_pages qr ON qr.membership_id=m.id WHERE q.passenger_id=? AND q.status IN ('ready','claimed') AND q.expires_at<=? AND q.expires_at>? ORDER BY q.created_at DESC LIMIT 10",[$u['id'],App::now(),App::after(-86400)]);
  }

  if($driver&&$role==='driver'&&in_array($section,['overview','qr'],true)){
   $qr=App::one('SELECT * FROM qr_pages WHERE membership_id=?',[$driver['id']]);
   if($section==='overview'){
    $queue=App::all("SELECT q.*,a.phone FROM passenger_requests q JOIN accounts a ON a.id=q.passenger_id WHERE q.driver_id=? AND q.status='ready' AND q.expires_at>? ORDER BY q.created_at,q.id LIMIT 25 OFFSET $offset",[$driver['id'],App::now()]);
    $queueTotal=(int)App::scalar("SELECT COUNT(*) FROM passenger_requests WHERE driver_id=? AND status='ready' AND expires_at>?",[$driver['id'],App::now()]);
    $drafts=App::all("SELECT r.*,a.phone FROM rides r JOIN accounts a ON a.id=r.passenger_id WHERE r.driver_id=? AND r.status IN ('draft','ready') ORDER BY r.created_at DESC LIMIT 25",[$driver['id']]);
    $events=App::all('SELECT * FROM scan_events WHERE driver_id=? ORDER BY id DESC LIMIT 25',[$driver['id']]);
    $expiredQueue=App::all("SELECT q.*,a.phone FROM passenger_requests q JOIN accounts a ON a.id=q.passenger_id WHERE q.driver_id=? AND q.status='ready' AND q.expires_at<=? AND q.expires_at>? ORDER BY q.created_at DESC LIMIT 10",[$driver['id'],App::now(),App::after(-86400)]);
    $driverRule=App::one('SELECT * FROM rules WHERE participant_id=? ORDER BY id DESC LIMIT 1',[$driver['participant_id']]);
    $controls=App::one('SELECT * FROM programme WHERE id=1');
   }
  }

  $pwhere=$u['is_admin']?'1=1':"EXISTS(SELECT 1 FROM memberships mm WHERE mm.participant_id=p.id AND mm.account_id=? AND mm.role='manager' AND mm.active=1 AND mm.admin_suspended=0 AND mm.ended_at IS NULL) AND p.status='active'";
  $pparams=$u['is_admin']?[]:[$u['id']];
  $selection=$selectedParticipant?' AND p.id=?':'';
  $selectionParams=$selectedParticipant?[$selectedParticipant]:[];
  if(in_array($section,['overview','agencies','drivers','fund','reports','account'],true)&&in_array($role,['agency','admin'],true)){
   $listOffset=$section==='agencies'?$offset:0;
   $searchPart=$section==='agencies'?' AND p.name LIKE ?':'';
   $listParams=array_merge($pparams,$selectionParams,$section==='agencies'?['%'.$search.'%']:[]);
   $counts=in_array($section,['overview','agencies'],true)?','.Setup::counts():'';
   $participants=App::all("SELECT p.*,(SELECT CONCAT(earn_bps,',',cap_bps,',',contribution_bps,',',daily_limit,',',max_fare) FROM rules WHERE participant_id=p.id ORDER BY id DESC LIMIT 1) effective_rule $counts FROM participants p WHERE $pwhere $selection $searchPart ORDER BY p.name,p.id LIMIT 25 OFFSET $listOffset",$listParams);
   if($section==='agencies'){$participantTotal=(int)App::scalar("SELECT COUNT(*) FROM participants p WHERE $pwhere $selection $searchPart",$listParams);}
  }

  if($section==='drivers'){
   $peopleParams=array_merge($pparams,$selectionParams,['%'.$search.'%','%'.$search.'%']);
   $peopleWhere="$pwhere $selection AND m.ended_at IS NULL AND (a.phone LIKE ? OR p.name LIKE ?)";
   $people=App::all("SELECT m.*,a.phone,p.name FROM memberships m JOIN accounts a ON a.id=m.account_id JOIN participants p ON p.id=m.participant_id WHERE $peopleWhere ORDER BY m.id DESC LIMIT 25 OFFSET $offset",$peopleParams);
   $peopleTotal=(int)App::scalar("SELECT COUNT(*) FROM memberships m JOIN accounts a ON a.id=m.account_id JOIN participants p ON p.id=m.participant_id WHERE $peopleWhere",$peopleParams);
   $invitations=App::all("SELECT i.*,p.name FROM invitations i JOIN participants p ON p.id=i.participant_id WHERE $pwhere $selection AND i.status='pending' ORDER BY i.id DESC LIMIT 25",array_merge($pparams,$selectionParams));
   $batches=App::all('SELECT * FROM imports WHERE actor_id=? ORDER BY created_at DESC LIMIT 10',[$u['id']]);
  }
  if($section==='vehicles'){
   $vehicleParams=array_merge($pparams,$selectionParams);
   $base="FROM vehicles v JOIN participants p ON p.id=v.participant_id JOIN memberships m ON m.id=v.membership_id WHERE $pwhere $selection AND m.ended_at IS NULL";
   $vehicles=App::all("SELECT v.*,p.name $base ORDER BY v.id DESC LIMIT 25 OFFSET $offset",$vehicleParams);
   $vehicleTotal=(int)App::scalar("SELECT COUNT(*) $base",$vehicleParams);
  }
  if($section==='fund'){
   $fundParams=array_merge($pparams,$selectionParams);
   $base="FROM settlements s JOIN participants p ON p.id=s.participant_id WHERE $pwhere $selection";
   $settlements=App::all("SELECT s.*,p.name $base ORDER BY s.id DESC LIMIT 25 OFFSET $offset",$fundParams);
   $settlementTotal=(int)App::scalar("SELECT COUNT(*) $base",$fundParams);
   if($role==='admin'){
    $fund=[];foreach(App::all('SELECT kind,SUM(amount) amount FROM fund_entries GROUP BY kind') as $row){$fund[$row['kind']]=(int)$row['amount'];}
    $reconciliation=['cash'=>($fund['contribution_received']??0)-($fund['reimbursement_paid']??0),'credit'=>(int)App::scalar('SELECT COALESCE(SUM(balance),0) FROM wallets')];
   }
  }
  if($section==='reports'){$presets=App::all('SELECT * FROM presets WHERE owner_id=? ORDER BY name',[$u['id']]);}
  if($section==='help'){$support=$role==='admin'?App::all('SELECT * FROM support_cases ORDER BY id DESC LIMIT 50'):App::all('SELECT * FROM support_cases WHERE owner_id=? ORDER BY id DESC LIMIT 25',[$u['id']]);}
  if($role==='admin'&&in_array($section,['audit','overview'],true)){
   $flags=App::all('SELECT * FROM risk_flags WHERE resolved=0 ORDER BY id DESC LIMIT 25');
   if($section==='audit'){$audit=App::all('SELECT * FROM audit_events ORDER BY id DESC LIMIT 50');}
  }
  if($section==='account'&&$role==='admin'){$controls=App::one('SELECT * FROM programme WHERE id=1');}
  if($section==='readiness'){App::admin($u);$diagnostics=Readiness::diagnostics();$checks=App::all('SELECT * FROM acceptance_checks ORDER BY check_key');}
  return compact('user','members','driver','roles','role','section','offset','rides','total','stats','balance','queue','queueTotal','drafts','events','qr','driverRule','requests','expiredRequests','expiredQueue','approvals','search','selectedParticipant','participants','participantTotal','people','peopleTotal','vehicles','vehicleTotal','invitations','settlements','settlementTotal','batches','presets','support','audit','flags','reconciliation','controls','context','diagnostics','checks');
 }
}
