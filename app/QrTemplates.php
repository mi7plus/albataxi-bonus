<?php
declare(strict_types=1);
namespace Alba;
final class QrTemplates {
 public static function editor(array $u,array $query):array{
  $o=($query['orientation']??'portrait')==='landscape'?'landscape':'portrait';$choices=[];
  if($u['is_admin'])$choices[]=['value'=>'default:0','label'=>'Program · model implicit'];
  $agencies=App::all("SELECT p.id,p.name FROM participants p WHERE ?=1 OR (p.status='active' AND EXISTS(SELECT 1 FROM memberships m WHERE m.participant_id=p.id AND m.account_id=? AND m.role='manager' AND m.active=1 AND m.admin_suspended=0 AND m.ended_at IS NULL)) ORDER BY p.id LIMIT 500",[(int)$u['is_admin'],$u['id']]);
  foreach($agencies as $p)$choices[]=['value'=>'agency:'.$p['id'],'label'=>$p['name'].' · model agenție'];
  $drivers=App::all("SELECT m.id,p.name FROM memberships m JOIN participants p ON p.id=m.participant_id WHERE m.role='driver' AND m.ended_at IS NULL AND (?=1 OR (m.account_id=? AND m.active=1 AND m.admin_suspended=0) OR (p.status='active' AND EXISTS(SELECT 1 FROM memberships manager WHERE manager.participant_id=p.id AND manager.account_id=? AND manager.role='manager' AND manager.active=1 AND manager.admin_suspended=0 AND manager.ended_at IS NULL))) ORDER BY m.id LIMIT 500",[(int)$u['is_admin'],$u['id'],$u['id']]);
  foreach($drivers as $m)$choices[]=['value'=>'driver:'.$m['id'],'label'=>$m['name'].' · șofer #'.$m['id']];
  $pending=[];$pendingLinks=[];if($choices){$keys=array_column($choices,'value');$labels=array_column($choices,'label','value');$marks=implode(',',array_fill(0,count($keys),'?'));$pendingRows=App::all("SELECT scope,owner_id,orientation FROM qr_templates WHERE status='pending' AND CONCAT(scope,':',owner_id) IN ($marks) ORDER BY updated_at",$keys);foreach($pendingRows as $r){$key=$r['scope'].':'.$r['owner_id'];if($r['orientation']===$o)$pending[$key]=true;$pendingLinks[]=['value'=>$key,'orientation'=>$r['orientation'],'label'=>$labels[$key].' · '.($r['orientation']==='portrait'?'portret':'peisaj')];}foreach($choices as &$choice){if(isset($pending[$choice['value']]))$choice['label'].=' · ÎN AȘTEPTARE';}unset($choice);}
  $target=(string)($query['target']??(isset($query['driver'])?'driver:'.(int)$query['driver']:($choices[0]['value']??'driver:0')));
  if(!preg_match('/^(default|agency|driver):(\d+)$/D',$target,$match))App::fail('INVALID_DESIGN','Model invalid.');
  $scope=$match[1];$id=(int)$match[2];$member=self::target($u,$scope,$id);$row=self::row($scope,$id,$o);$inherited=$scope==='default'?QrDesign::defaults($o):self::inherited($scope,$id,$o);
  $design=$row&&($row['proposal_json']||$row['approved_json'])?json_decode($row['proposal_json']?:$row['approved_json'],true,32,JSON_THROW_ON_ERROR):$inherited;
  $published=$row&&$row['approved_json']?json_decode($row['approved_json'],true,32,JSON_THROW_ON_ERROR):$inherited;
  $source=$scope==='driver'?self::effective($id,$o)['source']:($row&&$row['approved_json']?$scope:'default');
  $canReview=$row&&$row['status']==='pending'&&self::canReview($u,$scope,$id,$row);
  $outputQr=$scope==='driver'?App::one('SELECT display_token FROM qr_pages WHERE membership_id=?',[$id]):null;
  return compact('u','o','choices','pending','pendingLinks','target','scope','id','member','row','inherited','design','published','source','canReview','outputQr');
 }
 public static function target(array $u,string $scope,int $id):array{
  if($scope==='default'){App::admin($u);if($id!==0){App::fail('INVALID_DESIGN','Model implicit invalid.');}return ['name'=>'Modelul implicit al programului','id'=>0,'participant_id'=>0];}
  if($scope==='agency'){$p=Scope::managed($u,$id);return ['name'=>$p['name'].' · model agenție','id'=>$id,'participant_id'=>$id];}
  if($scope==='driver'){return QrDesign::access($u,$id);}
  App::fail('INVALID_DESIGN','Tip de model invalid.');
 }
 public static function row(string $scope,int $id,string $o):?array{return App::one('SELECT * FROM qr_templates WHERE scope=? AND owner_id=? AND orientation=?',[$scope,$id,$o]);}
 public static function effective(int $driver,string $o,bool $skipDriver=false):array{
  $m=App::one('SELECT participant_id FROM memberships WHERE id=?',[$driver]);
  foreach(array_merge($skipDriver?[]:[['driver',$driver]],[['agency',(int)($m['participant_id']??0)],['default',0]]) as [$scope,$id]){
   $r=self::row($scope,$id,$o);if($r&&$r['approved_json']){return ['design'=>json_decode($r['approved_json'],true,32,JSON_THROW_ON_ERROR),'source'=>$scope,'owner'=>$id];}
  }return ['design'=>QrDesign::defaults($o),'source'=>'default','owner'=>0];
 }
 public static function inherited(string $scope,int $id,string $o):array{
  if($scope==='driver'){return self::effective($id,$o,true)['design'];}
  $r=self::row('default',0,$o);return $r&&$r['approved_json']?json_decode($r['approved_json'],true,32,JSON_THROW_ON_ERROR):QrDesign::defaults($o);
 }
 public static function canReview(array $u,string $scope,int $id,?array $row):bool{
  if($scope==='agency'){return (bool)$u['is_admin'];}
  if($scope!=='driver'||(int)($row['proposed_by']??0)===(int)$u['id'])return false;
  $m=App::one('SELECT m.participant_id,p.kind,p.status FROM memberships m JOIN participants p ON p.id=m.participant_id WHERE m.id=? AND m.ended_at IS NULL',[$id]);
  if(!$m)return false;
  if($m['kind']==='independent')return (bool)$u['is_admin'];
  return $m['status']==='active'&&(bool)App::one("SELECT id FROM memberships WHERE account_id=? AND participant_id=? AND role='manager' AND active=1 AND admin_suspended=0 AND ended_at IS NULL",[$u['id'],$m['participant_id']]);
 }
 public static function save(array $u,array $data):array{
  $scope=(string)($data['scope']??'driver');$id=(int)($data['owner']??$data['driver']??0);self::target($u,$scope,$id);$o=(string)($data['orientation']??'');
  if(!in_array($o,['portrait','landscape'],true))App::fail('INVALID_DESIGN','Orientare invalidă.');
  $raw=(string)($data['design']??'');$decoded=strlen($raw)<=12000?json_decode($raw,true,32):null;
  if(!is_array($decoded))App::fail('INVALID_DESIGN','Design invalid.');$json=json_encode(QrDesign::validate($decoded,$o),JSON_THROW_ON_ERROR);
  return App::tx(function()use($u,$scope,$id,$o,$json,$data){
   self::target($u,$scope,$id);App::run('INSERT IGNORE INTO qr_templates(scope,owner_id,orientation,updated_at) VALUES(?,?,?,?)',[$scope,$id,$o,App::now()]);
   $row=App::one('SELECT * FROM qr_templates WHERE scope=? AND owner_id=? AND orientation=? FOR UPDATE',[$scope,$id,$o]);
   if((int)($data['revision']??-1)!==(int)$row['revision'])App::fail('DESIGN_CHANGED','Modelul s-a schimbat. Reîncărcați înainte de salvare.',409);
   $direct=$scope==='default';
   App::run('UPDATE qr_templates SET approved_json=?,proposal_json=?,status=?,revision=revision+1,proposed_by=?,reviewed_by=NULL,review_note=?,updated_at=? WHERE scope=? AND owner_id=? AND orientation=?',[$direct?$json:$row['approved_json'],$direct?null:$json,$direct?'approved':'pending',$u['id'],'',App::now(),$scope,$id,$o]);
   App::audit((int)$u['id'],$direct?'qr.default_published':'qr.template_submitted',$scope.':'.$id,['orientation'=>$o,'revision'=>(int)$row['revision']+1]);
   return ['revision'=>(int)$row['revision']+1,'message'=>$direct?'Model implicit publicat.':'Trimis pentru aprobare. Pagina publică păstrează modelul aprobat.'];
  });
 }
 public static function review(array $u,array $data):array{
  $scope=(string)($data['scope']??'');$id=(int)($data['owner']??0);$o=(string)($data['orientation']??'');self::target($u,$scope,$id);
  $decision=(string)($data['decision']??'');$note=trim((string)($data['note']??''));
  if(!in_array($decision,['approve','reject','inherit'],true)||($decision==='reject'&&mb_strlen($note)<5))App::fail('REASON_REQUIRED','Motivați respingerea (minimum 5 caractere).');
  return App::tx(function()use($u,$data,$scope,$id,$o,$decision,$note){
   $row=App::one('SELECT * FROM qr_templates WHERE scope=? AND owner_id=? AND orientation=? FOR UPDATE',[$scope,$id,$o]);
   if(!$row||(int)($data['revision']??-1)!==(int)$row['revision'])App::fail('DESIGN_CHANGED','Modelul s-a schimbat. Reîncărcați pagina.',409);
   if($decision==='inherit'){
    if($scope==='default')App::fail('FORBIDDEN','Modelul implicit nu are părinte.',403);
    self::target($u,$scope,$id);
    App::run("UPDATE qr_templates SET approved_json=NULL,proposal_json=NULL,status='inherited',revision=revision+1,review_note='',updated_at=? WHERE scope=? AND owner_id=? AND orientation=?",[App::now(),$scope,$id,$o]);
   }else{
    if(!self::canReview($u,$scope,$id,$row))App::fail('FORBIDDEN','Este necesară aprobarea nivelului superior. Nu puteți aproba propria propunere de șofer.',403);
    if($row['status']!=='pending'||!$row['proposal_json'])App::fail('INVALID_STATE','Nu există o propunere în așteptare.');
    App::run('UPDATE qr_templates SET approved_json=?,status=?,revision=revision+1,reviewed_by=?,review_note=?,updated_at=? WHERE scope=? AND owner_id=? AND orientation=?',[$decision==='approve'?$row['proposal_json']:$row['approved_json'],$decision==='approve'?'approved':'rejected',$u['id'],mb_substr($note,0,1000),App::now(),$scope,$id,$o]);
   }
   App::audit((int)$u['id'],'qr.template_'.$decision,$scope.':'.$id,['orientation'=>$o,'revision'=>(int)$row['revision']+1,'note'=>mb_substr($note,0,1000)]);
   return ['ok'=>true];
  });
 }
}
