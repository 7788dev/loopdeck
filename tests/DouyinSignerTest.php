<?php

declare(strict_types=1);

require __DIR__ . '/DouyinTestBootstrap.php';

use douyin\sdk\NodeSigner;
use douyin\sdk\PassportSigner;

$passport = new PassportSigner();

douyinCheck(
    $passport->aidSign('/passport/web/get_qrcode/', 1785844800)
        === '779e3398e053d18e35f110f3a426fe081909a29f6b4b265b6ec525f2637bb922',
    'x-tt-passport-aid-sign no longer matches the captured vector'
);

$base = $passport->baseQuery('', 1785869802237, 'f098c37d', '123');
douyinCheck(
    $base['p_no'] === '307004eb17a55caf2a3500ca9daac88373598e01efef6ed47cbfe919a04f2422',
    'p_no no longer matches the captured vector'
);
douyinCheck($base['ts'] === 1785844800, 'daily UTC noon timestamp is incorrect');

$query = [
    'passport_jssdk_version' => '3.1.3',
    'passport_jssdk_type' => 'normal',
    'is_from_ttaccountsdk' => '1',
    'aid' => '6383',
    'language' => 'zh',
    'account_app_language' => 'zh-CN',
    'ts' => 1785844800,
    'next' => 'https://www.douyin.com',
    'need_short_url' => 'true',
    'need_logo' => 'false',
    'is_new_login' => '1',
    'is_from_iesaccountsaas' => '1',
    'p_ui' => '2.1.9-alpha.6',
];
$signature = $passport->sign($query, ['token' => 'TOKEN', 'is_frontier' => 'false']);
douyinCheck(
    $signature['sign'] === '52d10549694ea574efc227ebbf29178a1ed0ea9e18ad79436385464dd0afe5b9',
    'passport sign does not use the sorted first ten query fields and sorted body'
);
douyinCheck(
    $signature['qs']
        === '6466666a706b715a6475755a69646b627064626029646c61296c765a63776a685a6c60766466666a706b7176646476296c765a63776a685a71716466666a706b7176616e296c765a6b60725a696a626c6b2969646b6270646260296b6060615a696a626a296b6060615a766d6a77715a707769296b607d7129755a706c',
    'qs XOR encoding changed unexpectedly'
);

$msToken = $passport->randomMsToken();
douyinCheck(strlen($msToken) === 184, 'random msToken has the wrong length');
douyinCheck((bool)preg_match('/^[A-Za-z0-9_-]{183}=$/', $msToken), 'random msToken has the wrong alphabet');

$nodeSigner = new NodeSigner(timeout: 12.0);
$signedUrl = $nodeSigner->sign(
    'https://login.douyin.com/passport/web/get_qrcode/?aid=6383&msToken=LOCAL_FIXTURE_TOKEN'
);
$parts = parse_url($signedUrl);
parse_str((string)($parts['query'] ?? ''), $signedQuery);
douyinCheck(($parts['host'] ?? '') === 'login.douyin.com', 'Node signer changed the request host');
douyinCheck(!empty($signedQuery['a_bogus']), 'Node VM did not append a_bogus');

$signedRequest = $nodeSigner->signRequest(
    'https://login.douyin.com/passport/web/check_qrconnect/?aid=6383&msToken=LOCAL_FIXTURE_TOKEN',
    'POST',
    'token=LOCAL_FIXTURE_TOKEN',
    str_repeat('01', 16)
);
$dtrait = $signedRequest['headers']['X-TT-Session-Dtrait'] ?? '';
$dtraitParts = explode('_', $dtrait);
douyinCheck(str_contains($signedRequest['url'], 'a_bogus='), 'enhanced signer result is missing a_bogus');
douyinCheck(count($dtraitParts) === 3 && $dtraitParts[0] === 'd0', 'Node VM returned the wrong DTrait envelope');
douyinCheck(strlen($dtrait) >= 700 && strlen($dtrait) <= 1200, 'Node VM returned an implausible DTrait length');
douyinCheck(strlen((string)base64_decode($dtraitParts[1] ?? '', true)) === 256, 'DTrait wrapped key is malformed');
douyinCheck(strlen((string)base64_decode($dtraitParts[2] ?? '', true)) >= 256, 'DTrait encrypted payload is malformed');

$rejected = false;
try {
    $nodeSigner->sign('https://TARGET/passport/web/get_qrcode/?aid=6383');
} catch (RuntimeException $exception) {
    $rejected = true;
}
douyinCheck($rejected, 'Node signer accepted a URL outside the local passport target boundary');

echo "Douyin signer tests passed\n";
