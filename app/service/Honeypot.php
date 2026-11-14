<?php

declare(strict_types=1);

namespace app\service;

/** Fixed decoys only: safe to require before Composer and application startup. */
final class Honeypot
{
    public const RESPONSE_BYTES = 8192;
    private const REMARK = '恭喜，你成功导出了空气';
    private const DATABASE = 'loopdeck_archive';
    private const CREATED_AT = '2026-10-31 02:10:00';
    private const TABLES = [
        'cloud_users' => [
            ['uid' => 1042, 'username' => 'cedar', 'nickname' => '青杉', 'state' => 1, 'quota' => 3],
            ['uid' => 1043, 'username' => 'island', 'nickname' => '远岛', 'state' => 1, 'quota' => 2],
        ],
        'cloud_accounts' => [
            ['id' => 2001, 'uid' => 1042, 'platform' => 'netease', 'nickname' => '青杉的歌单', 'state' => 1],
            ['id' => 2002, 'uid' => 1043, 'platform' => 'bilibili', 'nickname' => '远岛影像', 'state' => 1],
        ],
        'cloud_jobs' => [
            ['id' => 3001, 'uid' => 1042, 'account_id' => 2001, 'execute_name' => 'sign', 'state' => 1, 'last_execute' => self::CREATED_AT],
            ['id' => 3002, 'uid' => 1043, 'account_id' => 2002, 'execute_name' => 'login', 'state' => 1, 'last_execute' => self::CREATED_AT],
        ],
    ];

    public static function enabled(): bool
    {
        return !in_array(strtolower(trim((string)getenv('HONEYPOT_ENABLED'))), ['0', 'false', 'off', 'no'], true);
    }

    public static function isSqlExtraction(string $blockedValue, string $source): bool
    {
        // Classification happens only AFTER the original firewall rejects the
        // request. A truncated/unrecognized payload keeps its original 403.
        if (!in_array($source, ['GET', 'POST'], true)) {
            return false;
        }
        $prefix = substr($blockedValue, 0, 4096);
        $prefix = preg_replace('~/\*.*?\*/~s', ' ', $prefix) ?? '';
        return preg_match('/\bunion\s+(?:(?:all|distinct)\s+)?select\b/i', $prefix) === 1;
    }

    public static function respond(string $kind, string $route): never
    {
        $status = 403;
        $contentType = 'text/plain; charset=utf-8';
        $body = "Forbidden\n";
        $allow = false;
        try {
            if (!self::enabled()) {
                $status = 404;
                $body = "Not Found\n";
            } elseif (in_array($kind, ['decoy_config', 'decoy_backup', 'sql_result'], true)) {
                self::record($kind, $route);
                if ($kind !== 'sql_result' && !in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
                    $status = 405;
                    $body = "Method Not Allowed\n";
                    $allow = true;
                } else {
                    [$contentType, $body] = self::payload($kind);
                    $status = 200;
                }
            }
            if (strlen($body) > self::RESPONSE_BYTES) {
                throw new \LengthException('Decoy response exceeded its budget');
            }
        } catch (\Throwable) {
            $status = 403;
            $contentType = 'text/plain; charset=utf-8';
            $body = "Forbidden\n";
        }
        http_response_code($status);
        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . strlen($body));
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; base-uri 'none'; frame-ancestors 'none'");
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        if ($allow) {
            header('Allow: GET, HEAD');
        }
        if ($kind === 'decoy_backup' && $status === 200) {
            header('Content-Disposition: attachment; filename="loopdeck-backup.sql"');
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') {
            echo $body;
        }
        exit;
    }

    public static function record(string $type, string $route): void
    {
        try {
            require_once __DIR__ . '/HoneypotLog.php';
            // These helpers declare functions only; they do not boot ThinkPHP.
            require_once dirname(__DIR__) . '/common.php';
            (new HoneypotLog())->record($type, self::entry($route), (string)($_SERVER['REQUEST_METHOD'] ?? ''), real_ip());
        } catch (\Throwable) {
            // Logging must never reopen a blocked business path or flood the
            // application's regular log if storage is unavailable.
        }
    }

    public static function entry(string $route): string
    {
        if (in_array($route, ['/api/debug/config', '/api/backup/export'], true)) {
            return $route;
        }
        // Only fixed application/controller names are retained. Action/path
        // arguments and unknown routes can contain credentials and are omitted.
        $parts = explode('/', trim(explode('?', substr($route, 0, 512), 2)[0], '/'), 4);
        $controllers = ['index' => ['index', 'ajax', 'console', 'login', 'loginajax', 'netease', 'bilibili', 'anyrouter'],
            'admin' => ['system', 'ajax'], 'cron' => ['task', 'notifications'], 'install' => ['index']];
        $module = isset($controllers[$parts[0] ?? '']) ? array_shift($parts) : 'index';
        $controller = strtolower(str_replace(['-', '_'], '', $parts[0] ?? ''));
        return '/' . $module . '/' . (in_array($controller, $controllers[$module], true) ? $controller : 'other');
    }

    public static function payload(string $kind): array
    {
        $counts = array_map('count', self::TABLES);
        if ($kind === 'decoy_backup') {
            $body = '-- LoopDeck database backup' . "\n-- Database: " . self::DATABASE . "\n-- Created: " . self::CREATED_AT
                . "\n-- Rows: " . array_sum($counts) . "\nSET NAMES utf8mb4;\n\n";
            foreach (self::TABLES as $table => $rows) {
                $columns = [];
                foreach ($rows[0] as $column => $value) {
                    $columns[] = '`' . $column . '` ' . (is_int($value) ? 'int NOT NULL' : 'varchar(255) NOT NULL');
                }
                $body .= 'CREATE TABLE `' . $table . '` (' . implode(', ', $columns) . ") ENGINE=InnoDB;\n";
                foreach ($rows as $row) {
                    $values = array_map(static fn($value): string => is_int($value) ? (string)$value
                        : "'" . str_replace("'", "''", $value) . "'", $row);
                    $body .= 'INSERT INTO `' . $table . '` VALUES (' . implode(', ', $values) . ");\n";
                }
                $body .= "\n";
            }
            return ['text/plain; charset=utf-8', $body . '-- ' . self::REMARK . "\n"];
        }
        $data = $kind === 'decoy_config' ? [
            'app' => 'LoopDeck', 'debug' => true,
            'database' => ['driver' => 'mysql', 'host' => '192.0.2.23', 'port' => 3306,
                'database' => self::DATABASE, 'username' => 'archive_reader', 'password' => 'Ldk_archive_6c891f2e', 'prefix' => 'cloud_'],
            'backup' => ['url' => '/api/backup/export', 'created_at' => self::CREATED_AT, 'rows' => array_sum($counts)],
            'tables' => $counts,
        ] : ['database' => self::DATABASE, 'created_at' => self::CREATED_AT, 'rows' => array_sum($counts), 'tables' => self::TABLES];
        $data['remark'] = self::REMARK;
        return ['application/json; charset=utf-8', json_encode(['code' => 1, 'message' => 'ok', 'data' => $data],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n"];
    }
}
