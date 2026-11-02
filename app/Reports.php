<?php
declare(strict_types=1);
namespace Alba;
final class Reports {
 public const TEMPLATES=['rides'=>'Curse, corecții și sold net','redemptions'=>'Credit folosit și corecții','ledger'=>'Registru credit și corecții','drivers'=>'Activitate șoferi','settlement'=>'Decont participant','fund'=>'Reconciliere fond','audit'=>'Audit și excepții'];
 public const COLUMNS=['id'=>'Referință','date'=>'Data Europe/Bucharest','participant'=>'Participant','driver'=>'Șofer','vehicle'=>'Vehicul','fare'=>'Tarif RON','redeemed'=>'Credit folosit · brut RON','paid'=>'De încasat RON','earned'=>'Credit câștigat · brut RON','contribution'=>'Contribuție · brut RON','rule'=>'Versiune reguli','status'=>'Stare','earned_adjustment'=>'Corecții câștig RON','net_earned'=>'Credit câștigat · net RON','redeemed_adjustment'=>'Corecții utilizare RON','net_redeemed'=>'Credit folosit · net RON','net_credit'=>'Modificare sold · net RON','net_contribution'=>'Contribuție · net RON'];
 public static function filters(array $d):array {
  $f=[];
  foreach(['template','start','end','participant','driver','vehicle','status','sort','group','columns'] as $k){
   if(isset($d[$k])&&!is_scalar($d[$k])){App::fail('INVALID_FILTER','Filtru invalid.');}
   $f[$k]=trim((string)($d[$k]??''));
  }
  $f['template']=$f['template']?:'rides';
  $today=new \DateTimeImmutable('now',new \DateTimeZone('Europe/Bucharest'));
  $f['start']=$f['start']?:$today->modify('-30 days')->format('Y-m-d');$f['end']=$f['end']?:$today->format('Y-m-d');
  if(!isset(self::TEMPLATES[$f['template']])){App::fail('INVALID_REPORT','Raport necunoscut.');}
  foreach(['start','end'] as $k){$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$f[$k]);if(!$date||$date->format('Y-m-d')!==$f[$k]){App::fail('INVALID_DATE','Perioadă invalidă.');}}
  if($f['start']>$f['end']){App::fail('INVALID_DATE','Data finală este înaintea datei de început.');}
  foreach(['participant','driver'] as $k){if($f[$k]!==''&&!ctype_digit($f[$k])){App::fail('INVALID_FILTER','Identificator invalid.');}}
  if(!in_array($f['sort'],['','date','-date','fare','-fare'],true)||!in_array($f['group'],['','participant','driver'],true)){App::fail('INVALID_FILTER','Sortare sau grupare invalidă.');}
  if($f['columns']&&array_diff(explode(',',$f['columns']),array_keys(self::COLUMNS))){App::fail('INVALID_COLUMNS','Coloană necunoscută.');}
  return $f;
 }
 public static function bounds(array $f):array {
  $tz=new \DateTimeZone('Europe/Bucharest');$utc=new \DateTimeZone('UTC');
  return [(new \DateTimeImmutable($f['start'],$tz))->setTimezone($utc)->format('Y-m-d H:i:s'),(new \DateTimeImmutable($f['end'],$tz))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s')];
 }
 public static function report(array $u,array $data,int $limit=50,int $offset=0):array {
  $f=self::filters($data);
  if(in_array($f['template'],['fund','audit'],true)){App::admin($u);}
  $snapshot=ReportSnapshot::resolve($u,$f,(string)($data['snapshot']??''));
  // The saved frontier fixes the set across separate downloads; repeatable read
  // also keeps totals and rows consistent inside each individual response.
  App::db()->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
  return App::tx(fn()=>self::build($u,$f,$snapshot,max(1,min(5000,$limit)),max(0,$offset)));
 }
 private static function build(array $u,array $f,array $s,int $limit,int $offset):array {
  [$start,$end]=self::bounds($f);$ledger=(int)$s['ledger'];$fund=(int)$s['fund'];$audit=(int)$s['audit'];
  $meta=['filters'=>$f,'timezone'=>'Europe/Bucharest','generated'=>App::now(),'offset'=>$offset,'limit'=>$limit,
   'snapshot'=>$s['token'],'snapshot_at'=>$s['created'],'snapshot_expires'=>gmdate('Y-m-d H:i:s',$s['expires']),
   'total_labels'=>Labels::totals(),'template_label'=>self::TEMPLATES[$f['template']]];
  $template=$f['template'];
  if($template==='ledger'){
   [$where,$params]=Scope::sql($u,'r',false);$where.=" AND l.id<=$ledger AND l.created_at>=? AND l.created_at<?";$params[]=$start;$params[]=$end;
   foreach(['participant'=>'participant_id','driver'=>'driver_id','status'=>'status'] as $input=>$column){if($f[$input]!==''){$where.=" AND r.$column=?";$params[]=$f[$input];}}
   if($f['vehicle']){$where.=' AND r.vehicle_plate LIKE ?';$params[]='%'.$f['vehicle'].'%';}
   $base="FROM ledger l JOIN rides r ON r.id=l.ride_id JOIN participants p ON p.id=r.participant_id WHERE $where";
   $rows=App::all("SELECT l.id,l.created_at,l.ride_id,p.name,l.kind,l.amount $base ORDER BY l.id DESC LIMIT $limit OFFSET $offset",$params);
   return $meta+['columns'=>['ID','Data Europe/Bucharest','Cursă','Participant','Tip','Sumă RON'],
    'rows'=>array_map(fn($r)=>[$r['id'],App::local($r['created_at']),$r['ride_id'],$r['name'],Labels::state($r['kind']),self::decimal($r['amount'])],$rows),
    'total'=>(int)App::scalar("SELECT COUNT(*) $base",$params),
    'totals'=>App::one("SELECT COALESCE(SUM(CASE WHEN l.amount>0 THEN l.amount ELSE 0 END),0) positive,COALESCE(SUM(CASE WHEN l.amount<0 THEN l.amount ELSE 0 END),0) negative,COALESCE(SUM(l.amount),0) net $base",$params),
    'explanation'=>'Mișcările de credit din perioada aleasă, inclusiv corecțiile înregistrate în acea perioadă.'];
  }
  if($template==='audit'){
   $params=[$start,$end];$where="id<=$audit AND created_at>=? AND created_at<?";
   $rows=App::all("SELECT id,created_at,actor_id,action,target,detail FROM audit_events WHERE $where ORDER BY id DESC LIMIT $limit OFFSET $offset",$params);
   return $meta+['columns'=>['ID','Data UTC','Administrator / cont','Acțiune','Referință','Detalii'],'rows'=>array_map('array_values',$rows),'total'=>(int)App::scalar("SELECT COUNT(*) FROM audit_events WHERE $where",$params),'totals'=>[]];
  }
  if(in_array($template,['fund','settlement'],true)){
   $where="e.id<=$fund AND e.created_at>=? AND e.created_at<?";$params=[$start,$end];
   if(!$u['is_admin']){$where.=" AND e.participant_id IN (SELECT m.participant_id FROM memberships m JOIN participants p ON p.id=m.participant_id WHERE m.account_id=? AND m.role='manager' AND m.active=1 AND m.admin_suspended=0 AND m.ended_at IS NULL AND p.status='active')";$params[]=$u['id'];}
   if($f['participant']){$where.=' AND e.participant_id=?';$params[]=$f['participant'];}
   $base="FROM fund_entries e JOIN participants p ON p.id=e.participant_id WHERE $where";
   $group="SELECT p.name participant,e.kind,SUM(CASE WHEN e.amount>0 THEN e.amount ELSE 0 END) gross,SUM(CASE WHEN e.amount<0 THEN e.amount ELSE 0 END) adjustment,SUM(e.amount) amount $base GROUP BY p.id,p.name,e.kind";
   $rows=App::all("$group ORDER BY participant,p.id,e.kind LIMIT $limit OFFSET $offset",$params);
   $totals=[];foreach(App::all("SELECT e.kind,SUM(e.amount) amount $base GROUP BY e.kind",$params) as $r){$totals[$r['kind']]=(int)$r['amount'];}
   return $meta+['columns'=>['Participant','Tip înregistrare','Brut RON','Corecții RON','Net RON'],'rows'=>array_map(fn($r)=>[$r['participant'],Labels::state($r['kind']),self::decimal($r['gross']),self::decimal($r['adjustment']),self::decimal($r['amount'])],$rows),
    'total'=>(int)App::scalar("SELECT COUNT(*) FROM ($group) x",$params),'totals'=>$totals,'explanation'=>'Datoriile și numerarul sunt categorii separate. Sumele negative sunt corecții; numerarul real apare doar la încasări și plăți.'];
  }

  [$where,$params]=Scope::sql($u,'r',false);
  $where.=' AND r.posted_at>=? AND r.posted_at<?';$params[]=$start;$params[]=$end;
  foreach(['participant'=>'participant_id','driver'=>'driver_id','status'=>'status'] as $input=>$column){if($f[$input]!==''){$where.=" AND r.$column=?";$params[]=$f[$input];}}
  if($f['vehicle']){$where.=' AND r.vehicle_plate LIKE ?';$params[]='%'.$f['vehicle'].'%';}
  if($template==='redemptions'){$where.=' AND r.redeemed>0';}
  $base="FROM rides r JOIN participants p ON p.id=r.participant_id JOIN ledger award ON award.ride_id=r.id AND award.kind='earn' AND award.id<=$ledger LEFT JOIN ledger rev ON rev.ride_id=r.id AND rev.kind='reversal' AND rev.id<=$ledger WHERE $where";
  $earnedAdj="CASE WHEN rev.id IS NOT NULL THEN -r.earned ELSE 0 END";
  $redeemedAdj="CASE WHEN rev.id IS NOT NULL THEN -r.redeemed ELSE 0 END";
  $contributionAdj="CASE WHEN rev.id IS NOT NULL THEN -r.contribution ELSE 0 END";
  $net="r.earned-r.redeemed+COALESCE(rev.amount,0)";
  $totals=App::one("SELECT COALESCE(SUM(r.fare),0) fare,COALESCE(SUM(r.redeemed),0) redeemed,COALESCE(SUM(r.earned),0) earned,COALESCE(SUM(r.contribution),0) contribution,
   COALESCE(SUM($earnedAdj),0) earned_adjustment,COALESCE(SUM($redeemedAdj),0) redeemed_adjustment,COALESCE(SUM($contributionAdj),0) contribution_adjustment,
   COALESCE(SUM(r.earned+($earnedAdj)),0) net_earned,COALESCE(SUM(r.redeemed+($redeemedAdj)),0) net_redeemed,COALESCE(SUM(r.contribution+($contributionAdj)),0) net_contribution,COALESCE(SUM($net),0) net_credit $base",$params);
  $meta['explanation']='Cursele din perioada aleasă: valori originale, corecțiile lor înregistrate până la momentul raportului și rezultatul net. Pentru mișcările financiare după data corecției, folosește Registru credit.';
  if($template==='drivers'||$f['group']){
   $column=$f['group']==='participant'?'p.id':'r.driver_id';$label=$column==='p.id'?'p.name':'r.driver_id';
   $group="SELECT $label label,COUNT(*) count,SUM(r.fare) fare,SUM(r.earned) earned,SUM($earnedAdj) adjustment,SUM(r.earned+($earnedAdj)) net_earned,SUM($net) net_credit $base GROUP BY $column,$label";
   $rows=App::all("$group ORDER BY label,$column LIMIT $limit OFFSET $offset",$params);
   return $meta+['columns'=>['Participant / șofer','Curse','Tarif RON','Câștig brut RON','Corecții câștig RON','Câștig net RON','Modificare sold net RON'],'rows'=>array_map(fn($r)=>[$r['label'],$r['count'],self::decimal($r['fare']),self::decimal($r['earned']),self::decimal($r['adjustment']),self::decimal($r['net_earned']),self::decimal($r['net_credit'])],$rows),'total'=>(int)App::scalar("SELECT COUNT(*) FROM ($group) x",$params),'totals'=>$totals];
  }
  $sort=match($f['sort']){'date'=>'r.posted_at ASC','fare'=>'r.fare ASC','-fare'=>'r.fare DESC',default=>'r.posted_at DESC'};
  $columns=$f['columns']?explode(',',$f['columns']):array_keys(self::COLUMNS);
  $rides=App::all("SELECT r.*,p.name participant_name,rev.id reversal_id,rev.amount correction $base ORDER BY $sort,r.id LIMIT $limit OFFSET $offset",$params);$rows=[];
  foreach($rides as $r){
   $reversed=$r['reversal_id']!==null;
   $values=['id'=>$r['id'],'date'=>App::local($r['posted_at']),'participant'=>$r['participant_name'],'driver'=>$r['driver_id'],'vehicle'=>$r['vehicle_plate'],'rule'=>$r['rule_id'],'status'=>$reversed?'Corectat integral':Labels::state($r['status'])];
   foreach(['fare','redeemed','earned','contribution'] as $k){$values[$k]=self::decimal($r[$k]);}
   $values+=['paid'=>self::decimal((int)$r['fare']-(int)$r['redeemed']),'earned_adjustment'=>self::decimal($reversed?-(int)$r['earned']:0),
    'net_earned'=>self::decimal($reversed?0:$r['earned']),'redeemed_adjustment'=>self::decimal($reversed?-(int)$r['redeemed']:0),
    'net_redeemed'=>self::decimal($reversed?0:$r['redeemed']),'net_contribution'=>self::decimal($reversed?0:$r['contribution']),
    'net_credit'=>self::decimal((int)$r['earned']-(int)$r['redeemed']+(int)($r['correction']??0))];
   $rows[]=array_map(fn($k)=>$values[$k],$columns);
  }
  return $meta+['columns'=>array_map(fn($k)=>self::COLUMNS[$k],$columns),'rows'=>$rows,'total'=>(int)App::scalar("SELECT COUNT(*) $base",$params),'totals'=>$totals];
 }
 private static function decimal(mixed $value): string { return number_format((int)$value/100,2,'.',''); }

 public static function safe(mixed $v):string{
  $s=(string)$v;return preg_match('/^[\s]*[=+\-@]/u',$s)||str_starts_with($s,"\t")||str_starts_with($s,"\r")?"'".$s:$s;
 }
 public static function download(array $u,array $d):void{
  $format=$d['format']??'csv';if(!in_array($format,['csv','xlsx','pdf'],true)){App::fail('INVALID_FORMAT','Alegeți CSV, XLSX sau PDF.');}
  $offset=max(0,(int)($d['offset']??0));$limit=min(5000,max(1,(int)($d['limit']??1000)));
  // One repeatable-read snapshot keeps displayed totals and exported rows consistent.
  $report=self::report($u,$d,$limit,$offset);
  App::audit((int)$u['id'],'export.download','report',['format'=>$format,'filters'=>$report['filters'],'offset'=>$offset,'rows'=>count($report['rows']),'total'=>$report['total']]);
  $meta='AlbaTaxi Bonus | '.App::local($report['generated']).' Europe/Bucharest | rânduri '.($offset+1).'-'.($offset+count($report['rows'])).' / '.$report['total'].' | '.json_encode($report['filters'],JSON_UNESCAPED_UNICODE);
  $parts=[];foreach($report['totals'] as $key=>$value){$parts[]=(Labels::totals()[$key]??$key).': '.App::ron($value).' lei';}
  $summary='Totaluri pentru întregul raport: '.implode(' | ',$parts).' | Moment fix: '.App::local($report['snapshot_at']);
  header('Content-Disposition: attachment; filename="albataxi-'.gmdate('Ymd-His').'-'.$offset.'.'.$format.'"');
  if($format==='csv'){
   header('Content-Type: text/csv; charset=utf-8');$out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");
   foreach([[$meta],[$summary],$report['columns'],...$report['rows']] as $row){fputcsv($out,array_map([self::class,'safe'],$row),',','"','');}fclose($out);
  }elseif($format==='xlsx'){
   header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
   $book=new \PhpOffice\PhpSpreadsheet\Spreadsheet();$sheet=$book->getActiveSheet();$row=1;
   foreach([[$meta],[$summary],$report['columns'],...$report['rows']] as $cells){foreach($cells as $col=>$v){$sheet->setCellValueExplicit([$col+1,$row],(string)$v,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);}$row++;}
   (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output');$book->disconnectWorksheets();
  }else{
   $html='<html><meta charset="utf-8"><style>body{font:10px DejaVu Sans}table{border-collapse:collapse;width:100%}td,th{border:1px solid #ddd;padding:4px;word-wrap:break-word}thead{display:table-header-group}tr{page-break-inside:avoid}</style><h1>AlbaTaxi Bonus</h1><p>'.App::e($meta).'</p><p>'.App::e($summary).'</p><table><thead><tr>';
   foreach($report['columns'] as $label){$html.='<th>'.App::e($label).'</th>';}$html.='</tr></thead><tbody>';
   foreach($report['rows'] as $cells){$html.='<tr>';foreach($cells as $v){$html.='<td>'.App::e($v).'</td>';}$html.='</tr>';}$html.='</tbody></table></html>';
   $pdf=new \Dompdf\Dompdf(['isRemoteEnabled'=>false,'isPhpEnabled'=>false,'defaultFont'=>'DejaVu Sans']);$pdf->loadHtml($html);$pdf->setPaper('A4','landscape');$pdf->render();header('Content-Type: application/pdf');echo $pdf->output();
  }
 }
}
