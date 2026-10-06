<?php

declare(strict_types=1);

require __DIR__ . '/FunctionalDatabaseBootstrap.php';

use app\cron\controller\Common;
use app\cron\controller\Task;
use app\service\BilibiliTaskExecutor;
use app\service\NotificationService;
use think\facade\Db;

final class SettlementHelper
{
    public bool $cookiezt = false;
    public bool $settled = false;
    public array $configs = [];

    public function watchaid(): array
    {
        return $this->settled ? ['code' => 1, 'message' => '今日观看已完成']
            : ['code' => 0, 'pending_verification' => true, 'message' => '观看经验尚未确认'];
    }
}

final class SettlementTask extends Task
{
    public BilibiliTaskExecutor $executor;

    protected function bilibiliExecutor(): BilibiliTaskExecutor
    {
        return $this->executor;
    }
}

$helper = new SettlementHelper();
$clock = strtotime('2026-10-06 09:00:00');
$factory = static function (array $account, array $config) use ($helper): SettlementHelper {
    $helper->configs[] = $config;
    return $helper;
};
$executor = new BilibiliTaskExecutor($factory, static function () use (&$clock): int { return $clock; });
$account = ['mid' => '42', 'mid_md5' => 'fixture-md5', 'token' => 'fixture-session', 'csrf' => 'fixture-csrf'];
$config = ['add_coin_num' => 3];
$common = new Common();
for ($attempt = 0; $attempt <= 24; $attempt++) {
    $result = $executor->execute('watchaid', $account, $config);
    functionalCheck($result['code'] === 0, 'Unsettled experience was reported as success');
    functionalCheck(!empty(end($helper->configs)['verification_only']) === ($attempt > 0), 'A verification retry repeated the initial action');
    $updates = BilibiliTaskExecutor::jobUpdates($result, $config, '42', '09:00', $clock);
    $config = unserialize($updates['data'], ['allowed_classes' => false]);
    functionalCheck($config['add_coin_num'] === 3, 'Verification overwrote user configuration');
    if ($attempt < 24) {
        functionalCheck($common->statusTag($result) === '重试中' && $updates['nextExecute'] === $clock + 300, 'Pending work was not scheduled in five minutes');
        functionalCheck($config['_bilibili_verification']['attempt'] === $attempt + 1, 'Verification count was not persisted');
    } else {
        functionalCheck($common->statusTag($result) === '失败' && $updates['nextExecute'] > $clock + 3600, 'Verification exceeded its bounded retry limit');
        functionalCheck(!isset($config['_bilibili_verification']) && !str_contains($result['message'], '5 分钟后'), 'Terminal failure retained a retry promise');
    }
    $clock += 300;
}
$state = ['_bilibili_verification' => ['date' => '2026-10-06', 'attempt' => 13]];
$hourDelayed = $executor->execute('watchaid', $account, $state);
functionalCheck(($hourDelayed['retry_after_seconds'] ?? 0) === 300, 'An hour-long settlement delay exhausted the verification window');
$helper->settled = true;
$settled = $executor->execute('watchaid', $account, $state);
functionalCheck($common->statusTag($settled) === '成功' && !isset($settled['retry_after_seconds']), 'Settled experience kept retrying');
$helper->settled = false;
$clock = strtotime('2026-10-07 09:00:00');
$executor->execute('watchaid', $account, $state);
functionalCheck(empty(end($helper->configs)['verification_only']), 'Yesterday verification suppressed today task');
$clock = strtotime('2026-10-07 23:58:00');
$midnight = $executor->execute('watchaid', $account);
functionalCheck(!isset($midnight['retry_after_seconds']), 'A verification retry crossed the reward reset at midnight');

