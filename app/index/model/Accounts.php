<?php

namespace app\index\model;

use think\facade\Db;
use think\facade\Session;
use think\Model;
use app\service\RedemptionPlan;

class Accounts extends Model
{
    public static function add($type = null, $user_id = null, $data = [])
    {
        return self::saveAccount((string)$type, (string)$user_id, $data, false);
    }

    public static function addQrcode($type = null, $user_id = null, $data = [])
    {
        return self::saveAccount((string)$type, (string)$user_id, $data, true);
    }

    private static function saveAccount(string $type, string $userId, array $data, bool $qrcode)
    {
        $uid = (int)Session::get('user.uid');
        try {
            return Db::transaction(static function () use ($type, $userId, $data, $qrcode, $uid) {
                // Share the same owner lock as redemption: simultaneous additions
                // on different platforms cannot both take the final free slot.
                $user = Users::where('uid', $uid)->where('web_id', WEB_ID)->lock(true)->find();
                if (!$user) {
                    return resultJson(0, '用户不存在');
                }
                if ($qrcode && static::where('type', 'qrcode')->where('user_id', $userId)
                    ->where('zid', WEB_ID)->where('uid', '<>', $uid)->find()) {
                    return resultJson(0, '收款识别码已被使用，请更换识别码');
                }
                $query = static::where('type', $type)->where('user_id', $userId)->where('uid', $uid);
                $values = ['data' => serialize($data), 'addtime' => date('Y-m-d H:i:s'), 'state' => 1];
                if ($query->find()) {
                    if ($query->update($values) === false) {
                        throw new \RuntimeException('Unable to update account');
                    }
                    if (!$qrcode) {
                        Jobs::updateJob($type, $userId);
                    }
                    return resultJson(1, '更新成功');
                }
                if ((int)$user['quota'] !== RedemptionPlan::UNLIMITED_ACCOUNTS
                    && (int)$user['quota'] <= static::where('uid', $uid)->count('id')) {
                    return resultJson(0, '账号总数已达上限，所有平台共用账号配额，请联系管理员或使用兑换码');
                }
                if (static::insert($values + ['uid' => $uid, 'type' => $type, 'user_id' => $userId, 'zid' => WEB_ID]) !== 1) {
                    throw new \RuntimeException('Unable to add account');
                }
                if (!$qrcode) {
                    Jobs::add($type, $userId);
                }
                return resultJson(1, $qrcode ? '添加成功' : '登录成功');
            });
        } catch (\Throwable $exception) {
            return resultJson(0, '账号保存失败，请稍后重试');
        }
    }

    public static function getMyList($type)
    {
        $self = new static();
        if ($result = $self->where('type', '=', $type)->where('uid', Session::get('user.uid'))->order('addtime desc')->select()) {
            return $result;
        }
        return false;
    }

    public static function findByUserId($type, $user_id)
    {
        $self = new static();
        if ($result = $self->where('type', $type)
            ->where('user_id', $user_id)
            ->where('uid', Session::get('user.uid'))
            ->find()) {
            return $result;
        }
        return false;
    }

    public static function delByUserId($type, $user_id)
    {
        $self = new static();
        if($result = $self->where('type', $type)
            ->where('user_id', $user_id)
            ->where('uid', Session::get('user.uid'))
            ->delete()){
            return $result;
        }
        return false;
    }

    public static function delQrcodeByUserId($user_id)
    {
        $self = new static();
        if($result = $self->where('type','qrcode')->where('user_id', $user_id)->where('uid', Session::get('user.uid'))->delete()){
            return $result;
        }
        return false;
    }

    public static function getMyAccountNum()
    {
        $self = new static();
        // count() 生成 COUNT(*)，不把含登录凭据的 data 大字段整行拉回 PHP
        return $self->where('uid', '=', Session::get('user.uid'))->count('id');
    }

    public static function accountCount()
    {
        $self = new static();
        return $self->where('zid', '=', WEB_ID)->count('id');
    }
    
    public static function delById($type, $id, $uid = null)
    {
        $uid = $uid ?? Session::get('user.uid');
        $uid = filter_var($uid, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($uid === false) {
            return false;
        }

        $self = new static();
        $result = $self->where('type', $type)
            ->where('user_id', $id)
            ->where('uid', (int)$uid)
            ->delete();
        return $result === false ? false : $result;
    }
}
