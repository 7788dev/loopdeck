<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use netease\Netease;
use netease\sdk\Client;
use netease\sdk\TransportInterface;

final class WorkflowTransport implements TransportInterface
{
    public array $requests = [];

    public function request(string $method, string $url, array $options = []): array
    {
        $this->requests[] = compact('method', 'url', 'options');
        $body = $this->bodyFor($url);
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
                'listenSongs' => 10,
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
    'musician_follows_id' => 2,
    'songid' => 101,
    'times' => 2,
], $sdk);

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
    'musician_task' => $netease->musician_task(),
    'listen' => $netease->listen(),
];

foreach ($results as $name => $result) {
    workflowCheck(is_array($result), $name . ' did not return an array');
    workflowCheck((int)($result['code'] ?? 0) === 200, $name . ' did not complete successfully');
}

$urls = array_column($transport->requests, 'url');
workflowCheck(
    count(array_filter($urls, static fn(string $url): bool => str_contains($url, '/xeapi/resource/comments/add'))) === 2,
    'Musician workflow did not use XEAPI comment creation'
);
workflowCheck(
    count(array_filter($urls, static fn(string $url): bool => str_contains($url, '/eapi/feedback/weblog'))) >= 2,
    'Listening workflows did not use EAPI weblog reporting'
);
workflowCheck(
    count(array_filter($urls, static fn(string $url): bool => str_contains($url, '127.0.0.1:3010'))) === 0,
    'A workflow still called the Node bridge'
);

echo "Netease project workflow tests passed\n";
