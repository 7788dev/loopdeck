<?php

declare(strict_types=1);

namespace app\service;

use app\index\model\Order;
use app\index\model\Pays;
use app\index\model\Users;
use app\index\model\Weblist;
use epay\AlipayNotify;
use think\facade\Db;
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
    public const SHOPS = ['vip', 'quota', 'agent', 'money', 'site'];

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

            case 'agent':
                Users::where('uid', '=', $uid)->update(['agent' => (int)$order['shopid']]);
                break;

            case 'money':
                Users::where('uid', '=', $uid)->inc('money', (float)$order['money'])->update();
                break;

            case 'site':
                self::openSite($order);
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
     * @param array<string,mixed> $order
     */
    private static function openSite(array $order): void
    {
        $uid = (int)$order['uid'];
        $domain = self::orderSiteDomain($order);
        if ($domain === '') {
            throw new \RuntimeException('order carries no validated site domain');
        }
        if (Weblist::where('user_id', '=', $uid)->find() || Weblist::where('domain', '=', $domain)->find()) {
            return;
        }

        $days = (int)is_Site_Day($order['shopid']);
        $prefix = get_Prefix() . '_';
        $user = Users::where('uid', '=', $uid)->find();
        $webId = Weblist::field('sup_id,user_id,webname,domain,user_qq,mail,start_time,end_time,prefix,web_key')
            ->insertGetId([
                'sup_id' => (int)($order['zid'] ?? 0),
                'user_id' => $uid,
                'webname' => (string)$order['name'],
                'domain' => $domain,
                'user_qq' => (string)($user['qq'] ?? ''),
                'mail' => (string)($user['mail'] ?? ''),
                'start_time' => date('Y-m-d'),
                'end_time' => date('Y-m-d', strtotime('+' . max(1, $days) . ' day')),
                'prefix' => $prefix,
                'web_key' => getRandStr(16),
            ]);
        if (!$webId) {
            throw new \RuntimeException('site row could not be created');
        }

        foreach (self::siteSchemaStatements($prefix) as $statement) {
            Db::query($statement);
        }

        Users::where('uid', '=', $uid)->update([
            'power' => 6,
            'web_id' => $webId,
        ]);
    }

    /**
     * The buyer picked the sub-site host at checkout, where it was validated
     * against the main site and existing tenants. Read it back from the order
     * instead of from a client-controlled cookie.
     *
     * @param array<string,mixed> $order
     */
    public static function orderSiteDomain(array $order): string
    {
        $payload = $order['data'] ?? '';
        if (!is_string($payload) || $payload === '') {
            return '';
        }
        $decoded = json_decode($payload, true);
        $domain = is_array($decoded) ? trim((string)($decoded['siteUrl'] ?? '')) : '';
        if ($domain === '' || strlen($domain) > 190) {
            return '';
        }
        return preg_match('/\A[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?\z/', $domain) === 1 ? $domain : '';
    }

    /** @return array<int,string> */
    private static function siteSchemaStatements(string $prefix): array
    {
        $sql = @file_get_contents(root_path() . 'public' . DIRECTORY_SEPARATOR . 'static' . DIRECTORY_SEPARATOR . 'site.sql');
        if (!is_string($sql) || $sql === '') {
            $sql = (string)@file_get_contents('./static/site.sql');
        }
        $statements = [];
        foreach (explode(';', str_replace('cloud_', $prefix, $sql)) as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $statements[] = $statement;
            }
        }
        return $statements;
    }

    /**
     * @param mixed $callbackAmount
     * @param mixed $orderAmount
     */
    private static function amountMatches($callbackAmount, $orderAmount): bool
    {
        if ($callbackAmount === null || $callbackAmount === '') {
            // Older gateways omit the amount on the return leg; the order is
            // still authoritative for what gets granted.
            return true;
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
