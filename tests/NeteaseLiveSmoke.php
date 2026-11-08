<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use netease\sdk\Client;

$musicU = (string)getenv('NETEASE_MUSIC_U');
$client = new Client([
    'user_id' => (string)getenv('NETEASE_USER_ID'),
    'csrf' => (string)getenv('NETEASE_CSRF'),
    'music_u' => $musicU,
], [
    'auto_anonymous_token' => false,
    'cache_dir' => '',
    // A live CSR check must not silently succeed through the BSR fallback.
    'xeapi_fallback' => false,
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
    // This is a real, read-only encrypted business response, not only prepare().
    'task_list_xeapi' => $client->request('/api/vipnewcenter/app/level/task/list', [], 'xeapi', [
        'skip_anonymous' => true,
    ]),
];
if ($musicU !== '') {
    $checks['account_level'] = $client->request('/api/user/level', [], 'weapi', ['skip_anonymous' => true]);
}

$summary = ['session_supplied' => $musicU !== '', 'read_only' => true];
foreach ($checks as $name => $response) {
    $body = json_decode($response['body'], true);
    $code = is_array($body) ? (int)($body['code'] ?? 0) : 0;
    $summary[$name] = ['http' => $response['status'], 'code' => $code];
    if ($response['status'] < 200 || $response['status'] >= 300 || $code === 0) {
        fwrite(STDERR, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
        exit(1);
    }
    if (in_array($name, ['task_list_xeapi', 'account_level'], true)
        && ($code !== 200 || !is_array($body['data'] ?? null))) {
        fwrite(STDERR, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
        exit(1);
    }
}

// A supplied account verifies its own session. Guest registration is a separate
// path and retains a stable device/cache instead of creating a guest per run.
if ($musicU === '') {
    $anonymousCache = sys_get_temp_dir() . '/loopdeck-netease-anonymous-smoke';
    $anonymousClient = new Client([], ['cache_dir' => $anonymousCache, 'xeapi_fallback' => false]);
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
parse_str((string)($xeapi['options']['body'] ?? ''), $xeapiForm);
$summary['xeapi_prepare']['protocol'] = !empty($xeapiForm['C']) && empty($xeapiForm['B'])
    ? 'csr'
    : 'bsr';
if ($summary['xeapi_prepare']['protocol'] !== 'csr') {
    fwrite(STDERR, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(1);
}

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
parse_str((string)($shareXeapi['options']['body'] ?? ''), $shareXeapiForm);
$summary['share_xeapi_prepare']['protocol'] = !empty($shareXeapiForm['C']) && empty($shareXeapiForm['B'])
    ? 'csr'
    : 'bsr';
if (!$summary['share_xeapi_prepare']['token']) {
    fwrite(STDERR, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(1);
}
if ($summary['share_xeapi_prepare']['protocol'] !== 'csr') {
    fwrite(STDERR, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(1);
}

echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
