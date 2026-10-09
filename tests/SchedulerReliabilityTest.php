<?php
declare(strict_types=1);

require __DIR__ . '/FunctionalDatabaseBootstrap.php';

use app\cron\controller\Task;
use app\index\model\Jobs;
use app\service\AutomaticSchedule;
use app\service\NotificationService;
use app\service\TaskExecutionLock;
use think\facade\Db;

date_default_timezone_set('Asia/Shanghai');
$app->setRuntimePath(sys_get_temp_dir() . '/loopdeck-reliability-' . bin2hex(random_bytes(6)) . '/');
$app->config->set(['interval' => 50, 'reExecute_time' => 300], 'sys');

final class ReliabilityNetease
{
    public bool $cookiezt = false;
    public static int $calls = 0;
    public static int $dakaCalls = 0;
    public function __construct(...$args) {}
    public function vip_growth_task(): array
    {
        self::$calls++;
        return ['code' => 200, 'message' => 'fixture ordinary task'];
    }
    public function daka_new(): array
    {
        self::$dakaCalls++;
        return ['code' => 200, 'message' => 'fixture confirmed 300/300', 'data' => ['retry_after_seconds' => 0]];
    }
}
class_alias(ReliabilityNetease::class, 'netease\Netease');

fixtureTable('accounts', 'id INTEGER PRIMARY KEY, uid INTEGER, zid INTEGER DEFAULT 1, type TEXT,
    user_id TEXT, state INTEGER, timing TEXT, cooling INTEGER DEFAULT 0, data TEXT');
fixtureTable('tasks', 'id INTEGER PRIMARY KEY, type TEXT, execute_name TEXT, name TEXT, describe TEXT,
    icon TEXT, execute_rate INTEGER, execute_url TEXT, more INTEGER, state INTEGER, vip INTEGER, time TEXT, "order" INTEGER');
fixtureTable('jobs', 'id INTEGER PRIMARY KEY, uid INTEGER, zid INTEGER DEFAULT 1, type TEXT, user_id TEXT,
    do TEXT, state INTEGER, nextExecute INTEGER, lastExecute TEXT, data TEXT');
fixtureTable('task_logs', 'id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, user_id TEXT, do TEXT, response TEXT, addtime TEXT');

function reliabilityReset(): void
{
    foreach (['jobs', 'accounts', 'tasks', 'task_logs'] as $table) {
        Db::execute('DELETE FROM qa_' . $table);
    }
}
function reliabilityAccount(string $type = 'netease'): void
{
    Db::name('accounts')->insert(['id' => 1, 'uid' => 1, 'type' => $type, 'user_id' => '42',
        'state' => 1, 'timing' => '09:00', 'data' => serialize(['user_id' => '42', 'csrf' => 'fixture', 'musicu' => 'fixture'])]);
}
function reliabilityTask(int $id, string $type, string $task, int $vip): void
{
    Db::name('tasks')->insert(['id' => $id, 'type' => $type, 'execute_name' => $task, 'name' => $task,
        'vip' => $vip, 'state' => 1]);
}
function reliabilityJob(int $id, string $type, string $task): void
{
    Db::name('jobs')->insert(['id' => $id, 'uid' => 1, 'type' => $type, 'user_id' => '42', 'do' => $task,
        'state' => 1, 'nextExecute' => time() - 1, 'data' => serialize([])]);
}
function reliabilityRunner(): Task
{
    $runner = new Task();
    $notifications = new class extends NotificationService {
        public function __construct() {}
        public function sendVipExpired(mixed $user): array { return []; }
        public function recordTask(mixed $user, string $type, string $accountId, string $taskKey, string $taskName, array $result): void {}
    };
    $property = new ReflectionProperty(Task::class, 'notificationService');
    $property->setAccessible(true);
    $property->setValue($runner, $notifications);
    return $runner;
}

$run = new ReflectionMethod(Task::class, 'runJob');
$run->setAccessible(true);
reliabilityAccount();
reliabilityTask(1, 'netease', 'sign', 1);
reliabilityTask(2, 'netease', 'vip_growth_task', 0);
reliabilityJob(1, 'netease', 'sign');
reliabilityJob(2, 'netease', 'vip_growth_task');
Db::name('users')->where('uid', 1)->update(['vip_start' => '2026-01-01', 'vip_end' => '2026-01-02']);
$runner = reliabilityRunner();
$summary = ['disabled' => 0, 'vip_expired' => 0, 'attempted' => 0, 'failed' => 0, 'succeeded' => 0];
foreach (Db::name('jobs')->order('id')->select() as $job) {
    $run->invokeArgs($runner, [$job, &$summary]);
}
functionalCheck((int)Db::name('jobs')->where('id', 1)->value('state') === 0, 'Expired VIP task stayed enabled');
functionalCheck((int)Db::name('jobs')->where('id', 2)->value('state') === 1 && ReliabilityNetease::$calls === 1,
    'Expired VIP suppressed an ordinary task in the same batch');

reliabilityReset();
reliabilityTask(1, 'netease', 'sign', 0);
reliabilityTask(2, 'archived-fixture', 'sign', 0);
reliabilityJob(1, 'netease', 'sign');
reliabilityJob(2, 'archived-fixture', 'sign');
fixtureRequest(['id' => 1, 'vip' => 1]);
(new app\admin\controller\Ajax())->task('set');
functionalCheck((int)Db::name('jobs')->where('type', 'archived-fixture')->value('state') === 1,
    'An admin task edit crossed the platform boundary');

foreach (['netease', 'bilibili'] as $type) {
    reliabilityReset();
    reliabilityAccount($type);
    $task = $type === 'netease' ? 'sign' : 'watchaid';
    reliabilityTask(1, $type, $task, 0);
    reliabilityJob(1, $type, $task);
    functionalCheck(Jobs::claimDueJob(1, (int)Db::name('jobs')->value('nextExecute')), 'First worker could not claim');
    $lease = (int)Db::name('jobs')->value('nextExecute');
    fixtureRequest(['user_id' => '42']);
    $controller = $type === 'netease' ? new app\index\controller\Netease() : new app\index\controller\Bilibili();
    $controller->handle('reExecute');
    functionalCheck((int)Db::name('jobs')->value('nextExecute') === $lease, 'Manual retry overwrote a running lease');
    functionalCheck(!Jobs::claimDueJob(1, $lease, $lease + 1), 'An active process lost exclusivity when its timestamp lease expired');
    functionalCheck(Jobs::switchState($type, '42', $task) === false, 'A task switch raced with execution');

    $directory = (string)config('app.task_lock_directory');
    $child = 'require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';'
        . 'require ' . var_export(dirname(__DIR__) . '/vendor/topthink/framework/src/helper.php', true) . ';'
        . '$app = new think\\App(' . var_export(dirname(__DIR__) . '/', true) . ');'
        . '$app->config->set(["task_lock_directory"=>' . var_export($directory, true) . '],"app");'
        . 'echo app\\service\\TaskExecutionLock::acquire("job:1") ? "acquired" : "busy";';
    $process = proc_open([PHP_BINARY, '-r', $child], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    functionalCheck(is_resource($process), 'Could not start competing process');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    functionalCheck(proc_close($process) === 0 && $output === 'busy', 'Cross-process task lock failed: ' . $errors);

    Db::name('jobs')->where('id', 1)->update(['nextExecute' => 0]); // User cleared the account schedule.
    functionalCheck(!Jobs::updateClaimedJob(1, ['nextExecute' => time() + 86400]), 'Late worker overwrote a newer user schedule');
    Jobs::releaseDueJob(1);
    functionalCheck(Jobs::requestImmediate([1]) === 1, 'An idle job could not be queued after release');
}

// Full stop/restore flow: scheduler disables the definition, admin restores it,
// then the user enables the job. The enable must also restore its due time.
reliabilityReset();
reliabilityAccount();
reliabilityTask(1, 'netease', 'sign', 0);
reliabilityJob(1, 'netease', 'sign');
Db::name('tasks')->where('id', 1)->update(['state' => 0]);
$job = Db::name('jobs')->find(1);
$summary = ['disabled' => 0, 'failed' => 0];
$run->invokeArgs(reliabilityRunner(), [$job, &$summary]);
fixtureRequest(['id' => 1, 'state' => 1]);
(new app\admin\controller\Ajax())->task('set');
fixtureRequest(['user_id' => '42', 'do' => 'sign', 'act' => 'zt']);
$enabled = (new app\index\controller\Netease())->handle('set')->getData();
$saved = Db::name('jobs')->find(1);
functionalCheck($enabled['code'] === 1 && (int)$saved['state'] === 1 && $saved['nextExecute'] > time(),
    'Re-enabled job has no automatic schedule');

// Earlier completed tasks must not prevent a newly enabled listening task
// from being included in the account's manual retry.
reliabilityReset();
reliabilityAccount();
reliabilityTask(1, 'netease', 'sign', 0);
reliabilityTask(2, 'netease', 'daka_new', 1);
reliabilityTask(3, 'netease', 'musician_task', 1);
reliabilityJob(1, 'netease', 'sign');
reliabilityJob(2, 'netease', 'daka_new');
reliabilityJob(3, 'netease', 'musician_task');
Db::name('users')->where('uid', 1)->update(['vip_start' => date('Y-m-d'), 'vip_end' => '2099-12-31']);
think\facade\Session::set('user', Db::name('users')->find(1));
$tomorrow = time() + 86400;
Db::name('jobs')->where('id', 1)->update(['lastExecute' => date('Y-m-d H:i:s', time() - 600), 'nextExecute' => $tomorrow]);
Db::name('jobs')->whereIn('id', [2, 3])->update(['state' => 0, 'nextExecute' => $tomorrow]);
fixtureRequest(['user_id' => '42', 'do' => 'daka_new', 'act' => 'zt']);
$controller = new app\index\controller\Netease();
functionalCheck($controller->handle('set')->getData()['code'] === 1, 'Afternoon listening enable failed');
fixtureRequest(['user_id' => '42']);
functionalCheck($controller->handle('reExecute')->getData()['code'] === 1, 'Manual retry was rejected after earlier tasks ran');
$dakaJob = Db::name('jobs')->find(2);
functionalCheck((int)$dakaJob['nextExecute'] <= time(), 'Newly enabled listening task was left until tomorrow');
functionalCheck((int)Db::name('jobs')->where('id', 3)->value('nextExecute') === $tomorrow,
    'Retry scheduled a task that was still disabled');
$summary = ['attempted' => 0, 'failed' => 0, 'succeeded' => 0, 'disabled' => 0];
$run->invokeArgs(reliabilityRunner(), [$dakaJob, &$summary]);
functionalCheck(ReliabilityNetease::$dakaCalls === 1 && $summary['succeeded'] === 1
    && Db::name('task_logs')->where('do', 'daka_new')->count() === 1,
    'Newly enabled listening task did not execute and produce its final log');

// Stable daily jitter can cross midnight without turning a daily schedule into 48 hours.
$oldJitter = getenv('SCHEDULER_JITTER_SECONDS');
putenv('SCHEDULER_JITTER_SECONDS=120');
foreach (['netease', 'bilibili'] as $type) {
    foreach (['00:00', '09:00', '23:59'] as $timing) {
        $now = strtotime('2026-10-07 12:00:00');
        for ($day = 0; $day < 30; $day++) {
            $next = AutomaticSchedule::nextExecution($type, '42', $timing, $now + 1);
            if ($day > 0) {
                functionalCheck($next - $now > 23 * 3600 && $next - $now < 25 * 3600,
                    'Daily schedule skipped or repeated a calendar slot: ' . $type . ' ' . $timing);
            }
            $now = $next;
        }
    }
}
putenv($oldJitter === false ? 'SCHEDULER_JITTER_SECONDS' : 'SCHEDULER_JITTER_SECONDS=' . $oldJitter);

reliabilityReset();
reliabilityJob(1, 'bilibili', 'dailybag');
reliabilityJob(2, 'bilibili', 'watchaid');
reliabilityJob(3, 'heybox', 'sign');
Jobs::retireOfflineJobs();
functionalCheck((int)Db::name('jobs')->where('id', 1)->value('state') === 0, 'Retired Bilibili task remained enabled');
functionalCheck((int)Db::name('jobs')->where('id', 3)->value('state') === 0, 'Retired platform remained enabled');
functionalCheck((int)Db::name('jobs')->where('id', 2)->value('state') === 1, 'Maintenance disabled a supported task');
Db::name('jobs')->where('id', 1)->update(['state' => 1, 'nextExecute' => time()]);
Db::execute("CREATE TRIGGER qa_maintenance_failure BEFORE UPDATE ON qa_jobs WHEN OLD.id=1 BEGIN SELECT RAISE(ABORT,'fixture lock conflict'); END");
Jobs::retireOfflineJobs(); // The known failure must not escape and abort the batch.
Db::execute('DROP TRIGGER qa_maintenance_failure');
Jobs::retireOfflineJobs();
functionalCheck((int)Db::name('jobs')->where('id', 1)->value('state') === 0, 'Maintenance did not recover on its next attempt');
echo "Scheduler reliability tests passed: scoped VIP/admin changes, enable recovery, exclusive claims, calendar slots and maintenance isolation\n";

