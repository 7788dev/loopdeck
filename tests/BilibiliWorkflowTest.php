<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use bilibili\BiliHelper;
use bilibili\sdk\Client;
use bilibili\sdk\TransportInterface;

final class BilibiliWorkflowTransport implements TransportInterface
{
    public array $requests = [];

    /** @var array<string,array<int,array<string,mixed>>> */
    private array $responses = [];

    public function __construct(array $responses)
    {
        foreach ($responses as $path => $queue) {
            $this->responses[$path] = isset($queue['code']) ? [$queue] : array_values($queue);
        }
    }

    public function request(string $method, string $url, array $options = []): array
    {
        $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        $this->requests[] = compact('method', 'url', 'path', 'options');
        $payload = ['code' => 0, 'message' => '0', 'data' => []];
        if (!empty($this->responses[$path])) {
            $payload = array_shift($this->responses[$path]);
        }
        return [
            'status' => 200,
            'headers' => [],
            'body' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            'header' => '',
            'set_cookie' => [],
        ];
    }

    public function called(string $path): bool
    {
        return $this->callCount($path) > 0;
    }

    public function callCount(string $path): int
    {
        $count = 0;
        foreach ($this->requests as $request) {
            if ($request['path'] === $path) {
                $count++;
            }
        }
        return $count;
    }
}

function biliWorkflowCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array{0:BiliHelper,1:BilibiliWorkflowTransport} */
function biliWorkflow(array $responses, array $config = []): array
{
    $transport = new BilibiliWorkflowTransport($responses);
    $client = new Client([
        'DedeUserID' => '42',
        'DedeUserID__ckMd5' => 'mid-md5',
        'SESSDATA' => 'session-token',
        'bili_jct' => 'csrf-token',
        'sid' => 'sid-token',
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
    ], $transport);
    $helper = new BiliHelper('42', 'mid-md5', 'session-token', 'csrf-token', 'legacy-access', $config, $client);
    return [$helper, $transport];
}

function biliNav(float $money = 10): array
{
    return [
        'code' => 0,
        'message' => '0',
        'data' => [
            'isLogin' => true,
            'mid' => 42,
            'uname' => 'SDK Tester',
            'money' => $money,
        ],
    ];
}

function biliVideo(int $aid, string $bvid, int $cid): array
{
    return [
        'code' => 0,
        'message' => '0',
        'data' => [
            'View' => [
                'aid' => $aid,
                'bvid' => $bvid,
                'cid' => $cid,
                'duration' => 120,
            ],
        ],
    ];
}

[$manga, $mangaTransport] = biliWorkflow([
    '/x/web-interface/nav' => [biliNav(), biliNav()],
    '/twirp/activity.v1.Activity/ClockIn' => [['code' => 0, 'msg' => '', 'data' => ['point' => 5]]],
    '/twirp/activity.v1.Activity/ShareComic' => [['code' => 0, 'msg' => '今日已分享']],
]);
$mangaResult = $manga->manga();
biliWorkflowCheck($mangaResult['code'] === 1, 'manga workflow failed');
biliWorkflowCheck($mangaTransport->called('/twirp/activity.v1.Activity/ClockIn'), 'manga clock-in did not use SDK');
biliWorkflowCheck($mangaTransport->called('/twirp/activity.v1.Activity/ShareComic'), 'manga share did not use SDK');

[$dailyBag, $dailyBagTransport] = biliWorkflow([
    '/x/web-interface/nav' => [biliNav(), biliNav()],
    '/AppBag/sendDaily' => [['code' => 0, 'message' => '0']],
    '/gift/v2/live/receive_daily_bag' => [['code' => 0, 'message' => '0']],
]);
biliWorkflowCheck($dailyBag->dailybag()['code'] === 1, 'dailybag workflow failed');
biliWorkflowCheck($dailyBagTransport->called('/AppBag/sendDaily'), 'dailybag APP request did not use SDK');
biliWorkflowCheck($dailyBagTransport->called('/gift/v2/live/receive_daily_bag'), 'dailybag PC request did not use SDK');

