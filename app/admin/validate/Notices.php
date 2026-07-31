<?php

namespace app\admin\validate;

use think\Validate;

class Notices extends Validate
{
    protected $rule = [
        'content|内容' => 'require',
    ];

    protected $scene = [
        'add' => ['content'],
    ];
}