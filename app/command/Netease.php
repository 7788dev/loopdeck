<?php
declare (strict_types = 1);

namespace app\command;

use app\index\model\Accounts;
use app\index\model\Info;
use app\index\model\Jobs;
use app\index\model\TaskLogs;
use app\index\model\Tasks;
use app\index\model\Users;
use app\cron\controller\Common as CronCommon;
use app\service\AutomaticSchedule;
use app\service\NeteaseSchedule;
use app\service\NotificationService;
use app\service\SchedulerRetry;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use netease\Netease as NeteaseAPI;
use Throwable;

class Netease extends Command
{
    protected function configure()
    {
        // 指令配置
        $this->setName('netease')
            ->addArgument('interval', Argument::OPTIONAL, "执行任务数量", '100')
            ->setDescription('网易云类任务');
    }

    protected function execute(Input $input, Output $output)
    {
        $interval = trim($input->getArgument('interval'));
        $executed = 0;
        $jobs = Jobs::where([['type', '=', 'netease'], ['zid', '=', 1], ['state', '=', 1], ['nextExecute', '>', 0], ['nextExecute', '<=', time()]])
            ->whereIn('do', ['sign', 'login_work', 'musician_task', 'evaluate', 'daka_new', 'yunbei_task', 'vip_growth_task'])
            ->limit((int)$interval)
            ->select();
        foreach ($jobs as $job) {
            $jobId = (int)$job['id'];
            if (!Jobs::claimDueJob((int)$job['id'], (int)$job['nextExecute'])) continue;
            try {
                $user = Users::where('uid', $job['uid'])->where('web_id', 1)->where('state', 1)->find();
                $task = Tasks::where('type', 'netease')->where('execute_name', $job['do'])->where('state', 1)->find();
                if (!$user || !$task) {
                    Jobs::updateClaimedJob((int)$job['id'], ['state' => 0, 'nextExecute' => 0]);
                    continue;
                }
                if ($task['vip'] == 1 && strtotime($user['vip_end'] ?? '') < time()
                    && $this->vipExpired('netease', $user['uid'], $job['user_id'])) {
                    continue;
                }
                $account = Accounts::where('type', '=', 'netease')
                    ->where('user_id', '=', $job['user_id'])
                    ->where('uid', '=', $job['uid'])
                    ->find();
                if ($account == null) {
                    Accounts::delById('netease', $job['user_id'], (int)$job['uid']);
                    Jobs::delJob('netease', $job['user_id'], (int)$job['uid']);
                    continue;
                }
                if ((int)$account['state'] !== 1) {
                    Jobs::updateClaimedJob((int)$job['id'], ['state' => -1, 'nextExecute' => 0]);
                    continue;
                }
                if (!AutomaticSchedule::isConfigured((string)($account['timing'] ?? ''))) {
                    Jobs::updateClaimedJob((int)$job['id'], ['nextExecute' => 0]);
                    continue;
                }
                try {
                $job = Jobs::where('id', $jobId)->find();
                if (!$job) {
                    continue;
                }
                    $account_info = safe_unserialize_array($account['data']);
                    $job_config = safe_unserialize_array($job['data'] ?? '');
                    $accountUserId = trim((string)($account_info['user_id'] ?? ''));
                    $csrf = trim((string)($account_info['csrf'] ?? ''));
                    $musicU = trim((string)($account_info['musicu'] ?? ''));
                    if ($accountUserId === '' || $csrf === '' || $musicU === '') {
                        // Match the HTTP scheduler: incomplete credentials
                        // invalidate the account instead of reaching upstream
                        // with an empty client.
                        $this->accountInvalid('netease', $user, (string)$job['user_id']);
                        continue;
                    }
                    $do = new NeteaseAPI($accountUserId, $csrf, $musicU, $job_config);
                    $execute = $do->{$job['do']}();
                    if (!is_array($execute)) {
                        throw new \RuntimeException('Invalid NetEase task response');
                    }
                } catch (Throwable $exception) {
                    // Exceptions are scoped to this job: reschedule it and keep
                    // the rest of the batch running. Consecutive failures are
                    // counted in the job payload and stop retrying at the cap.
                    $jobData = safe_unserialize_array((string)($job['data'] ?? ''));
                    $nextData = SchedulerRetry::dataAfterFailure($jobData);
                    if ($nextData === null) {
                        $attempts = SchedulerRetry::attemptsIn($jobData) + 1;
                        if (Jobs::updateClaimedJob((int)$job['id'], [
                            'state' => 0,
                            'nextExecute' => 0,
                            'data' => serialize(SchedulerRetry::settledData($jobData)),
                        ]) && CronCommon::shouldReportTaskStatus('netease', (string)$job['do'], '失败')) {
                            TaskLogs::operateExecuteLog('netease', (string)$job['user_id'], (string)$job['do'],
                                '[失败] ' . SchedulerRetry::finalMessage($attempts));
                        }
                        continue;
                    }
                    if (Jobs::updateClaimedJob((int)$job['id'], [
                        'nextExecute' => time() + max(60, min(3600, (int)(config('sys.reExecute_time') ?: 300))),
                        'data' => serialize($nextData),
                    ]) && CronCommon::shouldReportTaskStatus('netease', (string)$job['do'], '重试中')) {
                        TaskLogs::operateExecuteLog('netease', (string)$job['user_id'], (string)$job['do'],
                            '[重试中] 执行异常，稍后自动重试');
                    }
                    continue;
                }
                if ($do->cookiezt) {
                    $account = Accounts::where('type', '=', 'netease')
                        ->where('user_id', '=', $job['user_id'])
                        ->where('uid', '=', $job['uid'])
                        ->find();
                    $user = $account === null ? null : Users::where('uid', '=', $account['uid'])->find();
                    if ($user !== null) {
                        $this->accountInvalid('netease', $user, $job['user_id']); // 账号失效处理
                    }
                    continue; // 失效只影响本账号，其余任务继续执行
                } else {
                    $status = (new CronCommon())->statusTag($execute);
                    if (CronCommon::shouldReportTaskStatus('netease', (string)$job['do'], $status)) {
                        TaskLogs::operateExecuteLog('netease', $job['user_id'], $job['do'],
                            '[' . $status . '] ' . $execute['message']);
                    }
                    (new NotificationService())->recordTask($user, 'netease', (string)$job['user_id'],
                        (string)$job['do'], (string)$task['name'], $execute);
                }
                try {
                    Info::recordRun(100);
                } catch (Throwable $exception) {
                    // Statistics are best-effort: a stats failure must not turn
                    // a completed upstream run into a scheduler retry.
                }
                $nextExecute = NeteaseSchedule::nextAfterResult(
                    (string)$job['user_id'], (string)$account['timing'], $execute
                );
                Jobs::updateClaimedJob((int)$job['id'], [
                    'lastExecute' => date("Y-m-d H:i:s"),
                    'nextExecute' => $nextExecute,
                    // A completed run breaks the consecutive-failure streak.
                    'data' => serialize(SchedulerRetry::dataAfterSuccess($job_config)),
                ]);
                $executed++;
            } catch (Throwable $exception) {
                // 一次查询或记账失败不能中断整批任务；租约在 finally 中释放，
                // 任务在租约到期后自动重试。
                continue;
            } finally {
                Jobs::releaseDueJob($jobId);
            }
        }
        $count = $executed;
        $output->writeln("成功执行 {$count} 条任务：" . date("Y-m-d H:i:s"));
    }

    protected function vipExpired($type, $uid, $user_id)
    {
        return (new \app\cron\controller\Common())->vipExpired($type, $uid, $user_id);
    }

    protected function accountInvalid($type, $user, $user_id)
    {
        $stateChanged = Accounts::where('user_id', '=', $user_id)
            ->where('type', '=', $type)
            ->where('uid', '=', $user['uid'])
            ->where('state', '=', 1)
            ->update(['state' => 0]);
        // 只有启用中的任务翻到 -1（账号失效）：updateJob 在下次保存账号时会
        // 复活 -1 行，用户手动停用的任务必须保持停用。
        Jobs::where('user_id', '=', $user_id)->where('uid', '=', $user['uid'])
            ->where('type', '=', $type)->where('state', '=', 1)->update(['state' => -1]);
        if ($stateChanged > 0) {
            (new NotificationService())->sendAccountInvalid($user, '网易云音乐', (string)$user_id);
        }
    }
}
