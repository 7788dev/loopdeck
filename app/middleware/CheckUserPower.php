<?php
declare (strict_types = 1);

namespace app\middleware;

use app\index\model\Users;
use think\facade\Session;
use think\facade\View;

class CheckUserPower
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
        //处理请求
        if (empty(Session::get('user'))) {
            // 跳转到错误
            View::assign(['msg' => '权限不足', 'url' => '/index/console']);
            exit(View::fetch('common/alert'));
        }
        $ret = Users::getByUid(Session::get('user.uid'));
        if ($ret['power'] != 6 || $ret['web_id'] != WEB_ID) {
            // 跳转到错误
            View::assign(['msg' => '权限不足', 'url' => '/index/console']);
            exit(View::fetch('common/alert'));
        }
        // 继续执行进入到控制器
        return $next($request);
    }
}
