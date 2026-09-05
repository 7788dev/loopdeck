<?php

declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;

final class AutomaticSchedule
{
    private const TIME_PATTERN = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';

    public static function normalize(?string $timing): ?string
    {
        $timing = trim((string)$timing);
        return preg_match(self::TIME_PATTERN, $timing) === 1 ? $timing : null;
    }

    public static function isConfigured(?string $timing): bool
    {
        return self::normalize($timing) !== null;
    }

    public static function nextExecution(
        string $type,
        string $accountKey,
        ?string $timing,
        ?int $now = null
    ): ?int {
        $timing = self::normalize($timing);
        if ($timing === null) {
            return null;
        }

        $now ??= time();
        if ($type === 'netease') {
            return NeteaseSchedule::nextTimedExecution(
                $timing,
                'netease:' . $accountKey,
                $now
            );
        }

        $nowDate = (new DateTimeImmutable('now'))->setTimestamp($now);
        [$hour, $minute] = array_map('intval', explode(':', $timing));
        $next = $nowDate->modify('+1 day')->setTime($hour, $minute);
        return $next->getTimestamp() + self::dailyJitter($type . ':' . $accountKey, $next->format('Y-m-d'));
    }

    /** Stable within one date, different across dates and accounts. */
    public static function dailyJitter(string $identity, string $date): int
    {
        $configured = getenv('SCHEDULER_JITTER_SECONDS');
        $maximum = $configured !== false && preg_match('/\A\d+\z/', $configured) === 1
            ? max(0, min(900, (int)$configured)) : 120;
        return $maximum === 0 ? 0 : (int)sprintf('%u', crc32($identity . '|' . $date)) % ($maximum + 1);
    }
}
