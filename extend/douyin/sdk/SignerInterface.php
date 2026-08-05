<?php

declare(strict_types=1);

namespace douyin\sdk;

interface SignerInterface
{
    public function sign(string $url, string $method = 'GET', string $body = ''): string;
}
