<?php

namespace app\middleware;

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
        // isAjax() also accepts ?_ajax=1. Only the actual browser header can
        // distinguish an AJAX request from a cross-site form/navigation.
        if (!$request->isAjax(true)
            || strtolower((string)$request->header('sec-fetch-site', '')) === 'cross-site'
            || is_cross_origin_request()) {
            return \think\Response::create(['code' => 0, 'message' => '请求来源无效，请刷新页面后重试'], 'json', 403);
        }
        // 继续执行进入到控制器
        return $next($request);
    }
}