// Exercise the real main scheduler against an isolated database, including its
// result conversion, job serialization, status prefix and notification boundary.
fixtureTable('accounts', 'id INTEGER PRIMARY KEY, uid INTEGER, type TEXT, user_id TEXT, state INTEGER, timing TEXT, data TEXT');
fixtureTable('tasks', 'id INTEGER PRIMARY KEY, type TEXT, execute_name TEXT, state INTEGER, vip INTEGER, name TEXT');
fixtureTable('jobs', 'id INTEGER PRIMARY KEY, uid INTEGER, zid INTEGER, type TEXT, user_id TEXT, do TEXT, state INTEGER, nextExecute INTEGER, lastExecute TEXT, data TEXT');
fixtureTable('task_logs', 'id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, user_id TEXT, do TEXT, response TEXT, addtime TEXT');
Db::name('accounts')->insert(['id' => 1, 'uid' => 1, 'type' => 'bilibili', 'user_id' => '42', 'state' => 1, 'timing' => '09:00', 'data' => serialize($account)]);
Db::name('tasks')->insert(['id' => 1, 'type' => 'bilibili', 'execute_name' => 'watchaid', 'state' => 1, 'vip' => 0, 'name' => '每日观看']);
Db::name('jobs')->insertAll([
    ['id' => 1, 'uid' => 1, 'zid' => 1, 'type' => 'bilibili', 'user_id' => '42', 'do' => 'watchaid', 'state' => 1, 'nextExecute' => time() - 1, 'data' => serialize(['add_coin_num' => 3])],
    ['id' => 2, 'uid' => 1, 'zid' => 1, 'type' => 'bilibili', 'user_id' => '42', 'do' => 'globalroom', 'state' => 1, 'nextExecute' => 0, 'data' => 'corrupt retired configuration'],
]);
$task = new SettlementTask();
$task->executor = new BilibiliTaskExecutor($factory, static fn(): int => strtotime(date('Y-m-d') . ' 09:00:00'));
$notifications = new class extends NotificationService {
    public array $results = [];
    public function __construct() {}
    public function recordTask(mixed $user, string $type, string $accountId, string $taskKey, string $taskName, array $result): void
    {
        $this->results[] = $result;
    }
};
$property = new ReflectionProperty(Task::class, 'notificationService');
$property->setAccessible(true);
$property->setValue($task, $notifications);
$run = new ReflectionMethod(Task::class, 'runJob');
$run->setAccessible(true);
$summary = ['attempted' => 0, 'failed' => 0, 'succeeded' => 0];
$job = Db::name('jobs')->find(1);
$run->invokeArgs($task, [$job, &$summary]);
$saved = Db::name('jobs')->find(1);
$savedConfig = unserialize($saved['data'], ['allowed_classes' => false]);
functionalCheck($summary['failed'] === 1 && isset($savedConfig['_bilibili_verification']), 'Main scheduler dropped verification progress');
functionalCheck(!isset($savedConfig['global_room']), 'Main scheduler copied global settings into task settings');
functionalCheck(abs($saved['nextExecute'] - time() - 300) < 5, 'Main scheduler ignored the five minute retry');
functionalCheck(str_starts_with(Db::name('task_logs')->order('id', 'desc')->value('response'), '[重试中]'), 'Main scheduler logged pending experience as success');
functionalCheck($common->statusTag($notifications->results[0]) === '重试中', 'Pending status was lost at the notification boundary');
$helper->settled = true;
Db::name('jobs')->where('id', 1)->update(['nextExecute' => time() - 1]);
$job = Db::name('jobs')->find(1);
$run->invokeArgs($task, [$job, &$summary]);
$savedConfig = unserialize(Db::name('jobs')->where('id', 1)->value('data'), ['allowed_classes' => false]);
functionalCheck($summary['succeeded'] === 1 && !isset($savedConfig['_bilibili_verification']), 'Main scheduler did not clear settled verification');
functionalCheck(str_starts_with(Db::name('task_logs')->order('id', 'desc')->value('response'), '[成功]'), 'Settled experience did not produce a final success');

