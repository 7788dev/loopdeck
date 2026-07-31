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

Route::group('console', function () {
    Route::rule('netease/[:act]/[:user_id]', 'console/netease');
    Route::rule('bilibili/[:act]/[:mid]', 'console/bilibili');
    Route::rule('sport/[:act]/[:uid]', 'console/sport');
    // Route::rule('iqiyi/[:act]/[:uid]', 'console/iqiyi'); // 功能已停用
    // Route::rule('tieba/[:act]/[:uid]', 'console/tieba'); // 功能已停用
    // Route::rule('mihoyo/[:act]/[:uid]', 'console/mihoyo'); // 功能已停用
    Route::rule('heybox/[:act]/[:uid]', 'console/heybox');
    Route::rule('user/[:act]', 'console/user');
    Route::rule('shop/[:act]', 'console/shop');
    Route::rule('qrcode/[:act]/[:uid]', 'console/qrcode');
});

$disabledFeature = static function () {
    return response('Not Found', 404);
};
Route::any('console/iqiyi/[:act]/[:uid]', $disabledFeature);
Route::any('console/tieba/[:act]/[:uid]', $disabledFeature);
Route::any('console/mihoyo/[:act]/[:uid]', $disabledFeature);
Route::any('console/tool/[:act]', $disabledFeature);
Route::any('console/wz/[:act]', $disabledFeature);
Route::any('tool/[:act]', $disabledFeature);
Route::any('wz/[:act]', $disabledFeature);
Route::any('ajax/iqiyi/[:act]', $disabledFeature);
Route::any('ajax/tieba/[:act]', $disabledFeature);
Route::any('ajax/mihoyo/[:act]', $disabledFeature);

// Route::get('tool/[:act]', 'console/tool');
// Route::group('tool', function () {
//    Route::post('analyse', 'tool/analyse');
// });

// Route::get('wz/[:act]', 'console/wz');
// Route::group('wz', function () {
//    Route::post('wangzhe', 'wz/wangzhe');
// });



Route::group('ajax', function () {
    // NetEase has its own clean controller; keep the legacy public URL.
    Route::rule('netease/[:act]', 'netease/handle');
    Route::rule('bilibili/[:act]', 'bilibili/handle');
    Route::rule('sport/[:act]', 'ajax/sport');;
    // Route::rule('iqiyi/[:act]', 'ajax/iqiyi'); // 功能已停用
    // Route::rule('tieba/[:act]', 'ajax/tieba'); // 功能已停用
    // Route::rule('mihoyo/[:act]', 'ajax/mihoyo'); // 功能已停用
    Route::rule('heybox/[:act]', 'ajax/heybox');
    Route::rule('qrcode/[:act]', 'ajax/qrcode');

    Route::rule('user/[:act]', 'ajax/user');
    Route::rule('shop/[:act]', 'ajax/shop');
    Route::rule('agent/[:act]', 'ajax/agent');
});
