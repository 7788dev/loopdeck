<?php

declare(strict_types=1);

namespace app\service;

use app\index\model\Jobs;

final class EpicSubscription
{
    /** Called in the same transaction as saving personal notification settings. */
    public static function sync(int $uid, int $webId, array $settings): void
    {
        $job = Jobs::where('uid', $uid)->where('zid', $webId)->where('type', 'epic')->where('do', 'weeklyGameNotify')->find();
        $enabled = $settings['enabled'] && $settings['events']['epic_free_games'];
        if (!$job && !$enabled) {
            return;
        }
        $timing = (string)($settings['epic_time'] ?? '09:00');
        $next = $enabled ? EpicSchedule::next($timing) : 0;
        $old = $job ? safe_unserialize_array((string)$job['data']) : [];
        if ($job && (int)$job['state'] === 1 && $enabled && ($old['timing'] ?? '') === $timing && (int)$job['nextExecute'] > 0) {
            $next = (int)$job['nextExecute'];
        }
        $changes = ['state' => (int)$enabled, 'data' => serialize(['timing' => $timing]), 'nextExecute' => $next];
        if ($job) {
            Jobs::where('id', (int)$job['id'])->where('uid', $uid)->where('zid', $webId)->update($changes);
        } else {
            Jobs::insert($changes + ['uid' => $uid, 'zid' => $webId, 'type' => 'epic',
                'do' => 'weeklyGameNotify', 'user_id' => 'epic:' . $uid]);
        }
    }
}
