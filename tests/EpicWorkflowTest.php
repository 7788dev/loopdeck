<?php

declare(strict_types=1);

require __DIR__ . '/NotificationTestBootstrap.php';

use app\service\EpicTaskExecutor;
use app\service\EpicSchedule;
use app\service\NotificationService;
use app\service\UserNotificationSettings;

$store = new MemoryNotifications();
$settings = new UserNotificationSettings($store);
$service = new NotificationService($store, $settings, new FixtureNotificationSite(), new FixtureNotificationTransport());
$user = ['uid' => 1, 'web_id' => 1, 'state' => 1, 'vip_end' => '2099-12-31'];
$empty = (new EpicTaskExecutor($service, static fn() => []))->execute($user);
notificationCheck(!$empty['success'] && $empty['retry_after_seconds'] > 0, 'Empty Epic catalog must retry');
$games = [['available' => true, 'title' => '测试游戏', 'start_at' => time(), 'end_at' => time() + 86400, 'productUrl' => 'https://store.epicgames.com/p/fixture']];
$executor = new EpicTaskExecutor($service, static fn() => $games);
notificationCheck(!$executor->execute($user)['success'], 'Disabled reminder reported success');
$settings->save(1, 1, ['enabled' => 1, 'bark_enabled' => 1, 'bark_token' => 'fixture-token', 'epic_free_games' => 1], false);
notificationCheck($executor->execute($user)['success'], 'Configured Epic reminder was not queued');
notificationCheck(count($store->messages) === 1, 'Epic reminder did not reach the outbox');
$executor->execute($user);
notificationCheck(count($store->messages) === 1, 'Repeated catalog notification duplicated a message');
notificationCheck(EpicSchedule::next('25:00') === null, 'Invalid Epic schedule accepted');
$friday = strtotime('2026-10-02 09:00:00');
notificationCheck(EpicSchedule::next('10:00', $friday) === $friday + 3600, 'Friday reminder scheduled on the wrong day');
notificationCheck(EpicSchedule::next('10:00', $friday + 7200) === $friday + 7 * 86400 + 3600, 'Past Friday slot failed to roll to next week');
echo "Epic catalog, reminder queue, deduplication and schedule tests passed\n";
