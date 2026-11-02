<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$u=Alba\App::one('SELECT * FROM accounts WHERE id=?',[(int)$argv[1]]);
while(microtime(true)<(float)$argv[4]){usleep(1000);}
try{echo json_encode(Alba\Loyalty::post($u,$argv[2],$argv[3]));}
catch(Alba\Problem $e){echo json_encode(['error'=>$e->error]);}

