<?php

declare(strict_types=1);

namespace app\service;

use RuntimeException;

/** A human-entered login challenge. Context remains on the server. */
final class PlatformVerification extends RuntimeException
{
    public function __construct(
        public readonly array $context,
        public readonly string $image,
        string $message = '请输入图片验证码后重新提交'
    ) {
        parent::__construct($message);
    }
}
