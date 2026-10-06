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
    '/gift/v2/live/receive_daily_bag' => [['code' => 0, 'data' => ['bag_list' => [['gift_id' => 1, 'gift_num' => 1]]]]],
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
    '/gift/v2/live/heart_gift_receive' => [['code' => 0, 'data' => ['heart_status' => 1, 'gift_list' => [['gift_id' => 1, 'gift_num' => 1]]]]],
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
    '/x/member/web/exp/reward' => [
        ['code' => 0, 'data' => ['watch' => false]],
        ['code' => 0, 'data' => ['watch' => true]],
    ],
    '/x/web-interface/popular' => [['code' => 0, 'data' => ['list' => [['aid' => 170001, 'bvid' => 'BV17x411w7KC']]]]],
    '/x/web-interface/wbi/view/detail' => [biliVideo(170001, 'BV17x411w7KC', 279786)],
    '/x/click-interface/click/web/h5' => [['code' => 0, 'message' => '0']],
    '/x/click-interface/web/heartbeat' => [['code' => 0, 'message' => '0']],
    '/x/v2/history/report' => [['code' => 0, 'message' => '0']],
]);
biliWorkflowCheck($watch->watchAid()['code'] === 1, 'watchaid workflow failed');
biliWorkflowCheck($watchTransport->called('/x/click-interface/web/heartbeat'), 'watchaid heartbeat did not use SDK');
biliWorkflowCheck($watchTransport->called('/x/v2/history/report'), 'watchaid history did not use SDK');

[$share, $shareTransport] = biliWorkflow([]);
$shareResult = $share->shareAid();
biliWorkflowCheck($shareResult['code'] === 0, 'retired share workflow was reported as successful');
biliWorkflowCheck(str_contains($shareResult['message'], '已下架'), 'retired share message is unclear');
biliWorkflowCheck(!$shareTransport->called('/x/web-interface/share/add'), 'retired share workflow performed a network request');

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
biliWorkflowCheck(str_contains($coinResult['message'], '硬币余额 3'), 'coinadd reported the coin balance from before spending');

// Videos coined on earlier days answer 34005; they must be skipped in favour
// of spare candidates instead of ending the run with 0 coins reported as success.
[$coinCapped, $coinCappedTransport] = biliWorkflow([
    '/x/web-interface/nav' => [biliNav(10)],
    '/x/web-interface/coin/today/exp' => [['code' => 0, 'data' => 0]],
    '/x/web-interface/popular' => [[
        'code' => 0,
        'data' => ['list' => [
            ['aid' => 170001], ['aid' => 170002], ['aid' => 170003], ['aid' => 170004],
        ]],
    ]],
    '/x/web-interface/coin/add' => [
        ['code' => 34005, 'message' => '超过投币上限啦~'],
        ['code' => 34005, 'message' => '超过投币上限啦~'],
        ['code' => 0, 'message' => '0'],
        ['code' => 0, 'message' => '0'],
    ],
], ['add_coin_num' => 2, 'add_coin_mode' => 'random']);
$coinCappedResult = $coinCapped->coinAdd();
biliWorkflowCheck($coinCappedResult['code'] === 1, 'coinadd stopped at a video that reached its coin cap');
biliWorkflowCheck($coinCappedTransport->callCount('/x/web-interface/coin/add') === 4, 'coinadd did not move on to spare candidates after 34005');
biliWorkflowCheck(str_contains($coinCappedResult['message'], '已投币 2 枚'), 'coinadd did not report coins spent on spare candidates');

[$coinNone, $coinNoneTransport] = biliWorkflow([
    '/x/web-interface/nav' => [biliNav(10)],
    '/x/web-interface/coin/today/exp' => [['code' => 0, 'data' => 0]],
    '/x/web-interface/popular' => [[
        'code' => 0,
        'data' => ['list' => [['aid' => 170001], ['aid' => 170002]]],
    ]],
    '/x/web-interface/coin/add' => [
        ['code' => 34005, 'message' => '超过投币上限啦~'],
        ['code' => 34005, 'message' => '超过投币上限啦~'],
    ],
], ['add_coin_num' => 3, 'add_coin_mode' => 'random']);
$coinNoneResult = $coinNone->coinAdd();
biliWorkflowCheck($coinNoneResult['code'] === 0, 'coinadd reported success without spending a single coin');
biliWorkflowCheck(str_contains($coinNoneResult['message'], '投币失败'), 'zero-coin coinadd message does not say it failed');
biliWorkflowCheck(!str_contains($coinNoneResult['message'], '0/3'), 'zero-coin coinadd still reports a 0/N progress line');

