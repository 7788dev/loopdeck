<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use anyrouter\Client;
use anyrouter\ProxyEndpoint;
use anyrouter\WafCookie;
use app\service\AnyRouterTaskExecutor;
use app\service\AnyRouterAccountInfo;
use app\service\SchedulerRetry;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

function anyCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function anyJson(array $body, int $status = 200): Response
{
    return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_UNESCAPED_UNICODE));
}

function anyClient(array $responses, array &$history): Client
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    return new Client('http://proxy-user:proxy-secret@8.8.8.8:8080', new HttpClient(['handler' => $stack]));
}

$probe = anyJson(['success' => true, 'data' => ['system_name' => 'AnyRouter']]);
$profile = anyJson(['success' => true, 'data' => ['id' => 42, 'username' => '测试账号']]);
$cookies = ['session' => 'private-session='];

// Real self responses may have an empty display_name. Only public profile fields survive.
$history = [];
$profileClient = anyClient([
    anyJson(['success' => true, 'data' => ['system_name' => 'AnyRouter', 'quota_per_unit' => 500000, 'display_in_currency' => true]]),
    anyJson(['success' => true, 'data' => ['id' => 42, 'display_name' => '', 'username' => 'fixture-user',
        'quota' => 62500000, 'used_quota' => 1750000, 'request_count' => 321, 'group' => 'default',
        'password' => 'never-render-password', 'access_token' => 'never-render-token', 'email' => 'private@example.com']]),
], $history);
$profileClient->probeAsync(microtime(true) + 5)->wait();
$accountProfile = $profileClient->authenticate($cookies, '42')['profile'];
anyCheck($accountProfile['nickname'] === 'fixture-user', 'Empty display name did not fall back to username');
anyCheck(($accountProfile['quota'] ?? null) === 62500000.0 && ($accountProfile['quota_per_unit'] ?? null) === 500000.0,
    'Account balance or site conversion unit missing');
anyCheck(!str_contains(json_encode($accountProfile), 'never-render') && !isset($accountProfile['email']),
    'Sensitive self response fields leaked into the account profile');
$presented = AnyRouterAccountInfo::present($accountProfile, '', '42');
anyCheck($presented['balance'] === '$125.00' && $presented['used'] === '$3.50' && $presented['requests'] === '321',
    'Profile display did not use the site quota conversion unit');
$unknown = AnyRouterAccountInfo::present([], '已保存的名称', '42');
anyCheck($unknown['balance'] === '暂不可用' && $unknown['used'] === '暂不可用'
    && $unknown['requests'] === '暂不可用', 'Missing account metrics were presented as zero');
$raw = AnyRouterAccountInfo::present(['quota' => 0, 'used_quota' => 12345, 'request_count' => 0], '', '42');
anyCheck($raw['balance'] === '0 额度' && $raw['used'] === '12,345 额度' && $raw['requests'] === '0',
    'Absent conversion settings were guessed or real zero values were hidden');
$remarked = AnyRouterAccountInfo::present($accountProfile, '工作账号', '42');
anyCheck($remarked['nickname'] === '工作账号' && $remarked['username'] === 'fixture-user',
    'Custom remark did not remain separate from the upstream username');
anyCheck(AnyRouterAccountInfo::remark(['remark' => '', 'nickname' => 'auto-name']) === '',
    'Cleared remark was restored from the fallback name');

anyCheck(WafCookie::fromHtml("<script>var arg1='0123456789ABCDEF0123456789ABCDEF01234567';document.cookie='acw_sc__v2=';</script>")
    === 'd2c7186598ab1a508a4f6064e4fa746323ab17c6', 'WAF permutation/XOR vector changed');
anyCheck(WafCookie::fromHtml('<html>success</html>') === null, 'Unknown HTML was treated as a WAF challenge');
anyCheck(Client::parseCookies('session=private-session=; acw_sc__v2=stale; cdn_sec_tc=stale') === $cookies,
    'WAF cookies from another proxy leaked into the new run');
anyCheck(Client::parseCookies('{"session":"private-session=","acw_tc":"old"}') === $cookies, 'JSON cookie compatibility broken');
foreach (["session=one\r\nInjected: x", 'session=', 'acw_tc=only', '{"session":[]}'] as $input) {
    try {
        Client::parseCookies($input);
        throw new RuntimeException('Invalid cookie accepted');
    } catch (InvalidArgumentException $expected) {}
}

