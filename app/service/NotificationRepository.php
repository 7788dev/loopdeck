<?php

declare(strict_types=1);

namespace app\service;

use RuntimeException;
use think\facade\Cache;
use think\facade\Config;
use think\facade\Db;
use Throwable;

/** Database boundary shared by preferences, daily reports and the outbox. */
class NotificationRepository
{
    private bool $ready = false;

    public function ensureSchema(): void
    {
        if ($this->ready) {
            return;
        }
        $identity = (string)Config::get('database.connections.mysql.hostname', '')
            . '|' . (string)Config::get('database.connections.mysql.database', '') . '|' . $this->table('notification_outbox');
        $cacheKey = 'notification_schema_v1_' . hash('sha256', $identity);
        try {
            if (Cache::get($cacheKey) === 1) {
                $this->ready = true;
                return;
            }
        } catch (Throwable $exception) {
        }
        foreach ($this->schemaStatements() as $statement) {
            Db::execute($statement);
        }
        $this->ready = true;
        try {
            Cache::set($cacheKey, 1, 86400);
        } catch (Throwable $exception) {
        }
    }

    public function schemaStatements(): array
    {
        $preferences = $this->table('user_notification_preferences');
        $outbox = $this->table('notification_outbox');
        $results = $this->table('notification_task_results');
        $suffix = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        return [
            "CREATE TABLE IF NOT EXISTS `{$preferences}` ("
                . '`uid` int unsigned NOT NULL, `web_id` int unsigned NOT NULL,'
                . '`settings` text NOT NULL, `enabled` tinyint unsigned NOT NULL DEFAULT 0,'
                . '`summary_enabled` tinyint unsigned NOT NULL DEFAULT 0, `next_summary_at` bigint unsigned NOT NULL DEFAULT 0,'
                . '`updated_at` bigint unsigned NOT NULL, PRIMARY KEY (`uid`,`web_id`),'
                . 'KEY `idx_notification_summary_due` (`enabled`,`summary_enabled`,`next_summary_at`,`uid`))' . $suffix,
            "CREATE TABLE IF NOT EXISTS `{$outbox}` ("
                . '`id` bigint unsigned NOT NULL AUTO_INCREMENT, `uid` int unsigned NOT NULL, `web_id` int unsigned NOT NULL,'
                . '`event_key` char(64) NOT NULL, `event_type` varchar(32) NOT NULL, `channel` varchar(16) NOT NULL,'
                . '`title` varchar(240) NOT NULL, `body` text NOT NULL, `url` varchar(512) NOT NULL DEFAULT \'\','
                . '`status` tinyint unsigned NOT NULL DEFAULT 0, `attempts` int unsigned NOT NULL DEFAULT 0,'
                . '`available_at` bigint unsigned NOT NULL, `created_at` bigint unsigned NOT NULL,'
                . '`finished_at` bigint unsigned NOT NULL DEFAULT 0, `last_error` varchar(255) NOT NULL DEFAULT \'\','
                . 'PRIMARY KEY (`id`), UNIQUE KEY `uniq_notification_event` (`uid`,`web_id`,`event_key`,`channel`),'
                . 'KEY `idx_notification_delivery_due` (`status`,`available_at`,`id`),'
                . 'KEY `idx_notification_user` (`uid`,`web_id`,`id`),'
                . 'KEY `idx_notification_finished` (`status`,`finished_at`))' . $suffix,
            "CREATE TABLE IF NOT EXISTS `{$results}` ("
                . '`id` bigint unsigned NOT NULL AUTO_INCREMENT, `uid` int unsigned NOT NULL, `web_id` int unsigned NOT NULL,'
                . '`run_date` date NOT NULL, `type` varchar(24) NOT NULL, `account_id` varchar(255) NOT NULL,'
                . '`task_key` varchar(80) NOT NULL, `task_name` varchar(200) NOT NULL, `status` varchar(16) NOT NULL,'
                . '`message` text NOT NULL, `updated_at` bigint unsigned NOT NULL, PRIMARY KEY (`id`),'
                . 'UNIQUE KEY `uniq_notification_task_day` (`uid`,`web_id`,`run_date`,`type`,`account_id`,`task_key`),'
                . 'KEY `idx_notification_result_retention` (`run_date`))' . $suffix,
        ];
    }

    public function preferences(int $uid, int $webId): ?array
    {
        $this->ensureSchema();
        $row = Db::name('user_notification_preferences')->where('uid', $uid)->where('web_id', $webId)->find();
        return is_array($row) ? $row : null;
    }

    public function legacyBarkToken(int $uid, int $webId): string
    {
        try {
            return trim((string)Db::name('user_notifications')->where('uid', $uid)->where('web_id', $webId)->value('bark_token'));
        } catch (Throwable $exception) {
            return '';
        }
    }

    public function legacyEpicSubscription(int $uid, int $webId): ?array
    {
        $row = Db::name('jobs')->where('uid', $uid)->where('zid', $webId)
            ->where('type', 'epic')->where('do', 'weeklyGameNotify')->where('state', 1)
            ->field('user_id,data')->find();
        return is_array($row) ? $row : null;
    }

