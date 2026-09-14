<?php

declare(strict_types=1);

namespace app\service;

use app\index\model\Order;
use app\index\model\Pays;
use app\index\model\Users;
use epay\AlipayNotify;
use Throwable;

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
        if (!(new AlipayNotify($epayConfig))->verifyReturn()) {
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

        // A single conditional UPDATE is the claim: only the request that moves
        // the order out of state 0 is allowed to grant the product, so replays
        // and concurrent callbacks cannot double-credit an account.
        $claimed = (int)Pays::where('orderid', '=', $orderId)
            ->where('status', '=', 0)
            ->update([
                'status' => 2,
                'endtime' => date('Y-m-d H:i:s'),
            ]);
        if ($claimed !== 1) {
            return [
                'ok' => true,
                'applied' => false,
                'message' => '订单已处理',
                'order' => $order,
            ];
        }

        Order::add([
            'uid' => (int)$order['uid'],
            'type' => (string)$order['type'],
            'orderid' => $orderId,
            'trade_no' => (string)($callback['trade_no'] ?? ''),
            'time' => date('Y-m-d H:i:s'),
            'name' => (string)$order['name'],
            'money' => $order['money'],
            'status' => 2,
            'zid' => (int)($order['zid'] ?? 0),
        ]);

        try {
            self::grant($order);
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'applied' => false,
                'message' => '订单已支付，但开通失败，请联系站长处理',
                'order' => $order,
            ];
        }

        return [
            'ok' => true,
            'applied' => true,
            'message' => '开通' . (string)$order['name'] . '成功，感谢您的购买',
            'order' => $order,
        ];
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

        switch ((string)$order['shop']) {
            case 'vip':
                self::grantVip($uid, (int)is_Vip_Day($order['shopid']));
                break;

            case 'quota':
                Users::where('uid', '=', $uid)->inc('quota', (int)is_Quota_Num($order['shopid']))->update();
                break;

            case 'money':
                Users::where('uid', '=', $uid)->inc('money', (float)$order['money'])->update();
                break;

        }
    }

    private static function grantVip(int $uid, int $days): void
    {
        if ($days <= 0) {
            return;
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
