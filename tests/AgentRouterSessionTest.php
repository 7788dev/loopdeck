<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use agentrouter\Client;
use app\service\AgentRouterTaskExecutor;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;

function agentSessionFixture(array &$history, int $selfStatus = 200, int $logoutStatus = 200, string $transportFailure = ''): Client
{
    $loginCount = 0;
    $stack = HandlerStack::create(static function ($request) use (&$loginCount, $selfStatus, $logoutStatus, $transportFailure) {
        $path = $request->getUri()->getPath();
        $headers = ['Content-Type' => 'application/json'];
        $status = 200;
        if ($path === '/api/status') {
            $body = ['success' => true, 'data' => ['system_name' => 'Agent Router',
                'quota_per_unit' => 500000, 'display_in_currency' => true]];
        } elseif ($path === '/api/user/login') {
            $loginCount++;
            $headers['Set-Cookie'] = 'session=fixture-' . $loginCount . '; Path=/; Secure; HttpOnly';
            // The original script treats true as a new check-in, false as already done.
            $body = ['success' => true, 'data' => ['id' => 42, 'checked_in' => $loginCount === 1]];
        } elseif ($request->getHeaderLine('Cookie') !== 'session=fixture-' . $loginCount
            || $request->getHeaderLine('new-api-user') !== '42') {
            $status = 401;
            $body = ['success' => false, 'message' => 'Not logged in'];
        } elseif ($path === $transportFailure) {
            return new RejectedPromise(new ConnectException('fixture transport failure', $request));
        } elseif ($path === '/api/user/self') {
            $status = $selfStatus;
            $body = ['success' => $status === 200, 'data' => ['id' => 42,
                'username' => 'fixture-user', 'quota' => 62500000]];
        } elseif ($path === '/api/user/logout') {
            $status = $logoutStatus;
            $body = ['success' => $status === 200];
            if ($status === 200) {
                $headers['Set-Cookie'] = 'session=; Path=/; Max-Age=0; Secure; HttpOnly';
            }
        } else {
            throw new RuntimeException('Unexpected AgentRouter endpoint: ' . $path);
        }
        return new FulfilledPromise(new Response($status, $headers, json_encode($body)));
    });
    $stack->push(Middleware::history($history));
    $client = new Client(new HttpClient(['handler' => $stack]));
    $client->probeAsync(microtime(true) + 5)->wait();
    return $client;
}

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$paths = static fn(array $history): array => array_map(
    static fn(array $entry): string => $entry['request']->getMethod() . ' ' . $entry['request']->getUri()->getPath(),
    $history
);

$history = [];
$client = agentSessionFixture($history);
$first = $client->login('fixture-user', 'fixture-password');
$check($first['success'] && !$first['already'], 'checked_in=true must report a newly completed check-in');
$check(($first['profile']['quota'] ?? null) === 62500000.0,
    'A session-only login did not authenticate the balance request');
$check($paths($history) === ['GET /api/status', 'POST /api/user/login',
    'GET /api/user/self', 'GET /api/user/logout'], 'The login, balance and logout cycle did not complete in order');

// Reusing an executor/client must still start the next run without yesterday's session.
$second = $client->login('fixture-user', 'fixture-password');
$check($second['success'] && $second['already'], 'checked_in=false must report today as already checked in');
$posts = array_values(array_filter($history, static fn(array $entry): bool => $entry['request']->getMethod() === 'POST'));
$check(count($posts) === 2 && !$posts[0]['request']->hasHeader('Cookie') && !$posts[1]['request']->hasHeader('Cookie'),
    'A new execution reused an authenticated session or replayed the login POST');
$check(count(array_filter($paths($history), static fn(string $path): bool => $path === 'GET /api/user/logout')) === 2,
    'Every successful daily login must end by logging out');
foreach ($history as $entry) {
    $check($entry['options']['proxy'] === '' && $entry['options']['verify'] === true
        && $entry['options']['allow_redirects'] === false, 'The daily session cycle lost direct connection or TLS protection');
}

// Balance is display-only, but the session must still be closed after its failure.
$history = [];
$client = agentSessionFixture($history, 500);
$partial = $client->login('fixture-user', 'fixture-password');
$check($partial['success'] && ($partial['profile']['quota'] ?? null) === null,
    'A balance failure revoked a confirmed check-in or invented a balance');
$check(end($history)['request']->getUri()->getPath() === '/api/user/logout',
    'A failed balance lookup left the login session open');

// A failed logout cannot be labelled as a complete login/logout cycle or replay the POST.
$history = [];
$client = agentSessionFixture($history, 200, 500);
$unfinished = $client->login('fixture-user', 'fixture-password');
$check(!$unfinished['success'] && !empty($unfinished['retryable']) && empty($unfinished['account_invalid']),
    'An unconfirmed logout was reported as a completed check-in cycle');
$check($paths($history) === ['GET /api/status', 'POST /api/user/login',
    'GET /api/user/self', 'GET /api/user/logout'], 'Logout failure replayed a state-changing request');
$retried = (new AgentRouterTaskExecutor(static fn() => $client))->execute('sign_in',
    ['username' => 'fixture-user', 'password' => 'fixture-password']);
$check(!$retried['success'] && !$retried['account_invalid'] && $retried['retry_after_seconds'] === 300
    && str_contains($retried['message'], '退出登录未确认'), 'Logout failure did not reach bounded scheduler retry');
$posts = array_values(array_filter($history, static fn(array $entry): bool => $entry['request']->getMethod() === 'POST'));
$check(count($posts) === 2 && !$posts[1]['request']->hasHeader('Cookie'),
    'Logout failure retained the authenticated cookie for the next run');

foreach (['/api/user/self', '/api/user/logout'] as $failedPath) {
    $history = [];
    $client = agentSessionFixture($history, 200, 200, $failedPath);
    $outcome = $client->login('fixture-user', 'fixture-password');
    $check($outcome['success'] === ($failedPath === '/api/user/self'),
        'Transport failure confused a display lookup with the required logout: ' . $failedPath);
    $check($paths($history) === ['GET /api/status', 'POST /api/user/login',
        'GET /api/user/self', 'GET /api/user/logout'], 'Transport failure skipped cleanup or replayed the login: ' . $failedPath);
    $client->login('fixture-user', 'fixture-password');
    $posts = array_values(array_filter($history, static fn(array $entry): bool => $entry['request']->getMethod() === 'POST'));
    $check(count($posts) === 2 && !$posts[1]['request']->hasHeader('Cookie'),
        'Transport failure retained the old login session: ' . $failedPath);
}

if ($failures !== []) {
    throw new RuntimeException(implode(PHP_EOL, $failures));
}
echo "AgentRouter fresh sessions, cookie authentication, check-in semantics and logout tests passed\n";
