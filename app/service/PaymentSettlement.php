<?php

declare(strict_types=1);

namespace app\service;

use app\index\model\Order;
use app\index\model\Pays;
use app\index\model\Users;
use epay\AlipayNotify;
use Throwable;
use think\facade\Db;

/**
 * Single entry point for e-pay gateway callbacks.
 *
 * The gateway signature covers the query string only, never the endpoint the
 * request was sent to. A callback therefore has to be re-bound to the stored
 * order: the order decides which product is granted, how much was paid and who
 * receives it. Without that binding a signed low-value callback can be replayed
 * against any other callback endpoint.
 */
final class PaymentSettlement
{
    public const SHOPS = ['vip', 'quota', 'money'];

    private const PAID_STATES = ['TRADE_SUCCESS', 'TRADE_FINISHED'];

    /**
     * @param array<string,mixed> $callback raw gateway query parameters
     * @param array<string,mixed> $epayConfig
     * @return array{ok:bool,applied:bool,message:string,order:array<string,mixed>|null}
     */
    public static function settle(string $shop, array $callback, array $epayConfig): array
    {
        if (!in_array($shop, self::SHOPS, true)) {
            return self::failure('未知的支付回调类型');
        }
        if (!is_string($callback['sign'] ?? null)
            || !(new AlipayNotify($epayConfig))->getSignVeryfy($callback, $callback['sign'])) {
            return self::failure('订单效验失败');
        }

        $orderId = trim((string)($callback['out_trade_no'] ?? ''));
        if ($orderId === '' || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $orderId)) {
            return self::failure('订单号不合法');
        }

        $order = Pays::where('orderid', '=', $orderId)->find();
        if (!$order) {
            return self::failure('该订单号不存在');
        }
        $order = $order->toArray();

        // The endpoint is attacker-chosen; the order is not.
        if ((string)$order['shop'] !== $shop) {
            return self::failure('订单类型与支付回调不匹配');
        }
        if (!self::amountMatches($callback['money'] ?? null, $order['money'] ?? null)) {
            return self::failure('订单金额与支付回调不一致');
        }
        if (!in_array((string)($callback['trade_status'] ?? ''), self::PAID_STATES, true)) {
            return self::failure('trade_status=' . (string)($callback['trade_status'] ?? ''), $order);
        }

        try {
            return Db::transaction(static function () use ($orderId, $callback, $order): array {
                $locked = Pays::where('orderid', $orderId)->lock(true)->find();
                if (!$locked) {
                    throw new \RuntimeException('Order disappeared');
                }
                if ((int)$locked['status'] !== 0) {
                    return ['ok' => true, 'applied' => false, 'message' => '订单已处理', 'order' => $order];
                }
                self::grant($order);
                if (!Order::add([
                    'uid' => (int)$order['uid'], 'type' => (string)$order['type'],
                    'orderid' => $orderId, 'trade_no' => (string)($callback['trade_no'] ?? ''),
                    'time' => date('Y-m-d H:i:s'), 'name' => (string)$order['name'],
                    'money' => $order['money'], 'status' => 2, 'zid' => (int)($order['zid'] ?? 0),
                ])) {
                    throw new \RuntimeException('Payment record failed');
                }
                $locked->save(['status' => 2, 'endtime' => date('Y-m-d H:i:s')]);
                return ['ok' => true, 'applied' => true,
                    'message' => '开通' . (string)$order['name'] . '成功，感谢您的购买', 'order' => $order];
            });
        } catch (Throwable $exception) {
            return self::failure('支付结果暂未入账，请稍后刷新；若持续失败请联系站长', $order);
        }
    }

    /**
     * Grant the purchased product to the account named on the order.
     *
     * @param array<string,mixed> $order
     */
    private static function grant(array $order): void
    {
        $uid = (int)$order['uid'];
        if ($uid <= 0) {
            throw new \RuntimeException('order has no owner');
        }

        if (!Users::where('uid', $uid)->lock(true)->find()) {
            throw new \RuntimeException('Order owner missing');
        }
        switch ((string)$order['shop']) {
            case 'vip':
                self::grantVip($uid, (int)is_Vip_Day($order['shopid']));
                break;

            case 'quota':
                $quota = (int)is_Quota_Num($order['shopid']);
                if ($quota <= 0) {
                    throw new \RuntimeException('Invalid quota product');
                }
                Users::where('uid', '=', $uid)->inc('quota', $quota)->update();
                break;

            case 'money':
                Users::where('uid', '=', $uid)->inc('money', (float)$order['money'])->update();
                break;

        }
    }

    private static function grantVip(int $uid, int $days): void
    {
        if ($days <= 0) {
            throw new \RuntimeException('Invalid VIP product');
        }
        $user = Users::where('uid', '=', $uid)->find();
        $current = $user ? strtotime((string)($user['vip_end'] ?? '')) : false;
        $base = ($current !== false && $current > time()) ? $current : time();
        Users::where('uid', '=', $uid)->update([
            'vip_start' => date('Y-m-d'),
            'vip_end' => date('Y-m-d', strtotime('+' . $days . ' day', $base)),
        ]);
    }

    /**
     * @param mixed $callbackAmount
     * @param mixed $orderAmount
     */
    private static function amountMatches($callbackAmount, $orderAmount): bool
    {
        // The order amount is what gets granted; a callback that does not even
        // state an amount can no longer be accepted, because "absent" and
        // "different" are indistinguishable and the order is the only anchor.
        if ($callbackAmount === null || $callbackAmount === '' || !is_numeric($callbackAmount)) {
            return false;
        }
        return abs((float)$callbackAmount - (float)$orderAmount) < 0.005;
    }

    /**
     * @param array<string,mixed>|null $order
     * @return array{ok:bool,applied:bool,message:string,order:array<string,mixed>|null}
     */
    private static function failure(string $message, ?array $order = null): array
    {
        return ['ok' => false, 'applied' => false, 'message' => $message, 'order' => $order];
    }
}
