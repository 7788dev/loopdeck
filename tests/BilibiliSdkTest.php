<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

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

$loginTransport = new BilibiliRecordingTransport([
    ['body' => '{"code":0,"data":{"url":"https://scan.test/","qrcode_key":"qr-key"}}'],
    [
        'body' => '{"code":0,"data":{"code":0,"message":"","refresh_token":"refresh"}}',
        'set_cookie' => [
            'DedeUserID=42; Path=/',
            'DedeUserID__ckMd5=mid-md5; Path=/',
            'SESSDATA=session-token; Path=/; HttpOnly',
            'bili_jct=csrf-token; Path=/',
            'sid=sid-token; Path=/',
        ],
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
biliCheck($loginTransport->requests[1]['method'] === 'GET', 'QR poll must use GET');
biliCheck(($loginTransport->requests[1]['options']['query']['qrcode_key'] ?? '') === 'qr-key', 'QR poll key is missing');

$smsTransport = new BilibiliRecordingTransport([
    ['body' => '{"code":0,"data":{"captcha_key":"captcha-key"}}'],
    [
        'body' => '{"code":0,"data":{"status":0}}',
        'set_cookie' => ['DedeUserID=77; Path=/', 'SESSDATA=sms-session; Path=/', 'bili_jct=sms-csrf; Path=/'],
    ],
]);
$smsClient = new Client([], [], $smsTransport);
$smsClient->smsSend('13800000000', [
    'token' => 'token',
    'challenge' => 'challenge',
    'validate' => 'validate',
    'seccode' => 'validate|jordan',
]);
$smsClient->smsLogin('13800000000', '123456', 'captcha-key');
$smsSendRequest = $smsTransport->requests[0];
biliCheck($smsSendRequest['url'] === 'https://passport.bilibili.com/x/passport-login/web/sms/send', 'SMS send endpoint is wrong');
biliCheck(($smsSendRequest['options']['form_params']['source'] ?? '') === 'main_web', 'SMS source is missing');
biliCheck($smsClient->cookies()['SESSDATA'] === 'sms-session', 'SMS login cookies were not captured');

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