[$doubleHeart, $doubleHeartTransport] = biliWorkflow([
    '/x/web-interface/nav' => [biliNav(), biliNav()],
    '/User/userOnlineHeart' => [['code' => 0, 'message' => '0']],
    '/mobile/userOnlineHeart' => [['code' => 0, 'message' => '0']],
], ['global_room' => 123]);
biliWorkflowCheck($doubleHeart->doubleheart()['code'] === 1, 'doubleheart workflow failed');
biliWorkflowCheck($doubleHeartTransport->called('/User/userOnlineHeart'), 'web heart did not use SDK');
biliWorkflowCheck($doubleHeartTransport->called('/mobile/userOnlineHeart'), 'APP heart did not use SDK');

[$groupSign, $groupTransport] = biliWorkflow([
    '/x/web-interface/nav' => [biliNav()],
    '/link_group/v1/member/my_groups' => [[
        'code' => 0,
        'data' => ['list' => [['group_id' => 10, 'owner_uid' => 20, 'group_name' => '测试应援团']]],
    ]],
    '/link_setting/v1/link_setting/sign_in' => [[
        'code' => 0,
        'data' => ['status' => 0, 'add_num' => 5],
    ]],
]);
biliWorkflowCheck($groupSign->groupsignIn()['code'] === 1, 'groupsignIn workflow failed');
biliWorkflowCheck($groupTransport->called('/link_setting/v1/link_setting/sign_in'), 'group sign-in did not use SDK');

[$giftHeart, $giftHeartTransport] = biliWorkflow([
    '/x/web-interface/nav' => [biliNav()],
    '/gift/v2/live/heart_gift_receive' => [['code' => 0, 'data' => ['heart_status' => 1]]],
], ['global_room' => 123]);
biliWorkflowCheck($giftHeart->giftheart()['code'] === 1, 'giftheart workflow failed');
biliWorkflowCheck($giftHeartTransport->called('/gift/v2/live/heart_gift_receive'), 'giftheart did not use SDK');

[$dailyTask, $dailyTaskTransport] = biliWorkflow([]);
$dailyTaskResult = $dailyTask->dailytask();
biliWorkflowCheck($dailyTaskResult['code'] === 0, 'offline dailytask was reported as successful');
biliWorkflowCheck(str_contains($dailyTaskResult['message'], '已下线'), 'offline dailytask message is missing');
biliWorkflowCheck($dailyTaskTransport->requests === [], 'offline dailytask performed a network request');

[$silver, $silverTransport] = biliWorkflow([
    '/x/web-interface/nav' => [biliNav(), biliNav()],
    '/xlive/revenue/v1/wallet/silver2coin' => [['code' => 0, 'message' => '0']],
    '/AppExchange/silver2coin' => [['code' => 0, 'message' => '0']],
]);
biliWorkflowCheck($silver->silver2coin()['code'] === 1, 'silver2coin workflow failed');
biliWorkflowCheck($silverTransport->called('/xlive/revenue/v1/wallet/silver2coin'), 'PC silver2coin did not use SDK');
biliWorkflowCheck($silverTransport->called('/AppExchange/silver2coin'), 'APP silver2coin did not use SDK');

[$watch, $watchTransport] = biliWorkflow([
    '/x/web-interface/nav' => [biliNav()],
    '/x/member/web/exp/reward' => [['code' => 0, 'data' => ['watch' => false]]],
    '/x/web-interface/popular' => [['code' => 0, 'data' => ['list' => [['aid' => 170001, 'bvid' => 'BV17x411w7KC']]]]],
    '/x/web-interface/wbi/view/detail' => [biliVideo(170001, 'BV17x411w7KC', 279786)],
    '/x/click-interface/click/web/h5' => [['code' => 0, 'message' => '0']],
    '/x/click-interface/web/heartbeat' => [['code' => 0, 'message' => '0']],
    '/x/v2/history/report' => [['code' => 0, 'message' => '0']],
]);
biliWorkflowCheck($watch->watchAid()['code'] === 1, 'watchaid workflow failed');
biliWorkflowCheck($watchTransport->called('/x/click-interface/web/heartbeat'), 'watchaid heartbeat did not use SDK');
biliWorkflowCheck($watchTransport->called('/x/v2/history/report'), 'watchaid history did not use SDK');

