<?php

namespace app\admin\model;

use think\Model;

class Accounts extends Model
{
    public static function getAccountList()
    {
        $start = (int)input('post.start');
        $length = (int)input('post.length');
        $search = input('post.search');

        $self = new static();
        $query = $self->alias('a');
        $query->where('zid', '=', WEB_ID);
        if (!empty($search['uid'])) $query->where('uid', '=',  $search['uid']);
        if (!empty($search['user_id'])) $query->where('user_id', '=',  $search['user_id']);
        if (is_numeric($search['status'])) $query->where('state', '=',  $search['status']);

        if ($result = $query->order('a.addtime desc')->withoutField('data')->limit($start, $length)->select()) {
            return [
                'total' => $query->count('id'),
                'page' => input('post.page'),
                'data' => $result,
            ];
        }
        return false;
    }

    public static function getAllAccountList()
    {
        $start = (int)input('post.start');
        $length = (int)input('post.length');
        $search = input('post.search');

        $self = new static();
        $query = $self->alias('a');

        if (!empty($search['uid'])) $query->where('uid', '=',  $search['uid']);
        if (!empty($search['user_id'])) $query->where('user_id', '=',  $search['user_id']);
        if (is_numeric($search['status'])) $query->where('state', '=',  $search['status']);

        if ($result = $query->order('a.addtime desc')->withoutField('data')->limit($start, $length)->select()) {
            return [
                'total' => $query->count('id'),
                'page' => input('post.page'),
                'data' => $result,
            ];
        }
        return false;
    }

    public static function delByid($id)
    {
        $self = new static();
        if ($self->where('user_id', '=', $id)->where('zid', '=', WEB_ID)->delete()) {
            return true;
        }
        return false;
    }

    public static function delByUserid($uid)
    {
        $self = new static();
        if ($self->where('uid', '=', $uid)->where('zid', '=', WEB_ID)->delete()) {
            return true;
        }
        return false;
    }
    
    public static function delBySiteid($id)
    {
        $self = new static();
        if ($self->where('zid', '=', $id)->delete()) {
            return true;
        }
        return false;
    }
    
}