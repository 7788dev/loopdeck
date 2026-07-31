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
}