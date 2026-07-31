<?php
declare(strict_types=1);

namespace app\exception;

use Throwable;

class BusinessException extends \Exception
{
    public function __construct(string $message = "", int $code = 100, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}