<?php

namespace app\cron\controller;

use app\index\model\Accounts;
use app\index\model\TaskLogs;
use app\index\model\Jobs;
use app\index\model\Users;
use app\service\NotificationService;

class Common
{
    /**
     * Short status tag rendered as a badge in the log list. Accepts every
     * result shape the cron controllers produce: raw adapter results
     * (`code` 200 / 1, optional `data.retry_after_seconds`), normalized
     * scheduler results (`success` bool, optional `retry_after_seconds`)
     * and anything non-array, which counts as failed.
     *
     * @param mixed $result
     */
    public function statusTag(mixed $result): string
    {
        if (!is_array($result)) {
            return '失败';
        }
        $success = array_key_exists('success', $result)
            ? !empty($result['success'])
            : in_array((int)($result['code'] ?? 0), [1, 200], true);
        if ($success) {
            return '成功';
        }
        $retryAfter = (int)($result['data']['retry_after_seconds']
            ?? $result['retry_after_seconds']
            ?? 0);

        return $retryAfter > 0 ? '重试中' : '失败';
    }

    /** Daily listening verification is internal until an outcome is final. */
    public static function shouldReportTaskStatus(string $type, string $task, string $status): bool
    {
        return $type !== 'netease' || $task !== 'daka_new' || $status !== '重试中';
    }

    public function vipExpired($type, $uid, $user_id)
    {
        $membershipChanged = Users::where('uid', '=', $uid)
            ->whereRaw('(`vip_start` IS NOT NULL OR `vip_end` IS NOT NULL)')
            ->update(['vip_start' => NULL, 'vip_end' => NULL]);
        Jobs::pauseVipJobs((string)$type, (int)$uid, (string)$user_id);
        $data = [
            'type' => $type,
            'user_id' => $user_id,
            'do' => '系统提示',
            'response' => '[失败] 会员过期，请开通会员后再试',
        ];
        TaskLogs::operateLog($data);
        $user = $membershipChanged > 0 ? Users::getByUid($uid) : null;
        if ($membershipChanged > 0 && $user) {
            (new NotificationService())->sendVipExpired($user);
        }
    }

    public function accountInvalid($type, $user, $user_id)
    {
        $name = match ($type) {
            'netease' => '网易云音乐',
            'bilibili' => '哔哩哔哩',
            'qq' => 'QQ',
        };
        $stateChanged = Accounts::where('user_id', '=', $user_id)
            ->where('type', '=', $type)
            ->where('uid', '=', $user['uid'])
            ->where('state', '=', 1)
            ->update(['state' => 0]);
        Jobs::where('user_id', '=', $user_id)
            ->where('uid', '=', $user['uid'])
            ->where('state', '=', 1)
            ->where('type', '=', $type)
            ->update(['state' => -1]); // state -1 代表账号失效
        if ($stateChanged > 0) {
            (new NotificationService())->sendAccountInvalid($user, $name, (string)$user_id);
        }
    }

}
