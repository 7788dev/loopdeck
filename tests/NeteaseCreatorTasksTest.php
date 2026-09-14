<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use app\cron\controller\Common;
use netease\Netease;
use netease\sdk\Client;
use netease\sdk\Crypto;
use netease\sdk\TransportInterface;

function creatorCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class CreatorTaskTransport implements TransportInterface
{
    public array $requests = [];
    public array $unexpected = [];

    public function __construct(public array $responses)
    {
    }

    public function request(string $method, string $url, array $options = []): array
    {
        $path = (string)parse_url($url, PHP_URL_PATH);
        $data = [];
        if (str_starts_with($path, '/weapi/')) {
            parse_str((string)($options['body'] ?? ''), $form);
            creatorCheck(isset($form['params'], $form['encSecKey']), 'WEAPI form is not encrypted');
            creatorCheck(strlen($form['encSecKey']) === 256, 'WEAPI RSA key is missing');
            $inner = openssl_decrypt(base64_decode($form['params'], true), 'aes-128-cbc',
                '0123456789abcdef', OPENSSL_RAW_DATA, '0102030405060708');
            creatorCheck(is_string($inner), 'Cannot decrypt WEAPI outer layer');
            $plain = openssl_decrypt(base64_decode($inner, true), 'aes-128-cbc',
                '0CoJUm6Qyw8W8jud', OPENSSL_RAW_DATA, '0102030405060708');
            $data = json_decode((string)$plain, true, 512, JSON_THROW_ON_ERROR);
        } elseif (str_starts_with($path, '/eapi/')) {
            parse_str((string)($options['body'] ?? ''), $form);
            $data = (new Crypto())->decryptEapiRequest($form['params'])['data'];
        }
        $this->requests[] = compact('method', 'url', 'path', 'options', 'data');
        if (empty($this->responses[$path])) {
            $this->unexpected[] = $path;
            throw new RuntimeException('Unexpected request: ' . $path);
        }
        $response = array_shift($this->responses[$path]);
        $status = is_array($response) ? ($response['http_status'] ?? 200) : 200;
        if (is_array($response) && array_key_exists('http_status', $response)) {
            $response = $response['payload'];
        }
        $body = is_string($response) ? $response : json_encode($response, JSON_THROW_ON_ERROR);
        if (str_starts_with($path, '/xeapi/')) {
            $body = openssl_encrypt($body, 'aes-128-ecb', 'e82ckenh8dichen8', OPENSSL_RAW_DATA);
        }
        return ['status' => $status, 'headers' => [], 'header' => '', 'body' => $body, 'set_cookie' => []];
    }

    public function matching(string $path): array
    {
        return array_values(array_filter($this->requests,
            static fn(array $request): bool => $request['path'] === $path));
    }
}

final class CreatorTaskProbe extends Netease
{
    public int $waits = 0;
    public array $apiCalls = [];

    protected function waitPartnerEvaluation(): void
    {
        $this->waits++;
    }

    protected function requestApi(string $uri, array $data = [], string $crypto = 'eapi', array $options = []): array
    {
        $this->apiCalls[] = compact('uri', 'data', 'crypto', 'options');
        return parent::requestApi($uri, $data, $crypto, $options);
    }
}

$creatorTransports = [];

/** @return array{CreatorTaskProbe,CreatorTaskTransport} */
function creatorFixture(array $responses, array $config = []): array
{
    global $creatorTransports;
    $transport = new CreatorTaskTransport($responses);
    $creatorTransports[] = $transport;
    $peerSecret = str_repeat("\x11", 32);
    $sdk = new Client(['user_id' => 1, 'csrf' => 'fixture-csrf', 'music_u' => 'fixture-cookie'], [
        'auto_anonymous_token' => false,
        'cache_dir' => '',
        'xeapi_public_key' => [
            'publicKey' => base64_encode(sodium_crypto_scalarmult_base($peerSecret)),
            'version' => 'fixture-version',
            'sk' => 'fixture-key',
        ],
        'anti_cheat_token_v3' => 'fixture-token',
    ], $transport, new Crypto(null, static fn(): string => '0123456789abcdef'));
    return [new CreatorTaskProbe(1, 'fixture-csrf', 'fixture-cookie', $config, $sdk), $transport];
}

