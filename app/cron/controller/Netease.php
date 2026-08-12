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
        if (count($jobs) == 0) {
            return resultJson(-1002, '没有要执行的任务');
        }
        foreach ($jobs as $job) {
            if (!Jobs::claimDueJob((int)$job['id'], (int)$job['nextExecute'])) continue;
            if (in_array($job['user_id'], $vip_expired_userIds)) continue;
            $user = Users::where('uid', '=', $job['uid'])->find();
            if ($user == null) {
                Jobs::delJob('netease', $job['user_id']);
                continue;
            }
            $task = Tasks::where('type', '=', 'netease')->where('execute_name', '=', $job['do'])->find();
            $account = Accounts::where('type', '=', 'netease')->where('user_id', '=', $job['user_id'])->find();
            if ($account == null) {
                Accounts::delById('netease', $job['user_id']);
                Jobs::delJob('netease', $job['user_id']);
                continue;
            }
            if (!AutomaticSchedule::isConfigured((string)($account['timing'] ?? ''))) {
                Jobs::where('id', $job['id'])->update(['nextExecute' => 0]);
                continue;
            }
            if ($task['vip'] == 1 && strtotime($user['vip_end'] ?? '') < time()) {  // 判断会员功能、用户会员是否过期
                $this->vipExpired('netease', $user['uid'], $job['user_id']); // 会员过期处理
                // 将VIP过期的任务用户id放入一个数组，用于后续判断
                $vip_expired_userIds[] = $job['user_id'];
                continue;
            }

            $this->runJob((string)$job['do'], $account, $user, (string)($job['data'] ?? ''));
            Info::where('sysid', '=', '100')->inc('times', 1)->update();
            Info::where('sysid', '=', '100')->update(['last' => date('Y-m-d H:i:s')]);
            Jobs::updateJobInfo('netease', $job['do'], $job['user_id'], [ // 更新任务执行信息
                'lastExecute' => date("Y-m-d H:i:s"),
                'nextExecute' => AutomaticSchedule::nextExecution(
                    'netease',
                    (string)$job['user_id'],
                    (string)$account['timing']
                ) ?? 0,
            ]);
        }
        return resultJson(1000, '执行任务成功');
    }

    /**
     * Run one job in this process. Credentials are read from the database and
     * never travel through a URL.
     *
     * @param mixed $account
     * @param mixed $user
     */
    private function runJob(string $do, $account, $user, string $jobData): void
    {
        if (!in_array($do, self::TASKS, true)) {
            TaskLogs::operateExecuteLog('netease', $account['user_id'], $do, '未知的任务名称，已跳过');
            return;
        }

        $accountData = safe_unserialize_array((string)$account['data']);
        $userId = trim((string)($accountData['user_id'] ?? $account['user_id']));
        $csrf = trim((string)($accountData['csrf'] ?? ''));
        $musicU = trim((string)($accountData['musicu'] ?? ''));
        if ($userId === '' || $csrf === '' || $musicU === '') {
            $this->accountInvalid('netease', $user, $account['user_id']);
            return;
        }

        $netease = new NeteaseAPI($userId, $csrf, $musicU, safe_unserialize_array($jobData));
        $execute = $netease->{$do}();
        if ($netease->cookiezt) {
            $this->accountInvalid('netease', $user, $account['user_id']); // 账号失效处理
            return;
        }
        TaskLogs::operateExecuteLog(
            'netease',
            $account['user_id'],
            $do,
            (string)($execute['message'] ?? '网易云任务执行完成')
        ); // 写入运行日志
    }
}
