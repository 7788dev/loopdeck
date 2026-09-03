<?php

namespace app\cron\controller;

use app\index\model\Accounts;
use app\index\model\Info;
use app\index\model\Jobs;
use app\index\model\TaskLogs;
use app\index\model\Tasks;
use app\index\model\Users;
use app\service\AutomaticSchedule;
use think\facade\Request;
use Throwable;
use xiaoheihe\BlackBox;

class Heybox extends Common
{
    /** jobs.do 允许派发到 BlackBox 的任务名白名单 */
    private const TASKS = [
        'sign',
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
        $jobs = Jobs::getUnexecutedList('heybox'); // 获取未执行任务列表
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
            $user = Users::where('uid', '=', (int)($job['uid'] ?? 0))->find();
            if ($user === null) {
                $this->disableJob($jobId, (string)($job['user_id'] ?? ''), (string)($job['do'] ?? ''), '用户不存在，任务已停用');
                continue;
            }
            $task = Tasks::where('type', '=', 'heybox')
                ->where('execute_name', '=', (string)($job['do'] ?? ''))
                ->where('state', '=', 1)
                ->find();
            if ($task === null) {
                $this->disableJob($jobId, (string)($job['user_id'] ?? ''), (string)($job['do'] ?? ''), '任务不存在或已停用');
                continue;
            }
            $account = Accounts::where('type', '=', 'heybox')
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
                $this->vipExpired('heybox', (int)$user['uid'], (string)$job['user_id']);
                // 将VIP过期的任务用户id放入一个数组，用于后续判断
                $vip_expired_userIds[] = $accountKey;
                continue;
            }

            // 凭据从数据库读取并在本进程执行，不再经 URL 传播 RUN_KEY/pkey
            $result = $this->runJob($jobId, (string)$job['do'], $account, $user);
            if ($result === null) {
                // A failed execution keeps its retry schedule; it must not be
                // advanced to the next normal daily slot.
                continue;
            }

            $nextExecute = AutomaticSchedule::nextExecution(
                'heybox',
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
                    'heybox',
                    (string)$job['user_id'],
                    (string)$job['do'],
                    '[重试中] 任务完成状态写入失败，已安排稍后重试'
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

    /**
     * 在当前进程执行一条小黑盒任务：凭据从库内 data 反序列化，绝不进 URL。
     */
    private function runJob(int $jobId, string $do, $account, $user): ?array
    {
        try {
            // 任务名来自 jobs.do，白名单防止脏数据把任意方法名派发到 BlackBox
            if (!in_array($do, self::TASKS, true)) {
                $this->disableJob($jobId, (string)($account['user_id'] ?? ''), $do, '未知的任务名称，任务已停用');
                return null;
            }
            $account_info = safe_unserialize_array((string)$account['data']);
            $accountUserId = trim((string)($account_info['user_id'] ?? $account['user_id']));
            $pkey = trim((string)($account_info['pkey'] ?? ''));
            if ($accountUserId === '' || $pkey === '') {
                $this->disableJob($jobId, (string)($account['user_id'] ?? ''), $do, '账号数据损坏，请重新添加账号');
                return null;
            }
            $heybox = new BlackBox($accountUserId, $pkey);
            $execute = $heybox->{$do}();
            if (!is_array($execute)) {
                throw new \RuntimeException('Invalid Heybox task response');
            }
            if ($heybox->cookiezt) {
                $this->accountInvalid('heybox', $user, $account['user_id']); // 账号失效处理
                return null;
            }
            $this->writeLog('heybox', $account['user_id'], $do,
                '[' . $this->statusTag($execute) . '] ' . (string)($execute['message'] ?? '小黑盒任务执行完成'));
            return $execute;
        } catch (Throwable $exception) {
            // 异常只影响本条任务；不要推进到正常计划。
            $this->scheduleRetry($jobId);
            $this->writeLog('heybox', $account['user_id'], $do, '[重试中] 任务调度异常，已安排稍后重试');
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
            $this->writeLog('heybox', $userId, $do, '[失败] ' . $message);
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

    private function writeLog(string $type, string $userId, string $do, string $message): void
    {
        try {
            TaskLogs::operateExecuteLog($type, $userId, $do, $message);
        } catch (Throwable $exception) {
            // Logging must not abort the remaining jobs in this round.
        }
    }

    public function execute($do)
    {
        // 历史自调用入口：RUN_KEY 与账号 pkey 曾随 URL 传播，任务已在 index()
        // 进程内执行，该端点仅作路由兼容并拒绝外部触发。
        return resultJson(-1001, 'RunKey Access Denied!');
    }
}