function creatorMusicianResponses(): array
{
    return [
        '/weapi/nmusician/workbench/mission/cycle/list' => [
            ['code' => 200, 'data' => ['list' => []]],
            ['code' => 200, 'data' => ['list' => []]],
        ],
        '/weapi/nmusician/workbench/mission/stage/list' => [
            ['code' => 200, 'data' => ['list' => []]],
            ['code' => 200, 'data' => ['list' => []]],
        ],
        '/weapi/nmusician/production/common/artist/album/item/list/get' => [
            ['code' => 200, 'data' => ['list' => [['id' => 20], ['id' => 21]]]],
        ],
        '/weapi/v1/album/20' => [['code' => 200, 'songs' => []]],
        '/weapi/v1/album/21' => [['code' => 200, 'songs' => [['id' => 101]]]],
        '/weapi/creator/user/access' => [['code' => 200]],
        '/weapi/nmusician/workbench/creator/watch/college/lesson' => [['code' => 200], ['code' => 200]],
        '/xeapi/share/friends/resource' => [['code' => 200, 'event' => ['id' => 22]]],
        '/weapi/event/delete' => [['code' => 200]],
        '/xeapi/resource/comments/add' => [
            ['code' => 200, 'comment' => ['commentId' => 31]],
            ['code' => 200, 'comment' => ['commentId' => 32]],
        ],
        '/xeapi/resource/comments/delete' => [['code' => 200], ['code' => 200]],
        '/eapi/msg/private/send' => [['code' => 200]],
        '/eapi/music/songshare/share/property' => [['code' => 200]],
    ];
}

$dailyPath = '/api/music/partner/daily/task/get';
$ratingPath = '/weapi/music/partner/work/evaluate';
$cyclePath = '/weapi/nmusician/workbench/mission/cycle/list';
$stagePath = '/weapi/nmusician/workbench/mission/stage/list';
$claimPath = '/weapi/nmusician/workbench/mission/reward/obtain/new';
$daily = ['code' => 200, 'data' => [
    'id' => 'daily-1', 'count' => 3, 'completedCount' => 1, 'completed' => 'false',
    'works' => [
        ['completed' => true, 'work' => ['id' => 41]],
        ['completed' => false, 'work' => ['id' => 42]],
        ['completed' => 'false', 'work' => ['id' => 43]],
    ],
]];
$complete = ['code' => 200, 'data' => ['id' => 'daily-1', 'count' => 3, 'completedCount' => 3]];
[$partner, $transport] = creatorFixture([
    $dailyPath => [$daily, $complete],
    $ratingPath => [['code' => 200], ['code' => '200']],
], ['evaluate_star' => '3']);
$result = $partner->evaluate();
creatorCheck($result['code'] === 200 && $result['data']['submitted'] === 2, 'Confirmed ratings must succeed');
creatorCheck($partner->waits === 2, 'Every new rating must respect the upstream interval');
creatorCheck(count($transport->matching($dailyPath)) === 2, 'Ratings must be verified with a fresh task read');
foreach ($transport->matching($dailyPath) as $request) {
    creatorCheck($request['method'] === 'GET'
        && $request['url'] === 'https://interface.music.163.com' . $dailyPath, 'Wrong daily-task protocol');
}
$ratings = $transport->matching($ratingPath);
creatorCheck(array_column(array_column($ratings, 'data'), 'workId') === [42, 43], 'Completed works were submitted again');
foreach ($ratings as $request) {
    creatorCheck($request['method'] === 'POST'
        && $request['url'] === 'https://interface.music.163.com' . $ratingPath, 'Wrong rating endpoint');
    creatorCheck(!isset($request['options']['form_params']), 'Ratings must not be submitted as plaintext forms');
    $payload = $request['data'];
    creatorCheck($payload['taskId'] === 'daily-1' && $payload['score'] === '3'
        && $payload['tags'] === '3-A-1', 'Task, score, or tag does not match upstream');
    creatorCheck($payload['customTags'] === '%5B%5D' && $payload['comment'] === ''
        && $payload['syncYunCircle'] === 'false', 'Missing rating fields or unexpected social sharing');
    creatorCheck($payload['csrf_token'] === 'fixture-csrf', 'CSRF must be inside the encrypted payload');
    creatorCheck(str_contains($request['options']['headers']['Cookie'], 'MUSIC_U=fixture-cookie'), 'Session was lost');
}

