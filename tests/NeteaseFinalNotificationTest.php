<?php

declare(strict_types=1);

require __DIR__ . '/NotificationTestBootstrap.php';

use app\service\NotificationService;
use app\service\NotificationText;
use app\service\UserNotificationSettings;

$store = new MemoryNotifications();
$settings = new UserNotificationSettings($store);
$settings->save(1, 1, ['enabled' => 1, 'bark_enabled' => 1, 'bark_token' => 'fixture_device',
    'task_success' => 1, 'task_failure' => 1, 'summary_time' => '22:00'], false);
$transport = new FixtureNotificationTransport();
$service = new NotificationService($store, $settings, new FixtureNotificationSite(), $transport);
$user = ['uid' => 1, 'web_id' => 1, 'state' => 1];
$pending = [
    ['code' => 201, 'message' => '上报已接收，等待入账', 'data' => ['retry_after_seconds' => 300]],
    ['success' => false, 'message' => '累计计数暂未变化', 'retry_after_seconds' => 900],
    ['success' => false, 'message' => '暂时读取失败，继续内部复查', 'retry_after_seconds' => 300],
];
foreach ($pending as $result) {
    $service->recordTask($user, 'netease', 'success-account', 'daka_new', '每日300首', $result);
    notificationCheck($store->tasks === [] && $store->messages === [],
        'A pending verification appeared in the daily overview or notification queue');
}

$success = ['code' => 200, 'message' => '进度 300/300', 'data' => ['retry_after_seconds' => 0]];
$service->recordTask($user, 'netease', 'success-account', 'daka_new', '每日300首', $success);
$service->recordTask($user, 'netease', 'success-account', 'daka_new', '每日300首', $success);
$service->recordTask($user, 'netease', 'success-account', 'daka_new', '每日300首', $pending[0]);
notificationCheck(count($store->tasks) === 1 && reset($store->tasks)['status'] === '成功'
    && count($store->messages) === 1 && reset($store->messages)['event_type'] === 'task_success',
    'The final success was duplicated or replaced by an intermediate verification');

$service->recordTask($user, 'netease', 'failed-account', 'daka_new', '每日300首', $pending[1]);
notificationCheck(count($store->messages) === 1, 'An unfinished second account sent a premature failure');
$failure = ['success' => false, 'message' => '今日核验窗口已结束，进度 296/300', 'retry_after_seconds' => 0];
$service->recordTask($user, 'netease', 'failed-account', 'daka_new', '每日300首', $failure);
$service->recordTask($user, 'netease', 'failed-account', 'daka_new', '每日300首', $failure);
notificationCheck(count($store->tasks) === 2 && count($store->messages) === 2
    && end($store->messages)['event_type'] === 'task_failure',
    'A terminal verification failure was lost or produced duplicate notifications');
notificationCheck($transport->calls === [], 'Recording a task made a synchronous external notification call');

$rows = $store->dailyTasks(1, 1, date('Y-m-d'));
$rows[] = ['type' => 'netease', 'account_id' => 'legacy-account', 'task_key' => 'daka_new',
    'task_name' => '旧版中间记录', 'status' => '重试中', 'message' => '等待入账'];
$rows[] = ['type' => 'netease', 'account_id' => 'another-task', 'task_key' => 'sign',
    'task_name' => '每日签到', 'status' => '重试中', 'message' => '等待重试'];
$report = NotificationText::dailySummary(date('Y-m-d'), $rows);
notificationCheck(!str_contains($report, '旧版中间记录') && str_contains($report, '共 3 项')
    && str_contains($report, '成功 1，失败 1，重试中 1') && str_contains($report, '每日签到'),
    'Historical in-progress listening rows leaked into a summary or changed unrelated task results');
notificationCheck(count($rows) === 4, 'Summary formatting mutated stored historical results');

$legacyOnly = [['type' => 'netease', 'task_key' => 'daka_new', 'status' => '重试中']];
$emptyReport = NotificationText::dailySummary(date('Y-m-d'), $legacyOnly);
notificationCheck(str_contains($emptyReport, '共 0 项') && str_contains($emptyReport, '今日暂无'),
    'A summary containing only legacy verification records was not treated as empty');
$manyRows = array_fill(0, 70, $legacyOnly[0]);
for ($index = 0; $index < 65; $index++) {
    $manyRows[] = ['type' => 'netease', 'account_id' => 'completed-account', 'task_key' => 'daka_new',
        'task_name' => '听歌' . $index, 'status' => '成功', 'message' => '已完成'];
}
$largeReport = NotificationText::dailySummary(date('Y-m-d'), $manyRows);
notificationCheck(str_contains($largeReport, '共 65 项') && str_contains($largeReport, '听歌59')
    && !str_contains($largeReport, '听歌60') && str_contains($largeReport, '其余 5 项'),
    'Legacy waiting rows consumed the 60-result summary limit or changed its remainder count');

echo "Netease final notification tests passed: silent verification, terminal alerts and legacy summary filtering\n";
