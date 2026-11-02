<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$u=Alba\App::one('SELECT * FROM accounts WHERE id=?',[(int)$argv[1]]);
Alba\App::tx(function()use($u,$argv){
 Alba\Loyalty::post($u,$argv[2],$argv[3]);
 echo "uncommitted\n";flush();
 usleep(450000);
});
echo "committed\n";

