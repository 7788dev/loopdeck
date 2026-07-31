<?php
declare (strict_types = 1);

namespace app\index\model;

use think\Model;

class Tasks extends Model
{

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
        $self = new static();
        if ($result = $self->where('type',  '=', $type)->where('state', '=', 1)->order('order asc')->select()) {
            return $result;
        }
        return false;
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