<?php
// Run on a trusted local machine, then import output in phpMyAdmin. No server shell needed.
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/vendor/autoload.php';
$phone=Alba\App::phone($argv[1]??'');$password=getenv('ALBA_ADMIN_PASSWORD')?:'';
Alba\Auth::password($password);$hash=password_hash($password,PASSWORD_DEFAULT);
echo "START TRANSACTION;\n";
echo "INSERT INTO accounts(phone,password_hash,verified,is_admin,terms_at,created_at) VALUES('".$phone."','".$hash."',1,1,UTC_TIMESTAMP(),UTC_TIMESTAMP());\n";
echo "INSERT INTO wallets(passenger_id,balance) VALUES(LAST_INSERT_ID(),0);\nCOMMIT;\n";

