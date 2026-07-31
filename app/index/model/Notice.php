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

    public static function findById($id)
    {
        $self = new static();
        if ($result = $self->where('id', $id)->find()) {
            return $result;
        }
        return false;
    }

    public static function updateByid($id, $data)
    {
        $self = new static();
        return ($self->where('id', '=', $id)->update($data) !== false);
    }

    public static function delByid($id)
    {
        $self = new static();
        if ($self->where('id', '=', $id)->delete()) {
            return true;
        }
        return false;
    }

    public static function add($data)
    {
        $self = new static();
        $data['zid'] = WEB_ID;
        $data['addtime'] = time();
        if ($self->field('type,title,content,addtime,zid')->insert($data)) {
            return resultJson(1,'添加成功');
        }
        return resultJson(0,'添加失败');
    }


}