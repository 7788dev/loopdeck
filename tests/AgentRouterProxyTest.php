<?php

declare(strict_types=1);

require __DIR__ . '/FunctionalDatabaseBootstrap.php';

use agentrouter\Client;
use app\service\AgentRouterConnection;
use app\service\AgentRouterTaskExecutor;
use app\service\AgentRouterProxySettings;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

fixtureTable('configs', 'k TEXT PRIMARY KEY, v TEXT');

function agentProxyCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function agentProxyJson(array $body): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], json_encode($body));
}

function agentProxyHttp(array $responses, array &$history): HttpClient
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    return new HttpClient(['handler' => $stack]);
}

$status = agentProxyJson(['success' => true, 'data' => ['system_name' => 'Agent Router',
    'quota_per_unit' => 500000, 'display_in_currency' => true]]);
$login = agentProxyJson(['success' => true, 'data' => ['id' => 42, 'checked_in' => true]])
    ->withHeader('Set-Cookie', 'session=proxy-fixture; Path=/; Secure; HttpOnly');
$self = agentProxyJson(['success' => true, 'data' => ['id' => 42, 'quota' => 62500000]]);
$logout = agentProxyJson(['success' => true]);
$proxy = 'socks5h://relay-user:relay-password@203.0.113.5:1080';
$origin = 'https://agentrouter.org';
$saved = [];
foreach (['AGENTROUTER_PROXY', 'AGENTROUTER_ORIGIN', 'ALL_PROXY', 'NO_PROXY'] as $name) {
    $saved[$name] = getenv($name);
}

