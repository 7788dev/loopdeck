<?php

declare(strict_types=1);

namespace app\service;

use RuntimeException;

/** Only fixed, credential-free messages may cross the adapter boundary. */
final class PlatformException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $accountInvalid = false,
        public readonly int $retryAfter = 300
    ) {
        parent::__construct($message);
    }
}