[$coinBroke, $coinBrokeTransport] = biliWorkflow([
    '/x/web-interface/nav' => [biliNav(10)],
    '/x/web-interface/coin/today/exp' => [['code' => 0, 'data' => 0]],
    '/x/web-interface/popular' => [[
        'code' => 0,
        'data' => ['list' => [['aid' => 170001], ['aid' => 170002], ['aid' => 170003]]],
    ]],
    '/x/web-interface/coin/add' => [['code' => -104, 'message' => '硬币不足']],
], ['add_coin_num' => 2, 'add_coin_mode' => 'random']);
$coinBrokeResult = $coinBroke->coinAdd();
biliWorkflowCheck($coinBrokeResult['code'] === 0, 'coinadd with insufficient coins was reported as successful');
biliWorkflowCheck(str_contains($coinBrokeResult['message'], '硬币不足'), 'coinadd failure did not surface the upstream reason');
biliWorkflowCheck($coinBrokeTransport->callCount('/x/web-interface/coin/add') === 1, 'coinadd kept spending after running out of coins');

[$coinGone, $coinGoneTransport] = biliWorkflow([
    '/x/web-interface/nav' => [biliNav(10)],
    '/x/web-interface/coin/today/exp' => [['code' => 0, 'data' => 0]],
    '/x/web-interface/popular' => [[
        'code' => 0,
        'data' => ['list' => [['aid' => 170001], ['aid' => 170002], ['aid' => 170003]]],
    ]],
    '/x/web-interface/coin/add' => [
        ['code' => 10003, 'message' => '不存在该稿件'],
        ['code' => 0, 'message' => '0'],
    ],
], ['add_coin_num' => 1, 'add_coin_mode' => 'random']);
$coinGoneResult = $coinGone->coinAdd();
biliWorkflowCheck($coinGoneResult['code'] === 1, 'coinadd gave up after a per-video error');
biliWorkflowCheck($coinGoneTransport->callCount('/x/web-interface/coin/add') === 2, 'coinadd did not retry the next candidate after a per-video error');

[$coinLoggedOut, $coinLoggedOutTransport] = biliWorkflow([
    '/x/web-interface/nav' => [biliNav(10)],
    '/x/web-interface/coin/today/exp' => [['code' => 0, 'data' => 0]],
    '/x/web-interface/popular' => [[
        'code' => 0,
        'data' => ['list' => [['aid' => 170001], ['aid' => 170002], ['aid' => 170003]]],
    ]],
    '/x/web-interface/coin/add' => [['code' => -101, 'message' => '账号未登录']],
], ['add_coin_num' => 3, 'add_coin_mode' => 'random']);
$coinLoggedOutResult = $coinLoggedOut->coinAdd();
biliWorkflowCheck($coinLoggedOutResult['code'] === 0, 'coinadd with a logged-out session was reported as successful');
biliWorkflowCheck($coinLoggedOutTransport->callCount('/x/web-interface/coin/add') === 1, 'coinadd kept trying candidates after an account-level error');

$cappedList = [];
for ($aid = 180001; $aid <= 180012; $aid++) {
    $cappedList[] = ['aid' => $aid];
}
[$coinAllCapped, $coinAllCappedTransport] = biliWorkflow([
    '/x/web-interface/nav' => [biliNav(10)],
    '/x/web-interface/coin/today/exp' => [['code' => 0, 'data' => 0]],
    '/x/web-interface/popular' => [['code' => 0, 'data' => ['list' => $cappedList]]],
    '/x/web-interface/coin/add' => array_fill(0, 12, ['code' => 34005, 'message' => '超过投币上限啦~']),
], ['add_coin_num' => 1, 'add_coin_mode' => 'random']);
$coinAllCappedResult = $coinAllCapped->coinAdd();
biliWorkflowCheck($coinAllCappedResult['code'] === 0, 'coinadd with every candidate capped was reported as successful');
biliWorkflowCheck($coinAllCappedTransport->callCount('/x/web-interface/coin/add') === 10, 'coinadd did not bound its coin requests');