// A 200 response to submission does not prove that the task was credited.
[$partner, $transport] = creatorFixture([
    $dailyPath => [$daily, $daily], $ratingPath => [['code' => 200], ['code' => 200]],
]);
$pending = $partner->evaluate();
creatorCheck($pending['code'] === 201 && $pending['data']['submitted'] === 2, 'Uncredited ratings were reported as success');
creatorCheck((new Common())->statusTag($pending) === '重试中', 'Pending ratings must reach the scheduler as retrying');

foreach ([['code' => 500, 'message' => 'service unavailable'], ['code' => 405, 'message' => '资源状态异常']] as $failure) {
    [$partner] = creatorFixture([$dailyPath => [$daily, $daily], $ratingPath => [$failure, $failure]]);
    $result = $partner->evaluate();
    creatorCheck($result['code'] === 201 && $result['data']['submitted'] === 0, 'All failed ratings became a success');
    creatorCheck(str_contains($result['message'], $failure['message']), 'Rating failure reason was discarded');
    creatorCheck($result['data']['retry_after_seconds'] === ($failure['code'] === 500 ? 300 : 0),
        'Permanent rating failures must not be retried as transient failures');
}
[$partner, $transport] = creatorFixture([
    $dailyPath => [$daily, $daily],
    $ratingPath => [['code' => 200], ['code' => 429, 'msg' => '操作太频繁']],
]);
$partial = $partner->evaluate();
creatorCheck($partial['code'] === 201 && $partial['data']['submitted'] === 1, 'Partial rating failure was hidden');
creatorCheck(str_contains($partial['message'], '操作太频繁'), 'The msg error field was ignored');

[$partner, $transport] = creatorFixture([
    $dailyPath => [$daily, $daily], $ratingPath => [['code' => 301, 'message' => '需要登录']],
]);
creatorCheck($partner->evaluate()['code'] === 201, 'Expired session was reported as success');
creatorCheck(count($transport->matching($ratingPath)) === 1, 'Authentication failure must stop further submissions');

foreach ([['code' => 301, 'message' => '需要登录'], ['code' => 403, 'msg' => '尚未成为合伙人'],
    '<html>upstream unavailable</html>', ['code' => 200], ['code' => 200, 'data' => []],
    ['data' => ['completed' => true]],
    ['http_status' => 503, 'payload' => $complete],
    ['code' => 200, 'data' => ['id' => 'daily-1', 'works' => []]]] as $invalid) {
    [$partner, $transport] = creatorFixture([$dailyPath => [$invalid]]);
    creatorCheck($partner->evaluate()['code'] === 201, 'Invalid daily-task response was accepted');
    creatorCheck(count($transport->requests) === 1, 'Invalid task data triggered scoring');
}
[$partner, $transport] = creatorFixture([$dailyPath => [$complete]]);
creatorCheck($partner->evaluate()['code'] === 200 && count($transport->requests) === 1, 'Completed count was not recognized');
[$partner, $transport] = creatorFixture([$dailyPath => [['code' => 200, 'data' => ['completed' => true]]]]);
creatorCheck($partner->evaluate()['code'] === 200 && $partner->waits === 0, 'Completed daily task must remain idempotent');
$stale = $daily;
$stale['data']['completed'] = true;
[$partner, $transport] = creatorFixture([$dailyPath => [$stale, $complete], $ratingPath => [['code' => 200], ['code' => 200]]]);
creatorCheck($partner->evaluate()['data']['submitted'] === 2, 'A stale completion flag overrode unfinished counts');
$duplicate = $daily;
$duplicate['data']['works'][] = $duplicate['data']['works'][1];
[$partner, $transport] = creatorFixture([$dailyPath => [$duplicate, $complete], $ratingPath => [['code' => 200], ['code' => 200]]]);
creatorCheck($partner->evaluate()['code'] === 200 && count($transport->matching($ratingPath)) === 2,
    'Duplicate work entries triggered duplicate ratings');
