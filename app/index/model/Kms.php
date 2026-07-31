<?php

namespace app\index\model;

use think\Collection;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use think\facade\Db;
use think\facade\Session;
use think\Model;
use think\response\Json;

class Kms extends Model
{
    /**
     * activate 卡密激活
     * @param $data
     * @return Json|void
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author BadCen
     */
    public static function activate($data)
    {
        $self = new static();
        $row = $self->where('km', $data['km'])->where('zid', '=', WEB_ID)->find();
        if (!$row) {
            return resultJson(-1, '系统不存在这张卡密，请检查是否输入错误!');
        } else {
            switch ($row['type']) {
                case 'vip':
                    if ($row['useid'] == 0) {
                        $user = Users::findByUid(Session::get('user.uid'));
                        $vip_start = date("Y-m-d"); //VIP开通时间
                        //计算应开通时间
                        if ($user['vip_end']) {
                            $vip_end = date("Y-m-d", strtotime("+" . $row['value'] . " day", strtotime($user['vip_end'])));
                            $message = '恭喜您通过卡密成功续费会员，到期时间：' . $vip_end . '';
                        } else {
                            $vip_end = date("Y-m-d", strtotime("+" . $row['value'] . " day"));
                            $message = '恭喜您通过卡密成功开通会员，到期时间：' . $vip_end . '';
                        }
                        //修改卡密信息
                        $up_kms = $self->where('km', '=', $data['km'])
                            ->update([
                                'useid' => Session::get('user.uid'),
                                'usetime' => date("Y-m-d H:i:s")
                            ]);
                        //修改用户信息
                        $up_user = Users::where('uid', '=', Session::get('user.uid'))
                            ->update([
                                'vip_start' => $vip_start,
                                'vip_end' => $vip_end
                            ]);
                        //成功返回信息
                        if ($up_user && $up_kms) {
                            return resultJson(1, $message);
                        } else {
                            return resultJson(0, '未知错误');
                        }
                    } else {
                        return resultJson(-1, '该卡密已经被使用');
                    }
                    break;
                case 'quota':
                    if ($row['useid'] == 0) {
                        $user = Users::findByUid(Session::get('user.uid'));
                        //当前的配额
                        $now_quota = $user['quota'];
                        //增加后的配额
                        $add_peie = $now_quota + $row['value'];
                        //修改用户配额信息
                        $up_user = Users::where('uid', '=', Session::get('user.uid'))
                            ->update([
                                'quota' => $add_peie,
                            ]);
                        //修改卡密信息
                        $up_kms = $self->where('km', '=', $data['km'])
                            ->update([
                                'useid' => Session::get('user.uid'),
                                'usetime' => date("Y-m-d H:i:s")
                            ]);
                        //成功返回信息
                        if ($up_kms && $up_user) {
                            return resultJson(1, '恭喜您成功通过卡密购买了：' . $row['value'] . '个配额，当前配额：' . $add_peie . '个');
                        } else {
                            return resultJson(0, '未知错误');
                        }
                    } else {
                        return resultJson(-1, '该卡密已经被使用');
                    }
                    break;
                case 'agent':
                    if ($row['useid'] == 0) {
                        if (Session::get('user.agent') >= $row['value']) {
                            return resultJson(0, '兑换权限小于或等于当前权限');
                        }
                        //修改用户配额信息
                        $up_user = Users::where('uid', '=', Session::get('user.uid'))
                            ->update([
                                'agent' => $row['value'],
                            ]);
                        //修改卡密信息
                        $up_kms = $self->where('km', '=', $data['km'])
                            ->update([
                                'useid' => Session::get('user.uid'),
                                'usetime' => date("Y-m-d H:i:s")
                            ]);
                        //成功返回信息
                        if ($up_kms && $up_user) {
                            return resultJson(1, '恭喜您通过卡密成功购买了：' . is_Agent_Name($row['value']) . '，代理后台权限已开通！');
                        } else {
                            return resultJson(0, '未知错误');
                        }
                    } else {
                        return resultJson(-1, '该卡密已经被使用');
                    }
                    break;
            }
        }
    }

