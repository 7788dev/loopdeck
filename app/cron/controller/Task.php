<?php

declare(strict_types=1);

namespace app\cron\controller;

use app\index\model\Accounts;
use app\index\model\Info;
use app\index\model\Jobs;
use app\index\model\TaskLogs;
use app\index\model\Tasks;
use app\index\model\Users;
use app\service\BilibiliTaskExecutor;
use netease\Netease as NeteaseAPI;
use think\facade\Request;
use Throwable;

class Task extends Common
{
    private const NETEASE_TASKS = [
        'sign',
        'login_work',
        'musician_task',
        'evaluate',
        'daka_new',
        'yunbei_task',
        'vip_growth_task',
    ];

    private array $userCache = [];
    private array $taskCache = [];
    private array $accountCache = [];
    private array $globalConfigCache = [];
    private array $suppressedAccounts = [];

    public function index()
    {
        $cronKey = trim((string)Request::header('x-cron-key', ''));
        if ($cronKey === '') {
            $cronKey = trim((string)Request::get('cronkey', ''));
        }
        $expectedKey = (string)config('sys.cronkey');
        if ($cronKey === '' || $expectedKey === '' || !hash_equals($expectedKey, $cronKey)) {
            return resultJson(-1000, 'CronKey Access Denied!');
        }

        $workerCount = max(1, min(16, (int)Request::get('workers', 1)));
        $workerIndex = (int)Request::get('worker', 0);
        if ($workerIndex < 0 || $workerIndex >= $workerCount) {
            return resultJson(-1004, 'Invalid scheduler worker');
        }

        $lockName = sprintf('cron-task-%d-%d.lock', $workerCount, $workerIndex);
        $lockHandle = @fopen(runtime_path() . $lockName, 'c+');
        if (!is_resource($lockHandle)) {
            return resultJson(-1003, '无法创建任务调度锁');
        }
        if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($lockHandle);
            return resultJson(1001, '任务调度正在运行');
        }

        set_time_limit(0);
        $summary = [
            'worker' => $workerIndex,
            'workers' => $workerCount,
            'selected' => 0,
            'processed' => 0,
            'deferred' => 0,
            'attempted' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'disabled' => 0,
            'invalid_accounts' => 0,
            'vip_expired' => 0,
        ];

