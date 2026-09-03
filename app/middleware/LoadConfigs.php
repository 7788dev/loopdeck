<?php

namespace app\middleware;

use think\facade\Cache;
use think\facade\Config;
use think\facade\Db;

class LoadConfigs
{
    /**
     * Load the local site configuration for each request.
     *
     * The original middleware used a working-directory-relative install check
     * and contained site expiry/blocking logic. A local installation now uses
     * the framework root path and falls back to the primary site record when
     * the host name changes (for example between localhost and 127.0.0.1).
     */
    public function handle($request, \Closure $next)
    {
        if (in_array($request->pathinfo(), ['healthcheck', 'version'], true)) {
            return $next($request);
        }

        if (!is_file(root_path() . 'config' . DIRECTORY_SEPARATOR . 'Db.php')) {
            return redirect('/install');
        }

        $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8000';
        if (!preg_match('/^[a-z0-9.:\-\[\]]+$/i', $host)) {
            $host = '127.0.0.1:8000';
        }

        // weblist + configs 原来是每请求 2-3 条 SQL 且无缓存。两条记录合并为
        // 一个缓存条目（60 秒），后台保存站点信息/配置时调用
        // LoadConfigs::invalidate() 失效。键按 host/ web_id 分别缓存，避免
        // domain/domain2 两个 host 名互相污染。
        $site = $this->loadSite($host);
        if (!$site) {
            throw new \RuntimeException('缺少本地站点配置，请重新执行安装程序。');
        }

        if (!defined('WEB_ID')) {
            define('WEB_ID', (int)$site['web_id']);
        }
        if (!defined('PREFIX')) {
            define('PREFIX', (string)$site['prefix']);
        }
        if (!defined('WEB_KEY')) {
            define('WEB_KEY', hash('sha256', (string)$site['web_key']));
        }
        if (!defined('RUN_KEY')) {
            $database = (array)Config::get('database.connections.mysql');
            define(
                'RUN_KEY',
                hash(
                    'sha256',
                    (string)($database['username'] ?? '')
                    . "\0"
                    . (string)($database['password'] ?? '')
                    . "\0"
                    . (string)$site['web_key']
                )
            );
        }

        $settings = $this->loadSettings((int)$site['web_id'], (string)$site['prefix']);
        Config::set($settings, 'sys');
        Config::set($site, 'web');

        return $next($request);
    }

    /** @return array<string,mixed>|null */
    private function loadSite(string $host): ?array
    {
        $cached = Cache::get('site_row_' . md5($host));
        if (is_array($cached)) {
            // 缓存可能早于一次站点删除/改名，行还在 weblist 表里才可用
            return $cached;
        }

        $site = Db::name('weblist')
            ->where('domain', '=', $host)
            ->whereOr('domain2', '=', $host)
            ->find();
        if (!$site) {
            $site = Db::name('weblist')->where('web_id', '=', 1)->find();
        }
        if ($site) {
            Cache::tag('site_rows')->set('site_row_' . md5($host), $site, 60);
        }
        return $site ?: null;
    }

    /** @return array<string,mixed> */
    private function loadSettings(int $webId, string $prefix): array
    {
        $cached = Cache::get('site_settings_' . $webId);
        if (is_array($cached)) {
            return $cached;
        }

        $settings = [];
        foreach (Db::table($prefix . 'configs')->select() as $row) {
            $settings[(string)$row['k']] = $row['v'];
        }
        Cache::tag('site_settings')->set('site_settings_' . $webId, $settings, 60);
        return $settings;
    }

    /**
     * Drop the cached site rows and settings so the next request re-reads
     * them. Called after an administrator edits site info or config values.
     */
    public static function invalidate(): void
    {
        Cache::tag('site_rows')->clear();
        Cache::tag('site_settings')->clear();
    }
}
