<?php

declare(strict_types=1);

namespace app\index\controller;

use think\facade\View;

/**
 * The bundled direct-Alipay controller was encrypted and could not be audited.
 * Direct payment is therefore closed explicitly; the clear Epay controller
 * remains available for merchants that configure it.
 */
class Alipay extends Common
{
    protected $middleware = [
        'app\middleware\CheckLoginUser',
    ];

    public function submit()
    {
        View::assign([
            'msg' => '直连支付宝功能已安全停用，请在后台选择“易支付”通道。',
            'url' => url('/index/console/shop/vip'),
        ]);
        return View::fetch('/common/alert');
    }

    public function notify(): string
    {
        return 'fail';
    }

    public function return(): string
    {
        return 'fail';
    }

    public function _empty()
    {
        return $this->submit();
    }
}
