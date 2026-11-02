<?php
declare(strict_types=1);
namespace Alba;
final class Scope{
 public static function managed(array $u,int $participant):array{
  $p=App::one('SELECT * FROM participants WHERE id=?',[$participant]);
  if(!$p){App::fail('NOT_FOUND','Participant inexistent.',404);}
  if(!$u['is_admin']&&!App::one("SELECT id FROM memberships WHERE account_id=? AND participant_id=? AND role='manager' AND active=1 AND admin_suspended=0 AND ended_at IS NULL",[$u['id'],$participant])){App::fail('FORBIDDEN','Nu aveți acces la acest participant.',403);}
  if(!$u['is_admin']&&$p['status']!=='active'){App::fail('PARTICIPANT_SUSPENDED','Participantul nu este activ.',403);}
  return $p;
 }
 public static function sql(array $u,string $alias='r',bool $passenger=true):array{
  if($u['is_admin']){return ['1=1',[]];}
  $where="($alias.driver_id IN (SELECT id FROM memberships WHERE account_id=?) OR $alias.participant_id IN (SELECT m.participant_id FROM memberships m JOIN participants p ON p.id=m.participant_id WHERE m.account_id=? AND m.role='manager' AND m.active=1 AND m.admin_suspended=0 AND m.ended_at IS NULL AND p.status='active')";
  $params=[$u['id'],$u['id']];
  if($passenger){$where.=" OR $alias.passenger_id=?";$params[]=$u['id'];}
  return [$where.')',$params];
 }
 public static function ride(array $u,string $id):array{
  [$where,$p]=self::sql($u);
  $ride=App::one("SELECT r.*,p.name participant_name,a.phone passenger_phone,q.code request_code FROM rides r JOIN participants p ON p.id=r.participant_id JOIN accounts a ON a.id=r.passenger_id JOIN passenger_requests q ON q.id=r.request_id WHERE r.id=? AND $where",array_merge([$id],$p));
  if(!$ride){App::fail('NOT_FOUND','Cursa nu a fost găsită.',404);}return $ride;
 }
 public static function signature(array $u):string{
  $members=App::all("SELECT m.id,m.participant_id,m.active,m.admin_suspended,m.ended_at,p.status FROM memberships m JOIN participants p ON p.id=m.participant_id WHERE m.account_id=? ORDER BY m.id",[$u['id']]);
  return hash('sha256',json_encode([$u['is_admin'],$u['active'],$members]));
 }
}
