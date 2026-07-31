<?php

namespace app\admin\model;

use think\Model;

class Jobs extends Model
{
    public function turnOffTask($user_id, $do)
    {
        return ($this->where('user_id', '=', $user_id)
                ->where('do', '=', $do)
                ->update(['state' => 0]) !== false);
    }

    public static function delByid($id)
    {
        $self = new static();
        $self->where('user_id', '=', $id)->delete();
        return true;
    }

    public static function delByUserid($uid)
    {
        $self = new static();
        $self->where('uid', '=', $uid)->delete();
        return true;
    }

    public static function delBySiteid($id)
    {
        $self = new static();
        if ($self->where('zid', '=', $id)->delete() !== false) {
            return true;
        }
        return false;
    }

    /**
     * 关联Tasks表
     */
    public function tasks()
    {
        return $this->hasOne(Tasks::class, 'execute_name', 'do');
    }

    /**
     * 获取任务数据
     */
    public function getJobs($type = '', $execute_name = '')
    {
        return $this->hasWhere('tasks')
            ->where('Tasks.type', '=', $type)
            ->where('do', '=', $execute_name)
            ->select();
    }
}