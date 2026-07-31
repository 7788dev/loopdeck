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

Route::get('healthcheck', 'index/healthcheck');

Route::group('console', function () {
    Route::rule('netease/[:act]/[:user_id]', 'console/netease');
    Route::rule('bilibili/[:act]/[:mid]', 'console/bilibili');
    Route::rule('heybox/[:act]/[:uid]', 'console/heybox');
    Route::rule('user/[:act]', 'console/user');
    Route::rule('shop/[:act]', 'console/shop');
    Route::rule('qrcode/[:act]/[:uid]', 'console/qrcode');
});

$retiredFeatureNotFound = static function () {
    return response('Not Found', 404);
};
foreach (['iqiyi', 'tieba', 'mihoyo', 'sport'] as $feature) {
    Route::any("console/{$feature}/[:act]/[:uid]", $retiredFeatureNotFound);
    Route::any("ajax/{$feature}/[:act]", $retiredFeatureNotFound);
}
foreach (['tool', 'wz'] as $feature) {
    Route::any("console/{$feature}/[:act]", $retiredFeatureNotFound);
    Route::any("{$feature}/[:act]", $retiredFeatureNotFound);
}

Route::group('ajax', function () {
    // NetEase has its own clean controller; keep the legacy public URL.
    Route::rule('netease/[:act]', 'netease/handle');
    Route::rule('bilibili/[:act]', 'bilibili/handle');
    Route::rule('heybox/[:act]', 'ajax/heybox');
    Route::rule('qrcode/[:act]', 'ajax/qrcode');

    Route::rule('user/[:act]', 'ajax/user');
    Route::rule('shop/[:act]', 'ajax/shop');
    Route::rule('agent/[:act]', 'ajax/agent');
});
