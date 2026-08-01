<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use app\cron\controller\Task;
use app\service\AutomaticSchedule;

function automaticScheduleCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

automaticScheduleCheck(!AutomaticSchedule::isConfigured(null), 'Null timing was treated as configured');
automaticScheduleCheck(!AutomaticSchedule::isConfigured(''), 'Empty timing was treated as configured');
automaticScheduleCheck(!AutomaticSchedule::isConfigured('8:00'), 'Non-canonical timing was accepted');
automaticScheduleCheck(!AutomaticSchedule::isConfigured('24:00'), 'Invalid timing was accepted');
automaticScheduleCheck(AutomaticSchedule::normalize(' 08:30 ') === '08:30', 'Timing was not normalized');

$now = new DateTimeImmutable('2026-08-01 10:00:00', new DateTimeZone('Asia/Shanghai'));
$bilibiliNext = AutomaticSchedule::nextExecution('bilibili', '7', '08:30', $now->getTimestamp());
automaticScheduleCheck(
    $bilibiliNext === $now->modify('+1 day')->setTime(8, 30)->getTimestamp(),
    'Non-NetEase schedule did not advance to the configured time on the next day'
);

$neteaseNext = AutomaticSchedule::nextExecution('netease', '3306177679', '08:30', $now->getTimestamp());
$neteaseBase = $now->modify('+1 day')->setTime(8, 30)->getTimestamp();
automaticScheduleCheck(
    $neteaseNext !== null && $neteaseNext >= $neteaseBase + 180 && $neteaseNext <= $neteaseBase + 900,
    'NetEase schedule did not retain its 3-15 minute safety delay'
);

$task = (new ReflectionClass(Task::class))->newInstanceWithoutConstructor();
$nextExecuteAt = new ReflectionMethod(Task::class, 'nextExecuteAt');
$nextExecuteAt->setAccessible(true);
automaticScheduleCheck(
    $nextExecuteAt->invoke($task, ['type' => 'netease', 'user_id' => '7', 'timing' => null], 1) === 0,
    'Unscheduled task was assigned a future execution time'
);

$neteaseController = file_get_contents(dirname(__DIR__) . '/app/index/controller/Netease.php');
$bilibiliController = file_get_contents(dirname(__DIR__) . '/app/index/controller/Bilibili.php');
automaticScheduleCheck(
    !str_contains($neteaseController, '请先等待系统执行后再设定挂机时间')
        && !str_contains($bilibiliController, '请先等待系统执行后再设定挂机时间'),
    'A new account is still blocked from setting its first schedule'
);

echo "Automatic schedule opt-in tests passed\n";