        try {
            $configuredLimit = (int)config('sys.interval');
            $limit = $this->envInt('SCHEDULER_BATCH_SIZE', $configuredLimit ?: 50, 1, 500);
            $timeBudget = $this->envInt('SCHEDULER_TIME_BUDGET_SECONDS', 50, 0, 300);
            $deadline = $timeBudget > 0 ? hrtime(true) + ($timeBudget * 1_000_000_000) : 0;
            $jobs = $this->dueJobs($limit, $workerIndex, $workerCount);
            $summary['selected'] = count($jobs);

            foreach ($jobs as $job) {
                if ($summary['processed'] > 0 && $deadline > 0 && hrtime(true) >= $deadline) {
                    break;
                }

                $this->runJob($job, $summary);
                $summary['processed']++;
            }

            $summary['deferred'] = $summary['selected'] - $summary['processed'];
            $this->recordAttempts($summary['attempted']);
            $this->pruneLogsIfDue();

            $message = $summary['selected'] === 0 ? '没有要执行的任务' : '任务调度完成';
            return resultJson(1000, $message, $summary);
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    private function dueJobs(int $limit, int $workerIndex, int $workerCount): array
    {
        $jobs = [];
        $taskMap = [
            'netease' => self::NETEASE_TASKS,
            'bilibili' => BilibiliTaskExecutor::TASKS,
        ];
        $now = time();

        foreach ($taskMap as $type => $tasks) {
            $query = Jobs::where('type', $type)
                ->where('state', 1)
                ->where('nextExecute', '<=', $now)
                ->whereIn('do', $tasks);
            if ($workerCount > 1) {
                // Keep every task for the same third-party account on one
                // worker while distributing different accounts evenly.
                $query->whereRaw(sprintf(
                    "MOD(CRC32(CONCAT(`type`, '#', `user_id`)), %d) = %d",
                    $workerCount,
                    $workerIndex
                ));
            }
            $rows = $query
                ->field('id,uid,type,user_id,do,data,nextExecute')
                ->order('nextExecute', 'asc')
                ->order('id', 'asc')
                ->limit($limit)
                ->select();
            foreach ($rows as $row) {
                $jobs[] = $row;
            }
        }

        usort($jobs, static function ($left, $right): int {
            $byTime = (int)$left['nextExecute'] <=> (int)$right['nextExecute'];
            return $byTime !== 0 ? $byTime : (int)$left['id'] <=> (int)$right['id'];
        });

        return array_slice($jobs, 0, $limit);
    }

    private function runJob($job, array &$summary): void
    {
        $jobId = (int)($job['id'] ?? 0);
        $uid = (int)($job['uid'] ?? 0);
        $type = (string)($job['type'] ?? '');
        $userId = trim((string)($job['user_id'] ?? ''));
        $taskName = trim((string)($job['do'] ?? ''));
        $accountKey = $this->accountKey($type, $uid, $userId);

        try {
            if ($jobId <= 0 || $uid <= 0 || $userId === '' || !$this->supports($type, $taskName)) {
                $this->disableJob($jobId, $type, $userId, $taskName, '任务数据不完整或功能已停用');
                $summary['disabled']++;
                return;
            }
            if (isset($this->suppressedAccounts[$accountKey])) {
                $summary['disabled']++;
                return;
            }

            $user = $this->user($uid);
            $task = $this->task($type, $taskName);
            $account = $this->account($type, $uid, $userId);

            if (!$user || !$task || !$account) {
                $this->disableJob($jobId, $type, $userId, $taskName, '用户、账号或任务数据不存在，任务已停用');
                $summary['disabled']++;
                return;
            }
            if ((int)$account['state'] !== 1) {
                Jobs::where('id', $jobId)->update(['state' => -1]);
                $this->writeLog($type, $userId, $taskName, '账号已停用，请重新登录后执行');
                $this->suppressedAccounts[$accountKey] = true;
                $summary['disabled']++;
                return;
            }
            if ((int)$task['vip'] === 1 && $this->vipExpiredAt($user)) {
                $this->expireVip($type, $uid, $userId);
                $summary['vip_expired']++;
                return;
            }

            $accountData = $this->decodeArray((string)$account['data']);
            $jobConfig = $this->decodeArray((string)($job['data'] ?? ''));
            if ($accountData === null || $accountData === [] || $jobConfig === null) {
                $this->disableJob($jobId, $type, $userId, $taskName, '账号或任务配置损坏，请重新登录后配置');
                $summary['disabled']++;
                return;
            }

            if ($type === 'bilibili') {
                $globalConfig = $this->bilibiliGlobalConfig($uid, $userId);
                if ($globalConfig === null) {
                    $this->disableJob($jobId, $type, $userId, $taskName, '全局任务配置损坏，请重新配置');
                    $summary['disabled']++;
                    return;
                }
                $jobConfig = array_replace($globalConfig, $jobConfig);
            }

            $summary['attempted']++;
            try {
                $result = $type === 'netease'
                    ? $this->executeNetease($taskName, $userId, $accountData, $jobConfig)
                    : $this->executeBilibili($taskName, $accountData, $jobConfig);
            } catch (Throwable $exception) {
                $this->retryJob($jobId);
                $this->writeLog($type, $userId, $taskName, '任务执行异常，已安排稍后重试');
                $summary['failed']++;
                return;
            }

            $this->writeLog($type, $userId, $taskName, $result['message']);
            if ($result['account_invalid']) {
                $this->invalidateAccount($type, $uid, $userId);
                $summary['invalid_accounts']++;
                $summary['failed']++;
                return;
            }

            Jobs::where('id', $jobId)->update([
                'lastExecute' => date('Y-m-d H:i:s'),
                'nextExecute' => $this->nextExecuteAt($account, $task, $jobId),
            ]);
            $summary[$result['success'] ? 'succeeded' : 'failed']++;
        } catch (Throwable $exception) {
            if ($jobId > 0) {
                $this->retryJob($jobId);
            }
            $this->writeLog($type, $userId, $taskName, '任务调度异常，已安排稍后重试');
            $summary['failed']++;
        }
    }

    private function supports(string $type, string $task): bool
    {
        return ($type === 'netease' && in_array($task, self::NETEASE_TASKS, true))
            || ($type === 'bilibili' && BilibiliTaskExecutor::supports($task));
    }

    private function executeNetease(string $task, string $userId, array $account, array $config): array
    {
        $csrf = trim((string)($account['csrf'] ?? ''));
        $musicU = trim((string)($account['musicu'] ?? ''));
        $accountUserId = trim((string)($account['user_id'] ?? $userId));
        if ($accountUserId === '' || $csrf === '' || $musicU === '') {
            return [
                'success' => false,
                'message' => '网易云账号凭据不完整，请重新登录',
                'account_invalid' => true,
            ];
        }

        $client = new NeteaseAPI($accountUserId, $csrf, $musicU, $config);
        $response = $client->{$task}();
        if (!is_array($response)) {
            throw new \RuntimeException('Invalid NetEase task response');
        }

        return [
            'success' => (int)($response['code'] ?? 0) === 200,
            'message' => trim((string)($response['message'] ?? '')) ?: '网易云任务执行完成',
            'account_invalid' => !empty($client->cookiezt),
        ];
    }

    private function executeBilibili(string $task, array $account, array $config): array
    {
        $result = (new BilibiliTaskExecutor())->execute($task, $account, $config);
        return [
            'success' => (int)$result['code'] === 1,
            'message' => trim((string)$result['message']) ?: '哔哩哔哩任务执行完成',
            'account_invalid' => (bool)$result['account_invalid'],
        ];
    }

    private function user(int $uid)
    {
        if (!array_key_exists($uid, $this->userCache)) {
            $this->userCache[$uid] = Users::where('uid', $uid)->find() ?: null;
        }

        return $this->userCache[$uid];
    }

    private function task(string $type, string $taskName)
    {
        $key = $type . '|' . $taskName;
        if (!array_key_exists($key, $this->taskCache)) {
            $this->taskCache[$key] = Tasks::where('type', $type)
                ->where('execute_name', $taskName)
                ->where('state', 1)
                ->find() ?: null;
        }

        return $this->taskCache[$key];
    }

    private function account(string $type, int $uid, string $userId)
    {
        $key = $this->accountKey($type, $uid, $userId);
        if (!array_key_exists($key, $this->accountCache)) {
            $this->accountCache[$key] = Accounts::where('type', $type)
                ->where('user_id', $userId)
                ->where('uid', $uid)
                ->find() ?: null;
        }

        return $this->accountCache[$key];
    }

    private function bilibiliGlobalConfig(int $uid, string $userId): ?array
    {
        $key = $this->accountKey('bilibili', $uid, $userId);
        if (!array_key_exists($key, $this->globalConfigCache)) {
            $payload = Jobs::where('type', 'bilibili')
                ->where('uid', $uid)
                ->where('user_id', $userId)
                ->where('do', 'globalroom')
                ->value('data');
            $this->globalConfigCache[$key] = $this->decodeArray(is_string($payload) ? $payload : '');
        }

        return $this->globalConfigCache[$key];
    }

    private function accountKey(string $type, int $uid, string $userId): string
    {
        return $type . '|' . $uid . '|' . $userId;
    }

    private function decodeArray(string $payload): ?array
    {
        if (trim($payload) === '') {
            return [];
        }

        try {
            $value = @unserialize($payload, ['allowed_classes' => false]);
        } catch (Throwable $exception) {
            return null;
        }

        return is_array($value) ? $value : null;
    }

    private function vipExpiredAt($user): bool
    {
        $expiresAt = strtotime((string)($user['vip_end'] ?? ''));
        return $expiresAt === false || $expiresAt < time();
    }

    private function nextExecuteAt($account, $task, int $jobId): int
    {
        if (!empty($account['timing'])) {
            $next = strtotime((string)$account['timing'] . ' +1 day');
            if ($next !== false) {
                $jitter = $this->envInt('SCHEDULER_JITTER_SECONDS', 120, 0, 900);
                return $next + $this->stableJitter($jobId, $jitter);
            }
        }

        return time() + max(60, (int)$task['execute_rate']);
    }

    private function retryJob(int $jobId): void
    {
        $cooldown = max(60, (int)(config('sys.reExecute_time') ?: 300));
        $jitter = $this->envInt('SCHEDULER_RETRY_JITTER_SECONDS', 60, 0, 300);
        Jobs::where('id', $jobId)->update([
            'lastExecute' => date('Y-m-d H:i:s'),
            'nextExecute' => time() + $cooldown + $this->stableJitter($jobId + intdiv(time(), 60), $jitter),
        ]);
    }

    private function stableJitter(int $seed, int $maximum): int
    {
        if ($maximum <= 0) {
            return 0;
        }

        return (int)sprintf('%u', crc32((string)$seed)) % ($maximum + 1);
    }

    private function disableJob(int $jobId, string $type, string $userId, string $task, string $message): void
    {
        if ($jobId > 0) {
            Jobs::where('id', $jobId)->update(['state' => 0]);
        }
        $this->writeLog($type, $userId, $task, $message);
    }

    private function expireVip(string $type, int $uid, string $userId): void
    {
        Users::where('uid', $uid)->update(['vip_start' => null, 'vip_end' => null]);
        Jobs::where('type', $type)
            ->where('uid', $uid)
            ->where('user_id', $userId)
            ->update(['state' => 0]);
        $this->suppressedAccounts[$this->accountKey($type, $uid, $userId)] = true;
        $this->writeLog($type, $userId, '系统提示', '会员过期，请开通会员后再试');
    }

    private function invalidateAccount(string $type, int $uid, string $userId): void
    {
        Accounts::where('type', $type)
            ->where('uid', $uid)
            ->where('user_id', $userId)
            ->update(['state' => 0]);
        Jobs::where('type', $type)
            ->where('uid', $uid)
            ->where('user_id', $userId)
            ->update(['state' => -1]);
        $key = $this->accountKey($type, $uid, $userId);
        $this->suppressedAccounts[$key] = true;
        $this->accountCache[$key] = null;
    }

    private function recordAttempts(int $attempts): void
    {
        if ($attempts <= 0) {
            return;
        }

        try {
            Info::where('sysid', 100)
                ->inc('times', $attempts)
                ->update(['last' => date('Y-m-d H:i:s')]);
        } catch (Throwable $exception) {
            // Statistics must not prevent the task schedule from advancing.
        }
    }

    private function pruneLogsIfDue(): void
    {
        $retentionDays = $this->envInt('TASK_LOG_RETENTION_DAYS', 30, 0, 3650);
        if ($retentionDays === 0) {
            return;
        }

        $stamp = runtime_path() . 'task-log-prune.stamp';
        clearstatcache(true, $stamp);
        if (is_file($stamp) && (int)filemtime($stamp) > time() - 86400) {
            return;
        }

        $lock = @fopen(runtime_path() . 'task-log-prune.lock', 'c+');
        if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            return;
        }

        try {
            clearstatcache(true, $stamp);
            if (is_file($stamp) && (int)filemtime($stamp) > time() - 86400) {
                return;
            }

            $cutoff = date('Y-m-d H:i:s', time() - ($retentionDays * 86400));
            $batchSize = $this->envInt('TASK_LOG_PRUNE_BATCH_SIZE', 5000, 100, 20000);
            for ($batch = 0; $batch < 20; $batch++) {
                $deleted = (int)TaskLogs::where('addtime', '<', $cutoff)
                    ->limit($batchSize)
                    ->delete();
                if ($deleted < $batchSize) {
                    break;
                }
            }
            @touch($stamp);
        } catch (Throwable $exception) {
            @touch($stamp);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function envInt(string $name, int $default, int $minimum, int $maximum): int
    {
        $value = getenv($name);
        if ($value === false || $value === '' || filter_var($value, FILTER_VALIDATE_INT) === false) {
            return max($minimum, min($maximum, $default));
        }

        return max($minimum, min($maximum, (int)$value));
    }

    private function writeLog(string $type, string $userId, string $task, string $message): void
    {
        if ($type === '' || $userId === '') {
            return;
        }

        try {
            TaskLogs::operateExecuteLog($type, $userId, $task ?: '系统提示', $message);
        } catch (Throwable $exception) {
            // A log write failure must not cause the external task to run twice.
        }
    }
}
