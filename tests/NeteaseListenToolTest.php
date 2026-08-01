<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use netease\Netease;
use netease\sdk\Client;
use netease\sdk\TransportInterface;

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
        return array_replace([
            'status' => 200,
            'headers' => [],
            'body' => '{"code":200}',
            'header' => '',
            'set_cookie' => [],
        ], array_shift($this->responses) ?? []);
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
]);
$sdk = new Client([
    'user_id' => '1',
    'csrf' => 'csrf-token',
    'music_u' => 'music-token',
], [
    'auto_anonymous_token' => false,
    'cache_dir' => '',
], $transport);
$netease = new Netease('1', 'csrf-token', 'music-token', [
    'songid' => '347230',
    'times' => 2,
], $sdk);
$result = $netease->listen();
listenToolCheck((int)($result['code'] ?? 0) === 200, 'Listening tool did not report success');
listenToolCheck(str_contains((string)$result['message'], '成功播放2次'), 'Listening tool returned the wrong success count');
$urls = array_column($transport->requests, 'url');
listenToolCheck(count($urls) === 3, 'Listening tool sent an unexpected request count');
listenToolCheck(str_contains($urls[0], '/weapi/v3/song/detail'), 'Listening tool did not resolve the song duration');
listenToolCheck(
    count(array_filter($urls, static fn(string $url): bool => str_contains($url, 'clientlog.music.163.com/eapi/feedback/weblog'))) === 2,
    'Listening tool did not use the upstream startplay/play EAPI endpoint twice'
);

$failureTransport = new NeteaseListenRecordingTransport([
    ['body' => '{"code":200,"songs":[{"id":347230,"dt":180000}]}'],
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
