<?php

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/common.php';

use app\service\ApplicationVersion;
use app\service\SystemUpdater;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

function updaterCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

updaterCheck(ApplicationVersion::current() === '1.1.11', 'Local VERSION was not loaded');
updaterCheck(app_version() === '1.1.11', 'Template asset version was not loaded');
updaterCheck(ApplicationVersion::normalize('v1.2.3') === '1.2.3', 'Version normalization failed');
updaterCheck(ApplicationVersion::normalize('latest') === null, 'Invalid version was accepted');

$history = [];
$stack = HandlerStack::create(new MockHandler([
    new Response(200, ['Content-Type' => 'text/plain'], "1.1.12\n"),
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
updaterCheck($status['current_version'] === '1.1.11', 'Status returned the wrong local version');
updaterCheck($status['latest_version'] === '1.1.12', 'Status returned the wrong remote version');
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
updaterCheck(($query['image'] ?? '') === 'ghcr.io/7788dev/loopdeck:latest', 'Target image query is missing');
updaterCheck(!array_key_exists('async', $query), 'Unsupported async query was sent to Watchtower');

$timeoutRequest = new Request('POST', 'http://updater:8080/v1/update');
$dispatchedTimeout = new SystemUpdater(new GuzzleHttp\Client([
    'handler' => new MockHandler([new ConnectException('response timeout', $timeoutRequest, null, [
        'errno' => 28,
        'request_size' => 283,
        'primary_port' => 8080,
    ])]),
]), [
    'update_url' => 'http://updater:8080/v1/update',
    'update_token' => 'test-update-token',
    'image' => 'ghcr.io/7788dev/loopdeck:latest',
]);
updaterCheck($dispatchedTimeout->trigger()['status'] === 202, 'Dispatched update timeout was not accepted');

$connectionFailed = new SystemUpdater(new GuzzleHttp\Client([
    'handler' => new MockHandler([new ConnectException('connect timeout', $timeoutRequest, null, [
        'errno' => 28,
        'request_size' => 0,
        'primary_port' => 0,
    ])]),
]), [
    'update_url' => 'http://updater:8080/v1/update',
    'update_token' => 'test-update-token',
    'image' => 'ghcr.io/7788dev/loopdeck:latest',
]);
$connectionFailureRaised = false;
try {
    $connectionFailed->trigger();
} catch (ConnectException $exception) {
    $connectionFailureRaised = true;
}
updaterCheck($connectionFailureRaised, 'Connection failure was incorrectly accepted');

$invalid = new SystemUpdater(new GuzzleHttp\Client([
    'handler' => new MockHandler([new Response(200, [], 'latest')]),
]), [
    'version_url' => 'https://example.test/VERSION',
]);
updaterCheck($invalid->status()['error'] !== null, 'Invalid remote VERSION did not produce an error');

$fallbackHistory = [];
$fallbackRequest = new Request('GET', 'https://primary.example.test/VERSION');
$fallbackStack = HandlerStack::create(new MockHandler([
    new ConnectException('connect timeout', $fallbackRequest, null, [
        'errno' => 28,
        'request_size' => 0,
        'primary_port' => 0,
    ]),
    new Response(200, ['Content-Type' => 'text/plain'], "1.1.4\n"),
]));
$fallbackStack->push(Middleware::history($fallbackHistory));
$fallback = new SystemUpdater(new GuzzleHttp\Client(['handler' => $fallbackStack]), [
    'version_url' => 'https://primary.example.test/VERSION',
    'version_fallback_urls' => ['https://fallback.example.test/VERSION'],
]);
$fallbackStatus = $fallback->status();
updaterCheck($fallbackStatus['latest_version'] === '1.1.4', 'Fallback version source was not used');
updaterCheck($fallbackStatus['version_url'] === 'https://fallback.example.test/VERSION', 'Fallback source was not reported');
updaterCheck(count($fallbackHistory) === 2, 'Unexpected fallback request count');

echo "System updater tests passed\n";
