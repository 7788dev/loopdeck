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

final class PointsSettlementHelper
{
    public bool $cookiezt = false;
    public array $configs = [];
    public array $result = [
        'code' => 0, 'pending_verification' => true,
        'points_submitted' => ['sign', 'bonus'], 'message' => '积分到账状态尚未确认',
    ];

    public function vippoints(): array
    {
        return $this->result;
    }
}

function settlementSubmitted(mixed $actual, array $expected, string $message): void
{
    functionalCheck(is_array($actual) && array_is_list($actual), 'Persisted points actions must be a list');
    sort($actual);
    sort($expected);
    functionalCheck($actual === $expected, $message);
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

// The points workflow tracks individual mutations, not one shared claim flag.
$pointsHelper = new PointsSettlementHelper();
$pointsClock = strtotime('2026-11-11 09:00:00');
$pointsFactory = static function (array $account, array $config) use ($pointsHelper): PointsSettlementHelper {
    $pointsHelper->configs[] = $config;
    return $pointsHelper;
};
$pointsExecutor = new BilibiliTaskExecutor($pointsFactory, static function () use (&$pointsClock): int { return $pointsClock; });
functionalCheck(BilibiliTaskExecutor::supports('vippoints'), 'Points task is not executable');
$pointsConfig = ['add_coin_num' => 3, 'points_submitted' => ['sign'], 'verification_only' => true];
$pointsFirst = $pointsExecutor->execute('vippoints', $account, $pointsConfig);
settlementSubmitted(end($pointsHelper->configs)['points_submitted'] ?? null, [], 'User configuration forged a prior points submission');
functionalCheck(empty(end($pointsHelper->configs)['verification_only']), 'User configuration forced a fresh task into verification');
$pointsUpdates = BilibiliTaskExecutor::jobUpdates($pointsFirst, $pointsConfig, '42', '09:00', $pointsClock);
$pointsConfig = unserialize($pointsUpdates['data'], ['allowed_classes' => false]);
functionalCheck($common->statusTag($pointsFirst) === '重试中' && $pointsUpdates['nextExecute'] === $pointsClock + 300,
    'Points acceptance was not scheduled as pending settlement');
settlementSubmitted($pointsConfig['_bilibili_verification']['points_submitted'] ?? null, ['sign', 'bonus'],
    'Initial points mutations were not persisted');
functionalCheck($pointsConfig['add_coin_num'] === 3, 'Points verification overwrote user configuration');

$pointsHelper->result['points_submitted'] = ['privilege', 'dress-view', 'bonus', 'bonus', 'tvodbuy', ['taskCode' => 'ogvwatchnew']];
$pointsClock += 300;
$pointsRetry = $pointsExecutor->execute('vippoints', $account, $pointsConfig);
settlementSubmitted(end($pointsHelper->configs)['points_submitted'] ?? null, ['sign', 'bonus'], 'Verification did not forward submitted points operations');
functionalCheck(!empty(end($pointsHelper->configs)['verification_only']), 'Points verification was treated as the initial run');
$pointsUpdates = BilibiliTaskExecutor::jobUpdates($pointsRetry, $pointsConfig, '42', '09:00', $pointsClock);
$pointsConfig = unserialize($pointsUpdates['data'], ['allowed_classes' => false]);
settlementSubmitted($pointsConfig['_bilibili_verification']['points_submitted'] ?? null, ['sign', 'bonus', 'privilege', 'dress-view'],
    'Points submission union lost earlier actions or retained unsupported task codes');
$pointsHelper->result['points_submitted'] = [];
$pointsClock += 300;
$readOnly = $pointsExecutor->execute('vippoints', $account, $pointsConfig);
settlementSubmitted($readOnly['verification_state']['points_submitted'] ?? null, ['sign', 'bonus', 'privilege', 'dress-view'],
    'A read-only result dropped the prior submitted set');

$pointsLimitConfig = [];
for ($attempt = 0; $attempt <= 24; $attempt++) {
    $pointsHelper->result['points_submitted'] = ['sign'];
    $pointsResult = $pointsExecutor->execute('vippoints', $account, $pointsLimitConfig);
    $pointsUpdates = BilibiliTaskExecutor::jobUpdates($pointsResult, $pointsLimitConfig, '42', '09:00', $pointsClock);
    $pointsLimitConfig = unserialize($pointsUpdates['data'], ['allowed_classes' => false]);
    functionalCheck($pointsResult['code'] === 0, 'Unconfirmed points became successful after repeated checks');
    if ($attempt < 24) {
        functionalCheck($pointsUpdates['nextExecute'] === $pointsClock + 300
            && $pointsLimitConfig['_bilibili_verification']['attempt'] === $attempt + 1, 'Points verification lost its bounded five-minute cadence');
        settlementSubmitted($pointsLimitConfig['_bilibili_verification']['points_submitted'] ?? null, ['sign'],
            'Points submission state changed during a settlement-only pass');
    } else {
        functionalCheck(!isset($pointsResult['retry_after_seconds']) && !isset($pointsLimitConfig['_bilibili_verification'])
            && $common->statusTag($pointsResult) === '失败', 'Points verification exceeded its 24-check bound');
    }
    $pointsClock += 300;
}

$stalePoints = ['_bilibili_verification' => [
    'date' => '2026-11-11', 'attempt' => 12, 'points_submitted' => ['sign', 'bonus', 'privilege', 'dress-view'],
]];
$pointsClock = strtotime('2026-11-12 09:00:00');
$nextDayPoints = $pointsExecutor->execute('vippoints', $account, $stalePoints);
settlementSubmitted(end($pointsHelper->configs)['points_submitted'] ?? null, [], 'Yesterday points submissions suppressed today actions');
functionalCheck(empty(end($pointsHelper->configs)['verification_only']) && $nextDayPoints['verification_state']['attempt'] === 1,
    'Points date reset inherited yesterday attempt count');
settlementSubmitted($nextDayPoints['verification_state']['points_submitted'] ?? null, ['sign'], 'Yesterday task set leaked into today verification');
$pointsClock = strtotime('2026-11-12 23:58:00');
$pointsMidnight = $pointsExecutor->execute('vippoints', $account);
functionalCheck(!isset($pointsMidnight['retry_after_seconds']) && $common->statusTag($pointsMidnight) === '失败',
    'Points verification crossed the next-day reward reset');

$pointsClock = strtotime('2026-11-11 09:00:00');
$pointsHelper->result = ['code' => 1, 'message' => '大会员积分余额 343'];
$pointsSuccess = $pointsExecutor->execute('vippoints', $account, $stalePoints);
$successConfig = unserialize(BilibiliTaskExecutor::jobUpdates($pointsSuccess, $stalePoints, '42', '09:00', $pointsClock)['data'], ['allowed_classes' => false]);
functionalCheck($common->statusTag($pointsSuccess) === '成功' && !isset($successConfig['_bilibili_verification']),
    'Points settlement did not clear its persisted verification state');
$pointsHelper->cookiezt = true;
$pointsHelper->result = ['code' => 0, 'pending_verification' => true, 'points_submitted' => ['sign'], 'message' => '账号未登录'];
$invalidPoints = $pointsExecutor->execute('vippoints', $account, $stalePoints);
functionalCheck($invalidPoints['account_invalid'] && !isset($invalidPoints['retry_after_seconds']), 'Expired points credentials kept retrying');
$pointsHelper->cookiezt = false;

// Exercise the real main scheduler against an isolated database, including its
// result conversion, job serialization, status prefix and notification boundary.
fixtureTable('accounts', 'id INTEGER PRIMARY KEY, uid INTEGER, type TEXT, user_id TEXT, state INTEGER, timing TEXT, data TEXT');
fixtureTable('tasks', 'id INTEGER PRIMARY KEY, type TEXT, execute_name TEXT, state INTEGER, vip INTEGER, name TEXT,
    describe TEXT, icon TEXT, execute_url TEXT, execute_rate TEXT, more INTEGER, time TEXT, "order" INTEGER');
fixtureTable('jobs', 'id INTEGER PRIMARY KEY, uid INTEGER, zid INTEGER NOT NULL DEFAULT 1, type TEXT, user_id TEXT, do TEXT, state INTEGER, nextExecute INTEGER, lastExecute TEXT, data TEXT');
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

// Use the real existing-install task sync and account refresh to prove that the
// new task is reachable, rather than inserting the new protocol name by hand.
functionalCheck(Db::name('tasks')->where('execute_name', 'vippoints')->count() === 0, 'Points task fixture was unexpectedly preseeded');
app\index\model\Jobs::refreshJob('bilibili', '42', 1);
$pointsDefinition = Db::name('tasks')->where('type', 'bilibili')->where('execute_name', 'vippoints')->find();
$pointsSeeded = app\index\model\Jobs::getJobInfo('bilibili', '42', 'vippoints', 1);
functionalCheck($pointsDefinition && (int)$pointsDefinition['state'] === 1 && (int)$pointsDefinition['vip'] === 0
    && $pointsDefinition['execute_url'] === null && (int)$pointsDefinition['execute_rate'] === 86400,
    'Existing-install sync did not publish the new daily points task');
functionalCheck($pointsSeeded && (int)$pointsSeeded['uid'] === 1 && (int)$pointsSeeded['state'] === 1
    && (int)$pointsSeeded['nextExecute'] > 0, 'Account refresh did not create a scheduled points job');
app\index\model\Jobs::refreshJob('bilibili', '42', 1);
functionalCheck(Db::name('tasks')->where('type', 'bilibili')->where('execute_name', 'vippoints')->count() === 1
    && Db::name('jobs')->where('uid', 1)->where('type', 'bilibili')->where('user_id', '42')->where('do', 'vippoints')->count() === 1,
    'Repeated task sync duplicated points tasks or jobs');
$pointsJobId = (int)$pointsSeeded['id'];
$pointsTaskName = (string)$pointsDefinition['name'];
Db::name('jobs')->where('id', $pointsJobId)->update(['nextExecute' => time() - 1, 'data' => serialize(['add_coin_num' => 3])]);
$task->executor = new BilibiliTaskExecutor($pointsFactory, static fn(): int => strtotime(date('Y-m-d') . ' 09:00:00'));
$pointsHelper->result = ['code' => 0, 'pending_verification' => true, 'points_submitted' => ['sign', 'bonus'], 'message' => '积分到账状态尚未确认'];
$pointsSummary = ['attempted' => 0, 'failed' => 0, 'succeeded' => 0];
$pointsJob = Db::name('jobs')->find($pointsJobId);
$run->invokeArgs($task, [$pointsJob, &$pointsSummary]);
$pointsSaved = Db::name('jobs')->find($pointsJobId);
$pointsSavedConfig = unserialize($pointsSaved['data'], ['allowed_classes' => false]);
settlementSubmitted($pointsSavedConfig['_bilibili_verification']['points_submitted'] ?? null, ['sign', 'bonus'],
    'Main scheduler lost submitted points actions while serializing the job');
functionalCheck($pointsSummary['failed'] === 1 && abs($pointsSaved['nextExecute'] - time() - 300) < 5,
    'Main scheduler did not schedule points settlement in five minutes');
functionalCheck(str_starts_with(Db::name('task_logs')->where('do', $pointsTaskName)->order('id', 'desc')->value('response'), '[重试中]')
    && $common->statusTag(end($notifications->results)) === '重试中', 'Points pending status was lost in logs or notifications');

$pointsHelper->result['points_submitted'] = ['privilege', 'dress-view'];
Db::name('jobs')->where('id', $pointsJobId)->update(['nextExecute' => time() - 1]);
$pointsJob = Db::name('jobs')->find($pointsJobId);
$run->invokeArgs($task, [$pointsJob, &$pointsSummary]);
settlementSubmitted(end($pointsHelper->configs)['points_submitted'] ?? null, ['sign', 'bonus'],
    'Main scheduler did not forward the persisted points state to verification');
$pointsSavedConfig = unserialize(Db::name('jobs')->where('id', $pointsJobId)->value('data'), ['allowed_classes' => false]);
settlementSubmitted($pointsSavedConfig['_bilibili_verification']['points_submitted'] ?? null, ['sign', 'bonus', 'privilege', 'dress-view'],
    'Main scheduler overwrote previous points submissions when a later task became ready');
functionalCheck($pointsSavedConfig['_bilibili_verification']['attempt'] === 2 && $pointsSavedConfig['add_coin_num'] === 3,
    'Main scheduler reset the points retry count or user options');

$pointsHelper->result = ['code' => 1, 'message' => '大会员积分余额 343'];
Db::name('jobs')->where('id', $pointsJobId)->update(['nextExecute' => time() - 1]);
$pointsJob = Db::name('jobs')->find($pointsJobId);
$run->invokeArgs($task, [$pointsJob, &$pointsSummary]);
$pointsSavedConfig = unserialize(Db::name('jobs')->where('id', $pointsJobId)->value('data'), ['allowed_classes' => false]);
functionalCheck($pointsSummary['succeeded'] === 1 && !isset($pointsSavedConfig['_bilibili_verification']),
    'Main scheduler retained points verification after confirmed completion');
functionalCheck(str_starts_with(Db::name('task_logs')->where('do', $pointsTaskName)->order('id', 'desc')->value('response'), '[成功]')
    && $common->statusTag(end($notifications->results)) === '成功', 'Points final status was lost in logs or notifications');

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
Db::name('tasks')->insert(['id' => 20, 'type' => 'bilibili', 'execute_name' => 'coinadd', 'state' => 1, 'vip' => 1, 'name' => '每日投币']);
Db::name('jobs')->insert(['id' => 20, 'uid' => 1, 'zid' => 1, 'type' => 'bilibili', 'user_id' => '42',
    'do' => 'coinadd', 'state' => 1, 'nextExecute' => 0, 'data' => serialize([])]);
fixtureRequest(['user_id' => '42', 'do' => 'coinadd', 'config' => '{"add_coin_mode":"random","add_coin_num":3}']);
$retained = json_decode((new app\index\controller\Ajax())->bilibili('set')->getContent(), true);
functionalCheck((int)($retained['code'] ?? -1) === 1, 'The console endpoint stopped serving retained daily tasks');
functionalCheck(str_contains((string)Db::name('jobs')->where('id', 20)->value('data'), 'add_coin_num'),
    'The console endpoint no longer stores retained task configuration');

echo "Bilibili settlement tests passed\n";
