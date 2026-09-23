<?php
declare (strict_types = 1);

namespace app\index\model;

use think\Model;

class Tasks extends Model
{
    private const BUILTIN_TASKS = [
        'netease' => [
            [
                'type' => 'netease',
                'name' => 'VIP成长任务',
                'describe' => '黑胶乐签、VIP听歌与成长值领取',
                'icon' => 'si si-diamond',
                'execute_name' => 'vip_growth_task',
                'execute_url' => null,
                'execute_rate' => '86400',
                'more' => 0,
                'state' => 1,
                'vip' => 0,
                'time' => '2026-07-31 00:00:00',
                'order' => 7,
            ],
        ],
        'bilibili' => [
            [
                'type' => 'bilibili',
                'name' => '每日分享',
                'describe' => '每日分享功能已下架',
                'icon' => 'si si-paper-plane',
                'execute_name' => 'shareaid',
                'execute_url' => null,
                'execute_rate' => '86400',
                'more' => 0,
                'state' => 1,
                'vip' => 1,
                'time' => '2022-01-01 00:00:00',
                'order' => 3,
            ],
            [
                'type' => 'bilibili',
                'name' => '每日经验任务',
                'describe' => '登录、观看、投币经验状态与经验日志核验（分享功能已下架）',
                'icon' => 'si si-graduation',
                'execute_name' => 'dailyexperience',
                'execute_url' => null,
                'execute_rate' => '86400',
                'more' => 0,
                'state' => 1,
                'vip' => 0,
                'time' => '2026-08-02 00:00:00',
                'order' => 2,
            ],
            [
                'type' => 'bilibili',
                'name' => '大会员每日经验',
                'describe' => '按官方协议领取大会员每日经验，非大会员安全跳过',
                'icon' => 'si si-diamond',
                'execute_name' => 'vipexperience',
                'execute_url' => null,
                'execute_rate' => '86400',
                'more' => 0,
                'state' => 1,
                'vip' => 0,
                'time' => '2026-08-02 00:00:00',
                'order' => 3,
            ],
        ],
    ];

    private static $builtinTasksSynced = [];

    /**
     * getTaskList
     * @param null $type
     * @return Tasks[]|array|false|\think\Collection
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author BadCen
     */
    public static function getTaskList($type = null)
    {
        self::ensureBuiltinTasks($type);
        $self = new static();
        if ($result = $self->where('type',  '=', $type)->where('state', '=', 1)->order('order asc')->select()) {
            return $result;
        }
        return false;
    }

    private static function ensureBuiltinTasks($type): void
    {
        $type = (string)$type;
        if (!isset(self::BUILTIN_TASKS[$type]) || isset(self::$builtinTasksSynced[$type])) {
            return;
        }

        foreach (self::BUILTIN_TASKS[$type] as $task) {
            $exists = (new static())
                ->where('type', '=', $task['type'])
                ->where('execute_name', '=', $task['execute_name'])
                ->find();
            if ($exists) {
                // Existing installations already have these rows, so an
                // insert-only sync would leave the retired-share wording
                // stale forever.  Keep administrator customisations intact
                // except for the known legacy descriptions that this release
                // deliberately replaces.
                $description = (string)($exists['describe'] ?? '');
                $legacyDailyExperience = [
                    '登录、观看、分享、投币经验状态与经验日志核验',
                    '登录、观看、分享、投币经验状态与经验日志核验（分享功能已下架）',
                ];
                $shouldSyncDescription = $task['execute_name'] === 'shareaid'
                    || ($task['execute_name'] === 'dailyexperience'
                        && in_array($description, $legacyDailyExperience, true));
                if ($shouldSyncDescription && $description !== $task['describe']) {
                    (new static())
                        ->where('type', '=', $task['type'])
                        ->where('execute_name', '=', $task['execute_name'])
                        ->update(['describe' => $task['describe']]);
                }
                continue;
            }
            try {
                (new static())->insert($task);
            } catch (\Throwable $exception) {
                $exists = (new static())
                    ->where('type', '=', $task['type'])
                    ->where('execute_name', '=', $task['execute_name'])
                    ->find();
                if (!$exists) {
                    throw $exception;
                }
            }
        }

        self::$builtinTasksSynced[$type] = true;
    }

    /**
     * taskCount
     * @return false|int
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author BadCen
     */
    public static function taskCount()
    {
        $self = new static();
        return $self->count('id');
    }

    public static function checkTaskPower($name, $type = '')
    {
        $self = new static();
        $query = $self->where('execute_name', '=', $name)->where('type', '=', $type)->find();
        if ($query['vip'] == 1) {
            return true;
        }
        return false;
    }

}
