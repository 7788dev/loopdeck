<?php

namespace app\index\model;

use think\Model;

class Order extends Model
{
    /**
     * add
     * @param $data
     * @return bool|int|string
     */
    public static function add($data)
    {
        $self = new static();
        if ($result = $self->insert($data)) {
            return $result;
        } else {
            return false;
        }
    }
}