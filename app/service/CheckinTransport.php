<?php

declare(strict_types=1);

namespace app\service;

/** Injectable HTTP boundary for check-in adapters (offline tests replace it). */
interface CheckinTransport
{
    /** @return array{status:int,body:string,headers:array,url:string} */
    public function request(string $method, string $url, array $options = []): array;
}
