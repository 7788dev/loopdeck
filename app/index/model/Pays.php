<?php

namespace app\index\model;

use think\Model;
use think\facade\Session;

class Pays extends Model
{
    public static function YpayVip($data)
    {
        Users::updateMyInfo(); //更新用户信息
        $res_price = config('sys.' . $data['shop'] . '_price_' . $data['shopid']);
        if ($res_price <= Session::get('user.money')) {
            //开通时间
            $vip_start = date('Y-m-d H:i:s');
            //计算开通的vip时长
            if (Session::get('user.vip_end')) {
                $vip_end = date("Y-m-d", strtotime("+" . is_Vip_Day($data['shopid']) . " day", strtotime(Session::get('user.vip_end'))));
            } else {
                $vip_end = date("Y-m-d", strtotime("+" . is_Vip_Day($data['shopid']) . " day"));
            }
            $new_money = Session::get('user.money') - $res_price;
            //修改用户信息
            $up_user = Users::where('uid', '=', Session::get('user.uid'))
                ->field('vip_start,vip_end,money')
                ->update([
                    'vip_start' => $vip_start,
                    'vip_end' => $vip_end,
                    'money' => $new_money
                ]);
            if ($up_user) {
                Users::updateMyInfo(); //更新用户信息
                return resultJson(1, '开通会员成功，感谢您的购买', ['success' => '']);
            } else {
                return resultJson(0, '购买失败，服务器繁忙');
            }
        } else {
            return resultJson(0, '您的账户余额不足，请先充值或选择其它支付方式', ['success' => 'money','error' => '交易取消']);
        }
    }

    public static function YpayQuota($data)
    {
        Users::updateMyInfo(); //更新用户信息
        $res_price = config('sys.' . $data['shop'] . '_price_' . $data['shopid']);
        if ($res_price <= Session::get('user.money')) {
            //获取购买配额数量
            $res_peie = is_Quota_Num($data['shopid']);
            //计算用户配额总数
            $all_peie = Session::get('user.quota') + $res_peie;
            //计算用户剩下的余额
            $new_money = Session::get('user.money') - $res_price;
            //修改用户信息
            $up_user = Users::where('uid', '=', Session::get('user.uid'))
                ->field('quota,money')
                ->update([
                    'quota' => $all_peie,
                    'money' => $new_money
                ]);
            if ($up_user) {
                Users::updateMyInfo(); //更新用户信息
                return resultJson(1, '购买额度成功，感谢您的购买', ['success' => '']);
            } else {
                return resultJson(0, '购买失败，服务器繁忙');
            }
        } else {
            return resultJson(0, '您的账户余额不足，请先充值或选择其它支付方式', ['success' => 'money','error' => '交易取消']);
        }
    }

    public static function Submit_Pay($data)
    {
        Users::updateMyInfo(); //更新用户信息
        $self = new static();
        switch ($data['shop']) {
            case 'vip':
                $name = is_Vip_Month($data['shopid']) . '杯快乐水';
                $res_money = config('sys.' . $data['shop'] . '_price_' . $data['shopid']); //计算价格
                break;
            case 'quota':
                $name = is_Quota_Num($data['shopid']) . '份快乐';
                $res_money = config('sys.' . $data['shop'] . '_price_' . $data['shopid']); //计算价格
                break;
            case 'agent':
                $name = '快乐' . is_Agent_Name($data['shopid']);
                $res_money = config('sys.' . $data['shop'] . '_price_' . $data['shopid']); //计算价格
                break;
            case 'money':
                $name = $data['shopid'] . '元';
                $res_money = $data['shopid'];
                if ($res_money > 1000) {
                    return resultJson(0, '请输入小于1000的充值金额');
                }
                break;
            case 'site':
                $siteUrl = $data['prefix'] . '.' . $data['domain'];
                $name = $data['webname'] . "（{$siteUrl}）";
                if ($siteUrl == $_SERVER['HTTP_HOST']) {
                    return resultJson(0,'分站域名不能和主站相同');
                } elseif (Weblist::where('user_id', '=', Session::get('user.uid'))->field('web_id')->find()){
                    return resultJson(0,'您已经开通过分站');
                } elseif (Weblist::where('domain', '=', $siteUrl)->find()) {
                    return resultJson(0,'该域名前缀已被使用');
                }
                $pay_data = json_encode(['siteUrl' => $siteUrl]);
                $res_money = config('sys.' . $data['shop'] . '_price_' . $data['shopid']);
                break;
        }
        $insert = [
            'uid' => Session::get('user.uid'),
            'qq' => Session::get('user.qq'),
            'orderid' => date("YmdHis") . rand(111, 999),
            'addtime' => date('Y-m-d H:i:s'),
            'name' => $name,
            'money' => $res_money,
            'type' => $data['pay_type'],
            'shop' => $data['shop'],
            'shopid' => $data['shopid'],
            'data'=> $pay_data ?? [],
            'zid' => config('web.web_id')
        ];
        if ($self->insert($insert)) {
            $row = $self->where('uid', '=', Session::get('user.uid'))->order('addtime desc')->find();
            $pay_url = '/index/epay/submit?orderid=' . $row['orderid'] . '&type=' . $data['pay_type'];
            return resultJson('1', '订单创建成功，是否现在前往付款？', ['success' => $pay_url, 'error' => '交易取消']);
        }
    }

    /**
     * findByOrderId
     * @param $orderid
     * @return Pays|array|false|Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author BadCen
     */
    public static function findByOrderId($orderid)
    {
        $self = new static();
        if ($result = $self->where('orderid', '=', $orderid)->find()) {
            return $result;
        } else {
            return false;
        }
    }

    /**
     * updateByOrderId
     * @param $orderid
     * @param $data
     * @return Pays|false
     * @author BadCen
     */
    public static function updateByOrderId($orderid, $data)
    {
        $self = new static();
        if ($result = $self->where('orderid', '=', $orderid)->update($data)) {
            return $result;
        } else {
            return false;
        }
    }
}
