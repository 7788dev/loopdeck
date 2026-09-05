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
$previousJitter = getenv('SCHEDULER_JITTER_SECONDS');
putenv('SCHEDULER_JITTER_SECONDS=120');
$bilibiliNext = AutomaticSchedule::nextExecution('bilibili', '7', '08:30', $now->getTimestamp());
$bilibiliBase = $now->modify('+1 day')->setTime(8, 30)->getTimestamp();
automaticScheduleCheck(
    $bilibiliNext >= $bilibiliBase && $bilibiliNext <= $bilibiliBase + 120,
    'Non-NetEase schedule did not retain the configured daily jitter window'
);
$offsets = [];
for ($day = 0; $day < 7; $day++) {
    $date = $now->modify('+' . $day . ' days')->format('Y-m-d');
    $offsets[] = AutomaticSchedule::dailyJitter('bilibili:7', $date);
    automaticScheduleCheck(end($offsets) === AutomaticSchedule::dailyJitter('bilibili:7', $date), 'Refreshing changed the same daily plan');
}
automaticScheduleCheck(count(array_unique($offsets)) > 1, 'Bilibili jitter repeated the same offset every day');
putenv('SCHEDULER_JITTER_SECONDS=0');
automaticScheduleCheck(AutomaticSchedule::nextExecution('bilibili', '7', '08:30', $now->getTimestamp()) === $bilibiliBase, 'Disabled jitter was ignored');
putenv($previousJitter === false ? 'SCHEDULER_JITTER_SECONDS' : 'SCHEDULER_JITTER_SECONDS=' . $previousJitter);

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

$neteaseView = file_get_contents(dirname(__DIR__) . '/app/index/view/console/netease/info.html');
$bilibiliView = file_get_contents(dirname(__DIR__) . '/app/index/view/console/bilibili/info.html');
automaticScheduleCheck(
    str_contains($neteaseView, 'data-allow-input="true"')
        && str_contains($bilibiliView, 'data-allow-input="true"'),
    'Schedule inputs cannot be cleared to disable automatic execution'
);
automaticScheduleCheck(
    str_contains($neteaseView, '<option value="daily_recommend">首页每日推荐（默认）</option>'),
    'NetEase daily listening source does not default to home daily recommendations'
);
automaticScheduleCheck(
    !str_contains($neteaseView, '说明：默认使用当前账号首页的每日推荐歌曲'),
    'NetEase daily listening configuration still shows explanatory text'
);

echo "Automatic schedule opt-in tests passed\n";
