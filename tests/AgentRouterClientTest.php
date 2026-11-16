<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use agentrouter\Client;
use app\service\AgentRouterTaskExecutor;
use app\service\AgentRouterAccountInfo;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

function agentCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function agentJson(array $body, int $status = 200): Response
{
    return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_UNESCAPED_UNICODE));
}

function agentClient(array $responses, array &$history): Client
{
    $stack = HandlerStack::create(new MockHandler(array_merge($responses, [agentJson(['success' => true])])));
    $stack->push(Middleware::history($history));
    return new Client(new HttpClient(['handler' => $stack]));
}

$probe = agentJson(['success' => true, 'data' => ['system_name' => 'Agent Router', 'quota_per_unit' => 500000, 'display_in_currency' => true]]);
$loginOk = agentJson(['success' => true, 'data' => ['id' => 42, 'checked_in' => false, 'access_token' => 'secret-token']])
    ->withHeader('Set-Cookie', 'session=fixture-session; Path=/; Secure; HttpOnly');
$selfOk = agentJson(['success' => true, 'data' => ['id' => 42, 'display_name' => '', 'username' => 'fixture-user',
    'quota' => 62500000, 'used_quota' => 1750000, 'request_count' => 321, 'group' => 'default',
    'password' => 'never-render-password', 'access_token' => 'never-render-token', 'email' => 'private@example.com']]);
$credentials = ['username' => 'fixture-user', 'password' => 'private-password'];

// Profile projection keeps only public fields and uses the site conversion unit.
$history = [];
$client = agentClient([$probe, $loginOk, $selfOk], $history);
$client->probeAsync(microtime(true) + 5)->wait();
$login = $client->login($credentials['username'], $credentials['password']);
agentCheck($login['success'] && $login['already'], 'Login-triggered check-in was not reported as already done');
$profile = $login['profile'];
agentCheck($profile['nickname'] === 'fixture-user', 'Empty display name did not fall back to username');
agentCheck(($profile['quota'] ?? null) === 62500000.0 && ($profile['quota_per_unit'] ?? null) === 500000.0,
    'Balance or site conversion unit missing');
agentCheck(!str_contains(json_encode($profile), 'never-render') && !isset($profile['email']) && !isset($profile['access_token']),
    'Sensitive self response fields leaked into the account profile');
$presented = AgentRouterAccountInfo::present($profile, '', 'fixture-user');
agentCheck($presented['balance'] === '$125.00' && $presented['used'] === '$3.50' && $presented['requests'] === '321',
    'Profile display did not use the site quota conversion unit');
$unknown = AgentRouterAccountInfo::present([], '已保存的名称', 'fixture-user');
agentCheck($unknown['balance'] === '暂不可用' && $unknown['nickname'] === '已保存的名称',
    'Missing metrics became zero or the remark was lost');

// Request shape: anonymous probe, exactly one login POST, balance GET and logout, direct connection.
agentCheck(count($history) === 4, 'Unexpected request count for probe/login/self/logout');
agentCheck(count(array_filter($history, static fn($e) => $e['request']->getMethod() === 'POST')) === 1,
    'More than one POST was sent in a single execution');
agentCheck($history[0]['request']->getMethod() === 'GET' && (string)$history[0]['request']->getUri()->getPath() === '/api/status'
    && !$history[0]['request']->hasHeader('new-api-user') && !str_contains((string)$history[0]['request']->getBody(), 'private-password'),
    'Anonymous probe carried account credentials');
$loginRequest = $history[1]['request'];
agentCheck($loginRequest->getMethod() === 'POST' && (string)$loginRequest->getUri()->getPath() === '/api/user/login',
    'Check-in did not use the login endpoint');
$loginBody = json_decode((string)$loginRequest->getBody(), true);
agentCheck(($loginBody['username'] ?? null) === 'fixture-user' && ($loginBody['password'] ?? null) === 'private-password',
    'Login payload did not carry the credentials as JSON');
