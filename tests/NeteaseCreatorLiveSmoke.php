<?php

declare(strict_types=1);

// Explicit read-only live check. No rating, sign-in, comment, message, or reward is submitted.
require dirname(__DIR__) . '/vendor/autoload.php';

use netease\sdk\Client;

$musicU = (string)getenv('NETEASE_MUSIC_U');
$client = new Client([
    'music_u' => $musicU,
    'csrf' => (string)getenv('NETEASE_CSRF'),
], ['auto_anonymous_token' => false, 'cache_dir' => '', 'timeout' => 20, 'connect_timeout' => 10]);

$requests = [
    'partner_daily_task' => static fn(): array => $client->rawRequest(
        'GET', 'https://interface.music.163.com/api/music/partner/daily/task/get',
        ['referer' => 'https://mp.music.163.com/', 'skip_anonymous' => true]
    ),
    'musician_cycle_tasks' => static fn(): array => $client->request(
        '/api/nmusician/workbench/mission/cycle/list', [], 'weapi', ['skip_anonymous' => true]
    ),
    'musician_stage_tasks' => static fn(): array => $client->request(
        '/api/nmusician/workbench/mission/stage/list', [], 'weapi', ['skip_anonymous' => true]
    ),
];

$summary = ['session_supplied' => $musicU !== '', 'read_only' => true, 'checks' => []];
$failed = false;
foreach ($requests as $name => $request) {
    $response = $request();
    $body = json_decode((string)($response['body'] ?? ''), true);
    $http = (int)($response['status'] ?? 0);
    $code = is_array($body) ? (int)($body['code'] ?? 0) : 0;
    $reachable = $http >= 200 && $http < 300 && in_array($code, [200, 301, 401, 403], true);
    $accessible = $reachable && $code === 200 && is_array($body['data'] ?? null);
    $summary['checks'][$name] = [
        'http' => $http,
        'code' => $code,
        'protocol_reachable' => $reachable,
        'account_access_verified' => $accessible,
    ];
    $failed = $failed || !$reachable || ($musicU !== '' && !$accessible);
}
$summary['note'] = $musicU === ''
    ? '未提供账号；登录校验响应仅证明接口可达，不能证明音乐人任务或评分可入账。'
    : '本检查仅读取任务；实际执行和入账仍需在有相应资格的账号上核验。';
echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
exit($failed ? 1 : 0);
