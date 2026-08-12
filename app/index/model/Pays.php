<?php

namespace app\index\model;

use think\Model;
use think\facade\Session;

class Pays extends Model
{
    public static function YpayVip($data)
    {
        Users::updateMyInfo(); //更新用户信息
        $uid = (int)Session::get('user.uid');
        $days = is_Vip_Day($data['shopid']);
        if ($days <= 0) {
            return resultJson(0, '商品不存在');
        }
        $price = round((float)config('sys.' . $data['shop'] . '_price_' . $data['shopid']), 2);
        // The balance check and the debit must be one statement, otherwise two
        // concurrent purchases both see the pre-purchase balance.
        if (!Users::spendBalance($uid, $price)) {
            return resultJson(0, '您的账户余额不足，请先充值或选择其它支付方式', ['success' => 'money','error' => '交易取消']);
        }

        $user = Users::findByUid($uid);
        $current = $user ? strtotime((string)($user['vip_end'] ?? '')) : false;
        $base = ($current !== false && $current > time()) ? $current : time();
        $updated = Users::where('uid', '=', $uid)->update([
            'vip_start' => date('Y-m-d H:i:s'),
            'vip_end' => date('Y-m-d', strtotime('+' . $days . ' day', $base)),
        ]);
        if ($updated === false) {
            Users::where('uid', '=', $uid)->inc('money', $price)->update();
            return resultJson(0, '购买失败，服务器繁忙');
        }
        Users::updateMyInfo(); //更新用户信息
        return resultJson(1, '开通会员成功，感谢您的购买', ['success' => '']);
    }

    public static function YpayQuota($data)
    {
        Users::updateMyInfo(); //更新用户信息
        $uid = (int)Session::get('user.uid');
        $quota = is_Quota_Num($data['shopid']);
        if ($quota <= 0) {
            return resultJson(0, '商品不存在');
        }
        $price = round((float)config('sys.' . $data['shop'] . '_price_' . $data['shopid']), 2);
        if (!Users::spendBalance($uid, $price)) {
            return resultJson(0, '您的账户余额不足，请先充值或选择其它支付方式', ['success' => 'money','error' => '交易取消']);
        }

        if (Users::where('uid', '=', $uid)->inc('quota', $quota)->update() === false) {
            Users::where('uid', '=', $uid)->inc('money', $price)->update();
            return resultJson(0, '购买失败，服务器繁忙');
        }
        Users::updateMyInfo(); //更新用户信息
        return resultJson(1, '购买额度成功，感谢您的购买', ['success' => '']);
    }

    public static function Submit_Pay($data)
    {
        Users::updateMyInfo(); //更新用户信息
        $self = new static();
        if (!in_array((string)($data['shop'] ?? ''), ['vip', 'quota', 'agent', 'money', 'site'], true)) {
            return resultJson(0, '未知的商品类型');
        }
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
                $res_money = round((float)$data['shopid'], 2);
                if (!is_numeric($data['shopid']) || $res_money < 0.01 || $res_money > 1000) {
                    return resultJson(0, '充值金额需要在 0.01 到 1000 元之间');
                }
                $name = $res_money . '元';
                break;
            case 'site':
                $siteUrl = strtolower(trim((string)$data['prefix']) . '.' . trim((string)$data['domain']));
                // The callback provisions the sub-site from this value, so it
                // has to be a plain host name and nothing else.
                if (strlen($siteUrl) > 190
                    || preg_match('/\A[a-z0-9]([a-z0-9.-]*[a-z0-9])?\z/', $siteUrl) !== 1) {
                    return resultJson(0, '分站域名格式不正确');
                }
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
            // Sequential, guessable order numbers let one user address another
            // user's order; use an unguessable suffix instead.
            'orderid' => date("YmdHis") . strtolower(getRandStr(12, 2)),
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
            $pay_url = '/index/epay/submit?orderid=' . rawurlencode($insert['orderid'])
                . '&type=' . rawurlencode((string)$data['pay_type']);
            return resultJson('1', '订单创建成功，是否现在前往付款？', ['success' => $pay_url, 'error' => '交易取消']);
        }
        return resultJson(0, '订单创建失败，请稍后再试');
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
