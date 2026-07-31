<?php
declare (strict_types=1);

namespace app\index\controller;

use app\index\model\Users;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use think\facade\Request;
use think\response\Json;

class LoginAjax extends Common
{
    protected $middleware = [
        'app\middleware\CheckAjaxRequest'
    ];

    /**
     * login 用户登录
     * @return Json|void
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author BadCen
     */
    public function login()
    {
        $data = Request::post();
        $result = Users::login($data);
        return $result;
    }

    /**
     * reg 用户注册
     * @return Json|void
     * @author BadCen
     */
    public function reg()
    {
        $data = Request::post();
        $result = Users::reg($data);
        return $result;
    }

    /**
     * find 找回密码
     * @return Json|void
     * @author BadCen
     */
    public function find()
    {
        $data = Request::post();
        $result = Users::findPass($data);
        return $result;
    }

    /**
     * reset 重置密码
     * @return Json|void
     * @author BadCen
     */
    public function reset()
    {
        $data = Request::post();
        $result = Users::reset($data);
        return $result;
    }


    /**
     * logout 注销登录
     * @return Json|void
     * @author BadCen
     */
    public function logout()
    {
        if (Users::logout()) {
            return resultJson(1, '退出登录成功');
        }
    }


}