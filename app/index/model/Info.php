<?php

namespace app\index\model;

use think\Model;

class Info extends Model
{
    /**
     * executeCount 所有任务运行次数
     * @param int $sysid
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author BadCen
     */
    public static function executeCount($sysid = 100)
    {
        $self = new static();
        $result = $self
            ->where('sysid', '=', $sysid)
            ->find();
        return $result['times'];
    }

    /**
     * 记录一次任务执行：次数自增与最后执行时间合并为一条 UPDATE。
     * 旧实现每个任务发两条 SQL（inc + update），批量调度时翻倍。
     */
    public static function recordRun($sysid = 100): void
    {
        (new static())
            ->where('sysid', '=', $sysid)
            ->inc('times', 1)
            ->update(['last' => date('Y-m-d H:i:s')]);
    }
}