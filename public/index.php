<?php
declare(strict_types=1);
$root=is_file(__DIR__.'/app-path.php')?require __DIR__.'/app-path.php':dirname(__DIR__);
require $root.'/app/bootstrap.php';
Alba\Http::run();

