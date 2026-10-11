<?php
declare(strict_types=1);

/**
 * Regression tests for bounded scheduler retries and lease-guarded
 * disable writes.
 *
 * Covers: a poisoned job (adapter always throwing) stops retrying at the
 * configured cap with one final `[失败]` record instead of retrying forever;
 * the failure counter is persisted in the job payload and expires with its
 * window; disabling a claimed job compares the lease so a plan the user
 * saved mid-run is never clobbered.
 */

require __DIR__ . '/FunctionalDatabaseBootstrap.php';

use app\cron\controller\Task;
use app\index\model\Jobs;
use app\service\BilibiliTaskExecutor;
use app\service\NotificationService;
use app\service\SchedulerRetry;
use think\facade\Db;

date_default_timezone_set('Asia/Shanghai');
$app->setRuntimePath(sys_get_temp_dir() . '/loopdeck-retry-cap-' . bin2hex(random_bytes(6)) . '/');
$app->config->set(['interval' => 50, 'reExecute_time' => 300], 'sys');
putenv('SCHEDULER_MAX_RETRIES=3');
putenv('SCHEDULER_RETRY_JITTER_SECONDS=0');

final class ThrowingNetease
{
    public bool $cookiezt = false;
    public static int $calls = 0;
    /** When false the fixture client succeeds, exercising the success path. */
    public static bool $broken = true;
    public function __construct(...$args) {}
    public function sign(): array
    {
        self::$calls++;
        if (self::$broken) {
            throw new RuntimeException('fixture upstream broken');
        }

        return ['code' => 200, 'message' => '签到成功'];
    }
}
class_alias(ThrowingNetease::class, 'netease\Netease');

final class RetryCountingNotifications extends NotificationService
{
    public static int $recorded = 0;

    public function __construct() {}
    public function sendVipExpired(mixed $user): array { return []; }
    public function recordTask(mixed $user, string $type, string $accountId, string $taskKey, string $taskName, array $result): void
    {
        self::$recorded++;
    }
}

