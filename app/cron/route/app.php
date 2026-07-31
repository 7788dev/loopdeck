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
Route::rule('iqiyi/:do', 'iqiyi/execute');
// Route::rule('tieba/:do', 'tieba/execute'); // 功能已停用
Route::rule('sport/:do', 'sport/execute');
// Route::rule('mihoyo/:do', 'mihoyo/execute'); // 功能已停用
Route::rule('heybox/:do', 'heybox/execute');

$disabledFeature = static function () {
    return response('Not Found', 404);
};
Route::any('tieba/[:do]', $disabledFeature);
Route::any('mihoyo/[:do]', $disabledFeature);
