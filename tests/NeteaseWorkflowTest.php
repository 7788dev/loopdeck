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

    /**
     * @param array<int,array{id:int,sourceId:int,time:int}> $songs
     * @param array<int,int> $playlists
     * @param array<int,true> $exclude
     */
    public function appendPlaylistForTest(array &$songs, array $playlists, array $exclude, int $limit): void
    {
        $this->appendPlaylistSongs($songs, $playlists, $exclude, $limit);
    }

    public function playlist_detail($playlist_id)
    {
        $this->playlistDetailCalls++;
        $base = (int)$playlist_id * 1000;
        return [
            'code' => 200,
            'playlist' => [
                'tracks' => [
                    ['id' => $base + 1, 'dt' => 180000],
                    ['id' => $base + 2, 'dt' => 180000],
                    ['id' => $base + 3, 'dt' => 180000],
                ],
            ],
        ];
    }
}

final class DailyDakaProbe extends Netease
{
    public int $scrobbleCalls = 0;
    public int $listenSongs = 10;
    /** How many of a submitted batch NetEase actually counts. */
    public int $countedPerBatch = 0;
    /** @var array<int,array<int,int>> */
    public array $submittedBatches = [];

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

    protected function dakaCandidates(string $source, array $exclude, int $limit): array
    {
        $songs = [];
        $id = 7000;
        while (count($songs) < $limit && $id < 9000) {
            $id++;
            if (isset($exclude[$id])) {
                continue;
            }
            $songs[$id] = ['id' => $id, 'sourceId' => 10, 'time' => 180];
        }
        return $songs;
    }

