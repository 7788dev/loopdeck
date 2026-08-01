<?php

declare(strict_types=1);

namespace app\service;

use RuntimeException;
use think\facade\Config;
use think\facade\Db;
use Throwable;

final class UserNotificationSettings
{
    private const TABLE = 'user_notifications';

    private static bool $schemaReady = false;

    public function barkToken(int $uid, int $webId): string
    {
        if ($uid <= 0 || $webId <= 0) {
            return '';
        }

        $this->ensureSchema();
        return trim((string)Db::name(self::TABLE)
            ->where('uid', $uid)
            ->where('web_id', $webId)
            ->value('bark_token'));
    }

    public function saveBarkToken(int $uid, int $webId, string $token): bool
    {
        if ($uid <= 0 || $webId <= 0) {
            throw new RuntimeException('用户信息无效');
        }

        $token = BarkClient::normalizeToken($token);
        if ($token === null) {
            throw new RuntimeException('Bark Token 只能包含字母、数字、下划线和短横线');
        }

        $this->ensureSchema();
        if ($token === '') {
            Db::name(self::TABLE)->where('uid', $uid)->where('web_id', $webId)->delete();
            return true;
        }

        $table = $this->tableName();
        Db::execute(
            "INSERT INTO `{$table}` (`uid`,`web_id`,`bark_token`,`updated_at`) "
            . "VALUES (:uid,:web_id,:bark_token,NOW()) "
            . "ON DUPLICATE KEY UPDATE `web_id` = :updated_web_id, "
            . "`bark_token` = :updated_bark_token, `updated_at` = NOW()",
            [
                'uid' => $uid,
                'web_id' => $webId,
                'bark_token' => $token,
                'updated_web_id' => $webId,
                'updated_bark_token' => $token,
            ]
        );
        return true;
    }

    public function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }

        $table = $this->tableName();
        try {
            Db::execute(
                "CREATE TABLE IF NOT EXISTS `{$table}` ("
                . "`uid` int(11) NOT NULL,"
                . "`web_id` int(11) NOT NULL,"
                . "`bark_token` varchar(255) NOT NULL DEFAULT '',"
                . "`updated_at` datetime NOT NULL,"
                . "PRIMARY KEY (`uid`),"
                . "KEY `idx_user_notifications_site` (`web_id`,`uid`)"
                . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            self::$schemaReady = true;
        } catch (Throwable $exception) {
            throw new RuntimeException('无法初始化用户推送设置', 0, $exception);
        }
    }

    private function tableName(): string
    {
        $prefix = (string)Config::get('database.connections.mysql.prefix', '');
        if (preg_match('/\A[A-Za-z0-9_]*\z/', $prefix) !== 1) {
            throw new RuntimeException('数据库表前缀无效');
        }
        return $prefix . self::TABLE;
    }
}
