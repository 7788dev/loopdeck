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
biliExecutorCheck(BilibiliTaskExecutor::offlineReason('dailytask') === '直播签到功能已下线', 'offline task reason is missing');
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
$rejected = $executor->execute('globalroom', $account);
biliExecutorCheck($rejected['code'] === 0, 'globalroom execution was accepted');
biliExecutorCheck($factoryCalls === $beforeRejectedTask, 'rejected task reached the helper factory');

$missingCredential = $account;
$missingCredential['csrf'] = '';
$invalidCredentials = $executor->execute('watchaid', $missingCredential);
biliExecutorCheck($invalidCredentials['code'] === 0, 'missing credentials were accepted');
biliExecutorCheck($factoryCalls === $beforeRejectedTask, 'invalid credentials reached the helper factory');

$invalidAccount = $executor->execute('shareaid', $account);
biliExecutorCheck($invalidAccount['account_invalid'], 'SDK account invalid state was not propagated');

$cronSource = file_get_contents(dirname(__DIR__) . '/app/cron/controller/Bilibili.php');
$taskSource = file_get_contents(dirname(__DIR__) . '/app/cron/controller/Task.php');
$jobsSource = file_get_contents(dirname(__DIR__) . '/app/index/model/Jobs.php');
$tasksModelSource = file_get_contents(dirname(__DIR__) . '/app/index/model/Tasks.php');
$installSql = file_get_contents(dirname(__DIR__) . '/app/install/install.sql');
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
biliExecutorCheck(str_contains((string)$cronSource, "Request::get('user_id'"), 'cron executor does not use user_id');
biliExecutorCheck(
    substr_count((string)$jobsSource, 'BilibiliTaskExecutor::offlineReason') >= 3,
    'job creation or account refresh can re-enable an offline Bilibili task'
);
biliExecutorCheck(str_contains((string)$tasksModelSource, "'execute_name' => 'dailyexperience'"), 'existing installs are missing the daily experience task');
biliExecutorCheck(str_contains((string)$tasksModelSource, "'execute_name' => 'vipexperience'"), 'existing installs are missing the VIP experience task');
biliExecutorCheck(str_contains((string)$installSql, "'dailyexperience'"), 'fresh installs are missing the daily experience task');
biliExecutorCheck(str_contains((string)$installSql, "'vipexperience'"), 'fresh installs are missing the VIP experience task');

echo "Bilibili task executor tests passed\n";
