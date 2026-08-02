<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use netease\Netease;
use netease\sdk\Client;
use netease\sdk\TransportInterface;

final class WorkflowTransport implements TransportInterface
{
    public array $requests = [];
    public int $listenSongs = 10;
    private int $weblogRequests = 0;

    public function request(string $method, string $url, array $options = []): array
    {
        $this->requests[] = compact('method', 'url', 'options');
        if (str_contains($url, '/feedback/weblog')) {
            $this->weblogRequests++;
            if ($this->weblogRequests % 2 === 0) {
                $this->listenSongs++;
            }
        }
        if (str_contains($url, 'clientlog3.music.163.com')) {
            preg_match('/filename="([^"]+)"/', (string)($options['body'] ?? ''), $match);
            $body = [
                'code' => 200,
                'data' => ['successfiles' => [(string)($match[1] ?? '')]],
            ];
        } else {
            $body = $this->bodyFor($url);
        }
        if (str_contains($url, '/xeapi/')) {
            $body = openssl_encrypt(
                json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'aes-128-ecb',
                'e82ckenh8dichen8',
                OPENSSL_RAW_DATA
            );
        } else {
            $body = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return [
            'status' => 200,
            'headers' => [],
            'body' => is_string($body) ? $body : '{}',
            'header' => '',
            'set_cookie' => [],
        ];
    }

    private function bodyFor(string $url): array
    {
        if (str_contains($url, '/w/nuser/account/get')) {
            return ['code' => 200, 'profile' => ['userId' => 1]];
        }
        if (str_contains($url, '/v1/user/detail/')) {
            return [
                'code' => 200,
                'listenSongs' => $this->listenSongs,
                'profile' => [
                    'userId' => 1,
                    'mainAuthType' => ['desc' => "\u{7F51}\u{6613}\u{97F3}\u{4E50}\u{4EBA}"],
                ],
            ];
        }
        if (str_contains($url, '/point/dailyTask')) {
            return ['code' => 200, 'message' => 'ok'];
        }
        if (str_contains($url, '/personalized/playlist')) {
            return ['code' => 200, 'result' => [['id' => 10]]];
        }
        if (str_contains($url, '/v6/playlist/detail')) {
            return ['code' => 200, 'playlist' => ['tracks' => [['id' => 101, 'dt' => 180000]]]];
        }
        if (str_contains($url, '/v3/song/detail')) {
            return ['code' => 200, 'songs' => [['id' => 101, 'dt' => 180000]]];
        }
        if (str_contains($url, '/feedback/weblog')) {
            return ['code' => 200];
        }
        if (str_contains($url, '/music/partner/daily/task/get')) {
            return ['code' => 200, 'data' => ['completed' => true]];
        }
        if (str_contains($url, '/music/partner/work/evaluate')) {
            return ['code' => 200];
        }
        if (str_contains($url, '/point/signed/get')) {
            return ['code' => 200, 'data' => ['signed' => true]];
        }
        if (str_contains($url, '/vipnewcenter/app/level/growhpoint/basic')) {
            return [
                'code' => 200,
                'data' => ['userLevel' => ['growthPoint' => 100, 'normal' => true]],
            ];
        }
        if (str_contains($url, '/vip-center-bff/task/sign')) {
            return ['code' => 200];
        }
        if (str_contains($url, '/vipnewcenter/app/level/user/checkin/history/detail')) {
            return ['code' => 200, 'data' => ['signed' => true]];
        }
        if (str_contains($url, '/vipnewcenter/app/minidesk/music/sign/pc')) {
            return ['code' => 200, 'data' => ['text' => '黑胶乐签']];
        }
        if (str_contains($url, '/vipnewcenter/app/user/sign/info')) {
            return ['code' => 200, 'data' => []];
        }
        if (str_contains($url, '/vipmusic/newrecord/weekflow')) {
            return ['code' => 200, 'data' => []];
        }
        if (str_contains($url, '/vipnewcenter/app/level/task/list')) {
            return [
                'code' => 200,
                'data' => [
                    'taskList' => [[
                        'taskItems' => [['unGetIds' => 'completed_1']],
                    ]],
                ],
            ];
        }
        if (str_contains($url, '/middle/vip/mission/user/progress/list')) {
            return [
                'code' => 200,
                'data' => [['historyUnObtainRewardWorth' => 3, 'children' => []]],
            ];
        }
        if (str_contains($url, '/vipnewcenter/app/level/task/reward/getall')) {
            return ['code' => 200, 'data' => ['received' => true]];
        }
        if (str_contains($url, '/vipnewcenter/app/level/task/reward/get')) {
            return ['code' => 200, 'data' => ['received' => true]];
        }
        if (str_contains($url, '/vipnewcenter/app/level/growth/details')) {
            return ['code' => 200, 'data' => []];
        }
        if (str_contains($url, '/yunbei/task/visit/mall')) {
            return ['code' => 200];
        }
        if (str_contains($url, '/vipnewcenter/app/level/task/external')) {
            return ['code' => 200, 'data' => ['code' => 200]];
        }
        if (str_contains($url, '/v3/discovery/recommend/songs')) {
            return ['code' => 200, 'data' => ['dailySongs' => [['id' => 101]]]];
        }
        if (str_contains($url, '/yunbei/rcmd/song/submit')) {
            return ['code' => 200];
        }
        if (str_contains($url, '/usertool/task/list/all')) {
            return ['code' => 200, 'data' => []];
        }
        if (str_contains($url, '/task/podcast/complete/report')) {
            return ['code' => 200];
        }
        if (str_contains($url, '/livestream/yunbeitask/finish')) {
            return ['code' => 200];
        }
        if (str_contains($url, '/usertool/task/todo/query')) {
            return ['code' => 200, 'data' => []];
        }
        if (str_contains($url, '/production/common/artist/album/item/list/get')) {
            return ['code' => 200, 'data' => ['list' => [['id' => 20]]]];
        }
        if (str_contains($url, '/v1/album/')) {
            return ['code' => 200, 'songs' => [['id' => 101]]];
        }
        if (str_contains($url, '/creator/user/access')) {
            return ['code' => 200];
        }
        if (str_contains($url, '/creator/watch/college/lesson')) {
            return ['code' => 200];
        }
        if (str_contains($url, '/share/friends/resource')) {
            return ['code' => 200];
        }
        if (str_contains($url, '/resource/comments/add')) {
            return ['code' => 200, 'comment' => ['commentId' => 30]];
        }
        if (str_contains($url, '/resource/comments/delete')) {
            return ['code' => 200];
        }
        if (str_contains($url, '/msg/private/send')) {
            return ['code' => 200];
        }
        if (str_contains($url, '/music/songshare/share/property')) {
            return ['code' => 200];
        }
        if (str_contains($url, '/mission/cycle/list')) {
            return ['code' => 200, 'data' => ['list' => []]];
        }
        if (str_contains($url, '/mission/stage/list')) {
            return ['code' => 200, 'data' => ['list' => []]];
        }
        return ['code' => 200];
    }
}

final class DakaLimitProbe extends Netease
{
    public int $playlistDetailCalls = 0;

