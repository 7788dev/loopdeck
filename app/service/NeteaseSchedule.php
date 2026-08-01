<?php

declare(strict_types=1);

namespace app\service;

final class NeteaseSchedule
{
    public const MINIMUM_JITTER_SECONDS = 180;
    public const MAXIMUM_JITTER_SECONDS = 900;

    public static function nextTimedExecution(string $timing, string $accountIdentity, ?int $now = null): ?int
    {
        $timing = trim($timing);
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $timing)) {
            return null;
        }

        $now ??= time();
        $tomorrow = strtotime('+1 day', $now);
        if ($tomorrow === false) {
            return null;
        }

        $date = date('Y-m-d', $tomorrow);
        $scheduled = strtotime($date . ' ' . $timing);
        if ($scheduled === false) {
            return null;
        }

        return $scheduled + self::dailyOffset($accountIdentity, $date);
    }

    public static function dailyOffset(string $accountIdentity, string $date): int
    {
        $range = self::MAXIMUM_JITTER_SECONDS - self::MINIMUM_JITTER_SECONDS + 1;
        $unsigned = (int)sprintf('%u', crc32($accountIdentity . '|' . $date));

        return self::MINIMUM_JITTER_SECONDS + ($unsigned % $range);
    }
}
