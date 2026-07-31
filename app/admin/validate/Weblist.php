<?php

namespace app\admin\validate;

use think\Validate;

class Weblist extends Validate
{
    protected $rule = [
        'user_id|开通UID' => 'require',
        'domain|网站域名' => 'require',
        'webname|网站名称' => 'require',
        'user_qq|站长QQ' => 'require',
        'mail|联系邮箱' => 'require',
        'end_time|到期时间' => 'require',
    ];

    protected $scene = [
        'add' => ['user_id','domain','webname','user_qq','mail','end_time'],
    ];
}