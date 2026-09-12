<?php

declare(strict_types=1);

namespace app\service;

/** Clock fixture loaded explicitly by notification tests only. */
final class NotificationTestClock
{
    public static ?int $now = null;
}

function time(): int
{
    return NotificationTestClock::$now ?? \time();
}

function date(string $format, ?int $timestamp = null): string
{
    return \date($format, $timestamp ?? NotificationTestClock::$now ?? \time());
}
