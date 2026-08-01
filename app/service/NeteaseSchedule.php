<?php

declare(strict_types=1);

namespace app\service;

final class NeteaseSchedule
{
    public const MINIMUM_JITTER_SECONDS = 180;
    public const MAXIMUM_JITTER_SECONDS = 900;

    public static function nextTimedExecution(string $timing, string $accountIdentity, ?int $now = null): ?int
    {
        $now ??= time();
        $tomorrow = strtotime('+1 day', $now);
        if ($tomorrow === false) {
            return null;
        }

        $date = date('Y-m-d', $tomorrow);
        $scheduled = self::baseExecution($timing, $date);
        if ($scheduled === null) {
            return null;
        }

        return $scheduled + self::dailyOffset($accountIdentity, $date);
    }

    public static function deferredLegacyExecution(
        string $timing,
        string $accountIdentity,
        int $scheduledAt,
        ?int $now = null
    ): ?int {
        if ($scheduledAt <= 0) {
            return null;
        }

        $date = date('Y-m-d', $scheduledAt);
        $base = self::baseExecution($timing, $date);
        if ($base === null) {
            return null;
        }

        $target = $base + self::dailyOffset($accountIdentity, $date);
        $now ??= time();
        if ($scheduledAt < $base || $scheduledAt >= $target || $now >= $target) {
            return null;
        }

        return $target;
    }

    public static function dailyOffset(string $accountIdentity, string $date): int
    {
        $range = self::MAXIMUM_JITTER_SECONDS - self::MINIMUM_JITTER_SECONDS + 1;
        $unsigned = (int)sprintf('%u', crc32($accountIdentity . '|' . $date));

        return self::MINIMUM_JITTER_SECONDS + ($unsigned % $range);
    }

    private static function baseExecution(string $timing, string $date): ?int
    {
        $timing = trim($timing);
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $timing)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        $scheduled = strtotime($date . ' ' . $timing);

        return $scheduled === false ? null : $scheduled;
    }
}