fixtureTable('accounts', 'id INTEGER PRIMARY KEY, uid INTEGER, zid INTEGER DEFAULT 1, type TEXT,
    user_id TEXT, state INTEGER, timing TEXT, cooling INTEGER DEFAULT 0, data TEXT');
fixtureTable('tasks', 'id INTEGER PRIMARY KEY, type TEXT, execute_name TEXT, name TEXT, describe TEXT,
    icon TEXT, execute_rate INTEGER, execute_url TEXT, more INTEGER, state INTEGER, vip INTEGER, time TEXT, "order" INTEGER');
fixtureTable('jobs', 'id INTEGER PRIMARY KEY, uid INTEGER, zid INTEGER DEFAULT 1, type TEXT, user_id TEXT,
    do TEXT, state INTEGER, nextExecute INTEGER, lastExecute TEXT, data TEXT');
fixtureTable('task_logs', 'id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, user_id TEXT, do TEXT, response TEXT, addtime TEXT');

Db::name('accounts')->insert(['id' => 1, 'uid' => 1, 'type' => 'netease', 'user_id' => '42',
    'state' => 1, 'timing' => '09:00', 'data' => serialize(['user_id' => '42', 'csrf' => 'fixture', 'musicu' => 'fixture'])]);
Db::name('tasks')->insert(['id' => 1, 'type' => 'netease', 'execute_name' => 'sign', 'name' => 'sign',
    'vip' => 0, 'state' => 1]);
Db::name('jobs')->insert(['id' => 1, 'uid' => 1, 'type' => 'netease', 'user_id' => '42', 'do' => 'sign',
    'state' => 1, 'nextExecute' => time() - 1, 'data' => serialize([])]);

function retryRunner(): Task
{
    $runner = new Task();
    $property = new ReflectionProperty(Task::class, 'notificationService');
    $property->setAccessible(true);
    $property->setValue($runner, new RetryCountingNotifications());
    return $runner;
}

$run = new ReflectionMethod(Task::class, 'runJob');
$run->setAccessible(true);
$runner = retryRunner();

// --- H1: a poisoned job must stop retrying at the cap --------------------

$summary = ['attempted' => 0, 'failed' => 0, 'succeeded' => 0, 'disabled' => 0, 'unscheduled' => 0];
for ($round = 1; $round <= 5; $round++) {
    $run->invokeArgs($runner, [Db::name('jobs')->find(1), &$summary]);
    // Simulate the retry cooldown elapsing before the next scheduler tick.
    Db::name('jobs')->where('id', 1)->where('state', 1)->update(['nextExecute' => time() - 1]);
}

functionalCheck(ThrowingNetease::$calls === 4,
    'A poisoned job kept calling upstream past the retry cap (calls: ' . ThrowingNetease::$calls . ')');
functionalCheck((int)Db::name('jobs')->where('id', 1)->value('state') === 0
    && (int)Db::name('jobs')->where('id', 1)->value('nextExecute') === 0,
    'A capped job was not disabled with a cleared schedule');
$settledData = safe_unserialize_array((string)Db::name('jobs')->where('id', 1)->value('data'));
functionalCheck(!isset($settledData[SchedulerRetry::DATA_KEY]),
    'A finalized job kept its stale retry counter in the payload');
functionalCheck((int)Db::name('task_logs')->count() === 4,
    'Retry rounds did not produce exactly three interim logs and one final record');
functionalCheck((int)Db::name('task_logs')->where('response', 'like', '[重试中]%')->count() === 3,
    'Interim retry logs were not written for every rescheduled round');
$finalLog = (array)Db::name('task_logs')->where('response', 'like', '[失败]%')->find();
functionalCheck($finalLog !== [] && str_contains((string)($finalLog['response'] ?? ''), '已连续失败 4 次'),
    'The final record does not carry the bounded-retry failure copy');
functionalCheck((string)($finalLog['do'] ?? '') === 'sign' && (string)($finalLog['user_id'] ?? '') === '42',
    'The final record lost the job identity');
functionalCheck(RetryCountingNotifications::$recorded === 3,
    'The interim "will retry" notification must not fire for the finalized round');

// The finalized job must not be re-claimed by the next scheduler tick.
$run->invokeArgs($runner, [Db::name('jobs')->find(1), &$summary]);
functionalCheck(ThrowingNetease::$calls === 4 && (int)Db::name('task_logs')->count() === 4,
    'A finalized job was picked up again by the scheduler');

// --- SchedulerRetry unit behavior -----------------------------------------

putenv('SCHEDULER_RETRY_WINDOW_SECONDS=60');
$stale = ['_scheduler_retry' => ['attempts' => 99, 'at' => time() - 3600]];
$next = SchedulerRetry::dataAfterFailure($stale);
functionalCheck(is_array($next) && $next[SchedulerRetry::DATA_KEY]['attempts'] === 1,
    'A failure after the retry window did not start a fresh count');
functionalCheck(SchedulerRetry::dataAfterFailure($stale) !== $stale,
    'The original job payload was mutated by the retry planner');

$fresh = [];
$last = null;
for ($i = 0; $i < 4; $i++) {
    $last = SchedulerRetry::dataAfterFailure($fresh);
    if ($last !== null) {
        $fresh = $last;
    }
}
functionalCheck($last === null, 'A fresh failure streak did not reach the cap');
functionalCheck(SchedulerRetry::attemptsIn($fresh) === 3, 'The recorded attempt count drifted');
functionalCheck(SchedulerRetry::settledData($fresh) === [], 'The settled payload kept the retry tracker');
functionalCheck(str_contains(SchedulerRetry::finalMessage(4), '已连续失败 4 次')
    && str_contains(SchedulerRetry::finalMessage(4), '停用'),
    'The final message copy changed');

putenv('SCHEDULER_MAX_RETRIES=999');
functionalCheck(SchedulerRetry::cap() === 100, 'The retry cap is not clamped from above');
putenv('SCHEDULER_MAX_RETRIES=0');
functionalCheck(SchedulerRetry::cap() === 1, 'The retry cap is not clamped from below');
putenv('SCHEDULER_MAX_RETRIES=3');

// --- M1: disabling a claimed job must compare the lease -------------------

$disable = new ReflectionMethod(Task::class, 'disableClaimedJob');
$disable->setAccessible(true);

$due = time() - 10;
Db::name('jobs')->where('id', 1)->update(['state' => 1, 'nextExecute' => $due, 'data' => serialize([])]);
functionalCheck(Jobs::claimDueJob(1, $due), 'Fixture claim failed');
Db::name('jobs')->where('id', 1)->update(['nextExecute' => time() + 86400]); // user re-planned mid-run
functionalCheck($disable->invoke($runner, 1, 'netease', '42', 'sign', 'x') === false,
    'Disabling a re-planned lease reported success');
functionalCheck((int)Db::name('jobs')->where('id', 1)->value('state') === 1
    && (int)Db::name('jobs')->where('id', 1)->value('nextExecute') > time(),
    'A disable write clobbered the plan the user saved mid-run');
functionalCheck((int)Db::name('task_logs')->count() === 4,
    'A taken-over disable still wrote its failure log');

Jobs::releaseDueJob(1);
$due = time() - 10;
Db::name('jobs')->where('id', 1)->update(['nextExecute' => $due]);
functionalCheck(Jobs::claimDueJob(1, $due), 'Second fixture claim failed');
functionalCheck($disable->invoke($runner, 1, 'netease', '42', 'sign', 'disable under lease') === true,
    'Disabling a healthy claimed lease failed');
functionalCheck((int)Db::name('jobs')->where('id', 1)->value('state') === 0
    && (int)Db::name('jobs')->where('id', 1)->value('nextExecute') === 0,
    'A lease-guarded disable did not clear the job');
functionalCheck((int)Db::name('task_logs')->where('response', 'like', '[失败] disable under lease')->count() === 1,
    'The disable record lost its [失败] prefix or message');
Jobs::releaseDueJob(1);

// --- M2: a retry that never lands must not promise one ---------------------

$retry = new ReflectionMethod(Task::class, 'retryJob');
$retry->setAccessible(true);

// User re-planned mid-run: the lease-guarded retry write lands nowhere, so the
// caller must not write the interim "will retry" copy (and must not clobber
// the plan).
Jobs::releaseDueJob(1);
$due = time() - 10;
Db::name('jobs')->where('id', 1)->update(['state' => 1, 'nextExecute' => $due, 'data' => serialize([])]);
functionalCheck(Jobs::claimDueJob(1, $due), 'Fixture claim failed (M2)');
Db::name('jobs')->where('id', 1)->update(['nextExecute' => time() + 86400]);
$logsBefore = (int)Db::name('task_logs')->count();
functionalCheck($retry->invoke($runner, 1) === false,
    'A retry write that landed nowhere reported a scheduled retry');
functionalCheck((int)Db::name('task_logs')->count() === $logsBefore,
    'A lost-lease retry still wrote the interim "will retry" copy');
functionalCheck((int)Db::name('jobs')->where('id', 1)->value('nextExecute') > time(),
    'A lost-lease retry clobbered the plan the user saved mid-run');
Jobs::releaseDueJob(1);

// Account lost its schedule mid-run: the job is paused, so no retry copy.
$due = time() - 10;
Db::name('jobs')->where('id', 1)->update(['state' => 1, 'nextExecute' => $due]);
functionalCheck(Jobs::claimDueJob(1, $due), 'Fixture claim failed (M2 pause)');
Db::name('accounts')->where('id', 1)->update(['timing' => '']);
$logsBefore = (int)Db::name('task_logs')->count();
functionalCheck($retry->invoke($runner, 1) === false,
    'A retry for an unscheduled account reported a scheduled retry');
functionalCheck((int)Db::name('jobs')->where('id', 1)->value('nextExecute') === 0,
    'An unscheduled retry did not pause the job');
functionalCheck((int)Db::name('task_logs')->count() === $logsBefore,
    'A paused retry still wrote the interim "will retry" copy');
Db::name('accounts')->where('id', 1)->update(['timing' => '09:00']);
Jobs::releaseDueJob(1);

// Lease invalidated mid-run (for example the account was invalidated in
// bulk): the job is finalized elsewhere, so no retry copy may be added.
$due = time() - 10;
Db::name('jobs')->where('id', 1)->update(['state' => 1, 'nextExecute' => $due]);
functionalCheck(Jobs::claimDueJob(1, $due), 'Fixture claim failed (M2 taken over)');
Db::name('jobs')->where('id', 1)->update(['state' => -1]);
$logsBefore = (int)Db::name('task_logs')->count();
functionalCheck($retry->invoke($runner, 1) === false,
    'A retry for an invalidated job reported a scheduled retry');
functionalCheck((int)Db::name('task_logs')->count() === $logsBefore,
    'A taken-over retry still wrote the interim "will retry" copy');
Db::name('jobs')->where('id', 1)->update(['state' => 1]);
Jobs::releaseDueJob(1);

// --- M3: a completed run breaks the consecutive-failure streak -------------

putenv('SCHEDULER_MAX_RETRIES=3');
ThrowingNetease::$broken = false;
Db::name('jobs')->where('id', 1)->update([
    'state' => 1,
    'nextExecute' => time() - 1,
    'data' => serialize([SchedulerRetry::DATA_KEY => ['attempts' => 2, 'at' => time()]]),
]);
$summary = ['attempted' => 0, 'failed' => 0, 'succeeded' => 0, 'disabled' => 0, 'unscheduled' => 0];
$run->invokeArgs($runner, [Db::name('jobs')->find(1), &$summary]);
functionalCheck($summary['succeeded'] === 1,
    'The success-path fixture did not complete its run (summary: ' . json_encode($summary) . ')');
$streakData = safe_unserialize_array((string)Db::name('jobs')->where('id', 1)->value('data'));
functionalCheck(!isset($streakData[SchedulerRetry::DATA_KEY]),
    'A completed run kept the stale consecutive-failure counter in the payload');
ThrowingNetease::$broken = true;

// --- M4: the account-stopped sweep must not resurrect user-disabled jobs ---

Db::name('jobs')->insert(['id' => 2, 'uid' => 1, 'type' => 'netease', 'user_id' => '42',
    'do' => 'sign', 'state' => 0, 'nextExecute' => time() + 3600, 'data' => serialize([])]);
Db::name('jobs')->insert(['id' => 3, 'uid' => 1, 'type' => 'netease', 'user_id' => '42',
    'do' => 'login_work', 'state' => 1, 'nextExecute' => time() + 3600, 'data' => serialize([])]);
Db::name('accounts')->where('id', 1)->update(['state' => 0]);
Db::name('jobs')->where('id', 1)->update(['state' => 1, 'nextExecute' => time() - 1, 'data' => serialize([])]);
$summary = ['attempted' => 0, 'failed' => 0, 'succeeded' => 0, 'disabled' => 0, 'unscheduled' => 0];
$run->invokeArgs(retryRunner(), [Db::name('jobs')->find(1), &$summary]);
functionalCheck((int)Db::name('jobs')->where('id', 1)->value('state') === -1,
    'The claimed job was not marked account-invalid');
functionalCheck((int)Db::name('jobs')->where('id', 3)->value('state') === -1,
    'The enabled sibling job was not marked account-invalid');
functionalCheck((int)Db::name('jobs')->where('id', 2)->value('state') === 0,
    'The account-stopped sweep flipped a user-disabled job to -1 (updateJob would resurrect it)');
Db::name('jobs')->where('id', 'in', [2, 3])->delete();
Db::name('accounts')->where('id', 1)->update(['state' => 1]);

// --- M5: a bilibili completion write drops the retry tracker ---------------

$completion = BilibiliTaskExecutor::jobUpdates(
    ['success' => true, 'message' => 'ok', 'account_invalid' => false],
    ['_bilibili_verification' => ['date' => '2026-10-11'], SchedulerRetry::DATA_KEY => ['attempts' => 3, 'at' => time()]],
    '42',
    '09:00'
);
$completionData = safe_unserialize_array((string)$completion['data']);
functionalCheck(!isset($completionData[SchedulerRetry::DATA_KEY]) && !isset($completionData['_bilibili_verification']),
    'A bilibili completion write kept the stale retry tracker or verification state');

putenv('SCHEDULER_MAX_RETRIES');
putenv('SCHEDULER_RETRY_JITTER_SECONDS');
putenv('SCHEDULER_RETRY_WINDOW_SECONDS');

echo "Scheduler retry cap tests passed: poisoned jobs finalize at the cap, counters expire with their window and disables compare the lease\n";
