<?php

namespace app\admin\model;

use think\Model;

class Notice extends Model
{
    public static function getNoticeList()
    {
        $self = new static();
        if ($result = $self->where('zid', '=', WEB_ID)->order('addtime desc ')->select()) {
            return $result;
        }
        return false;
    }

    public static function getAdminNoticeList()
    {
        $self = new static();
        if ($result = $self->where('type', '=', 2)->order('addtime desc ')->limit(3)->select()) {
            return $result;
        }
        return false;
    }

    public static function findById($id)
    {
        $self = new static();
        if ($result = $self->where('id', $id)->where('zid', '=', WEB_ID)->find()) {
            return $result;
        }
        return false;
    }

    public static function updateByid($id, $data)
    {
        if (!is_array($data)) {
            return false;
        }
        // Only update fields owned by the notice form.
        $data = array_intersect_key($data, array_flip(['type', 'title', 'content', 'alert']));
        if ($data === []) {
            return false;
        }
        foreach ($data as $value) {
            if (!is_scalar($value) && $value !== null) {
                return false;
            }
        }
        $self = new static();
        return ($self->where('id', '=', $id)->where('zid', '=', WEB_ID)->update($data) !== false);
    }

    public static function delByid($id)
    {
        $self = new static();
        if ($self->where('id', '=', $id)->where('zid', '=', WEB_ID)->delete()) {
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
