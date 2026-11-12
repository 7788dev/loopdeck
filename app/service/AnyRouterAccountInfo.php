<?php

declare(strict_types=1);

namespace app\service;

/** Public account card fields; unknown balances stay unknown instead of becoming zero. */
final class AnyRouterAccountInfo
{
    public static function present(array $profile, string $remark, string $userId): array
    {
        $unit = $profile['quota_per_unit'] ?? null;
        $currency = ($profile['display_in_currency'] ?? null) === true && is_numeric($unit)
            && is_finite((float)$unit) && (float)$unit > 0;
        $amount = static function ($value) use ($currency, $unit): string {
            if ((!is_int($value) && !is_float($value) && !is_string($value))
                || !is_numeric($value) || !is_finite((float)$value)) {
                return '暂不可用';
            }
            return $currency ? '$' . number_format((float)$value / (float)$unit, 2, '.', ',')
                : number_format((float)$value, 0, '.', ',') . ' 额度';
        };
        $name = trim((string)($profile['nickname'] ?? ''));
        $requests = $profile['request_count'] ?? null;
        return ['user_id' => $userId, 'nickname' => trim($remark) !== '' ? trim($remark) : ($name ?: 'AnyRouter 用户 ' . $userId),
            'username' => (string)($profile['username'] ?? ''), 'avatar' => '/static/ruoyi/img/avatar.svg',
            'balance' => $amount($profile['quota'] ?? null), 'used' => $amount($profile['used_quota'] ?? null),
            'requests' => is_int($requests) && $requests >= 0 ? number_format($requests, 0, '.', ',') : '暂不可用',
            'group' => trim((string)($profile['group'] ?? '')) ?: '暂不可用',
            'currency' => $currency];
    }

    public static function remark(array $account): string
    {
        return (string)($account['remark'] ?? $account['nickname'] ?? '');
    }
}
