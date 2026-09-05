<?php

declare(strict_types=1);

require __DIR__ . '/NotificationTestBootstrap.php';

use app\service\BarkClient;
use app\service\NotificationTransport;
use app\service\UserNotificationSettings;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

$history = [];
$handler = HandlerStack::create(new MockHandler([
    new Response(200, [], '{"code":200,"data":"accepted"}'),
    new Response(200, [], '{"code":1000,"data":[{"uid":"UID_testuser","code":1000}]}'),
    new Response(200, [], '{"code":1000,"data":[{"uid":"UID_testuser","code":1001}]}'),
    new Response(200, [], '{"code":1000,"data":[]}'),
    new Response(200, [], '<html>upstream error</html>'),
    new Response(302, ['Location' => 'https://foreign.example'], ''),
    new Response(200, [], '{}'),
]));
$handler->push(Middleware::history($history));
$client = new Client(['handler' => $handler]);
$transport = new NotificationTransport($client);
$settings = array_replace(UserNotificationSettings::defaults(), ['bark_token' => 'test_device_key',
    'pushplus_token' => 'test_pushplus_token', 'wxpusher_app_token' => 'AT_testapplication', 'wxpusher_uid' => 'UID_testuser']);
$site = ['name' => 'LoopDeck', 'email_available' => false];
notificationCheck($transport->send('pushplus', $settings, $site, '每日汇总', 'success')['success'], 'PushPlus acknowledgement was rejected');
notificationCheck($transport->send('wxpusher', $settings, $site, '每日汇总', 'success')['success'], 'WxPusher recipient acknowledgement was rejected');
notificationCheck(!$transport->send('wxpusher', $settings, $site, 'test', 'body')['success'], 'A failed WxPusher recipient was treated as success');
notificationCheck(!$transport->send('wxpusher', $settings, $site, 'test', 'body')['success'], 'An absent WxPusher recipient was treated as success');
notificationCheck(!$transport->send('pushplus', $settings, $site, 'test', 'body')['success'], 'HTTP 200 with non-JSON content was accepted');
notificationCheck(!$transport->send('pushplus', $settings, $site, 'test', 'body')['success'], 'A redirect was followed or treated as success');
notificationCheck(!(new BarkClient($client))->send('test_device_key', 'test', 'body')['success'], 'Bark accepted an empty success envelope');
$pushplus = json_decode((string)$history[0]['request']->getBody(), true);
$wxpusher = json_decode((string)$history[1]['request']->getBody(), true);
notificationCheck((string)$history[0]['request']->getUri() === NotificationTransport::PUSHPLUS_ENDPOINT
    && $pushplus['channel'] === 'wechat' && $pushplus['template'] === 'txt', 'PushPlus used an incorrect or paid delivery channel');
notificationCheck($wxpusher['uids'] === ['UID_testuser'] && $wxpusher['contentType'] === 1 && $wxpusher['verifyPayType'] === 0, 'WxPusher request did not target the configured subscriber');
foreach ($history as $request) {
    notificationCheck($request['options']['allow_redirects'] === false, 'A push credential could be forwarded through a redirect');
    notificationCheck(!str_contains((string)$request['request']->getUri(), 'test_'), 'A credential was placed into a request URL');
}

$smtp = ['mail_enabled' => 1, 'mail_smtp' => 'smtp.example.com', 'mail_port' => 587, 'mail_name' => 'sender@example.com', 'mail_pwd' => 'test-password'];
$site += ['smtp' => $smtp];
$settings['email_address'] = 'recipient@example.com';
$mailCalls = 0;
$mailTransport = new NotificationTransport($client, static function ($mail) use (&$mailCalls): bool {
    $mailCalls++;
    notificationCheck($mail->CharSet === 'UTF-8' && $mail->SMTPSecure === 'tls', 'SMTP encoding or STARTTLS configuration was wrong');
    notificationCheck($mail->Timeout === 8 && $mail->getSMTPInstance()->Timelimit === 8, 'SMTP operations had an unbounded default timeout');
    return false;
});
notificationCheck(!$mailTransport->send('email', $settings, $site, 'test', 'body')['success'] && $mailCalls === 0, 'Unavailable email could be sent through a direct request');
$site['email_available'] = true;
notificationCheck(!$mailTransport->send('email', $settings, $site, 'test', 'body')['success'] && $mailCalls === 1, 'SMTP false was incorrectly reported as success');
echo "Notification provider protocol tests passed\n";
