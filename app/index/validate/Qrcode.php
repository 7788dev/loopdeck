<?php

namespace app\index\validate;

use think\Validate;

class Qrcode extends Validate
{
    protected $rule = [
        'alipay_url|支付宝URL' => 'require',
        'qq_url|QQURL' => 'require',
        'wechat_url|微信URL' => 'require',
        'name|识别码' => 'require',
    ];

    protected $scene = [
        'create' => ['alipay_url', 'qq_url', 'wechat_url','name'],
    ];
}