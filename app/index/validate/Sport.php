<?php

namespace app\index\validate;

use think\Validate;

class Sport extends Validate
{
    protected $rule = [
        'step_start' => 'number|max:5',
        'step_stop' => 'number|max:5|between:1,98000|checkNumber',
    ];

    protected $message = [
        'step_start.number' => '开始步数格式错误',
        'step_stop.number' => '结束步数格式错误',
        'step_start.max' => '开始长度不能超过 5',
        'step_stop.max' => '结束步数长度不能超过 5',
        'step_stop.between' => '结束步数最大为98000',
    ];

    protected function checkNumber($value, $rule, $data = [])
    {
        $value = 1;
        if ($data['step_start'] > $data['step_stop']) {
            return '请输入正确的步数范围';
        }
        return true;
    }
}