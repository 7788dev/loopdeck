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
        if (count($jobs) == 0) {
            return resultJson(-1002, '没有要执行的任务');
        }
        foreach ($jobs as $job) {
            if (in_array($job['user_id'], $vip_expired_userIds)) continue;
            if (!Jobs::claimDueJob((int)$job['id'], (int)$job['nextExecute'])) continue;
            $user = Users::where('uid' , '=' , $job['uid'])->find();
            if($user == null){
                Jobs::delJob('heybox',$job['user_id']);
                continue;
            }
            $task = Tasks::where('type', '=', 'heybox')->where('execute_name', '=', $job['do'])->find();
            $account = Accounts::where('type', '=', 'heybox')->where('user_id', '=', $job['user_id'])->find();
            if ($account == null) {
                Accounts::delById('heybox', $job['user_id']);
                Jobs::delJob('heybox',$job['user_id']);
                continue;
            }
            if (!AutomaticSchedule::isConfigured((string)($account['timing'] ?? ''))) {
                Jobs::where('id', $job['id'])->update(['nextExecute' => 0]);
                continue;
            }
            if ($task['vip'] == 1 && strtotime($user['vip_end'] ?? '') < time()) {  // 判断会员功能、用户会员是否过期
                $this->vipExpired('heybox', $user['uid'], $job['user_id']); // 会员过期处理
                // 将VIP过期的任务用户id放入一个数组，用于后续判断
                $vip_expired_userIds[] = $job['user_id'];
                continue;
            } else {
                // 凭据从数据库读取并在本进程执行，不再经 URL 传播 RUN_KEY/pkey
                $this->runJob((string)$job['do'], $account, $user);
            }
            Info::recordRun(100);
            Jobs::updateJobInfo('heybox', $job['do'], $job['user_id'], [ // 更新任务执行信息
                'lastExecute' => date("Y-m-d H:i:s"),
                'nextExecute' => AutomaticSchedule::nextExecution(
                    'heybox',
                    (string)$job['user_id'],
                    (string)$account['timing']
                ) ?? 0,
            ]);
        }
        return resultJson(1000, '执行任务成功');
    }

    /**
     * 在当前进程执行一条小黑盒任务：凭据从库内 data 反序列化，绝不进 URL。
     */
    private function runJob(string $do, $account, $user): void
    {
        try {
            // 任务名来自 jobs.do，白名单防止脏数据把任意方法名派发到 BlackBox
            if (!in_array($do, self::TASKS, true)) {
                TaskLogs::operateExecuteLog('heybox', $account['user_id'], $do, '[失败] 未知的任务名称，已跳过');
                return;
            }
            $account_info = safe_unserialize_array((string)$account['data']);
            $accountUserId = trim((string)($account_info['user_id'] ?? $account['user_id']));
            $pkey = trim((string)($account_info['pkey'] ?? ''));
            if ($accountUserId === '' || $pkey === '') {
                TaskLogs::operateExecuteLog('heybox', $account['user_id'], $do, '[失败] 账号数据损坏，请重新添加账号');
                return;
            }
            $heybox = new BlackBox($accountUserId, $pkey);
            $execute = $heybox->{$do}();
            if ($heybox->cookiezt) {
                $this->accountInvalid('heybox', $user, $account['user_id']); // 账号失效处理
                return;
            }
            TaskLogs::operateExecuteLog('heybox', $account['user_id'], $do,
                '[' . $this->statusTag($execute) . '] ' . (string)($execute['message'] ?? '小黑盒任务执行完成'));
        } catch (Throwable $exception) {
            // 异常只影响本条任务；租约未推进，下轮调度自动重试
            TaskLogs::operateExecuteLog('heybox', $account['user_id'], $do, '[重试中] 任务调度异常，已安排稍后重试');
        }
    }

    public function execute($do)
    {
        // 历史自调用入口：RUN_KEY 与账号 pkey 曾随 URL 传播，任务已在 index()
        // 进程内执行，该端点仅作路由兼容并拒绝外部触发。
        return resultJson(-1001, 'RunKey Access Denied!');
    }
}
