<?php

namespace app\cron\controller;

use app\index\model\TaskLogs;
use app\index\model\Info;
use app\index\model\Accounts;
use app\index\model\Jobs;
use app\index\model\Tasks;
use app\index\model\Users;
use app\service\AutomaticSchedule;
use app\service\NeteaseSchedule;
use app\service\SchedulerRetry;
use netease\Netease as NeteaseAPI;
use Throwable;

/**
 * Legacy NetEase scheduler entry point.
 *
 * It used to fan out one HTTP self-call per job with the account's `csrf` and
 * `MUSIC_U` cookies plus the shared RUN_KEY in the query string, which wrote
 * every stored credential into the web server access log, and then dispatched
 * `$netease->{$do}()` on a task name taken from the URL. Jobs now run in the
 * scheduler process and the task name is checked against a fixed list.
 */
class Netease extends Common
{
    private const TASKS = [
        'sign',
        'login_work',
        'musician_task',
        'evaluate',
        'daka_new',
        'yunbei_task',
        'vip_growth_task',
    ];

    /** Per-batch lookups: most jobs in a batch share the same user, task or account. */
    private array $userCache = [];
    private array $taskCache = [];
    private array $accountCache = [];

    public function index()
    {
        $jobs = Jobs::getUnexecutedList('netease'); // 获取未执行任务列表
        if (!$jobs || count($jobs) === 0) {
            return resultJson(-1002, '没有要执行的任务');
        }
        foreach ($jobs as $job) {
            $jobId = (int)($job['id'] ?? 0);
            if (!Jobs::claimDueJob($jobId, (int)($job['nextExecute'] ?? 0))) {
                continue;
            }
            try {

                $user = $this->user((int)($job['uid'] ?? 0));
                if ($user === null) {
                    $this->disableJob($jobId, (string)($job['user_id'] ?? ''), (string)($job['do'] ?? ''), '用户不存在，任务已停用');
                    continue;
                }
                $task = $this->task((string)($job['do'] ?? ''));
                if ($task === null) {
                    $this->disableJob($jobId, (string)($job['user_id'] ?? ''), (string)($job['do'] ?? ''), '任务不存在或已停用');
                    continue;
                }
                $account = $this->account((int)($job['uid'] ?? 0), (string)($job['user_id'] ?? ''));
                if ($account === null) {
                    $this->disableJob($jobId, (string)($job['user_id'] ?? ''), (string)($job['do'] ?? ''), '账号不存在，任务已停用');
                    continue;
                }
                if ((int)($account['state'] ?? 0) !== 1) {
                    Jobs::updateClaimedJob($jobId, ['state' => -1, 'nextExecute' => 0]);
                    continue;
                }
                if (!AutomaticSchedule::isConfigured((string)($account['timing'] ?? ''))) {
                    Jobs::updateClaimedJob($jobId, ['nextExecute' => 0]);
                    continue;
                }
                if ((int)$task['vip'] === 1 && strtotime((string)($user['vip_end'] ?? '')) < time()) {
                    $this->vipExpired('netease', (int)$user['uid'], (string)$job['user_id']);
                    continue;
                }

                $result = $this->runJob(
                    $jobId,
                    (string)$job['do'],
                    $account,
                    $user,
                    (string)($job['data'] ?? '')
                );
                if ($result === null) {
                    // Exceptions and invalid credentials either schedule their own
                    // retry or disable the account. Never advance to the normal
                    // daily schedule after a failed execution.
                    continue;
                }

                (new \app\service\NotificationService())->recordTask($user, 'netease', (string)$job['user_id'],
                    (string)$job['do'], (string)$task['name'], $result);
                $nextExecute = NeteaseSchedule::nextAfterResult(
                    (string)$job['user_id'], (string)$account['timing'], $result
                );
                try {
                    Jobs::updateClaimedJob($jobId, [
                        'lastExecute' => date("Y-m-d H:i:s"),
                        'nextExecute' => $nextExecute,
                        // A completed run breaks the consecutive-failure streak.
                        'data' => serialize(SchedulerRetry::dataAfterSuccess(
                            safe_unserialize_array((string)($job['data'] ?? ''))
                        )),
                    ]);
                } catch (Throwable $exception) {
                    // Keep the leased job retryable when its completion update
                    // fails; do not abort the remaining jobs in this batch.
                    if ($this->scheduleRetry($jobId)) {
                        $this->writeLog(
                            'netease',
                            (string)$job['user_id'],
                            (string)$job['do'],
                            '执行结果写入失败，稍后自动重试',
                            $this->statusTag(['retry_after_seconds' => 300])
                        );
                    }
                    continue;
                }
                try {
                    Info::recordRun(100);
                } catch (Throwable $exception) {
                    // Statistics are best-effort and must not repeat a completed
                    // upstream task.
                }
            } catch (Throwable $exception) {
                // One broken job (failed lookup, VIP bookkeeping, ...) must not
                // abort the remaining jobs in this batch; the lease is released
                // below and the job retries at lease expiry.
                continue;
            } finally {
                Jobs::releaseDueJob((int)$job['id']);
            }
        }
        return resultJson(1000, '执行任务成功');
    }

    public function execute($do)
    {
        // 历史自调用入口：账号 cookie 与 RUN_KEY 曾随 URL 传播，任务已在
        // index() 进程内执行，该端点仅作路由兼容并拒绝外部触发。
        return resultJson(-1001, 'RunKey Access Denied!');
    }

