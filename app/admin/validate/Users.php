<?php

namespace app\admin\validate;

use think\Validate;

class Users extends Validate
{
    protected $rule = [
        'password' => 'require|min:6|max:64',
    ];

    protected $message = [
        'password.require' => '密码不能为空',
        'password.min' => '请输入不低于6位的用户密码',
        'password.max' => '请输入6-64位的用户密码',
    ];

    protected $scene = [
        'edit' => ['password'],
    ];
}
