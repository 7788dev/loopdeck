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
        try {
            $common = new Common();
            $notifications = new NotificationService();
            $user = null;
            try {
                $user = Users::where('uid', (int)$job['uid'])->where('web_id', 1)->where('state', 1)->find();
                $config = safe_unserialize_array((string)($job['data'] ?? ''));
                $timing = (string)($config['timing'] ?? '');
                if (!$user || !AutomaticSchedule::isConfigured($timing)) {
                    // A lease-guarded write: if it lands nowhere the user
                    // re-planned the job mid-run and their schedule stands.
                    if (!Jobs::updateClaimedJob($id, ['state' => 0, 'nextExecute' => 0])) {
                        return 'skipped';
                    }
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
                // A run that returned a result breaks the consecutive-failure
                // streak tracked in the payload.
                Jobs::updateClaimedJob($id, ['state' => $disabled ? 0 : 1,
                    'lastExecute' => date('Y-m-d H:i:s'), 'nextExecute' => $next,
                    'data' => serialize(SchedulerRetry::dataAfterSuccess($config))]);
            } catch (Throwable $exception) {
                $jobData = safe_unserialize_array((string)($job['data'] ?? ''));
                $nextData = SchedulerRetry::dataAfterFailure($jobData);
                if ($nextData === null) {
                    // 连续异常达到上限：停用任务并留下 [失败] 终态；写不进去
                    // 说明用户已在租约期内接管，按普通失败上报即可。结果日志
                    // 由方法末尾的公共路径统一写入，避免重复。
                    $attempts = SchedulerRetry::attemptsIn($jobData) + 1;
                    $finalized = Jobs::updateClaimedJob($id, [
                        'state' => 0,
                        'nextExecute' => 0,
                        'data' => serialize(SchedulerRetry::settledData($jobData)),
                    ]);
                    $result = $finalized
                        ? ['success' => false, 'message' => SchedulerRetry::finalMessage($attempts)]
                        : ['success' => false, 'message' => 'Epic 周免提醒执行异常'];
                } elseif (Jobs::updateClaimedJob($id, ['nextExecute' => time() + 300, 'data' => serialize($nextData)])) {
                    $result = ['success' => false, 'message' => 'Epic 周免提醒执行异常，稍后重试', 'retry_after_seconds' => 300];
                } else {
                    // 重试写入落空：租约已被接管，任务不再由本调度推进，
                    // 上报文案不能承诺“稍后重试”。
                    $result = ['success' => false, 'message' => 'Epic 周免提醒执行异常'];
                }
            }
            $notifications->recordTask($user, 'epic', (string)$job['user_id'], 'weeklyGameNotify', 'Epic 周免提醒', $result);
            try {
                TaskLogs::operateExecuteLog('epic', (string)$job['user_id'], 'weeklyGameNotify',
                    '[' . $common->statusTag($result) . '] ' . $result['message']);
            } catch (Throwable $exception) {
            }
            return !empty($result['success']) ? 'succeeded' : 'failed';
        } finally {
            Jobs::releaseDueJob($id);
        }
    }
}
