<?php

declare(strict_types=1);

require __DIR__ . '/FunctionalDatabaseBootstrap.php';

use agentrouter\Client;
use app\service\AgentRouterConnection;
use app\service\AgentRouterProxySettings;
use app\service\AgentRouterSubscription;
use app\service\AgentRouterTaskExecutor;
use app\service\TaskExecutionLock;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use think\facade\Db;

fixtureTable('configs', 'k TEXT PRIMARY KEY, v TEXT');

function agPlatformHttp(array $responses, array &$history): HttpClient
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    return new HttpClient(['handler' => $stack]);
}

function agPlatformStatus(int $status = 200): Response
{
    return new Response($status, [], '{"success":true,"data":{"system_name":"AgentRouter"}}');
}

function agPlatformSession(): array
{
    return [new Response(200, [], '{"success":true,"data":{"id":42,"checked_in":true}}'),
        new Response(200, [], '{"success":true,"data":{"id":42,"quota":62500000}}'),
        new Response(200, [], '{"success":true}')];
}

$environment = [];
foreach (['AGENTROUTER_PROXY', 'AGENTROUTER_ORIGIN', 'ALL_PROXY', 'NO_PROXY'] as $key) {
    $environment[$key] = getenv($key);
}
try {
    // A shared deployment relay must not override the proxy this account selected.
    putenv('AGENTROUTER_PROXY=http://127.0.0.1:9876');
    putenv('AGENTROUTER_ORIGIN=https://agentrouter.org');
    putenv('ALL_PROXY=http://127.0.0.1:1');
    putenv('NO_PROXY=*');
    $history = [];
    $connection = new AgentRouterConnection(agPlatformHttp([agPlatformStatus(), ...agPlatformSession()], $history));
    $account = ['username' => 'fixture-user', 'password' => 'fixture-password',
        'proxy_mode' => 'custom', 'proxy_url' => 'http://fixture-user:fixture-secret@8.8.8.8:8080'];
    $result = $connection->connect($account)->login($account['username'], $account['password']);
    functionalCheck($result['success'], 'Custom proxy session did not finish');
    foreach ($history as $entry) {
        functionalCheck($entry['options']['proxy'] === $account['proxy_url'],
            'AG ignored the account proxy and used the shared environment relay');
    }

    // No configuration must not use an inherited personal relay or a public free-proxy list.
    $history = [];
    $empty = (new AgentRouterTaskExecutor((new AgentRouterConnection(agPlatformHttp([], $history)))->connect(...)))
        ->execute('sign_in', ['username' => 'fixture-user', 'password' => 'fixture-password']);
    functionalCheck(!$empty['success'] && $history === [] && $empty['retry_after_seconds'] === 0
        && str_contains($empty['message'], '站长尚未配置'), 'Unconfigured AG silently used another proxy source');

    $settings = new AgentRouterProxySettings();
    $source = 'https://subscription.example.invalid/list?token=fixture-subscription-secret';
    $settings->save(['kind' => 'subscription', 'source' => $source, 'origin' => Client::ORIGIN]);
    $nodes = AgentRouterSubscription::parse("http://8.8.8.8:8001\nhttp://8.8.4.4:8002\nhttp://1.1.1.1:8003\nhttp://9.9.9.9:8004");
    $loads = 0;
    $now = strtotime('2026-11-16 09:00:00');
    $run = static function (array $responses, array &$history) use ($settings, $nodes, &$loads, &$now): array {
        $loader = static function () use ($nodes, &$loads): array { $loads++; return $nodes; };
        $clock = static function () use (&$now): int { return $now; };
        $connection = new AgentRouterConnection(agPlatformHttp($responses, $history), $settings, $loader, null, $clock);
        return (new AgentRouterTaskExecutor($connection->connect(...)))->execute('sign_in',
            ['username' => 'fixture-user', 'password' => 'fixture-password', 'proxy_mode' => 'platform']);
    };
    $state = static fn(): array => $settings->state(AgentRouterProxySettings::fingerprint($settings->load()));
    $urls = static fn(): array => array_column(array_column($state()['verified'], 'node'), 'url');

    // Day one: scan only until one node works, record it only after login and logout.
    $history = [];
    functionalCheck($run([agPlatformStatus(), ...agPlatformSession()], $history)['success'], 'First selected node failed');
    functionalCheck($loads === 1 && $urls() === [$nodes[0]['url']], 'First success was not persisted in the database');
    functionalCheck(count($history) === 4 && !$history[0]['request']->hasHeader('Cookie')
        && !$history[0]['request']->hasHeader('new-api-user'), 'Candidate check sent account credentials');

    // Day two: A fails, retain A once and append B. All authenticated requests stay on B.
    $now += 86400;
    $history = [];
    functionalCheck($run([agPlatformStatus(429), agPlatformStatus(), ...agPlatformSession()], $history)['success'],
        'A failed anonymous probe did not select B');
    functionalCheck($urls() === [$nodes[0]['url'], $nodes[1]['url']] && $state()['verified'][0]['failures'] === 1,
        'The first failed day removed A or promoted B too early');
    functionalCheck(count(array_filter($history, static fn($entry): bool => $entry['request']->getMethod() === 'POST')) === 1,
        'Selecting B repeated the check-in POST');
    foreach (array_slice($history, 1) as $entry) {
        functionalCheck($entry['options']['proxy'] === $nodes[1]['url'], 'The daily session changed exit after choosing B');
    }

    // Day three: retry A first, remove it after another failed day, reuse B without a subscription fetch.
    $now += 86400;
    $history = [];
    functionalCheck($run([agPlatformStatus(403), agPlatformStatus(), ...agPlatformSession()], $history)['success'],
        'B could not take over after A failed twice');
    functionalCheck($urls() === [$nodes[1]['url']] && $loads === 2, 'A was not removed or the known B required another download');
    $now += 86400;
    $history = [];
    functionalCheck($run([agPlatformStatus(), ...agPlatformSession()], $history)['success']
        && $history[0]['options']['proxy'] === $nodes[1]['url'] && $loads === 2,
        'A healthy first node was rotated merely because another day started');

    // Preserve blank secrets, reject an incomplete type change, and clear records only with changed settings.
    $before = Db::name('configs')->where('k', AgentRouterProxySettings::STATE_KEY)->value('v');
    $settings->save(['kind' => 'subscription', 'source' => '', 'origin' => Client::ORIGIN]);
    functionalCheck($settings->load()['source'] === $source
        && Db::name('configs')->where('k', AgentRouterProxySettings::STATE_KEY)->value('v') === $before,
        'A blank settings update erased the secret or its success history');
    functionalCheck(!str_contains(json_encode($settings->present()), 'fixture-subscription-secret')
        && !str_contains(json_encode($settings->present()), '8.8.4.4'), 'The public settings view contained private endpoints');
    try {
        $settings->save(['kind' => 'proxy', 'source' => '']);
        throw new RuntimeException('An empty source with changed type was accepted');
    } catch (InvalidArgumentException $expected) {
        functionalCheck($settings->load()['source'] === $source && $urls() === [$nodes[1]['url']],
            'Invalid settings changed the working source or cache');
    }

    // Same-day retries do not erase the preferred node. At most three successful candidates are retained.
    $settings->save(['kind' => 'subscription', 'source' => $source . '-cap']);
    $now = strtotime('2026-11-20 09:00:00');
    $history = [];
    $run([agPlatformStatus(), ...agPlatformSession()], $history);
    for ($index = 1; $index < 4; $index++) {
        $now += 601;
        $history = [];
        $responses = array_fill(0, min(3, $index), agPlatformStatus(429));
        functionalCheck($run([...$responses, agPlatformStatus(), ...agPlatformSession()], $history)['success'],
            'The fallback scan failed while populating the preference list');
        functionalCheck(count($state()['verified']) === min(3, $index + 1)
            && $state()['verified'][0]['failures'] === 1, 'Cache exceeded three entries or counted one day multiple times');
    }
    functionalCheck($urls() === [$nodes[0]['url'], $nodes[1]['url'], $nodes[3]['url']],
        'The cache cap discarded a higher-priority record');
    $now += 86400;
    $history = [];
    $waf = new Response(200, [], '<html>aliyun_waf verification</html>');
    functionalCheck($run([$waf, $waf, $waf, agPlatformStatus(), ...agPlatformSession()], $history)['success']
        && $urls()[0] === $nodes[1]['url'] && $state()['verified'][0]['failures'] === 0,
        'WAF did not evict the twice-failed first node or reset the recovered fallback');

    // Login-side 429 ends this execution; the next bounded retry chooses a different node.
    $settings->save(['kind' => 'subscription', 'source' => $source . '-post']);
    $now += 86400;
    $history = [];
    $run([agPlatformStatus(), ...agPlatformSession()], $history);
    $history = [];
    $limited = $run([agPlatformStatus(), new Response(429, [], '{"message":"too many requests"}')], $history);
    functionalCheck(!$limited['success'] && $limited['retry_after_seconds'] === 300 && count($history) === 2
        && $state()['verified'][0]['failures'] === 1, 'Login 429 replayed a POST or did not record the failing exit');
    $now += 300;
    $history = [];
    functionalCheck($run([agPlatformStatus(), ...agPlatformSession()], $history)['success']
        && $history[0]['options']['proxy'] === $nodes[1]['url'], 'A scheduled retry returned to the cooling-down node');

    // Wrong account credentials are not evidence that the exit is broken.
    $now += 601;
    $history = [];
    $invalid = $run([agPlatformStatus(), new Response(401, [], '{"success":false}')], $history);
    functionalCheck($invalid['account_invalid'] && $state()['verified'][0]['failures'] === 1
        && count($history) === 2, 'Invalid credentials penalized a proxy or repeated login');

    // An unused client and failed login still release the installation-wide pool lock.
    $now += 601;
    $history = [];
    $client = (new AgentRouterConnection(agPlatformHttp([agPlatformStatus()], $history), $settings,
        static fn(): array => $nodes, null, static fn(): int => $now))->connect(['proxy_mode' => 'platform']);
    functionalCheck(!TaskExecutionLock::acquire(AgentRouterProxySettings::LOCK), 'Selected exit lost its lease before login');
    try {
        $settings->save(['kind' => 'subscription', 'source' => $source . '-concurrent']);
        throw new RuntimeException('Settings changed during an owned session');
    } catch (RuntimeException $expected) {
        functionalCheck(str_contains($expected->getMessage(), '正在使用'), 'Unexpected settings-lock failure');
    }
    $client->close();
    functionalCheck(TaskExecutionLock::acquire(AgentRouterProxySettings::LOCK), 'Closing an unused client leaked the pool lock');
    TaskExecutionLock::release(AgentRouterProxySettings::LOCK);
    $settings->save(['clear' => '1']);
    functionalCheck($settings->load()['source'] === '' && $state()['verified'] === []
        && Db::name('configs')->where('k', AgentRouterProxySettings::STATE_KEY)->count() === 0,
        'Explicit clearing kept private source data or success records');
} finally {
    foreach ($environment as $key => $value) {
        putenv($value === false ? $key : $key . '=' . $value);
    }
}

echo "AgentRouter platform settings and persistent node preference tests passed\n";
