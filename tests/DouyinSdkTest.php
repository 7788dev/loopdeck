<?php

declare(strict_types=1);

require __DIR__ . '/DouyinTestBootstrap.php';

use douyin\sdk\Client;
use douyin\sdk\PassportSigner;
use douyin\sdk\SignerInterface;
use douyin\sdk\TransportInterface;

final class DouyinRecordingSigner implements SignerInterface
{
    /** @var array<int,array{url:string,method:string,body:string}> */
    public array $requests = [];
    /** @var array<int,string> */
    public array $dtraitKeys = [];

    public function sign(string $url, string $method = 'GET', string $body = ''): string
    {
        $this->requests[] = compact('url', 'method', 'body');
        return $url . (str_contains($url, '?') ? '&' : '?') . 'a_bogus=LOCAL_FIXTURE_SIGNATURE';
    }

    /** @return array{url:string,headers:array<string,string>} */
    public function signRequest(string $url, string $method, string $body, string $dtraitKey): array
    {
        $this->dtraitKeys[] = $dtraitKey;
        return [
            'url' => $this->sign($url, $method, $body),
            'headers' => [
                'X-TT-Session-Dtrait' => 'd0_'
                    . base64_encode(str_repeat("\x01", 256))
                    . '_'
                    . base64_encode(str_repeat("\x02", 300)),
            ],
        ];
    }
}

final class DouyinRecordingTransport implements TransportInterface
{
    /** @var array<int,array<string,mixed>> */
    private array $responses;
    /** @var array<int,array{method:string,url:string,options:array<string,mixed>}> */
    public array $requests = [];

    public function __construct(array $responses)
    {
        $this->responses = array_values($responses);
    }

    public function request(string $method, string $url, array $options = []): array
    {
        $this->requests[] = compact('method', 'url', 'options');
        $response = array_shift($this->responses) ?? [];
        $payload = is_array($response['payload'] ?? null)
            ? $response['payload']
            : ['data' => ['error_code' => 0], 'message' => 'success'];
        return [
            'status' => (int)($response['status'] ?? 200),
            'headers' => is_array($response['headers'] ?? null) ? $response['headers'] : [],
            'body' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            'header' => '',
            'set_cookie' => is_array($response['set_cookie'] ?? null) ? $response['set_cookie'] : [],
        ];
    }
}

$qrcode = base64_encode("\x89PNG\r\n\x1a\nLOCAL_FIXTURE");
$transport = new DouyinRecordingTransport([
    [
        'headers' => ['X-Ms-Token' => ['SERVER_MS_TOKEN']],
        'payload' => [
            'data' => [
                'error_code' => 0,
                'token' => 'QR_TOKEN',
                'qrcode' => $qrcode,
                'is_frontier' => false,
                'expire_time' => time() + 120,
            ],
            'message' => 'success',
        ],
    ],
    [
        'payload' => [
            'data' => [
                'error_code' => 0,
                'status' => '2',
                'captcha' => json_encode([
                    'verify_url' => 'https://verify.snssdk.com/captcha/?ticket=LOCAL_FIXTURE',
                ], JSON_UNESCAPED_SLASHES),
                'description' => 'verify',
            ],
            'message' => 'success',
        ],
    ],
    [
        'set_cookie' => [
            'uid_tt=LOCAL_UID; Path=/; Secure; HttpOnly',
            'sessionid=LOCAL_SESSION; Path=/; Secure; HttpOnly',
        ],
        'payload' => [
            'data' => ['error_code' => 0, 'status' => 'confirmed'],
            'message' => 'success',
        ],
    ],
    [
        'payload' => [
            'data' => [
                'error_code' => 0,
                'user_id' => '7312345678901234567',
                'sec_user_id' => 'MS4wLjABAAAA_LOCAL_FIXTURE',
                'screen_name' => 'Local Douyin Fixture',
                'avatar_url' => 'https://p3.douyinpic.com/local-avatar.jpeg',
            ],
            'message' => 'success',
        ],
    ],
]);
$urlSigner = new DouyinRecordingSigner();
$passport = new PassportSigner();
$client = new Client(
    config: ['ms_token' => 'INITIAL_MS_TOKEN'],
    transport: $transport,
    urlSigner: $urlSigner,
    passportSigner: $passport
);

