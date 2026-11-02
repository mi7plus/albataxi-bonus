<?php
declare(strict_types=1);
namespace Alba;
final class QrDesign {
 public const FONTS=['sans'=>'DejaVu Sans','serif'=>'DejaVu Serif','mono'=>'DejaVu Sans Mono'];
 public static function defaults(string $orientation):array{
  $land=$orientation==='landscape';
  $positions=$land?[[60,35,880,90],[60,125,880,55],[145,220,270,270],[585,220,270,270],[80,510,400,60],[520,510,400,60],[60,610,880,65]]:[[60,75,880,150],[60,235,880,80],[300,350,400,400],[300,855,300,300],[100,760,800,70],[100,1165,800,65],[70,1280,860,100]];
  $texts=['Scanează. Călătorește. Revino.','AlbaTaxi Bonus','', '', 'Credit pentru următoarea cursă.','Google Reviews · Povestește călătoria.','Recenzia este opțională și nu influențează bonusul.'];
  $ids=['title','subtitle','bonus','review','bonusLabel','reviewLabel','footer'];$elements=[];
  foreach($ids as $i=>$id){[$x,$y,$w,$h]=$positions[$i];$elements[$id]=compact('x','y','w','h')+['text'=>$texts[$i],'size'=>$i===0?($land?36:52):($i===6||($land&&$i>=4)?23:30),'color'=>'#173e35','bold'=>$i===0,'align'=>'center'];}
  return ['background'=>'#f8f5ed','font'=>'sans','elements'=>$elements];
 }
 public static function access(array $u,int $id):array{
  $m=App::one("SELECT m.*,p.name FROM memberships m JOIN participants p ON p.id=m.participant_id WHERE m.id=? AND m.role='driver' AND m.ended_at IS NULL",[$id]);
  if(!$m){App::fail('NOT_FOUND','Șofer inexistent.',404);}
  if(!$u['is_admin']&&!((int)$m['account_id']===(int)$u['id']&&$m['active']&&!$m['admin_suspended'])){Scope::managed($u,(int)$m['participant_id']);}
  return $m;
 }
 public static function load(int $id,string $orientation):array{
  return QrTemplates::effective($id,$orientation)['design'];
 }
 public static function validate(array $d,string $orientation):array{
  $base=self::defaults($orientation);$height=$orientation==='landscape'?707:1414;
  if(!is_string($d['background']??null)||!preg_match('/^#[0-9a-fA-F]{6}$/D',$d['background'])||!is_string($d['font']??null)||!isset(self::FONTS[$d['font']])){App::fail('INVALID_DESIGN','Alegeți o culoare și un font valid.');}
  $base['background']=$d['background'];$base['font']=$d['font'];
  foreach($base['elements'] as $id=>&$e){
   $v=$d['elements'][$id]??null;if(!is_array($v)){App::fail('INVALID_DESIGN','Lipsește un element din design.');}
   foreach(['x','y','w','h','size'] as $key){if(!isset($v[$key])||!is_numeric($v[$key])||!is_finite((float)$v[$key])){App::fail('INVALID_DESIGN','Dimensiuni invalide.');}$e[$key]=(int)round((float)$v[$key]);}
   $qr=in_array($id,['bonus','review'],true);
   if($e['x']<0||$e['y']<0||$e['w']<($qr?160:60)||$e['h']<($qr?160:30)||$e['x']+$e['w']>1000||$e['y']+$e['h']>$height||($qr&&$e['w']!==$e['h'])||$e['size']<12||$e['size']>100){App::fail('INVALID_DESIGN','Păstrați elementele în pagină și codurile QR pătrate (minimum 160).');}
   if(!is_string($v['color']??null)||!preg_match('/^#[0-9a-fA-F]{6}$/D',$v['color'])||!in_array($v['align']??'',['left','center','right'],true)||!is_string($v['text']??null)||!is_scalar($v['bold']??null)){App::fail('INVALID_DESIGN','Stil invalid.');}
   $e['color']=$v['color'];$e['align']=$v['align'];$e['bold']=App::bool($v['bold']??false);$e['text']=$qr?'':mb_substr(trim((string)($v['text']??'')),0,250);
  }unset($e);
  foreach(['bonus','review'] as $qrId){$q=$base['elements'][$qrId];foreach($base['elements'] as $id=>$other){if($id===$qrId)continue;if($q['x']<$other['x']+$other['w']&&$q['x']+$q['w']>$other['x']&&$q['y']<$other['y']+$other['h']&&$q['y']+$q['h']>$other['y']){App::fail('INVALID_DESIGN','Codurile QR nu pot fi acoperite de alte elemente. Mutați sau micșorați casetele suprapuse.');}}}
  return $base;
 }
 public static function save(array $u,array $data):array{return QrTemplates::save($u,$data);}
 public static function render(array $d,array $qr,string $orientation,bool $demo=false,bool $pdf=false,bool $editable=true):string{
  $height=$orientation==='landscape'?707:1414;$mm=$orientation==='landscape'?297:210;$scale=$mm/1000;$font=self::FONTS[$d['font']];
  $css='.qr-canvas{position:relative;width:100%;aspect-ratio:1000/'.$height.';container-type:inline-size;background:'.$d['background'].';font-family:"'.$font.'",sans-serif;overflow:hidden}.qr-piece{position:absolute;box-sizing:border-box;overflow:hidden;white-space:pre-wrap;line-height:1.18}.qr-piece img{display:block;width:100%;height:100%;max-width:none;background:white}.qr-piece p{margin:0}';
  if($pdf){$css.='.qr-canvas{width:'.$mm.'mm;height:'.($orientation==='landscape'?210:297).'mm}';}
  else{$css.='@media print{@page{size:A4 '.$orientation.';margin:0}.display-page .qr-canvas{width:'.$mm.'mm;height:'.($orientation==='landscape'?210:297).'mm}}';}
  $html='';foreach($d['elements'] as $id=>$e){
   $css.='#piece-'.$id.'{left:'.($e['x']*$scale).'mm;top:'.($e['y']*$scale).'mm;width:'.($e['w']*$scale).'mm;height:'.($e['h']*$scale).'mm;font-size:'.($e['size']*$scale).'mm;color:'.$e['color'].';font-weight:'.($e['bold']?'700':'400').';text-align:'.$e['align'].'}';
   if(!$pdf){$css.='#piece-'.$id.'{left:'.($e['x']/10).'%;top:'.($e['y']/$height*100).'%;width:'.($e['w']/10).'%;height:'.($e['h']/$height*100).'%;font-size:'.($e['size']/10).'cqw}';}
   $isQr=in_array($id,['bonus','review'],true);$content=App::e($e['text']);
   if($isQr){$available=$id==='bonus'||!empty($qr['review_url']);if($demo||$available){$url=$demo?'DEMO - '.$id:App::url('?page='.($id==='bonus'?'scan':'review').'&token='.$qr['token']);$content='<img draggable="false" alt="'.($id==='bonus'?'QR Bonus':'QR Google Reviews').'" src="data:image/png;base64,'.base64_encode(Http::qrImage($url)).'">';}else{$content='Recenzii indisponibile';}}
   $html.='<div id="piece-'.$id.'" class="qr-piece" data-piece="'.$id.'"'.($demo&&$editable?' tabindex="0" role="button" aria-label="Editează '.$id.'"':'').'>'.$content.($demo&&$editable?'<span class="resize-handle" aria-hidden="true"></span>':'').'</div>';
  }
  return '<style nonce="'.App::e(Http::$styleNonce).'">'.$css.'</style><div class="qr-canvas" id="qr-canvas">'.$html.'</div>';
 }
}
