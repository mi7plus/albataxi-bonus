<?php
declare(strict_types=1);
namespace Alba;
final class Http {
 public static string $styleNonce='';
 public static function json(array $data,int $status=200):void{http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);}
 public static function redirect(string $url):never{header('Location: '.$url, true,303);exit;}
 public static function view(string $name,array $data=[]):void{extract($data,EXTR_SKIP);ob_start();try{require dirname(__DIR__).'/views/'.$name.'.php';$html=ob_get_clean();}catch(\Throwable $e){ob_end_clean();throw $e;}echo $html;}
 public static function run():void{
  self::$styleNonce=base64_encode(random_bytes(18));
  header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');header('Referrer-Policy: same-origin');
  header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'nonce-".self::$styleNonce."'; script-src 'self'; connect-src 'self'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
  if(App::$config['environment']!=='development'){header('Strict-Transport-Security: max-age=31536000');}
  $page=is_string($_GET['page']??null)?$_GET['page']:'home';
  try{
   foreach($_GET as $value){if(!is_string($value)){App::fail('INVALID_INPUT','Parametru invalid.');}}
   foreach($_POST as $value){if(!is_string($value)){App::fail('INVALID_INPUT','Câmp invalid.');}}
   if($page==='health'){App::scalar('SELECT 1');self::json(['status'=>'ready']);return;}
   if(App::$config['maintenance']){App::fail('MAINTENANCE','Programul este în mentenanță. Reîncercați mai târziu.',503);}
   Auth::start();
   if($_SERVER['REQUEST_METHOD']==='POST'){Auth::csrf();}
   if($page==='api'){
    $u=Auth::user();$action=(string)($_GET['action']??'');
    if($_SERVER['REQUEST_METHOD']==='POST'){
     $d=str_contains($_SERVER['CONTENT_TYPE']??'','application/json')?json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR):$_POST;
     if(!is_array($d)){App::fail('INVALID_INPUT','Date invalide.');}
     foreach($d as $value){if(!is_scalar($value)&&$value!==null){App::fail('INVALID_INPUT','Câmp invalid.');}}
     unset($d['csrf']);$key=$_SERVER['HTTP_IDEMPOTENCY_KEY']??$d['key']??'';unset($d['key']);
     self::json(['ok'=>true]+Commands::execute($u,$action,$d,(string)$key));return;
    }
    if($action==='report'){self::json(Reports::report($u,$_GET,50,max(0,(int)($_GET['offset']??0))));return;}
    if($action==='reconcile'){App::admin($u);$result=Loyalty::reconcile();App::audit((int)$u['id'],'admin.reconciliation','wallets',['differences'=>count($result['differences'])]);self::json($result+['checked_at'=>App::now()]);return;}
    if($action==='operation'){
     $op=App::one('SELECT result FROM operations WHERE actor_id=? AND kind=? AND operation_key=?',[$u['id'],$_GET['kind']??'',$_GET['key']??'']);
     self::json(['found'=>(bool)$op,'result'=>$op?json_decode($op['result'],true):null]);return;
    }
    if($action==='participants'){
     $q=trim((string)($_GET['search']??''));$offset=max(0,(int)($_GET['offset']??0));
     if($u['is_admin']){$where='p.name LIKE ?';$p=['%'.$q.'%'];}
     else{$where="p.name LIKE ? AND EXISTS(SELECT 1 FROM memberships m WHERE m.participant_id=p.id AND m.account_id=? AND m.role='manager' AND m.active=1 AND m.admin_suspended=0 AND m.ended_at IS NULL) AND p.status='active'";$p=['%'.$q.'%',$u['id']];}
     self::json(['items'=>App::all("SELECT p.id,p.name,p.status,p.kind FROM participants p WHERE $where ORDER BY p.name LIMIT 50 OFFSET $offset",$p),'total'=>(int)App::scalar("SELECT COUNT(*) FROM participants p WHERE $where",$p)]);return;
    }
    if($action==='verify'){
     App::admin($u);
     $tables=['accounts'=>'id,phone,verified,active,is_admin,terms_at,marketing,created_at','participants'=>'*','memberships'=>'*','vehicles'=>'*','rules'=>'*','rides'=>'*','ledger'=>'*','fund_entries'=>'*','settlements'=>'*','corrections'=>'*','risk_flags'=>'*','support_cases'=>'*','scan_events'=>'*','audit_events'=>'*'];
     $entity=(string)($_GET['entity']??'rides');if(!isset($tables[$entity])){App::fail('INVALID_ENTITY','Registru invalid.');}
     $offset=max(0,(int)($_GET['offset']??0));$columns=$tables[$entity];$id=(string)($_GET['id']??'');$where=$id!==''?'id=?':'1=1';$p=$id!==''?[$id]:[];
     App::audit((int)$u['id'],'admin.record_access',$entity,['id'=>$id,'offset'=>$offset]);
     self::json(['items'=>App::all("SELECT $columns FROM $entity WHERE $where ORDER BY id DESC LIMIT 50 OFFSET $offset",$p),'total'=>(int)App::scalar("SELECT COUNT(*) FROM $entity WHERE $where",$p)]);return;
    }
    App::fail('NOT_FOUND','Resursă inexistentă.',404);
   }
   if(in_array($page,['login','register','verify','recover'],true)){self::auth($page);return;}
   if($page==='logout'){
    if($_SERVER['REQUEST_METHOD']!=='POST'){App::fail('METHOD','Folosiți butonul de ieșire.',405);}
    Auth::renew(null,[]);self::redirect(App::url('?page=login'));
   }
   if($page==='terms'){self::view('terms');return;}
   if(in_array($page,['scan','review','qr','display','qr-pdf'],true)){self::publicPage($page);return;}
   if($page==='invitation'){
    $token=(string)($_GET['token']??'');$inv=App::one("SELECT i.*,p.name FROM invitations i JOIN participants p ON p.id=i.participant_id WHERE i.token=? AND i.status='pending' AND i.expires_at>?",[$token,App::now()]);
    if(!$inv){App::fail('INVITATION_INVALID','Invitația a expirat sau a fost revocată.',404);}
    $context=Auth::context();$context['invitation']=$token;Auth::context($context);$u=Auth::user(false,false);
    if($_SERVER['REQUEST_METHOD']==='POST'){
     $u=Auth::user();Memberships::accept($u,$token);unset($context['invitation']);Auth::context($context);self::redirect(App::url());
    }self::view('invitation',['invitation'=>$inv,'user'=>$u]);return;
   }
   $u=Auth::user();$context=Auth::context();
   if(isset($context['invitation'])){self::redirect(App::url('?page=invitation&token='.$context['invitation']));}
   if(isset($context['qr'])){
    try{Loyalty::ready($u,$context['qr']);}catch(Problem $e){$context['notice']=$e->getMessage();}
    unset($context['qr']);Auth::context($context);
   }
   if($page==='download'){Reports::download($u,$_GET);return;}
   if(in_array($page,['qr-editor','qr-template-preview'],true)){
    $editor=QrTemplates::editor($u,$_GET);
    self::view($page==='qr-editor'?'qr-editor':'qr-template-preview',$editor);return;
   }
   if($page==='ride'){
    $ride=Scope::ride($u,(string)($_GET['id']??''));
    if($u['is_admin']){App::audit((int)$u['id'],'admin.ride_access',$ride['id']);}
    self::view('ride',['user'=>$u,'ride'=>$ride,'rule'=>App::one('SELECT * FROM rules WHERE id=?',[$ride['rule_id']]),'ledger'=>App::all('SELECT * FROM ledger WHERE ride_id=? ORDER BY id',[$ride['id']]),'correction'=>App::one('SELECT * FROM corrections WHERE ride_id=?',[$ride['id']]),'driver'=>App::one('SELECT account_id FROM memberships WHERE id=?',[$ride['driver_id']])]);return;
   }
   if($page!=='home'){App::fail('NOT_FOUND','Pagina nu există.',404);}
   self::workspace($u);
  }catch(Problem $e){
   if($page==='api'){self::json(['error'=>$e->error,'message'=>$e->getMessage()],$e->status);return;}
   if($e->error==='AUTH_REQUIRED'){self::redirect(App::url('?page=login'));}
   if($e->error==='PHONE_UNVERIFIED'&&!in_array($page,['verify','api'],true)){self::redirect(App::url('?page=verify'));}
   http_response_code($e->status);self::view('error',['message'=>$e->getMessage()]);
  }catch(\JsonException|\InvalidArgumentException $e){self::json(['error'=>'INVALID_INPUT','message'=>'Date invalide.'],400);}
  catch(\PDOException $e){
   $code=(int)($e->errorInfo[1]??0);
   if(in_array($code,[1062,1205,1213],true)){self::json(['error'=>'CONFLICT','message'=>'Date duplicate sau operațiune concurentă. Verificați rezultatul.'],409);return;}
   self::unexpected($e,$page);
  }catch(\Throwable $e){self::unexpected($e,$page);}
 }
 private static function unexpected(\Throwable $e,string $page):void{
  $ref=bin2hex(random_bytes(5));error_log('Alba error '.$ref.' '.get_class($e).' '.basename($e->getFile()).':'.$e->getLine());
  if($page==='api'){self::json(['error'=>'SERVER_ERROR','message'=>'Eroare temporară. Verificați operațiunea înainte de reluare. Referință '.$ref],500);}
  else{http_response_code(500);self::view('error',['message'=>'Serviciu indisponibil temporar. Referință '.$ref]);}
 }
 private static function auth(string $mode):void{
  $error='';$notice='';$u=Auth::user(false,false);$phone=(string)($_POST['phone']??$u['phone']??'');
  $fullName=(string)($_POST['full_name']??'');$dateOfBirth=(string)($_POST['date_of_birth']??'');
  if($_SERVER['REQUEST_METHOD']==='POST'){
   try{
    $ip=$_SERVER['REMOTE_ADDR']??'unknown';$phone=App::phone($phone);
    if($mode==='register'){
     Auth::throttle('register:'.$ip,10);$u=Auth::create($phone,(string)($_POST['password']??''),App::bool($_POST['terms']??false),App::bool($_POST['marketing']??false),$fullName,$dateOfBirth);
     Auth::renew($u,Auth::context());
     try{Auth::requestOtp($phone,'verify',$ip);}catch(Problem $e){$context=Auth::context();$context['notice']=$e->getMessage();Auth::context($context);}
     self::redirect(App::url('?page=verify'));
    }elseif($mode==='login'){
     $u=Auth::login($phone,(string)($_POST['password']??''),$ip);Auth::renew($u,Auth::context());self::redirect(App::url($u['verified']?'':'?page=verify'));
    }else{
     $purpose=$mode==='recover'?'recover':'verify';
     if(($_POST['action']??'')==='request'){Auth::requestOtp($phone,$purpose,$ip);$notice='Dacă numărul este eligibil, veți primi un cod SMS.';}
     else{
      Auth::throttle('consume:'.$ip,30);Auth::consumeOtp($phone,$purpose,(string)($_POST['code']??''),$_POST['password']??null);
      if($purpose==='recover'){Auth::renew(null,[]);self::redirect(App::url('?page=login'));}
      self::redirect(App::url());
     }
    }
   }catch(Problem $e){$error=$e->getMessage();}
  }
  self::view('auth',compact('mode','error','notice','phone','fullName','dateOfBirth'));
 }
 private static function publicPage(string $page):void{
  $token=(string)($_GET['token']??'');$display=in_array($page,['display','qr-pdf'],true);
  $column=$display?'display_token':'token';
  $qr=App::one("SELECT q.*,m.participant_id,p.name,p.review_url FROM qr_pages q JOIN memberships m ON m.id=q.membership_id JOIN participants p ON p.id=m.participant_id WHERE q.$column=?",[$token]);
  if(!$qr){App::fail('QR_REVOKED','Codul QR a expirat sau a fost revocat.',404);}
  if($page==='qr'){
   $kind=($_GET['kind']??'bonus')==='review'?'review':'scan';
   if($kind==='review'&&!$qr['review_url']){App::fail('REVIEW_UNAVAILABLE','Pagina de recenzii nu este configurată.',404);}
   header('Content-Type: image/png');echo self::qrImage(App::url('?page='.$kind.'&token='.$token));return;
  }
  if($display){
   $orientation=($_GET['orientation']??'portrait')==='landscape'?'landscape':'portrait';
   if($page==='qr-pdf'){self::qrPdf($qr,$orientation);return;}
   $design=QrDesign::load((int)$qr['membership_id'],$orientation);self::view('display',compact('qr','orientation','design'));return;
  }
  Loyalty::driver((int)$qr['membership_id']);Auth::throttle('scan:'.$qr['id'].':'.($_SERVER['REMOTE_ADDR']??''),60,60);
  Loyalty::event((int)$qr['membership_id'],$page==='review'?'review_open':'bonus_open');
  if($page==='review'){
   if(!$qr['review_url']){App::fail('REVIEW_UNAVAILABLE','Pagina de recenzii nu este configurată.',404);}
   self::redirect(Memberships::reviewUrl($qr['review_url']));
  }
  $context=Auth::context();$context['qr']=$token;Auth::context($context);$u=Auth::user(false,false);
  if($u&&$u['verified']){Loyalty::ready($u,$token);unset($context['qr']);Auth::context($context);self::redirect(App::url());}
  self::view('landing',['qr'=>$qr]);
 }
 public static function qrImage(string $url):string{
  $options=new \chillerlan\QRCode\QROptions(['outputType'=>\chillerlan\QRCode\QRCode::OUTPUT_IMAGE_PNG,'outputBase64'=>false,'scale'=>8,'quietzoneSize'=>4]);
  return (new \chillerlan\QRCode\QRCode($options))->render($url);
 }
 private static function qrPdf(array $qr,string $orientation):void{
  $design=QrDesign::load((int)$qr['membership_id'],$orientation);
  $html='<html><meta charset="utf-8"><style>@page{margin:0}body{margin:0}</style>'.QrDesign::render($design,$qr,$orientation,false,true).'</html>';
  $pdf=new \Dompdf\Dompdf(['isRemoteEnabled'=>false,'isPhpEnabled'=>false,'defaultFont'=>'DejaVu Sans']);$pdf->loadHtml($html);$pdf->setPaper('A4',$orientation);$pdf->render();
  header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="albataxi-qr-'.$orientation.'.pdf"');echo $pdf->output();
 }
 private static function workspace(array $u):void{
  self::view('workspace',Workspace::data($u));
 }
}
