<?php

declare(strict_types=1);

namespace app\service;

final class ApplicationVersion
{
    public const FALLBACK = '0.0.0';

    public static function current(): string
    {
        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'VERSION';
        if (!is_file($path)) {
            return self::FALLBACK;
        }

        return self::normalize((string)file_get_contents($path)) ?? self::FALLBACK;
    }

    public static function normalize(string $version): ?string
    {
        $version = trim($version);
        if ($version !== '' && ($version[0] === 'v' || $version[0] === 'V')) {
            $version = substr($version, 1);
        }

        if (!preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $version)) {
            return null;
        }

        return $version;
    }
}
