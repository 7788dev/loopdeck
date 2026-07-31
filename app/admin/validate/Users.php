<?php

namespace app\admin\validate;

use think\Validate;

class Users extends Validate
{
    protected $rule = [
        'password' => 'require|min:6|alphaNum|max:18',
    ];

    protected $message = [
        'password.require' => '密码不能为空',
        'password.min' => '请输入不低于6位的用户密码',
        'password.max' => '请输入6-16位的用户密码',
        'password.alphaNum' => '登录密码只能是字母和数字！',
    ];

    protected $scene = [
        'edit' => ['password'],
    ];
}