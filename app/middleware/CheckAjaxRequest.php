<?php

namespace app\middleware;

use think\facade\Request;

class CheckAjaxRequest
{
    /**
     * 处理请求
     *
     * @param \think\Request $request
     * @param \Closure       $next
     */
    public function handle($request, \Closure $next)
    {
        //判断是否Ajax请求
        if (!Request::isAjax()) {
            exit('非法请求');
        }
        // 继续执行进入到控制器
        return $next($request);
    }
}
