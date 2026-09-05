<?php

declare(strict_types=1);

namespace app\service;

use app\cron\controller\Common;
use app\index\model\Jobs;
use app\index\model\TaskLogs;
use app\index\model\Users;
use Throwable;

final class EpicJobRunner
{
    public function run(mixed $job): string
    {
        $id = (int)($job['id'] ?? 0);
        if ($id <= 0 || ($job['type'] ?? '') !== 'epic' || ($job['do'] ?? '') !== 'weeklyGameNotify'
            || !Jobs::claimDueJob($id, (int)($job['nextExecute'] ?? 0))) {
            return 'skipped';
        }
        $common = new Common();
        $notifications = new NotificationService();
        $user = null;
        try {
            $user = Users::where('uid', (int)$job['uid'])->where('web_id', (int)$job['zid'])->where('state', 1)->find();
            $config = safe_unserialize_array((string)($job['data'] ?? ''));
            $timing = (string)($config['timing'] ?? '');
            if (!$user || !AutomaticSchedule::isConfigured($timing)) {
                Jobs::where('id', $id)->update(['state' => 0, 'nextExecute' => 0]);
                return 'disabled';
            }
            if ((int)strtotime((string)($user['vip_end'] ?? '')) < time()) {
                $common->vipExpired('epic', (int)$user['uid'], (string)$job['user_id']);
                return 'disabled';
            }
            $result = (new EpicTaskExecutor($notifications))->execute($user);
            $retry = (int)($result['retry_after_seconds'] ?? 0);
            $disabled = !empty($result['disable_job']);
            $next = $disabled ? 0 : ($retry > 0 ? time() + $retry : EpicSchedule::next($timing));
            Jobs::where('id', $id)->update(['state' => $disabled ? 0 : 1,
                'lastExecute' => date('Y-m-d H:i:s'), 'nextExecute' => $next]);
        } catch (Throwable $exception) {
            $result = ['success' => false, 'message' => 'Epic 周免提醒执行异常，稍后重试', 'retry_after_seconds' => 300];
            Jobs::where('id', $id)->update(['nextExecute' => time() + 300]);
        }
        $notifications->recordTask($user, 'epic', (string)$job['user_id'], 'weeklyGameNotify', 'Epic 周免提醒', $result);
        try {
            TaskLogs::operateExecuteLog('epic', (string)$job['user_id'], 'weeklyGameNotify',
                '[' . $common->statusTag($result) . '] ' . $result['message']);
        } catch (Throwable $exception) {
        }
        return !empty($result['success']) ? 'succeeded' : 'failed';
    }
}
