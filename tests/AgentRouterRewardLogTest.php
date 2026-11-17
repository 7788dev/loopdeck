<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use agentrouter\Client;
use app\service\AgentRouterTaskExecutor;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

function rewardLogCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rewardLogJson(array $body, int $status = 200): Response
{
    return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
}

function rewardLogExecute(array $before, array $responses): array
{
    $history = [];
    $stack = HandlerStack::create(new MockHandler(array_merge([
        rewardLogJson(['success' => true, 'data' => ['system_name' => 'Agent Router',
            'quota_per_unit' => 500000, 'display_in_currency' => true]]),
    ], $responses)));
    $stack->push(Middleware::history($history));
    $client = new Client(new HttpClient(['handler' => $stack]));
    rewardLogCheck($client->probeAsync(microtime(true) + 5)->wait(), 'Anonymous fixture probe failed');
    $account = ['username' => 'reward-fixture', 'password' => 'reward-private-password', 'profile' => $before];
    $result = (new AgentRouterTaskExecutor(static fn() => $client))->execute('sign_in', $account);
    rewardLogCheck($account['profile'] === $before, 'Reward logging mutated the stored profile');
    rewardLogCheck(!str_contains($result['message'], 'reward-private-password'), 'Reward log exposed credentials');
    return [$result, $history];
}

$before = ['id' => '42', 'quota' => 62500000, 'quota_per_unit' => 500000, 'display_in_currency' => true];
$cases = [
    ['new check-in', $before, ['quota' => 75000000], true],
    ['first check-in without previous metrics', [], ['quota' => 75000000], true],
    ['balance decreased due to usage', $before, ['quota' => 60000000], true],
    ['balance includes other rewards', $before, ['quota' => 125000000], true],
    ['confirmed zero balance', $before, ['quota' => 0], true],
    ['missing balance', $before, [], true],
    ['balance lookup failed', $before, new Response(500, [], 'unavailable'), true],
    ['already checked in', $before, ['quota' => 62500000], false],
    ['already checked in with unrelated balance increase', $before, ['quota' => 125000000], false],
];

foreach ($cases as [$name, $previous, $current, $checkedIn]) {
    // The upstream's checked_in flag confirms the reward; balances can include unrelated changes.
    $login = rewardLogJson(['success' => true, 'data' => ['id' => 42, 'checked_in' => $checkedIn, 'quota' => 0]])
        ->withHeader('Set-Cookie', 'session=reward-fixture; Path=/; Secure; HttpOnly');
    $self = $current instanceof Response ? $current : rewardLogJson(['success' => true, 'data' => ['id' => 42] + $current]);
    [$result, $history] = rewardLogExecute($previous, [$login, $self, rewardLogJson(['success' => true])]);
    $expected = $checkedIn ? '本次签到获得 $25.00 额度' : '今日已签到';
    rewardLogCheck($result['success'] && $result['message'] === $expected,
        $name . ': expected ' . $expected . ', got ' . $result['message']);
    rewardLogCheck(!str_contains($result['message'], '余额') && !str_contains($result['message'], '较上次'),
        $name . ': reward log still included balance comparisons');
    $requests = array_map(static fn($entry) => $entry['request']->getMethod() . ' ' . $entry['request']->getUri()->getPath(), $history);
    rewardLogCheck($requests === ['GET /api/status', 'POST /api/user/login', 'GET /api/user/self', 'GET /api/user/logout'],
        $name . ': reward logging added a login or another upstream request');
    rewardLogCheck(!$history[1]['request']->hasHeader('Cookie')
        && $history[2]['request']->getHeaderLine('Cookie') === 'session=reward-fixture'
        && $history[2]['request']->getHeaderLine('New-API-User') === '42'
        && $history[3]['request']->getHeaderLine('Cookie') === 'session=reward-fixture',
        $name . ': login, balance query and logout did not use the expected fresh session');
    $quota = $current instanceof Response ? null : ($current['quota'] ?? null);
    rewardLogCheck(($result['profile']['quota'] ?? null) === ($quota === null ? null : (float)$quota),
        $name . ': reward logging substituted a reward or login placeholder for confirmed balance metrics');
}

// An unconfirmed login/logout keeps failure/retry semantics and never claims the daily reward.
foreach ([
    [rewardLogJson(['success' => false, 'message' => '用户名或密码错误'], 401)],
    [new Response(429, [], 'too many requests')],
    [rewardLogJson(['success' => true, 'data' => ['id' => 42, 'checked_in' => true]]),
        rewardLogJson(['success' => true, 'data' => ['id' => 42, 'quota' => 75000000]]), new Response(500, [], 'down')],
] as $responses) {
    [$result, $history] = rewardLogExecute($before, $responses);
    rewardLogCheck(!$result['success'] && !str_contains($result['message'], '获得')
        && !str_contains($result['message'], '$25'), 'Failed execution claimed a completed sign-in reward');
    rewardLogCheck(count(array_filter($history, static fn($entry) => $entry['request']->getMethod() === 'POST')) === 1,
        'Failed execution replayed login while formatting the reward');
}

echo "AgentRouter sign-in reward copy, unrelated balance changes and single-session log tests passed\n";
