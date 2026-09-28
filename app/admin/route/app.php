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

Route::get('system/update', 'system/update');
//自动更新状态与立即检查 Ajax
Route::rule('ajax/updater/[:act]', 'ajax/updater');

//系统设置
Route::rule('system/set/[:act]', 'system/set');
//系统设置 Ajax
Route::rule('ajax/set/[:act]', 'ajax/set');
//任务设置
Route::rule('system/task/[:act]', 'system/task');
//任务设置Ajax
Route::rule('ajax/task/[:act]', 'ajax/task');
//数据设置
Route::rule('system/data/[:act]/[:do]', 'system/data');
//数据设置 Ajax
Route::rule('ajax/data/[:act]/[:do]', 'ajax/data');
