<?php

declare(strict_types=1);

require __DIR__ . '/FunctionalDatabaseBootstrap.php';
require __DIR__ . '/NotificationTestBootstrap.php';

use agentrouter\Client;
use app\cron\controller\Task;
use app\index\controller\Agentrouter;
use app\index\model\Accounts;
use app\index\model\Jobs;
use app\index\model\Tasks;
use app\service\AgentRouterTaskExecutor;
use app\service\TaskExecutionLock;
use app\service\NotificationService;
use app\service\UserNotificationSettings;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use think\facade\Db;
use think\facade\Session;

date_default_timezone_set('Asia/Shanghai');
$app->setRuntimePath(sys_get_temp_dir() . '/loopdeck-agentrouter-workflow-' . bin2hex(random_bytes(6)) . '/');
fixtureTable('accounts', 'id INTEGER PRIMARY KEY AUTOINCREMENT, uid INTEGER, zid INTEGER DEFAULT 1, type TEXT,
    user_id TEXT, state INTEGER DEFAULT 1, timing TEXT, cooling INTEGER DEFAULT 0, addtime TEXT, data TEXT');
fixtureTable('tasks', 'id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, execute_name TEXT, name TEXT, describe TEXT,
    icon TEXT, execute_rate INTEGER, execute_url TEXT, more INTEGER, state INTEGER, vip INTEGER, time TEXT, "order" INTEGER');
fixtureTable('jobs', 'id INTEGER PRIMARY KEY AUTOINCREMENT, uid INTEGER, zid INTEGER DEFAULT 1, type TEXT, user_id TEXT,
    do TEXT, state INTEGER, nextExecute INTEGER, lastExecute TEXT, data TEXT');
fixtureTable('task_logs', 'id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, user_id TEXT, do TEXT, response TEXT, addtime TEXT');
Db::name('users')->where('uid', 1)->update(['vip_start' => '2026-01-01', 'vip_end' => '2099-01-01']);
Session::set('user', Db::name('users')->find(1));

function agentWorkflowClient(array $tail, array &$history): Client
{
    $stack = HandlerStack::create(new MockHandler(array_merge([
        new Response(200, [], '{"success":true,"data":{"system_name":"Agent Router","quota_per_unit":500000,"display_in_currency":true}}'),
    ], $tail, [new Response(200, [], '{"success":true}')])));
    $stack->push(Middleware::history($history));
    $client = new Client(new HttpClient(['handler' => $stack]));
    $client->probeAsync(microtime(true) + 5)->wait();
    return $client;
}

function agentWorkflowNotifications(Task $task): MemoryNotifications
{
    $store = new MemoryNotifications();
    $settings = new UserNotificationSettings($store);
    $service = new NotificationService($store, $settings, new FixtureNotificationSite(), new FixtureNotificationTransport());
    (new ReflectionProperty(Task::class, 'notificationService'))->setValue($task, $service);
    return $store;
}

$loginOk = '{"success":true,"data":{"id":42,"checked_in":false}}';
$selfOk = '{"success":true,"data":{"id":42,"username":"account-from-api","quota":62500000,"used_quota":1750000,"request_count":3,"password":"never-save-password"}}';

// Saving validates by logging in (the check-in itself), then stores the profile.
$history = [];
$client = agentWorkflowClient([new Response(200, [], $loginOk), new Response(200, [], $selfOk)], $history);
$controller = new class(new AgentRouterTaskExecutor(static fn() => $client)) extends Agentrouter {
    public function __construct(private AgentRouterTaskExecutor $fixture) {}
    protected function executor(): AgentRouterTaskExecutor { return $this->fixture; }
};
fixtureRequest(['username' => 'fixture-user', 'password' => 'fixture-private-password', 'nickname' => '我的测试账号',
    'proxy_mode' => 'custom', 'proxy_url' => 'http://fixture-user:fixture-proxy-secret@8.8.8.8:8080']);
$response = $controller->handle('save')->getData();
functionalCheck($response['code'] === 1, 'Account bind failed: ' . $response['message']);
$saved = Db::name('accounts')->where('type', 'agentrouter')->find();
$job = Db::name('jobs')->where('type', 'agentrouter')->find();
functionalCheck((int)$job['nextExecute'] === 0 && (int)$job['state'] === 1, 'Account bind auto-ran a task without timing');
functionalCheck(count($history) === 4 && count(array_filter($history, static fn($e) => $e['request']->getMethod() === 'POST')) === 1,
    'Binding did not perform exactly one login POST');
functionalCheck(!str_contains(json_encode($response), 'fixture-private'), 'Bind response exposed credentials');
$payload = safe_unserialize_array((string)$saved['data']);
functionalCheck($payload['password'] === 'fixture-private-password' && $payload['profile']['username'] === 'account-from-api',
    'Stored payload lost credentials or the captured profile');
functionalCheck($payload['proxy_mode'] === 'custom' && str_contains($payload['proxy_url'], 'fixture-proxy-secret'),
    'Saving an AG account ignored its selected proxy');

// The account page is read-only: it shows the stored profile and never logs in.
$console = new app\index\controller\Console();
$beforeView = Db::name('accounts')->find($saved['id']);
$view = $console->agentrouter('info', 'fixture-user');
functionalCheck(!str_contains(serialize($view->getVars()), 'fixture-private') && !str_contains(serialize($view->getVars()), 'never-save')
    && !str_contains(serialize($view->getVars()), 'fixture-proxy-secret') && $view->getVars()['agentrouter_proxy_label'] === '自有代理',
    'Controller projection exposed credentials');
functionalCheck($view->getVars()['agentrouter_info']['nickname'] === '我的测试账号'
    && $view->getVars()['agentrouter_info']['username'] === 'account-from-api'
    && $view->getVars()['agentrouter_info']['balance'] === '$125.00', 'Account page omitted the stored profile');
functionalCheck(Db::name('accounts')->find($saved['id']) === $beforeView, 'Read-only page overwrote stored credentials');
$update = $console->agentrouter('update', 'fixture-user');
functionalCheck(!str_contains(serialize($update->getVars()), 'fixture-private')
    && $update->getVars()['agentrouter_form']['nickname'] === '我的测试账号'
    && $update->getVars()['agentrouter_form']['password_configured'] === true
    && $update->getVars()['agentrouter_form']['proxy_configured'] === true
    && $update->getVars()['agentrouter_form']['proxy_mode'] === 'custom'
    && !str_contains(serialize($update->getVars()), 'fixture-proxy-secret'), 'Update form lost the remark or credential state');
$listing = $console->agentrouter('list')->getVars();
functionalCheck($listing['list'][0]['nickname'] === '我的测试账号', 'Account list replaced the remark with an upstream name');
fixtureRequest(['user_id' => 'fixture-user']);
functionalCheck($controller->handle('logs')->getData() === [], 'Logs endpoint did not return the shared table shape');
functionalCheck(count(Tasks::getTaskList('agentrouter')) === 1, 'Builtin task not migrated to old installation');
Db::name('tasks')->where('type', 'agentrouter')->update(['state' => 0]);
Tasks::getTaskList('agentrouter');
functionalCheck(Db::name('tasks')->where('type', 'agentrouter')->value('state') === 0, 'Task sync re-enabled administrator-disabled task');
Db::name('tasks')->where('type', 'agentrouter')->update(['state' => 1]);

// Same external identity cannot be shared across users; account limits include this platform.
Db::name('users')->insert(['uid' => 2, 'web_id' => 1, 'quota' => 10, 'vip_start' => '2026-01-01', 'vip_end' => '2099-01-01']);
Session::set('user', Db::name('users')->find(2));
functionalCheck(Accounts::add('agentrouter', 'fixture-user', [])->getData()['code'] === 0, 'Cross-user account identity collision allowed');
fixtureRequest(['user_id' => 'fixture-user']);
foreach (['timing', 'toggle', 'retry', 'delete', 'logs'] as $action) {
    functionalCheck($controller->handle($action)->getData()['code'] === 0, 'Foreign account action allowed: ' . $action);
}
Session::set('user', Db::name('users')->find(1));
Db::name('users')->where('uid', 1)->update(['quota' => 1]);
functionalCheck(Accounts::add('agentrouter', 'other-user', [])->getData()['code'] === 0, 'AgentRouter bypassed shared account quota');

fixtureRequest(['user_id' => 'fixture-user', 'timing' => '09:30']);
$configuredAt = time();
functionalCheck($controller->handle('timing')->getData()['code'] === 1, 'Saving timing failed');
functionalCheck(Db::name('jobs')->value('nextExecute') >= $configuredAt, 'Timing did not schedule execution');
$lock = 'job:' . $job['id'];
TaskExecutionLock::acquire($lock);
try {
    fixtureRequest(['user_id' => 'fixture-user', 'timing' => '10:30']);
    foreach (['timing', 'toggle', 'retry'] as $action) {
        functionalCheck($controller->handle($action)->getData()['code'] === 0, 'Active task changed through ' . $action);
    }
    functionalCheck(Db::name('accounts')->value('timing') === '09:30', 'Active task timing overwritten');
} finally {
    TaskExecutionLock::release($lock);
}

// A blank password retains the stored credential.
$history = [];
$client = agentWorkflowClient([new Response(200, [], $loginOk), new Response(200, [], $selfOk)], $history);
$controller = new class(new AgentRouterTaskExecutor(static fn() => $client)) extends Agentrouter {
    public function __construct(private AgentRouterTaskExecutor $fixture) {}
    protected function executor(): AgentRouterTaskExecutor { return $this->fixture; }
};
fixtureRequest(['username' => 'fixture-user', 'password' => '', 'nickname' => '']);
functionalCheck($controller->handle('save')->getData()['code'] === 1, 'Blank password did not retain existing credentials');
$payload = safe_unserialize_array(Db::name('accounts')->value('data'));
functionalCheck($payload['password'] === 'fixture-private-password', 'Blank password erased the stored credential');
functionalCheck($payload['proxy_mode'] === 'custom' && str_contains($payload['proxy_url'], 'fixture-proxy-secret'),
    'A blank account update erased its saved proxy');

// Exercise actual scheduler selection, claim, result write, profile refresh, log prefix and release.
fixtureRequest(['user_id' => 'fixture-user']);
functionalCheck($controller->handle('retry')->getData()['code'] === 1, 'Manual run was not queued');
functionalCheck($controller->handle('retry')->getData()['code'] === 0, 'Manual cooldown not enforced');
$history = [];
$client = agentWorkflowClient([new Response(200, [], '{"success":true,"data":{"id":42,"checked_in":true}}'),
    new Response(200, [], '{"success":true,"data":{"id":42,"username":"account-from-api","quota":75000000}}')], $history);
$scheduler = new class(new AgentRouterTaskExecutor(static fn() => $client)) extends Task {
    public function __construct(private AgentRouterTaskExecutor $fixture) {}
    protected function agentRouterExecutor(): AgentRouterTaskExecutor { return $this->fixture; }
};
$selector = new ReflectionMethod(Task::class, 'dueJobs');
$notificationStore = agentWorkflowNotifications($scheduler);
$selected = $selector->invoke($scheduler, 50, 0, 1);
functionalCheck(count($selected) === 1 && $selected[0]['type'] === 'agentrouter', 'Unified scheduler omitted AgentRouter');
$run = new ReflectionMethod(Task::class, 'runJob');
$summary = array_fill_keys(['attempted', 'succeeded', 'failed', 'disabled', 'invalid_accounts', 'vip_expired', 'unscheduled'], 0);
$run->invokeArgs($scheduler, [$selected[0], &$summary]);
functionalCheck($summary['succeeded'] === 1, 'Scheduled check-in did not complete');
functionalCheck(Db::name('jobs')->value('nextExecute') > time() + 3600, 'Successful run did not advance schedule');
functionalCheck(str_starts_with(Db::name('task_logs')->value('response'), '[成功] 签到已完成'), 'Task log helper prefix lost');
$refreshed = safe_unserialize_array((string)Db::name('accounts')->value('data'));
functionalCheck(($refreshed['profile']['quota'] ?? null) === 75000000.0 && $refreshed['password'] === 'fixture-private-password',
    'Scheduler did not refresh the stored balance or clobbered credentials');
fixtureRequest(['user_id' => 'fixture-user']);
$logs = $controller->handle('logs')->getData();
functionalCheck(count($logs) === 1 && isset($logs[0]['id'], $logs[0]['do'], $logs[0]['response'], $logs[0]['addtime']),
    'Shared log table is missing account task fields');
functionalCheck(count($notificationStore->tasks) === 1 && reset($notificationStore->tasks)['status'] === '成功',
    'Check-in did not reach notification overview');
functionalCheck(TaskExecutionLock::acquire($lock), 'Scheduler leaked task execution lock');
TaskExecutionLock::release($lock);

// A transient connection failure retries without invalidating the account.
Db::name('jobs')->where('id', $job['id'])->update(['nextExecute' => time() - 1]);
$scheduler = new class extends Task {
    protected function agentRouterExecutor(): AgentRouterTaskExecutor
    {
        return new AgentRouterTaskExecutor(static function () { throw new RuntimeException('暂时无法访问 AgentRouter，请稍后重试'); });
    }
};
agentWorkflowNotifications($scheduler);
$summary = array_fill_keys(array_keys($summary), 0);
$run->invokeArgs($scheduler, [Jobs::where('id', $job['id'])->find(), &$summary]);
$retryJob = Db::name('jobs')->find($job['id']);
functionalCheck($summary['failed'] === 1 && $retryJob['nextExecute'] >= time() + 298
    && $retryJob['nextExecute'] <= time() + 302 && Db::name('accounts')->value('state') === 1,
    'Transient failure did not retry or invalidated account');
functionalCheck(str_starts_with(Db::name('task_logs')->order('id', 'desc')->value('response'), '[重试中]'),
    'Retry result did not use retry prefix');

// A real credential failure stops the account before any further upstream work.
Db::name('jobs')->where('id', $job['id'])->update(['nextExecute' => time() - 1]);
$history = [];
$client = agentWorkflowClient([new Response(200, [], '{"success":false,"message":"用户名或密码错误"}')], $history);
$scheduler = new class(new AgentRouterTaskExecutor(static fn() => $client)) extends Task {
    public function __construct(private AgentRouterTaskExecutor $fixture) {}
    protected function agentRouterExecutor(): AgentRouterTaskExecutor { return $this->fixture; }
};
agentWorkflowNotifications($scheduler);
$summary = array_fill_keys(array_keys($summary), 0);
$run->invokeArgs($scheduler, [Jobs::where('id', $job['id'])->find(), &$summary]);
functionalCheck($summary['invalid_accounts'] === 1 && Db::name('accounts')->value('state') === 0
    && Db::name('jobs')->value('state') === -1 && count($history) === 2, 'Bad credentials were not stopped after login');
Db::name('accounts')->where('id', $saved['id'])->update(['state' => 1]);
Db::name('jobs')->where('id', $job['id'])->update(['state' => 1]);

// An expired VIP pauses only the task and never attempts an upstream request.
Db::name('users')->where('uid', 1)->update(['vip_end' => '2020-01-01']);
Db::name('jobs')->where('id', $job['id'])->update(['nextExecute' => time() - 1]);
$scheduler = new class extends Task {
    protected function agentRouterExecutor(): AgentRouterTaskExecutor { throw new RuntimeException('VIP must block upstream access'); }
};
agentWorkflowNotifications($scheduler);
$summary = array_fill_keys(array_keys($summary), 0);
$run->invokeArgs($scheduler, [Jobs::where('id', $job['id'])->find(), &$summary]);
functionalCheck($summary['vip_expired'] === 1 && (int)Db::name('jobs')->value('state') === 0, 'Expired VIP ran AgentRouter task');

fixtureRequest(['user_id' => 'fixture-user']);
functionalCheck($controller->handle('delete')->getData()['code'] === 1, 'Delete failed');
functionalCheck(Db::name('accounts')->count() === 0 && Db::name('jobs')->count() === 0 && Db::name('task_logs')->count() === 0,
    'Deleting AgentRouter account left tasks or logs');

echo "AgentRouter account lifecycle, read-only page, tenant isolation, quota, controls and scheduler workflow tests passed\n";
