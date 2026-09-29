<?php

namespace app\index\model;

use app\service\RedemptionPlan;
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
     * activate 兑换码激活
     * @param $data
     * @return Json|void
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author BadCen
     */
    public static function activate($data)
    {
        if (!is_string($data['km'] ?? null) || trim($data['km']) === '' || strlen($data['km']) > 255) {
            return resultJson(0, '请输入有效的兑换码');
        }
        $uid = (int)Session::get('user.uid');
        $km = trim($data['km']);
        try {
            $result = Db::transaction(static function () use ($uid, $km) {
                // Lock the owner before the card, also serializing separate codes
                // redeemed concurrently by the same user.
                $user = Users::where('uid', $uid)->where('web_id', WEB_ID)->lock(true)->find();
                if (!$user) {
                    return resultJson(0, '用户不存在');
                }
                $row = static::where('km', $km)->where('zid', WEB_ID)->lock(true)->find();
                if (!$row) {
                    return resultJson(-1, '系统不存在这张兑换码，请检查是否输入错误');
                }
                if ((int)$row['useid'] !== 0) {
                    return resultJson(-1, '该兑换码已经被使用');
                }
                $plan = RedemptionPlan::fromCard((string)$row['type'], (string)$row['value']);
                if ($plan === null) {
                    return resultJson(-1, '兑换码权益无效或已停用，请联系管理员');
                }

                $updates = [];
                $parts = [];
                $quota = (int)$user['quota'];
                $bundle = $row['type'] === 'bundle';
                if ($bundle || $row['type'] === 'quota') {
                    if ($quota !== RedemptionPlan::UNLIMITED_ACCOUNTS) {
                        $quota = $bundle
                            ? ($plan['account_limit'] === 0 ? RedemptionPlan::UNLIMITED_ACCOUNTS : max($quota, $plan['account_limit']))
                            : $quota + $plan['account_limit'];
                    }
                    if ($quota > RedemptionPlan::MAX_ACCOUNTS) {
                        return resultJson(0, '账号总数超出允许范围，请联系管理员');
                    }
                    $updates['quota'] = $quota;
                    $parts[] = '账号总数：' . RedemptionPlan::accountLimitLabel($quota) . '（所有平台共用）';
                }
                $vipEnd = (string)($user['vip_end'] ?? '');
                if ($bundle || $row['type'] === 'vip') {
                    $current = strtotime($vipEnd);
                    $renewal = $current !== false && $current > time();
                    if ($vipEnd === RedemptionPlan::PERMANENT_VIP_END || ($bundle && $plan['vip_days'] === 0)) {
                        $vipEnd = RedemptionPlan::PERMANENT_VIP_END;
                        array_unshift($parts, '永久会员');
                    } else {
                        $expires = strtotime('+' . $plan['vip_days'] . ' day', $renewal ? $current : time());
                        $vipEnd = date('Y-m-d', min($expires, strtotime(RedemptionPlan::PERMANENT_VIP_END)));
                        array_unshift($parts, '会员已' . ($renewal ? '延长' : '开通') . ' ' . $plan['vip_days'] . ' 天，到期时间：' . $vipEnd);
                    }
                    $updates['vip_start'] = $renewal && !empty($user['vip_start']) ? $user['vip_start'] : date('Y-m-d');
                    $updates['vip_end'] = $vipEnd;
                }
                if ($quota === (int)$user['quota'] && $vipEnd === (string)($user['vip_end'] ?? '')) {
                    return resultJson(0, '当前权益已包含该兑换码的权益，无需兑换，兑换码仍可使用');
                }
                $claimed = static::where('id', $row['id'])->where('zid', WEB_ID)->where('useid', 0)
                    ->update(['useid' => $uid, 'usetime' => date('Y-m-d H:i:s')]);
                if ($claimed !== 1) {
                    return resultJson(-1, '该兑换码已经被使用');
                }
                // One user update and a surrounding transaction make the two
                // benefits and the card claim succeed or roll back together.
                if (Users::where('uid', $uid)->update($updates) === false) {
                    throw new \RuntimeException('Unable to grant redemption benefits');
                }
                return resultJson(1, '兑换成功：' . implode('；', $parts), [
                    'account_limit' => $quota,
                    'vip_end' => $vipEnd,
                    'account_limit_label' => RedemptionPlan::accountLimitLabel($quota),
                    'membership_label' => RedemptionPlan::membershipLabel($vipEnd),
                ]);
            });
        } catch (\Throwable $exception) {
            return resultJson(0, '兑换失败，权益未变更且兑换码未使用，请稍后重试');
        }
        if ($result->getData()['code'] === 1) {
            Users::updateMyInfo();
        }
        return $result;
    }

    /**
     * @return \think\response\Json
     */
    private static function issueCards(string $type, $value, int $count)
    {
        $codes = [];
        $rows = [];
        for ($index = 0; $index < $count; $index++) {
            $code = getRandStr();
            $codes[] = $code;
            $rows[] = [
                'uid' => Session::get('user.uid'),
                'type' => $type,
                'km' => $code,
                'value' => $value,
                'addtime' => date("Y-m-d H:i:s"),
                'zid' => WEB_ID,
            ];
        }
        try {
            Db::transaction(static function () use ($rows) {
                foreach (array_chunk($rows, 500) as $chunk) {
                    if (Db::name('kms')->insertAll($chunk) !== count($chunk)) {
                        throw new \RuntimeException('Unable to issue complete card batch');
                    }
                }
            });
        } catch (\Throwable $exception) {
            return resultJson(0, '生成失败，未保存兑换码，请稍后重试');
        }

        $success = '';
        $copy = '';
        foreach ($codes as $code) {
            $success .= '<p class="fs-lg fw-semibold mb-1">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</p>';
            $copy .= $code . "\n";
        }
        return resultJson(1, '生成成功', [
            'km' => $success, 'copy' => $copy,
            'benefits' => RedemptionPlan::description($type, $value), 'count' => $count,
        ]);
    }

    public static function getKmList()
    {
        $start = (int)input('post.start');
        $length = (int)input('post.length');
        $search = (array)(input('post.search') ?? []);

        $self = new static();
        $query = $self->alias('a');
        $query->where('zid', '=', WEB_ID);
        if (!empty($search['km'])) $query->where('km', '=',  $search['km']);
        if (!empty($search['type'])) $query->where('type', '=',  $search['type']);
        if (is_numeric($search['status'] ?? null)) $search['status'] == 0 ? $query->where('useid', '=', 0) : $query->where('useid', '<>', 0);

        // Count on a pristine query: reusing the paged query object would make
        // the aggregate inherit ORDER BY/LIMIT and misreport the total.
        $total = $self->alias('a')->where('zid', '=', WEB_ID);
        if (!empty($search['km'])) $total->where('km', '=',  $search['km']);
        if (!empty($search['type'])) $total->where('type', '=',  $search['type']);
        if (is_numeric($search['status'] ?? null)) $search['status'] == 0 ? $total->where('useid', '=', 0) : $total->where('useid', '<>', 0);
        $total = $total->count('id');

        if ($result = $query->order('a.id desc')->limit($start, $length)->select()) {
            $rows = $result->toArray();
            foreach ($rows as &$row) {
                $row['benefits'] = RedemptionPlan::description((string)$row['type'], (string)$row['value']);
            }
            unset($row);
            return [
                'total' => $total,
                'page' => input('post.page'),
                'data' => $rows,
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
        if (($data['type'] ?? 'bundle') !== 'bundle') {
            return resultJson(0, '请刷新页面，使用会员时长和账号总数生成兑换码');
        }
        $count = RedemptionPlan::integer($data['num'] ?? null, RedemptionPlan::MAX_BATCH);
        if ($count === null || $count < 1) {
            return resultJson(0, '生成数量需要是 1 到 ' . RedemptionPlan::MAX_BATCH . ' 之间的整数');
        }
        $plan = RedemptionPlan::fromInput($data);
        if ($plan === null) {
            return resultJson(0, '会员天数需为 0–' . RedemptionPlan::MAX_DAYS . ' 的整数，账号总数需为 0–'
                . RedemptionPlan::MAX_ACCOUNTS . ' 的整数；0 分别表示永久会员和账号数量不限');
        }

        return self::issueCards('bundle', json_encode($plan, JSON_THROW_ON_ERROR), $count);
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
