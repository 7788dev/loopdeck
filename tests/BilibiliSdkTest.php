<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use bilibili\Bilibili as BilibiliService;
use bilibili\sdk\AppSigner;
use bilibili\sdk\Client;
use bilibili\sdk\CookieSession;
use bilibili\sdk\TransportInterface;
use bilibili\sdk\WbiSigner;

final class BilibiliRecordingTransport implements TransportInterface
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
            'body' => '{"code":0,"message":"0","data":{}}',
            'header' => '',
            'set_cookie' => [],
        ], array_shift($this->responses) ?? []);
    }
}

function biliCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function biliRequestByPath(array $requests, string $path): array
{
    foreach ($requests as $request) {
        if (str_contains($request['url'], $path)) {
            return $request;
        }
    }
    throw new RuntimeException('Missing request for ' . $path);
}

$session = new CookieSession('DedeUserID=1; SESSDATA=old; bili_jct=csrf');
$session->capture([
    'SESSDATA=new-token; Path=/; Domain=.bilibili.com; HttpOnly',
    'sid=session-id; Path=/',
]);
biliCheck($session->get('SESSDATA') === 'new-token', 'Set-Cookie did not replace SESSDATA');
biliCheck(str_contains($session->header(), 'sid=session-id'), 'Set-Cookie did not capture sid');

$signer = new WbiSigner();
$signed = $signer->sign(
    ['foo' => 114, 'bar' => 514, 'zab' => 1919810],
    '7cd084941338484aae1ad9425b84077c',
    '4932caff0ff746eab6f01bf08b70ac45',
    1702204169
);
biliCheck($signer->mixinKey(
    '7cd084941338484aae1ad9425b84077c',
    '4932caff0ff746eab6f01bf08b70ac45'
) === 'ea1db124af3c7062474693fa704f4ff8', 'WBI mixin key does not match the upstream vector');
biliCheck(($signed['w_rid'] ?? '') === '8f6f2b5b3d485fe1886cec6a0be8c5d4', 'WBI signature does not match the upstream vector');

$appSigner = new AppSigner();
biliCheck(
    $appSigner->protocol() === ['version' => '9.5.0', 'build' => '9050300'],
    'Current Android protocol metadata is stale'
);
biliCheck(
    $appSigner->protocol(['version' => 'invalid', 'build' => '1']) === ['version' => '9.5.0', 'build' => '9050300'],
    'Invalid Android protocol metadata did not fall back safely'
);

$captchaTransport = new BilibiliRecordingTransport();
$captchaClient = new Client([], [], $captchaTransport);
$captchaClient->captcha();
biliCheck(
    ($captchaTransport->requests[0]['options']['query']['source'] ?? '') === 'main-fe-header',
    'Captcha source is stale'
);

$loginTransport = new BilibiliRecordingTransport([
    ['body' => '{"code":0,"data":{"url":"https://scan.test/","qrcode_key":"qr-key"}}'],
    [
        'body' => '{"code":0,"data":{"code":0,"message":"","refresh_token":"refresh","url":"https://www.bilibili.com/?DedeUserID=42&DedeUserID__ckMd5=mid-md5&SESSDATA=session-token&bili_jct=csrf-token&sid=sid-token"}}',
    ],
    ['body' => '{"code":0,"data":{"isLogin":true,"mid":42}}'],
]);
$loginClient = new Client([], [], $loginTransport);
$generated = $loginClient->qrGenerate();
biliCheck(($generated['data']['qrcode_key'] ?? '') === 'qr-key', 'QR generate response mapping failed');
$polled = $loginClient->qrPoll('qr-key');
biliCheck(($polled['data']['code'] ?? -1) === 0, 'QR poll success mapping failed');
biliCheck($loginClient->cookies()['SESSDATA'] === 'session-token', 'QR poll lost response cookies');
$loginClient->nav();
biliCheck(
    str_contains($loginTransport->requests[2]['options']['headers']['Cookie'] ?? '', 'SESSDATA=session-token'),
    'Captured QR cookies were not sent to nav'
);
biliCheck($loginTransport->requests[0]['url'] === 'https://passport.bilibili.com/x/passport-login/web/qrcode/generate', 'QR generate endpoint is stale');
biliCheck(($loginTransport->requests[0]['options']['query']['source'] ?? '') === 'main-fe-header', 'QR generate source is stale');
biliCheck(($loginTransport->requests[0]['options']['query']['go_url'] ?? '') === 'https://www.bilibili.com/', 'QR generate go_url is missing');
biliCheck(($loginTransport->requests[0]['options']['query']['web_location'] ?? '') === '333.1007', 'QR generate web_location is missing');
biliCheck($loginTransport->requests[1]['method'] === 'GET', 'QR poll must use GET');
biliCheck(($loginTransport->requests[1]['options']['query']['qrcode_key'] ?? '') === 'qr-key', 'QR poll key is missing');
biliCheck(($loginTransport->requests[1]['options']['query']['source'] ?? '') === 'main-fe-header', 'QR poll source is stale');

