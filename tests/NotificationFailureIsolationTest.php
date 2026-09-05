<?php

declare(strict_types=1);

require __DIR__ . '/NotificationTestBootstrap.php';

use app\service\NotificationService;
use app\service\NotificationRepository;
use app\service\NotificationSite;
use app\service\NotificationTransport;
use app\service\UserNotificationSettings;

$store = new MemoryNotifications();
$settings = new UserNotificationSettings($store);
$now = time();
foreach ([1, 2] as $uid) {
    $settings->save($uid, 1, ['enabled' => 1, 'bark_enabled' => 1, 'bark_token' => 'fixture_device_key',
        'task_failure' => 1, 'daily_summary' => 1, 'summary_time' => '22:00'], false);
    $store->preferences['1:' . $uid]['next_summary_at'] = $now - 1;
}
$store->failSummaryUsers = [1];
$transport = new FixtureNotificationTransport();
$service = new NotificationService($store, $settings, new FixtureNotificationSite(), $transport,
    static fn(int $uid, int $webId): array => ['uid' => $uid, 'web_id' => $webId, 'state' => 1]);
$service->notify(['uid' => 2, 'web_id' => 1], 'task_failure', 'pending', 'Task failed', 'Fixture notification');
$result = $service->tick(20, 20, $now);
notificationCheck($result['summaries'] === 1 && $result['sent'] === 2 && $result['errors'] === 1,
    'One broken summary blocked another user or the pending delivery queue');
notificationCheck($store->preferences['1:1']['next_summary_at'] === $now - 1,
    'A summary that could not be built was advanced instead of remaining retryable');
$store->failSummaryUsers = [];
$service->tick(20, 20, $now + 1);
notificationCheck(count($transport->calls) === 3, 'Recovering a summary lost or duplicated another delivery');

$unusedSites = new class extends NotificationSite {
    public function get(int $webId): array
    {
        throw new RuntimeException('Disabled notifications must not query site settings');
    }
};
$disabled = new NotificationService($store, $settings, $unusedSites, $transport);
notificationCheck(!$disabled->notify(['uid' => 99, 'web_id' => 1], 'task_failure', 'disabled', 'Test', 'Test')['success'],
    'Disabled notifications did not return before querying site settings');

$batchTransport = new class extends NotificationTransport {
    public int $closed = 0;
    public function closeMail(): void
    {
        $this->closed++;
        parent::closeMail();
    }
};
(new NotificationService(new MemoryNotifications(), null, null, $batchTransport))->tick();
notificationCheck($batchTransport->closed === 1, 'A completed batch retained transport resources');
$brokenRepository = new class extends NotificationRepository {
    public function dueSummaries(int $now, int $limit): array
    {
        throw new RuntimeException('Fixture database unavailable');
    }
};
try {
    (new NotificationService($brokenRepository, null, null, $batchTransport))->tick();
    throw new LogicException('Expected the unavailable repository to fail');
} catch (RuntimeException $error) {
    notificationCheck($error->getMessage() === 'Fixture database unavailable', 'Unexpected batch failure');
}
notificationCheck($batchTransport->closed === 2, 'An exceptional batch exit retained transport resources');

echo "Notification failure isolation tests passed\n";