try {
    // The selected exit defeats inherited proxy/bypass variables for the entire session.
    putenv('ALL_PROXY=http://127.0.0.1:1');
    putenv('NO_PROXY=*');
    $history = [];
    $client = new Client(agentProxyHttp([$status, $login, $self, $logout], $history), $proxy, $origin);
    agentProxyCheck($client->probeAsync(microtime(true) + 5)->wait(), 'Configured proxy probe failed');
    $result = $client->login('fixture-user', 'fixture-password');
    agentProxyCheck($result['success'] && !$result['already'] && $result['profile']['quota'] === 62500000.0,
        'The proxied login/balance/logout cycle did not complete');
    agentProxyCheck(count($history) === 4, 'Unexpected requests in a proxied execution');
    foreach ($history as $entry) {
        agentProxyCheck($entry['options']['proxy'] === $proxy
            && $entry['options']['curl'][CURLOPT_PROXY] === $proxy
            && $entry['options']['curl'][CURLOPT_NOPROXY] === '',
            'Configured proxy was ignored or bypassed during the daily session');
        agentProxyCheck($entry['options']['verify'] === true && $entry['options']['allow_redirects'] === false
            && $entry['request']->getUri()->getHost() === 'agentrouter.org'
            && $entry['request']->getHeaderLine('Origin') === $origin
            && str_starts_with($entry['request']->getHeaderLine('Referer'), $origin . '/'),
            'Proxy mode changed the verified credential destination or request headers');
    }
    agentProxyCheck(!$history[0]['request']->hasHeader('Cookie') && !$history[0]['request']->hasHeader('Authorization')
        && !$history[0]['request']->hasHeader('new-api-user')
        && $history[2]['request']->getHeaderLine('Cookie') === 'session=proxy-fixture'
        && $history[3]['request']->getHeaderLine('Cookie') === 'session=proxy-fixture',
        'Anonymous probe or login session isolation failed');

    // Only the two official HTTPS domains may receive credentials, even with operator configuration.
    foreach (['http://agentrouter.org', 'https://attacker.example', 'https://agentrouter.org.attacker.example',
        'https://agentrouter.org@attacker.example', 'https://agentrouter.org/path', 'https://agentrouter.org?x=1'] as $badOrigin) {
        $history = [];
        try {
            new Client(agentProxyHttp([], $history), $proxy, $badOrigin);
            throw new RuntimeException('Untrusted AgentRouter origin was accepted');
        } catch (InvalidArgumentException $expected) {
            agentProxyCheck($history === [], 'Credentials were sent before validating the origin');
        }
    }

    // Read-only WAF retries retain the exit. A blocked login never replays its POST.
    foreach ([false, true] as $blockedLogin) {
        $history = [];
        $waf = new Response(200, [], '<html>aliyun_waf interactive verification</html>');
        $responses = $blockedLogin ? [$status, $waf]
            : [$status, $login, $waf, $waf, $self, $logout];
        $client = new Client(agentProxyHttp($responses, $history), $proxy, $origin);
        $client->probeAsync(microtime(true) + 5)->wait();
        $result = $client->login('fixture-user', 'fixture-password');
        agentProxyCheck($result['success'] === !$blockedLogin
            && count(array_filter($history, static fn(array $e): bool => $e['request']->getMethod() === 'POST')) === 1,
            'WAF handling replayed a login or changed the check-in result');
        foreach ($history as $entry) {
            agentProxyCheck($entry['options']['proxy'] === $proxy, 'WAF handling changed the selected exit');
        }
    }

    // Both scheduler and explicit account validation use the private administrator settings.
    $settings = new AgentRouterProxySettings();
    $settings->save(['kind' => 'proxy', 'source' => 'http://relay-user:relay-password@127.0.0.1:8080', 'origin' => $origin]);
    putenv('AGENTROUTER_PROXY=http://ignored-personal-relay:8080');
    putenv('AGENTROUTER_ORIGIN=' . Client::ORIGIN);
    $history = [];
    $connection = new AgentRouterConnection(agentProxyHttp([
        $status, $login, $self, $logout, $status, $login, $self, $logout,
    ], $history));
    $executor = new AgentRouterTaskExecutor($connection->connect(...));
    $account = ['username' => 'fixture-user', 'password' => 'fixture-password'];
    agentProxyCheck($executor->validateAccount($account)['success']
        && $executor->execute('sign_in', $account)['success'], 'One entry ignored the configured AG connection');
    foreach ($history as $entry) {
        agentProxyCheck($entry['options']['proxy'] === 'http://relay-user:relay-password@127.0.0.1:8080'
            && $entry['options']['curl'][CURLOPT_NOPROXY] === '', 'Operator-configured local relay was lost');
    }

    // Invalid account proxy input never leaks credentials or disables an otherwise valid account.
    $history = [];
    $connection = new AgentRouterConnection(agentProxyHttp([], $history));
    $badAccount = $account + ['proxy_mode' => 'custom', 'proxy_url' => 'http://relay-user:relay-password@bad-host:invalid'];
    $result = (new AgentRouterTaskExecutor($connection->connect(...)))->execute('sign_in', $badAccount);
    agentProxyCheck(!$result['success'] && $result['retry_after_seconds'] === 0 && !$result['account_invalid']
        && $history === [] && !str_contains($result['message'], 'relay-password'),
        'Malformed account proxy leaked a secret, disabled an account or sent a login');
    $history = [];
    $connection = new AgentRouterConnection(agentProxyHttp([
        new Response(200, [], '<html>verification required</html>'),
    ], $history));
    $result = (new AgentRouterTaskExecutor($connection->connect(...)))->execute('sign_in',
        $account + ['proxy_mode' => 'custom', 'proxy_url' => 'http://relay-user:relay-password@8.8.8.8:8080']);
    agentProxyCheck(!$result['success'] && $result['retry_after_seconds'] === 300 && count($history) === 1
        && $history[0]['request']->getMethod() === 'GET', 'Failed proxy preflight sent a login or switched exits');

    // Empty platform configuration requires the operator's input instead of falling back to an environment relay.
    $settings->save(['clear' => '1']);
    $history = [];
    putenv('AGENTROUTER_PROXY=' . $proxy);
    putenv('AGENTROUTER_ORIGIN=' . $origin);
    $connection = new AgentRouterConnection(agentProxyHttp([], $history));
    $result = (new AgentRouterTaskExecutor($connection->connect(...)))->execute('sign_in', $account);
    agentProxyCheck(!$result['success'] && $history === [] && str_contains($result['message'], '站长尚未配置'),
        'An unconfigured platform secretly used the inherited personal relay');
} finally {
    foreach ($saved as $name => $value) {
        putenv($value === false ? $name : $name . '=' . $value);
    }
}

echo "AgentRouter configured exit, official origins, session isolation and no POST replay tests passed\n";