foreach ([null, [], ['data' => ['id' => [], 'works' => $daily['data']['works']]],
    ['data' => ['id' => 0, 'works' => $daily['data']['works']]]] as $invalid) {
    [$partner, $transport] = creatorFixture([]);
    creatorCheck($partner->evaluate_Execute($invalid)['code'] === 201 && $transport->requests === [],
        'Invalid direct execution data triggered requests');
}
[$partner, $transport] = creatorFixture([
    $dailyPath => [$daily, $daily],
    $ratingPath => [['http_status' => 503, 'payload' => ['code' => 200]], ['http_status' => 503, 'payload' => ['code' => 200]]],
]);
creatorCheck($partner->evaluate()['data']['submitted'] === 0, 'HTTP failures were counted as successful ratings');

[$partner] = creatorFixture([
    $dailyPath => [$daily, ['code' => 502, 'message' => 'verification unavailable']],
    $ratingPath => [['code' => 200], ['code' => 200]],
]);
creatorCheck($partner->evaluate()['code'] === 201, 'Verification failure was reported as completion');
$newDay = $complete;
$newDay['data']['id'] = 'daily-2';
[$partner] = creatorFixture([$dailyPath => [$daily, $newDay], $ratingPath => [['code' => 200], ['code' => 200]]]);
creatorCheck($partner->evaluate()['code'] === 201, 'A different daily task was used to prove completion');

// Blank legacy configuration must fall back to an actual song, including a later album.
[$musician, $transport] = creatorFixture(creatorMusicianResponses(), [
    'musician_song_id' => '', 'musician_follows_id' => '2',
]);
$result = $musician->musician_task();
creatorCheck($result['code'] === 200, 'Music workbench eligibility or automatic song discovery failed');
creatorCheck(count($result['data']['steps']) === 7, 'A musician step was silently removed');
creatorCheck($result['data']['steps']['musician_finished_task']['code'] === 200, 'No pending rewards must be a successful no-op');
creatorCheck(count($transport->matching('/weapi/v1/album/21')) === 1, 'Song discovery stopped at an empty first album');
foreach ($musician->apiCalls as $request) {
    if ($request['uri'] === '/api/share/friends/resource') {
        creatorCheck($request['data']['id'] === '101' && $request['crypto'] === 'xeapi', 'Blank song ID did not fall back for shares');
    }
    if ($request['uri'] === '/api/resource/comments/add') {
        creatorCheck($request['data']['threadId'] === 'R_SO_4_101', 'Blank song ID did not fall back for comments');
    }
}
creatorCheck($transport->matching('/eapi/music/songshare/share/property')[0]['data']['songId'] === '101',
    'Blank song ID did not fall back for share reporting');
creatorCheck($transport->matching('/eapi/msg/private/send')[0]['data']['userIds'] === '[2]', 'Private-message recipient changed');
creatorCheck($transport->matching('/weapi/v1/user/detail/1') === [], 'Eligibility still depends on a localized profile badge');

