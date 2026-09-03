<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use app\service\BilibiliTaskExecutor;

final class BilibiliTaskExecutorFake
{
    public bool $cookiezt = false;
    public array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function watchaid(): array
    {
        return ['code' => 1, 'message' => '观看任务已执行'];
    }

    public function shareaid(): array
    {
        $this->cookiezt = true;
        return ['code' => 0, 'message' => '账号已失效'];
    }

    public function dailyexperience(): array
    {
        return ['code' => 1, 'message' => '主站每日经验已执行'];
    }

    public function vipexperience(): array
    {
        return ['code' => 1, 'message' => '大会员每日经验已执行'];
    }
}

function biliExecutorCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$expectedTasks = [
    'manga',
    'dailybag',
    'doubleheart',
    'groupsignIn',
    'giftheart',
    'silver2coin',
    'watchaid',
    'shareaid',
    'coinadd',
    'dailyexperience',
    'vipexperience',
];
biliExecutorCheck(BilibiliTaskExecutor::TASKS === $expectedTasks, 'task allowlist changed unexpectedly');
biliExecutorCheck(!BilibiliTaskExecutor::supports('globalroom'), 'globalroom must never be executable');
biliExecutorCheck(!BilibiliTaskExecutor::supports('dailytask'), 'offline dailytask must never be executable');
biliExecutorCheck(!BilibiliTaskExecutor::supports('shareaid'), 'retired share task must never be executable');
biliExecutorCheck(BilibiliTaskExecutor::offlineReason('dailytask') === '直播签到功能已下线', 'offline task reason is missing');
biliExecutorCheck(BilibiliTaskExecutor::offlineReason('shareaid') === '每日分享功能已下架', 'retired share task reason is missing');
biliExecutorCheck(!in_array('shareaid', BilibiliTaskExecutor::executableTasks(), true), 'retired share task remained in executable list');
biliExecutorCheck(!BilibiliTaskExecutor::supports('__construct'), 'arbitrary methods are executable');

$decoded = BilibiliTaskExecutor::decodeSerializedArray(serialize(['global_room' => '123']));
biliExecutorCheck($decoded === ['global_room' => '123'], 'valid serialized config was rejected');
biliExecutorCheck(BilibiliTaskExecutor::decodeSerializedArray('not serialized') === null, 'invalid serialization was accepted');
biliExecutorCheck(
    BilibiliTaskExecutor::decodeSerializedArray(serialize(new stdClass())) === null,
    'serialized objects were accepted'
);

$factoryCalls = 0;
$capturedAccount = [];
$capturedConfig = [];
$executor = new BilibiliTaskExecutor(static function (array $account, array $config) use (
    &$factoryCalls,
    &$capturedAccount,
    &$capturedConfig
): BilibiliTaskExecutorFake {
    $factoryCalls++;
    $capturedAccount = $account;
    $capturedConfig = $config;
    return new BilibiliTaskExecutorFake($config);
});

$account = [
    'mid' => '42',
    'mid_md5' => 'mid-md5',
    'token' => 'session-token',
    'csrf' => 'csrf-token',
    'sid' => 'sid-token',
    'access_key' => 'legacy-access',
    'refresh_token' => 'refresh-token',
];
$watch = $executor->execute('watchaid', $account, [
    'global_room' => '123',
    'add_coin_mode' => 'random',
    'add_coin_num' => 3,
    'ignored' => 'value',
]);
biliExecutorCheck($watch['code'] === 1, 'allowed task did not execute');
biliExecutorCheck($factoryCalls === 1, 'helper factory was not called exactly once');
biliExecutorCheck($capturedAccount['refresh_token'] === 'refresh-token', 'account fields were not normalized');
biliExecutorCheck($capturedConfig['sid'] === 'sid-token', 'sid cookie was not forwarded');
biliExecutorCheck($capturedConfig['global_room'] === '123', 'global room config was not forwarded');
biliExecutorCheck(!isset($capturedConfig['ignored']), 'unknown task config was forwarded');