$smsTransport = new BilibiliRecordingTransport([
    ['body' => '{"code":0,"message":"0","data":{"b_3":"sms-buvid3","b_4":"sms-buvid4"}}'],
    [
        'body' => '{"code":0,"data":{"captcha_key":"captcha-key"}}',
        'set_cookie' => ['b_lsid=sms-lsid; Path=/; Domain=.bilibili.com'],
    ],
]);
$smsClient = new Client([], [], $smsTransport);
$smsClient->smsSend('13800000000', [
    'token' => 'token',
    'challenge' => 'challenge',
    'validate' => 'validate',
    'seccode' => 'validate|jordan',
]);
$smsContext = $smsClient->smsLoginContext();
$smsVerifyTransport = new BilibiliRecordingTransport([[
    'body' => '{"code":0,"data":{"status":0,"cookie_info":{"cookies":[{"name":"DedeUserID","value":"77"},{"name":"DedeUserID__ckMd5","value":"sms-mid-md5"},{"name":"SESSDATA","value":"sms-session"},{"name":"bili_jct","value":"sms-csrf"}]},"url":"https://www.bilibili.com/?sid=sms-sid"}}',
]]);
$smsVerifyClient = new Client([], [], $smsVerifyTransport);
$smsVerifyClient->smsLogin('13800000000', '123456', 'captcha-key', 86, $smsContext);
$smsSendRequest = $smsTransport->requests[1];
$smsLoginRequest = $smsVerifyTransport->requests[0];
biliCheck($smsTransport->requests[0]['url'] === 'https://api.bilibili.com/x/frontend/finger/spi', 'SMS device fingerprint endpoint is missing');
biliCheck($smsSendRequest['url'] === 'https://passport.bilibili.com/x/passport-login/web/sms/send', 'SMS send endpoint is wrong');
biliCheck(($smsSendRequest['options']['form_params']['source'] ?? '') === 'main-fe-header', 'SMS send source is stale');
biliCheck(($smsSendRequest['options']['form_params']['cid'] ?? 0) === 86, 'SMS send cid must use the mainland international prefix');
biliCheck(str_contains($smsSendRequest['options']['headers']['Cookie'] ?? '', 'buvid3=sms-buvid3'), 'SMS send is missing the device cookie');
biliCheck(
    ($smsSendRequest['options']['headers']['User-Agent'] ?? '') === 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0',
    'SMS web User-Agent is stale'
);
biliCheck(($smsLoginRequest['options']['form_params']['source'] ?? '') === 'main-fe-header', 'SMS login did not retain the send source');
biliCheck(($smsLoginRequest['options']['form_params']['cid'] ?? 0) === 86, 'SMS login cid changed between requests');
biliCheck(($smsContext['protocol'] ?? '') === 'web', 'Web SMS context protocol is missing');
biliCheck(($smsContext['buvid3'] ?? '') === 'sms-buvid3', 'Web SMS context lost buvid3');
biliCheck(($smsContext['cookies']['b_lsid'] ?? '') === 'sms-lsid', 'Web SMS context lost response cookies');
biliCheck(str_contains($smsLoginRequest['options']['headers']['Cookie'] ?? '', 'buvid3=sms-buvid3'), 'SMS login lost the device cookie across requests');
biliCheck(str_contains($smsLoginRequest['options']['headers']['Cookie'] ?? '', 'b_lsid=sms-lsid'), 'SMS login lost send-response cookies across requests');
biliCheck($smsVerifyClient->cookies()['SESSDATA'] === 'sms-session', 'SMS login cookies were not captured');
biliCheck($smsVerifyClient->cookies()['sid'] === 'sms-sid', 'SMS login URL cookies were not captured');

