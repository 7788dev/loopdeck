<?php

declare(strict_types=1);

namespace app\command;

use app\index\model\Accounts;
use app\index\model\Info;
use app\index\model\Jobs;
use app\index\model\TaskLogs;
use app\index\model\Tasks;
use app\index\model\Users;
use app\service\AutomaticSchedule;
use app\service\BilibiliTaskExecutor;
use app\service\BarkNotificationService;
use app\service\SchedulerRetry;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;
use Throwable;

class Bilibili extends Command
{
    protected function configure()
    {
        $this->setName('bilibili')
            ->addArgument('interval', Argument::OPTIONAL, '执行任务数量', '100')
            ->setDescription('哔哩哔哩类任务');
    }

    protected function execute(Input $input, Output $output)
    {
        $limit = max(1, min(1000, (int)$input->getArgument('interval')));
        $executor = new BilibiliTaskExecutor();
        $this->disableOfflineJobs();
        $executed = 0;
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
            $userId = (string)$job['user_id'];
            $taskName = (string)$job['do'];
            if (!Jobs::claimDueJob((int)$job['id'], (int)$job['nextExecute'])) {
                continue;
            }

            try {
                $user = Users::where('uid', $job['uid'])->find();
                if (!$user) {
                    Jobs::where('type', 'bilibili')->where('user_id', $userId)->where('uid', $job['uid'])->delete();
                    continue;
                }
                if ((int)$user['state'] !== 1 || (int)$user['web_id'] !== 1) {
                    Jobs::updateClaimedJob((int)$job['id'], ['state' => 0, 'nextExecute' => 0]);
                    continue;
                }

                $task = Tasks::where('type', 'bilibili')
                    ->where('execute_name', $taskName)
                    ->where('state', 1)
                    ->find();
                if (!$task) {
                    if (Jobs::updateClaimedJob((int)$job['id'], ['state' => 0, 'nextExecute' => 0])) {
                        $this->writeLog($userId, $taskName, '任务不存在或已停用');
                    }
                    continue;
                }

                if ((int)$task['vip'] === 1 && strtotime((string)($user['vip_end'] ?? '')) < time()) {
                    $this->vipExpired('bilibili', (int)$user['uid'], $userId);
                    continue;
                }

                $account = Accounts::where('type', 'bilibili')
                    ->where('user_id', $userId)
                    ->where('uid', $job['uid'])
                    ->find();
                if (!$account) {
                    Jobs::where('type', 'bilibili')->where('user_id', $userId)->where('uid', $job['uid'])->delete();
                    continue;
                }
                if ((int)$account['state'] !== 1) {
                    Jobs::where('type', 'bilibili')->where('user_id', $userId)->where('uid', $job['uid'])->update(['state' => -1]);
                    continue;
                }
                if (!AutomaticSchedule::isConfigured((string)($account['timing'] ?? ''))) {
                    Jobs::updateClaimedJob((int)$job['id'], ['nextExecute' => 0]);
                    continue;
                }

                $accountData = BilibiliTaskExecutor::decodeSerializedArray((string)$account['data']);
                $jobConfig = BilibiliTaskExecutor::decodeSerializedArray((string)($job['data'] ?? ''));
                if ($accountData === null || $jobConfig === null) {
                    if (Jobs::updateClaimedJob((int)$job['id'], ['state' => 0, 'nextExecute' => 0])) {
                        $this->writeLog($userId, $taskName, '账号或任务配置损坏，请重新登录后配置');
                    }
                    continue;
                }

                $result = $executor->execute($taskName, $accountData, $jobConfig);
                $this->writeLog($userId, $taskName, '[' . (new \app\cron\controller\Common())->statusTag($result) . '] ' . $result['message']);
                (new \app\service\NotificationService())->recordTask($user, 'bilibili', $userId,
                    $taskName, (string)$task['name'], $result);

                if ($result['account_invalid']) {
                    $this->accountInvalid('bilibili', $user, $userId);
                    continue;
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
                $executed++;
            } catch (Throwable $exception) {
                // 租约未推进，任务在租约到期后自动重试；连续异常达到上限则停
                // 用任务并写一条 [失败] 终态记录。计数持久化在 job data 中。
                $jobData = BilibiliTaskExecutor::decodeSerializedArray((string)($job['data'] ?? ''));
                $jobData = is_array($jobData) ? $jobData : [];
                $nextData = SchedulerRetry::dataAfterFailure($jobData);
                if ($nextData === null) {
                    $attempts = SchedulerRetry::attemptsIn($jobData) + 1;
                    if (Jobs::updateClaimedJob((int)$job['id'], [
                        'state' => 0,
                        'nextExecute' => 0,
                        'data' => serialize(SchedulerRetry::settledData($jobData)),
                    ])) {
                        $this->writeLog($userId, $taskName, '[失败] ' . SchedulerRetry::finalMessage($attempts));
                    }
                } elseif (Jobs::updateClaimedJob((int)$job['id'], ['data' => serialize($nextData)])) {
                    // 计数写入落空说明租约已被接管，任务不再由本调度推进，
                    // 此时不能留下“稍后重试”的承诺。
                    $this->writeLog($userId, $taskName, '[重试中] 任务调度异常，等待租约到期后重试');
                }
            } finally {
                Jobs::releaseDueJob((int)$job['id']);
            }
        }

        $output->writeln("成功执行 {$executed} 条任务：" . date('Y-m-d H:i:s'));
        return 0;
    }

    private function disableOfflineJobs(): void
    {
        Jobs::retireOfflineJobs();
    }

    private function writeLog(string $userId, string $task, string $message): void
    {
        TaskLogs::operateExecuteLog('bilibili', $userId, $task, $message);
    }

    private function vipExpired(string $type, int $uid, string $userId): void
    {
        (new \app\cron\controller\Common())->vipExpired($type, $uid, $userId);
    }

    private function accountInvalid(string $type, $user, string $userId): void
    {
        $stateChanged = Accounts::where('type', $type)
            ->where('user_id', $userId)
            ->where('uid', $user['uid'])
            ->where('state', 1)
            ->update(['state' => 0]);
        // 只有启用中的任务翻到 -1（账号失效）：updateJob 在下次保存账号时会
        // 复活 -1 行，用户手动停用的任务必须保持停用。
        Jobs::where('type', $type)->where('user_id', $userId)->where('uid', $user['uid'])
            ->where('state', 1)->update(['state' => -1]);
        if ($stateChanged > 0) {
            (new BarkNotificationService())->sendAccountInvalid($user, '哔哩哔哩', $userId);
        }
    }
}