agentCheck($history[2]['request']->getMethod() === 'GET' && (string)$history[2]['request']->getUri()->getPath() === '/api/user/self'
    && $history[2]['request']->getHeaderLine('new-api-user') === '42'
    && $history[2]['request']->getHeaderLine('Cookie') === 'session=fixture-session', 'Balance lookup session or request shape changed');
agentCheck($history[3]['request']->getMethod() === 'GET' && $history[3]['request']->getUri()->getPath() === '/api/user/logout'
    && $history[3]['request']->getHeaderLine('Cookie') === 'session=fixture-session', 'The login session was not logged out');
foreach ($history as $entry) {
    agentCheck((string)$entry['request']->getUri()->getHost() === 'ps.air-outer.com', 'Unexpected credential destination');
    agentCheck($entry['options']['proxy'] === '' && $entry['options']['curl'][CURLOPT_PROXY] === ''
        && $entry['options']['curl'][CURLOPT_NOPROXY] === '*' && $entry['options']['verify'] === true
        && $entry['options']['allow_redirects'] === false, 'Direct connection, TLS or redirect protection lost');
}

// A failed balance lookup never turns a confirmed check-in into a failure.
$history = [];
$client = agentClient([$probe, $loginOk, new Response(500, [], 'down')], $history);
$client->probeAsync(microtime(true) + 5)->wait();
$partial = $client->login($credentials['username'], $credentials['password']);
agentCheck($partial['success'] && ($partial['profile']['quota'] ?? null) === null,
    'Balance failure revoked the check-in or invented a balance');

// Live login responses contain zero placeholders even for a funded account.
// Only a confirmed /self response can supply balance and usage metrics.
$placeholderLogin = agentJson(['success' => true, 'data' => ['id' => 42, 'checked_in' => true,
    'quota' => 0, 'used_quota' => 0, 'request_count' => 0]])
    ->withHeader('Set-Cookie', 'session=fixture-session; Path=/; Secure; HttpOnly');
foreach ([
    [new Response(500, [], 'down')],
    array_fill(0, 3, new Response(200, [], '<html>aliyun_waf block</html>')),
    [agentJson(['success' => true, 'data' => ['id' => 42]])],
] as $failedBalance) {
    $history = [];
    $client = agentClient(array_merge([$probe, $placeholderLogin], $failedBalance), $history);
    $client->probeAsync(microtime(true) + 5)->wait();
    $result = $client->login($credentials['username'], $credentials['password']);
    $card = AgentRouterAccountInfo::present($result['profile'], '', 'fixture-user');
    agentCheck($result['success'] && $result['profile']['quota'] === null
        && $result['profile']['used_quota'] === null && $result['profile']['request_count'] === null
        && $card['balance'] === '暂不可用' && $card['used'] === '暂不可用' && $card['requests'] === '暂不可用',
        'Unconfirmed balance lookup displayed login placeholders as real zero metrics');
    agentCheck(count(array_filter($history, static fn($entry) => $entry['request']->getMethod() === 'POST')) === 1
        && end($history)['request']->getUri()->getPath() === '/api/user/logout',
        'Display-only balance failure replayed the login or skipped logout');
}

$history = [];
$client = agentClient([$probe, $placeholderLogin, agentJson(['success' => true,
    'data' => ['id' => 42, 'quota' => 0, 'used_quota' => 0, 'request_count' => 0]])], $history);
$client->probeAsync(microtime(true) + 5)->wait();
$result = $client->login($credentials['username'], $credentials['password']);
$card = AgentRouterAccountInfo::present($result['profile'], '', 'fixture-user');
agentCheck($card['balance'] === '$0.00' && $card['used'] === '$0.00' && $card['requests'] === '0',
    'Confirmed zero metrics must remain usable');

