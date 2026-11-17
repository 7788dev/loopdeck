<?php

declare(strict_types=1);

require __DIR__ . '/FunctionalDatabaseBootstrap.php';
require __DIR__ . '/NotificationTestBootstrap.php';

use agentrouter\Client;
use app\cron\controller\Task;
use app\index\controller\Agentrouter;
use app\index\model\Jobs;
use app\service\AgentRouterTaskExecutor;
use app\service\AutomaticSchedule;
use app\service\NotificationService;
use app\service\TaskExecutionLock;
use app\service\UserNotificationSettings;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use think\facade\Db;

date_default_timezone_set('Asia/Shanghai');
putenv('SCHEDULER_JITTER_SECONDS=0');
$app->setRuntimePath(sys_get_temp_dir() . '/loopdeck-ag-first-schedule-' . bin2hex(random_bytes(6)) . '/');
fixtureTable('accounts', 'id INTEGER PRIMARY KEY, uid INTEGER, zid INTEGER DEFAULT 1, type TEXT, user_id TEXT,
    state INTEGER, timing TEXT, cooling INTEGER DEFAULT 0, addtime TEXT, data TEXT');
fixtureTable('tasks', 'id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, execute_name TEXT, name TEXT, describe TEXT,
    icon TEXT, execute_rate INTEGER, execute_url TEXT, more INTEGER, state INTEGER, vip INTEGER, time TEXT, "order" INTEGER');
fixtureTable('jobs', 'id INTEGER PRIMARY KEY, uid INTEGER, zid INTEGER DEFAULT 1, type TEXT, user_id TEXT,
    do TEXT, state INTEGER, nextExecute INTEGER, lastExecute TEXT, data TEXT');
fixtureTable('task_logs', 'id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, user_id TEXT, do TEXT, response TEXT, addtime TEXT');
Db::name('users')->where('uid', 1)->update(['vip_start' => '2026-01-01', 'vip_end' => '2099-01-01']);
$accountPayload = ['username' => 'first-user', 'password' => 'fixture-credential'];
$jobPayload = ['preserve' => 'saved-option'];
Db::name('accounts')->insert(['id' => 1, 'uid' => 1, 'type' => 'agentrouter', 'user_id' => 'first-user',
    'state' => 1, 'timing' => null, 'addtime' => '2026-11-17 03:17:08', 'data' => serialize($accountPayload)]);
Db::name('jobs')->insert(['id' => 1, 'uid' => 1, 'type' => 'agentrouter', 'user_id' => 'first-user',
    'do' => 'sign_in', 'state' => 1, 'nextExecute' => 0, 'lastExecute' => null, 'data' => serialize($jobPayload)]);

$controller = new class extends Agentrouter {
    public int $clock = 0;
    protected function scheduleNow(): int { return $this->clock; }
    protected function executor(): AgentRouterTaskExecutor
    {
        throw new RuntimeException('Saving a schedule must not log into the upstream');
    }
};
$at = static fn(string $time): int => (new DateTimeImmutable($time, new DateTimeZone('Asia/Shanghai')))->getTimestamp();
$controller->clock = $at('2026-11-17 03:17:08');
fixtureRequest(['user_id' => 'first-user', 'timing' => '08:00']);
functionalCheck($controller->handle('timing')->getData()['code'] === 1, 'Saving first AG schedule failed');
$planned = (int)Db::name('jobs')->value('nextExecute');
functionalCheck($planned === $at('2026-11-17 08:00:00'),
    'First AG schedule skipped today: expected 2026-11-17 08:00:00, got ' . date('Y-m-d H:i:s', $planned));
functionalCheck(Db::name('jobs')->value('data') === serialize($jobPayload), 'Scheduling overwrote job options');
functionalCheck(Db::name('accounts')->value('data') === serialize($accountPayload), 'Scheduling changed account credentials');

$controller->clock = $at('2026-11-17 03:20:00');
functionalCheck($controller->handle('timing')->getData()['code'] === 1
    && (int)Db::name('jobs')->value('nextExecute') === $planned, 'Repeated save moved the pending first schedule');
functionalCheck($controller->handle('toggle')->getData()['code'] === 1, 'Pausing a first schedule failed');
functionalCheck($controller->handle('toggle')->getData()['code'] === 1
    && (int)Db::name('jobs')->value('nextExecute') === $planned, 'Enabling a first AG plan skipped today');

$controller->clock = $at('2026-11-17 14:00:00');
functionalCheck($controller->handle('timing')->getData()['code'] === 1
    && (int)Db::name('jobs')->value('nextExecute') === $controller->clock, 'Past first AG slot was postponed to tomorrow');

$lock = 'job:1';
TaskExecutionLock::acquire($lock);
try {
    fixtureRequest(['user_id' => 'first-user', 'timing' => '10:00']);
    functionalCheck($controller->handle('timing')->getData()['code'] === 0, 'Saving changed an active execution lease');
    functionalCheck(Db::name('accounts')->value('timing') === '08:00', 'Active account timing changed');
} finally {
    TaskExecutionLock::release($lock);
}

// Daily jitter must stay stable even when a pending first slot crosses midnight.
putenv('SCHEDULER_JITTER_SECONDS=900');
$controller->clock = $at('2026-11-17 23:58:00');
fixtureRequest(['user_id' => 'first-user', 'timing' => '23:59']);
$midnightSlot = $at('2026-11-17 23:59:00') + AutomaticSchedule::dailyJitter('agentrouter:first-user', '2026-11-17');
functionalCheck($midnightSlot > $at('2026-11-18 00:01:00'), 'Fixture jitter did not cross midnight');
functionalCheck($controller->handle('timing')->getData()['code'] === 1
    && (int)Db::name('jobs')->value('nextExecute') === $midnightSlot, 'First AG slot lost its daily jitter');
$controller->clock = $at('2026-11-18 00:01:00');
functionalCheck($controller->handle('timing')->getData()['code'] === 1
    && (int)Db::name('jobs')->value('nextExecute') === $midnightSlot, 'Saving after midnight postponed a pending first slot');
$controller->clock = $midnightSlot + 60;
functionalCheck($controller->handle('timing')->getData()['code'] === 1
    && (int)Db::name('jobs')->value('nextExecute') === $controller->clock, 'Overdue midnight slot was postponed for a day');
putenv('SCHEDULER_JITTER_SECONDS=0');
$controller->clock = $at('2026-11-17 14:00:00');

$retryData = $jobPayload + ['_scheduler_retry' => ['attempts' => 1, 'at' => $controller->clock - 30]];
$retryAt = $controller->clock + 300;
Db::name('jobs')->where('id', 1)->update(['nextExecute' => $retryAt, 'data' => serialize($retryData)]);
fixtureRequest(['user_id' => 'first-user', 'timing' => '09:00']);
functionalCheck($controller->handle('timing')->getData()['code'] === 1
    && (int)Db::name('jobs')->value('nextExecute') === $retryAt, 'Saving reset the existing retry deadline');
functionalCheck(Db::name('jobs')->value('data') === serialize($retryData), 'Saving reset the retry counter');

Db::name('jobs')->where('id', 1)->update(['lastExecute' => '2026-11-17 09:00:00', 'data' => serialize($jobPayload)]);
fixtureRequest(['user_id' => 'first-user', 'timing' => '08:00']);
functionalCheck($controller->handle('timing')->getData()['code'] === 1
    && (int)Db::name('jobs')->value('nextExecute') === $at('2026-11-18 08:00:00'),
    'An already executed AG task was queued again on the same day');
functionalCheck($controller->handle('toggle')->getData()['code'] === 1, 'Pausing AG schedule failed');
functionalCheck($controller->handle('toggle')->getData()['code'] === 1
    && (int)Db::name('jobs')->value('nextExecute') === $at('2026-11-18 08:00:00'),
    'Re-enabling an executed task caused a same-day replay');

// Shared account recovery must apply the same first-plan rule.
Db::name('accounts')->where('id', 1)->update(['timing' => '00:00']);
Db::name('jobs')->where('id', 1)->update(['state' => 0, 'nextExecute' => 0, 'lastExecute' => null]);
fixtureRequest(['user_id' => 'first-user']);
functionalCheck(Jobs::switchState('agentrouter', 'first-user', 'sign_in') !== false, 'Shared task recovery failed');
$recoveryAt = time();
functionalCheck((int)Db::name('jobs')->value('nextExecute') >= $recoveryAt - 2
    && (int)Db::name('jobs')->value('nextExecute') <= $recoveryAt + 2,
    'Shared recovery skipped a never-executed AG task');

Db::name('jobs')->where('id', 1)->update(['state' => -1, 'nextExecute' => 0]);
functionalCheck(Jobs::updateJob('agentrouter', 'first-user', 1) !== false, 'Credential recovery failed');
functionalCheck((int)Db::name('jobs')->where('id', 1)->value('nextExecute') >= time() - 2
    && (int)Db::name('jobs')->where('id', 1)->value('nextExecute') <= time() + 2,
    'Credential recovery postponed a never-executed AG task');
Db::name('jobs')->where('id', 1)->update(['state' => -1, 'nextExecute' => 0, 'lastExecute' => date('Y-m-d H:i:s')]);
functionalCheck(Jobs::updateJob('agentrouter', 'first-user', 1) !== false
    && (int)Db::name('jobs')->where('id', 1)->value('nextExecute') === AutomaticSchedule::nextExecution('agentrouter', 'first-user', '00:00'),
    'Credential recovery replayed an already executed AG task');

// Shared creation and missing-job repair must also use the first-day rule.
foreach (['created-user' => 'add', 'repaired-user' => 'refreshJob'] as $identity => $method) {
    Db::name('accounts')->insert(['uid' => 1, 'type' => 'agentrouter', 'user_id' => $identity,
        'state' => 1, 'timing' => '00:00', 'data' => serialize($accountPayload)]);
    Jobs::$method('agentrouter', $identity);
    $created = Db::name('jobs')->where('user_id', $identity)->find();
    functionalCheck($created && (int)$created['nextExecute'] >= time() - 2 && (int)$created['nextExecute'] <= time() + 2,
        'Shared first-plan entry postponed AG: ' . $method);
    Db::name('jobs')->where('user_id', $identity)->delete();
    Db::name('accounts')->where('user_id', $identity)->delete();
}
Db::name('jobs')->where('id', 1)->update(['lastExecute' => null]);

// No saved time still means no automatic work.
fixtureRequest(['user_id' => 'first-user', 'timing' => '']);
functionalCheck($controller->handle('timing')->getData()['code'] === 1
    && (int)Db::name('jobs')->value('nextExecute') === 0, 'Clearing timing retained automatic work');

// Verify a past first slot through selection, claim, HTTP session, completion and the next-day plan.
$controller->clock = time();
fixtureRequest(['user_id' => 'first-user', 'timing' => '00:00']);
functionalCheck($controller->handle('timing')->getData()['code'] === 1, 'Queuing current-day first run failed');
$history = [];
$stack = HandlerStack::create(new MockHandler([
    new Response(200, [], '{"success":true,"data":{"system_name":"Agent Router","quota_per_unit":500000}}'),
    new Response(200, [], '{"success":true,"data":{"id":42,"checked_in":true}}'),
    new Response(200, [], '{"success":true,"data":{"id":42,"username":"first-user","quota":75000000}}'),
    new Response(200, [], '{"success":true}'),
]));
$stack->push(Middleware::history($history));
$client = new Client(new HttpClient(['handler' => $stack]));
functionalCheck($client->probeAsync(microtime(true) + 5)->wait(), 'Fixture anonymous probe failed');
$scheduler = new class(new AgentRouterTaskExecutor(static fn() => $client)) extends Task {
    public function __construct(private AgentRouterTaskExecutor $fixture) {}
    protected function agentRouterExecutor(): AgentRouterTaskExecutor { return $this->fixture; }
};
$notifications = new MemoryNotifications();
$settings = new UserNotificationSettings($notifications);
(new ReflectionProperty(Task::class, 'notificationService'))->setValue($scheduler,
    new NotificationService($notifications, $settings, new FixtureNotificationSite(), new FixtureNotificationTransport()));
$selected = (new ReflectionMethod(Task::class, 'dueJobs'))->invoke($scheduler, 50, 0, 1);
functionalCheck(count($selected) === 1, 'The first AG plan was not eligible for the unified scheduler');
$summary = array_fill_keys(['attempted', 'succeeded', 'failed', 'disabled', 'invalid_accounts', 'vip_expired', 'unscheduled'], 0);
(new ReflectionMethod(Task::class, 'runJob'))->invokeArgs($scheduler, [$selected[0], &$summary]);
functionalCheck($summary['succeeded'] === 1, 'First scheduled AG run failed');
functionalCheck(count(array_filter($history, static fn(array $entry): bool => $entry['request']->getMethod() === 'POST')) === 1,
    'First run replayed login POST');
functionalCheck(count(array_filter($history, static fn(array $entry): bool => $entry['request']->getUri()->getPath() === '/api/user/logout')) === 1,
    'First run did not perform one logout');
functionalCheck(str_starts_with((string)Db::name('task_logs')->value('response'), '[成功]'), 'First run did not produce a success log');
functionalCheck((int)Db::name('jobs')->value('nextExecute') === AutomaticSchedule::nextExecution('agentrouter', 'first-user', '00:00'),
    'A completed first run did not return to the normal next-day schedule');
functionalCheck((new ReflectionMethod(Task::class, 'dueJobs'))->invoke($scheduler, 50, 0, 1) === [],
    'A completed first run remained due on the same day');
(new ReflectionMethod(Task::class, 'runJob'))->invokeArgs($scheduler, [$selected[0], &$summary]);
functionalCheck(count($history) === 4 && $summary['attempted'] === 1, 'A stale first-day selection replayed the login');

echo "AgentRouter first-day scheduling, recovery, retries, opt-in and complete scheduler session passed\n";

