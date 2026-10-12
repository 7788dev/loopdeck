<?php

declare(strict_types=1);

require __DIR__ . '/FunctionalDatabaseBootstrap.php';

use app\service\NotificationRepository;
use think\facade\Db;

$database = $app->config->get('database');
$database['connections']['mysql']['prefix'] = 'qa_';
$app->config->set($database, 'database');
fixtureTable('notification_outbox', 'id INTEGER PRIMARY KEY, status INTEGER, attempts INTEGER, available_at INTEGER, finished_at INTEGER DEFAULT 0');

$owner = new NotificationRepository();
$other = new NotificationRepository();
$failures = [];
$now = time();
Db::name('notification_outbox')->insert(['id' => 1, 'status' => 0, 'attempts' => 0, 'available_at' => $now]);
functionalCheck($owner->claimMessage(1, $now, $now), 'First sender could not claim');
$lease = (int)Db::name('notification_outbox')->value('available_at');
$secondClaim = $other->claimMessage(1, $lease, $lease + 1);
$owner->finishMessage(1, ['status' => 1]);
if ($secondClaim) {
    $failures[] = 'A second sender reclaimed an in-flight message after timestamp expiry';
}

Db::name('notification_outbox')->where('id', 1)->update(['status' => 0, 'available_at' => $now, 'attempts' => 0]);
functionalCheck($owner->claimMessage(1, $now, $now), 'Sender lock was not released after completion');
// A state changed by another actor must not be overwritten by the late completion.
Db::name('notification_outbox')->where('id', 1)->update(['status' => 3, 'available_at' => $now + 999]);
$owner->finishMessage(1, ['status' => 1]);
if ((int)Db::name('notification_outbox')->value('status') !== 3) {
    $failures[] = 'Late completion overwrote a newer message state';
}
functionalCheck($failures === [], implode('; ', $failures));
echo "Notification lease ownership tests passed\n";
