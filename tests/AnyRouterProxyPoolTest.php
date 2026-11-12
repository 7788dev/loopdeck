<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/vendor/topthink/framework/src/helper.php';

use app\service\AnyRouterProxyPool;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

function poolCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$directory = sys_get_temp_dir() . '/loopdeck-anyrouter-pool-' . bin2hex(random_bytes(6));
mkdir($directory);
$file = $directory . '/pool.json';
$valid = new Response(200, ['Content-Type' => 'application/json'], '{"success":true,"data":{"system_name":"AnyRouter"}}');
$history = [];
$stack = HandlerStack::create(new MockHandler([new Response(200, [], '8.8.8.8:80'), new Response(200, [], '1.1.1.1:1080'), $valid, $valid]));
$stack->push(Middleware::history($history));
$pool = new AnyRouterProxyPool(new Client(['handler' => $stack]), $file);
$previous = getenv('ANYROUTER_PLATFORM_PROXY');
putenv('ANYROUTER_PLATFORM_PROXY');
try {
    $pool->connect(['proxy_mode' => 'platform']);
    poolCheck(count($history) === 4, 'Unexpected discovery requests');
    poolCheck(str_contains((string)$history[0]['request']->getUri(), 'cdn.jsdelivr.net/gh/monosans/proxy-list'), 'Domestic source mirror missing');
    foreach ($history as $entry) {
        poolCheck(!$entry['request']->hasHeader('Cookie') && !$entry['request']->hasHeader('new-api-user'),
            'Proxy discovery exposed account credentials');
    }
    $cache = json_decode(file_get_contents($file), true);
    poolCheck(count($cache['candidates']) === 2 && count($cache['verified']) > 0, 'Pool did not cache verified candidates');

    // A cached entry must be revalidated even before its expiry; HTML is never healthy.
    file_put_contents($file, json_encode(['candidates' => ['http://8.8.8.8:80', 'socks5h://1.1.1.1:1080'],
        'verified' => ['http://8.8.8.8:80' => time()], 'updated_at' => time(), 'cursor' => 1]));
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], '<html>success</html>'), $valid, $valid]));
    $stack->push(Middleware::history($history));
    (new AnyRouterProxyPool(new Client(['handler' => $stack]), $file))->connect([]);
    poolCheck($history[0]['options']['proxy'] === 'http://8.8.8.8:80'
        && $history[1]['options']['proxy'] === 'socks5h://1.1.1.1:1080', 'Dead cached endpoint prevented cursor advancement');

    // Caller-supplied proxy must not fall back to public sources or the fixed platform relay.
    putenv('ANYROUTER_PLATFORM_PROXY=http://127.0.0.1:7890');
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(403)]));
    $stack->push(Middleware::history($history));
    try {
        (new AnyRouterProxyPool(new Client(['handler' => $stack]), $file))->connect([
            'proxy_mode' => 'custom', 'proxy_url' => 'http://8.8.8.8:8080']);
        throw new LogicException('Failed custom proxy was accepted');
    } catch (RuntimeException $expected) {
        poolCheck(str_contains($expected->getMessage(), '提供的代理'), 'Custom failure lost its explanation');
    }
    poolCheck(count($history) === 1 && $history[0]['options']['proxy'] === 'http://8.8.8.8:8080', 'Custom proxy silently fell back');

    $history = [];
    $stack = HandlerStack::create(new MockHandler([$valid]));
    $stack->push(Middleware::history($history));
    (new AnyRouterProxyPool(new Client(['handler' => $stack]), $file))->connect([]);
    poolCheck($history[0]['options']['proxy'] === 'http://127.0.0.1:7890', 'Operator local relay was not honored');

    // Corrupt/future-dated cache entries cannot inject a private endpoint or suppress refresh.
    putenv('ANYROUTER_PLATFORM_PROXY');
    file_put_contents($file, json_encode(['candidates' => ['http://127.0.0.1:80'],
        'verified' => ['http://127.0.0.1:80' => time()], 'updated_at' => time() + 3600, 'cursor' => 0]));
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], '8.8.8.8:80'), new Response(200, [], '1.1.1.1:80'), $valid, $valid]));
    $stack->push(Middleware::history($history));
    (new AnyRouterProxyPool(new Client(['handler' => $stack]), $file))->connect([]);
    foreach ($history as $entry) {
        poolCheck(!str_contains((string)($entry['options']['proxy'] ?? ''), '127.0.0.1'), 'Cache bypassed private-address rejection');
    }
} finally {
    putenv($previous === false ? 'ANYROUTER_PLATFORM_PROXY' : 'ANYROUTER_PLATFORM_PROXY=' . $previous);
    foreach (glob($directory . '/*') as $path) {
        unlink($path);
    }
    rmdir($directory);
}

echo "AnyRouter platform discovery, cache revalidation, cursor and custom-proxy isolation tests passed\n";
