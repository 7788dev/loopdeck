<?php
declare (strict_types=1);

namespace app\index\controller;

use app\index\model\Users;
use think\facade\Session;
use think\facade\View;
use think\response\Redirect;

class Login extends Common
{
    /**
     * login
     * 用户登录
     * @return string|Redirect
     * @author BadCen
     */
    public function login()
    {
        if (Session::has('user')) {
            return redirect((string)url('/index/console/index'));
        } else {
            return View::fetch('/login/login');
        }
    }

    /**
     * reg
     * 用户注册
     * @return string|Redirect
     * @author BadCen
     */
    public function reg()
    {
        if (Session::has('user')) {
            return redirect((string)url('/index/console/index'));
        } else {
            return View::fetch('/login/reg');
        }
    }

    /**
     * find
     * 找回密码
     * @return string
     * @author BadCen
     */
    public function find()
    {
        return View::fetch('/login/find');
    }

    public function reset()
    {
        $token = input('get.token');
        $mail = input('get.mail');
        // Users::findPass 生成的是 48 位 hex（random_bytes(24)），旧校验 32 位导致
        // 邮件里的链接永远打不开重置页。
        if (!$token || strlen((string)$token) !== 48 || !$mail) {
            View::assign([
                'msg' => '参数错误',
                'url' => url('index')
            ]);
            return View::fetch('/common/alert');
        } else {
            $user = Users::where('mail', '=', $mail)->find();
            if (!$user || !hash_equals((string)$user['sid'], (string)$token)) {
                View::assign([
                    'msg' => '令牌效验失败，请返回重试！',
                    'url' => url('login/find')
                ]);
                return View::fetch('/common/alert');
            } else {
                View::assign([
                    'webTitle' => '设置新密码',
                    'mail' => $mail
                ]);
                return View::fetch('login/reset');
            }
        }
    }

}
