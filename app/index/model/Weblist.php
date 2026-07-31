<?php

namespace app\index\model;

use think\Model;

class Weblist extends Model
{
    public static function start_Time()
    {
        $self = new static();
        $start_time = $self
            ->where('web_id', '=', WEB_ID)
            ->field('start_time')
            ->find();
        $sitestart = strtotime($start_time['start_time']);
        $sitenow = time();
        $sitetime = $sitenow - $sitestart;
        $sitedays = (int)($sitetime / 86400);
        return $sitedays;
    }

    public static function end_Time()
    {
        $start = strtotime(config('web.start_time'));
        $end = strtotime(config('web.end_time'));
        $res = ceil(($end - $start) / 86400);
        return $res;
    }

}