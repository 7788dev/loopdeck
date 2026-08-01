<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use netease\sdk\Client;

$client = new Client([], [
    'auto_anonymous_token' => false,
    'cache_dir' => dirname(__DIR__) . '/runtime/netease-sdk',
]);

$checks = [
    'qr_eapi' => $client->request('/api/login/qrcode/unikey', ['type' => 3], 'eapi', [
        'cookie' => '',
        'skip_anonymous' => true,
    ]),
    'personalized_weapi' => $client->request('/api/personalized/playlist', [
        'limit' => 1,
        'total' => true,
        'n' => 1000,
    ], 'weapi', [
        'cookie' => '',
        'skip_anonymous' => true,
    ]),
    'search_eapi' => $client->request('/api/cloudsearch/pc', [
        's' => 'test',
        'type' => 1,
        'limit' => 1,
        'offset' => 0,
        'total' => true,
    ], 'eapi', [
        'cookie' => '',
        'skip_anonymous' => true,
    ]),
];

$summary = [];
foreach ($checks as $name => $response) {
    $body = json_decode($response['body'], true);
    $code = is_array($body) ? (int)($body['code'] ?? 0) : 0;
    $summary[$name] = ['http' => $response['status'], 'code' => $code];
    if ($response['status'] < 200 || $response['status'] >= 300 || $code === 0) {
        fwrite(STDERR, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
        exit(1);
    }
}

$anonymousCache = dirname(__DIR__) . '/runtime/netease-sdk-anonymous-smoke';
$anonymousClient = new Client([], ['cache_dir' => $anonymousCache]);
$anonymousResponse = $anonymousClient->request('/api/login/qrcode/unikey', ['type' => 3], 'eapi', [
    'cookie' => '',
]);
$anonymousBody = json_decode($anonymousResponse['body'], true);
$anonymousTokenFile = $anonymousCache . '/anonymous_token.txt';
$summary['anonymous_registration'] = [
    'code' => (int)($anonymousBody['code'] ?? 0),
    'token_cached' => is_file($anonymousTokenFile) && filesize($anonymousTokenFile) > 0,
];
if ($summary['anonymous_registration']['code'] === 0 || !$summary['anonymous_registration']['token_cached']) {
    fwrite(STDERR, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(1);
}

// Preparing a comment request performs the live XEAPI public-key and v3 token handshakes,
// but deliberately does not send the state-changing comment request.
$xeapi = $client->prepare('/api/resource/comments/add', [
    'threadId' => 'R_SO_4_1',
    'content' => 'protocol-smoke-test',
], 'xeapi', [
    'check_token' => 'v3',
    'os' => 'android',
    'skip_anonymous' => true,
]);
$summary['xeapi_prepare'] = [
    'url' => $xeapi['url'],
    'token' => !empty($xeapi['options']['headers']['X-antiCheatToken']),
];

$shareXeapi = $client->prepare('/api/share/friends/resource', [
    'type' => 'song',
    'msg' => '',
    'id' => '1',
], 'xeapi', [
    'check_token' => 'v3',
    'os' => 'android',
    'skip_anonymous' => true,
]);
$summary['share_xeapi_prepare'] = [
    'url' => $shareXeapi['url'],
    'token' => !empty($shareXeapi['options']['headers']['X-antiCheatToken']),
];
if (!$summary['share_xeapi_prepare']['token']) {
    fwrite(STDERR, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(1);
}

echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
