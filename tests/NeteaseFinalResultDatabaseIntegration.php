<?php

declare(strict_types=1);

// Explicit opt-in. Run only against an empty disposable database with a QA-only user.
require dirname(__DIR__) . '/vendor/autoload.php';

use app\cron\controller\Task;
use app\index\model\TaskLogs;
use app\service\NotificationRepository;
use app\service\NotificationService;
use app\service\NotificationSite;
use app\service\NotificationText;
use app\service\NotificationTransport;
use app\service\UserNotificationSettings;
use think\facade\Db;

$database = (string)getenv('LOOPDECK_TEST_DATABASE');
if (!preg_match('/\Aloopdeck_qa_[a-z0-9_]+\z/', $database)) {
    throw new RuntimeException('Set LOOPDECK_TEST_DATABASE to an empty disposable loopdeck_qa_* database');
}

// Only the upstream music adapter is replaced; scheduling, ORM and notifications are real.
final class QaFinalResultNetease
{
    public static array $response = [];
    public static bool $throw = false;
    public static int $calls = 0;
    public bool $cookiezt = false;

    public function __construct(...$arguments)
    {
    }

    public function daka_new(): array
    {
        self::$calls++;
        if (self::$throw) {
            throw new RuntimeException('Fixture upstream temporarily unavailable');
        }
        return self::$response;
    }
}

class_alias(QaFinalResultNetease::class, 'netease\\Netease');
$app = new think\App(dirname(__DIR__) . '/');
$app->setRuntimePath(sys_get_temp_dir() . '/' . $database . '/');
$app->initialize();
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . ' at '
        . basename($error->getFile()) . ':' . $error->getLine() . PHP_EOL);
    exit(1);
});
require_once dirname(__DIR__) . '/app/common.php';
$connection = array_replace($app->config->get('database.connections.mysql'), [
    'hostname' => getenv('LOOPDECK_TEST_HOST') ?: '127.0.0.1', 'database' => $database,
    'username' => getenv('LOOPDECK_TEST_USER'), 'password' => getenv('LOOPDECK_TEST_PASSWORD'),
    'prefix' => 'cloud_', 'charset' => 'utf8mb4',
]);
$app->config->set(['default' => 'mysql', 'connections' => ['mysql' => $connection]], 'database');
$app->config->set(['cronkey' => 'qa-inert-key', 'interval' => 50, 'reExecute_time' => 300], 'sys');

function finalResultCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

finalResultCheck(Db::query('SELECT DATABASE() AS db')[0]['db'] === $database, 'Wrong database connection');
finalResultCheck(Db::query('SHOW TABLES') === [], 'The disposable database must be empty');
Db::connect()->getPdo()->exec(file_get_contents(dirname(__DIR__) . '/app/install/install.sql'));
Db::name('weblist')->insert(['web_id' => 1, 'webname' => 'LoopDeck QA', 'title' => '隔离测试',
    'domain' => 'example.invalid', 'prefix' => 'cloud_', 'web_key' => 'qa-inert-key']);
Db::name('users')->insert(['uid' => 1, 'web_id' => 1, 'username' => 'qa_final_result',
    'password' => password_hash('qa-only-inert-password', PASSWORD_DEFAULT), 'state' => 1,
    'power' => 100, 'vip_start' => date('Y-m-d'), 'vip_end' => '2099-12-31']);
Db::name('tasks')->where('type', 'netease')->where('execute_name', 'daka_new')
    ->update(['name' => '每日听歌（自定义名称）']);
$repository = new NotificationRepository();
$repository->ensureSchema();
$settings = new UserNotificationSettings($repository);
$settings->save(1, 1, ['enabled' => 1, 'bark_enabled' => 1, 'bark_token' => 'qa_inert_device',
    'task_success' => 1, 'task_failure' => 1, 'summary_time' => '22:00'], false);

