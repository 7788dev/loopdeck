<?php

namespace app\admin\model;

use think\Model;

class Order extends Model
{
    /**
     * getOrderList 获取订单列表
     * @return array|false
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author BadCen
     */
    public static function getOrderList()
    {
        $self = new static();
        if ($result = $self->where('zid', '=', WEB_ID)->order('time', 'desc')->select()) {
            return $result;
        }
        return false;
    }

}