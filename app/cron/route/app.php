<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
use think\facade\Route;

// 监控运行
Route::rule('netease/:do', 'netease/execute');
Route::rule('bilibili/:do', 'bilibili/execute');
Route::rule('heybox/:do', 'heybox/execute');

$retiredFeatureNotFound = static function () {
    return response('Not Found', 404);
};
foreach (['iqiyi', 'tieba', 'mihoyo', 'sport'] as $feature) {
    Route::any("{$feature}/[:do]", $retiredFeatureNotFound);
}
