<?php
declare (strict_types = 1);

namespace app\index\model;

use think\Model;

class Tasks extends Model
{
    private const NETEASE_BUILTIN_TASKS = [
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
        if ($type !== 'netease' || isset(self::$builtinTasksSynced[$type])) {
            return;
        }

        foreach (self::NETEASE_BUILTIN_TASKS as $task) {
            $exists = (new static())
                ->where('type', '=', $task['type'])
                ->where('execute_name', '=', $task['execute_name'])
                ->find();
            if ($exists) {
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
        return $self->select()->count('id');
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