$generated = $client->qrGenerate();
douyinCheck(($generated['data']['token'] ?? '') === 'QR_TOKEN', 'QR generation did not return the token');
douyinCheck(count($transport->requests) === 1, 'QR generation made an unexpected number of requests');
douyinCheck($transport->requests[0]['method'] === 'GET', 'QR generation must use GET');
$firstUrl = parse_url($urlSigner->requests[0]['url']);
parse_str((string)($firstUrl['query'] ?? ''), $firstQuery);
douyinCheck(($firstQuery['msToken'] ?? '') === 'INITIAL_MS_TOKEN', 'initial msToken was not sent');
douyinCheck(($firstQuery['account_sdk_source'] ?? '') === 'web', 'QR query is missing the SDK source');
douyinCheck(
    (bool)preg_match('/^[a-f0-9]+$/', (string)($firstQuery['account_sdk_source_info'] ?? '')),
    'QR query is missing the encoded browser source information'
);
douyinCheck(isset($firstQuery['sign'], $firstQuery['qs']), 'QR query is missing passport signatures');
$unsignedFirstQuery = $firstQuery;
unset($unsignedFirstQuery['sign'], $unsignedFirstQuery['qs'], $unsignedFirstQuery['msToken']);
$expectedFirstSignature = $passport->sign($unsignedFirstQuery);
douyinCheck($firstQuery['sign'] === $expectedFirstSignature['sign'], 'QR sign was computed over the wrong fields');
douyinCheck($firstQuery['qs'] === $expectedFirstSignature['qs'], 'QR qs was computed over the wrong fields');
$firstHeaders = $transport->requests[0]['options']['headers'];
douyinCheck(!empty($firstHeaders['X-TT-Passport-Aid-Sign']), 'QR request is missing aid signature');
douyinCheck(
    (bool)preg_match(
        '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\.login$/',
        (string)($firstHeaders['X-TT-Passport-Verify-Portrait'] ?? '')
    ),
    'QR request is missing the login-scoped portrait ID'
);
douyinCheck(
    str_starts_with((string)($firstHeaders['X-TT-Session-Dtrait'] ?? ''), 'd0_'),
    'QR request is missing the DTrait header'
);
douyinCheck(
    str_contains((string)($firstHeaders['Cookie'] ?? ''), 'passport_csrf_token='),
    'QR request is missing the server-side CSRF cookie'
);

$scanned = $client->qrPoll();
douyinCheck(($scanned['data']['status'] ?? '') === '2', 'QR polling response changed unexpectedly');
$pollState = $client->publicState();
douyinCheck($pollState['status'] === 'scanned', 'numeric scanned status was not normalized');
douyinCheck($pollState['verification']['required'], 'captcha response did not request verification');
douyinCheck(
    $pollState['verification']['url'] === 'https://verify.snssdk.com/captcha/?ticket=LOCAL_FIXTURE',
    'captcha URL was not extracted'
);
douyinCheck($urlSigner->requests[1]['method'] === 'POST', 'QR polling must use POST');
parse_str($urlSigner->requests[1]['body'], $pollBody);
douyinCheck(($pollBody['token'] ?? '') === 'QR_TOKEN', 'QR polling body is missing the token');
douyinCheck(($pollBody['is_frontier'] ?? '') === 'false', 'minimal QR polling must preserve is_frontier=false');
$pollUrl = parse_url($urlSigner->requests[1]['url']);
parse_str((string)($pollUrl['query'] ?? ''), $pollQuery);
douyinCheck(($pollQuery['msToken'] ?? '') === 'SERVER_MS_TOKEN', 'response x-ms-token was not persisted');
douyinCheck(($pollQuery['p_ca'] ?? '') === '4.0.17', 'QR polling query is missing p_ca');
douyinCheck(($pollQuery['p_ca_real'] ?? '') === '1.0.0.852', 'QR polling query is missing the current captcha build');
douyinCheck(
    str_contains((string)($transport->requests[1]['options']['headers']['User-Agent'] ?? ''), 'Chrome/151.0.0.0'),
    'QR polling request is using a stale browser user agent'
);
douyinCheck(
    $transport->requests[1]['options']['headers']['X-TT-Passport-Verify-Portrait']
        === $firstHeaders['X-TT-Passport-Verify-Portrait'],
    'QR polling did not preserve the portrait ID from QR generation'
);
douyinCheck(
    count(array_unique($urlSigner->dtraitKeys)) === 1
        && (bool)preg_match('/^[a-f0-9]{32}$/', $urlSigner->dtraitKeys[0] ?? ''),
    'QR requests did not preserve one DTrait AES key for the login session'
);

