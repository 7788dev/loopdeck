<?php

declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/** Serializes token rotation and side effects across web and CLI processes. */
final class AccountMutex
{
    public static function run(string $type, string $identity, callable $operation): mixed
    {
        $connection = Db::connect();
        $name = 'loopdeck-account-' . substr(hash('sha256', $type . "\0" . $identity), 0, 40);
        $result = $connection->query('SELECT GET_LOCK(:mutex, 0) AS acquired', ['mutex' => $name]);
        if ((int)($result[0]['acquired'] ?? 0) !== 1) {
            throw new PlatformException('该账号正在处理其他任务，稍后重试', false, 60);
        }
        try {
            return $operation();
        } finally {
            $connection->query('SELECT RELEASE_LOCK(:mutex)', ['mutex' => $name]);
        }
    }
}
