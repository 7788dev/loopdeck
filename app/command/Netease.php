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
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use netease\Netease as NeteaseAPI;

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
        $vip_expired_userIds = [];
        $jobs = Jobs::where([['type', '=', 'netease'], ['state', '=', 1], ['nextExecute', '>', 0], ['nextExecute', '<=', time()]])
            ->limit((int)$interval)
            ->select();
        foreach ($jobs as $job) {
            if (in_array($job['user_id'], $vip_expired_userIds)) continue;
            $user = Users::where('uid' , '=' , $job['uid'])->find();
            $task = Tasks::where('type', '=', 'netease')->where('execute_name', '=', $job['do'])->find();
            if ($task['vip'] == 1 && strtotime($user['vip_end'] ?? '') < time()) {  // 判断会员功能、用户会员是否过期
                $this->vipExpired('netease', $user['uid'], $job['user_id']); // 会员过期处理
                // 将VIP过期的任务用户id放入一个数组，用于后续判断
                $vip_expired_userIds[] = $job['user_id'];
                continue;
            }
            $account = Accounts::where('type', '=', 'netease')->where('user_id', '=', $job['user_id'])->find();
            if ($account == null) {
                Accounts::delById('netease', $job['user_id']);
                Jobs::delJob('netease',$job['user_id']);
                continue;
            }
            if (!AutomaticSchedule::isConfigured((string)($account['timing'] ?? ''))) {
                Jobs::where('id', $job['id'])->update(['nextExecute' => 0]);
                continue;
            }
            $account_info = safe_unserialize_array($account['data']);
            $job_config = safe_unserialize_array($job['data'] ?? '');
            $do = new NeteaseAPI($account_info['user_id'], $account_info['csrf'], $account_info['musicu'], $job_config);
            $execute = $do->{$job['do']}();
            if ($do->cookiezt) {
                $account = Accounts::where('type', '=', 'netease')->where('user_id', '=', $job['user_id'])->find();
                $user = Users::where('uid', '=', $account['uid'])->find();
                $this->accountInvalid('netease', $user, $job['user_id']); // 账号失效处理
                break;
            } else {
                TaskLogs::operateExecuteLog('netease', $job['user_id'], $job['do'], $execute['message']); // 写入运行日志
            }
            Info::where('sysid','=','100')->inc('times',1)->update();
            Info::where('sysid','=','100')->update(['last' => date('Y-m-d H:i:s')]);
            Jobs::updateJobInfo('netease', $job['do'], $job['user_id'], [ // 更新任务执行信息
                'lastExecute' => date("Y-m-d H:i:s"),
                'nextExecute' => AutomaticSchedule::nextExecution(
                    'netease',
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
            $msg = get_mail_tempale(3, $user, '网易云音乐');
            $sub = config('web.webname') . ' - 失效提醒';
            send_mail($user['mail'], $sub, $msg);
        }
        if ($stateChanged > 0) {
            (new BarkNotificationService())->sendAccountInvalid($user, '网易云音乐');
        }
    }
}
