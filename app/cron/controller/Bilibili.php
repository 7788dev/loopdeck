<?php

declare(strict_types=1);

namespace app\cron\controller;

use app\index\model\Accounts;
use app\index\model\Info;
use app\index\model\Jobs;
use app\index\model\TaskLogs;
use app\index\model\Tasks;
use app\index\model\Users;
use app\service\BilibiliTaskExecutor;
use think\facade\Request;
use Throwable;

class Bilibili extends Common
{
    public function index()
    {
        $cronKey = (string)Request::get('cronkey', '');
        if ($cronKey === '' || !hash_equals((string)config('sys.cronkey'), $cronKey)) {
            return resultJson(-1000, 'CronKey Access Denied!');
        }

        $urls = [];
        $limit = max(1, (int)config('sys.interval'));
        $jobs = Jobs::where('type', 'bilibili')
            ->where('state', 1)
            ->where('nextExecute', '<=', time())
            ->whereIn('do', BilibiliTaskExecutor::TASKS)
            ->order('nextExecute', 'asc')
            ->limit($limit)
            ->select();

        foreach ($jobs as $job) {
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
                Jobs::where('id', $job['id'])->update(['state' => 0]);
                continue;
            }
            if ((int)$task['vip'] === 1 && strtotime((string)($user['vip_end'] ?? '')) < time()) {
                $this->vipExpired('bilibili', $user['uid'], $userId);
                continue;
            }
            $urls[] = $this->getExecuteUrl($userId, (string)$job['do']);
        }

        if ($urls === []) {
            return resultJson(-1002, '没有要执行的任务');
        }
        $this->curl_mulit($urls);
        return resultJson(1000, '已调度 ' . count($urls) . ' 条任务');
    }

    public function execute($do)
    {
        $runKey = (string)Request::get('runkey', '');
        if ($runKey === '' || !hash_equals((string)RUN_KEY, $runKey)) {
            return resultJson(-1001, 'RunKey Access Denied!');
        }

        $taskName = trim((string)$do);
        $userId = trim((string)Request::get('user_id', ''));
        $offlineReason = BilibiliTaskExecutor::offlineReason($taskName);
        if ($offlineReason !== null) {
            return resultJson(0, $offlineReason);
        }
        if (!BilibiliTaskExecutor::supports($taskName) || $userId === '' || !ctype_digit($userId)) {
            return resultJson(0, '任务或账号参数错误');
        }

        try {
            $job = Jobs::where('type', 'bilibili')
                ->where('user_id', $userId)
                ->where('do', $taskName)
                ->where('state', 1)
                ->find();
            if (!$job) {
                return resultJson(0, '任务不存在或未启用');
            }

            $user = Users::where('uid', $job['uid'])->find();
            $task = Tasks::where('type', 'bilibili')
                ->where('execute_name', $taskName)
                ->where('state', 1)
                ->find();
            $account = Accounts::where('type', 'bilibili')
                ->where('user_id', $userId)
                ->where('uid', $job['uid'])
                ->where('state', 1)
                ->find();
            if (!$user || !$task || !$account) {
                return resultJson(0, '账号、用户或任务数据不存在');
            }
            if ((int)$task['vip'] === 1 && strtotime((string)($user['vip_end'] ?? '')) < time()) {
                $this->vipExpired('bilibili', $user['uid'], $userId);
                return resultJson(0, '会员已过期');
            }

            $accountData = BilibiliTaskExecutor::decodeSerializedArray((string)$account['data']);
            $jobConfig = BilibiliTaskExecutor::decodeSerializedArray((string)($job['data'] ?? ''));
            $globalConfig = $this->globalConfig((int)$job['uid'], $userId);
            if ($accountData === null || $jobConfig === null || $globalConfig === null) {
                Jobs::where('id', $job['id'])->update(['state' => 0]);
                TaskLogs::operateExecuteLog('bilibili', $userId, $taskName, '账号或任务配置损坏');
                return resultJson(0, '账号或任务配置损坏，请重新配置');
            }

            $result = (new BilibiliTaskExecutor())->execute(
                $taskName,
                $accountData,
                array_replace($globalConfig, $jobConfig)
            );
            TaskLogs::operateExecuteLog('bilibili', $userId, $taskName, $result['message']);

            if ($result['account_invalid']) {
                $this->accountInvalid('bilibili', $user, $userId);
                return resultJson(0, $result['message']);
            }

            Info::where('sysid', '100')->inc('times', 1)->update();
            Info::where('sysid', '100')->update(['last' => date('Y-m-d H:i:s')]);
            Jobs::where('id', $job['id'])->update([
                'lastExecute' => date('Y-m-d H:i:s'),
                'nextExecute' => $this->nextExecuteAt($account, $task),
            ]);
            return resultJson($result['code'] === 1 ? 1 : 0, $result['message']);
        } catch (Throwable $exception) {
            TaskLogs::operateExecuteLog('bilibili', $userId, $taskName, '任务调度异常：' . $exception->getMessage());
            return resultJson(0, '任务执行异常，请查看运行日志');
        }
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

    private function nextExecuteAt($account, $task): int
    {
        if (!empty($account['timing'])) {
            $next = strtotime((string)$account['timing'] . ' +1 day');
            if ($next !== false) {
                return $next;
            }
        }
        return time() + max(60, (int)$task['execute_rate']);
    }

    private function getExecuteUrl(string $userId, string $task): string
    {
        return get_Domain() . 'cron/bilibili/' . rawurlencode($task) . '?' . http_build_query([
            'user_id' => $userId,
            'runkey' => RUN_KEY,
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