$fallbackTransport = new BilibiliRecordingTransport([
    ['body' => '{"code":0,"message":"0","data":{"b_3":"fallback-buvid3","b_4":"fallback-buvid4"}}'],
    ['body' => '{"code":20000,"message":"版本过低，请升级客户端"}'],
    ['body' => '{"code":0,"message":"0","data":{"captcha_key":"app-captcha-key"}}'],
]);
$fallbackClient = new Client([], [], $fallbackTransport);
$fallbackSend = $fallbackClient->smsSend('13800000000', [
    'token' => 'token',
    'challenge' => 'challenge',
    'validate' => 'validate',
    'seccode' => 'validate|jordan',
]);
biliCheck(($fallbackSend['data']['captcha_key'] ?? '') === 'app-captcha-key', 'Version-upgrade response did not fall back to APP SMS');
$appContext = $fallbackClient->smsLoginContext();
biliCheck(($appContext['protocol'] ?? '') === 'app', 'APP SMS context protocol is missing');
biliCheck(preg_match('/^[0-9a-f]{32}$/', $appContext['login_session_id'] ?? '') === 1, 'APP SMS login_session_id is invalid');
biliCheck(($appContext['buvid'] ?? '') === ($appContext['local_id'] ?? ''), 'APP SMS local_id does not match buvid');
biliCheck(($appContext['android_version'] ?? '') === '9.5.0', 'APP SMS context lost the Android version');
biliCheck(($appContext['android_build'] ?? '') === '9050300', 'APP SMS context lost the Android build');
$fallbackVerifyTransport = new BilibiliRecordingTransport([
    ['body' => '{"code":0,"message":"0","data":{"token_info":{"access_token":"app-access","refresh_token":"app-refresh"},"cookie_info":{"cookies":[{"name":"DedeUserID","value":"88"},{"name":"DedeUserID__ckMd5","value":"app-mid-md5"},{"name":"SESSDATA","value":"app-session"},{"name":"bili_jct","value":"app-csrf"},{"name":"sid","value":"app-sid"}]}}}'],
]);
$fallbackVerifyClient = new Client([], [
    'android_version' => '1.0.0',
    'android_build' => '1000000',
], $fallbackVerifyTransport);
$fallbackLogin = $fallbackVerifyClient->smsLogin('13800000000', '123456', 'app-captcha-key', 86, $appContext);
biliCheck(($fallbackLogin['data']['token_info']['access_token'] ?? '') === 'app-access', 'APP SMS login response was not returned');

$appSendRequest = biliRequestByPath($fallbackTransport->requests, '/x/passport-login/sms/send');
$appLoginRequest = biliRequestByPath($fallbackVerifyTransport->requests, '/x/passport-login/login/sms');
$appSendParams = $appSendRequest['options']['form_params'];
$appLoginParams = $appLoginRequest['options']['form_params'];
biliCheck(($appSendParams['build'] ?? '') === '9050300', 'APP SMS send build is stale');
biliCheck(($appLoginParams['build'] ?? '') === '9050300', 'APP SMS login build is stale');
biliCheck(($appSendParams['cid'] ?? 0) === 86, 'APP SMS send cid is wrong');
biliCheck(($appLoginParams['login_session_id'] ?? '') === ($appSendParams['login_session_id'] ?? ''), 'APP SMS login_session_id changed between requests');
$statistics = json_decode((string)($appSendParams['statistics'] ?? ''), true);
biliCheck(($statistics['version'] ?? '') === '9.5.0', 'APP SMS statistics version is stale');
biliCheck(str_contains($appSendRequest['options']['headers']['User-Agent'] ?? '', 'BiliDroid/9.5.0'), 'APP SMS User-Agent version is stale');
biliCheck(str_contains($appSendRequest['options']['headers']['User-Agent'] ?? '', 'build/9050300'), 'APP SMS User-Agent build is stale');
$appSignature = (string)($appSendParams['sign'] ?? '');
unset($appSendParams['sign']);
ksort($appSendParams, SORT_STRING);
biliCheck(
    hash_equals($appSignature, md5(http_build_query($appSendParams, '', '&', PHP_QUERY_RFC3986) . '2653583c8873dea268ab9386918b1d65')),
    'APP SMS signature is invalid'
);
biliCheck($fallbackVerifyClient->cookies()['SESSDATA'] === 'app-session', 'APP SMS cookie_info was not captured');

