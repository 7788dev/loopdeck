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
            if (!Jobs::claimDueJob((int)$job['id'], (int)$job['nextExecute'])) continue;
            try {
                $user = Users::where('uid', $job['uid'])->where('web_id', 1)->where('state', 1)->find();
                $task = Tasks::where('type', 'netease')->where('execute_name', $job['do'])->where('state', 1)->find();
                if (!$user || !$task) {
                    Jobs::where('id', $job['id'])->update(['state' => 0, 'nextExecute' => 0]);
                    continue;
                }
                if ($task['vip'] == 1 && strtotime($user['vip_end'] ?? '') < time()) {  // 判断会员功能、用户会员是否过期
                    $this->vipExpired('netease', $user['uid'], $job['user_id']); // 会员过期处理
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
                if (!AutomaticSchedule::isConfigured((string)($account['timing'] ?? ''))) {
                    Jobs::where('id', $job['id'])->update(['nextExecute' => 0]);
                    continue;
                }
                try {
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
                    // the rest of the batch running.
                    Jobs::updateClaimedJob((int)$job['id'], [
                        'nextExecute' => time() + max(60, min(3600, (int)(config('sys.reExecute_time') ?: 300))),
                    ]);
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
                Info::recordRun(100);
                $nextExecute = NeteaseSchedule::nextAfterResult(
                    (string)$job['user_id'], (string)$account['timing'], $execute
                );
                Jobs::updateClaimedJob((int)$job['id'], [
                    'lastExecute' => date("Y-m-d H:i:s"),
                    'nextExecute' => $nextExecute,
                ]);
                $executed++;
            } finally {
                Jobs::releaseDueJob((int)$job['id']);
            }
        }
        $count = $executed;
        $output->writeln("成功执行 {$count} 条任务：" . date("Y-m-d H:i:s"));
    }

    protected function vipExpired($type, $uid, $user_id)
    {
        (new \app\cron\controller\Common())->vipExpired($type, $uid, $user_id);
    }

    protected function accountInvalid($type, $user, $user_id)
    {
        $stateChanged = Accounts::where('user_id', '=', $user_id)
            ->where('type', '=', $type)
            ->where('uid', '=', $user['uid'])
            ->where('state', '=', 1)
            ->update(['state' => 0]);
        Jobs::where('user_id', '=', $user_id)->where('uid', '=', $user['uid'])->where('type', '=', $type)->update(['state' => -1]);
        if ($stateChanged > 0) {
            (new NotificationService())->sendAccountInvalid($user, '网易云音乐', (string)$user_id);
        }
    }
}
