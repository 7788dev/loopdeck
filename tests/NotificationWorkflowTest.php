<?php

declare(strict_types=1);

require __DIR__ . '/NotificationTestBootstrap.php';

use app\service\NotificationService;
use app\service\NotificationSite;
use app\service\NotificationText;
use app\service\UserNotificationSettings;

$store = new MemoryNotifications();
$store->legacy['1:1'] = 'legacy_device_key';
$settings = new UserNotificationSettings($store);
$legacy = $settings->get(1, 1);
notificationCheck($legacy['enabled'] && $legacy['channels']['bark'], 'Existing Bark subscription was lost');
notificationCheck(!$legacy['events']['daily_summary'] && !$legacy['events']['task_failure'], 'Migration silently subscribed old users to new events');
notificationCheck($settings->get(1, 2)['bark_token'] === '', 'A site could read another site\'s device key');
notificationCheck(!array_key_exists('bark_token', $settings->publicSettings(1, 1)), 'A saved secret was returned to the browser');
$store->legacyEpic['1:3'] = ['user_id' => 'legacy@example.com', 'data' => serialize(['timing' => '10:00'])];
$oldEpic = $settings->get(3, 1);
notificationCheck($oldEpic['enabled'] && $oldEpic['channels']['email'] && $oldEpic['events']['epic_free_games']
    && $oldEpic['email_address'] === 'legacy@example.com' && $oldEpic['epic_time'] === '10:00', 'An existing Epic subscription was lost during migration');
notificationCheck(!$oldEpic['events']['daily_summary'] && !$oldEpic['events']['task_failure'], 'Migrating Epic enabled unrelated notifications');

$input = ['enabled' => 1, 'bark_enabled' => 1, 'pushplus_enabled' => 1, 'bark_token' => '',
    'pushplus_token' => 'pushplus_test_token', 'task_success' => 1, 'task_failure' => 1, 'daily_summary' => 1,
    'account_invalid' => 1, 'vip_expired' => 1, 'summary_time' => '22:00'];
$settings->save(1, 1, $input, false);
notificationCheck($settings->get(1, 1)['bark_token'] === 'legacy_device_key', 'Saving blank masked credentials erased the saved key');
notificationCheck($settings->get(1, 1)['channels']['pushplus'], 'PushPlus preferences were not saved');
$dueBeforeEdit = time() - 30;
$store->preferences['1:1']['next_summary_at'] = $dueBeforeEdit;
$settings->save(1, 1, $input, false);
notificationCheck($store->preferences['1:1']['next_summary_at'] === $dueBeforeEdit, 'Editing settings skipped the daily report awaiting delivery');
foreach ([['email_enabled' => 1, 'email_address' => 'test@example.com'], ['summary_time' => '25:00'],
    ['enabled' => 2], ['bark_token' => ['nested']], ['wxpusher_enabled' => 1], ['bark_token' => 'https://evil.example/key']] as $bad) {
    try {
        $settings->save(1, 1, array_replace($input, $bad), false);
        throw new LogicException('Invalid notification input was accepted');
    } catch (InvalidArgumentException $expected) {

    }
}
$smtp = ['mail_enabled' => 1, 'mail_smtp' => 'smtp.example.com', 'mail_port' => 587, 'mail_name' => 'sender@example.com', 'mail_pwd' => 'test-password'];
notificationCheck(NotificationSite::emailAvailable($smtp), 'Complete enabled SMTP settings were unavailable');
notificationCheck(!NotificationSite::emailAvailable(array_replace($smtp, ['mail_enabled' => 0])), 'Disabled email remained available');
notificationCheck(!NotificationSite::emailAvailable(array_replace($smtp, ['mail_pwd' => ''])), 'Incomplete SMTP settings exposed email');

$before = strtotime(date('Y-m-d') . ' 21:59:00');
notificationCheck(UserNotificationSettings::nextSummaryAt('22:00', $before) === $before + 60, 'Daily report was not scheduled at 22:00');
notificationCheck(UserNotificationSettings::nextSummaryAt('22:00', $before + 120) === $before + 86460, 'A past report time was scheduled immediately');

$site = new FixtureNotificationSite();
$transport = new FixtureNotificationTransport();
$user = ['uid' => 1, 'web_id' => 1, 'state' => 1];
$loader = static fn(int $uid, int $webId): array => $uid === 1 && $webId === 1 ? $user : [];
$service = new NotificationService($store, $settings, $site, $transport, $loader);
$service->recordTask($user, 'netease', '123', 'daka_new', '每日300首', ['success' => false, 'message' => '进度 0/300', 'retry_after_seconds' => 300]);
notificationCheck($store->messages === [], 'Pending accounting generated a final failure push');
$service->recordTask($user, 'netease', '123', 'daka_new', '每日300首', ['success' => true, 'message' => '进度 300/300']);
$service->recordTask($user, 'netease', '123', 'daka_new', '每日300首', ['success' => true, 'message' => '进度 300/300']);
notificationCheck(count($store->messages) === 2 && count($store->tasks) === 1, 'A repeated task result duplicated alerts or daily report rows');
notificationCheck($transport->calls === [], 'Platform execution synchronously called a push service');
$service->recordTask($user, 'bilibili', '456', 'sign', '每日签到', ['success' => false, 'message' => '签到失败']);
$service->recordTask($user, 'bilibili', '456', 'silver2coin', '银瓜子兑换', ['success' => false, 'message' => '稍后再试', 'retry_after_seconds' => 300]);
$report = NotificationText::dailySummary(date('Y-m-d'), $store->dailyTasks(1, 1, date('Y-m-d')));
notificationCheck(str_contains($report, '成功 1，失败 1，重试中 1') && str_contains($report, '每日300首')
    && str_contains($report, '每日签到') && str_contains($report, '银瓜子兑换'), 'Daily overview lost individual task outcomes');
notificationCheck(!str_contains(NotificationText::clean('ok MUSIC_U=secret token:credential | done'), 'credential'), 'A credential leaked into a notification');

$now = time();
$store->preferences['1:1']['next_summary_at'] = $now - 1;
$transport->fail['bark'] = true;
$tick = $service->tick(20, 20, $now);
notificationCheck($tick['summaries'] === 1 && $tick['sent'] === 3 && $tick['retrying'] === 3, 'A channel failure blocked other channels or the daily overview');
$sentPushplus = count(array_filter($transport->calls, static fn($r) => $r['channel'] === 'pushplus'));
$transport->fail['bark'] = false;
$service->tick(20, 20, $now + 301);
notificationCheck(count(array_filter($transport->calls, static fn($r) => $r['channel'] === 'pushplus')) === $sentPushplus, 'Retrying Bark duplicated successful PushPlus deliveries');
notificationCheck(count(array_filter($store->messages, static fn($r) => $r['status'] === 1)) === 6, 'Retryable notifications did not complete');
$service->tick(20, 20, $now + 600);
notificationCheck(count($store->messages) === 6, 'The same daily summary was queued twice');

$settings->saveBarkToken(1, 1, '');
notificationCheck($settings->get(1, 1)['channels']['pushplus'] && $settings->get(1, 1)['pushplus_token'] !== '', 'Clearing Bark erased another channel');
echo "Notification preferences, summary and delivery workflow tests passed\n";
