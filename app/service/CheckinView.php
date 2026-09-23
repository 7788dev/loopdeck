<?php

declare(strict_types=1);

namespace app\service;

/**
 * Formatting shared by the check-in platform templates. Templates render
 * cached snapshots only, so every helper accepts incomplete input.
 */
final class CheckinView
{
    public static function bytes(mixed $bytes): string
    {
        if (!is_numeric($bytes) || (float)$bytes < 0) {
            return '暂不可用';
        }
        $bytes = (float)$bytes;
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $index = 0;
        while ($bytes >= 1024 && $index < 5) {
            $bytes /= 1024;
            $index++;
        }
        return rtrim(rtrim(number_format($bytes, 2, '.', ''), '0'), '.') . ' ' . $units[$index];
    }

    /**
     * Usage bar data, or null when the platform did not report both figures.
     *
     * @return array{total:string,used:string,free:string,percent:int,level:string}|null
     */
    public static function capacity(array $stats, string $totalKey = 'capacity_total', string $usedKey = 'capacity_used'): ?array
    {
        $total = $stats[$totalKey] ?? null;
        $used = $stats[$usedKey] ?? null;
        if (!is_int($total) || !is_int($used) || $total <= 0) {
            return null;
        }
        $percent = self::percent($used, $total);
        return ['total' => self::bytes($total), 'used' => self::bytes($used), 'free' => self::bytes(max(0, $total - $used)),
            'percent' => $percent, 'level' => $percent >= 90 ? 'danger' : ($percent >= 75 ? 'warning' : 'success')];
    }

    public static function percent(mixed $value, mixed $total): int
    {
        if (!is_numeric($value) || !is_numeric($total) || (float)$total <= 0) {
            return 0;
        }
        return (int)max(0, min(100, round((float)$value / (float)$total * 100)));
    }

    /** "m-d H:i" for a unix time, or the fallback text. */
    public static function time(mixed $timestamp, string $fallback = '尚未执行'): string
    {
        return is_numeric($timestamp) && (int)$timestamp > 0 ? date('m-d H:i', (int)$timestamp) : $fallback;
    }
}
