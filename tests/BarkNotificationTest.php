<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use app\service\BarkClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

function barkCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$history = [];
$stack = HandlerStack::create(new MockHandler([
    new Response(200, ['Content-Type' => 'application/json'], '{"code":200,"message":"success"}'),
    new Response(400, ['Content-Type' => 'application/json'], '{"code":400,"message":"invalid key"}'),
]));
$stack->push(Middleware::history($history));
$client = new BarkClient(new GuzzleHttp\Client(['handler' => $stack]));

$sent = $client->send(
    'device_Key-123',
    '测试标题',
    '测试内容',
    'LoopDeck',
    'https://example.com/console'
);
barkCheck($sent['success'] === true, 'Valid Bark response was not accepted');
barkCheck(count($history) === 1, 'Bark request was not sent exactly once');
$request = $history[0]['request'];
barkCheck((string)$request->getUri() === BarkClient::ENDPOINT, 'Bark did not use the fixed official endpoint');
barkCheck($request->getMethod() === 'POST', 'Bark did not use POST');
$payload = json_decode((string)$request->getBody(), true);
barkCheck(is_array($payload), 'Bark JSON body is invalid');
barkCheck(($payload['device_key'] ?? '') === 'device_Key-123', 'Bark device_key is missing');
barkCheck(($payload['title'] ?? '') === '测试标题', 'Bark title is missing');
barkCheck(($payload['body'] ?? '') === '测试内容', 'Bark body is missing');
barkCheck(($payload['url'] ?? '') === 'https://example.com/console', 'Bark click URL is missing');

$invalid = $client->send('https://custom.example/key', 'title', 'body');
barkCheck($invalid['success'] === false, 'A custom Bark URL was accepted as a token');
barkCheck(count($history) === 1, 'Invalid Bark token still triggered a request');
barkCheck(BarkClient::normalizeToken('') === '', 'Empty token cannot be used to clear settings');
barkCheck(BarkClient::normalizeToken('short') === null, 'Too-short Bark token was accepted');

$failed = $client->send('another_device_key', 'title', 'body');
barkCheck($failed['success'] === false, 'Bark upstream failure was treated as success');
barkCheck($failed['message'] === 'invalid key', 'Bark upstream error message was lost');

$adminView = file_get_contents(dirname(__DIR__) . '/app/admin/view/system/set/mail.html');
$consoleHead = file_get_contents(dirname(__DIR__) . '/app/index/view/console/head.html');
$userView = file_get_contents(dirname(__DIR__) . '/app/index/view/console/user/notification.html');
$ajaxController = file_get_contents(dirname(__DIR__) . '/app/index/controller/Ajax.php');
$adminAjaxController = file_get_contents(dirname(__DIR__) . '/app/admin/controller/Ajax.php');
$scheduler = file_get_contents(dirname(__DIR__) . '/app/cron/controller/Task.php');
$installSql = file_get_contents(dirname(__DIR__) . '/app/install/install.sql');

barkCheck(str_contains($adminView, 'name="bark_enabled"'), 'Admin Bark enable switch is missing');
barkCheck(str_contains($adminView, 'https://api.day.app'), 'Admin view does not disclose the fixed official server');
barkCheck(!str_contains($adminView, 'name="bark_server"'), 'Admin view exposes a custom Bark server');
barkCheck(str_contains($consoleHead, "config('sys.bark_enabled') eq 1"), 'User Bark menu is not gated by the admin switch');
barkCheck(str_contains($consoleHead, '/index/console/user/notification'), 'User Bark menu link is missing');
barkCheck(str_contains($userView, '/index/ajax/user/notificationTest'), 'User Bark test action is missing');
barkCheck(str_contains($ajaxController, "case 'notification':"), 'User Bark save action is missing');
barkCheck(str_contains($adminAjaxController, "case 'testSendMail':"), 'Adjacent SMTP test action is still missing');
barkCheck(str_contains($adminAjaxController, "['mail_invalid', 'bark_enabled', 'is_netease_tool']"), 'Notification switches are not validated server-side');
barkCheck(str_contains($scheduler, 'sendAccountInvalid'), 'Scheduler does not send Bark account-invalid notifications');
barkCheck(str_contains($scheduler, 'sendVipExpired'), 'Scheduler does not send Bark VIP-expiry notifications');
barkCheck(str_contains($scheduler, '$stateChanged > 0'), 'Scheduler can send duplicate account-invalid notifications');
barkCheck(str_contains($scheduler, '$membershipChanged > 0'), 'Scheduler can send duplicate VIP-expiry notifications');
barkCheck(str_contains($installSql, 'cloud_user_notifications'), 'Fresh-install Bark settings table is missing');

echo "Bark notification tests passed\n";