    private function user(int $uid)
    {
        if (!array_key_exists($uid, $this->userCache)) {
            $this->userCache[$uid] = Users::where('uid', '=', $uid)->where('web_id', 1)->where('state', 1)->find() ?: null;
        }

        return $this->userCache[$uid];
    }

    private function task(string $taskName)
    {
        if (!array_key_exists($taskName, $this->taskCache)) {
            $this->taskCache[$taskName] = Tasks::where('type', '=', 'netease')
                ->where('execute_name', '=', $taskName)
                ->where('state', '=', 1)
                ->find() ?: null;
        }

        return $this->taskCache[$taskName];
    }

    private function account(int $uid, string $userId)
    {
        $key = $uid . '|' . $userId;
        if (!array_key_exists($key, $this->accountCache)) {
            $this->accountCache[$key] = Accounts::where('type', '=', 'netease')
                ->where('user_id', '=', $userId)
                ->where('uid', '=', $uid)
                ->find() ?: null;
        }

        return $this->accountCache[$key];
    }

    /**
     * Run one job in this process. Credentials are read from the database and
     * never travel through a URL.
     *
     * @param mixed $account
     * @param mixed $user
     */
    private function runJob(int $jobId, string $do, $account, $user, string $jobData): ?array
    {
        try {
            if (!in_array($do, self::TASKS, true)) {
                $userId = (string)($account['user_id'] ?? '');
                $this->disableJob($jobId, $userId, $do, '未知的任务名称，任务已停用');
                return null;
            }
            $accountData = safe_unserialize_array((string)$account['data']);
            $userId = trim((string)($accountData['user_id'] ?? $account['user_id']));
            $csrf = trim((string)($accountData['csrf'] ?? ''));
            $musicU = trim((string)($accountData['musicu'] ?? ''));
            if ($userId === '' || $csrf === '' || $musicU === '') {
                $this->accountInvalid('netease', $user, $account['user_id']);
                return null;
            }

            $netease = new NeteaseAPI($userId, $csrf, $musicU, safe_unserialize_array($jobData));
            $execute = $netease->{$do}();
            if (!is_array($execute)) {
                throw new \RuntimeException('Invalid NetEase task response');
            }
            if ($netease->cookiezt) {
                $this->accountInvalid('netease', $user, $account['user_id']); // 账号失效处理
                return null;
            }
            $this->writeLog(
                'netease',
                $account['user_id'],
                $do,
                (string)($execute['message'] ?? '任务执行完成'),
                $this->statusTag($execute)
            ); // 写入运行日志
            return $execute;
        } catch (Throwable $exception) {
            // 异常只影响本条任务；不要把租约推进到下一次正常计划。
            if ($this->scheduleRetry($jobId)) {
                $this->writeLog(
                    'netease', $account['user_id'], $do,
                    '执行异常，稍后自动重试',
                    $this->statusTag(['retry_after_seconds' => 300])
                );
            }
            return null;
        }
    }

    private function disableJob(int $jobId, string $userId, string $do, string $message): void
    {
        try {
            if ($jobId > 0 && !Jobs::updateClaimedJob($jobId, ['state' => 0, 'nextExecute' => 0])) {
                // The lease-guarded write landed nowhere: the user re-planned
                // the job during execution, so their plan wins and the
                // disable record would be wrong.
                return;
            }
        } catch (Throwable $exception) {
            // A database hiccup must not abort the remaining jobs.
            return;
        }
        if ($userId !== '') {
            $this->writeLog('netease', $userId, $do, $message);
        }
    }

    /**
     * Reschedule a failed claimed job. Returns true only when a retry was
     * actually scheduled. Returns false when the retry budget was exhausted
     * and the job was finalized (disabled with one final `[失败]` record) or
     * when the lease-guarded write landed nowhere — callers must not promise
     * another retry afterwards.
     */
    private function scheduleRetry(int $jobId): bool
    {
        if ($jobId <= 0) {
            return false;
        }
        try {
            $delay = max(60, min(3600, (int)(config('sys.reExecute_time') ?: 300)));
            $job = Jobs::where('id', $jobId)->field('user_id,do,data')->find();
            $jobData = safe_unserialize_array((string)($job['data'] ?? ''));
            $updates = ['nextExecute' => time() + $delay];
            $nextData = SchedulerRetry::dataAfterFailure($jobData);
            if ($nextData === null) {
                $updates = [
                    'state' => 0,
                    'nextExecute' => 0,
                    'data' => serialize(SchedulerRetry::settledData($jobData)),
                ];
                if (Jobs::updateClaimedJob($jobId, $updates) && $job) {
                    $this->writeLog(
                        'netease',
                        (string)$job['user_id'],
                        (string)$job['do'],
                        SchedulerRetry::finalMessage(SchedulerRetry::attemptsIn($jobData) + 1)
                    );
                }
                return false;
            }
            $updates['data'] = serialize($nextData);
            return Jobs::updateClaimedJob($jobId, $updates);
        } catch (Throwable $exception) {
            // Keep the claimed lease when the retry write itself fails: the
            // lease expiry re-claims the job, so a retry does still happen.
        }

        return true;
    }

    private function writeLog(string $type, string $userId, string $do, string $message, string $status = '失败'): void
    {
        if (!self::shouldReportTaskStatus($type, $do, $status)) {
            return;
        }
        try {
            TaskLogs::operateExecuteLog($type, $userId, $do, "[{$status}] " . $message);
        } catch (Throwable $exception) {
            // Logging must not abort the remaining jobs in this round.
        }
    }
}