[$coinDone, $coinDoneTransport] = biliWorkflow([
    '/x/web-interface/nav' => [biliNav(5)],
    '/x/web-interface/coin/today/exp' => [['code' => 0, 'data' => 10]],
], ['add_coin_num' => 1, 'add_coin_mode' => 'random']);
$coinDoneResult = $coinDone->coinAdd();
biliWorkflowCheck($coinDoneResult['code'] === 1, 'configured daily coin target was not treated as complete');
biliWorkflowCheck(str_contains($coinDoneResult['message'], '今日投币已完成'), 'configured daily coin target message is unclear');
biliWorkflowCheck(!$coinDoneTransport->called('/x/web-interface/coin/add'), 'coinadd repeated spending after reaching configured daily target');

[$dailyExperience, $dailyExperienceTransport] = biliWorkflow([
    '/x/web-interface/nav' => [biliNav()],
    '/x/member/web/exp/reward' => [
        ['code' => 0, 'data' => ['login' => true, 'watch' => true, 'share' => false, 'coins' => 20]],
        ['code' => 0, 'data' => ['login' => true, 'watch' => true, 'share' => false, 'coins' => 20]],
    ],
    '/x/web-interface/coin/today/exp' => [['code' => 0, 'data' => 20]],
    '/x/member/web/exp/log' => [[
        'code' => 0,
        'data' => ['list' => [['delta' => 15, 'time' => date('Y-m-d') . ' 08:00:00']]],
    ]],
]);
$dailyExperienceResult = $dailyExperience->dailyexperience();
biliWorkflowCheck($dailyExperienceResult['code'] === 1, 'daily experience workflow failed');
biliWorkflowCheck(str_contains($dailyExperienceResult['message'], '投币经验 20/50'), 'daily experience did not report coin experience');
biliWorkflowCheck(!str_contains($dailyExperienceResult['message'], '分享已下架'), 'daily experience still reports the permanently retired share task');
biliWorkflowCheck(!$dailyExperienceTransport->called('/x/web-interface/share/add'), 'daily experience attempted the retired share request');
biliWorkflowCheck(!$dailyExperienceTransport->called('/x/member/web/exp/log'), 'daily experience read the delayed ledger as a current total');
biliWorkflowCheck(str_contains($dailyExperienceResult['message'], '已确认基础经验+30（不含大会员）'), 'confirmed daily experience total is incorrect');

[$nonVipExperience, $nonVipExperienceTransport] = biliWorkflow([
    '/x/web-interface/nav' => [biliNav()],
    '/x/vip/privilege/my' => [['code' => 0, 'data' => ['vip_status' => 0, 'is_vip' => false, 'list' => []]]],
]);
$nonVipExperienceResult = $nonVipExperience->vipexperience();
biliWorkflowCheck($nonVipExperienceResult['code'] === 1, 'non-VIP account was treated as a task failure');
biliWorkflowCheck(str_contains($nonVipExperienceResult['message'], '已跳过'), 'non-VIP skip message is unclear');
biliWorkflowCheck(!$nonVipExperienceTransport->called('/x/vip/experience/add'), 'non-VIP account attempted to claim VIP experience');

[$vipExperience, $vipExperienceTransport] = biliWorkflow([
    '/x/web-interface/nav' => [biliNav()],
    '/x/vip/privilege/my' => [[
        'code' => 0,
        'data' => ['vip_status' => 1, 'is_vip' => true, 'list' => [['type' => 9, 'state' => 0]]],
    ]],
    '/x/vip/experience/add' => [['code' => 0, 'data' => ['is_grant' => true]]],
]);
$vipExperienceResult = $vipExperience->vipexperience();
biliWorkflowCheck($vipExperienceResult['code'] === 1, 'VIP experience claim workflow failed');
biliWorkflowCheck($vipExperienceTransport->called('/x/vip/experience/add'), 'VIP experience claim endpoint was not used');

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

