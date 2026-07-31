<?php

namespace app\index\validate;

use think\Validate;

class Kms extends Validate
{
    protected $rule = [
        'value|卡密面值' => 'require|number|>:0|<:8000',
        'num|生成数量' => 'require|number|>:0|<:101',
    ];

    protected $scene = [
        'add' => ['value','num'],
    ];
}