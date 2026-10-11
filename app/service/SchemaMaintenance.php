<?php

declare(strict_types=1);

namespace app\service;

use think\facade\Config;
use think\facade\Db;
use Throwable;

/**
 * Structural maintenance for databases installed by an older release.
 *
 * install.sql only runs on first install, so indexes and engines added in
 * later releases never reach deployments that only auto-update images. The
 * worst gap is `udx_users_username`: `Users::reg` relies on the unique key
 * as its concurrent-registration backstop, and without it duplicate
 * usernames are silently created. `cloud_notice` kept its legacy MyISAM/utf8
 * definition for a long time, so notices containing emoji fail on those
 * installs.
 *
 * `plan()` is a pure function so the offline suite can pin the exact DDL
 * without a MySQL server; `runIfDue()` executes it behind the same daily
 * stamp gate the other scheduler maintenance sweeps use. Everything is
 * best-effort: a database that cannot answer metadata queries (for example
 * the offline SQLite fixture) simply skips the sweep.
 */
final class SchemaMaintenance
{
    private const STAMP_FILE = 'schema-maintenance.stamp';

    /**
     * Definitions every installation must have. Column sets mirror
     * app/install/install.sql exactly.
     *
     * @return array<int, array{table: string, index: string, unique: bool, columns: string}>
     */
    public static function requiredIndexes(): array
    {
        return [
            ['table' => 'users', 'index' => 'udx_users_username', 'unique' => true, 'columns' => '(`username`)'],
            ['table' => 'accounts', 'index' => 'udx_accounts_identity', 'unique' => true, 'columns' => '(`uid`,`type`(32),`user_id`(128))'],
            ['table' => 'jobs', 'index' => 'udx_jobs_identity', 'unique' => true, 'columns' => '(`uid`,`type`(32),`user_id`(128),`do`(64))'],
            ['table' => 'jobs', 'index' => 'idx_jobs_due', 'unique' => false, 'columns' => '(`type`(32),`state`,`nextExecute`,`id`)'],
            ['table' => 'task_logs', 'index' => 'idx_task_logs_retention', 'unique' => false, 'columns' => '(`addtime`,`id`)'],
        ];
    }

    /**
     * Build the DDL that brings an installation up to the current structure.
     *
     * @param array<string, array<int, string>> $existingIndexes index names per short table name
     * @param array<string, array{engine: string, collation: string}> $tableStates per short table name
     * @return array<int, string>
     */
    public static function plan(array $existingIndexes, array $tableStates): array
    {
        $statements = [];
        foreach (self::requiredIndexes() as $required) {
            $table = $required['table'];
            $known = $existingIndexes[$table] ?? null;
            if ($known === null || in_array($required['index'], $known, true)) {
                continue;
            }
            $keyword = $required['unique'] ? 'UNIQUE KEY' : 'KEY';
            $statements[] = sprintf(
                'ALTER TABLE `%s` ADD %s `%s` %s',
                self::tableName($table),
                $keyword,
                $required['index'],
                $required['columns']
            );
        }

        $notice = $tableStates['notice'] ?? null;
        if (is_array($notice)
            && (strtoupper($notice['engine']) !== 'INNODB'
                || !str_starts_with(strtolower($notice['collation']), 'utf8mb4'))) {
            $statements[] = sprintf(
                'ALTER TABLE `%s` ENGINE=InnoDB CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                self::tableName('notice')
            );
        }

        return $statements;
    }

    /**
     * Run the sweep at most once a day from the unified scheduler. Returns
     * without touching the stamp on any failure so the next run retries.
     */
    public static function runIfDue(): void
    {
        $stamp = runtime_path() . self::STAMP_FILE;
        clearstatcache(true, $stamp);
        if (is_file($stamp) && (int)filemtime($stamp) > time() - 86400) {
            return;
        }

        $lock = @fopen(runtime_path() . self::STAMP_FILE . '.lock', 'c+');
        if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            return;
        }

        try {
            clearstatcache(true, $stamp);
            if (is_file($stamp) && (int)filemtime($stamp) > time() - 86400) {
                return;
            }

            $plan = self::plan(self::existingIndexes(), self::tableStates());
            if ($plan === []) {
                @touch($stamp);
                return;
            }

            foreach ($plan as $statement) {
                Db::execute($statement);
            }
            @touch($stamp);
        } catch (Throwable $exception) {
            // Maintenance must never break scheduling; a failed sweep retries
            // on the next scheduler run because the stamp was not written.
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Existing index names per short table name. A table the database cannot
     * describe maps to null and its indexes are treated as unknown (skipped)
     * so a metadata failure never produces a wrong ALTER.
     *
     * @return array<string, array<int, string>|null>
     */
    private static function existingIndexes(): array
    {
        $state = [];
        foreach (self::requiredIndexes() as $required) {
            $table = $required['table'];
            if (array_key_exists($table, $state)) {
                continue;
            }
            try {
                $rows = Db::query(sprintf('SHOW INDEX FROM `%s`', self::tableName($table)));
                $state[$table] = array_values(array_unique(array_column(
                    is_array($rows) ? $rows : [],
                    'Key_name'
                )));
            } catch (Throwable $exception) {
                $state[$table] = null;
            }
        }

        return $state;
    }

    /**
     * @return array<string, array{engine: string, collation: string}|null>
     */
    private static function tableStates(): array
    {
        try {
            $rows = Db::query(sprintf(
                "SHOW TABLE STATUS LIKE '%s'",
                self::tableName('notice')
            ));
            $row = is_array($rows) ? ($rows[0] ?? null) : null;
            if (!is_array($row)) {
                return ['notice' => null];
            }

            return ['notice' => [
                'engine' => (string)($row['Engine'] ?? ''),
                'collation' => (string)($row['Collation'] ?? ''),
            ]];
        } catch (Throwable $exception) {
            return ['notice' => null];
        }
    }

    private static function tableName(string $name): string
    {
        $prefix = (string)Config::get('database.connections.mysql.prefix', '');
        if (preg_match('/\A[A-Za-z0-9_]*\z/', $prefix) !== 1) {
            throw new \RuntimeException('数据库表前缀无效');
        }

        return $prefix . $name;
    }
}