// An accepted heartbeat is not proof of a settled watch reward.
$pendingWatchResponses = [
    '/x/web-interface/nav' => [biliNav()],
    '/x/member/web/exp/reward' => [
        ['code' => 0, 'data' => ['watch' => false]],
        ['code' => 0, 'data' => ['watch' => false]],
    ],
    '/x/web-interface/popular' => [['code' => 0, 'data' => ['list' => [['aid' => 170001, 'bvid' => 'BV17x411w7KC']]]]],
    '/x/web-interface/wbi/view/detail' => [biliVideo(170001, 'BV17x411w7KC', 279786)],
];
[$pendingWatch, $pendingTransport] = biliWorkflow($pendingWatchResponses);
$pending = $pendingWatch->watchAid();
biliWorkflowCheck($pending['code'] === 0 && !empty($pending['pending_verification']), 'Unsettled watching was reported as complete');
[$heartbeatFailure] = biliWorkflow(array_replace($pendingWatchResponses, [
    '/x/click-interface/web/heartbeat' => [['code' => -403, 'message' => '上报被拒绝']],
    '/x/v2/history/report' => [['code' => 0]],
]));
$heartbeatFailureResult = $heartbeatFailure->watchAid();
biliWorkflowCheck($heartbeatFailureResult['code'] === 0 && str_contains($heartbeatFailureResult['message'], '上报被拒绝'),
    'A successful history update masked a failed heartbeat');
[$verifyWatch, $verifyTransport] = biliWorkflow($pendingWatchResponses, ['verification_only' => true]);
biliWorkflowCheck(!empty($verifyWatch->watchAid()['pending_verification']), 'Read-only watching verification lost its pending state');
biliWorkflowCheck(!$verifyTransport->called('/x/web-interface/popular') && !$verifyTransport->called('/x/click-interface/web/heartbeat'),
    'Watching verification submitted another video');

foreach ([['login' => false, 'watch' => false], ['login' => false, 'watch' => true]] as $state) {
    [$unsettled, $unsettledTransport] = biliWorkflow([
        '/x/web-interface/nav' => [biliNav()],
        '/x/member/web/exp/reward' => array_fill(0, 2, ['code' => 0, 'data' => $state]),
        '/x/web-interface/coin/today/exp' => [['code' => 0, 'data' => 30]],
    ], ['verification_only' => true]);
    $result = $unsettled->dailyexperience();
    biliWorkflowCheck($result['code'] === 0 && !empty($result['pending_verification']), 'Incomplete daily rewards were reported as success');
    biliWorkflowCheck(!str_contains($result['message'], '今日经验+0'), 'A delayed ledger was reported as zero total experience');
    biliWorkflowCheck(!$unsettledTransport->called('/x/click-interface/web/heartbeat'), 'Daily verification repeated watching');
}
[$stateReadFailure] = biliWorkflow([
    '/x/web-interface/nav' => [biliNav()],
    '/x/member/web/exp/reward' => [
        ['code' => 0, 'data' => ['login' => true, 'watch' => true]],
        ['code' => -1, 'message' => 'timeout'],
    ],
]);
biliWorkflowCheck($stateReadFailure->dailyexperience()['code'] === 0, 'Failed verification fell back to an old successful state');
[$coinReadFailure, $coinReadTransport] = biliWorkflow([
    '/x/web-interface/nav' => [biliNav()],
    '/x/web-interface/coin/today/exp' => [['code' => -1, 'message' => 'timeout']],
], ['add_coin_num' => 3]);
biliWorkflowCheck($coinReadFailure->coinAdd()['code'] === 0, 'Unknown coin experience was treated as zero');
biliWorkflowCheck(!$coinReadTransport->called('/x/web-interface/coin/add'), 'Unknown coin experience caused extra spending');
[$summaryCoinFailure] = biliWorkflow([
    '/x/web-interface/nav' => [biliNav()],
    '/x/member/web/exp/reward' => array_fill(0, 2, ['code' => 0, 'data' => ['login' => true, 'watch' => true]]),
    '/x/web-interface/coin/today/exp' => [['code' => -1, 'message' => 'timeout']],
]);
$failedSummary = $summaryCoinFailure->dailyexperience();
biliWorkflowCheck($failedSummary['code'] === 0 && !str_contains($failedSummary['message'], '基础经验+'), 'Failed coin query produced a partial total as success');

