<?php

declare(strict_types=1);

require __DIR__ . '/FunctionalDatabaseBootstrap.php';
require __DIR__ . '/NotificationTestBootstrap.php';

use anyrouter\Client;
use anyrouter\ProxyEndpoint;
use app\cron\controller\Task;
use app\index\controller\Anyrouter;
use app\index\model\Accounts;
use app\index\model\Jobs;
use app\index\model\Tasks;
use app\service\AnyRouterTaskExecutor;
use app\service\TaskExecutionLock;
use app\service\NotificationService;
use app\service\SchedulerRetry;
use app\service\UserNotificationSettings;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use think\facade\Db;
use think\facade\Session;

date_default_timezone_set('Asia/Shanghai');
$app->setRuntimePath(sys_get_temp_dir() . '/loopdeck-anyrouter-workflow-' . bin2hex(random_bytes(6)) . '/');
fixtureTable('accounts', 'id INTEGER PRIMARY KEY AUTOINCREMENT, uid INTEGER, zid INTEGER DEFAULT 1, type TEXT,
    user_id TEXT, state INTEGER DEFAULT 1, timing TEXT, cooling INTEGER DEFAULT 0, addtime TEXT, data TEXT');
fixtureTable('tasks', 'id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, execute_name TEXT, name TEXT, describe TEXT,
    icon TEXT, execute_rate INTEGER, execute_url TEXT, more INTEGER, state INTEGER, vip INTEGER, time TEXT, "order" INTEGER');
fixtureTable('jobs', 'id INTEGER PRIMARY KEY AUTOINCREMENT, uid INTEGER, zid INTEGER DEFAULT 1, type TEXT, user_id TEXT,
    do TEXT, state INTEGER, nextExecute INTEGER, lastExecute TEXT, data TEXT');
fixtureTable('task_logs', 'id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, user_id TEXT, do TEXT, response TEXT, addtime TEXT');
Db::name('users')->where('uid', 1)->update(['vip_start' => '2026-01-01', 'vip_end' => '2099-01-01']);
Session::set('user', Db::name('users')->find(1));

function workflowClient(array $tail, array &$history): Client
{
    $stack = HandlerStack::create(new MockHandler(array_merge([
        new Response(200, [], '{"success":true,"data":{"system_name":"AnyRouter"}}'),
    ], $tail)));
    $stack->push(Middleware::history($history));
    $client = new Client('http://8.8.8.8:8080', new HttpClient(['handler' => $stack]));
    $client->probeAsync(microtime(true) + 5)->wait();
    return $client;
}

function workflowNotifications(Task $task): MemoryNotifications
{
    $store = new MemoryNotifications();
    $settings = new UserNotificationSettings($store);
    $service = new NotificationService($store, $settings, new FixtureNotificationSite(), new FixtureNotificationTransport());
    (new ReflectionProperty(Task::class, 'notificationService'))->setValue($task, $service);
    return $store;
}

$history = [];
$client = workflowClient([new Response(200, [], '{"success":true,"data":{"id":42,"username":"fixture"}}')], $history);
$controller = new class(new AnyRouterTaskExecutor(static fn() => $client)) extends Anyrouter {
    public function __construct(private AnyRouterTaskExecutor $fixture) {}
    protected function executor(): AnyRouterTaskExecutor { return $this->fixture; }
};
fixtureRequest(['api_user' => '42', 'cookie' => 'session=fixture-private-cookie', 'proxy_mode' => 'custom',
    'proxy_url' => 'http://username:fixture-private-password@8.8.8.8:8080', 'nickname' => '我的测试账号']);
$response = $controller->handle('save')->getData();
functionalCheck($response['code'] === 1, 'Account bind failed: ' . $response['message']);
$saved = Db::name('accounts')->where('type', 'anyrouter')->find();
$job = Db::name('jobs')->where('type', 'anyrouter')->find();
functionalCheck((int)$job['nextExecute'] === 0 && (int)$job['state'] === 1, 'Account bind auto-ran a task without timing');
functionalCheck(count($history) === 2 && $history[1]['request']->getMethod() === 'GET', 'Binding submitted check-in');
functionalCheck(!str_contains(json_encode($response), 'fixture-private'), 'Bind response exposed credentials');
$profileHistory = [];
$profileClient = workflowClient([new Response(200, [], '{"success":true,"data":{"id":42,"username":"account-from-api","quota":100,"used_quota":20,"request_count":3,"password":"never-save-password"}}')], $profileHistory);
$console = new class(new AnyRouterTaskExecutor(static fn() => $profileClient)) extends app\index\controller\Console {
    public function __construct(private AnyRouterTaskExecutor $fixture) {}
    protected function anyRouterExecutor(): AnyRouterTaskExecutor { return $this->fixture; }
};
$beforeView = Db::name('accounts')->find($saved['id']);
$view = $console->anyrouter('info', '42');
functionalCheck(!str_contains(serialize($view->getVars()), 'fixture-private'), 'Controller projection exposed credentials');
functionalCheck($view->getVars()['anyrouter_info']['nickname'] === '我的测试账号'
    && $view->getVars()['anyrouter_info']['username'] === 'account-from-api'
    && $view->getVars()['anyrouter_info']['balance'] === '100 额度', 'Account page omitted live profile information');
functionalCheck(count($profileHistory) === 2 && array_reduce($profileHistory,
    static fn(bool $ok, array $entry): bool => $ok && $entry['request']->getMethod() === 'GET', true),
    'Opening the account page submitted check-in');
functionalCheck(Db::name('accounts')->find($saved['id']) === $beforeView, 'Read-only profile load overwrote stored credentials');
$update = $console->anyrouter('update', '42');
functionalCheck(!str_contains(serialize($update->getVars()), 'fixture-private') && count($profileHistory) === 2,
    'Update page exposed credentials or contacted upstream');
functionalCheck($update->getVars()['anyrouter_form']['nickname'] === '我的测试账号', 'Update form lost the custom remark');
$listing = $console->anyrouter('list')->getVars();
functionalCheck($listing['list'][0]['nickname'] === '我的测试账号', 'Account list replaced the remark with an upstream name');
$unavailable = new class extends app\index\controller\Console {
    protected function anyRouterExecutor(): AnyRouterTaskExecutor
    {
        return new AnyRouterTaskExecutor(static function () { throw new RuntimeException('private network details'); });
    }
};
$failedView = $unavailable->anyrouter('info', '42')->getVars();
functionalCheck($failedView['anyrouter_info']['balance'] === '暂不可用' && $failedView['info_warning'] !== ''
    && !str_contains(serialize($failedView), 'private network details'), 'Unavailable profile leaked errors or invented a balance');
fixtureRequest(['user_id' => '42']);
functionalCheck($controller->handle('logs')->getData() === [], 'Logs endpoint did not return the shared table shape');
functionalCheck(count(Tasks::getTaskList('anyrouter')) === 1, 'Builtin task not migrated to old installation');
Db::name('tasks')->where('type', 'anyrouter')->update(['state' => 0]);
Tasks::getTaskList('anyrouter');
functionalCheck(Db::name('tasks')->where('type', 'anyrouter')->value('state') === 0, 'Task sync re-enabled administrator-disabled task');
Db::name('tasks')->where('type', 'anyrouter')->update(['state' => 1]);

// Same external identity cannot be shared across users; account limits include this platform.
Db::name('users')->insert(['uid' => 2, 'web_id' => 1, 'quota' => 10, 'vip_start' => '2026-01-01', 'vip_end' => '2099-01-01']);
Session::set('user', Db::name('users')->find(2));
functionalCheck(Accounts::add('anyrouter', '42', [])->getData()['code'] === 0, 'Cross-user account identity collision allowed');
fixtureRequest(['user_id' => '42']);
foreach (['timing', 'toggle', 'retry', 'delete', 'logs'] as $action) {
    functionalCheck($controller->handle($action)->getData()['code'] === 0, 'Foreign account action allowed: ' . $action);
}
Session::set('user', Db::name('users')->find(1));
Db::name('users')->where('uid', 1)->update(['quota' => 1]);
functionalCheck(Accounts::add('anyrouter', '99', [])->getData()['code'] === 0, 'AnyRouter bypassed shared account quota');

fixtureRequest(['user_id' => '42', 'timing' => '09:30']);
functionalCheck($controller->handle('timing')->getData()['code'] === 1, 'Saving timing failed');
functionalCheck(Db::name('jobs')->value('nextExecute') > time(), 'Timing did not schedule execution');
$lock = 'job:' . $job['id'];
TaskExecutionLock::acquire($lock);
try {
    fixtureRequest(['user_id' => '42', 'timing' => '10:30']);
    foreach (['timing', 'toggle', 'retry'] as $action) {
        functionalCheck($controller->handle($action)->getData()['code'] === 0, 'Active task changed through ' . $action);
    }
    functionalCheck(Db::name('accounts')->value('timing') === '09:30', 'Active task timing overwritten');
} finally {
    TaskExecutionLock::release($lock);
}

// Blank secret fields retain values; switching back to platform removes custom proxy.
$history = [];
$client = workflowClient([new Response(200, [], '{"success":true,"data":{"id":42,"username":"fixture"}}')], $history);
$controller = new class(new AnyRouterTaskExecutor(static fn() => $client)) extends Anyrouter {
    public function __construct(private AnyRouterTaskExecutor $fixture) {}
    protected function executor(): AnyRouterTaskExecutor { return $this->fixture; }
};
fixtureRequest(['api_user' => '42', 'cookie' => '', 'proxy_mode' => 'platform', 'proxy_url' => '']);
functionalCheck($controller->handle('save')->getData()['code'] === 1, 'Blank cookie did not retain existing credentials');
$payload = safe_unserialize_array(Db::name('accounts')->value('data'));
functionalCheck($payload['cookies']['session'] === 'fixture-private-cookie' && $payload['proxy_url'] === '', 'Proxy mode switch retained custom endpoint or lost cookie');

// Exercise actual scheduler selection, claim, result write, log prefix and release.
fixtureRequest(['user_id' => '42']);
functionalCheck($controller->handle('retry')->getData()['code'] === 1, 'Manual run was not queued');
functionalCheck($controller->handle('retry')->getData()['code'] === 0, 'Manual cooldown not enforced');
$history = [];
$client = workflowClient([new Response(200, [], '{"success":true,"data":{"id":42,"username":"fixture"}}'),
    new Response(200, [], '{"success":true}')], $history);
$scheduler = new class(new AnyRouterTaskExecutor(static fn() => $client)) extends Task {
    public function __construct(private AnyRouterTaskExecutor $fixture) {}
    protected function anyRouterExecutor(): AnyRouterTaskExecutor { return $this->fixture; }
};
$selector = new ReflectionMethod(Task::class, 'dueJobs');
$notificationStore = workflowNotifications($scheduler);
$selected = $selector->invoke($scheduler, 50, 0, 1);
functionalCheck(count($selected) === 1 && $selected[0]['type'] === 'anyrouter', 'Unified scheduler omitted AnyRouter');
$run = new ReflectionMethod(Task::class, 'runJob');
$summary = array_fill_keys(['attempted', 'succeeded', 'failed', 'disabled', 'invalid_accounts', 'vip_expired', 'unscheduled'], 0);
$run->invokeArgs($scheduler, [$selected[0], &$summary]);
functionalCheck($summary['succeeded'] === 1, 'Scheduled check-in did not complete');
functionalCheck(Db::name('jobs')->value('nextExecute') > time() + 3600, 'Successful run did not advance schedule');
functionalCheck(str_starts_with(Db::name('task_logs')->value('response'), '[成功] 签到已完成'), 'Task log helper prefix lost');
fixtureRequest(['user_id' => '42']);
$logs = $controller->handle('logs')->getData();
functionalCheck(count($logs) === 1 && isset($logs[0]['id'], $logs[0]['do'], $logs[0]['response'], $logs[0]['addtime']),
    'Shared log table is missing account task fields');
functionalCheck(count($notificationStore->tasks) === 1 && reset($notificationStore->tasks)['status'] === '成功',
    'Check-in did not reach notification overview');
functionalCheck(TaskExecutionLock::acquire($lock), 'Scheduler leaked task execution lock');
TaskExecutionLock::release($lock);

// Temporary proxy failure retries without invalidating the account; a real auth failure stops it.
Db::name('jobs')->where('id', $job['id'])->update(['nextExecute' => time() - 1]);
$scheduler = new class extends Task {
    protected function anyRouterExecutor(): AnyRouterTaskExecutor
    {
        return new AnyRouterTaskExecutor(static function () { throw new RuntimeException('proxy unreachable'); });
    }
};
workflowNotifications($scheduler);
$summary = array_fill_keys(array_keys($summary), 0);
$run->invokeArgs($scheduler, [Jobs::where('id', $job['id'])->find(), &$summary]);
$retryJob = Db::name('jobs')->find($job['id']);
functionalCheck($summary['failed'] === 1 && $retryJob['nextExecute'] >= time() + 298
    && $retryJob['nextExecute'] <= time() + 302 && Db::name('accounts')->value('state') === 1,
    'Transient failure did not retry or invalidated account');
functionalCheck(str_starts_with(Db::name('task_logs')->order('id', 'desc')->value('response'), '[重试中]'),
    'Retry result did not use retry prefix');

// Failed proxy DNS resolution must persist another five-minute retry, not skip to the next day.
Db::name('jobs')->where('id', $job['id'])->update(['nextExecute' => time() - 1]);
$scheduler = new class extends Task {
    protected function anyRouterExecutor(): AnyRouterTaskExecutor
    {
        return new AnyRouterTaskExecutor(static fn(): Client => new Client(
            ProxyEndpoint::resolve('http://relay.example.com:8080', true, static fn() => [])
        ));
    }
};
workflowNotifications($scheduler);
$summary = array_fill_keys(array_keys($summary), 0);
$run->invokeArgs($scheduler, [Jobs::where('id', $job['id'])->find(), &$summary]);
$dnsRetryJob = Db::name('jobs')->find($job['id']);
functionalCheck($summary['attempted'] === 1 && $summary['failed'] === 1
    && $dnsRetryJob['nextExecute'] >= time() + 298 && $dnsRetryJob['nextExecute'] <= time() + 302
    && (int)$dnsRetryJob['state'] === 1 && Db::name('accounts')->value('state') === 1,
    'DNS failure skipped the current day or invalidated the account');
functionalCheck(SchedulerRetry::attemptsIn(safe_unserialize_array((string)$dnsRetryJob['data']))
    === SchedulerRetry::attemptsIn(safe_unserialize_array((string)$retryJob['data'])) + 1,
    'DNS failure reset the persisted retry budget');
functionalCheck(str_starts_with(Db::name('task_logs')->order('id', 'desc')->value('response'), '[重试中]'),
    'DNS failure did not use the retry log prefix');

Db::name('jobs')->where('id', $job['id'])->update(['nextExecute' => time() - 1]);
$history = [];
$client = workflowClient([new Response(401, [], '{"success":false,"message":"未登录"}')], $history);
$scheduler = new class(new AnyRouterTaskExecutor(static fn() => $client)) extends Task {
    public function __construct(private AnyRouterTaskExecutor $fixture) {}
    protected function anyRouterExecutor(): AnyRouterTaskExecutor { return $this->fixture; }
};
workflowNotifications($scheduler);
$summary = array_fill_keys(array_keys($summary), 0);
$run->invokeArgs($scheduler, [Jobs::where('id', $job['id'])->find(), &$summary]);
functionalCheck($summary['invalid_accounts'] === 1 && Db::name('accounts')->value('state') === 0
    && Db::name('jobs')->value('state') === -1 && count($history) === 2, 'Expired session was not stopped before POST');
Db::name('accounts')->where('id', $saved['id'])->update(['state' => 1]);
Db::name('jobs')->where('id', $job['id'])->update(['state' => 1]);

// An expired VIP pauses only the task and never attempts an upstream request.
Db::name('users')->where('uid', 1)->update(['vip_end' => '2020-01-01']);
Db::name('jobs')->where('id', $job['id'])->update(['nextExecute' => time() - 1]);
$scheduler = new class extends Task {
    protected function anyRouterExecutor(): AnyRouterTaskExecutor { throw new RuntimeException('VIP must block upstream access'); }
};
workflowNotifications($scheduler);
$summary = array_fill_keys(array_keys($summary), 0);
$run->invokeArgs($scheduler, [Jobs::where('id', $job['id'])->find(), &$summary]);
functionalCheck($summary['vip_expired'] === 1 && (int)Db::name('jobs')->value('state') === 0, 'Expired VIP ran AnyRouter task');

fixtureRequest(['user_id' => '42']);
functionalCheck($controller->handle('delete')->getData()['code'] === 1, 'Delete failed');
functionalCheck(Db::name('accounts')->count() === 0 && Db::name('jobs')->count() === 0 && Db::name('task_logs')->count() === 0,
    'Deleting AnyRouter account left tasks or logs');

echo "AnyRouter account lifecycle, tenant isolation, quota, controls and scheduler workflow tests passed\n";
