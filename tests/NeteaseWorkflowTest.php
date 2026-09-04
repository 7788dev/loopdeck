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
        $this->appendPlaylistSongs($songs, $playlists, $exclude, [], $limit);
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
    public int $waitCalls = 0;

    protected function waitDakaSettlement(int $seconds): void
    {
        // Keep the workflow test deterministic and fast; production uses the
        // bounded sleep implemented by the base class.
        $this->waitCalls++;
    }

    // Offline probe: the play-record seed would hit the network.
    protected function seedDakaHistoryFromPlayRecord(): void
    {
    }
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
 * The default flow completes supplementation inside one daka_new() call. If
 * the first accepted batch only moves listenSongs part-way, the same call
 * submits exactly the measured shortfall using fresh IDs and returns one final
 * result for the scheduler to log.
 */
$topUpDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'loopdeck-daka-topup-' . bin2hex(random_bytes(6));
workflowCheck(@mkdir($topUpDirectory, 0770, true), 'Daily daka test directory could not be created');
$topUpProbe = new DailyDakaProbe(1, 'csrf', 'music-u', [
    'daka_limit' => 10,
    'daka_history_dir' => $topUpDirectory,
    'daka_internal_wait_seconds' => 0,
], $sdk);
$topUpProbe->countedPerBatch = 6;

$firstRun = $topUpProbe->daka_new();
workflowCheck((int)($firstRun['code'] ?? 0) === 200, 'The internal top-up did not complete the target');
workflowCheck((int)($firstRun['data']['submitted'] ?? -1) === 14, 'The internal top-up did not submit the measured shortfall');
workflowCheck((int)($firstRun['data']['listen_songs_delta'] ?? -1) === 10, 'The final increase was not reported');
workflowCheck((int)($firstRun['data']['daily_actual_progress'] ?? -1) === 10, 'Progress was not based on listenSongs');
workflowCheck((int)($firstRun['data']['daily_remaining'] ?? -1) === 0, 'The internal top-up left a shortfall');
workflowCheck((int)($firstRun['data']['retry_after_seconds'] ?? -1) === 0, 'A completed run scheduled another log-producing retry');
workflowCheck((int)($firstRun['data']['internal_batches'] ?? -1) === 2, 'The shortfall was not processed inside the same call');
workflowCheck($topUpProbe->scrobbleCalls === 2, 'The internal top-up did not call the reporting protocol exactly once more');
workflowCheck((int)($firstRun['data']['topups_used'] ?? -1) === 1, 'The internal top-up bookkeeping was not recorded');
workflowCheck(
    array_intersect($topUpProbe->submittedBatches[0], $topUpProbe->submittedBatches[1]) === [],
    'The internal top-up repeated songs already submitted today'
);

