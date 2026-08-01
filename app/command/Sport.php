<?php
declare (strict_types = 1);

namespace app\command;

use app\index\model\Accounts;
use app\index\model\Info;
use app\index\model\Jobs;
use app\index\model\TaskLogs;
use app\index\model\Tasks;
use app\index\model\Users;
use app\service\AutomaticSchedule;
use app\service\BarkNotificationService;
use think\console\Command;
use think\console\Input;
use sport\Step as SportAPI;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;

class Sport extends Command
{
    protected function configure()
    {
        // 指令配置
        $this->setName('sport')
            ->addArgument('interval', Argument::OPTIONAL, "执行任务数量", '100')
            ->setDescription('运动类任务');
    }

    protected function execute(Input $input, Output $output)
    {
        $interval = trim($input->getArgument('interval'));
        $vip_expired_userIds = [];
        $jobs = Jobs::where([['type', '=', 'sport'], ['state', '=', 1], ['nextExecute', '>', 0], ['nextExecute', '<=', time()]])
            ->limit((int)$interval)
            ->select();
        foreach ($jobs as $job) {
            if (in_array($job['user_id'], $vip_expired_userIds)) continue;
            $user = Users::where('uid' , '=' , $job['uid'])->find();
            $task = Tasks::where('type', '=', 'sport')->where('execute_name', '=', $job['do'])->find();
            if ($task['vip'] == 1 && strtotime($user['vip_end'] ?? '') < time()) {  // 判断会员功能、用户会员是否过期
                $this->vipExpired('sport', $user['uid'], $job['user_id']); // 会员过期处理
                // 将VIP过期的任务用户id放入一个数组，用于后续判断
                $vip_expired_userIds[] = $job['user_id'];
                continue;
            }
            $account = Accounts::where('type', '=', 'sport')->where('user_id', '=', $job['user_id'])->find();
            if ($account == null) {
                Accounts::delById($job['user_id']);
                Jobs::delJob('netease',$job['user_id']);
                continue;
            }
            if (!AutomaticSchedule::isConfigured((string)($account['timing'] ?? ''))) {
                Jobs::where('id', $job['id'])->update(['nextExecute' => 0]);
                continue;
            }
            $account_info = safe_unserialize_array($account['data']);
            $job_config = safe_unserialize_array($job['data'] ?? '');
            $job_config['username'] = $account_info['username'];
            $job_config['password'] = $account_info['password'];
            $do = new SportAPI($account_info['user_id'], $account_info['login_token'], $account_info['app_token'], $job_config);
            $execute = $do->{$job['do']}();
            if ($do->cookiezt) {
                $account = Accounts::where('type', '=', 'sport')->where('user_id', '=', $job['user_id'])->find();
                $user = Users::where('uid', '=', $account['uid'])->find();
                $this->accountInvalid('sport', $user, $job['user_id']); // 账号失效处理
                break;
            } else {
                TaskLogs::operateExecuteLog('sport', $job['user_id'], $job['do'], $execute['message']); // 写入运行日志
            }
            Info::where('sysid','=','100')->inc('times',1)->update();
            Info::where('sysid','=','100')->update(['last' => date('Y-m-d H:i:s')]);
            Jobs::updateJobInfo($job['do'], $job['user_id'], [ // 更新任务执行信息
                'lastExecute' => date("Y-m-d H:i:s"),
                'nextExecute' => AutomaticSchedule::nextExecution(
                    'sport',
                    (string)$job['user_id'],
                    (string)$account['timing']
                ) ?? 0,
            ]);
        }
        $count = count($jobs);
        $output->writeln("成功执行 {$count} 条任务：" . date("Y-m-d H:i:s"));
    }

    protected function vipExpired($type, $uid, $user_id)
    {
        $membershipChanged = Users::where('uid', '=', $uid)
            ->whereRaw('(`vip_start` IS NOT NULL OR `vip_end` IS NOT NULL)')
            ->update(['vip_start' => NULL, 'vip_end' => NULL]);
        Jobs::where('type', '=', $type)->where('user_id', '=', $user_id)->update(['state' => 0]);
        $data = [
            'type' => $type,
            'user_id' => $user_id,
            'do' => '系统提示',
            'response' => '会员过期，请开通会员后再试',
        ];
        TaskLogs::operateLog($data);
        if ($membershipChanged > 0) {
            $user = Users::where('uid', '=', $uid)->find();
            if ($user) {
                (new BarkNotificationService())->sendVipExpired($user);
            }
        }
    }

    protected function accountInvalid($type, $user, $user_id)
    {
        $stateChanged = Accounts::where('user_id', '=', $user_id)
            ->where('type', '=', $type)
            ->where('uid', '=', $user['uid'])
            ->where('state', '=', 1)
            ->update(['state' => 0]);
        Jobs::where('user_id', '=', $user_id)->where('uid', '=', $user['uid'])->where('type', '=', $type)->update(['state' => -1]);
        if ($stateChanged > 0 && config('sys.mail_invalid') == 1) {
            $msg = get_mail_tempale(3, $user, '小米运动');
            $sub = config('web.webname') . ' - 失效提醒';
            send_mail($user['mail'], $sub, $msg);
        }
        if ($stateChanged > 0) {
            (new BarkNotificationService())->sendAccountInvalid($user, '小米运动');
        }
    }
}
