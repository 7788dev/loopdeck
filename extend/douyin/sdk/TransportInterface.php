<?php

declare(strict_types=1);

namespace douyin\sdk;

interface TransportInterface
{
    /**
     * @return array{status:int,headers:array<string,array<int,string>>,body:string,header:string,set_cookie:array<int,string>}
     */
    public function request(string $method, string $url, array $options = []): array;
}