$beforeRejectedTask = $factoryCalls;
$offline = $executor->execute('dailytask', $account);
biliExecutorCheck($offline['code'] === 0 && str_contains($offline['message'], '已下线'), 'offline task was not rejected clearly');
biliExecutorCheck($factoryCalls === $beforeRejectedTask, 'offline task reached the helper factory');
$shareOffline = $executor->execute('shareaid', $account);
biliExecutorCheck($shareOffline['code'] === 0 && str_contains($shareOffline['message'], '已下架'), 'retired share task was not rejected clearly');
biliExecutorCheck($factoryCalls === $beforeRejectedTask, 'retired share task reached the helper factory');
$rejected = $executor->execute('globalroom', $account);
biliExecutorCheck($rejected['code'] === 0, 'globalroom execution was accepted');
biliExecutorCheck($factoryCalls === $beforeRejectedTask, 'rejected task reached the helper factory');

$missingCredential = $account;
$missingCredential['csrf'] = '';
$invalidCredentials = $executor->execute('watchaid', $missingCredential);
biliExecutorCheck($invalidCredentials['code'] === 0, 'missing credentials were accepted');
biliExecutorCheck($factoryCalls === $beforeRejectedTask, 'invalid credentials reached the helper factory');

$cronSource = file_get_contents(dirname(__DIR__) . '/app/cron/controller/Bilibili.php');
$taskSource = file_get_contents(dirname(__DIR__) . '/app/cron/controller/Task.php');
$jobsSource = file_get_contents(dirname(__DIR__) . '/app/index/model/Jobs.php');
$tasksModelSource = file_get_contents(dirname(__DIR__) . '/app/index/model/Tasks.php');
$adminAjaxSource = file_get_contents(dirname(__DIR__) . '/app/admin/controller/Ajax.php');
$installSql = file_get_contents(dirname(__DIR__) . '/app/install/install.sql');
$bilibiliSource = file_get_contents(dirname(__DIR__) . '/extend/bilibili/Bilibili.php');
foreach (['mid_md5', 'token', 'csrf', 'access_key'] as $credential) {
    biliExecutorCheck(
        !preg_match('/cron\/bilibili\/[^\r\n]*' . preg_quote($credential, '/') . '/', (string)$cronSource),
        'cron controller URL exposes ' . $credential
    );
    biliExecutorCheck(
        !preg_match('/cron\/bilibili\/[^\r\n]*' . preg_quote($credential, '/') . '/', (string)$taskSource),
        'generic task URL exposes ' . $credential
    );
}
// 调度器已改为进程内执行：任务不再通过 HTTP 自调用派发，RUN_KEY 与
// 账号参数都不应出现在 URL 构造里；user_id 一律来自 jobs 表。
biliExecutorCheck(!str_contains((string)$cronSource, 'getExecuteUrl'), 'cron controller still dispatches over HTTP');
biliExecutorCheck(
    !preg_match('/cron\/bilibili\//', (string)$cronSource),
    'cron controller still builds self-call URLs'
);
biliExecutorCheck(str_contains((string)$cronSource, 'runJob'), 'cron scheduler does not run jobs in-process');
biliExecutorCheck(str_contains((string)$cronSource, 'executableTasks()'), 'legacy Bilibili scheduler still selects retired tasks');
biliExecutorCheck(str_contains((string)$taskSource, 'executableTasks()'), 'unified scheduler still selects retired tasks');
biliExecutorCheck(str_contains((string)$adminAjaxSource, 'BilibiliTaskExecutor::offlineReason'), 'admin task edits do not recognize retired tasks');
biliExecutorCheck(str_contains((string)$adminAjaxSource, "'nextExecute' => 0"), 'admin task edits can leave retired jobs scheduled');
biliExecutorCheck(str_contains((string)$bilibiliSource, '分享功能已下架'), 'daily experience does not report retired share task');
biliExecutorCheck(!str_contains((string)$bilibiliSource, '$this->shareAid()'), 'daily experience still calls the retired share task');
biliExecutorCheck(
    substr_count((string)$jobsSource, 'BilibiliTaskExecutor::offlineReason') >= 3,
    'job creation or account refresh can re-enable an offline Bilibili task'
);
biliExecutorCheck(str_contains((string)$tasksModelSource, "'execute_name' => 'dailyexperience'"), 'existing installs are missing the daily experience task');
biliExecutorCheck(str_contains((string)$tasksModelSource, "'execute_name' => 'vipexperience'"), 'existing installs are missing the VIP experience task');
biliExecutorCheck(str_contains((string)$installSql, "'dailyexperience'"), 'fresh installs are missing the daily experience task');
biliExecutorCheck(str_contains((string)$installSql, "'vipexperience'"), 'fresh installs are missing the VIP experience task');

echo "Bilibili task executor tests passed\n";