[$musician, $transport] = creatorFixture(creatorMusicianResponses(), ['musician_song_id' => '102', 'musician_follows_id' => '2']);
creatorCheck($musician->musician_task()['code'] === 200, 'Configured song workflow failed');
creatorCheck($transport->matching('/weapi/nmusician/production/common/artist/album/item/list/get') === [],
    'An explicit song should not depend on album discovery');
creatorCheck($transport->matching('/eapi/music/songshare/share/property')[0]['data']['songId'] === '102',
    'Configured song was not respected');

foreach ([['code' => 403, 'message' => '尚未开通音乐人'], ['code' => 500, 'message' => 'service unavailable'],
    ['http_status' => 503, 'payload' => ['code' => 200, 'data' => ['list' => []]]],
    ['code' => 200, 'data' => null]] as $response) {
    [$musician, $transport] = creatorFixture([$cyclePath => [$response]]);
    creatorCheck($musician->musician_task()['code'] === 201, 'Failed musician eligibility check was ignored');
    creatorCheck(count($transport->requests) === 1, 'Failed workbench access triggered musician actions');
}

foreach ([
    '/weapi/creator/user/access' => 'musician_sign',
    '/weapi/nmusician/workbench/creator/watch/college/lesson' => 'watch_teaching_video',
    '/xeapi/share/friends/resource' => 'share_resource',
    '/eapi/msg/private/send' => 'musician_sendPrivateMsg',
    '/eapi/music/songshare/share/property' => 'shareyourself',
] as $path => $step) {
    $responses = creatorMusicianResponses();
    $responses[$path][0] = ['code' => 500, 'message' => 'fixture step failure'];
    [$musician] = creatorFixture($responses, ['musician_song_id' => '101', 'musician_follows_id' => '2']);
    $result = $musician->musician_task();
    creatorCheck($result['code'] === 201 && $result['data']['steps'][$step]['code'] === 201, $step . ' failure was hidden');
    creatorCheck((new Common())->statusTag($result) === '失败', 'Musician errors must reach the scheduler as failures');
    creatorCheck($result['data']['steps']['musician_finished_task']['code'] === 200, 'A failed step stopped reward processing');
}

[$musician, $transport] = creatorFixture(creatorMusicianResponses(), ['musician_song_id' => '101']);
$result = $musician->musician_task();
creatorCheck($result['code'] === 201 && $result['data']['steps']['musician_sendPrivateMsg']['code'] === 201,
    'Missing fan configuration was silently accepted');
creatorCheck($transport->matching('/eapi/msg/private/send') === [], 'A message was sent without a configured recipient');
$responses = creatorMusicianResponses();
$responses['/weapi/nmusician/production/common/artist/album/item/list/get'] = [['code' => 200, 'data' => ['list' => []]]];
[$musician, $transport] = creatorFixture($responses, ['musician_follows_id' => '2']);
$result = $musician->musician_task();
creatorCheck($result['code'] === 201 && $result['data']['steps']['shareyourself']['code'] === 201, 'Missing song was reported as success');
creatorCheck($transport->matching('/xeapi/resource/comments/add') === [], 'Missing song triggered a comment');

// Both comment submissions and the cleanup matter, including partial success.
$responses = creatorMusicianResponses();
$responses['/xeapi/resource/comments/add'][1] = ['code' => 500, 'message' => 'second comment failed'];
[$musician, $transport] = creatorFixture($responses, ['musician_song_id' => '101']);
creatorCheck($musician->musician_publishComment()['code'] === 201, 'One of two comments was reported as full success');
creatorCheck(count($transport->matching('/xeapi/resource/comments/delete')) === 1, 'The successful comment was not cleaned up');
$responses = creatorMusicianResponses();
$responses['/xeapi/resource/comments/delete'][0] = ['code' => 500, 'message' => 'cleanup failed'];
[$musician] = creatorFixture($responses, ['musician_song_id' => '101']);
creatorCheck($musician->musician_publishComment()['code'] === 201, 'Comment cleanup failure was hidden');
$responses = creatorMusicianResponses();
$responses['/weapi/event/delete'][0] = ['code' => 500, 'message' => 'cleanup failed'];
[$musician] = creatorFixture($responses, ['musician_song_id' => '101']);
creatorCheck($musician->share_resource()['code'] === 201, 'Share cleanup failure was hidden');

