<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use app\service\ApplicationVersion;
use app\service\SystemUpdater;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

function updaterCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

updaterCheck(ApplicationVersion::current() === '1.0.1', 'Local VERSION was not loaded');
updaterCheck(ApplicationVersion::normalize('v1.2.3') === '1.2.3', 'Version normalization failed');
updaterCheck(ApplicationVersion::normalize('latest') === null, 'Invalid version was accepted');

$history = [];
$stack = HandlerStack::create(new MockHandler([
    new Response(200, ['Content-Type' => 'text/plain'], "1.1.0\n"),
    new Response(202, ['Content-Type' => 'application/json'], '{"status":"accepted"}'),
]));
$stack->push(Middleware::history($history));
$client = new GuzzleHttp\Client(['handler' => $stack]);
$updater = new SystemUpdater($client, [
    'version_url' => 'https://example.test/VERSION',
    'update_url' => 'http://updater:8080/v1/update',
    'update_token' => 'test-update-token',
    'image' => 'ghcr.io/7788dev/loopdeck:latest',
]);

$status = $updater->status();
updaterCheck($status['current_version'] === '1.0.1', 'Status returned the wrong local version');
updaterCheck($status['latest_version'] === '1.1.0', 'Status returned the wrong remote version');
updaterCheck($status['update_available'] === true, 'Newer remote version was not detected');
updaterCheck($status['updater_available'] === true, 'Configured updater was reported unavailable');

$triggered = $updater->trigger();
updaterCheck($triggered['status'] === 202, 'Async update was not accepted');
updaterCheck(count($history) === 2, 'Unexpected updater request count');
updaterCheck(
    $history[1]['request']->getHeaderLine('Authorization') === 'Bearer test-update-token',
    'Updater authorization header is missing'
);
$query = [];
parse_str($history[1]['request']->getUri()->getQuery(), $query);
updaterCheck(($query['async'] ?? '') === 'true', 'Async update query is missing');
updaterCheck(($query['image'] ?? '') === 'ghcr.io/7788dev/loopdeck:latest', 'Target image query is missing');

$invalid = new SystemUpdater(new GuzzleHttp\Client([
    'handler' => new MockHandler([new Response(200, [], 'latest')]),
]), [
    'version_url' => 'https://example.test/VERSION',
]);
updaterCheck($invalid->status()['error'] !== null, 'Invalid remote VERSION did not produce an error');

echo "System updater tests passed\n";
