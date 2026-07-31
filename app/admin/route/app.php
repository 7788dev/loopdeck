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

// Security hardening: never allow the legacy remote update service to
// download and overwrite application files.
Route::rule('ajax/update', function () {
    return response()
        ->data(json_encode([
            'code' => 0,
            'message' => '远程更新已因安全风险禁用，请使用经过审计的离线更新包。',
        ], JSON_UNESCAPED_UNICODE))
        ->contentType('application/json; charset=utf-8');
});

//系统设置
Route::rule('system/set/[:act]', 'system/set');
//系统设置 Ajax
Route::rule('ajax/set/[:act]', 'ajax/set');
//支付设置
Route::rule('system/pay/[:act]', 'system/pay');
//支付设置 Ajax
Route::rule('ajax/pay/[:act]', 'ajax/pay');
//任务设置
Route::rule('system/task/[:act]', 'system/task');
//任务设置Ajax
Route::rule('ajax/task/[:act]', 'ajax/task');
//数据设置
Route::rule('system/data/[:act]/[:do]', 'system/data');
//数据设置 Ajax
Route::rule('ajax/data/[:act]/[:do]', 'ajax/data');
