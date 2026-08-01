<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use app\service\NeteaseSchedule;

function scheduleCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

date_default_timezone_set('Asia/Shanghai');

$now = strtotime('2026-08-01 07:00:00');
$base = strtotime('2026-08-02 08:00:00');
$next = NeteaseSchedule::nextTimedExecution('08:00', 'netease:3306177679', $now);

scheduleCheck($next !== null, 'Valid timing did not produce a schedule');
$offset = $next - $base;
scheduleCheck(
    $offset >= NeteaseSchedule::MINIMUM_JITTER_SECONDS
    && $offset <= NeteaseSchedule::MAXIMUM_JITTER_SECONDS,
    'NetEase timing offset is outside the 3-15 minute window'
);
scheduleCheck(
    $next === NeteaseSchedule::nextTimedExecution('08:00', 'netease:3306177679', $now),
    'The same account and day did not receive a stable offset'
);

$offsets = [];
for ($day = 1; $day <= 7; $day++) {
    $date = date('Y-m-d', strtotime("2026-08-01 +{$day} day"));
    $offsets[] = NeteaseSchedule::dailyOffset('netease:3306177679', $date);
}
scheduleCheck(count(array_unique($offsets)) > 1, 'NetEase timing offset did not change across days');
scheduleCheck(NeteaseSchedule::nextTimedExecution('24:00', 'netease:1', $now) === null, 'Invalid timing was accepted');
scheduleCheck(NeteaseSchedule::nextTimedExecution('8:00', 'netease:1', $now) === null, 'Non-canonical timing was accepted');

echo "Netease schedule tests passed\n";