$history = [];
$challenge = "<script>var arg1='0123456789ABCDEF0123456789ABCDEF01234567';document.cookie='acw_sc__v2=';</script>";
$client = anyClient([new Response(200, ['Set-Cookie' => ['acw_tc=tc; Path=/', 'cdn_sec_tc=cdn; Path=/']], $challenge),
    $probe, $profile, anyJson(['success' => true])], $history);
putenv('NO_PROXY=*');
try {
    anyCheck($client->probeAsync(microtime(true) + 10)->wait(), 'Known WAF challenge did not produce a valid probe');
    anyCheck($client->authenticate($cookies, '42')['success'], 'Valid session was not authenticated');
    anyCheck($client->signIn()['success'], 'Successful POST was rejected');
} finally {
    putenv('NO_PROXY');
}
anyCheck(count($history) === 4, 'Unexpected WAF or POST retries');
foreach ($history as $index => $entry) {
    $request = $entry['request'];
    anyCheck($entry['options']['proxy'] === 'http://proxy-user:proxy-secret@8.8.8.8:8080'
        && $entry['options']['curl'][CURLOPT_NOPROXY] === '' && $entry['options']['verify'] === true
        && $entry['options']['allow_redirects'] === false, 'Proxy, TLS or redirect protection lost');
    anyCheck((string)$request->getUri()->getHost() === 'anyrouter.top', 'Unexpected credential destination');
    if ($index < 2) {
        anyCheck(!$request->hasHeader('new-api-user') && !str_contains($request->getHeaderLine('Cookie'), 'session='),
            'Anonymous proxy probe received account credentials');
    }
}
anyCheck(str_contains($history[1]['request']->getHeaderLine('Cookie'), 'acw_tc=tc')
    && str_contains($history[1]['request']->getHeaderLine('Cookie'), 'cdn_sec_tc=cdn')
    && str_contains($history[1]['request']->getHeaderLine('Cookie'), 'acw_sc__v2='), 'WAF cookie jar was not reused');
anyCheck($history[3]['request']->getMethod() === 'POST'
    && (string)$history[3]['request']->getUri()->getPath() === '/api/user/sign_in'
    && $history[3]['request']->getHeaderLine('new-api-user') === '42', 'Check-in request shape changed');

foreach ([new Response(200, [], '<html>success</html>'), anyJson(['success' => true]),
    new Response(302, ['Location' => 'https://example.org']), anyJson(['success' => false])] as $response) {
    $history = [];
    anyCheck(!anyClient([$response], $history)->probeAsync(microtime(true) + 5)->wait(), 'False positive proxy was accepted');
}
foreach ([
    [anyJson(['success' => false, 'message' => '今日已签到']), true, false, false],
    [anyJson(['success' => false, 'code' => 0, 'message' => '数据库维护']), false, false, false],
    [new Response(200, [], '<html>success</html>'), false, false, true],
    [new Response(403, [], 'Access denied'), false, false, true],
    [anyJson(['success' => false, 'message' => '未登录'], 401), false, true, false],
    [new Response(200, [], $challenge), false, false, true],
] as [$response, $success, $invalid, $retry]) {
    $history = [];
    $client = anyClient([$probe, $profile, $response], $history);
    $client->probeAsync(microtime(true) + 5)->wait();
    $executor = new AnyRouterTaskExecutor(static fn() => $client);
    $result = $executor->execute('sign_in', ['cookies' => $cookies, 'api_user' => '42']);
    anyCheck($result['success'] === $success && $result['account_invalid'] === $invalid
        && ($result['retry_after_seconds'] > 0) === $retry, 'Check-in result classification failed');
    anyCheck(count(array_filter($history, static fn($entry) => $entry['request']->getMethod() === 'POST')) === 1,
        'A POST was replayed within one execution');
}
$history = [];
$client = anyClient([$probe, anyJson(['success' => true, 'data' => ['id' => 99]])], $history);
$client->probeAsync(microtime(true) + 5)->wait();
anyCheck(!$client->authenticate($cookies, '42')['success'] && count($history) === 2, 'Mismatched identity was accepted');

$history = [];
$client = anyClient([$probe, $profile,
    new ConnectException('private-session= proxy-secret', new Request('POST', Client::ORIGIN . '/api/user/sign_in'))], $history);