$closingRun = $topUpProbe->daka_new();
workflowCheck((int)($closingRun['data']['submitted'] ?? -1) === 0, 'A completed day submitted another batch');
workflowCheck($topUpProbe->scrobbleCalls === 2, 'A completed day called the reporting protocol again');
workflowCheck(!empty($closingRun['data']['target_reached']), 'A completed day was not marked as reached');
foreach (glob($topUpDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $topUpFile) {
    @unlink($topUpFile);
}
@rmdir($topUpDirectory);

// The legacy zero top-up setting must not reintroduce an external retry loop;
// supplementation remains internal and still produces one final result.
$verifyDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'loopdeck-daka-verify-' . bin2hex(random_bytes(6));
workflowCheck(@mkdir($verifyDirectory, 0770, true), 'Verification-only daka test directory could not be created');
$verifyProbe = new DailyDakaProbe(1, 'csrf', 'music-u', [
    'daka_limit' => 10,
    'daka_history_dir' => $verifyDirectory,
    'daka_topup_batches' => 0,
    'daka_internal_wait_seconds' => 0,
], $sdk);
$verifyProbe->countedPerBatch = 6;
$verifyFirst = $verifyProbe->daka_new();
workflowCheck((int)($verifyFirst['code'] ?? 0) === 200, 'The legacy zero-budget run did not complete internally');
workflowCheck((int)($verifyFirst['data']['submitted'] ?? -1) === 14, 'The legacy zero-budget run did not submit its shortfall');
$verifySecond = $verifyProbe->daka_new();
workflowCheck((int)($verifySecond['data']['submitted'] ?? -1) === 0, 'The zero-budget mode submitted a top-up');
workflowCheck(!empty($verifySecond['data']['target_reached']), 'The internally completed day was not marked as reached');
workflowCheck($verifyProbe->scrobbleCalls === 2, 'The zero-budget compatibility mode called the reporting protocol unexpectedly');
foreach (glob($verifyDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $verifyFile) {
    @unlink($verifyFile);
}
@rmdir($verifyDirectory);

// A state file written by the previous implementation may have exhausted its
// external top-up counter while listenSongs is still four short. The new
// single-run workflow must rescue that state instead of treating the legacy
// counter as a hard stop.
$rescueDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'loopdeck-daka-rescue-' . bin2hex(random_bytes(6));
workflowCheck(@mkdir($rescueDirectory, 0770, true), 'Legacy rescue test directory could not be created');
$rescueProbe = new DailyDakaProbe(1, 'csrf', 'music-u', [
    'daka_limit' => 300,
    'daka_history_dir' => $rescueDirectory,
    'daka_topup_batches' => 3,
    'daka_internal_wait_seconds' => 0,
], $sdk);
$rescueProbe->listenSongs = 7449;
$rescueProbe->countedPerBatch = 4;
$rescueStatePath = $rescueDirectory . DIRECTORY_SEPARATOR . hash('sha256', '1') . '.daily.json';
file_put_contents($rescueStatePath, json_encode([
    'date' => date('Y-m-d'),
    'target' => 300,
    'listen_songs_baseline' => 7153,
    'listen_songs_observed' => 7449,
    'actual_progress' => 296,
    'submitted_total' => 312,
    'startplay_accepted_total' => 312,
    'play_accepted_total' => 312,
    'reported_play_seconds' => 65443,
    'topups_used' => 3,
    'attempts' => 4,
    'stalled_runs' => 3,
    'verifications' => 1,
    'submitted_song_ids' => range(8000, 8311),
]));
$rescueResult = $rescueProbe->daka_new();
workflowCheck((int)($rescueResult['code'] ?? 0) === 200, 'The legacy 296/300 state was not rescued');
workflowCheck((int)($rescueResult['data']['submitted'] ?? -1) === 4, 'The legacy rescue did not submit the four-song shortfall');
workflowCheck((int)($rescueResult['data']['daily_actual_progress'] ?? -1) === 300, 'The legacy rescue did not reach 300/300');
workflowCheck((int)($rescueResult['data']['retry_after_seconds'] ?? -1) === 0, 'The legacy rescue scheduled an external retry');
workflowCheck($rescueProbe->scrobbleCalls === 1, 'The legacy rescue used more than one internal batch');
workflowCheck((int)($rescueResult['data']['topups_used'] ?? -1) === 4, 'The legacy top-up bookkeeping did not remain compatible');
foreach (glob($rescueDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $rescueFile) {
    @unlink($rescueFile);
}
@rmdir($rescueDirectory);

// The internal batch cap is the only retry budget now. A failed cap produces a
// final failure (retry_after_seconds=0) and seals the day.
$optInDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'loopdeck-daka-optin-' . bin2hex(random_bytes(6));
workflowCheck(@mkdir($optInDirectory, 0770, true), 'Opt-in daka test directory could not be created');
$optInProbe = new DailyDakaProbe(1, 'csrf', 'music-u', [
    'daka_limit' => 6,
    'daka_history_dir' => $optInDirectory,
    'daka_max_batches_per_day' => 2,
    'daka_internal_wait_seconds' => 0,
], $sdk);
$optInProbe->countedPerBatch = 2;

$optInFirst = $optInProbe->daka_new();
workflowCheck((int)($optInFirst['code'] ?? 0) === 201, 'The internal batch cap reported a false success');
workflowCheck((int)($optInFirst['data']['submitted'] ?? -1) === 10, 'The capped run did not use its two internal batches');
workflowCheck((int)($optInFirst['data']['daily_actual_progress'] ?? -1) === 4, 'The capped run lost counted progress');
workflowCheck((int)($optInFirst['data']['retry_after_seconds'] ?? -1) === 0, 'The capped run scheduled an external retry');
workflowCheck($optInProbe->scrobbleCalls === 2, 'The internal cap did not bound protocol calls');
workflowCheck(
    array_intersect($optInProbe->submittedBatches[0], $optInProbe->submittedBatches[1]) === [],
    'The capped internal batch repeated songs already submitted today'
);

$optInSecond = $optInProbe->daka_new();
workflowCheck((int)($optInSecond['data']['submitted'] ?? -1) === 0, 'A sealed failed day submitted another batch');
workflowCheck($optInProbe->scrobbleCalls === 2, 'A sealed failed day called the reporting protocol again');
foreach (glob($optInDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $optInFile) {
    @unlink($optInFile);
}
@rmdir($optInDirectory);

// When nothing counts at all the task must stop by itself instead of
// resubmitting for the rest of the day.
$idleDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'loopdeck-daka-idle-' . bin2hex(random_bytes(6));
workflowCheck(@mkdir($idleDirectory, 0770, true), 'Daily daka test directory could not be created');
$idleProbe = new DailyDakaProbe(1, 'csrf', 'music-u', [
    'daka_limit' => 5,
    'daka_history_dir' => $idleDirectory,
    'daka_max_verification_runs' => 3,
    'daka_topup_batches' => 0,
    'daka_internal_wait_seconds' => 0,
], $sdk);
$idleProbe->countedPerBatch = 0;

$idleFirst = $idleProbe->daka_new();
workflowCheck((int)($idleFirst['code'] ?? 0) === 201, 'A dead day was reported as complete');
workflowCheck((int)($idleFirst['data']['submitted'] ?? -1) === 9, 'The dead day did not use its bounded replacement cushion');
workflowCheck((int)($idleFirst['data']['retry_after_seconds'] ?? -1) === 0, 'A dead day scheduled an external retry');
workflowCheck((int)($idleFirst['data']['stalled_runs'] ?? -1) >= 1, 'The unproductive run did not count as stalled');
workflowCheck($idleProbe->scrobbleCalls === 2, 'The internal idle guard did not bound protocol calls');
$idleStopped = $idleProbe->daka_new();
workflowCheck((int)($idleStopped['data']['submitted'] ?? -1) === 0, 'A stalled day submitted another batch');
workflowCheck(
    str_contains((string)($idleStopped['message'] ?? ''), '本日任务已结束'),
    'A stalled day did not report that it was sealed'
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
    'sealed' => true,
]));
$batchCapResult = $batchCapProbe->daka_new();
workflowCheck((int)($batchCapResult['code'] ?? 0) === 201, 'An unfinished day was reported as success');
workflowCheck((int)($batchCapResult['data']['submitted'] ?? -1) === 0, 'The batch cap submitted another batch');
workflowCheck((int)($batchCapResult['data']['retry_after_seconds'] ?? -1) === 0, 'The batch cap kept retrying');
workflowCheck($batchCapProbe->scrobbleCalls === 0, 'The batch cap called the reporting protocol');
workflowCheck(
    str_contains((string)($batchCapResult['message'] ?? ''), '本日任务已结束'),
    'The sealed day did not explain why it stopped'
);
foreach (glob($batchCapDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $batchCapFile) {
    @unlink($batchCapFile);
}
@rmdir($batchCapDirectory);

// The same-day submission memory must never be trimmed: trimming would let
// the candidate pool offer an already-reported song again on the same day,
// which NetEase refuses to count a second time.
$memoryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'loopdeck-daka-memory-' . bin2hex(random_bytes(6));
workflowCheck(@mkdir($memoryDirectory, 0770, true), 'Daily daka memory test directory could not be created');
$memoryProbe = new DailyDakaProbe(1, 'csrf', 'music-u', [
    'daka_limit' => 3,
    'daka_history_dir' => $memoryDirectory,
    'daka_topup_batches' => 0,
    'daka_internal_wait_seconds' => 0,
], $sdk);
$memoryStatePath = $memoryDirectory . DIRECTORY_SEPARATOR . hash('sha256', '1') . '.daily.json';
$sameDayIds = range(5000, 6299);
file_put_contents($memoryStatePath, json_encode([
    'date' => date('Y-m-d'),
    'target' => 3,
    'listen_songs_baseline' => 10,
    'listen_songs_observed' => 11,
    'actual_progress' => 1,
    'submitted_total' => count($sameDayIds),
    'startplay_accepted_total' => count($sameDayIds),
    'play_accepted_total' => count($sameDayIds),
    'submitted_song_ids' => $sameDayIds,
    'attempts' => 1,
    'stalled_runs' => 0,
    'sealed' => true,
]));
$memoryResult = $memoryProbe->daka_new();
workflowCheck((int)($memoryResult['data']['submitted'] ?? -1) === 0, 'The verification pass submitted again');
$savedMemory = json_decode((string)file_get_contents($memoryStatePath), true);
workflowCheck(
    is_array($savedMemory['submitted_song_ids'] ?? null) && count($savedMemory['submitted_song_ids']) === count($sameDayIds),
    'The same-day submission memory was trimmed and could resubmit already-reported songs'
);
foreach (glob($memoryDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $memoryFile) {
    @unlink($memoryFile);
}
@rmdir($memoryDirectory);

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
    str_contains((string)($results['daka_new']['message'] ?? ''), '上报 1'),
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
        && str_contains($schedulerSource, "\$task === 'daka_new'")
        && str_contains($schedulerSource, 'isSingleRunNeteaseTask'),
    'The unified scheduler does not keep daily listening supplementation inside one run'
);

echo "Netease project workflow tests passed\n";
