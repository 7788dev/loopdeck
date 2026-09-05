<?php

declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;

final class EpicSchedule
{
    public static function next(string $timing, ?int $now = null): ?int
    {
        if (AutomaticSchedule::normalize($timing) === null) {
            return null;
        }
        $now ??= time();
        $date = (new DateTimeImmutable())->setTimestamp($now);
        [$hour, $minute] = array_map('intval', explode(':', $timing));
        $days = (5 - (int)$date->format('N') + 7) % 7;
        $candidate = $date->modify('+' . $days . ' days')->setTime($hour, $minute);
        if ($candidate->getTimestamp() <= $now) {
            $candidate = $candidate->modify('+7 days');
        }
        return $candidate->getTimestamp();
    }
}
