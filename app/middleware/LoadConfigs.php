<?php

namespace app\middleware;

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
        if (!is_file(root_path() . 'config' . DIRECTORY_SEPARATOR . 'Db.php')) {
            return redirect('/install');
        }

        $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8000';
        if (!preg_match('/^[a-z0-9.:\-\[\]]+$/i', $host)) {
            $host = '127.0.0.1:8000';
        }

        $site = Db::name('weblist')
            ->where('domain', '=', $host)
            ->whereOr('domain2', '=', $host)
            ->find();
        if (!$site) {
            $site = Db::name('weblist')->where('web_id', '=', 1)->find();
        }
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

        $settings = [];
        foreach (Db::table((string)$site['prefix'] . 'configs')->select() as $row) {
            $settings[(string)$row['k']] = $row['v'];
        }
        Config::set($settings, 'sys');
        Config::set($site, 'web');

        return $next($request);
    }
}