foreach (['dailybag', 'doubleheart', 'groupsignIn', 'giftheart'] as $oldTask) {
    Db::name('jobs')->insert(['uid' => 1, 'zid' => 1, 'type' => 'bilibili', 'user_id' => '42',
        'do' => $oldTask, 'state' => 1, 'nextExecute' => time(), 'data' => serialize([])]);
}
$historyCount = Db::name('task_logs')->count();
$disable = new ReflectionMethod(Task::class, 'disableOfflineBilibiliJobs');
$disable->setAccessible(true);
$disable->invoke($task);
foreach (['dailybag', 'doubleheart', 'groupsignIn', 'giftheart', 'globalroom'] as $oldTask) {
    $oldJob = Db::name('jobs')->where('do', $oldTask)->find();
    functionalCheck((int)$oldJob['state'] === 0 && (int)$oldJob['nextExecute'] === 0, 'Old job remained scheduled');
    app\index\model\Jobs::switchState('bilibili', '42', $oldTask);
    functionalCheck((int)Db::name('jobs')->where('do', $oldTask)->value('state') === 0, 'Old job could be re-enabled');
}
$setTiming = new ReflectionMethod(app\index\controller\Bilibili::class, 'setTiming');
$setTiming->setAccessible(true);
$setTiming->invoke(new app\index\controller\Bilibili(), '42', '10:00');
functionalCheck(Db::name('jobs')->whereIn('do', ['dailybag','doubleheart','groupsignIn','giftheart','globalroom'])
    ->where('nextExecute', '>', 0)->count() === 0, 'Changing account timing rescheduled retired jobs');
functionalCheck((int)Db::name('jobs')->where('do', 'watchaid')->value('state') === 1, 'Retained viewing was disabled');
functionalCheck(Db::name('task_logs')->count() === $historyCount, 'Retiring jobs erased history');

// Reachability is decided at the user-facing entry point: the save endpoint the
// console posts to must reject retired configuration instead of accepting it
// and relying on the executor to ignore it later.
fixtureRequest(['user_id' => '42', 'do' => 'globalroom', 'config' => '{"global_room":"999"}']);
$save = json_decode((new app\index\controller\Ajax())->bilibili('set')->getContent(), true);
functionalCheck((int)($save['code'] ?? -1) === 0 && str_contains((string)($save['message'] ?? ''), '已停用'),
    'The save endpoint accepted retired live-room configuration');
functionalCheck(Db::name('jobs')->where('do', 'globalroom')->value('data') === 'corrupt retired configuration',
    'The save endpoint rewrote retired job data');
fixtureRequest(['user_id' => '42', 'do' => 'globalroom', 'act' => 'zt']);
$enable = json_decode((new app\index\controller\Ajax())->bilibili('set')->getContent(), true);
functionalCheck((int)($enable['code'] ?? -1) === 0
    && (int)Db::name('jobs')->where('do', 'globalroom')->value('state') === 0,
    'A retired task could be switched on through the console endpoint');
Db::name('tasks')->insert(['id' => 2, 'type' => 'bilibili', 'execute_name' => 'coinadd', 'state' => 1, 'vip' => 1, 'name' => '每日投币']);
Db::name('jobs')->insert(['id' => 20, 'uid' => 1, 'zid' => 1, 'type' => 'bilibili', 'user_id' => '42',
    'do' => 'coinadd', 'state' => 1, 'nextExecute' => 0, 'data' => serialize([])]);
fixtureRequest(['user_id' => '42', 'do' => 'coinadd', 'config' => '{"add_coin_mode":"random","add_coin_num":3}']);
$retained = json_decode((new app\index\controller\Ajax())->bilibili('set')->getContent(), true);
functionalCheck((int)($retained['code'] ?? -1) === 1, 'The console endpoint stopped serving retained daily tasks');
functionalCheck(str_contains((string)Db::name('jobs')->where('id', 20)->value('data'), 'add_coin_num'),
    'The console endpoint no longer stores retained task configuration');

echo "Bilibili settlement tests passed\n";