$rewards = [
    $cyclePath => [['code' => 200, 'data' => ['list' => [
        ['status' => '20', 'userMissionId' => '1001', 'period' => 1],
        ['status' => 100, 'userMissionId' => '1002', 'period' => 1],
    ]]]],
    $stagePath => [['code' => 200, 'data' => ['list' => [[
        'period' => 7, 'userStageTargetList' => [
            ['status' => '20', 'userMissionId' => '1003'],
            ['status' => 10, 'userMissionId' => '1004'],
        ],
    ], [
        'period' => 1, 'userStageTargetList' => [['status' => 20, 'userMissionId' => '1001']],
    ]]]]],
    $claimPath => [['code' => 200], ['code' => 200]],
];
[$musician, $transport] = creatorFixture($rewards);
$result = $musician->musician_finished_task();
creatorCheck($result['code'] === 200 && $result['data']['claimed'] === 2, 'Cycle/stage rewards or string status were not handled');
$claims = $transport->matching($claimPath);
creatorCheck(array_column(array_column($claims, 'data'), 'userMissionId') === ['1001', '1003'],
    'Claimed, unfinished, or duplicate rewards were sent');
creatorCheck(array_column(array_column($claims, 'data'), 'period') === [1, 7], 'Stage period was lost');
$rewards[$claimPath][1] = ['code' => 500, 'message' => 'claim unavailable'];
[$musician] = creatorFixture($rewards);
$result = $musician->musician_finished_task();
creatorCheck($result['code'] === 201 && $result['data']['claimed'] === 1, 'Partial reward failure was hidden');

foreach ([[$cyclePath, ['code' => 500]], [$stagePath, ['code' => 500]],
    [$stagePath, ['code' => 200, 'data' => ['list' => [['period' => 7]]]]]] as [$path, $failure]) {
    $responses = creatorMusicianResponses();
    $responses[$path][0] = $failure;
    [$musician] = creatorFixture($responses);
    creatorCheck($musician->musician_finished_task()['code'] === 201, 'Failed reward read became no pending rewards');
}
[$musician, $transport] = creatorFixture([]);
creatorCheck($musician->musician_cloudbean_obtain([['period' => 1]])['code'] === 201, 'Missing reward ID was accepted');
creatorCheck($musician->musician_cloudbean_obtain([['userMissionId' => '1001']])['code'] === 201, 'Missing reward period was accepted');
creatorCheck($musician->musician_cloudbean_obtain([])['code'] === 200, 'Empty rewards should be a no-op');
creatorCheck($transport->requests === [], 'Invalid rewards must not be submitted');
[$musician, $transport] = creatorFixture([
    $cyclePath => [['code' => 200, 'data' => ['list' => []]]],
    $stagePath => [['code' => 200, 'data' => ['list' => [[
        'period' => 7, 'userMissionId' => 'parent-id', 'userStageTargetList' => [['status' => 20]],
    ]]]]],
]);
creatorCheck($musician->musician_finished_task()['code'] === 201 && $transport->matching($claimPath) === [],
    'A missing stage reward ID was replaced with the parent mission ID');

foreach ($creatorTransports as $transport) {
    creatorCheck($transport->unexpected === [], 'Unmocked request: ' . implode(', ', $transport->unexpected));
    foreach ($transport->requests as $request) {
        creatorCheck(!str_contains($request['url'], 'fixture-csrf') && !str_contains($request['url'], 'fixture-cookie'),
            'Credentials leaked into a request URL');
    }
}

echo "Netease musician and partner task tests passed\n";
