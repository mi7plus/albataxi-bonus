<?php
declare(strict_types=1);
final class Browser {
 public string $cookie='';public string $csrf='';
 public function __construct(public string $base){}
 public function request(string $query='',?array $data=null,bool $json=false,?string $key=null):array{
  $headers="Connection: close\r\n";if($this->cookie){$headers.="Cookie: ".$this->cookie."\r\n";}
  if($data!==null){$headers.='Content-Type: '.($json?'application/json':'application/x-www-form-urlencoded')."\r\nX-CSRF-Token: ".$this->csrf."\r\n";}
  if($key){$headers.="Idempotency-Key: $key\r\n";}
  $context=stream_context_create(['http'=>['method'=>$data!==null?'POST':'GET','header'=>$headers,'content'=>$data===null?'':($json?json_encode($data):http_build_query($data)),'ignore_errors'=>true,'follow_location'=>0,'timeout'=>30]]);
  $body=file_get_contents($this->base.$query,false,$context);if($body===false){throw new RuntimeException('HTTP test target is not ready: '.$this->base);}$h=$http_response_header??[];preg_match('/\s(\d{3})\s/',$h[0]??'',$m);$status=(int)($m[1]??0);
  foreach($h as $header){if(stripos($header,'Set-Cookie: alba_session=')===0){$this->cookie=explode(';',substr($header,12))[0];}}
  if(preg_match('/name="csrf-token" content="([^"]+)"/',$body,$m)){$this->csrf=$m[1];}
  return [$status,$body,$h];
 }
 public function login(string $phone,string $password):void{
  $this->request('?page=login');[$status]=$this->request('?page=login',['csrf'=>$this->csrf,'phone'=>$phone,'password'=>$password]);
  if($status!==303){throw new RuntimeException('Login failed '.$phone.': '.$status);}$this->request();
 }
}
