<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use netease\Netease;
use netease\sdk\Client;
use netease\sdk\TransportInterface;

final class InspectableNetease extends Netease
{
    public function scrobbleStarts(): int
    {
        return (int)$this->lastScrobbleStarts;
    }

    public function scrobbleSeconds(): int
    {
        return (int)$this->lastScrobbleSeconds;
    }

    public function scrobbleSongIds(): array
    {
        return $this->lastScrobbleSongIds;
    }
}

final class NeteaseListenRecordingTransport implements TransportInterface
{
    public array $requests = [];
    private array $responses;

    public function __construct(array $responses)
    {
        $this->responses = array_values($responses);
    }

    public function request(string $method, string $url, array $options = []): array
    {
        $this->requests[] = compact('method', 'url', 'options');
        $response = array_replace([
            'status' => 200,
            'headers' => [],
            'body' => '{"code":200}',
            'header' => '',
            'set_cookie' => [],
        ], array_shift($this->responses) ?? []);
        if (str_contains($url, 'clientlog3.music.163.com')) {
            $decoded = json_decode((string)$response['body'], true);
            if ((int)($decoded['code'] ?? 0) === 200
                && !array_key_exists('data', is_array($decoded) ? $decoded : [])) {
                preg_match('/filename="([^"]+)"/', (string)($options['body'] ?? ''), $match);
                $response['body'] = json_encode([
                    'code' => 200,
                    'data' => ['successfiles' => [(string)($match[1] ?? '')]],
                ], JSON_UNESCAPED_SLASHES);
            }
        }
        return $response;
    }
}

function listenToolCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$transport = new NeteaseListenRecordingTransport([
    ['body' => '{"code":200,"songs":[{"id":347230,"dt":180000}]}'],
    ['body' => '{"code":200}'],
    ['body' => '{"code":200}'],
    ['body' => '{"code":200}'],
    ['body' => '{"code":200}'],
]);
$sdk = new Client([
    'user_id' => '1',
    'csrf' => 'csrf-token',
    'music_u' => 'music-token',
], [
    'auto_anonymous_token' => false,
    'cache_dir' => '',
], $transport);
$netease = new InspectableNetease('1', 'csrf-token', 'music-token', [
    'songid' => '347230',
    'times' => 2,
], $sdk);
$result = $netease->listen();
listenToolCheck((int)($result['code'] ?? 0) === 200, 'Listening tool did not report success');
listenToolCheck(str_contains((string)$result['message'], 'NCBL完播文件确认2/2次'), 'Listening tool returned the wrong confirmed count');
listenToolCheck(str_contains((string)$result['message'], '提交时长约6分钟'), 'Listening tool did not report submitted listening minutes');
listenToolCheck($netease->scrobbleStarts() === 2, 'Listening tool did not submit recent-play footprints');
listenToolCheck($netease->scrobbleSeconds() === 360, 'Listening tool did not submit the real song durations');
listenToolCheck($netease->scrobbleSongIds() === [347230, 347230], 'Listening tool did not retain accepted play IDs');
$urls = array_column($transport->requests, 'url');
listenToolCheck(count($urls) === 5, 'Listening tool sent an unexpected request count');
listenToolCheck(str_contains($urls[0], '/weapi/v3/song/detail'), 'Listening tool did not resolve the song duration');
listenToolCheck(
    count(array_filter($urls, static fn(string $url): bool => str_contains($url, 'clientlog3.music.163.com/api/clientlog/encrypt/upload'))) === 4,
    'Listening tool did not send one NCBL PLV/PLD pair per play'
);
foreach (array_slice($transport->requests, 1) as $request) {
    listenToolCheck(
        str_contains((string)($request['options']['body'] ?? ''), 'NCBL'),
        'Listening tool did not upload an NCBL binary payload'
    );
}

$failureTransport = new NeteaseListenRecordingTransport([
    ['body' => '{"code":200,"songs":[{"id":347230,"dt":180000}]}'],
    ['body' => '{"code":500}'],
    ['body' => '{"code":500}'],
    ['body' => '{"code":500}'],
    ['body' => '{"code":500}'],
]);
$failureSdk = new Client([
    'user_id' => '1',
    'csrf' => 'csrf-token',
    'music_u' => 'music-token',
], ['auto_anonymous_token' => false, 'cache_dir' => ''], $failureTransport);
$failed = (new Netease('1', 'csrf-token', 'music-token', [
    'songid' => '347230',
    'times' => 1,
], $failureSdk))->listen();
listenToolCheck((int)($failed['code'] ?? 0) === 201, 'Failed listening reports were treated as successful');

$silentDropTransport = new NeteaseListenRecordingTransport([
    ['body' => '{"code":200,"songs":[{"id":347230,"dt":180000}]}'],
    ['body' => '{"code":200,"data":{"successfiles":[]}}'],
]);
$silentDropSdk = new Client([
    'user_id' => '1',
    'csrf' => 'csrf-token',
    'music_u' => 'music-token',
], ['auto_anonymous_token' => false, 'cache_dir' => ''], $silentDropTransport);
$silentDrop = (new Netease('1', 'csrf-token', 'music-token', [
    'songid' => '347230',
    'times' => 1,
], $silentDropSdk))->listen();
listenToolCheck(
    (int)($silentDrop['code'] ?? 0) === 201,
    'HTTP 200 without the uploaded file name was treated as a counted play'
);

$missingSongTransport = new NeteaseListenRecordingTransport([
    ['body' => '{"code":200,"songs":[]}'],
]);
$missingSongSdk = new Client([
    'user_id' => '1',
    'csrf' => 'csrf-token',
    'music_u' => 'music-token',
], ['auto_anonymous_token' => false, 'cache_dir' => ''], $missingSongTransport);
$missingSong = (new Netease('1', 'csrf-token', 'music-token', [
    'songid' => '999999999',
    'times' => 1,
], $missingSongSdk))->listen();
listenToolCheck((int)($missingSong['code'] ?? 0) === 201, 'Missing songs were still submitted as successful plays');
listenToolCheck(count($missingSongTransport->requests) === 1, 'Missing songs still triggered weblog reports');

$controller = file_get_contents(dirname(__DIR__) . '/app/index/controller/Netease.php');
$consoleController = file_get_contents(dirname(__DIR__) . '/app/index/controller/Console.php');
$consoleHead = file_get_contents(dirname(__DIR__) . '/app/index/view/console/head.html');
$toolView = file_get_contents(dirname(__DIR__) . '/app/index/view/console/netease/tool.html');
listenToolCheck(!str_contains($controller, "'cron/netease/listen?'"), 'Listening tool still calls the missing Cron action');
listenToolCheck(str_contains($controller, '$client->listen()'), 'Listening tool does not execute the protocol directly');
listenToolCheck(str_contains($controller, "'connect_timeout' => 4.0"), 'Listening tool does not bound connection time for 300-play batches');
listenToolCheck(str_contains($controller, "'timeout' => 8.0"), 'Listening tool does not bound request time for 300-play batches');
listenToolCheck(str_contains($consoleController, 'case "tool"'), 'Listening tool console route is missing');
listenToolCheck(str_contains($consoleHead, "config('sys.is_netease_tool') eq 1"), 'Listening tool menu is not gated by the admin switch');
listenToolCheck(str_contains($toolView, '/index/ajax/netease/listen'), 'Listening tool page does not call the AJAX action');
listenToolCheck(str_contains($toolView, 'max="300"'), 'Listening tool page does not enforce the bounded batch size');

echo "Netease listening tool tests passed\n";
