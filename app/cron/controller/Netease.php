<?php

namespace app\cron\controller;

use app\index\model\TaskLogs;
use app\index\model\Info;
use app\index\model\Accounts;
use app\index\model\Jobs;
use app\index\model\Tasks;
use app\index\model\Users;
use app\service\AutomaticSchedule;
use netease\Netease as NeteaseAPI;
use think\facade\Request;
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

    public function index()
    {
        $cronkey = (string)Request::get('cronkey', '');
        $expected = (string)config('sys.cronkey');
        if ($cronkey === '' || $expected === '' || !hash_equals($expected, $cronkey)) {
            $res = ['code' => -1000, 'message' => 'CronKey Access Denied!'];
            exit(json_encode($res, JSON_UNESCAPED_UNICODE));
        }

        $vip_expired_userIds = [];
        $jobs = Jobs::getUnexecutedList('netease'); // 获取未执行任务列表
        if (!$jobs || count($jobs) === 0) {
            return resultJson(-1002, '没有要执行的任务');
        }
        foreach ($jobs as $job) {
            $jobId = (int)($job['id'] ?? 0);
            if (!Jobs::claimDueJob($jobId, (int)($job['nextExecute'] ?? 0))) {
                continue;
            }
            $accountKey = (int)($job['uid'] ?? 0) . ':' . (string)($job['user_id'] ?? '');
            if (in_array($accountKey, $vip_expired_userIds, true)) {
                continue;
            }

            $user = Users::where('uid', '=', (int)($job['uid'] ?? 0))->where('state', 1)->find();
            if ($user === null) {
                $this->disableJob($jobId, (string)($job['user_id'] ?? ''), (string)($job['do'] ?? ''), '用户不存在，任务已停用');
                continue;
            }
            $task = Tasks::where('type', '=', 'netease')
                ->where('execute_name', '=', (string)($job['do'] ?? ''))
                ->where('state', '=', 1)
                ->find();
            if ($task === null) {
                $this->disableJob($jobId, (string)($job['user_id'] ?? ''), (string)($job['do'] ?? ''), '任务不存在或已停用');
                continue;
            }
            $account = Accounts::where('type', '=', 'netease')
                ->where('user_id', '=', (string)($job['user_id'] ?? ''))
                ->where('uid', '=', (int)($job['uid'] ?? 0))
                ->find();
            if ($account === null) {
                $this->disableJob($jobId, (string)($job['user_id'] ?? ''), (string)($job['do'] ?? ''), '账号不存在，任务已停用');
                continue;
            }
            if ((int)($account['state'] ?? 0) !== 1) {
                Jobs::where('id', $jobId)->update(['state' => -1, 'nextExecute' => 0]);
                continue;
            }
            if (!AutomaticSchedule::isConfigured((string)($account['timing'] ?? ''))) {
                Jobs::where('id', $jobId)->update(['nextExecute' => 0]);
                continue;
            }
            if ((int)$task['vip'] === 1 && strtotime((string)($user['vip_end'] ?? '')) < time()) {
                $this->vipExpired('netease', (int)$user['uid'], (string)$job['user_id']);
                // 将VIP过期的任务用户id放入一个数组，用于后续判断
                $vip_expired_userIds[] = $accountKey;
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
            $nextExecute = AutomaticSchedule::nextExecution(
                'netease',
                (string)$job['user_id'],
                (string)$account['timing']
            ) ?? 0;
            $retryAfter = (int)($result['data']['retry_after_seconds']
                ?? $result['retry_after_seconds']
                ?? 0);
            if ($retryAfter > 0) {
                $nextExecute = time() + max(60, min(3600, $retryAfter));
            }
            try {
                Jobs::where('id', $jobId)->update([
                    'lastExecute' => date("Y-m-d H:i:s"),
                    'nextExecute' => $nextExecute,
                ]);
            } catch (Throwable $exception) {
                // Keep the leased job retryable when its completion update
                // fails; do not abort the remaining jobs in this batch.
                $this->scheduleRetry($jobId);
                $this->writeLog(
                    'netease',
                    (string)$job['user_id'],
                    (string)$job['do'],
                    '任务完成状态写入失败，已安排稍后重试',
                    $this->statusTag(['retry_after_seconds' => 300])
                );
                continue;
            }
            try {
                Info::recordRun(100);
            } catch (Throwable $exception) {
                // Statistics are best-effort and must not repeat a completed
                // upstream task.
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
                (string)($execute['message'] ?? '网易云任务执行完成'),
                $this->statusTag($execute)
            ); // 写入运行日志
            return $execute;
        } catch (Throwable $exception) {
            // 异常只影响本条任务；不要把租约推进到下一次正常计划。
            $this->scheduleRetry($jobId);
            $this->writeLog(
                'netease', $account['user_id'], $do,
                '任务调度异常，已安排稍后重试',
                $this->statusTag(['retry_after_seconds' => 300])
            );
            return null;
        }
    }

    private function disableJob(int $jobId, string $userId, string $do, string $message): void
    {
        try {
            if ($jobId > 0) {
                Jobs::where('id', $jobId)->update(['state' => 0, 'nextExecute' => 0]);
            }
        } catch (Throwable $exception) {
            // A database hiccup must not abort the remaining jobs.
        }
        if ($userId !== '') {
            $this->writeLog('netease', $userId, $do, $message);
        }
    }

    private function scheduleRetry(int $jobId): void
    {
        if ($jobId <= 0) {
            return;
        }
        try {
            $delay = max(60, min(3600, (int)(config('sys.reExecute_time') ?: 300)));
            Jobs::where('id', $jobId)->update(['nextExecute' => time() + $delay]);
        } catch (Throwable $exception) {
            // Keep the claimed lease when the retry write itself fails.
        }
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
