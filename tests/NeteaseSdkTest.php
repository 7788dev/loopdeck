<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use netease\Netease;
use netease\sdk\Client;
use netease\sdk\Crypto;
use netease\sdk\GuzzleTransport;
use netease\sdk\TransportInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

final class RecordingTransport implements TransportInterface
{
    public array $requests = [];
    private array $responses;

    public function __construct(array $responses = [])
    {
        $this->responses = $responses;
    }

    public function request(string $method, string $url, array $options = []): array
    {
        $this->requests[] = compact('method', 'url', 'options');
        return array_replace([
            'status' => 200,
            'headers' => [],
            'body' => '{"code":200}',
            'header' => '',
            'set_cookie' => [],
        ], array_shift($this->responses) ?? []);
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function decodeFormBody(string $body): array
{
    parse_str($body, $decoded);
    return is_array($decoded) ? $decoded : [];
}

$crypto = new Crypto();
$eapi = $crypto->eapi('/api/test', ['name' => 'music', 'e_r' => false]);
$decodedEapi = $crypto->decryptEapiRequest($eapi['params']);
check($decodedEapi['uri'] === '/api/test', 'EAPI URI did not round-trip');
check($decodedEapi['data']['name'] === 'music', 'EAPI payload did not round-trip');

$weapiSecret = '0123456789abcdef';
$weapi = $crypto->weapi(['hello' => 'world'], $weapiSecret);
$secondLayer = openssl_decrypt(
    base64_decode($weapi['params'], true),
    'aes-128-cbc',
    $weapiSecret,
    OPENSSL_RAW_DATA,
    '0102030405060708'
);
check(is_string($secondLayer), 'WEAPI second AES layer could not be decrypted');
$firstLayer = openssl_decrypt(
    base64_decode($secondLayer, true),
    'aes-128-cbc',
    '0CoJUm6Qyw8W8jud',
    OPENSSL_RAW_DATA,
    '0102030405060708'
);
check($firstLayer === '{"hello":"world"}', 'WEAPI first AES layer did not round-trip');
check(strlen($weapi['encSecKey']) === 256, 'WEAPI RSA output has the wrong size');

$peerSecret = random_bytes(SODIUM_CRYPTO_SCALARMULT_SCALARBYTES);
$peerPublic = sodium_crypto_scalarmult_base($peerSecret);
$dynamicKey = '0123456789abcdef';
$mask = "\x05" . str_repeat("\x22", 15);
$ephemeralSecret = str_repeat("\x33", 32);
$iv = str_repeat("\x44", 12);
$randomQueue = [$dynamicKey, $mask, $ephemeralSecret, $iv];
$deterministicCrypto = new Crypto(static function (int $length) use (&$randomQueue): string {
    $value = array_shift($randomQueue);
    check(is_string($value) && strlen($value) === $length, 'Unexpected XEAPI random byte request');
    return $value;
});
$publicKeyState = [
    'publicKey' => base64_encode($peerPublic),
    'version' => 'test-v1',
    'sk' => 'server-key',
];
$xeapi = $deterministicCrypto->xeapi('/api/resource/comments/add', [
    'threadId' => 'R_SO_4_1',
    'content' => 'hello',
    'e_r' => true,
], $publicKeyState, ['os' => 'android']);

$sessionPacket = base64_decode($xeapi['S'], true);
$ephemeralPublic = substr($sessionPacket, 0, 32);
$sessionIv = substr($sessionPacket, 32, 12);
$sessionTag = substr($sessionPacket, -16);
$sessionCiphertext = substr($sessionPacket, 44, -16);
$sharedSecret = sodium_crypto_scalarmult($peerSecret, $ephemeralPublic);
$zeroKey = str_repeat("\0", 32);
$prk = hash_hmac('sha256', $sharedSecret, $zeroKey, true);
$sessionAesKey = substr(hash_hmac('sha256', $ephemeralPublic . "\x01", $prk, true), 0, 16);
$sessionPlain = openssl_decrypt(
    $sessionCiphertext,
    'aes-128-gcm',
    $sessionAesKey,
    OPENSSL_RAW_DATA,
    $sessionIv,
    $sessionTag
);
check($sessionPlain === base64_encode($dynamicKey) . '|android|server-key', 'XEAPI X25519 session packet is invalid');

$middle = openssl_decrypt(base64_decode($xeapi['B'], true), 'aes-128-ecb', $dynamicKey, OPENSSL_RAW_DATA);
check(is_string($middle), 'XEAPI B outer layer could not be decrypted');
$actualMask = substr($middle, 0, 16);
$rotated = substr($middle, 16);
$rotation = (ord($actualMask[0]) & 15) % strlen($rotated);
$base64Inner = $rotation === 0
    ? $rotated
    : substr($rotated, -$rotation) . substr($rotated, 0, -$rotation);
$xoredInner = base64_decode($base64Inner, true);
$encryptedInner = '';
for ($i = 0, $length = strlen($xoredInner); $i < $length; $i++) {
    $encryptedInner .= $xoredInner[$i] ^ $actualMask[$i & 15];
}
$staticKey = hex2bin('ab1d5a430f6bb04a3f01e81ddd72bd916d5ce591248ac128714806d7f8fb1b84');
$xeapiPlain = openssl_decrypt($encryptedInner, 'aes-256-ecb', $staticKey, OPENSSL_RAW_DATA);
$xeapiEnvelope = json_decode($xeapiPlain, true);
check(($xeapiEnvelope['queryString'] ?? '') === 'e_r=true', 'XEAPI query envelope is invalid');
$xeapiBody = [];
parse_str(base64_decode($xeapiEnvelope['body'], true), $xeapiBody);
check(($xeapiBody['threadId'] ?? '') === 'R_SO_4_1', 'XEAPI form body is invalid');

$transport = new RecordingTransport();
$client = new Client([
    'user_id' => 1,
    'csrf' => 'csrf-value',
    'music_u' => 'music-u-value',
], [
    'auto_anonymous_token' => false,
    'cache_dir' => '',
    'xeapi_public_key' => $publicKeyState,
    'anti_cheat_token_v3' => 'anti-token',
], $transport);

$preparedEapi = $client->prepare('/api/cloudsearch/pc', ['s' => 'test'], 'eapi');
check($preparedEapi['url'] === 'https://interfacepc.music.163.com/eapi/cloudsearch/pc', 'EAPI URL mapping is invalid');
$preparedEapiForm = decodeFormBody($preparedEapi['options']['body']);
$preparedEapiData = $crypto->decryptEapiRequest($preparedEapiForm['params'])['data'];
check(($preparedEapiData['header']['MUSIC_U'] ?? '') === 'music-u-value', 'EAPI header cookie is missing MUSIC_U');

$preparedWeapi = $client->prepare('/api/v1/user/detail/1', [], 'weapi', ['weapi_secret' => $weapiSecret]);
check($preparedWeapi['url'] === 'https://music.163.com/weapi/v1/user/detail/1', 'WEAPI URL mapping is invalid');
$preparedWeapiForm = decodeFormBody($preparedWeapi['options']['body']);
check(isset($preparedWeapiForm['params'], $preparedWeapiForm['encSecKey']), 'WEAPI form is incomplete');

$preparedApi = $client->prepare('/api/login/qrcode/unikey', ['type' => 3], 'api', ['cookie' => '', 'skip_anonymous' => true]);
check($preparedApi['url'] === 'https://interface.music.163.com/api/login/qrcode/unikey', 'API URL mapping is invalid');
check(str_contains($preparedApi['options']['body'], 'e_r=false'), 'API boolean form encoding does not match URLSearchParams');

$preparedXeapi = $client->prepare('/api/resource/comments/add', ['threadId' => 'R_SO_4_1'], 'xeapi', [
    'check_token' => 'v3',
    'os' => 'android',
]);
check($preparedXeapi['url'] === 'https://interface3.music.163.com/xeapi/resource/comments/add', 'XEAPI URL mapping is invalid');
check(($preparedXeapi['options']['headers']['X-antiCheatToken'] ?? '') === 'anti-token', 'XEAPI anti-cheat token is missing');
$preparedXeapiForm = decodeFormBody($preparedXeapi['options']['body']);
check(isset($preparedXeapiForm['B'], $preparedXeapiForm['S'], $preparedXeapiForm['R']), 'XEAPI form is incomplete');

$client->rawRequest('GET', 'https://example.test/resource', ['cookie' => null]);
check(str_contains($transport->requests[0]['options']['headers']['Cookie'] ?? '', 'MUSIC_U=music-u-value'), 'Raw request with a null cookie did not inherit the session');

$encryptedResponse = openssl_encrypt(
    '{"code":200,"encrypted":true}',
    'aes-128-ecb',
    'e82ckenh8dichen8',
    OPENSSL_RAW_DATA
);
$encryptedTransport = new RecordingTransport([['body' => $encryptedResponse]]);
$encryptedClient = new Client([], [
    'auto_anonymous_token' => false,
    'cache_dir' => '',
], $encryptedTransport);
$decryptedResponse = $encryptedClient->request('/api/test', [], 'eapi', [
    'cookie' => '',
    'skip_anonymous' => true,
    'e_r' => true,
]);
$decryptedBody = json_decode($decryptedResponse['body'], true);
check(($decryptedBody['encrypted'] ?? false) === true, 'Encrypted EAPI response was not decrypted');

$manyTransport = new RecordingTransport([
    ['body' => '{"code":200,"request":1}'],
    ['body' => '{"code":200,"request":2}'],
]);
$manyClient = new Client([], [
    'auto_anonymous_token' => false,
    'cache_dir' => '',
], $manyTransport);
$manyResponses = $manyClient->requestMany([
    'first' => ['uri' => '/api/test/one', 'data' => ['id' => 1]],
    'second' => ['uri' => '/api/test/two', 'data' => ['id' => 2]],
]);
check(count($manyTransport->requests) === 2, 'Multi-request fallback did not keep requests independent');
check((json_decode($manyResponses['first']['body'], true)['request'] ?? 0) === 1, 'Multi-request response order changed');
check((json_decode($manyResponses['second']['body'], true)['request'] ?? 0) === 2, 'Multi-request response keys changed');

$poolMock = new MockHandler([
    new Response(200, [], '{"code":200,"request":"first"}'),
    new Response(200, [], '{"code":200,"request":"second"}'),
]);
$poolClient = new Client([], [
    'auto_anonymous_token' => false,
    'cache_dir' => '',
], new GuzzleTransport(new GuzzleClient(['handler' => HandlerStack::create($poolMock)])));
$poolResponses = $poolClient->requestMany([
    'first' => ['uri' => '/api/test/first'],
    'second' => ['uri' => '/api/test/second'],
], 2);
check((json_decode($poolResponses['first']['body'], true)['request'] ?? '') === 'first', 'Concurrent response lost its first key');
check((json_decode($poolResponses['second']['body'], true)['request'] ?? '') === 'second', 'Concurrent response lost its second key');

$proxyTransport = new RecordingTransport();
$proxyClient = new Client([], [
    'auto_anonymous_token' => false,
    'cache_dir' => '',
    'proxy_url' => 'http://proxy.example:80',
    'proxy_order_no' => 'order',
    'proxy_secret' => 'secret',
], $proxyTransport);
$proxyClient->rawRequest('GET', 'https://example.test/proxy', ['cookie' => '', 'proxy' => true]);
check(($proxyTransport->requests[0]['options']['proxy'] ?? '') === 'http://proxy.example:80', 'Configured proxy URL was not applied');
check(isset($proxyTransport->requests[0]['options']['headers']['Proxy-Authorization']), 'Proxy authorization header was not generated');

$qrTransport = new RecordingTransport([[
    'body' => '{"code":200,"unikey":"qr-key"}',
]]);
$qrClient = new Client([], [
    'auto_anonymous_token' => false,
    'cache_dir' => '',
], $qrTransport);
$legacy = new Netease(null, null, null, [], $qrClient);
check($legacy->get_qr_key() === 'qr-key', 'Legacy facade did not use the PHP SDK response');
check($qrTransport->requests[0]['url'] === 'https://interfacepc.music.163.com/eapi/login/qrcode/unikey', 'QR login did not use current upstream EAPI mode');

$loginTransport = new RecordingTransport([
    [
        'body' => '{"code":200}',
        'set_cookie' => ['MUSIC_U=session-token; Path=/', '__csrf=csrf-token; Path=/'],
    ],
    [
        'body' => '{"code":200,"profile":{"userId":7,"nickname":"tester","avatarUrl":"avatar"}}',
    ],
]);
$loginClient = new Client([], [
    'auto_anonymous_token' => false,
    'cache_dir' => '',
], $loginTransport);
$loginFacade = new Netease(null, null, null, [], $loginClient);
$login = $loginFacade->loginByEmail('test@example.com', md5('password'));
check(($login['code'] ?? 0) === 200, 'Login fallback did not resolve the profile');
check(($login['data']['user_id'] ?? 0) === 7, 'Login fallback returned the wrong user');
check(($login['data']['musicu'] ?? '') === 'session-token', 'Login fallback lost MUSIC_U');
check(str_contains($loginTransport->requests[1]['options']['headers']['Cookie'] ?? '', 'MUSIC_U=session-token'), 'Login status request did not receive response cookies');

$qrLoginTransport = new RecordingTransport([
    [
        'body' => '{"code":803}',
        'set_cookie' => ['MUSIC_U=qr-session; Path=/', '__csrf=qr-csrf; Path=/'],
    ],
    [
        'body' => '{"code":200,"profile":{"userId":8,"nickname":"qr-user","avatarUrl":"qr-avatar"}}',
    ],
]);
$qrLoginClient = new Client([], [
    'auto_anonymous_token' => false,
    'cache_dir' => '',
], $qrLoginTransport);
$qrLoginFacade = new Netease(null, null, null, [], $qrLoginClient);
$qrLogin = $qrLoginFacade->qrLogin('qr-key');
check(($qrLogin['code'] ?? 0) === 200, 'QR login success path did not resolve the profile');
check(($qrLogin['data']['user_id'] ?? 0) === 8, 'QR login returned the wrong user');
check(($qrLogin['data']['musicu'] ?? '') === 'qr-session', 'QR login lost MUSIC_U');

$source = file_get_contents(dirname(__DIR__) . '/extend/netease/Netease.php');
check(strpos($source, 'netease_bridge') === false, 'Node bridge reference remains in the project facade');
check(strpos($source, "], 'xeapi', ['os' => 'android', 'check_token' => 'v3']);") !== false, 'Comment XEAPI v3 mapping is missing');

echo "Netease SDK protocol tests passed\n";
