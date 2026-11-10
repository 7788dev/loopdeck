<?php

declare(strict_types=1);

namespace app\service;

/** Builds a read-only view of confirmed level experience from upstream snapshots. */
final class BilibiliExperienceSummary
{
    public static function build(
        array $reward,
        array $coin,
        array $privilege,
        array $coinConfig = [],
        ?int $coinJobState = null
    ): array
    {
        $rewardData = self::responseData($reward);
        $basic = [];
        foreach (['login' => '每日登录', 'watch' => '每日观看'] as $key => $label) {
            $flag = $rewardData[$key] ?? null;
            $basic[] = self::component(
                $label,
                5,
                $flag === true ? 5 : null,
                is_bool($flag) ? ($flag ? 'confirmed' : 'pending') : 'unknown',
                $flag === true ? '经验已确认到账'
                    : ($flag === false ? '经验尚未确认' : '暂时无法读取任务状态')
            );
        }

        $coinExp = ($coin['code'] ?? -1) === 0
            ? self::nonNegativeInteger($coin['data'] ?? null, 50)
            : null;
        $basic[] = self::component('每日投币', 50, $coinExp,
            $coinExp === null ? 'unknown' : 'confirmed',
            $coinExp === null ? '暂时无法读取投币经验' : '按今日实际到账经验统计');
        $vip = self::vipComponent($privilege);
        $components = [...$basic, $vip];
        $coinTarget = self::nonNegativeInteger($coinConfig['add_coin_num'] ?? null, 5);
        $coinTarget = $coinTarget !== null && $coinTarget >= 1 ? $coinTarget : null;

        return [
            'components' => $components,
            'basic_confirmed' => self::confirmedSum($basic),
            'basic_placeholder' => self::placeholder($basic),
            'vip_confirmed' => $vip['confirmed_exp'],
            'vip_placeholder' => $vip['status_text'],
            'vip_skipped' => $vip['state'] === 'skipped',
            'confirmed_total' => self::confirmedSum($components),
            'total_placeholder' => self::placeholder($components),
            'has_unconfirmed' => count(array_filter($components,
                static fn(array $item): bool => in_array($item['state'], ['pending', 'unknown'], true))) > 0,
            'coin_target_exp' => $coinTarget === null ? null : $coinTarget * 10,
            'coin_plan' => self::coinPlan($coinTarget, $coinJobState),
        ];
    }

    public static function levelInfo(array $navData): ?array
    {
        $level = is_array($navData['level_info'] ?? null) ? $navData['level_info'] : [];
        if ($level === []) {
            return null;
        }
        $currentLevel = self::nonNegativeInteger($level['current_level'] ?? null, 6);
        $currentExp = self::nonNegativeInteger($level['current_exp'] ?? null);
        $nextExp = self::nonNegativeInteger($level['next_exp'] ?? null);
        $money = $navData['money'] ?? null;
        $money = (is_int($money) || is_float($money) || is_string($money))
            && is_numeric($money) && is_finite((float)$money) && (float)$money >= 0
            ? (string)$money : null;

        return [
            'current_level' => $currentLevel,
            'next_level' => $currentLevel !== null && $currentLevel < 6 ? $currentLevel + 1 : null,
            'current_exp' => $currentExp,
            'remaining_exp' => $currentLevel !== null && $currentLevel < 6
                && $currentExp !== null && $nextExp !== null && $nextExp >= $currentExp
                ? $nextExp - $currentExp : null,
            'money' => $money,
        ];
    }

    private static function vipComponent(array $privilege): array
    {
        $data = self::responseData($privilege);
        $isVip = self::binaryFlag($data['is_vip'] ?? null);
        $vipStatus = self::binaryFlag($data['vip_status'] ?? null);
        if ($isVip !== true && $vipStatus !== true && ($isVip === false || $vipStatus === false)) {
            return self::component('大会员加成', 10, null, 'skipped', '当前账号非大会员，无此项经验');
        }

        $benefits = is_array($data['list'] ?? null) ? $data['list'] : [];
        foreach ($benefits as $benefit) {
            if (!is_array($benefit) || self::nonNegativeInteger($benefit['type'] ?? null) !== 9) {
                continue;
            }
            $state = self::nonNegativeInteger($benefit['state'] ?? null);
            if ($state === 1) {
                return self::component('大会员加成', 10, 10, 'confirmed', '今日经验已领取');
            }
            if (($isVip === true || $vipStatus === true) && in_array($state, [0, 2], true)) {
                return self::component('大会员加成', 10, null, 'pending',
                    $state === 2 ? '需先完成观看任务，经验尚未确认' : '今日经验尚未领取');
            }
            break;
        }

        return self::component('大会员加成', 10, null, 'unknown', '暂时无法确认大会员经验状态');
    }

    private static function component(string $label, int $limit, ?int $confirmed, string $state, string $detail): array
    {
        return [
            'label' => $label,
            'limit' => $limit,
            'confirmed_exp' => $confirmed,
            'state' => $state,
            'status_text' => match ($state) {
                'confirmed' => '已确认',
                'pending' => '待确认',
                'skipped' => '不适用',
                default => '暂不可用',
            },
            'detail' => $detail,
        ];
    }

    /** A partial sum counts confirmed components only; no evidence remains null. */
    private static function confirmedSum(array $components): ?int
    {
        $values = array_filter(array_column($components, 'confirmed_exp'),
            static fn(?int $value): bool => $value !== null);
        return $values === [] ? null : array_sum($values);
    }

    private static function coinPlan(?int $target, ?int $state): string
    {
        if ($state === null) {
            return '尚未创建投币任务。';
        }
        $status = $state === 1 ? '已开启' : ($state === 0 ? '已关闭' : '已暂停');
        if ($target === null) {
            return '投币任务' . $status . '，尚未配置有效的投币数量。';
        }
        if ($state !== 1) {
            return '投币任务' . $status . '，保留配置 ' . $target . ' 枚 / 日（'
                . ($target * 10) . ' 经验），不会自动投币。';
        }
        return '投币任务已开启：配置 ' . $target . ' 枚 / 日，对应目标 ' . ($target * 10)
            . ' 经验；以实际到账为准。';
    }

    private static function placeholder(array $components): string
    {
        foreach ($components as $component) {
            if ($component['state'] === 'pending') {
                return '待确认';
            }
        }
        return '暂不可用';
    }

    private static function responseData(array $response): array
    {
        return ($response['code'] ?? -1) === 0 && is_array($response['data'] ?? null)
            ? $response['data'] : [];
    }

    private static function binaryFlag(mixed $value): ?bool
    {
        return match ($value) {
            true, 1, '1' => true,
            false, 0, '0' => false,
            default => null,
        };
    }

    private static function nonNegativeInteger(mixed $value, int $maximum = PHP_INT_MAX): ?int
    {
        if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value)) {
            return null;
        }
        $number = (float)$value;
        if (!is_finite($number) || $number < 0 || floor($number) !== $number
            || $number >= PHP_INT_MAX || $number > $maximum) {
            return null;
        }
        return (int)$number;
    }
}