$confirmed = $client->qrPoll();
douyinCheck(($confirmed['data']['status'] ?? '') === 'confirmed', 'confirmed response changed unexpectedly');
douyinCheck($client->publicState()['authenticated'], 'confirmed QR state was not marked authenticated');
$credentials = $client->credentials();
douyinCheck(($credentials['cookies']['uid_tt'] ?? '') === 'LOCAL_UID', 'uid_tt cookie was not captured');
douyinCheck(($credentials['cookies']['sessionid'] ?? '') === 'LOCAL_SESSION', 'session cookie was not captured');
douyinCheck(!array_key_exists('cookies', $client->publicState()), 'public state exposed login cookies');
douyinCheck(!array_key_exists('dtrait_key', $client->publicState()), 'public state exposed the DTrait key');
douyinCheck(!array_key_exists('verify_portrait', $client->publicState()), 'public state exposed the portrait ID');
$profile = $client->profile();
douyinCheck($profile['user_id'] === '7312345678901234567', 'account info user ID was not normalized');
douyinCheck($profile['sec_user_id'] === 'MS4wLjABAAAA_LOCAL_FIXTURE', 'sec_user_id was not preserved');
douyinCheck($profile['nickname'] === 'Local Douyin Fixture', 'account nickname was not normalized');
douyinCheck(
    parse_url($urlSigner->requests[3]['url'], PHP_URL_PATH) === '/passport/account/info/v2/',
    'confirmed login did not request account info'
);

$pendingState = [
    'version' => 1,
    'cookies' => [
        'passport_csrf_token' => 'LOCAL_CSRF',
        'passport_csrf_token_default' => 'LOCAL_CSRF',
    ],
    'ms_token' => 'LOCAL_REDIRECT_MS_TOKEN',
    'token' => 'LOCAL_REDIRECT_QR_TOKEN',
    'is_frontier' => true,
    'expire_time' => time() + 120,
    'status' => 'scanned',
];
$redirectTransport = new DouyinRecordingTransport([
    [
        'payload' => [
            'data' => [
                'error_code' => 0,
                'status' => 'confirmed',
                'redirect_url' => 'https://www.douyin.com/passport/sso/login/?ticket=LOCAL_FIXTURE',
            ],
            'message' => 'success',
        ],
    ],
    [
        'status' => 302,
        'headers' => ['Location' => ['/passport/sso/finalize/?ticket=LOCAL_FIXTURE']],
        'set_cookie' => ['sid_guard=LOCAL_GUARD; Path=/; Secure; HttpOnly'],
    ],
    [
        'status' => 200,
        'set_cookie' => [
            'uid_tt=LOCAL_REDIRECT_UID; Path=/; Secure; HttpOnly',
            'sessionid=LOCAL_REDIRECT_SESSION; Path=/; Secure; HttpOnly',
        ],
    ],
    [
        'payload' => [
            'data' => [
                'error_code' => 0,
                'user' => [
                    'user_id' => '7399999999999999999',
                    'sec_user_id' => 'MS4wLjABAAAA_REDIRECT_FIXTURE',
                    'nickname' => 'Redirect Fixture',
                    'avatar' => ['url_list' => ['https://p6.byteimg.com/local-avatar.jpeg']],
                ],
            ],
            'message' => 'success',
        ],
    ],
]);
$redirectSigner = new DouyinRecordingSigner();
$redirectClient = new Client(
    config: ['state' => $pendingState],
    transport: $redirectTransport,
    urlSigner: $redirectSigner,
    passportSigner: $passport
);
$redirectClient->qrPoll();
douyinCheck(count($redirectTransport->requests) === 4, 'confirmed redirect flow made the wrong number of requests');
douyinCheck(
    $redirectTransport->requests[1]['url'] === 'https://www.douyin.com/passport/sso/login/?ticket=LOCAL_FIXTURE',
    'confirmed redirect URL was not consumed'
);
douyinCheck(
    $redirectTransport->requests[2]['url'] === 'https://www.douyin.com/passport/sso/finalize/?ticket=LOCAL_FIXTURE',
    'relative authentication redirect was not resolved'
);
douyinCheck(
    str_contains((string)($redirectTransport->requests[2]['options']['headers']['Cookie'] ?? ''), 'sid_guard=LOCAL_GUARD'),
    'cookies from an authentication redirect were not sent to the next hop'
);
douyinCheck($redirectClient->publicState()['authenticated'], 'redirect login did not capture final authentication cookies');
douyinCheck($redirectClient->state()['auth_redirect_consumed'], 'redirect completion was not persisted in protocol state');
$redirectProfile = $redirectClient->profile();
douyinCheck($redirectProfile['user_id'] === '7399999999999999999', 'redirect account profile was not captured');
douyinCheck($redirectProfile['nickname'] === 'Redirect Fixture', 'redirect account nickname was not captured');