    public function appendSearchForTest(array &$songs, array $candidates, array $history, int $limit): void
    {
        $this->appendSearchSongs($songs, $candidates, $history, $limit);
    }

    public function appendPlaylistForTest(array &$songs, array $playlists, array $history, int $limit): void
    {
        $this->appendPlaylistSongs($songs, $playlists, $history, $limit);
    }

    public function playlist_detail($playlist_id)
    {
        $this->playlistDetailCalls++;
        return [
            'code' => 200,
            'playlist' => [
                'tracks' => [
                    ['id' => 9000 + $this->playlistDetailCalls, 'dt' => 180000],
                ],
            ],
        ];
    }
}

final class DailyDakaProbe extends Netease
{
    public int $scrobbleCalls = 0;
    public int $listenSongs = 10;

    public function detail($uid)
    {
        return [
            'status' => 200,
            'headers' => [],
            'body' => json_encode([
                'code' => 200,
                'listenSongs' => $this->listenSongs,
            ], JSON_UNESCAPED_SLASHES),
            'header' => '',
            'set_cookie' => [],
        ];
    }

    protected function dakaSongs(string $source, array $history, int $limit = 300): array
    {
        return $this->probeSongs($limit);
    }

    protected function dakaSupplementSongs(array $history, int $limit = 300): array
    {
        return $this->probeSongs($limit);
    }

