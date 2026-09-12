<?php

declare(strict_types=1);

namespace app\install\service;

final class DatabaseConfig
{
    private const ENV_FIELDS = [
        'install-db-hostname' => 'MYSQL_HOST',
        'install-db-hostport' => 'MYSQL_PORT',
        'install-db-database' => 'MYSQL_DATABASE',
        'install-db-username' => 'MYSQL_USER',
        'install-db-password' => 'MYSQL_PASSWORD',
    ];

    /** @return array<string, string>|null */
    public static function environmentInput(): ?array
    {
        // Compose explicitly selects automatic configuration with MYSQL_HOST.
        if (getenv('MYSQL_HOST') === false) {
            return null;
        }

        $input = [];
        foreach (self::ENV_FIELDS as $field => $variable) {
            $value = getenv($variable);
            $input[$field] = $value === false ? ($variable === 'MYSQL_PORT' ? '3306' : '') : $value;
        }

        return $input;
    }

    public static function resolveInput(array $input): array
    {
        // Never mix browser-supplied connection fields with container credentials.
        return array_replace($input, self::environmentInput() ?? []);
    }
}
