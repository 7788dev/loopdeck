<?php

namespace app\index\controller;

use app\index\model\Pays;
use app\index\model\Users;
use app\service\PaymentSettlement;
use epay\AlipaySubmit;
use think\facade\Request;
use think\facade\Session;
use think\facade\View;

class Epay extends Common
{
    /**
     * The gateway's server-to-server callbacks carry no session. They used to
     * sit behind the login middleware, so crediting an order depended entirely
     * on the buyer's browser coming back. Settlement no longer reads the
     * session, so those actions can run unauthenticated.
     *
     * The framework lower-cases the configured names before comparing, so the
     * callback URLs are generated lower-cased too (PHP method lookup is
     * case-insensitive). Any other spelling simply keeps the old behaviour.
     */
    protected $middleware = [
        'app\middleware\CheckLoginUser' => [
            'except' => ['vip_notify', 'quota_notify', 'agent_notify', 'money_notify', 'site_notify'],
        ],
    ];

    private $epay_config;

    public function __construct()
    {
        $this->epay_config = [
            'partner' => config('sys.epay_id'),
            'key' => config('sys.epay_key'),
            'sign_type' => strtoupper('MD5'),
            'input_charset' => strtolower('utf-8'),
            'transport' => 'http',
            'apiurl' => config('sys.epay_url')
        ];
    }

    public function _empty()
    {
        return '404';
    }

    /**
     * Hand the buyer over to the gateway.
     *
     * The order is looked up inside the caller's own account: order numbers are
     * short and time based, so an unscoped lookup lets anyone pay against — and
     * therefore enumerate — someone else's order.
     */
    public function submit()
    {
        $data = input('get.');
        if (!isset($data['type'])) {
            return $this->alert('支付方式错误');
        }

        $order = Pays::where('orderid', '=', (string)($data['orderid'] ?? ''))
            ->where('uid', '=', Session::get('user.uid'))
            ->find();
        if (!$order) {
            return $this->alert('该订单号不存在，请重新发起支付请求');
        }
        if ((int)$order['status'] !== 0) {
            return $this->alert('该订单已处理，请勿重复支付');
        }

        exit($this->Pay(
            $order['orderid'],
            $order['name'],
            $order['money'],
            $order['type'],
            $order['shop'],
            $order['shopid']
        ));
    }

    public function Pay($orderid, $name, $price, $type, $shop, $shopid)
    {
        if (!in_array((string)$shop, PaymentSettlement::SHOPS, true)) {
            return $this->alert('支付类型错误');
        }

        $base = rtrim(get_Domain(), '/');
        $parameter = [
            "pid" => trim((string)$this->epay_config['partner']),
            "type" => $type,
            "notify_url" => $base . url('Epay/' . $shop . '_notify'),
            "return_url" => $base . url('Epay/' . $shop . '_Return'),
            "out_trade_no" => $orderid,
            "name" => $name,
            "money" => $price,
            "sitename" => config('web.webname')
        ];
        $alipaySubmit = new AlipaySubmit($this->epay_config);
        echo $alipaySubmit->buildRequestForm($parameter, "get");
    }

    public function vip_Return()
    {
        return $this->handleReturn('vip');
    }

    public function vip_Notify()
    {
        return $this->handleNotify('vip');
    }

    public function quota_Return()
    {
        return $this->handleReturn('quota');
    }

    public function quota_Notify()
    {
        return $this->handleNotify('quota');
    }

    public function agent_Return()
    {
        return $this->handleReturn('agent');
    }

    public function agent_Notify()
    {
        return $this->handleNotify('agent');
    }

    public function money_Return()
    {
        return $this->handleReturn('money');
    }

    public function money_Notify()
    {
        return $this->handleNotify('money');
    }

    public function site_Return()
    {
        return $this->handleReturn('site');
    }

    public function site_Notify()
    {
        return $this->handleNotify('site');
    }

    /**
     * Browser-facing leg: the buyer is redirected back here after paying.
     */
    private function handleReturn(string $shop)
    {
        $result = PaymentSettlement::settle($shop, Request::get(), $this->epay_config);
        if (!$result['ok'] && str_starts_with($result['message'], 'trade_status=')) {
            return $result['message'];
        }
        if (!$result['ok']) {
            return $this->alert($result['message']);
        }

        // Session copies of money/quota/vip are stale after the grant.
        Users::updateMyInfo();
        $order = $result['order'] ?? [];
        $target = url('/index/console');
        if ($shop === 'site') {
            $domain = PaymentSettlement::orderSiteDomain($order);
            if ($domain !== '') {
                $target = Request::scheme() . '://' . $domain;
            }
        }
        return $this->alert($result['message'], (string)$target);
    }

    /**
     * Server-to-server leg. Nothing here reads the session, so the same code
     * settles the order correctly whether or not a browser is involved.
     */
    private function handleNotify(string $shop)
    {
        $result = PaymentSettlement::settle($shop, Request::get(), $this->epay_config);
        return $result['ok'] ? 'success' : 'fail';
    }

    private function alert(string $message, string $url = '')
    {
        View::assign([
            'msg' => $message,
            'url' => $url !== '' ? $url : url('/index/console'),
        ]);
        return View::fetch('/common/alert');
    }
}
