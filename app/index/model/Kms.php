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
    /** Upper bound for one card-generation request. */
    private const MAX_BATCH = 1000;

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
        $uid = (int)Session::get('user.uid');
        $km = (string)($data['km'] ?? '');
        $row = $self->where('km', $km)->where('zid', '=', WEB_ID)->find();
        if (!$row) {
            return resultJson(-1, '系统不存在这张卡密，请检查是否输入错误!');
        }
        if ((int)$row['useid'] !== 0) {
            return resultJson(-1, '该卡密已经被使用');
        }
        if (!in_array((string)$row['type'], ['vip', 'quota', 'agent'], true)) {
            return resultJson(-1, '未知的卡密类型');
        }
        if ((string)$row['type'] === 'agent' && Session::get('user.agent') >= $row['value']) {
            return resultJson(0, '兑换权限小于或等于当前权限');
        }
        // Cards must carry a value this system actually defines, otherwise a
        // forged legacy row could grant agent levels or VIP days out of range.
        if (!self::cardValueValid((string)$row['type'], (string)$row['value'])) {
            return resultJson(-1, '卡密面值异常，请联系管理员');
        }

        // Claiming the card and granting it used to be two statements, so the
        // same card could be redeemed twice by two concurrent requests. Claim
        // first with a conditional update and only then grant.
        $claimed = (int)$self->where('km', '=', $km)
            ->where('zid', '=', WEB_ID)
            ->where('useid', '=', 0)
            ->update([
                'useid' => $uid,
                'usetime' => date("Y-m-d H:i:s"),
            ]);
        if ($claimed !== 1) {
            return resultJson(-1, '该卡密已经被使用');
        }

        try {
            switch ((string)$row['type']) {
                case 'vip':
                    $user = Users::findByUid($uid);
                    $current = $user ? strtotime((string)($user['vip_end'] ?? '')) : false;
                    $renewal = ($current !== false && $current > time());
                    $vip_end = date("Y-m-d", strtotime("+" . (int)$row['value'] . " day", $renewal ? $current : time()));
                    $granted = Users::where('uid', '=', $uid)->update([
                        'vip_start' => date("Y-m-d"),
                        'vip_end' => $vip_end,
                    ]) !== false;
                    $message = $renewal
                        ? '恭喜您通过卡密成功续费会员，到期时间：' . $vip_end
                        : '恭喜您通过卡密成功开通会员，到期时间：' . $vip_end;
                    break;

                case 'quota':
                    $granted = Users::where('uid', '=', $uid)->inc('quota', (int)$row['value'])->update() !== false;
                    $message = '恭喜您成功通过卡密购买了：' . $row['value'] . '个配额';
                    break;

                default:
                    $granted = Users::where('uid', '=', $uid)->update(['agent' => $row['value']]) !== false;
                    $message = '恭喜您通过卡密成功购买了：' . is_Agent_Name($row['value']) . '，代理后台权限已开通！';
                    break;
            }
        } catch (\Throwable $exception) {
            $granted = false;
            $message = '';
        }

        if (!$granted) {
            // Put the card back so a failed grant does not consume it.
            $self->where('km', '=', $km)
                ->where('useid', '=', $uid)
                ->update(['useid' => 0, 'usetime' => null]);
            return resultJson(0, '未知错误');
        }

        Users::updateMyInfo();
        return resultJson(1, $message);
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
        $type = (string)($data['type'] ?? '');
        if (!in_array($type, ['vip', 'quota', 'agent'], true)) {
            return resultJson(0, '未知的卡密类型');
        }
        // Only agents may mint cards; without this check every user reaches
        // the pricing code below and an unconfigured discount yields 0.
        if ((int)Session::get('user.agent') < 1) {
            return resultJson(0, '您还不是代理，无法生成卡密');
        }
        $count = (int)($data['num'] ?? 0);
        if ($count < 1 || $count > self::MAX_BATCH) {
            return resultJson(0, '生成数量需要在 1 到 ' . self::MAX_BATCH . ' 之间');
        }

        // Resolve the product before spending anything, so a rejected request
        // can never leave the balance debited. Values outside the defined
        // product list must be rejected outright: their price key is missing,
        // which used to make (float)null === 0 a free purchase.
        if ($type === 'vip') {
            $value = is_Vip_Day($data['value']);
        } elseif ($type === 'quota') {
            $value = is_Quota_Num($data['value']);
        } else {
            $value = (int)$data['value'];
        }
        if ($value <= 0) {
            return resultJson(0, '商品不存在');
        }
        if ($type === 'agent'
            && (!in_array($value, [1, 2, 3], true) || (int)Session::get('user.agent') <= $value)) {
            return resultJson(0, '权限不足');
        }
        if (!self::cardValueValid($type, (string)$value)) {
            return resultJson(0, '商品不存在');
        }

        $unit = config('sys.' . $type . '_price_' . $value);
        if (!is_numeric($unit)) {
            return resultJson(0, '该商品价格未配置，请联系管理员');
        }
        $oprice = $count * (float)$unit;
        $zk = (float)config('sys.agent_give_z_' . Session::get('user.agent'));
        if ($zk <= 0) {
            // A missing discount key must fall back to full price, never to 0.
            $zk = 10.0;
        }
        $price = round($oprice * $zk / 10, 2);
        if ($price < 0 || !Users::spendBalance(Session::get('user.uid'), $price)) {
            return resultJson(0, '您的账户余额不足，请先充值');
        }
        Users::updateMyInfo();

        return self::issueCards($type, $value, $count);
    }

    /**
     * A card value the system actually defines: VIP day tiers, quota tiers or
     * the three agent levels. Anything else must never be minted or granted.
     */
    private static function cardValueValid(string $type, $value): bool
    {
        $value = trim((string)$value);
        if ($value === '' || !ctype_digit($value)) {
            return false;
        }
        return match ($type) {
            'vip' => is_Vip_Day($value) > 0,
            'quota' => is_Quota_Num($value) > 0,
            'agent' => in_array((int)$value, [1, 2, 3], true),
            default => false,
        };
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
        foreach (array_chunk($rows, 500) as $chunk) {
            Db::table('cloud_kms')->insertAll($chunk);
        }

        $success = '';
        $copy = '';
        foreach ($codes as $code) {
            $success .= '<p class="fs-lg fw-semibold mb-1">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</p>';
            $copy .= $code . "\n";
        }
        return resultJson(1, '生成成功', ['km' => $success, 'copy' => $copy]);
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
            return [
                'total' => $total,
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
        $type = (string)($data['type'] ?? '');
        if (!in_array($type, ['vip', 'quota', 'agent'], true)) {
            return resultJson(0, '未知的卡密类型');
        }
        $count = (int)($data['num'] ?? 0);
        if ($count < 1 || $count > self::MAX_BATCH) {
            return resultJson(0, '生成数量需要在 1 到 ' . self::MAX_BATCH . ' 之间');
        }

        if ($type === 'vip') {
            $value = is_Vip_Day($data['value']);
        } elseif ($type === 'quota') {
            $value = is_Quota_Num($data['value']);
        } else {
            $value = (int)($data['value'] ?? 0);
        }
        if ($value <= 0 || !self::cardValueValid($type, (string)$value)) {
            return resultJson(0, '商品不存在');
        }

        return self::issueCards($type, $value, $count);
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