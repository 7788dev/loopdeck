<?php

namespace app\middleware;

use think\facade\Cache;
use think\facade\Config;
use think\facade\Db;

class LoadConfigs
{
    /**
     * Load the single installation's configuration for each request.
     */
    public function handle($request, \Closure $next)
    {
        if (in_array($request->pathinfo(), ['healthcheck', 'version'], true)) {
            return $next($request);
        }

        if (!is_file(root_path() . 'config' . DIRECTORY_SEPARATOR . 'Db.php')) {
            return redirect('/install');
        }

        // Keep the primary record and existing ownership columns compatible
        // with installed databases; the request host never selects a site.
        $site = $this->loadSite();
        if (!$site) {
            throw new \RuntimeException('缺少本地站点配置，请重新执行安装程序。');
        }

        if (!defined('WEB_ID')) {
            define('WEB_ID', 1);
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

        $settings = $this->loadSettings();
        Config::set($settings, 'sys');
        Config::set($site, 'web');

        return $next($request);
    }

    /** @return array<string,mixed>|null */
    private function loadSite(): ?array
    {
        $cached = Cache::get('site_row_primary');
        if (is_array($cached)) {
            return $cached;
        }

        $site = Db::name('weblist')->where('web_id', '=', 1)->find();
        if ($site) {
            Cache::tag('site_rows')->set('site_row_primary', $site, 60);
        }
        return $site ?: null;
    }

    /** @return array<string,mixed> */
    private function loadSettings(): array
    {
        $cached = Cache::get('site_settings_primary');
        if (is_array($cached)) {
            return $cached;
        }

        $settings = [];
        foreach (Db::name('configs')->select() as $row) {
            $settings[(string)$row['k']] = $row['v'];
        }
        Cache::tag('site_settings')->set('site_settings_primary', $settings, 60);
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
