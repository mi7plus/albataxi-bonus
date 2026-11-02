<?php
declare(strict_types=1);
namespace Alba;
final class Problem extends \RuntimeException{
 public function __construct(public readonly string $error,string $message,public readonly int $status=400){parent::__construct($message);}
}