[$share, $shareTransport] = biliWorkflow([
    '/x/web-interface/nav' => [biliNav()],
    '/x/member/web/exp/reward' => [['code' => 0, 'data' => ['share' => false]]],
    '/x/web-interface/popular' => [['code' => 0, 'data' => ['list' => [['aid' => 170001, 'bvid' => 'BV17x411w7KC']]]]],
    '/x/web-interface/wbi/view/detail' => [biliVideo(170001, 'BV17x411w7KC', 279786)],
    '/x/web-interface/share/add' => [['code' => 0, 'message' => '0']],
]);
biliWorkflowCheck($share->shareAid()['code'] === 1, 'shareaid workflow failed');
biliWorkflowCheck($shareTransport->called('/x/web-interface/share/add'), 'shareaid did not use SDK');

[$coin, $coinTransport] = biliWorkflow([
    '/x/web-interface/nav' => [biliNav(5)],
    '/x/web-interface/coin/today/exp' => [['code' => 0, 'data' => 0]],
    '/x/web-interface/popular' => [[
        'code' => 0,
        'data' => ['list' => [
            ['aid' => 170001, 'bvid' => 'BV17x411w7KC'],
            ['aid' => 170002, 'bvid' => 'BV1xx411c7mD'],
        ]],
    ]],
    '/x/web-interface/wbi/view/detail' => [
        biliVideo(170001, 'BV17x411w7KC', 279786),
        biliVideo(170002, 'BV1xx411c7mD', 279787),
    ],
    '/x/web-interface/coin/add' => [
        ['code' => 0, 'message' => '0'],
        ['code' => 0, 'message' => '0'],
    ],
], ['add_coin_num' => 2, 'add_coin_mode' => 'random']);
$coinResult = $coin->coinAdd();
biliWorkflowCheck($coinResult['code'] === 1, 'coinadd workflow failed');
biliWorkflowCheck($coinTransport->callCount('/x/web-interface/coin/add') === 2, 'coinadd did not submit two SDK requests');

[$coinDone, $coinDoneTransport] = biliWorkflow([
    '/x/web-interface/nav' => [biliNav(5)],
    '/x/web-interface/coin/today/exp' => [['code' => 0, 'data' => 10]],
], ['add_coin_num' => 1, 'add_coin_mode' => 'random']);
$coinDoneResult = $coinDone->coinAdd();
biliWorkflowCheck($coinDoneResult['code'] === 1, 'configured daily coin target was not treated as complete');
biliWorkflowCheck(str_contains($coinDoneResult['message'], '已达到配置数量'), 'configured daily coin target message is unclear');
biliWorkflowCheck(!$coinDoneTransport->called('/x/web-interface/coin/add'), 'coinadd repeated spending after reaching configured daily target');

[$globalRoom, $globalRoomTransport] = biliWorkflow([]);
biliWorkflowCheck($globalRoom->globalroom()['code'] === 1, 'globalroom workflow failed');
biliWorkflowCheck($globalRoomTransport->requests === [], 'globalroom unexpectedly performed a network request');

[$invalidAccount] = biliWorkflow([
    '/x/web-interface/nav' => [['code' => -101, 'message' => '账号未登录']],
]);
biliWorkflowCheck($invalidAccount->watchAid()['code'] === 0, 'explicit logout was not rejected');
biliWorkflowCheck($invalidAccount->cookiezt, 'explicit logout did not mark the account invalid');

[$temporaryFailure] = biliWorkflow([
    '/x/web-interface/nav' => [['code' => -1, 'message' => 'temporary network failure']],
]);
$temporaryResult = $temporaryFailure->watchAid();
biliWorkflowCheck($temporaryResult['code'] === 0, 'temporary nav failure unexpectedly succeeded');
biliWorkflowCheck(!$temporaryFailure->cookiezt, 'temporary nav failure marked the account invalid');
biliWorkflowCheck(str_contains($temporaryResult['message'], '状态校验失败'), 'temporary nav failure message is misleading');

echo "Bilibili workflow tests passed\n";
