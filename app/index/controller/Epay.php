<?php

namespace app\index\controller;

use app\index\model\Order;
use app\index\model\Pays;
use app\index\model\Users;
use app\index\model\Weblist;
use epay\AlipayNotify;
use epay\AlipaySubmit;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use think\facade\Db;
use think\facade\Request;
use think\facade\Session;
use think\facade\View;

class epay extends Common
{
    protected $middleware = [
        'app\middleware\CheckLoginUser'
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

    /**
     * _empty
     * @return string
     * @author BadCen
     */
    public function _empty()
    {
        return '404';
    }

    /**
     * submit
     * @return string|void
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author BadCen
     */
    public function submit()
    {
        $data = input('get.');
        if (!isset($data['type'])) {
            View::assign(['msg' => '支付方式错误', 'url' => url('/index/console')]);
            return View::fetch('/common/alert');
        } elseif (!$order = Pays::where('orderid', '=', $data['orderid'])->find()) {
            View::assign(['msg' => '该订单号不存在，请重新发起支付请求', 'url' => url('/index/console')]);
            return View::fetch('/common/alert');
        } else {
            exit($this->Pay($order['orderid'], $order['name'], $order['money'], $order['type'], $order['shop'], $order['shopid']));
        }
    }

    /**
     * Pay
     * @param $orderid
     * @param $name
     * @param $price
     * @param $type
     * @param $shop
     * @param $shopid
     * @author BadCen
     */
    public function Pay($orderid, $name, $price, $type, $shop, $shopid)
    {
        $epay_config = [
            'partner' => config('sys.epay_id'),
            'key' => config('sys.epay_key'),
            'sign_type' => strtoupper('MD5'),
            'input_charset' => strtolower('utf-8'),
            'transport' => 'http',
            'apiurl' => config('sys.epay_url')
        ];
        $parameter = array(
            "pid" => trim($epay_config['partner']),
            "type" => $type,
            "notify_url" => 'http://' . $_SERVER['HTTP_HOST'] . url('Epay/' . $shop . '_Notify'), //服务器异步通知页面路径
            "return_url" => 'http://' . $_SERVER['HTTP_HOST'] . url('Epay/' . $shop . '_Return'), //页面跳转同步通知页面路径
            "out_trade_no" => $orderid,
            "name" => $name,
            "money" => $price,
            "sitename" => config('web.webname')
        );
        //建立请求
        $alipaySubmit = new AlipaySubmit($epay_config);
        $html_text = $alipaySubmit->buildRequestForm($parameter, "get");
        echo $html_text;
    }

    /**
     * vip_Return
     * @return string
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author BadCen
     */
    public function vip_Return()
    {
        $data = Request::get();
        $alipayNotify = new AlipayNotify($this->epay_config);
        $verify_result = $alipayNotify->verifyReturn();
        if ($verify_result) {
            $pay_order = Pays::findByOrderId($data['out_trade_no']);
            $order = [
                'uid' => Session::get('user.uid'),
                'type' => '' . $pay_order['type'] . '',
                'orderid' => $data['out_trade_no'],
                'trade_no' => $data['trade_no'],
                'time' => date("Y-m-d H:i:s"),
                'name' => $pay_order['name'],
                'money' => $pay_order['money'],
                'status' => 2,
                'zid' => WEB_ID,
            ];
            if ($data['trade_status'] == 'TRADE_FINISHED' || $data['trade_status'] == 'TRADE_SUCCESS') {
                if ($pay_order['status'] == 0) {
                    Order::add($order);
                    Pays::updateByOrderId($data['out_trade_no'], ['status' => 2, 'endtime' => date("Y-m-d H:i:s")]);
                    $start_time = is_Vip_Day($pay_order['shopid']);
                    //开通时间
                    $vip_start = date("Y-m-d");
                    //计算开通的vip时长
                    if (Session::get('user.vip_end')) {
                        $vip_end = date("Y-m-d", strtotime("+" . $start_time . " day", strtotime(Session::get('user.vip_end'))));
                    } else {
                        $vip_end = date("Y-m-d", strtotime("+" . $start_time . " day"));
                    }
                    Users::where('uid', '=', Session::get('user.uid'))
                        ->field('vip_start,vip_end')
                        ->update([
                            'vip_start' => $vip_start,
                            'vip_end' => $vip_end
                        ]);
                } else {
                    View::assign(['msg' => '开通' . $pay_order['name'] . '成功，感谢您的购买', 'url' => url('/index/console')]);
                    return View::fetch('/common/alert');
                }
                View::assign(['msg' => '开通' . $pay_order['name'] . '成功，感谢您的购买', 'url' => url('/index/console')]);
                return View::fetch('/common/alert');
            } else {
                return "trade_status=" . $data['trade_status'];
            }
        } else {
            View::assign(['msg' => '订单效验失败', 'url' => url('/index/console')]);
            return View::fetch('/common/alert');
        }
    }

    /**
     * vip_Notify
     * @return string
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author BadCen
     */
    public function vip_Notify()
    {
        $data = Request::get();
        $alipayNotify = new AlipayNotify($this->epay_config);
        $verify_result = $alipayNotify->verifyReturn();
        if ($verify_result) {
            $pay_order = Pays::findByOrderId($data['out_trade_no']);
            $order = [
                'uid' => Session::get('user.uid'),
                'type' => '' . $pay_order['type'] . '',
                'orderid' => $data['out_trade_no'],
                'trade_no' => $data['trade_no'],
                'time' => date("Y-m-d H:i:s"),
                'name' => $pay_order['name'],
                'money' => $pay_order['money'],
                'status' => 2,
                'zid' => WEB_ID,
            ];
            Order::add($order);
            if ($data['trade_status'] == 'TRADE_FINISHED') {
            } else if ($data['trade_status'] == 'TRADE_SUCCESS' && $pay_order['status'] == 0) {
                Pays::updateByOrderId($data['out_trade_no'], ['status' => 2, 'endtime' => date("Y-m-d H:i:s")]);
                $start_time = is_Vip_Day($pay_order['shopid']);
                //开通时间
                $vip_start = date("Y-m-d");
                //计算开通的vip时长
                if (Session::get('user.vip_end')) {
                    $vip_end = date("Y-m-d", strtotime("+" . $start_time . " day", strtotime(Session::get('user.vip_end'))));
                } else {
                    $vip_end = date("Y-m-d", strtotime("+" . $start_time . " day"));
                }
                Users::where('uid', '=', Session::get('user.uid'))
                    ->field('vip_start,vip_end')
                    ->update([
                        'vip_start' => $vip_start,
                        'vip_end' => $vip_end
                    ]);
            }
            return 'success';
        } else {
            return 'fail';
        }
    }

    /**
     * quota_Reutrn
     * @return string
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author BadCen
     */
    public function quota_Return()
    {
        $data = Request::get();
        $alipayNotify = new AlipayNotify($this->epay_config);
        $verify_result = $alipayNotify->verifyReturn();
        if ($verify_result) {
            $pay_order = Pays::findByOrderId($data['out_trade_no']);
            $order = [
                'uid' => Session::get('user.uid'),
                'type' => '' . $pay_order['type'] . '',
                'orderid' => $data['out_trade_no'],
                'trade_no' => $data['trade_no'],
                'time' => date("Y-m-d H:i:s"),
                'name' => $pay_order['name'],
                'money' => $pay_order['money'],
                'status' => 2,
                'zid' => WEB_ID,
            ];
            if ($data['trade_status'] == 'TRADE_FINISHED' || $data['trade_status'] == 'TRADE_SUCCESS') {
                if ($pay_order['status'] == 0) {
                    Order::add($order);
                    Pays::updateByOrderId($data['out_trade_no'], ['status' => 2, 'endtime' => date("Y-m-d H:i:s")]);
                    $quota_Num = is_Quota_Num($pay_order['shopid']);
                    $res_Num = Session::get('user.quota') + $quota_Num;
                    Users::where('uid', '=', Session::get('user.uid'))
                        ->field('quota')
                        ->update([
                            'quota' => $res_Num,
                        ]);
                } else {
                    View::assign(['msg' => '购买' . $pay_order['name'] . '成功，感谢您的购买', 'url' => url('/index/console')]);
                    return View::fetch('/common/alert');
                }
                View::assign(['msg' => '购买' . $pay_order['name'] . '成功，感谢您的购买', 'url' => url('/index/console')]);
                return View::fetch('/common/alert');
            } else {
                return "trade_status=" . $data['trade_status'];
            }
        } else {
            View::assign(['msg' => '订单效验失败', 'url' => url('/index/console')]);
            return View::fetch('/common/alert');
        }
    }

    /**
     * quota_Notify
     * @return string
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author BadCen
     */
    public function quota_Notify()
    {
        $data = Request::get();
        $alipayNotify = new AlipayNotify($this->epay_config);
        $verify_result = $alipayNotify->verifyReturn();
        if ($verify_result) {
            $pay_order = Pays::findByOrderId($data['out_trade_no']);
            $order = [
                'uid' => Session::get('user.uid'),
                'type' => '' . $pay_order['type'] . '',
                'orderid' => $data['out_trade_no'],
                'trade_no' => $data['trade_no'],
                'time' => date("Y-m-d H:i:s"),
                'name' => $pay_order['name'],
                'money' => $pay_order['money'],
                'status' => 2,
                'zid' => WEB_ID,
            ];
            Order::add($order);
            if ($data['trade_status'] == 'TRADE_FINISHED') {
            } else if ($data['trade_status'] == 'TRADE_SUCCESS' && $pay_order['status'] == 0) {
                Pays::updateByOrderId($data['out_trade_no'], ['status' => 2, 'endtime' => date("Y-m-d H:i:s")]);
                $quota_Num = is_Quota_Num($pay_order['shopid']);
                $res_Num = Session::get('user.quota') + $quota_Num;
                Users::where('uid', '=', Session::get('user.uid'))
                    ->field('quota')
                    ->update([
                        'quota' => $res_Num,
                    ]);
            }
            return 'success';
        } else {
            return 'fail';
        }
    }

    /**
     * agent_Return
     * @return string
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author BadCen
     */
    public function agent_Return()
    {
        $data = Request::get();
        $alipayNotify = new AlipayNotify($this->epay_config);
        $verify_result = $alipayNotify->verifyReturn();
        if ($verify_result) {
            $pay_order = Pays::findByOrderId($data['out_trade_no']);
            $order = [
                'uid' => Session::get('user.uid'),
                'type' => '' . $pay_order['type'] . '',
                'orderid' => $data['out_trade_no'],
                'trade_no' => $data['trade_no'],
                'time' => date("Y-m-d H:i:s"),
                'name' => $pay_order['name'],
                'money' => $pay_order['money'],
                'status' => 2,
                'zid' => WEB_ID,
            ];
            if ($data['trade_status'] == 'TRADE_FINISHED' || $data['trade_status'] == 'TRADE_SUCCESS') {
                if ($pay_order['status'] == 0) {
                    Order::add($order);
                    Pays::updateByOrderId($data['out_trade_no'], ['status' => 2, 'endtime' => date("Y-m-d H:i:s")]);
                    Users::where('uid', '=', Session::get('user.uid'))
                        ->field('agent')
                        ->update([
                            'agent' => $pay_order['shopid'],
                        ]);
                } else {
                    View::assign(['msg' => '开通' . $pay_order['name'] . '成功，感谢您的购买', 'url' => url('/index/console')]);
                    return View::fetch('/common/alert');
                }
                View::assign(['msg' => '开通' . $pay_order['name'] . '成功，感谢您的购买', 'url' => url('/index/console')]);
                return View::fetch('/common/alert');
            } else {
                return "trade_status=" . $data['trade_status'];
            }
        } else {
            View::assign(['msg' => '订单效验失败', 'url' => url('/index/console')]);
            return View::fetch('/common/alert');
        }
    }

    /**
     * agent_Notify
     * @return string
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author BadCen
     */
    public function agent_Notify()
    {
        $data = Request::get();
        $alipayNotify = new AlipayNotify($this->epay_config);
        $verify_result = $alipayNotify->verifyReturn();
        if ($verify_result) {
            $pay_order = Pays::findByOrderId($data['out_trade_no']);
            $order = [
                'uid' => Session::get('user.uid'),
                'type' => '' . $pay_order['type'] . '',
                'orderid' => $data['out_trade_no'],
                'trade_no' => $data['trade_no'],
                'time' => date("Y-m-d H:i:s"),
                'name' => $pay_order['name'],
                'money' => $pay_order['money'],
                'status' => 2,
                'zid' => WEB_ID,
            ];
            Order::add($order);
            if ($data['trade_status'] == 'TRADE_FINISHED') {
            } else if ($data['trade_status'] == 'TRADE_SUCCESS' && $pay_order['status'] == 0) {
                Pays::updateByOrderId($data['out_trade_no'], ['status' => 2, 'endtime' => date("Y-m-d H:i:s")]);
                Users::where('uid', '=', Session::get('user.uid'))
                    ->field('agent')
                    ->update([
                        'agent' => $pay_order['shopid'],
                    ]);
            }
            return 'success';
        } else {
            return 'fail';
        }
    }

    /**
     * money_Return
     * @return string
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author BadCen
     */
    public function money_Return()
    {
        $data = Request::get();
        $alipayNotify = new AlipayNotify($this->epay_config);
        $verify_result = $alipayNotify->verifyReturn();
        if ($verify_result) {
            $pay_order = Pays::findByOrderId($data['out_trade_no']);
            $order = [
                'uid' => Session::get('user.uid'),
                'type' => '' . $pay_order['type'] . '',
                'orderid' => $data['out_trade_no'],
                'trade_no' => $data['trade_no'],
                'time' => date("Y-m-d H:i:s"),
                'name' => $pay_order['name'],
                'money' => $pay_order['money'],
                'status' => 2,
                'zid' => WEB_ID,
            ];
            if ($data['trade_status'] == 'TRADE_FINISHED' || $data['trade_status'] == 'TRADE_SUCCESS') {
                if ($pay_order['status'] == 0) {
                    Order::add($order);
                    Pays::updateByOrderId($data['out_trade_no'], ['status' => 2, 'endtime' => date("Y-m-d H:i:s")]);
                    $shop_money = $pay_order['money'];
                    $res_money = Session::get('user.money') + $shop_money;
                    Users::where('uid', '=', Session::get('user.uid'))
                        ->field('money')
                        ->update([
                            'money' => $res_money,
                        ]);
                } else {
                    View::assign(['msg' => '开通' . $pay_order['name'] . '成功，感谢您的购买', 'url' => url('/index/console')]);
                    return View::fetch('/common/alert');
                }
                View::assign(['msg' => '开通' . $pay_order['name'] . '成功，感谢您的购买', 'url' => url('/index/console')]);
                return View::fetch('/common/alert');
            } else {
                return "trade_status=" . $data['trade_status'];
            }
        } else {
            View::assign(['msg' => '订单效验失败', 'url' => url('/index/console')]);
            return View::fetch('/common/alert');
        }
    }

    /**
     * money_Notify
     * @return string
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author BadCen
     */
    public function money_Notify()
    {
        $data = Request::get();
        $alipayNotify = new AlipayNotify($this->epay_config);
        $verify_result = $alipayNotify->verifyReturn();
        if ($verify_result) {
            $pay_order = Pays::findByOrderId($data['out_trade_no']);
            $order = [
                'uid' => Session::get('user.uid'),
                'type' => '' . $pay_order['type'] . '',
                'orderid' => $data['out_trade_no'],
                'trade_no' => $data['trade_no'],
                'time' => date("Y-m-d H:i:s"),
                'name' => $pay_order['name'],
                'money' => $pay_order['money'],
                'status' => 2,
                'zid' => WEB_ID,
            ];
            Order::add($order);
            if ($data['trade_status'] == 'TRADE_FINISHED') {
            } else if ($data['trade_status'] == 'TRADE_SUCCESS' && $pay_order['status'] == 0) {
                Pays::updateByOrderId($data['out_trade_no'], ['status' => 2, 'endtime' => date("Y-m-d H:i:s")]);
                $shop_money = $pay_order['money'];
                $res_money = Session::get('user.money') + $shop_money;
                Users::where('uid', '=', Session::get('user.uid'))
                    ->field('money')
                    ->update([
                        'money' => $res_money,
                    ]);
            }
            return 'success';
        } else {
            return 'fail';
        }
    }

    public function site_Return()
    {
        $data = Request::get();
        $alipayNotify = new AlipayNotify($this->epay_config);
        $verify_result = $alipayNotify->verifyReturn();
        if ($verify_result) {
            $pay_order = Pays::findByOrderId($data['out_trade_no']);
            $order = [
                'uid' => Session::get('user.uid'),
                'type' => '' . $pay_order['type'] . '',
                'orderid' => $data['out_trade_no'],
                'trade_no' => $data['trade_no'],
                'time' => date("Y-m-d H:i:s"),
                'name' => $pay_order['name'],
                'money' => $pay_order['money'],
                'status' => 2,
                'zid' => WEB_ID,
            ];
            if ($data['trade_status'] == 'TRADE_FINISHED' || $data['trade_status'] == 'TRADE_SUCCESS') {
                if ($pay_order['status'] == 0) {
                    Order::add($order);
                    Pays::updateByOrderId($data['out_trade_no'], ['status' => 2, 'endtime' => date("Y-m-d H:i:s")]);
                    $is_start_day = is_Site_Day($pay_order['shopid']);//获取开通天数
                    $site_time = date("Y-m-d", strtotime("+" . $is_start_day . " day"));
                    $prefix = get_Prefix() . '_';
                    $addsite = [
                        'domain' => cookie('siteUrl'),
                        'prefix' => $prefix,
                        'sup_id' => WEB_ID,
                        'web_key' => getRandStr(16),
                        'start_time' => date("Y-m-d"),
                        'user_id' => Session::get('user.uid'),
                        'webname' => $pay_order['name'],
                        'user_qq' => Session::get('user.qq'),
                        'mail' => Session::get('user.mail'),
                        'end_time' => $site_time
                    ];
                    $webid = Weblist::field('sup_id,user_id,webname,domain,user_qq,mail,start_time,end_time,prefix,web_key')->insertGetId($addsite);
                    $sqls = file_get_contents('./static/site.sql');
                    $sqls = str_replace('cloud_', $prefix, $sqls);
                    $explode = explode(';', $sqls);
                    foreach ($explode as $sql) {
                        if ($sql = trim($sql)) {
                            Db::query($sql);
                        }
                    }
                    Users::where('uid', '=', Session::get('user.uid'))
                        ->update([
                            'power' => 6,
                            'web_id' => $webid
                        ]);
                } else {
                    View::assign(['msg' => '开通分站[' . $pay_order['name'] . ']成功，感谢您的购买', 'url' => cookie('siteUrl')]);
                    cookie('siteUrl', null);
                    return View::fetch('/common/alert');
                }
                View::assign(['msg' => '开通分站[' . $pay_order['name'] . ']成功，感谢您的购买', 'url' => cookie('siteUrl')]);
                cookie('siteUrl', null);
                return View::fetch('/common/alert');
            } else {
                return "trade_status=" . $data['trade_status'];
            }
        } else {
            View::assign(['msg' => '订单效验失败', 'url' => url('/index/console')]);
            return View::fetch('/common/alert');
        }
    }

    public function site_Notify()
    {
        $data = Request::get();
        $alipayNotify = new AlipayNotify($this->epay_config);
        $verify_result = $alipayNotify->verifyReturn();
        if ($verify_result) {
            $pay_order = Pays::findByOrderId($data['out_trade_no']);
            $order = [
                'uid' => Session::get('user.uid'),
                'type' => '' . $pay_order['type'] . '',
                'orderid' => $data['out_trade_no'],
                'trade_no' => $data['trade_no'],
                'time' => date("Y-m-d H:i:s"),
                'name' => $pay_order['name'],
                'money' => $pay_order['money'],
                'status' => 2,
                'zid' => WEB_ID,
            ];
            Order::add($order);
            if ($data['trade_status'] == 'TRADE_FINISHED') {
            } else if ($data['trade_status'] == 'TRADE_SUCCESS' && $pay_order['status'] == 0) {
                Pays::updateByOrderId($data['out_trade_no'], ['status' => 2, 'endtime' => date("Y-m-d H:i:s")]);
                $is_start_day = is_Site_Day($pay_order['shopid']);//获取开通天数
                $site_time = date("Y-m-d", strtotime("+" . $is_start_day . " day"));
                $prefix = get_Prefix() . '_';
                $addsite = [
                    'domain' => cookie('siteUrl'),
                    'prefix' => $prefix,
                    'sup_id' => WEB_ID,
                    'web_key' => getRandStr(16),
                    'start_time' => date("Y-m-d"),
                    'user_id' => Session::get('user.uid'),
                    'webname' => $pay_order['name'],
                    'title' => config('web.title'),
                    'user_qq' => Session::get('user.qq'),
                    'mail' => Session::get('user.mail'),
                    'end_time' => $site_time
                ];
                Weblist::field('sup_id,user_id,webname,domain,user_qq,mail,start_time,end_time,prefix,web_key')->insert($addsite);
                $sqls = file_get_contents('./static/site.sql');
                $sqls = str_replace('cloud_', $prefix, $sqls);
                $explode = explode(';', $sqls);
                foreach ($explode as $sql) {
                    if ($sql = trim($sql)) {
                        Db::query($sql);
                    }
                }
                //修改用户信息
                Users::where('uid', '=', Session::get('user.uid'))
                    ->update(['power' => 6]);
            }
            return 'success';
        } else {
            return 'full';
        }
    }
}