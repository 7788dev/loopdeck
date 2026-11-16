<?php

namespace app\index\model;

use think\Model;

class Weblist extends Model
{
    public static function start_Time()
    {
        // LoadConfigs already caches the whole site row as config('web');
        // querying start_time again would repeat that read on every home view.
        $sitestart = strtotime((string)config('web.start_time'));
        $sitenow = time();
        $sitetime = $sitenow - $sitestart;
        $sitedays = (int)($sitetime / 86400);
        return $sitedays;
    }

}
