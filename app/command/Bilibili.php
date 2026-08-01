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
        $vipExpiredAccounts = [];
        $executed = 0;
        $jobs = Jobs::where('type', 'bilibili')
            ->where('state', 1)
            ->where('nextExecute', '>', 0)
            ->where('nextExecute', '<=', time())
            ->whereIn('do', BilibiliTaskExecutor::TASKS)
            ->order('nextExecute', 'asc')
            ->limit($limit)
            ->select();

        foreach ($jobs as $job) {
            $userId = (string)$job['user_id'];
            $taskName = (string)$job['do'];
            if (isset($vipExpiredAccounts[$userId])) {
                continue;
            }

            try {
                $user = Users::where('uid', $job['uid'])->find();
                if (!$user) {
                    Jobs::where('type', 'bilibili')->where('user_id', $userId)->where('uid', $job['uid'])->delete();
                    continue;
                }

                $task = Tasks::where('type', 'bilibili')
                    ->where('execute_name', $taskName)
                    ->where('state', 1)
                    ->find();
                if (!$task) {
                    Jobs::where('id', $job['id'])->update(['state' => 0]);
                    $this->writeLog($userId, $taskName, '任务不存在或已停用');
                    continue;
                }

                if ((int)$task['vip'] === 1 && strtotime((string)($user['vip_end'] ?? '')) < time()) {
                    $this->vipExpired('bilibili', (int)$user['uid'], $userId);
                    $vipExpiredAccounts[$userId] = true;
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
                    Jobs::where('id', $job['id'])->update(['nextExecute' => 0]);
                    continue;
                }

                $accountData = BilibiliTaskExecutor::decodeSerializedArray((string)$account['data']);
                $jobConfig = BilibiliTaskExecutor::decodeSerializedArray((string)($job['data'] ?? ''));
                if ($accountData === null || $jobConfig === null) {
                    Jobs::where('id', $job['id'])->update(['state' => 0]);
                    $this->writeLog($userId, $taskName, '账号或任务配置损坏，请重新登录后配置');
                    continue;
                }

                $globalConfig = $this->globalConfig((int)$job['uid'], $userId);
                $result = $executor->execute($taskName, $accountData, array_replace($globalConfig, $jobConfig));
                $this->writeLog($userId, $taskName, $result['message']);

                if ($result['account_invalid']) {
                    $this->accountInvalid('bilibili', $user, $userId);
                    continue;
                }

                Info::where('sysid', '100')->inc('times', 1)->update();
                Info::where('sysid', '100')->update(['last' => date('Y-m-d H:i:s')]);
                Jobs::where('id', $job['id'])->update([
                    'lastExecute' => date('Y-m-d H:i:s'),
                    'nextExecute' => $this->nextExecuteAt($account, $userId),
                ]);
                $executed++;
            } catch (Throwable $exception) {
                $this->writeLog($userId, $taskName, '任务调度异常：' . $exception->getMessage());
            }
        }

        $output->writeln("成功执行 {$executed} 条任务：" . date('Y-m-d H:i:s'));
        return 0;
    }

    private function globalConfig(int $uid, string $userId): array
    {
        $payload = Jobs::where('type', 'bilibili')
            ->where('uid', $uid)
            ->where('user_id', $userId)
            ->where('do', 'globalroom')
            ->value('data');
        return BilibiliTaskExecutor::decodeSerializedArray(is_string($payload) ? $payload : '') ?? [];
    }

    private function nextExecuteAt($account, string $userId): int
    {
        return AutomaticSchedule::nextExecution(
            'bilibili',
            $userId,
            (string)($account['timing'] ?? '')
        ) ?? 0;
    }

    private function writeLog(string $userId, string $task, string $message): void
    {
        TaskLogs::operateExecuteLog('bilibili', $userId, $task, $message);
    }

    private function vipExpired(string $type, int $uid, string $userId): void
    {
        $membershipChanged = Users::where('uid', $uid)
            ->whereRaw('(`vip_start` IS NOT NULL OR `vip_end` IS NOT NULL)')
            ->update(['vip_start' => null, 'vip_end' => null]);
        Jobs::where('type', $type)->where('user_id', $userId)->where('uid', $uid)->update(['state' => 0]);
        TaskLogs::operateLog([
            'type' => $type,
            'user_id' => $userId,
            'do' => '系统提示',
            'response' => '会员过期，请开通会员后再试',
        ]);
        if ($membershipChanged > 0) {
            $user = Users::where('uid', $uid)->find();
            if ($user) {
                (new BarkNotificationService())->sendVipExpired($user);
            }
        }
    }

    private function accountInvalid(string $type, $user, string $userId): void
    {
        $stateChanged = Accounts::where('type', $type)
            ->where('user_id', $userId)
            ->where('uid', $user['uid'])
            ->where('state', 1)
            ->update(['state' => 0]);
        Jobs::where('type', $type)->where('user_id', $userId)->where('uid', $user['uid'])->update(['state' => -1]);
        if ($stateChanged > 0 && (int)config('sys.mail_invalid') === 1) {
            $message = get_mail_tempale(3, $user, '哔哩哔哩');
            send_mail((string)$user['mail'], (string)config('web.webname') . ' - 失效提醒', $message);
        }
        if ($stateChanged > 0) {
            (new BarkNotificationService())->sendAccountInvalid($user, '哔哩哔哩');
        }
    }
}
