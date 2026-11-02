<?php
// Copy outside public_html as config.local.php. Never commit real secrets.
return [
 'environment'=>'production','base_url'=>'https://bonus.example.com',
 'db_dsn'=>'mysql:host=localhost;dbname=YOUR_DATABASE;charset=utf8mb4',
 'db_user'=>'YOUR_DATABASE_USER','db_password'=>'REPLACE_WITH_DATABASE_PASSWORD',
 'app_key'=>'REPLACE_WITH_64_RANDOM_HEX_CHARACTERS',
 'sms_backend'=>'disabled', // Set smslink after configuring credentials; database only in development
 'smslink_connection_id'=>'','smslink_password'=>'','smslink_sender'=>'', // Blank sender uses provider default
 'sms_url'=>'https://YOUR_SMS_GATEWAY.example/send','sms_token'=>'REPLACE_WITH_SMS_TOKEN',
 'maintenance'=>false,
];
