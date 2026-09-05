<?php

declare(strict_types=1);

namespace app\service;

use app\admin\model\Weblist;
use think\facade\Db;

class NotificationSite
{
    private array $sites = [];

    public function get(int $webId): array
    {
        if (isset($this->sites[$webId])) {
            return $this->sites[$webId];
        }
        $row = Weblist::where('web_id', $webId)->field('web_id,prefix,webname,domain')->find();
        $table = $row ? Weblist::configTableName((string)$row['prefix']) : null;
        $config = $table !== null ? Db::table($table)->column('v', 'k') : [];
        $domain = trim((string)($row['domain'] ?? ''));
        if ($domain !== '' && !preg_match('#\Ahttps?://#i', $domain)) {
            $domain = 'https://' . $domain;
        }
        return $this->sites[$webId] = [
            'exists' => $row !== null && $row !== false,
            'name' => trim((string)($row['webname'] ?? '')) ?: 'LoopDeck',
            'url' => self::safeUrl($domain),
            'email_available' => self::emailAvailable($config), 'smtp' => $config,
        ];
    }

    public static function emailAvailable(array $config): bool
    {
        return (int)($config['mail_enabled'] ?? $config['mail_invalid'] ?? 0) === 1 && self::validSmtp($config);
    }

    public static function validSmtp(array $config): bool
    {
        $host = trim((string)($config['mail_smtp'] ?? ''));
        $validHost = filter_var($host, FILTER_VALIDATE_IP) !== false
            || preg_match('/\A(?=.{1,253}\z)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\z/', $host) === 1;
        return $validHost && (int)($config['mail_port'] ?? 0) >= 1 && (int)$config['mail_port'] <= 65535
            && filter_var((string)($config['mail_name'] ?? ''), FILTER_VALIDATE_EMAIL) !== false
            && (string)($config['mail_pwd'] ?? '') !== '';
    }

    public static function safeUrl(string $url): string
    {
        return preg_match('#\Ahttps?://#i', $url) && filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
    }
}