$client->probeAsync(microtime(true) + 5)->wait();
$result = (new AnyRouterTaskExecutor(static fn() => $client))->execute('sign_in', ['cookies' => $cookies, 'api_user' => '42']);
anyCheck(!$result['success'] && $result['retry_after_seconds'] === 300
    && !str_contains($result['message'], 'private-session') && !str_contains($result['message'], 'proxy-secret'), 'Exception exposed credentials');

// DNS can fail temporarily even for a saved, valid proxy; it shares the bounded network retry path.
$dnsExecutor = new AnyRouterTaskExecutor(static function (): Client {
    return new Client(ProxyEndpoint::resolve('http://proxy-user:proxy-secret@relay.example.com:8080', true, static fn() => []));
});
$dnsResult = $dnsExecutor->execute('sign_in', []);
anyCheck(!$dnsResult['success'] && !$dnsResult['account_invalid'] && $dnsResult['retry_after_seconds'] === 300,
    'Temporary DNS failure did not enter the network retry path');
anyCheck(!str_contains($dnsResult['message'], 'relay.example.com') && !str_contains($dnsResult['message'], 'proxy-secret'),
    'DNS failure exposed the proxy address or credentials');
anyCheck(!$dnsExecutor->validateAccount([])['success'], 'DNS failure was accepted during account validation');

// Invalid input and a private DNS answer still require user correction rather than repeated requests.
foreach (['http://127.0.0.1:80', 'http://relay.example.com:80'] as $proxy) {
    $invalidExecutor = new AnyRouterTaskExecutor(static fn(): Client => new Client(
        ProxyEndpoint::resolve($proxy, true, static fn() => ['8.8.8.8', '127.0.0.1'])
    ));
    $invalidResult = $invalidExecutor->execute('sign_in', []);
    anyCheck(!$invalidResult['success'] && $invalidResult['retry_after_seconds'] === 0,
        'Unsafe proxy configuration was treated as a temporary network failure');
}

putenv('SCHEDULER_MAX_RETRIES=2');
try {
    foreach ([$dnsExecutor, new AnyRouterTaskExecutor(static function () { throw new RuntimeException('untrusted failure'); })] as $executor) {
        $result = $executor->execute('sign_in', []);
        $result = $executor->execute('sign_in', [], $result['job_data']);
        $result = $executor->execute('sign_in', [], $result['job_data']);
        anyCheck($result['retry_after_seconds'] === 0 && $result['retry_exhausted']
            && !str_contains($result['message'], '将自动重试'), 'Proxy failures were retried forever');
        $updates = AnyRouterTaskExecutor::jobUpdates($result, '42', '09:00');
        anyCheck($updates['state'] === 0 && $updates['nextExecute'] === 0, 'Exhausted job stayed scheduled');
    }
} finally {
    putenv('SCHEDULER_MAX_RETRIES');
}

anyCheck(ProxyEndpoint::normalize('socks5://user:pass@8.8.8.8:1080') === 'socks5h://user:pass@8.8.8.8:1080', 'SOCKS DNS is not remote');
foreach (['http://127.0.0.1:80', 'http://10.0.0.1:80', 'http://169.254.169.254:80', 'http://100.100.100.200:80',
    'http://198.18.0.1:80', 'http://[ff00::1]:80', 'http://[::1]:80',
    'http://[::ffff:127.0.0.1]:80', 'file:///etc/passwd', 'http://8.8.8.8:80/path', "http://8.8.8.8:80\r\nx:1"] as $proxy) {
    try {
        ProxyEndpoint::normalize($proxy);
        throw new RuntimeException('Unsafe proxy accepted: ' . $proxy);
    } catch (InvalidArgumentException $expected) {}
}
try {
    ProxyEndpoint::resolve('http://relay.example.com:80', true, static fn() => ['8.8.8.8', '127.0.0.1']);
    throw new RuntimeException('Mixed public/private DNS accepted');
} catch (InvalidArgumentException $expected) {}
anyCheck(ProxyEndpoint::resolve('http://relay.example.com:80', true, static fn() => ['8.8.8.8']) === 'http://8.8.8.8:80',
    'Resolved proxy was not pinned');
anyCheck(ProxyEndpoint::parseList("8.8.8.8:80\n127.0.0.1:80\n8.8.8.8:80\n<script>evil</script>", 'http') === ['http://8.8.8.8:80'],
    'Proxy list filtering or deduplication failed');

echo "AnyRouter WAF cookies, proxy isolation, identity, result semantics and retry tests passed\n";
