<?php

namespace app\admin\validate;

use think\Validate;

class Tasks extends Validate
{
    protected $rule = [
        'type|任务类型' => 'require',
        'name|任务名称' => 'require',
        'describe|任务描述' => 'require',
        'icon|任务图标' => 'require',
        'execute_name|执行方法' => 'require',
    ];

    protected $scene = [
        'add' => ['type','name','describe','icon','execute_name'],
    ];
}