    private function probeSongs(int $limit): array
    {
        $songs = [];
        for ($index = 1; $index <= $limit; $index++) {
            $id = 7000 + ($this->scrobbleCalls * 100) + $index;
            $songs[$id] = [
                'id' => $id,
                'sourceId' => 10,
                'time' => 180,
            ];
        }
        return $songs;
    }

    protected function weblogScrobbleBatch(array $songs): int
    {
        $this->scrobbleCalls++;
        $this->lastScrobbleStarts = count($songs);
        $this->lastScrobbleSeconds = count($songs) * 180;
        $this->lastScrobbleSongIds = array_values(array_map(
            static fn(array $song): int => (int)$song['id'],
            $songs
        ));
        $this->lastScrobbleElapsedSeconds = 0.25;
        return count($songs);
    }
}

function workflowCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$peerSecret = random_bytes(SODIUM_CRYPTO_SCALARMULT_SCALARBYTES);
$transport = new WorkflowTransport();
$sdk = new Client([
    'user_id' => 1,
    'csrf' => 'csrf',
    'music_u' => 'music-u',
], [
    'auto_anonymous_token' => false,
    'cache_dir' => '',
    'xeapi_public_key' => [
        'publicKey' => base64_encode(sodium_crypto_scalarmult_base($peerSecret)),
        'version' => 'workflow-v1',
        'sk' => 'workflow-key',
    ],
    'anti_cheat_token_v3' => 'workflow-token',
], $transport);
$netease = new Netease(1, 'csrf', 'music-u', [
    'daka_music_from' => 'personalized',
    'daka_limit' => 1,
    'daka_history_dir' => '',
    'musician_follows_id' => 2,
    'songid' => 101,
    'times' => 2,
], $sdk);

$limitProbe = new DakaLimitProbe(1, 'csrf', 'music-u', [], $sdk);
$alreadyFull = [101 => ['id' => 101, 'sourceId' => 10, 'time' => 180]];
$limitProbe->appendSearchForTest(
    $alreadyFull,
    [['id' => 102, 'sourceId' => 10, 'time' => 180]],
    [],
    1
);
workflowCheck(count($alreadyFull) === 1, 'Search candidates exceeded an already reached daka limit');
$limitProbe->appendPlaylistForTest($alreadyFull, [10], [], 1);
workflowCheck(count($alreadyFull) === 1, 'Playlist candidates exceeded an already reached daka limit');
workflowCheck($limitProbe->playlistDetailCalls === 0, 'Reached daka limit still fetched another playlist');

$oneSong = [];
$limitProbe->appendSearchForTest(
    $oneSong,
    [
        ['id' => 102, 'sourceId' => 10, 'time' => 180],
        ['id' => 103, 'sourceId' => 10, 'time' => 180],
    ],
    [],
    1
);
workflowCheck(count($oneSong) === 1, 'Search candidates did not stop exactly at the daka limit');

$dailyDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'loopdeck-daka-daily-' . bin2hex(random_bytes(6));
workflowCheck(@mkdir($dailyDirectory, 0770, true), 'Daily daka test directory could not be created');
$dailyProbe = new DailyDakaProbe(1, 'csrf', 'music-u', [
    'daka_limit' => 3,
    'daka_history_dir' => $dailyDirectory,
], $sdk);
$legacyDailyState = $dailyDirectory . DIRECTORY_SEPARATOR . hash('sha256', '1') . '.daily.json';
file_put_contents($legacyDailyState, json_encode([
    'date' => date('Y-m-d'),
    'target' => 3,
    'submitted' => 3,
    'plv_confirmed' => 3,
    'pld_confirmed' => 3,
    'listen_songs_before' => 10,
    'listen_songs_after' => 10,
]));
$dailySupplement = $dailyProbe->daka_new();
workflowCheck((int)($dailySupplement['code'] ?? 0) === 201, 'Unconfirmed daily progress was reported as complete');
workflowCheck((int)($dailySupplement['data']['submitted'] ?? 0) === 3, 'Accepted legacy uploads incorrectly completed the daily target');
workflowCheck((int)($dailySupplement['data']['daily_actual_progress'] ?? -1) === 0, 'Daily progress was not based on listenSongs');
workflowCheck((int)($dailySupplement['data']['retry_after_seconds'] ?? 0) > 0, 'Incomplete daily progress did not request a retry');
workflowCheck($dailyProbe->scrobbleCalls === 1, 'Incomplete daily progress did not submit a supplement batch');
workflowCheck(
    str_contains((string)($dailySupplement['message'] ?? ''), '未等待歌曲播放'),
    'Daily daka did not explain that reporting is immediate'
);

$dailyProbe->listenSongs = 13;
$dailyComplete = $dailyProbe->daka_new();
workflowCheck((int)($dailyComplete['code'] ?? 0) === 200, 'Actual listenSongs progress did not complete the target');
workflowCheck(!empty($dailyComplete['data']['target_reached']), 'Completed daily target lost its completion flag');
workflowCheck((int)($dailyComplete['data']['submitted'] ?? -1) === 0, 'Completed actual progress still submitted songs');
workflowCheck($dailyProbe->scrobbleCalls === 1, 'Completed actual progress called the reporting protocol again');
workflowCheck(
    str_contains((string)($dailyComplete['message'] ?? ''), '实际新增3/3首'),
    'Completed daily daka did not report the actual listenSongs increase'
);
foreach (glob($dailyDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $dailyFile) {
    @unlink($dailyFile);
}
@rmdir($dailyDirectory);

$batchCapDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'loopdeck-daka-cap-' . bin2hex(random_bytes(6));
workflowCheck(@mkdir($batchCapDirectory, 0770, true), 'Daily daka cap test directory could not be created');
$batchCapProbe = new DailyDakaProbe(1, 'csrf', 'music-u', [
    'daka_limit' => 3,
    'daka_history_dir' => $batchCapDirectory,
], $sdk);
$batchCapState = $batchCapDirectory . DIRECTORY_SEPARATOR . hash('sha256', '1') . '.daily.json';
file_put_contents($batchCapState, json_encode([
    'date' => date('Y-m-d'),
    'target' => 3,
    'listen_songs_baseline' => 10,
    'listen_songs_observed' => 10,
    'actual_progress' => 0,
    'submitted_total' => 9,
    'startplay_accepted_total' => 9,
    'play_accepted_total' => 9,
    'attempts' => 3,
]));
$batchCapResult = $batchCapProbe->daka_new();
workflowCheck((int)($batchCapResult['code'] ?? 0) === 201, 'Daily batch cap was reported as success');
workflowCheck((int)($batchCapResult['data']['submitted'] ?? -1) === 0, 'Daily batch cap submitted another batch');
workflowCheck((int)($batchCapResult['data']['retry_after_seconds'] ?? -1) === 0, 'Daily batch cap scheduled another retry');
workflowCheck($batchCapProbe->scrobbleCalls === 0, 'Daily batch cap called the reporting protocol');
workflowCheck(
    str_contains((string)($batchCapResult['message'] ?? ''), '批次上限3次'),
    'Daily batch cap did not report the three-batch limit'
);
foreach (glob($batchCapDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $batchCapFile) {
    @unlink($batchCapFile);
}
@rmdir($batchCapDirectory);

$results = [
    'login_work' => $netease->login_work(),
    'sign' => $netease->sign(),
    'daka_new' => $netease->daka_new(),
    'evaluate' => $netease->evaluate(),
    'evaluate_execute' => $netease->evaluate_Execute([
        'data' => [
            'id' => 'task',
            'works' => [['completed' => false, 'work' => ['id' => 'work']]],
        ],
    ]),
    'yunbei_task' => $netease->yunbei_task(),
    'vip_growth_task' => $netease->vip_growth_task(),
    'vip_growthpoint_details' => $netease->vip_growthpoint_details(10, 5),
    'vip_sign_history' => $netease->vip_sign_history(1),
    'vip_sign_info' => $netease->vip_sign_info(),
    'musician_task' => $netease->musician_task(),
    'listen' => $netease->listen(),
];

foreach ($results as $name => $result) {
    workflowCheck(is_array($result), $name . ' did not return an array');
    workflowCheck((int)($result['code'] ?? 0) === 200, $name . ' did not complete successfully');
}
workflowCheck(
    str_contains((string)($results['daka_new']['message'] ?? ''), '本批即时上报1首')
        && str_contains((string)($results['daka_new']['message'] ?? ''), '未等待歌曲播放'),
    'Daily 300-song workflow did not use immediate api-enhanced reporting'
);

$urls = array_column($transport->requests, 'url');
workflowCheck(
    count(array_filter($urls, static fn(string $url): bool => str_contains($url, '/xeapi/resource/comments/add'))) === 2,
    'Musician workflow did not use XEAPI comment creation'
);
workflowCheck(
    count(array_filter($urls, static fn(string $url): bool => str_contains($url, '/xeapi/share/friends/resource'))) === 1,
    'Musician share task did not use the current XEAPI v3 endpoint'
);
$shareRequests = array_values(array_filter(
    $transport->requests,
    static fn(array $request): bool => str_contains($request['url'], '/xeapi/share/friends/resource')
));
workflowCheck(
    !empty($shareRequests[0]['options']['headers']['X-antiCheatToken']),
    'Musician share task did not attach the v3 anti-cheat token'
);
workflowCheck(
    count(array_filter($urls, static fn(string $url): bool => str_contains($url, '/eapi/feedback/weblog'))) >= 2,
    'Daily listening workflow did not use the countable EAPI weblog protocol'
);
workflowCheck(
    count(array_filter($urls, static fn(string $url): bool => str_contains($url, 'clientlog3.music.163.com/api/clientlog/encrypt/upload'))) >= 2,
    'Single-song listening workflow did not retain NCBL client-log reporting'
);
workflowCheck(
    count(array_filter($urls, static fn(string $url): bool => str_contains($url, '/weapi/vip-center-bff/task/sign'))) === 1,
    'Black Vinyl LeQian did not use the upstream WEAPI sign endpoint'
);
workflowCheck(
    count(array_filter($urls, static fn(string $url): bool => str_contains($url, '/eapi/vipnewcenter/app/level/user/checkin/history/detail'))) === 1,
    'Black Vinyl LeQian did not verify the EAPI check-in detail'
);
workflowCheck(
    count(array_filter($urls, static fn(string $url): bool => str_contains($url, '/xeapi/middle/vip/mission/user/progress/list'))) >= 1,
    'VIP growth task did not query the v1 XEAPI task list'
);
workflowCheck(
    count(array_filter($urls, static fn(string $url): bool => str_contains($url, '/xeapi/vipnewcenter/app/level/task/reward/getall'))) === 1,
    'VIP growth task did not use the XEAPI reward claim endpoint'
);
workflowCheck(
    count(array_filter($urls, static fn(string $url): bool => str_contains($url, '/weapi/vipnewcenter/app/level/task/reward/get'))) === 1,
    'VIP growth task did not claim legacy task rewards'
);
workflowCheck(
    count(array_filter($urls, static fn(string $url): bool => str_contains($url, '127.0.0.1:3010'))) === 0,
    'A workflow still called the Node bridge'
);

$schedulerSource = file_get_contents(dirname(__DIR__) . '/app/cron/controller/Task.php');
$taskModelSource = file_get_contents(dirname(__DIR__) . '/app/index/model/Tasks.php');
$installSql = file_get_contents(dirname(__DIR__) . '/app/install/install.sql');
workflowCheck(str_contains($schedulerSource, "'vip_growth_task'"), 'Unified scheduler is missing the VIP growth task');
workflowCheck(str_contains($taskModelSource, "'execute_name' => 'vip_growth_task'"), 'Existing installs cannot sync the VIP growth task');
workflowCheck(str_contains($installSql, "'vip_growth_task'"), 'Fresh installs are missing the VIP growth task');
workflowCheck(
    str_contains($schedulerSource, "'retry_after_seconds'")
        && str_contains($schedulerSource, '$nextExecute = time() +'),
    'Incomplete daily listening progress is not rescheduled for verification and supplementation'
);

echo "Netease project workflow tests passed\n";
