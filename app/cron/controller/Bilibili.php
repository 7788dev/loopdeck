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
use think\facade\Request;
use Throwable;

class Bilibili extends Common
{
    /** @var int 本轮实际领取并执行（含失败）的任务数 */
    private int $scheduled = 0;

    public function index()
    {
        $cronKey = (string)Request::get('cronkey', '');
        if ($cronKey === '' || !hash_equals((string)config('sys.cronkey'), $cronKey)) {
            return resultJson(-1000, 'CronKey Access Denied!');
        }

        $this->disableOfflineJobs();

        $limit = max(1, (int)config('sys.interval'));
        $jobs = Jobs::where('type', 'bilibili')
            ->where('state', 1)
            ->where('nextExecute', '>', 0)
            ->where('nextExecute', '<=', time())
            ->whereIn('do', BilibiliTaskExecutor::executableTasks())
            ->order('nextExecute', 'asc')
            ->limit($limit)
            ->select();

        foreach ($jobs as $job) {
            if (!Jobs::claimDueJob((int)$job['id'], (int)$job['nextExecute'])) {
                continue;
            }
            $this->scheduled++;
            $userId = (string)$job['user_id'];
            $user = Users::where('uid', $job['uid'])->find();
            $account = Accounts::where('type', 'bilibili')
                ->where('user_id', $userId)
                ->where('uid', $job['uid'])
                ->where('state', 1)
                ->find();
            $task = Tasks::where('type', 'bilibili')
                ->where('execute_name', $job['do'])
                ->where('state', 1)
                ->find();

            if (!$user || !$account || !$task) {
                Jobs::where('id', $job['id'])->update(['state' => 0, 'nextExecute' => 0]);
                continue;
            }
            if (!AutomaticSchedule::isConfigured((string)($account['timing'] ?? ''))) {
                Jobs::where('id', $job['id'])->update(['nextExecute' => 0]);
                continue;
            }
            if ((int)$task['vip'] === 1 && strtotime((string)($user['vip_end'] ?? '')) < time()) {
                $this->vipExpired('bilibili', $user['uid'], $userId);
                continue;
            }
            // 凭据/配置都在库内，任务在本进程执行，RUN_KEY 不再进 URL
            $this->runJob((string)$job['do'], $job, $user, $account, $task);
        }

        if ($this->scheduled === 0) {
            return resultJson(-1002, '没有要执行的任务');
        }
        return resultJson(1000, '已调度 ' . $this->scheduled . ' 条任务');
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
            $globalConfig = $this->globalConfig((int)$job['uid'], $userId);
            if ($accountData === null || $jobConfig === null || $globalConfig === null) {
                Jobs::where('id', $job['id'])->update(['state' => 0, 'nextExecute' => 0]);
                TaskLogs::operateExecuteLog('bilibili', $userId, $taskName, '[失败] 账号或任务配置损坏');
                return;
            }

            $result = (new BilibiliTaskExecutor())->execute(
                $taskName,
                $accountData,
                array_replace($globalConfig, $jobConfig)
            );
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

            Info::recordRun(100);
            Jobs::where('id', $job['id'])->update([
                'lastExecute' => date('Y-m-d H:i:s'),
                'nextExecute' => $this->nextExecuteAt($account, $userId),
            ]);
        } catch (Throwable $exception) {
            // 租约未推进，任务稍后自动重试；日志标签按仓库规范走 [重试中]
            TaskLogs::operateExecuteLog('bilibili', $userId, $taskName, '[重试中] 任务调度异常，已安排稍后重试');
        }
    }

    public function execute($do)
    {
        // 历史自调用入口：RUN_KEY 曾随 URL 传播，任务已在 index() 进程内执行，
        // 该端点仅作路由兼容并拒绝外部触发。
        return resultJson(-1001, 'RunKey Access Denied!');
    }


    private function globalConfig(int $uid, string $userId): ?array
    {
        $payload = Jobs::where('type', 'bilibili')
            ->where('uid', $uid)
            ->where('user_id', $userId)
            ->where('do', 'globalroom')
            ->value('data');
        return BilibiliTaskExecutor::decodeSerializedArray(is_string($payload) ? $payload : '');
    }

    private function nextExecuteAt($account, string $userId): int
    {
        return AutomaticSchedule::nextExecution(
            'bilibili',
            $userId,
            (string)($account['timing'] ?? '')
        ) ?? 0;
    }

    private function disableOfflineJobs(): void
    {
        $offlineTasks = array_keys(BilibiliTaskExecutor::OFFLINE_TASKS);
        if ($offlineTasks === []) {
            return;
        }

        Jobs::where('type', 'bilibili')
            ->whereIn('do', $offlineTasks)
            ->update(['state' => 0, 'nextExecute' => 0]);
    }

}
