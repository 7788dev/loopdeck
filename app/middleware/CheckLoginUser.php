<?php

namespace app\middleware;

use app\index\model\Users;
use think\facade\Session;
use think\facade\View;

class CheckLoginUser
{
    /**
     * @var string[]
     */
    private $authController = [];

    /**
     * 处理请求
     * @param \think\Request $request
     * @param \Closure       $next
     */
    public function handle($request, \Closure $next)
    {
        // 判断是否登录
        if (empty(Session::get('user'))) {
            if (in_array($request->controller() . '/' . $request->action(), $this->authController)) {
                // 继续执行进入到控制器
                return $next($request);
            }
            // 未登录 跳转到登录页面
            return redirect('/index/login/login');
        } else {
            // 已登录 读取用户信息并存入Session
            $ret = Users::where('uid', (int)Session::get('user.uid'))->withoutField('password')->find();
            $current = $ret ? $ret->toArray() : [];
            if (!self::validSessionUser((array)Session::get('user'), $current, (int)WEB_ID)) {
                Session::delete('user');
                return redirect('/index/login/login');
            }
            //检测用户VIP是否过期
            if (!empty($current['vip_end']) && strtotime((string)$current['vip_end']) < time()) {
                $changed = Users::where('uid', (int)$current['uid'])
                    ->where('vip_end', $current['vip_end'])
                    ->update(['vip_start' => null, 'vip_end' => null]);
                if ($changed > 0) {
                    (new \app\service\NotificationService())->sendVipExpired($current);
                }
                $current['vip_start'] = null;
                $current['vip_end'] = null;
            }
            Session::set('user', $current);
        }
        if ($request->action() == 'agent' && empty(Session::get('user.agent'))) {
            // 无代理权限
            View::assign(['msg' => '权限不足', 'url' => '/index/console']);
            exit(View::fetch('common/alert'));
        }
        // 继续执行进入到控制器
        return $next($request);
    }

    public static function validSessionUser(array $session, array $current, int $webId): bool
    {
        $sid = (string)($session['sid'] ?? '');
        $stored = (string)($current['sid'] ?? '');
        return (int)($current['uid'] ?? 0) > 0
            && (int)($session['uid'] ?? 0) === (int)$current['uid']
            && (int)($current['state'] ?? 0) === 1
            && (int)($current['web_id'] ?? 0) === $webId
            && $webId > 0 && $sid !== '' && $stored !== '' && hash_equals($stored, $sid);
    }
}