    protected function weblogScrobbleBatch(array $songs): int
    {
        $this->scrobbleCalls++;
        $this->submittedBatches[] = array_values(array_map(
            static fn(array $song): int => (int)$song['id'],
            $songs
        ));
        $this->lastScrobbleStarts = count($songs);
        $this->lastScrobbleSeconds = count($songs) * 180;
        $this->lastScrobbleSongIds = array_values(array_map(
            static fn(array $song): int => (int)$song['id'],
            $songs
        ));
        $this->lastScrobbleElapsedSeconds = 0.25;
        $this->listenSongs += min(count($songs), $this->countedPerBatch);
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

$limitProbe = new DakaLimitProbe(1, 'csrf', 'music-u', ['daka_history_dir' => ''], $sdk);
$alreadyFull = [101 => ['id' => 101, 'sourceId' => 10, 'time' => 180]];
$limitProbe->appendPlaylistForTest($alreadyFull, [10], [], 1);
workflowCheck(count($alreadyFull) === 1, 'Playlist candidates exceeded an already reached daka limit');
workflowCheck($limitProbe->playlistDetailCalls === 0, 'Reached daka limit still fetched another playlist');

$oneSong = [];
$limitProbe->appendPlaylistForTest($oneSong, [11], [], 1);
workflowCheck(count($oneSong) === 1, 'Playlist candidates did not stop exactly at the daka limit');
workflowCheck($limitProbe->playlistDetailCalls === 1, 'Filling one song needed more than one playlist request');

$excludedSongs = [];
$limitProbe->appendPlaylistForTest($excludedSongs, [11], [11001 => true], 3);
workflowCheck(!isset($excludedSongs[11001]), 'A song already submitted today was offered again');
workflowCheck(count($excludedSongs) === 2, 'Excluding one track did not leave the rest of the playlist available');
workflowCheck($limitProbe->playlistDetailCalls === 1, 'The per-run playlist track cache was not reused');

/**
 * A day that only partially counts must keep topping up. This is the case the
 * old event-budget logic could not recover from: it charged the daily cap for
 * every event it sent, so a run that reported 300 but only gained 180 was
 * locked out of the remaining 120 for the rest of the day.
 */
$topUpDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'loopdeck-daka-topup-' . bin2hex(random_bytes(6));
workflowCheck(@mkdir($topUpDirectory, 0770, true), 'Daily daka test directory could not be created');
$topUpProbe = new DailyDakaProbe(1, 'csrf', 'music-u', [
    'daka_limit' => 10,
    'daka_history_dir' => $topUpDirectory,
], $sdk);
$topUpProbe->countedPerBatch = 6;

$firstRun = $topUpProbe->daka_new();
workflowCheck((int)($firstRun['code'] ?? 0) === 201, 'A partially counted first run was reported as complete');
workflowCheck((int)($firstRun['data']['submitted'] ?? -1) === 10, 'The first run did not submit the whole target');
workflowCheck((int)($firstRun['data']['listen_songs_delta'] ?? -1) === 6, 'The measured increase was not reported');
workflowCheck((int)($firstRun['data']['daily_actual_progress'] ?? -1) === 6, 'Progress was not based on listenSongs');
workflowCheck((int)($firstRun['data']['daily_remaining'] ?? -1) === 4, 'The shortfall was not carried forward');
workflowCheck((int)($firstRun['data']['retry_after_seconds'] ?? 0) > 0, 'A partially counted run did not schedule a top-up');

$secondRun = $topUpProbe->daka_new();
workflowCheck(
    (int)($secondRun['data']['submitted'] ?? -1) === 4,
    'The top-up run did not submit exactly the measured shortfall'
);
workflowCheck((int)($secondRun['code'] ?? 0) === 200, 'The top-up run did not complete the target');
workflowCheck(!empty($secondRun['data']['target_reached']), 'The completed target lost its completion flag');
workflowCheck((int)($secondRun['data']['daily_remaining'] ?? -1) === 0, 'The completed target still reported a shortfall');
workflowCheck($topUpProbe->scrobbleCalls === 2, 'The top-up did not reuse the reporting protocol exactly once more');
workflowCheck(
    array_intersect($topUpProbe->submittedBatches[0], $topUpProbe->submittedBatches[1]) === [],
    'The top-up batch repeated songs already submitted today'
);

$thirdRun = $topUpProbe->daka_new();
workflowCheck((int)($thirdRun['code'] ?? 0) === 200, 'A completed day was not reported as complete');
workflowCheck((int)($thirdRun['data']['submitted'] ?? -1) === 0, 'A completed day submitted more songs');
workflowCheck($topUpProbe->scrobbleCalls === 2, 'A completed day called the reporting protocol again');
foreach (glob($topUpDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $topUpFile) {
    @unlink($topUpFile);
}
@rmdir($topUpDirectory);

// When nothing counts at all the task must stop by itself instead of
// resubmitting for the rest of the day.
$idleDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'loopdeck-daka-idle-' . bin2hex(random_bytes(6));
workflowCheck(@mkdir($idleDirectory, 0770, true), 'Daily daka test directory could not be created');
$idleProbe = new DailyDakaProbe(1, 'csrf', 'music-u', [
    'daka_limit' => 5,
    'daka_history_dir' => $idleDirectory,
    'daka_max_verification_runs' => 3,
], $sdk);
$idleProbe->countedPerBatch = 0;

for ($run = 1; $run <= 3; $run++) {
    $idleResult = $idleProbe->daka_new();
    workflowCheck((int)($idleResult['data']['submitted'] ?? -1) === 5, 'An idle run stopped submitting too early');
    workflowCheck(
        (int)($idleResult['data']['stalled_runs'] ?? -1) === $run,
        'The stall counter did not advance once per unproductive run'
    );
    $expectedRetry = $run < 3;
    workflowCheck(
        ((int)($idleResult['data']['retry_after_seconds'] ?? 0) > 0) === $expectedRetry,
        'The idle run retry decision did not follow the stall limit'
    );
}
workflowCheck($idleProbe->scrobbleCalls === 3, 'The stall limit did not bound how often a dead day resubmits');
$idleStopped = $idleProbe->daka_new();
workflowCheck((int)($idleStopped['data']['submitted'] ?? -1) === 0, 'A stalled day submitted another batch');
workflowCheck(
    str_contains((string)($idleStopped['message'] ?? ''), '已停止自动重试'),
    'A stalled day did not report that it stopped'
);
foreach (glob($idleDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $idleFile) {
    @unlink($idleFile);
}
@rmdir($idleDirectory);

// The per-day batch cap remains an independent safety net.
$batchCapDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'loopdeck-daka-cap-' . bin2hex(random_bytes(6));
workflowCheck(@mkdir($batchCapDirectory, 0770, true), 'Daily daka cap test directory could not be created');
$batchCapProbe = new DailyDakaProbe(1, 'csrf', 'music-u', [
    'daka_limit' => 3,
    'daka_history_dir' => $batchCapDirectory,
    'daka_max_batches_per_day' => 3,
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
    'stalled_runs' => 0,
]));
$batchCapResult = $batchCapProbe->daka_new();
workflowCheck((int)($batchCapResult['code'] ?? 0) === 201, 'An unfinished day was reported as success');
workflowCheck((int)($batchCapResult['data']['submitted'] ?? -1) === 0, 'The batch cap submitted another batch');
workflowCheck((int)($batchCapResult['data']['retry_after_seconds'] ?? -1) === 0, 'The batch cap kept retrying');
workflowCheck($batchCapProbe->scrobbleCalls === 0, 'The batch cap called the reporting protocol');
workflowCheck(
    str_contains((string)($batchCapResult['message'] ?? ''), '批次上限'),
    'The batch cap did not explain why it stopped'
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
