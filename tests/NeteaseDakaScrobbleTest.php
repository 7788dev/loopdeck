<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use netease\Netease;
use netease\sdk\Client;
use netease\sdk\Crypto;
use netease\sdk\TransportInterface;

final class DakaScrobbleProbe extends Netease
{
    public function report(array $songs): int
    {
        return $this->weblogScrobbleBatch($songs);
    }

    public function acceptedStarts(): int
    {
        return (int)$this->lastScrobbleStarts;
    }

    public function reportedSeconds(): int
    {
        return (int)$this->lastScrobbleSeconds;
    }

    public function acceptedSongIds(): array
    {
        return $this->lastScrobbleSongIds;
    }

    public function elapsedSeconds(): float
    {
        return (float)$this->lastScrobbleElapsedSeconds;
    }
}

final class DakaScrobbleTransport implements TransportInterface
{
    public array $requests = [];
    private array $responses;

    public function __construct(array $responses = [])
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

function dakaProtocolCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function dakaProtocolPayload(array $request): array
{
    parse_str((string)($request['options']['body'] ?? ''), $form);
    $decoded = (new Crypto())->decryptEapiRequest((string)($form['params'] ?? ''));
    return is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
}

$transport = new DakaScrobbleTransport();
$sdk = new Client([
    'user_id' => '1',
    'csrf' => 'csrf-token',
    'music_u' => 'music-token',
], ['auto_anonymous_token' => false, 'cache_dir' => ''], $transport);
$probe = new DakaScrobbleProbe('1', 'csrf-token', 'music-token', [
    'daka_concurrency' => 10,
], $sdk);
$songs = [];
for ($id = 1001; $id <= 1025; $id++) {
    $songs[] = ['id' => $id, 'sourceId' => 9001, 'time' => 180];
}

$accepted = $probe->report($songs);
dakaProtocolCheck($accepted === 25, 'Concurrent weblog reporting lost accepted songs');
dakaProtocolCheck($probe->acceptedStarts() === 25, 'Concurrent weblog reporting lost startplay records');
dakaProtocolCheck($probe->reportedSeconds() === 4500, 'Concurrent weblog reporting lost the upstream time fields');
dakaProtocolCheck($probe->acceptedSongIds() === range(1001, 1025), 'Accepted song IDs were not retained');
dakaProtocolCheck($probe->elapsedSeconds() < 1.0, 'The immediate protocol introduced a playback wait');
dakaProtocolCheck(count($transport->requests) === 50, 'Twenty-five songs did not use independent startplay/play requests');

$expectedActions = array_merge(
    array_fill(0, 10, 'startplay'),
    array_fill(0, 10, 'play'),
    array_fill(0, 10, 'startplay'),
    array_fill(0, 10, 'play'),
    array_fill(0, 5, 'startplay'),
    array_fill(0, 5, 'play')
);
foreach ($transport->requests as $index => $request) {
    dakaProtocolCheck(
        $request['url'] === 'https://clientlog.music.163.com/eapi/feedback/weblog',
        'Daily reporting used the wrong api-enhanced domain or crypto mode'
    );
    dakaProtocolCheck(
        str_contains((string)($request['options']['headers']['Cookie'] ?? ''), 'os=osx'),
        'Daily reporting did not inject the required macOS client identity'
    );
    dakaProtocolCheck((float)($request['options']['timeout'] ?? 0) === 8.0, 'Daily reporting timeout is unbounded');
    dakaProtocolCheck((float)($request['options']['connect_timeout'] ?? 0) === 4.0, 'Daily reporting connect timeout is unbounded');
    $payload = dakaProtocolPayload($request);
    $logs = json_decode((string)($payload['logs'] ?? ''), true);
    dakaProtocolCheck(is_array($logs), 'Daily reporting logs were not valid JSON');
    dakaProtocolCheck(count($logs) === 1, 'Daily reporting did not match api-enhanced one-log request semantics');
    $expectedAction = $expectedActions[$index];
    dakaProtocolCheck(
        count(array_filter($logs, static fn(array $log): bool => ($log['action'] ?? '') === $expectedAction))
            === count($logs),
        'startplay and play were not sent as separate ordered phases'
    );
}

$playPayload = dakaProtocolPayload($transport->requests[10]);
$playLogs = json_decode((string)$playPayload['logs'], true);
$firstPlay = $playLogs[0]['json'] ?? [];
dakaProtocolCheck(($firstPlay['id'] ?? null) === '1001', 'Song ID did not match api-enhanced query-string semantics');
dakaProtocolCheck(($firstPlay['sourceId'] ?? null) === '9001', 'Source ID did not match api-enhanced query-string semantics');
dakaProtocolCheck(($firstPlay['time'] ?? null) === '180', 'Reported time did not match api-enhanced query-string semantics');
dakaProtocolCheck(($firstPlay['end'] ?? null) === 'playend', 'Play report did not use the upstream playend marker');

$retryTransport = new DakaScrobbleTransport([
    ['body' => '{"code":500}'],
    ['body' => '{"code":200}'],
    ['body' => '{"code":250}'],
    ['body' => '{"code":250}'],
]);
$retrySdk = new Client([
    'user_id' => '1',
    'csrf' => 'csrf-token',
    'music_u' => 'music-token',
], ['auto_anonymous_token' => false, 'cache_dir' => ''], $retryTransport);
$retryProbe = new DakaScrobbleProbe('1', 'csrf-token', 'music-token', [
    'daka_concurrency' => 10,
], $retrySdk);
$retryAccepted = $retryProbe->report([['id' => 2001, 'sourceId' => 9002, 'time' => 200]]);
dakaProtocolCheck($retryAccepted === 0, 'A non-200 play response was reported as accepted');
dakaProtocolCheck($retryProbe->acceptedStarts() === 1, 'A transient startplay failure was not retried once');
dakaProtocolCheck(count($retryTransport->requests) === 4, 'Weblog retry count was not bounded to one retry per phase');

echo "Netease daily scrobble protocol tests passed\n";