[$emptyBag] = biliWorkflow([
    '/x/web-interface/nav' => [biliNav(), biliNav()],
    '/AppBag/sendDaily' => [['code' => 0, 'data' => ['result' => 0]]],
    '/gift/v2/live/receive_daily_bag' => [['code' => 0, 'data' => ['bag_status' => 0, 'bag_list' => []]]],
]);
$emptyBagResult = $emptyBag->dailybag();
biliWorkflowCheck($emptyBagResult['code'] === 0 && !str_contains($emptyBagResult['message'], '已领取'), 'Empty bags were reported as received');
[$emptyGift] = biliWorkflow([
    '/x/web-interface/nav' => [biliNav()],
    '/gift/v2/live/heart_gift_receive' => [['code' => 0, 'data' => ['heart_status' => 1, 'gift_list' => []]]],
]);
biliWorkflowCheck($emptyGift->giftheart()['code'] === 0, 'A waiting gift heartbeat was reported as a received gift');
[$emptyGroups] = biliWorkflow([
    '/x/web-interface/nav' => [biliNav()],
    '/link_group/v1/member/my_groups' => [['code' => 0, 'data' => []]],
]);
biliWorkflowCheck(!str_contains($emptyGroups->groupsignIn()['message'], '均已签到'), 'No groups were reported as already signed');
[$emptySilver] = biliWorkflow([
    '/x/web-interface/nav' => [biliNav(), biliNav()],
    '/xlive/revenue/v1/wallet/silver2coin' => [['code' => 403, 'message' => '银瓜子余额不足']],
    '/AppExchange/silver2coin' => [['code' => 403, 'message' => '银瓜子余额不足']],
]);
biliWorkflowCheck(str_contains($emptySilver->silver2coin()['message'], '银瓜子余额不足'), 'No-balance explanation was lost while composing results');
[$notGrantedVip] = biliWorkflow([
    '/x/web-interface/nav' => [biliNav()],
    '/x/vip/privilege/my' => [['code' => 0, 'data' => ['is_vip' => true, 'list' => [['type' => 9, 'state' => 0]]]]],
    '/x/vip/experience/add' => [['code' => 0, 'data' => ['is_grant' => false]]],
]);
biliWorkflowCheck($notGrantedVip->vipexperience()['code'] === 0, 'Ungrantable VIP experience was reported as received');

// A zero coin balance is not a completed day: no coin may be spent, so the run
// must reach the scheduler as a failure instead of a [成功] line.
[$noBalance, $noBalanceTransport] = biliWorkflow([
    '/x/web-interface/nav' => [biliNav(0)],
    '/x/web-interface/coin/today/exp' => [['code' => 0, 'data' => 0]],
], ['add_coin_num' => 3, 'add_coin_mode' => 'random']);
$noBalanceResult = $noBalance->coinAdd();
biliWorkflowCheck($noBalanceResult['code'] === 0, 'coinadd with an empty coin wallet was reported as successful');
biliWorkflowCheck(str_contains($noBalanceResult['message'], '硬币余额不足'), 'empty-wallet coinadd did not state the reason');
biliWorkflowCheck($noBalanceTransport->callCount('/x/web-interface/coin/add') === 0, 'coinadd issued requests without any coins');
$cronStatus = new app\cron\controller\Common();
biliWorkflowCheck($cronStatus->statusTag($noBalanceResult) === '失败',
    'The scheduler still rendered an empty-wallet coinadd as [成功]');