$runners = [
    'unified' => static function (int $jobId): void {
        $controller = new Task();
        $method = new ReflectionMethod($controller, 'runJob');
        $method->setAccessible(true);
        $summary = ['attempted' => 0, 'succeeded' => 0, 'failed' => 0, 'disabled' => 0];
        $method->invokeArgs($controller, [Db::name('jobs')->where('id', $jobId)->find(), &$summary]);
        finalResultCheck($summary['attempted'] === 1, 'Unified scheduler did not execute the fixture job');
    },
    'legacy' => static function (int $jobId) use ($app): void {
        $app->request->withGet(['cronkey' => 'qa-inert-key']);
        (new app\cron\controller\Netease())->index();
    },
    'cli' => static function (int $jobId) use ($app): void {
        $command = new app\command\Netease();
        $command->setApp($app);
        $command->run(new think\console\Input(['1']), new think\console\Output('buffer'));
    },
];
$pending = ['code' => 201, 'message' => '已上传，等待入账', 'data' => ['retry_after_seconds' => 300]];
$outcomes = [
    '成功' => ['code' => 200, 'message' => '进度 300/300', 'data' => ['retry_after_seconds' => 0]],
    '失败' => ['code' => 201, 'message' => '核验结束，进度 296/300', 'data' => ['retry_after_seconds' => 0]],
];
$fixtureId = 91000;
$terminalCount = 0;
foreach ($runners as $name => $run) {
    foreach ($outcomes as $status => $outcome) {
        $accountId = (string)++$fixtureId;
        Db::name('accounts')->insert(['uid' => 1, 'type' => 'netease', 'user_id' => $accountId,
            'timing' => '09:00', 'state' => 1, 'data' => serialize([
                'user_id' => $accountId, 'csrf' => 'qa-inert-csrf', 'musicu' => 'qa-inert-cookie',
            ])]);
        $jobId = (int)Db::name('jobs')->insertGetId(['uid' => 1, 'type' => 'netease',
            'user_id' => $accountId, 'do' => 'daka_new', 'data' => serialize([]), 'state' => 1,
            'nextExecute' => time() - 1]);
        foreach ([300, 900] as $delay) {
            Db::name('jobs')->where('id', $jobId)->update(['nextExecute' => time() - 1]);
            QaFinalResultNetease::$response = $pending;
            QaFinalResultNetease::$response['data']['retry_after_seconds'] = $delay;
            $calls = QaFinalResultNetease::$calls;
            $before = time();
            $run($jobId);
            $next = (int)Db::name('jobs')->where('id', $jobId)->value('nextExecute');
            finalResultCheck(QaFinalResultNetease::$calls === $calls + 1, "$name did not call the adapter once");
            finalResultCheck($next >= $before + $delay && $next <= time() + $delay,
                "$name lost the scheduled automatic verification");
            finalResultCheck(Db::name('task_logs')->count() === $terminalCount
                && Db::name('notification_task_results')->count() === $terminalCount
                && Db::name('notification_outbox')->count() === $terminalCount,
                "$name exposed a pending result in logs, daily results or the outbox");
        }
        if ($name !== 'cli') {
            Db::name('jobs')->where('id', $jobId)->update(['nextExecute' => time() - 1]);
            QaFinalResultNetease::$throw = true;
            $run($jobId);
            QaFinalResultNetease::$throw = false;
            $next = (int)Db::name('jobs')->where('id', $jobId)->value('nextExecute');
            finalResultCheck($next > time() && $next <= time() + 360,
                "$name failed to reschedule a transient exception");
            finalResultCheck(Db::name('task_logs')->count() === $terminalCount
                && Db::name('notification_task_results')->count() === $terminalCount
                && Db::name('notification_outbox')->count() === $terminalCount,
                "$name published a retrying exception as a terminal result");
        }
        Db::name('jobs')->where('id', $jobId)->update(['nextExecute' => time() - 1]);
        QaFinalResultNetease::$response = $outcome;
        $run($jobId);
        $terminalCount++;
        $logs = TaskLogs::searchLogs('netease', $accountId);
        finalResultCheck(count($logs) === 1 && str_starts_with($logs[0]['response'], "[$status] "),
            "$name did not publish exactly one terminal log");
        finalResultCheck(Db::name('notification_task_results')->where('account_id', $accountId)->value('status') === $status
            && Db::name('notification_outbox')->count() === $terminalCount,
            "$name lost its terminal notification");
        // A late intermediate result must not replace or duplicate the published result.
        $user = Db::name('users')->where('uid', 1)->find();
        $service = new NotificationService();
        $service->recordTask($user, 'netease', $accountId, 'daka_new', '每日300首', $pending);
        $service->recordTask($user, 'netease', $accountId, 'daka_new', '每日300首', $outcome);
        finalResultCheck(Db::name('notification_task_results')->where('account_id', $accountId)->value('status') === $status
            && Db::name('notification_outbox')->count() === $terminalCount, 'Terminal notification deduplication failed');
    }
}