$unsafeRedirectTransport = new DouyinRecordingTransport([
    [
        'payload' => [
            'data' => [
                'error_code' => 0,
                'status' => 'confirmed',
                'redirect_url' => 'https://www.douyin.com.attacker.invalid/passport/sso/login/?ticket=LOCAL_FIXTURE',
            ],
            'message' => 'success',
        ],
    ],
]);
$unsafeRedirectClient = new Client(
    config: ['state' => $pendingState],
    transport: $unsafeRedirectTransport,
    urlSigner: new DouyinRecordingSigner(),
    passportSigner: $passport
);
$unsafeRedirectClient->qrPoll();
douyinCheck(count($unsafeRedirectTransport->requests) === 1, 'untrusted authentication redirect was requested');
douyinCheck(!$unsafeRedirectClient->publicState()['authenticated'], 'untrusted redirect created an authenticated state');

$retryTransport = new DouyinRecordingTransport([
    [
        'headers' => ['x-ms-token' => ['RETRY_MS_TOKEN']],
        'payload' => ['data' => ['error_code' => 8], 'message' => 'retry'],
    ],
    [
        'payload' => [
            'data' => [
                'error_code' => 0,
                'token' => 'RETRY_QR_TOKEN',
                'qrcode' => $qrcode,
                'is_frontier' => false,
                'expire_time' => time() + 120,
            ],
            'message' => 'success',
        ],
    ],
]);
$retrySigner = new DouyinRecordingSigner();
$retryClient = new Client(
    config: ['ms_token' => 'RANDOM_BOOTSTRAP_TOKEN'],
    transport: $retryTransport,
    urlSigner: $retrySigner,
    passportSigner: $passport
);
$retryResult = $retryClient->qrGenerate();
douyinCheck(($retryResult['data']['token'] ?? '') === 'RETRY_QR_TOKEN', 'msToken bootstrap retry failed');
douyinCheck(count($retrySigner->requests) === 2, 'msToken bootstrap did not retry exactly once');
$retryUrl = parse_url($retrySigner->requests[1]['url']);
parse_str((string)($retryUrl['query'] ?? ''), $retryQuery);
douyinCheck(($retryQuery['msToken'] ?? '') === 'RETRY_MS_TOKEN', 'retry did not use response x-ms-token');

$verifyPayload = json_encode([
    'decision' => 'captcha',
    'subtype' => 'slide',
    'log_id' => 'LOCAL_FIXTURE_LOG',
], JSON_UNESCAPED_SLASHES);
$turingTransport = new DouyinRecordingTransport([
    [
        'headers' => ['x-vc-bdturing-parameters' => [base64_encode((string)$verifyPayload)]],
        'payload' => [
            'data' => ['error_code' => 0, 'status' => 'scanned'],
            'message' => 'success',
        ],
    ],
]);
$turingSigner = new DouyinRecordingSigner();
$turingClient = new Client(
    config: ['state' => $client->state()],
    transport: $turingTransport,
    urlSigner: $turingSigner,
    passportSigner: $passport
);
$turingClient->qrPoll();
$turingVerification = $turingClient->publicState()['verification'];
douyinCheck($turingVerification['required'], 'Bdturing response header did not request verification');
douyinCheck($turingVerification['mode'] === 'turing', 'Bdturing response header selected the wrong renderer');
douyinCheck($turingVerification['verify_data'] === $verifyPayload, 'base64 Bdturing payload was not decoded');

$blockedTransport = new DouyinRecordingTransport([
    [
        'payload' => [
            'data' => [
                'error_code' => 0,
                'status' => 'new',
                'captcha' => 'https://TARGET/captcha/',
            ],
            'message' => 'success',
        ],
    ],
]);
$blockedSigner = new DouyinRecordingSigner();
$blockedClient = new Client(
    config: ['state' => $client->state()],
    transport: $blockedTransport,
    urlSigner: $blockedSigner,
    passportSigner: $passport
);
$blockedClient->qrPoll();
douyinCheck(
    $blockedClient->publicState()['verification']['url'] === '',
    'untrusted verification URL passed the upstream allowlist'
);

echo "Douyin SDK protocol tests passed\n";
