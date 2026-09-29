<?php

declare(strict_types=1);

namespace app\service;

final class RedemptionPlan
{
    public const MAX_DAYS = 3650;
    public const MAX_ACCOUNTS = 100000;
    public const MAX_BATCH = 1000;
    public const UNLIMITED_ACCOUNTS = -1;
    public const PERMANENT_VIP_END = '9999-12-31';

    public static function integer($value, int $max): ?int
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $value = trim((string)$value);
        if ($value === '' || !ctype_digit($value) || strlen($value) > 10 || (int)$value > $max) {
            return null;
        }
        return (int)$value;
    }

    public static function fromInput(array $data): ?array
    {
        $days = self::integer($data['vip_days'] ?? null, self::MAX_DAYS);
        $accounts = self::integer($data['account_limit'] ?? null, self::MAX_ACCOUNTS);
        if ($days === null || $accounts === null) {
            return null;
        }
        return ['vip_days' => $days, 'account_limit' => $accounts];
    }

    public static function fromCard(string $type, string $value): ?array
    {
        if ($type === 'bundle') {
            $data = json_decode($value, true);
            if (!is_array($data) || count($data) !== 2
                || !is_int($data['vip_days'] ?? null) || !is_int($data['account_limit'] ?? null)) {
                return null;
            }
            return self::fromInput($data);
        }

        // Existing single-benefit codes keep their original redemption semantics.
        $legacy = self::integer($value, self::MAX_ACCOUNTS);
        return match (true) {
            $type === 'vip' && in_array($legacy, [3, 7, 30, 90, 180, 365], true)
                => ['vip_days' => $legacy, 'account_limit' => 0],
            $type === 'quota' && in_array($legacy, [1, 3, 5, 10], true)
                => ['vip_days' => 0, 'account_limit' => $legacy],
            default => null,
        };
    }

    public static function description(string $type, string $value): string
    {
        $plan = self::fromCard($type, $value);
        if ($plan === null) {
            return '权益无效或已停用';
        }
        $parts = [];
        if ($type === 'bundle' && $plan['vip_days'] === 0) {
            $parts[] = '永久会员';
        }
        if ($plan['vip_days'] > 0) {
            $parts[] = '会员 ' . $plan['vip_days'] . ' 天';
        }
        if ($plan['account_limit'] > 0) {
            $parts[] = ($type === 'quota' ? '新增账号名额 ' : '账号总数 ') . $plan['account_limit'] . ' 个';
        } elseif ($type === 'bundle') {
            $parts[] = '账号数量不限';
        }
        return implode(' · ', $parts);
    }

    public static function presets(): array
    {
        return [
            ['name' => '体验套餐', 'vip_days' => 7, 'account_limit' => 3],
            ['name' => '月度套餐', 'vip_days' => 30, 'account_limit' => 7],
            ['name' => '季度套餐', 'vip_days' => 90, 'account_limit' => 10],
            ['name' => '年度套餐', 'vip_days' => 365, 'account_limit' => 20],
            ['name' => '永久无限套餐', 'vip_days' => 0, 'account_limit' => 0],
        ];
    }

    public static function accountLimitLabel($limit): string
    {
        return (int)$limit === self::UNLIMITED_ACCOUNTS ? '不限' : (int)$limit . ' 个';
    }

    public static function membershipLabel($end): string
    {
        return $end === self::PERMANENT_VIP_END ? '永久会员' : ($end ? '会员到期：' . $end : '普通用户');
    }
}