$transport = new class extends NotificationTransport {
    public int $calls = 0;
    public function send(string $channel, array $settings, array $site, string $title, string $body, string $url = ''): array
    {
        $this->calls++;
        finalResultCheck(!str_contains($title . $body, '重试中') && !str_contains($body, '等待入账'),
            'An intermediate verification escaped into a delivered notification');
        return ['success' => true, 'message' => 'QA transport only'];
    }
};
$delivery = new NotificationService($repository, $settings, new NotificationSite(), $transport);
finalResultCheck(Db::name('notification_outbox')->where('status', 0)->count() === 6, 'Task execution delivered synchronously');
$tick = $delivery->tick(20, 20, time());
finalResultCheck($tick['sent'] === 6 && $transport->calls === 6, 'Terminal results did not reach the delivery worker');

// More than one page of legacy waiting rows must not crowd out final results.
$logAccount = 'qa-log-pagination';
$insertLog = static function (?string $task, ?string $message, string $type = 'netease', string $account = '') use ($logAccount): void {
    Db::name('task_logs')->insert(['type' => $type, 'user_id' => $account ?: $logAccount,
        'do' => $task, 'response' => $message, 'addtime' => date('Y-m-d H:i:s')]);
};
for ($i = 0; $i < 55; $i++) {
    $insertLog('每日300首', '[成功] 历史已完成 ' . $i);
}
$insertLog('每日300首', '[失败] 核验结束');
$insertLog('sign', '[重试中] 签到临时失败');
$insertLog(null, '[重试中] 其他旧任务');
$insertLog('每日300首', null);
for ($i = 0; $i < 70; $i++) {
    $insertLog(['daka_new', '每日300首', '每日听歌（自定义名称）'][$i % 3], '[重试中] 等待入账');
}
$insertLog('daka_new', '[重试中] 其他平台', 'bilibili');
$insertLog('sign', '[成功] 其他账号', 'netease', 'qa-other-account');
$storedBefore = Db::name('task_logs')->order('id')->select()->toArray();
$visible = TaskLogs::searchLogs('netease', $logAccount);
finalResultCheck(count($visible) === 50 && array_column($visible, 'id') === range(59, 10),
    'Legacy filtering broke pagination or account-local numbering');
finalResultCheck($visible[0]['response'] === null && $visible[1]['do'] === null
    && str_starts_with($visible[2]['response'], '[重试中]')
    && str_starts_with($visible[3]['response'], '[失败]'), 'Filtering hid another task, NULL row or final failure');
finalResultCheck(!str_contains(json_encode($visible, JSON_UNESCAPED_UNICODE), '等待入账'), 'Legacy waiting logs remain visible');
finalResultCheck(count(TaskLogs::searchLogs('bilibili', $logAccount)) === 1
    && count(TaskLogs::searchLogs('netease', 'qa-other-account')) === 1
    && TaskLogs::searchLogs('netease', 'qa-empty-account') === [], 'Log filtering changed platform/account isolation');
finalResultCheck(Db::name('task_logs')->order('id')->select()->toArray() === $storedBefore,
    'Reading the visible log list modified stored historical data');

$rows = $repository->dailyTasks(1, 1, date('Y-m-d'));
$rows[] = ['type' => 'netease', 'task_key' => 'daka_new', 'status' => '重试中',
    'task_name' => '旧版等待记录', 'message' => '等待入账'];
$summary = NotificationText::dailySummary(date('Y-m-d'), $rows);
finalResultCheck(str_contains($summary, '共 6 项') && !str_contains($summary, '旧版等待记录'),
    'Historical pending results leaked into the daily summary');

echo "Netease final-result database integration passed: 3 runners, 300/900-second verification, exceptions, "
    . "6 terminal deliveries, deduplication, legacy filtering, pagination and data preservation\n";