$serviceTransport = new BilibiliRecordingTransport([
    ['body' => '{"code":0,"message":"0","data":{"token_info":{"access_token":"service-access","refresh_token":"service-refresh"},"cookie_info":{"cookies":[{"name":"DedeUserID","value":"99"},{"name":"DedeUserID__ckMd5","value":"service-mid-md5"},{"name":"SESSDATA","value":"service-session"},{"name":"bili_jct","value":"service-csrf"},{"name":"sid","value":"service-sid"}]}}}'],
    ['body' => '{"code":0,"message":"0","data":{"isLogin":true,"mid":99,"uname":"SMS Tester","face":"https://example.test/avatar.png"}}'],
]);
$serviceClient = new Client([], [], $serviceTransport);
$service = new BilibiliService(client: $serviceClient);
$serviceLogin = $service->smsLogin('13800000000', '123456', 'service-captcha', 86, [
    'protocol' => 'app',
    'login_session_id' => str_repeat('a', 32),
    'buvid' => 'XYservicebuvid',
    'local_id' => 'XYservicebuvid',
]);
biliCheck(($serviceLogin['code'] ?? 0) === 1, 'APP SMS service login failed');
biliCheck(($serviceLogin['data']['access_key'] ?? '') === 'service-access', 'APP access token was not persisted');
biliCheck(($serviceLogin['data']['refresh_token'] ?? '') === 'service-refresh', 'APP refresh token was not persisted');

$protocolTransport = new BilibiliRecordingTransport();
$protocolClient = new Client([
    'DedeUserID' => '42',
    'DedeUserID__ckMd5' => 'mid-md5',
    'SESSDATA' => 'session',
    'bili_jct' => 'csrf',
    'buvid3' => 'device-id',
    'buvid4' => 'device-id-4',
    'b_nut' => '1700000000',
    'bili_ticket' => 'web-ticket',
], [
    'access_key' => 'legacy-access',
    'wbi_keys' => [
        'img_key' => '7cd084941338484aae1ad9425b84077c',
        'sub_key' => '4932caff0ff746eab6f01bf08b70ac45',
    ],
], $protocolTransport);

$video = ['aid' => 170001, 'bvid' => 'BV17x411w7KC', 'cid' => 279786, 'duration' => 120];
$protocolClient->nav();
$protocolClient->dailyReward();
$protocolClient->todayCoinExp();
$protocolClient->popular();
$protocolClient->dynamicFeed();
$protocolClient->videoDetail(170001);
$protocolClient->startVideo($video);
$protocolClient->videoHeartbeat($video, 60);
$protocolClient->historyReport($video, 60);
$protocolClient->shareVideo(170001);
$protocolClient->coinVideo(170001);
$protocolClient->mangaClockIn();
$protocolClient->mangaShare();
$protocolClient->liveDailyBagPc();
$protocolClient->liveDailyBagApp();
$protocolClient->liveWebHeart(1);
$protocolClient->liveAppHeart(1);
$protocolClient->liveGroupList();
$protocolClient->liveGroupSign(['group_id' => 1, 'owner_uid' => 2]);
$protocolClient->liveGiftHeart(1);
$protocolClient->liveTaskInfo();
$protocolClient->liveSignInfo();
$protocolClient->liveSign();
$protocolClient->liveSilverToCoinApp();
$protocolClient->liveSilverToCoinPc();

$expectations = [
    '/x/web-interface/nav' => 'GET',
    '/x/member/web/exp/reward' => 'GET',
    '/x/web-interface/coin/today/exp' => 'GET',
    '/x/web-interface/popular' => 'GET',
    '/x/polymer/web-dynamic/v1/feed/all' => 'GET',
    '/x/web-interface/wbi/view/detail' => 'GET',
    '/x/click-interface/click/web/h5' => 'POST',
    '/x/click-interface/web/heartbeat' => 'POST',
    '/x/v2/history/report' => 'POST',
    '/x/web-interface/share/add' => 'POST',
    '/x/web-interface/coin/add' => 'POST',
    '/twirp/activity.v1.Activity/ClockIn' => 'POST',
    '/twirp/activity.v1.Activity/ShareComic' => 'POST',
    '/gift/v2/live/receive_daily_bag' => 'GET',
    '/AppBag/sendDaily' => 'GET',
    '/User/userOnlineHeart' => 'POST',
    '/mobile/userOnlineHeart' => 'POST',
    '/link_group/v1/member/my_groups' => 'GET',
    '/link_setting/v1/link_setting/sign_in' => 'GET',
    '/gift/v2/live/heart_gift_receive' => 'GET',
    '/i/api/taskInfo' => 'GET',
    '/xlive/web-ucenter/v1/sign/WebGetSignInfo' => 'GET',
    '/xlive/web-ucenter/v1/sign/DoSign' => 'GET',
    '/AppExchange/silver2coin' => 'POST',
    '/xlive/revenue/v1/wallet/silver2coin' => 'POST',
];
foreach ($expectations as $path => $method) {
    $request = biliRequestByPath($protocolTransport->requests, $path);
    biliCheck($request['method'] === $method, $path . ' uses the wrong HTTP method');
}

