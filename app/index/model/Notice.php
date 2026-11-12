<?php
declare (strict_types=1);

namespace app\index\model;

use think\Model;

class Notice extends Model
{

    public static function getNoticeList()
    {
        $self = new static();
        if ($result = $self->where('zid', '=', WEB_ID)->where('type', '=', 1)->order('sort desc')->order('addtime desc')->select()) {
            return $result;
        }
        return false;
    }
}