    public function migratePreferences(int $uid, int $webId, array $settings): void
    {
        $this->ensureSchema();
        $table = $this->table('user_notification_preferences');
        // A concurrent first page load must not overwrite settings just saved by the user.
        Db::execute(
            "INSERT INTO `{$table}` (`uid`,`web_id`,`settings`,`enabled`,`summary_enabled`,`next_summary_at`,`updated_at`) "
            . 'VALUES (:uid,:web_id,:settings,:enabled,0,0,:updated_at) '
            . 'ON DUPLICATE KEY UPDATE `uid`=`uid`',
            ['uid' => $uid, 'web_id' => $webId, 'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'enabled' => (int)$settings['enabled'], 'updated_at' => time()]
        );
    }

    public function savePreferences(int $uid, int $webId, array $settings, int $nextSummaryAt): void
    {
        $this->ensureSchema();
        $table = $this->table('user_notification_preferences');
        Db::execute(
            "INSERT INTO `{$table}` (`uid`,`web_id`,`settings`,`enabled`,`summary_enabled`,`next_summary_at`,`updated_at`) "
            . 'VALUES (:uid,:web_id,:settings,:enabled,:summary_enabled,:next_summary_at,:updated_at) '
            . 'ON DUPLICATE KEY UPDATE `settings`=VALUES(`settings`), `enabled`=VALUES(`enabled`),'
            . '`summary_enabled`=VALUES(`summary_enabled`), `next_summary_at`=VALUES(`next_summary_at`), `updated_at`=VALUES(`updated_at`)',
            ['uid' => $uid, 'web_id' => $webId,
                'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'enabled' => (int)$settings['enabled'], 'summary_enabled' => (int)$settings['events']['daily_summary'],
                'next_summary_at' => $nextSummaryAt, 'updated_at' => time()]
        );
    }

    public function recordTask(array $row): void
    {
        $this->ensureSchema();
        $table = $this->table('notification_task_results');
        Db::execute(
            "INSERT INTO `{$table}` (`uid`,`web_id`,`run_date`,`type`,`account_id`,`task_key`,`task_name`,`status`,`message`,`updated_at`) "
            . 'VALUES (:uid,:web_id,:run_date,:type,:account_id,:task_key,:task_name,:status,:message,:updated_at) '
            . 'ON DUPLICATE KEY UPDATE `task_name`=VALUES(`task_name`), `status`=VALUES(`status`),'
            . '`message`=VALUES(`message`), `updated_at`=VALUES(`updated_at`)', $row
        );
    }

    public function dailyTasks(int $uid, int $webId, string $date): array
    {
        $this->ensureSchema();
        return Db::name('notification_task_results')->where('uid', $uid)->where('web_id', $webId)
            ->where('run_date', $date)->order(['type' => 'asc', 'account_id' => 'asc', 'task_key' => 'asc'])->select()->toArray();
    }

    public function enqueue(array $row): bool
    {
        $this->ensureSchema();
        $table = $this->table('notification_outbox');
        return Db::execute(
            "INSERT IGNORE INTO `{$table}` (`uid`,`web_id`,`event_key`,`event_type`,`channel`,`title`,`body`,`url`,`available_at`,`created_at`) "
            . 'VALUES (:uid,:web_id,:event_key,:event_type,:channel,:title,:body,:url,:available_at,:created_at)', $row
        ) > 0;
    }

    public function dueMessages(int $now, int $limit): array
    {
        $this->ensureSchema();
        return Db::name('notification_outbox')->where('status', 0)->where('available_at', '<=', $now)
            ->order(['available_at' => 'asc', 'id' => 'asc'])->limit($limit)->select()->toArray();
    }

    public function recentMessages(int $uid, int $webId): array
    {
        $this->ensureSchema();
        return Db::name('notification_outbox')->where('uid', $uid)->where('web_id', $webId)
            ->field('channel,title,status,attempts,last_error,created_at,available_at')
            ->order('id', 'desc')->limit(10)->select()->toArray();
    }

    public function claimMessage(int $id, int $expectedAt, int $now): bool
    {
        $table = $this->table('notification_outbox');
        return Db::execute(
            "UPDATE `{$table}` SET `available_at`=:lease, `attempts`=`attempts`+1 "
            . 'WHERE `id`=:id AND `status`=0 AND `available_at`=:expected AND `available_at`<=:now',
            ['id' => $id, 'expected' => $expectedAt, 'now' => $now, 'lease' => $now + 120]
        ) === 1;
    }

    public function finishMessage(int $id, array $changes): void
    {
        Db::name('notification_outbox')->where('id', $id)->update($changes);
    }

    public function dueSummaries(int $now, int $limit): array
    {
        $this->ensureSchema();
        return Db::name('user_notification_preferences')->where('enabled', 1)->where('summary_enabled', 1)
            ->where('next_summary_at', '>', 0)->where('next_summary_at', '<=', $now)
            ->order('next_summary_at')->limit($limit)->select()->toArray();
    }

    public function advanceSummary(int $uid, int $webId, int $expectedAt, int $nextAt): void
    {
        Db::name('user_notification_preferences')->where('uid', $uid)->where('web_id', $webId)
            ->where('next_summary_at', $expectedAt)->update(['next_summary_at' => $nextAt]);
    }

    public function prune(int $now): void
    {
        $cutoff = $now - 30 * 86400;
        Db::name('notification_outbox')->where('status', '>', 0)->where('finished_at', '<', $cutoff)->limit(500)->delete();
        Db::name('notification_task_results')->where('run_date', '<', date('Y-m-d', $cutoff))->limit(500)->delete();
    }

    private function table(string $name): string
    {
        $prefix = (string)Config::get('database.connections.mysql.prefix', '');
        if (preg_match('/\A[A-Za-z0-9_]*\z/', $prefix) !== 1) {
            throw new RuntimeException('数据库表前缀无效');
        }
        return $prefix . $name;
    }
}
