<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
date_default_timezone_set('UTC');
$local=dirname(__DIR__).'/config.local.php';
$config=is_file($local)?require $local:[];
foreach(['environment'=>'APP_ENV','base_url'=>'APP_URL','db_dsn'=>'DB_DSN','db_user'=>'DB_USER','db_password'=>'DB_PASSWORD','app_key'=>'APP_KEY','sms_backend'=>'SMS_BACKEND','sms_url'=>'SMS_URL','sms_token'=>'SMS_TOKEN','smslink_connection_id'=>'SMSLINK_CONNECTION_ID','smslink_password'=>'SMSLINK_PASSWORD','smslink_sender'=>'SMSLINK_SENDER'] as $key=>$name){
 if(getenv($name)!==false){$config[$key]=getenv($name);}
}
$config+=['environment'=>'production','base_url'=>'http://localhost:8080','sms_backend'=>'disabled','sms_url'=>'','sms_token'=>'','smslink_connection_id'=>'','smslink_password'=>'','smslink_sender'=>'','maintenance'=>false];
if(strlen($config['app_key']??'')<32){throw new RuntimeException('Configure a random app_key of at least 32 characters.');}
Alba\App::boot($config);