// The daily experience ceiling is settled by the already-completed branch; the
// wallet and the ceiling must not need a second, unreachable success line.
[$expFull, $expFullTransport] = biliWorkflow([
    '/x/web-interface/nav' => [biliNav(5)],
    '/x/web-interface/coin/today/exp' => [['code' => 0, 'data' => 50]],
], ['add_coin_num' => 5, 'add_coin_mode' => 'random']);
$expFullResult = $expFull->coinAdd();
biliWorkflowCheck($expFullResult['code'] === 1 && str_contains($expFullResult['message'], '今日投币已完成'),
    'a full coin-experience day was not settled by the completion branch');
biliWorkflowCheck(!str_contains($expFullResult['message'], '未投币'), 'coinadd reintroduced a no-op success line');

// A verification pass that already submitted the claim may only read the ledger.
[$vipWaiting, $vipWaitingTransport] = biliWorkflow([
    '/x/web-interface/nav' => [biliNav(), biliNav()],
    '/x/vip/privilege/my' => [
        ['code' => 0, 'data' => ['is_vip' => true, 'list' => [['type' => 9, 'state' => 0]]]],
        ['code' => 0, 'data' => ['is_vip' => true, 'list' => [['type' => 9, 'state' => 1]]]],
    ],
], ['verification_only' => true, 'claim_submitted' => true]);
$vipPending = $vipWaiting->vipexperience();
biliWorkflowCheck(!$vipWaitingTransport->called('/x/vip/experience/add'),
    'A VIP verification retry submitted the claim again');
biliWorkflowCheck($vipPending['code'] === 0 && !empty($vipPending['pending_verification'])
    && !empty($vipPending['claim_submitted']),
    'An unsettled VIP claim lost its pending state or its submitted stage');
$vipConfirmed = $vipWaiting->vipexperience();
biliWorkflowCheck($vipConfirmed['code'] === 1 && str_contains((string)$vipConfirmed['message'], '今日已领取')
    && !$vipWaitingTransport->called('/x/vip/experience/add'),
    'A confirmed VIP benefit was not recognised during verification');

// A prerequisite that only settled later must still get its first claim.
[$vipDelayed, $vipDelayedTransport] = biliWorkflow([
    '/x/web-interface/nav' => [biliNav()],
    '/x/vip/privilege/my' => [['code' => 0, 'data' => ['is_vip' => true, 'list' => [['type' => 9, 'state' => 0]]]]],
    '/x/vip/experience/add' => [['code' => 0, 'data' => ['is_grant' => true]]],
], ['verification_only' => true]);
$vipClaim = $vipDelayed->vipexperience();
biliWorkflowCheck($vipClaim['code'] === 1 && str_contains((string)$vipClaim['message'], '已领取'),
    'A VIP benefit stayed unclaimed after its prerequisite settled');
biliWorkflowCheck($vipDelayedTransport->callCount('/x/vip/experience/add') === 1,
    'The first VIP claim was not submitted exactly once');

// While the prerequisite watch is unsettled nothing may be claimed.
[$vipPrerequisite, $vipPrerequisiteTransport] = biliWorkflow([
    '/x/web-interface/nav' => [biliNav(), biliNav()],
    '/x/vip/privilege/my' => [['code' => 0, 'data' => ['is_vip' => true, 'list' => [['type' => 9, 'state' => 2]]]]],
    '/x/member/web/exp/reward' => [
        ['code' => 0, 'data' => ['watch' => false, 'login' => true]],
        ['code' => 0, 'data' => ['watch' => false, 'login' => true]],
    ],
], ['verification_only' => true]);
$vipBlocked = $vipPrerequisite->vipexperience();
biliWorkflowCheck(!$vipPrerequisiteTransport->called('/x/vip/experience/add'),
    'A VIP claim was submitted before its prerequisite watch settled');
biliWorkflowCheck($vipBlocked['code'] === 0 && str_contains((string)$vipBlocked['message'], '大会员前置观看'),
    'The VIP prerequisite wait was not explained');

echo "Bilibili workflow tests passed\n";
