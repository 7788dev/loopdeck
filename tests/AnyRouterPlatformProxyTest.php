<?php

declare(strict_types=1);

require __DIR__ . '/FunctionalDatabaseBootstrap.php';

use app\service\AgentRouterSubscription;
use app\service\AnyRouterProxyPool;
use app\service\AnyRouterProxySettings;
use app\service\AnyRouterTaskExecutor;
use app\service\TaskExecutionLock;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

fixtureTable('configs', 'k TEXT PRIMARY KEY, v TEXT');
$directory = sys_get_temp_dir() . '/loopdeck-anyrouter-platform-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
$file = $directory . '/pool.json';
$settings = new AnyRouterProxySettings();
$account = ['proxy_mode' => 'platform', 'cookies' => ['session' => 'fixture-session'], 'api_user' => '42'];
$status = new Response(200, [], '{"success":true,"data":{"system_name":"AnyRouter"}}');
$self = new Response(200, [], '{"success":true,"data":{"id":42,"username":"fixture-user"}}');
$signed = new Response(200, [], '{"success":true}');
$source = 'https://subscription.example.invalid/list?token=fixture-private-subscription';
$nodes = AgentRouterSubscription::parse("trojan://fixture-node-secret@8.8.8.8:443#first\nhttp://8.8.4.4:8002\nhttp://1.1.1.1:8003");
$loads = 0;
$relays = [];
$loader = static function (string $url) use (&$loads, &$nodes, $source): array {
    functionalCheck(str_starts_with($url, $source), 'Subscription discovery used an unconfigured source');
    $loads++;
    return $nodes;
};
$factory = static function (array $node, bool $publicOnly) use (&$relays) {
    functionalCheck($publicOnly, 'Subscription nodes bypassed public-address protection');
    $relay = new class($node) {
        public bool $closed = false;
        public string $url;
        public function __construct(public array $node)
        {
            $port = ($node['type'] ?? '') === 'endpoint' ? parse_url($node['url'])['port'] : $node['port'];
            $this->url = 'http://fixture-relay:fixture-relay-secret@127.0.0.1:' . $port;
        }
        public function close(): void { $this->closed = true; }
    };
    $relays[] = $relay;
    return $relay;
};
$http = static function (array $responses, array &$history, bool $subscription = false) use (&$relays): HttpClient {
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    if ($subscription) {
        $stack->push(Middleware::tap(static function () use (&$relays): void {
            functionalCheck(!TaskExecutionLock::acquire(AnyRouterProxySettings::LOCK), 'A request lost its shared source lease');
            functionalCheck(count(array_filter($relays, static fn($relay): bool => !$relay->closed)) === 1,
                'An authenticated request used a closed relay or more than one live exit');
        }));
    }
    return new HttpClient(['handler' => $stack]);
};
$released = static function (): void {
    functionalCheck(TaskExecutionLock::acquire(AnyRouterProxySettings::LOCK), 'The selected proxy leaked its lease');
    TaskExecutionLock::release(AnyRouterProxySettings::LOCK);
};
$environment = [];
foreach (['ANYROUTER_PLATFORM_PROXY', 'ALL_PROXY', 'NO_PROXY'] as $key) $environment[$key] = getenv($key);
try {
    putenv('ANYROUTER_PLATFORM_PROXY=http://127.0.0.1:9876');
    putenv('ALL_PROXY=http://127.0.0.1:1');
    putenv('NO_PROXY=*');
    $settings->save(['kind' => 'builtin', 'http_sources' => 'https://8.8.8.8/http.txt', 'socks5_sources' => '']);
    $history = [];
    $pool = new AnyRouterProxyPool($http([new Response(200, [], '9.9.9.9:8080'), $status, $self, $signed], $history), $file);
    functionalCheck((new AnyRouterTaskExecutor($pool->connect(...)))->execute('sign_in', $account)['success'],
        'The edited built-in source did not drive an actual check-in');
    functionalCheck((string)$history[0]['request']->getUri() === 'https://8.8.8.8/http.txt'
        && $history[0]['options']['curl'][CURLOPT_RESOLVE] === ['8.8.8.8:443:8.8.8.8'], 'List download lost the saved URL or TLS hostname pin');
    foreach (array_slice($history, 1) as $entry) {
        functionalCheck($entry['options']['proxy'] === 'http://9.9.9.9:8080'
            && $entry['options']['verify'] && $entry['options']['curl'][CURLOPT_NOPROXY] === '',
            'An administrator choice fell back to an environment exit or disabled TLS');
    }
    $released();

    // Fixed administrator proxies may use a local relay; failure never silently falls back to the free list.
    $settings->save(['kind' => 'proxy', 'source' => 'http://fixture:fixture-private-proxy@127.0.0.1:7890']);
    $history = [];
    $pool = new AnyRouterProxyPool($http([$status, $self, $signed], $history), $file);
    functionalCheck((new AnyRouterTaskExecutor($pool->connect(...)))->execute('sign_in', $account)['success'], 'Fixed proxy check-in failed');
    foreach ($history as $entry) functionalCheck($entry['options']['proxy'] === $settings->load()['source'], 'Fixed proxy was overridden');
    $history = [];
    $pool = new AnyRouterProxyPool($http([new Response(429)], $history), $file);
    $failed = (new AnyRouterTaskExecutor($pool->connect(...)))->execute('sign_in', $account);
    functionalCheck(!$failed['success'] && count($history) === 1 && !str_contains($failed['message'], 'fixture-private-proxy'),
        'A failed fixed source fell back or exposed credentials');
    $released();

    $settings->save(['kind' => 'subscription', 'source' => $source]);
    $history = [];
    $challenge = "<script>var arg1='0123456789ABCDEF0123456789ABCDEF01234567';document.cookie='acw_sc__v2=';</script>";
    $pool = new AnyRouterProxyPool($http([new Response(429), new Response(200, [], $challenge), $status, $self, $signed], $history, true),
        $file, null, $loader, $factory);
    $result = (new AnyRouterTaskExecutor($pool->connect(...)))->execute('sign_in', $account);
    functionalCheck($result['success'] && $loads === 1 && count($relays) === 2 && $relays[0]->closed && $relays[1]->closed,
        'Subscription selection or finished-session relay cleanup failed');
    foreach (array_slice($history, 1) as $entry) {
        functionalCheck($entry['options']['proxy'] === $relays[1]->url, 'WAF, authentication and sign-in switched exits');
    }
    foreach (array_slice($history, 0, 3) as $entry) {
        functionalCheck(!$entry['request']->hasHeader('new-api-user')
            && !str_contains($entry['request']->getHeaderLine('Cookie'), 'session='), 'Candidate discovery exposed the account');
    }
    functionalCheck(count(array_filter($history, static fn($entry): bool => $entry['request']->getMethod() === 'POST')) === 1,
        'Subscription fallback replayed a check-in POST');
    functionalCheck(!str_contains(file_get_contents($file), 'fixture-private-subscription'), 'Cache retained the subscription URL');
    $released();

    // Refreshing an account reuses the selected node but only reads the profile, and still closes its relay.
    $history = [];
    $pool = new AnyRouterProxyPool($http([$status, $self], $history, true), $file, null, $loader, $factory);
    functionalCheck((new AnyRouterTaskExecutor($pool->connect(...)))->validateAccount($account)['success'] && $loads === 1,
        'Cached subscription node required another download');
    functionalCheck(count($history) === 2 && end($relays)->closed
        && $history[0]['options']['proxy'] === $relays[1]->url, 'Read-only validation signed in, rotated exits or leaked the relay');
    $released();

    // Editing the source must invalidate a still-healthy cached exit before any account request.
    $settings->save(['kind' => 'subscription', 'source' => $source . '-updated']);
    $history = [];
    $pool = new AnyRouterProxyPool($http([$status, $self, new Response(200, [], $challenge)], $history, true),
        $file, null, $loader, $factory);
    $result = (new AnyRouterTaskExecutor($pool->connect(...)))->execute('sign_in', $account);
    functionalCheck(!$result['success'] && $result['retry_after_seconds'] === 300 && $loads === 2
        && $history[0]['options']['proxy'] !== $relays[1]->url && count($history) === 3 && end($relays)->closed,
        'A changed source kept the old cache, replayed a WAF POST or leaked the selected relay');
    $released();

    $history = [];
    $pool = new AnyRouterProxyPool($http([$status, new Response(401, [], '{"success":false}')], $history, true),
        $file, null, $loader, $factory);
    $result = (new AnyRouterTaskExecutor($pool->connect(...)))->execute('sign_in', $account);
    functionalCheck($result['account_invalid'] && count($history) === 2 && end($relays)->closed,
        'Invalid credentials submitted a POST or left their relay running');
    $released();

    // A caller that does not authenticate must be able to abandon the connection without leaking the lock.
    $history = [];
    $pool = new AnyRouterProxyPool($http([$status], $history, true), $file, null, $loader, $factory);
    $client = $pool->connect($account);
    try {
        $settings->save(['kind' => 'builtin']);
        throw new LogicException('An in-use source was replaced');
    } catch (RuntimeException $expected) {
        functionalCheck(str_contains($expected->getMessage(), '正在使用'), 'Unexpected occupied-lease failure');
    }
    $client->close();
    $client->close();
    functionalCheck(end($relays)->closed, 'Closing an unused connection left its relay alive');
    $released();

    // Bounded scans retain their cursor, so later retries can reach nodes beyond the first failed batch.
    $settings->save(['kind' => 'subscription', 'source' => $source . '-cursor']);
    $nodes = [];
    for ($index = 0; $index < 41; $index++) $nodes[] = AgentRouterSubscription::endpoint('http://8.8.8.8:' . (8100 + $index));
    $history = [];
    $pool = new AnyRouterProxyPool($http(array_fill(0, 40, new Response(429)), $history, true), $file, null, $loader, $factory);
    functionalCheck(!(new AnyRouterTaskExecutor($pool->connect(...)))->execute('sign_in', $account)['success'] && count($history) === 40,
        'Subscription discovery exceeded its bounded scan');
    $history = [];
    $pool = new AnyRouterProxyPool($http([$status, $self, $signed], $history, true), $file, null, $loader, $factory);
    functionalCheck((new AnyRouterTaskExecutor($pool->connect(...)))->execute('sign_in', $account)['success']
        && str_ends_with($history[0]['options']['proxy'], ':8140'), 'A retry restarted from the same dead nodes');
    $released();
} finally {
    foreach ($environment as $key => $value) putenv($value === false ? $key : $key . '=' . $value);
    foreach (glob($directory . '/*') ?: [] as $path) unlink($path);
    rmdir($directory);
}

echo "AnyRouter platform modes, cache invalidation, fixed-exit WAF, bounded scans and relay lifetime tests passed\n";