// Result classification through the executor.
foreach ([
    [agentJson(['success' => false, 'message' => '用户名或密码错误']), false, true, false],
    [agentJson(['success' => false, 'message' => '未登录'], 401), false, true, false],
    [new Response(200, [], '<html>aliyun_waf block</html>'), false, false, true],
    [new Response(403, [], 'Access denied'), false, false, true],
    [new Response(500, [], 'oops'), false, false, true],
    [new Response(200, [], '<html>not json</html>'), false, false, true],
    [agentJson(['success' => true, 'data' => ['id' => 42, 'checked_in' => false]]), true, false, false],
] as [$response, $success, $invalid, $retry]) {
    $history = [];
    $client = agentClient([$probe, $response, $selfOk], $history);
    $client->probeAsync(microtime(true) + 5)->wait();
    $executor = new AgentRouterTaskExecutor(static fn() => $client);
    $result = $executor->execute('sign_in', $credentials);
    agentCheck($result['success'] === $success && $result['account_invalid'] === $invalid
        && ($result['retry_after_seconds'] > 0) === $retry, 'Check-in result classification failed');
    agentCheck(count(array_filter($history, static fn($e) => $e['request']->getMethod() === 'POST')) <= 1,
        'A login POST was replayed within one execution');
}

// The original script reports a new check-in when checked_in is true.
$history = [];
$client = agentClient([$probe, agentJson(['success' => true, 'data' => ['id' => 42, 'checked_in' => true]]), $selfOk], $history);
$client->probeAsync(microtime(true) + 5)->wait();
$fresh = (new AgentRouterTaskExecutor(static fn() => $client))->execute('sign_in', $credentials);
agentCheck($fresh['success'] && str_contains($fresh['message'], '签到') && !str_contains($fresh['message'], '已签到'),
    'A fresh check-in was labelled as already done');

// Transport exceptions must not expose the password.
$history = [];
$client = agentClient([$probe, new ConnectException('private-password upstream', new Request('POST', Client::ORIGIN . '/api/user/login'))], $history);
$client->probeAsync(microtime(true) + 5)->wait();
$result = (new AgentRouterTaskExecutor(static fn() => $client))->execute('sign_in', $credentials);
agentCheck(!$result['success'] && $result['retry_after_seconds'] === 300
    && !str_contains($result['message'], 'private-password'), 'Exception exposed the account password');

// Invalid credentials require user correction, not repeated requests.
foreach (['', "bad\r\nname", str_repeat('x', 129)] as $badUsername) {
    try {
        Client::normalizeUsername($badUsername);
        throw new RuntimeException('Invalid username accepted');
    } catch (InvalidArgumentException $expected) {}
}
foreach (['', "pass\r\nword", str_repeat('x', 2049)] as $badPassword) {
    try {
        Client::normalizePassword($badPassword);
        throw new RuntimeException('Invalid password accepted');
    } catch (InvalidArgumentException $expected) {}
}

// Bounded retries: exhausted failures disable the job instead of retrying forever.
putenv('SCHEDULER_MAX_RETRIES=2');
try {
    $failing = new AgentRouterTaskExecutor(static function (): Client {
        $client = new Client(new HttpClient(['handler' => HandlerStack::create(new MockHandler([
            new Response(200, [], '<html>aliyun_waf</html>'),
        ]))]));
        $client->probeAsync(microtime(true) + 5)->wait();
        return $client;
    });
    $result = $failing->execute('sign_in', $credentials);
    $result = $failing->execute('sign_in', $credentials, $result['job_data']);
    $result = $failing->execute('sign_in', $credentials, $result['job_data']);
    agentCheck($result['retry_after_seconds'] === 0 && $result['retry_exhausted']
        && !str_contains($result['message'], '将自动重试'), 'Failures were retried forever');
    $updates = AgentRouterTaskExecutor::jobUpdates($result, 'fixture-user', '09:00');
    agentCheck($updates['state'] === 0 && $updates['nextExecute'] === 0, 'Exhausted job stayed scheduled');
} finally {
    putenv('SCHEDULER_MAX_RETRIES');
}

echo "AgentRouter login check-in, direct isolation, result semantics and retry tests passed\n";
