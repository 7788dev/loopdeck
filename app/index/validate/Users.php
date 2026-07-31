<?php

namespace app\index\validate;

use think\validate;

class Users extends Validate
{
    protected $rule = [
        'username' => 'require|min:5|max:25',
        'password' => 'require|min:6|alphaNum|max:18',
        'qq' => 'require|number|max:10',
        'captcha|验证码' => 'require|captcha',
        'outpass' => 'require|min:6|alphaNum|max:18',
        'repass' => 'require|confirm:password',
        'mail' => 'require|email',
        'nickname' => 'require|min:1|chsAlphaNum|max:8',
    ];

    protected $message = [
        'username.require' => '用户名不能为空',
        'username.min' => '请输入不低于5位的用户名',
        'username.max' => '请输入5-25位的用户名',
        'password.require' => '密码不能为空',
        'password.min' => '请输入不低于6位的用户密码',
        'password.max' => '请输入6-16位的用户密码',
        'password.alphaNum' => '登录密码只能是字母和数字！',
        'qq.require' => 'QQ号码不能为空',
        'outpass.require' => '原密码不能为空',
        'outpass.min' => '请输入不低于6位的用户密码',
        'outpass.max' => '请输入6-18位的用户密码',
        'outpass.alphaNum' => '密码只能是字母和数字！',
        'repass' => '两次输入的密码不一致',
        'repass.require' => '二次密码确认不能为空',
        'nickname.require' => '昵称不能为空',
        'nickname.min' => '请输入不低于1位的昵称',
        'nickname.max' => '请输入1-8位的昵称',
        'nickname.chsAlphaNum' => '昵称只能是汉字、字母和数字',
    ];

    protected $scene = [
        'login' => ['username', 'password'],
        'reg' => ['username', 'password', 'qq', 'captcha'],
        'changePassWord' => ['oldpass', 'password', 'repass'],
        'find' => ['mail', 'captcha'],
        'reset' => ['password', 'repass'],
        'profile' => ['nickname', 'qq','mail'],
    ];

}