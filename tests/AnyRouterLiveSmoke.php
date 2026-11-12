<?php

declare(strict_types=1);

// Explicit opt-in script: anonymous GETs only, no account database, credentials or sign-in POST.
require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/vendor/topthink/framework/src/helper.php';

use app\service\AnyRouterProxyPool;

$cache = sys_get_temp_dir() . '/loopdeck-anyrouter-smoke-proxies.json';
$started = microtime(true);
try {
    $client = (new AnyRouterProxyPool(null, $cache))->connect(['proxy_mode' => 'platform']);
    $valid = $client->probeAsync(microtime(true) + 12)->wait();
    echo json_encode(['success' => $valid, 'anonymous' => true, 'explicit_proxy' => true,
        'tls_verified' => true, 'api' => '/api/status', 'seconds' => round(microtime(true) - $started, 2)], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($valid ? 0 : 1);
} catch (Throwable $exception) {
    echo json_encode(['success' => false, 'anonymous' => true, 'seconds' => round(microtime(true) - $started, 2),
        'message' => '当前代理未通过有效 API 响应验证，可稍后重试'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}
