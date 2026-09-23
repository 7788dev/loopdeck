<?php

declare(strict_types=1);

namespace app\service;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;

/** Share sockets within a worker, never account headers or cookie jars. */
final class HttpClientFactory
{
    private static ?HandlerStack $handler = null;

    public static function create(array $options = []): Client
    {
        return new Client(array_replace([
            'handler' => self::$handler ??= HandlerStack::create(),
            'http_errors' => false,
            'connect_timeout' => 5.0,
            'timeout' => 15.0,
        ], $options));
    }
}