    /**
     * agent_add 代理卡密生成
     * @param $data
     * @return Json|void
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author BadCen
     */
    public static function agent_add($data)
    {
        Users::updateMyInfo(); //更新用户信息
        switch ($data['type']) {
            case 'vip':
            case 'quota':
            case 'agent':
                $oprice = $data['num'] * config('sys.' . $data['type'] . '_price_' . $data['value'] . '');
                $zk = config('sys.agent_give_z_' . Session::get('user.agent') . '');
                $price = round($oprice * $zk / 10, 2);
                if (Session::get('user.money') >= $price) {
                    $new_money = Session::get('user.money') - $price;
                    $up_user = Users::where('uid', '=', Session::get('user.uid'))
                        ->update(['money' => $new_money]);
                    if ($data['type'] == 'vip') {
                        $value = is_Vip_Day($data['value']);
                    } elseif ($data['type'] == 'quota') {
                        $value = is_Quota_Num($data['value']);
                    } elseif ($data['type'] == 'agent') {
                        if (Session::get('user.agent') > $data['value']) {
                            $value = $data['value'];
                        } else {
                            return resultJson(0, '权限不足');
                        }
                    }
                    if ($up_user !== false) {
                        for ($i = 0; $i < $data['num']; $i++) {
                            $km = getRandStr();
                            $km_data[] = [
                                'uid' => Session::get('user.uid'),
                                'type' => $data['type'],
                                'km' => $km,
                                'value' => $value,
                                'addtime' => date("Y-m-d H:i:s"),
                                'zid' => WEB_ID
                            ];
                        }
                        $km_data = array_chunk($km_data, 1000);
                        foreach ($km_data as $datas) {
                            $i++;
                            Db::table('cloud_kms')->insertAll($datas);
                        }
                        $list = Kms::where('uid', '=', Session::get('user.uid'))
                            ->where('type', $data['type'])
                            ->order('addtime desc')
                            ->limit($data['num'])
                            ->select();
                        $success = '';
                        $copy = '';
                        foreach ($list as $k => $v) {
                            $success .= '<p class="fs-lg fw-semibold mb-1">' . $v['km'] . '</p>';
                            $copy .= $v['km'] . "\n";
                        }
                        return resultJson(1, '生成成功', ['km' => $success, 'copy' => $copy]);
                    } else {
                        return resultJson(0, '生成失败，未知错误');
                    }
                } else {
                    return resultJson(0, '您的账户余额不足，请先充值');
                }
                break;
        }
    }

    /**
     * getMyList 获取代理生成卡密列表
     * @param null $type
     * @param null $state
     * @return Kms[]|array|Collection
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author BadCen
     */
    public static function getMyList($type = null, $state = null)
    {
        $self = new static();
        $query = $self->alias('a');
        $query->where('a.uid', '=', Session::get('user.uid'));
        if (!empty($type)) {
            $query->where('type', '=', $type);
        }
        if (!empty($state) && $state == 'used') {
            $query->where('useid', '<>', 0);
        }
        return $query->select();
    }

    public static function getKmList()
    {
        $start = (int)input('post.start');
        $length = (int)input('post.length');
        $search = input('post.search');

        $self = new static();
        $query = $self->alias('a');
        $query->where('zid', '=', WEB_ID);
        if (!empty($search['km'])) $query->where('km', '=',  $search['km']);
        if (!empty($search['type'])) $query->where('type', '=',  $search['type']);
        if (is_numeric($search['status'])) $search['status'] == 0 ? $query->where('useid', '=', 0) : $query->where('useid', '<>', 0);

        if ($result = $query->order('a.id desc')->limit($start, $length)->select()) {
            return [
                'total' => $query->count('id'),
                'page' => input('post.page'),
                'data' => $result,
            ];
        }
        return false;
    }

    public static function delByid($id)
    {
        $self = new static();
        if ($self->where('id', '=', $id)->where('zid', '=', WEB_ID)->delete()) {
            return true;
        }
        return false;
    }

    public static function admin_add($data)
    {
        $self = new static();
        switch ($data['type']) {
            case 'vip':
            case 'quota':
            case 'agent':
                if ($data['type'] == 'vip') {
                    $value = is_Vip_Day($data['value']);
                } elseif ($data['type'] == 'quota') {
                    $value = is_Quota_Num($data['value']);
                } elseif ($data['type'] == 'agent') {
                    $value = $data['value'];
                }
                if (Session::get('user.money')) {
                    for ($i = 0; $i < $data['num']; $i++) {
                        $km = getRandStr();
                        $km_data[] = [
                            'uid' => Session::get('user.uid'),
                            'type' => $data['type'],
                            'km' => $km,
                            'value' => $value,
                            'addtime' => date("Y-m-d H:i:s"),
                            'zid' => WEB_ID
                        ];
                    }
                    $km_data = array_chunk($km_data, 1000);
                    foreach ($km_data as $datas) {
                        $i++;
                        Db::table('cloud_kms')->insertAll($datas);
                    }
                    $list = Kms::where('uid', '=', Session::get('user.uid'))
                        ->where('type', $data['type'])
                        ->order('addtime desc')
                        ->limit($data['num'])
                        ->select();
                    $success = '';
                    $copy = '';
                    foreach ($list as $k => $v) {
                        $success .= '<p class="fs-lg fw-semibold mb-1">' . $v['km'] . '</p>';
                        $copy .= $v['km'] . "\n";
                    }
                    return resultJson(1, '生成成功', ['km' => $success, 'copy' => $copy]);
                } else {
                    return resultJson(0, '生成失败，未知错误');
                }
                break;
        }
    }

    public static function AdminDelUse()
    {
        $self = new static();
        if ($self->where('useid', '<>', 0)->where('zid', '=', WEB_ID)->delete()) {
            return true;
        }
        return false;
    }
    
    public static function AdminDelNotUse()
    {
        $self = new static();
        if ($self->where('useid', '=', 0)->where('zid', '=', WEB_ID)->delete()) {
            return true;
        }
        return false;
    }
    

}