$detailRequest = biliRequestByPath($protocolTransport->requests, '/x/web-interface/wbi/view/detail');
biliCheck(isset($detailRequest['options']['query']['w_rid'], $detailRequest['options']['query']['wts']), 'WBI detail request is unsigned');
$historyRequest = biliRequestByPath($protocolTransport->requests, '/x/v2/history/report');
biliCheck(($historyRequest['options']['form_params']['csrf'] ?? '') === 'csrf', 'History report is missing CSRF');
$mangaRequest = biliRequestByPath($protocolTransport->requests, '/twirp/activity.v1.Activity/ClockIn');
biliCheck(($mangaRequest['options']['form_params']['platform'] ?? '') === 'android', 'Manga clock-in is missing platform=android');
$legacyRequest = biliRequestByPath($protocolTransport->requests, '/AppBag/sendDaily');
biliCheck(isset($legacyRequest['options']['query']['appkey'], $legacyRequest['options']['query']['sign']), 'Legacy live compatibility request is unsigned');
biliCheck(($legacyRequest['options']['query']['build'] ?? '') === '9050300', 'Legacy APP compatibility build is stale');
biliCheck(str_contains($legacyRequest['options']['headers']['User-Agent'] ?? '', 'BiliDroid/9.5.0'), 'Legacy APP compatibility User-Agent is stale');
$appHeartRequest = biliRequestByPath($protocolTransport->requests, '/mobile/userOnlineHeart');
biliCheck(($appHeartRequest['options']['form_params']['csrf'] ?? '') === 'csrf', 'Legacy APP heartbeat is missing csrf');
biliCheck(($appHeartRequest['options']['form_params']['csrf_token'] ?? '') === 'csrf', 'Legacy APP heartbeat is missing csrf_token');
$appSilverRequest = biliRequestByPath($protocolTransport->requests, '/AppExchange/silver2coin');
biliCheck(($appSilverRequest['options']['form_params']['csrf'] ?? '') === 'csrf', 'Legacy APP silver exchange is missing csrf');

$deviceTransport = new BilibiliRecordingTransport([
    [
        'body' => '<!doctype html><title>Bilibili</title>',
        'set_cookie' => [
            'buvid3=homepage-buvid3; Path=/; Domain=.bilibili.com',
            'b_nut=1700000000; Path=/; Domain=.bilibili.com',
        ],
    ],
    ['body' => '{"code":0,"message":"ok","data":{"b_3":"generated-buvid3","b_4":"generated-buvid4"}}'],
    ['body' => '{"code":0,"message":"OK","data":{"ticket":"generated-ticket","ttl":259200}}'],
    ['body' => '{"code":0,"message":"0","data":{"like":false}}'],
]);
$deviceClient = new Client([
    'DedeUserID' => '42',
    'SESSDATA' => 'session',
    'bili_jct' => 'csrf',
], [], $deviceTransport);
$deviceClient->coinVideo(170001);
$homeRequest = biliRequestByPath($deviceTransport->requests, 'www.bilibili.com/');
biliCheck($homeRequest['method'] === 'GET', 'Bilibili homepage cookie bootstrap must use GET');
$fingerRequest = biliRequestByPath($deviceTransport->requests, '/x/frontend/finger/spi');
biliCheck($fingerRequest['method'] === 'GET', 'Device fingerprint endpoint must use GET');
$ticketRequest = biliRequestByPath($deviceTransport->requests, '/bapis/bilibili.api.ticket.v1.Ticket/GenWebTicket');
biliCheck($ticketRequest['method'] === 'POST', 'Web ticket endpoint must use POST');
$deviceCoinRequest = biliRequestByPath($deviceTransport->requests, '/x/web-interface/coin/add');
biliCheck(
    str_contains($deviceCoinRequest['options']['headers']['Cookie'] ?? '', 'buvid3=homepage-buvid3'),
    'Coin request is missing the homepage buvid3 cookie'
);
biliCheck(
    str_contains($deviceCoinRequest['options']['headers']['Cookie'] ?? '', 'bili_ticket=generated-ticket'),
    'Coin request is missing the generated bili_ticket cookie'
);

echo "Bilibili SDK protocol tests passed\n";
