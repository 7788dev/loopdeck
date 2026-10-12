<?php

declare(strict_types=1);

namespace app\cron\controller;

use app\index\model\Accounts;
use app\index\model\Info;
use app\index\model\Jobs;
use app\index\model\TaskLogs;
use app\index\model\Tasks;
use app\index\model\Users;
use app\service\AutomaticSchedule;
use app\service\BilibiliTaskExecutor;
use app\service\SchedulerRetry;
use Throwable;

class Bilibili extends Common
{
    /** @var int 本轮实际领取并执行（含失败）的任务数 */
    private int $scheduled = 0;

    /** Per-batch lookups: most jobs in a batch share the same user, task or account. */
    private array $userCache = [];
    private array $taskCache = [];
    private array $accountCache = [];

    public function index()
    {
        $this->disableOfflineJobs();

        $limit = max(1, (int)config('sys.interval'));
        $jobs = Jobs::where('type', 'bilibili')
            ->where('zid', 1)
            ->where('state', 1)
            ->where('nextExecute', '>', 0)
            ->where('nextExecute', '<=', time())
            ->whereIn('do', BilibiliTaskExecutor::executableTasks())
            ->order('nextExecute', 'asc')
            ->limit($limit)
            ->select();

        foreach ($jobs as $job) {
            $jobId = (int)$job['id'];
            if (!Jobs::claimDueJob((int)$job['id'], (int)$job['nextExecute'])) {
                continue;
            }
            try {
                $job = Jobs::where('id', $jobId)->find();
                if (!$job) {
                    continue;
                }
                $this->scheduled++;
                $userId = (string)$job['user_id'];
                $user = $this->user((int)$job['uid']);
                $account = $this->account((int)$job['uid'], $userId);
                $task = $this->task((string)$job['do']);

                if (!$user || !$account || !$task) {
                    Jobs::updateClaimedJob((int)$job['id'], ['state' => 0, 'nextExecute' => 0]);
                    continue;
                }
                if (!AutomaticSchedule::isConfigured((string)($account['timing'] ?? ''))) {
                    Jobs::updateClaimedJob((int)$job['id'], ['nextExecute' => 0]);
                    continue;
                }
                if ((int)$task['vip'] === 1 && strtotime((string)($user['vip_end'] ?? '')) < time()
                    && $this->vipExpired('bilibili', $user['uid'], $userId)) {
                    continue;
                }
                // 凭据/配置都在库内，任务在本进程执行，RUN_KEY 不再进 URL
                $this->runJob((string)$job['do'], $job, $user, $account, $task);
            } catch (Throwable $exception) {
                // 一次查询或记账失败不能中断本轮其余任务；租约在 finally 中
                // 释放，任务在租约到期后自动重试。
                continue;
            } finally {
                Jobs::releaseDueJob($jobId);
            }
        }

        if ($this->scheduled === 0) {
            return resultJson(-1002, '没有要执行的任务');
        }
        return resultJson(1000, '已调度 ' . $this->scheduled . ' 条任务');
    }

    private function user(int $uid)
    {
        if (!array_key_exists($uid, $this->userCache)) {
            $this->userCache[$uid] = Users::where('uid', $uid)->where('web_id', 1)->where('state', 1)->find() ?: null;
        }

        return $this->userCache[$uid];
    }

    private function task(string $taskName)
    {
        if (!array_key_exists($taskName, $this->taskCache)) {
            $this->taskCache[$taskName] = Tasks::where('type', 'bilibili')
                ->where('execute_name', $taskName)
                ->where('state', 1)
                ->find() ?: null;
        }

        return $this->taskCache[$taskName];
    }

    private function account(int $uid, string $userId)
    {
        $key = $uid . '|' . $userId;
        if (!array_key_exists($key, $this->accountCache)) {
            $this->accountCache[$key] = Accounts::where('type', 'bilibili')
                ->where('user_id', $userId)
                ->where('uid', $uid)
                ->where('state', 1)
                ->find() ?: null;
        }

        return $this->accountCache[$key];
    }

    /**
     * 进程内执行一条哔哩哔哩任务，等价于原 execute() 的主体逻辑，
     * 但不经过 HTTP 自调用。
     */
    private function runJob(string $taskName, $job, $user, $account, $task): void
    {
        $userId = (string)$job['user_id'];
        try {
            $accountData = BilibiliTaskExecutor::decodeSerializedArray((string)$account['data']);
            $jobConfig = BilibiliTaskExecutor::decodeSerializedArray((string)($job['data'] ?? ''));
            if ($accountData === null || $jobConfig === null) {
                if (Jobs::updateClaimedJob((int)$job['id'], ['state' => 0, 'nextExecute' => 0])) {
                    TaskLogs::operateExecuteLog('bilibili', $userId, $taskName, '[失败] 账号或任务配置异常');
                }
                return;
            }

            $result = (new BilibiliTaskExecutor())->execute(
                $taskName,
                $accountData,
                $jobConfig
            );
            (new \app\service\NotificationService())->recordTask($user, 'bilibili', $userId,
                $taskName, (string)$task['name'], $result);
            TaskLogs::operateExecuteLog(
                'bilibili',
                $userId,
                $taskName,
                '[' . $this->statusTag($result) . '] ' . (string)$result['message']
            );

            if ($result['account_invalid']) {
                $this->accountInvalid('bilibili', $user, $userId);
                return;
            }

            try {
                Info::recordRun(100);
            } catch (Throwable $exception) {
                // 统计是尽力而为的：一次统计失败不能把一次成功的上游执行
                // 变成调度失败（那会重复执行任务并计入重试计数）。
            }
            Jobs::updateClaimedJob((int)$job['id'], BilibiliTaskExecutor::jobUpdates(
                $result, $jobConfig, $userId, (string)$account['timing']
            ));
        } catch (Throwable $exception) {
            // 租约未推进，任务在租约到期后自动重试；连续异常达到上限则停用
            // 任务并写一条 [失败] 终态记录。计数持久化在 job data 中。
            $jobData = BilibiliTaskExecutor::decodeSerializedArray((string)($job['data'] ?? ''));
            $jobData = is_array($jobData) ? $jobData : [];
            $nextData = SchedulerRetry::dataAfterFailure($jobData);
            if ($nextData === null) {
                $finalUpdates = [
                    'state' => 0,
                    'nextExecute' => 0,
                    'data' => serialize(SchedulerRetry::settledData($jobData)),
                ];
                if (Jobs::updateClaimedJob((int)$job['id'], $finalUpdates)) {
                    TaskLogs::operateExecuteLog('bilibili', $userId, $taskName,
                        '[失败] ' . SchedulerRetry::finalMessage(SchedulerRetry::attemptsIn($jobData) + 1));
                }
            } else if (Jobs::updateClaimedJob((int)$job['id'], ['data' => serialize($nextData)])) {
                // 计数写入落空说明租约已被接管，任务不再由本调度推进，
                // 此时不能留下“稍后自动重试”的承诺。
                TaskLogs::operateExecuteLog('bilibili', $userId, $taskName, '[重试中] 执行异常，稍后自动重试');
            }
        }
    }

    public function execute($do)
    {
        // 历史自调用入口：RUN_KEY 曾随 URL 传播，任务已在 index() 进程内执行，
        // 该端点仅作路由兼容并拒绝外部触发。
        return resultJson(-1001, 'RunKey Access Denied!');
    }


    private function disableOfflineJobs(): void
    {
        Jobs::retireOfflineJobs();
    }

}
