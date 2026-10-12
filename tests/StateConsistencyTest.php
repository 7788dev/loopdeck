<?php

declare(strict_types=1);

require __DIR__ . '/FunctionalDatabaseBootstrap.php';

use app\cron\controller\Common;
use app\cron\controller\Task;
use app\index\model\Accounts;
use app\index\model\Jobs;
use app\service\EpicSubscription;
use app\service\NotificationService;
use app\service\SchedulerRetry;
use app\service\UserNotificationSettings;
use think\facade\Db;
use think\facade\Session;

date_default_timezone_set('Asia/Shanghai');
$app->config->set(['interval' => 50], 'sys');

final class ConsistencyNeteaseFixture
{
    public bool $cookiezt = false;
    public static int $calls = 0;
    public static int $forgotten = 0;
    public function __construct(...$args) {}
    public function sign(): array { self::$calls++; return ['code' => 200, 'message' => 'fixture success']; }
    public function forgetDakaState(): void { self::$forgotten++; }
}
class_alias(ConsistencyNeteaseFixture::class, 'netease\Netease');

fixtureTable('accounts', 'id INTEGER PRIMARY KEY, uid INTEGER, zid INTEGER DEFAULT 1, type TEXT,
    user_id TEXT, state INTEGER, timing TEXT, cooling INTEGER DEFAULT 0, data TEXT, addtime TEXT');
fixtureTable('tasks', 'id INTEGER PRIMARY KEY, type TEXT, execute_name TEXT, name TEXT, describe TEXT,
    icon TEXT, execute_rate INTEGER, execute_url TEXT, more INTEGER, state INTEGER, vip INTEGER, time TEXT, "order" INTEGER');
fixtureTable('jobs', 'id INTEGER PRIMARY KEY, uid INTEGER, zid INTEGER DEFAULT 1, type TEXT, user_id TEXT,
    do TEXT, state INTEGER, nextExecute INTEGER, lastExecute TEXT, data TEXT');
fixtureTable('task_logs', 'id INTEGER PRIMARY KEY, type TEXT, user_id TEXT, do TEXT, response TEXT, addtime TEXT');

function consistencySeed(string $type = 'netease', string $task = 'sign', array $config = []): void
{
    foreach (['jobs', 'accounts', 'tasks', 'task_logs'] as $table) {
        Db::execute('DELETE FROM qa_' . $table);
    }
    Db::name('users')->where('uid', 1)->update(['vip_start' => date('Y-m-d'), 'vip_end' => '2099-12-31']);
    Session::set('user', Db::name('users')->find(1));
    Db::name('accounts')->insert(['id' => 1, 'uid' => 1, 'type' => $type, 'user_id' => '42', 'state' => 1,
        'timing' => '09:00', 'data' => serialize(['user_id' => '42', 'csrf' => 'fixture', 'musicu' => 'fixture'])]);
    Db::name('tasks')->insert(['id' => 1, 'type' => $type, 'execute_name' => $task, 'name' => $task, 'state' => 1, 'vip' => 0]);
    Db::name('jobs')->insert(['id' => 1, 'uid' => 1, 'type' => $type, 'user_id' => '42', 'do' => $task,
        'state' => 1, 'nextExecute' => time() - 1, 'data' => serialize($config)]);
    Db::name('task_logs')->insert(['id' => 1, 'type' => $type, 'user_id' => '42', 'do' => $task,
        'response' => 'fixture history', 'addtime' => date('Y-m-d H:i:s')]);
}

$failures = [];
$check = static function (string $name, Closure $case) use (&$failures): void {
    try {
        $case();
        echo "PASS {$name}\n";
    } catch (Throwable $exception) {
        $failures[] = $name . ': ' . $exception->getMessage();
        echo "FAIL " . end($failures) . "\n";
    } finally {
        Jobs::releaseDueJob(1);
        Db::execute('DROP TRIGGER IF EXISTS reject_job_delete');
    }
};

$check('renewal survives a stale unified-scheduler snapshot', static function (): void {
    consistencySeed();
    Db::name('tasks')->where('id', 1)->update(['vip' => 1]);
    Db::name('users')->where('uid', 1)->update(['vip_end' => '2000-01-01']);
    $runner = new Task();
    $notifications = new class extends NotificationService {
        public function __construct() {}
        public function sendVipExpired(mixed $user): array { return []; }
        public function recordTask(mixed $user, string $type, string $accountId, string $taskKey, string $taskName, array $result): void {}
    };
    (new ReflectionProperty(Task::class, 'notificationService'))->setValue($runner, $notifications);
    (new ReflectionMethod(Task::class, 'user'))->invoke($runner, 1);
    // Redemption commits after the batch cached the user, before this VIP job starts.
    Db::name('users')->where('uid', 1)->update(['vip_end' => '2099-12-31']);
    $summary = ['disabled' => 0, 'vip_expired' => 0, 'attempted' => 0, 'failed' => 0, 'succeeded' => 0];
    (new ReflectionMethod(Task::class, 'runJob'))->invokeArgs($runner, [Db::name('jobs')->find(1), &$summary]);
    functionalCheck(Db::name('users')->where('uid', 1)->value('vip_end') === '2099-12-31', 'Renewed membership was erased');
    functionalCheck((int)Db::name('jobs')->where('id', 1)->value('state') === 1 && $summary['succeeded'] === 1,
        'A renewed owner was still paused by the stale expiry decision');
});

$check('legacy expiry rechecks current membership', static function (): void {
    consistencySeed();
    (new Common())->vipExpired('netease', 1, '42');
    functionalCheck(Db::name('users')->where('uid', 1)->value('vip_end') === '2099-12-31', 'Legacy expiry erased a current renewal');
    functionalCheck((int)Db::name('jobs')->where('id', 1)->value('state') === 1, 'Legacy expiry paused a renewed owner');
});

$check('repeated expiry sends only one notification', static function (): void {
    consistencySeed();
    Db::name('users')->where('uid', 1)->update(['vip_end' => '2000-01-01']);
    $notifications = new class extends NotificationService {
        public int $sent = 0;
        public function __construct() {}
        public function sendVipExpired(mixed $user): array { $this->sent++; return []; }
    };
    $runner = new Task();
    (new ReflectionProperty(Task::class, 'notificationService'))->setValue($runner, $notifications);
    $expire = new ReflectionMethod(Task::class, 'expireVip');
    $expire->invoke($runner, 'netease', 1, '42');
    $expire->invoke($runner, 'netease', 1, '42');
    functionalCheck($notifications->sent === 1, 'Repeated expiry queued duplicate notifications');
});

foreach (['netease' => ['daka_new', ['daka_limit' => 100], ['daka_limit' => 200]],
    'bilibili' => ['coinadd', ['add_coin_mode' => 'random', 'add_coin_num' => 1], ['add_coin_mode' => 'fixed', 'add_coin_num' => 2]]] as $type => [$task, $old, $new]) {
    $check($type . ' config cannot race a running task', static function () use ($type, $task, $old, $new): void {
        consistencySeed($type, $task, $old);
        functionalCheck(Jobs::claimDueJob(1, (int)Db::name('jobs')->value('nextExecute')), 'Could not claim fixture');
        fixtureRequest(['user_id' => '42', 'do' => $task, 'config' => json_encode($new)]);
        $class = 'app\\index\\controller\\' . ucfirst($type);
        $response = (new $class())->handle('set')->getData();
        functionalCheck($response['code'] !== 1, 'Configuration save reported success while the worker owns the job');
        functionalCheck(safe_unserialize_array(Db::name('jobs')->value('data')) === $old, 'Running configuration was overwritten');
        Jobs::releaseDueJob(1);
        functionalCheck((new $class())->handle('set')->getData()['code'] === 1, 'Idle configuration could not be saved');
        functionalCheck(safe_unserialize_array(Db::name('jobs')->value('data')) === $new, 'Idle configuration did not persist');
    });
}

$check('configuration saves retain pending verification', static function (): void {
    $state = ['_bilibili_verification' => ['date' => date('Y-m-d'), 'attempt' => 2, 'claim_submitted' => true],
        SchedulerRetry::DATA_KEY => ['attempts' => 3, 'at' => time()]];
    consistencySeed('bilibili', 'watchaid', $state);
    fixtureRequest(['user_id' => '42', 'do' => 'watchaid', 'config' => '{}']);
    functionalCheck((new app\index\controller\Bilibili())->handle('set')->getData()['code'] === 1, 'Idle save failed');
    functionalCheck(safe_unserialize_array(Db::name('jobs')->value('data')) === $state, 'Save discarded submitted-claim or retry state');
});

$check('selected jobs reload options after claiming', static function (): void {
    consistencySeed('netease', 'sign', ['daka_limit' => 100]);
    $selected = Db::name('jobs')->find(1);
    fixtureRequest(['user_id' => '42', 'do' => 'sign', 'config' => '{"daka_limit":200}']);
    functionalCheck((new app\index\controller\Netease())->handle('set')->getData()['code'] === 1, 'Idle save failed');
    $summary = ['disabled' => 0, 'vip_expired' => 0, 'attempted' => 0, 'failed' => 0, 'succeeded' => 0];
    (new ReflectionMethod(Task::class, 'runJob'))->invokeArgs(new Task(), [$selected, &$summary]);
    functionalCheck((safe_unserialize_array(Db::name('jobs')->value('data'))['daka_limit'] ?? 0) === 200,
        'A job selected before the save overwrote the new options');
});

$check('Epic preference saves retain retry state', static function (): void {
    $retry = ['attempts' => 3, 'at' => time()];
    consistencySeed('epic', 'weeklyGameNotify', ['timing' => '09:00', SchedulerRetry::DATA_KEY => $retry]);
    EpicSubscription::sync(1, 1, array_replace_recursive(UserNotificationSettings::defaults(), ['enabled' => true]));
    functionalCheck((safe_unserialize_array(Db::name('jobs')->value('data'))[SchedulerRetry::DATA_KEY] ?? null) === $retry,
        'Saving unrelated preferences reset the failure budget');
});

foreach (['netease', 'bilibili'] as $type) {
    $check($type . ' deletion preserves another owner and shared history', static function () use ($type): void {
        consistencySeed($type);
        fixtureRequest(['user_id' => '42']);
        Session::set('user.uid', 2);
        $class = 'app\\index\\controller\\' . ucfirst($type);
        functionalCheck((new $class())->handle('delete')->getData()['code'] !== 1, 'A foreign account was deleted');
        functionalCheck(Db::name('accounts')->count() === 1 && Db::name('jobs')->count() === 1 && Db::name('task_logs')->count() === 1,
            'Foreign deletion changed stored data');
        Session::set('user.uid', 1);
        Db::name('accounts')->insert(['id' => 2, 'uid' => 2, 'zid' => 1, 'type' => $type, 'user_id' => '42', 'state' => 1]);
        functionalCheck((new $class())->handle('delete')->getData()['code'] === 1, 'Owned deletion failed');
        functionalCheck(Db::name('accounts')->where('uid', 2)->count() === 1 && Db::name('task_logs')->count() === 1,
            'Cleanup removed another legacy owner or its shared history');
    });
    $check($type . ' account deletion rolls back on a child-write failure', static function () use ($type): void {
        consistencySeed($type);
        $forgotten = ConsistencyNeteaseFixture::$forgotten;
        Db::execute("CREATE TRIGGER reject_job_delete BEFORE DELETE ON qa_jobs BEGIN SELECT RAISE(FAIL, 'injected delete failure'); END");
        fixtureRequest(['user_id' => '42']);
        $class = 'app\\index\\controller\\' . ucfirst($type);
        try { (new $class())->handle('delete'); } catch (Throwable $exception) {}
        functionalCheck(Db::name('accounts')->count() === 1 && Db::name('jobs')->count() === 1 && Db::name('task_logs')->count() === 1,
            'Account credentials or history were deleted before the failed transaction completed');
        functionalCheck(ConsistencyNeteaseFixture::$forgotten === $forgotten, 'Daily state was removed before commit');
        Db::execute('DROP TRIGGER reject_job_delete');
        functionalCheck((new $class())->handle('delete')->getData()['code'] === 1, 'Deletion could not be retried');
        functionalCheck(Db::name('accounts')->count() === 0 && Db::name('jobs')->count() === 0 && Db::name('task_logs')->count() === 0,
            'Successful deletion left children behind');
    });
}

$check('NetEase CLI respects inactive accounts', static function (): void {
    consistencySeed();
    Db::name('accounts')->where('id', 1)->update(['state' => 0]);
    $before = ConsistencyNeteaseFixture::$calls;
    $command = new app\command\Netease();
    $input = new think\console\Input([]);
    $input->bind($command->getDefinition());
    (new ReflectionMethod($command, 'execute'))->invoke($command, $input, new think\console\Output());
    functionalCheck(ConsistencyNeteaseFixture::$calls === $before, 'CLI executed an inactive account');
});

$check('Bilibili CLI retains user-paused jobs', static function (): void {
    consistencySeed('bilibili', 'watchaid');
    Db::name('accounts')->where('id', 1)->update(['state' => 0]);
    Db::name('jobs')->insert(['id' => 2, 'uid' => 1, 'type' => 'bilibili', 'user_id' => '42', 'do' => 'coinadd', 'state' => 0, 'nextExecute' => 0]);
    $command = new app\command\Bilibili();
    $input = new think\console\Input([]);
    $input->bind($command->getDefinition());
    (new ReflectionMethod($command, 'execute'))->invoke($command, $input, new think\console\Output());
    functionalCheck((int)Db::name('jobs')->where('id', 2)->value('state') === 0, 'Paused job became resumable after account login');
});

if ($failures !== []) {
    throw new RuntimeException(implode("\n", $failures));
}
echo "State consistency tests passed\n";
