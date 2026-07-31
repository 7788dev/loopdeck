<?php

namespace app\middleware;

use think\facade\Request;

class CheckPjaxRequest
{
    /**
     * 处理请求
     *
     * @param \think\Request $request
     * @param \Closure       $next
     * @return Response
     */
    public function handle($request, \Closure $next)
    {
        //判断是否Pjax请求
        if (Request::instance()->header("X-PJAX")) {
            config('default_ajax_return', 'html');
            define('PJAX', true);
        } else {
            define('PJAX', false);
        }
        // 继续执行进入到控制器
        return $next($request);

    }
}