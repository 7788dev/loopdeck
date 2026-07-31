<?php

namespace app\index\validate;

use think\Validate;

class Accounts extends Validate
{
    protected $rule = [
        'uid|用户UID' => 'require',
        'type|账号类型' => 'require',
        'user_id|账号ID' => 'require|number',
    ];

    protected $message = [
        'user_id.require' => '账号ID不能为空',
        'user_id.number' => '账号ID必须为数字',
        'type.require' => '账号类型不能为空',
        'uid.require' => '用户UID不能为空',
    ];

    protected $scene = [
        'add' => ['uid', 'type', 'user_id'],
    ];
}