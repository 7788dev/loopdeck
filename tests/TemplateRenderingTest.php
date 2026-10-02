<?php

declare(strict_types=1);

require __DIR__ . '/FunctionalDatabaseBootstrap.php';

define('PJAX', false);
// Health probes and non-browser clients need not send User-Agent.
unset($_SERVER['HTTP_USER_AGENT']);
$_GET['token'] = str_repeat('a', 48);
$root = dirname(__DIR__);
$cachePath = sys_get_temp_dir() . '/loopdeck-render-' . bin2hex(random_bytes(6)) . '/';
mkdir($cachePath);
$app->config->set(['webname' => 'LoopDeck', 'title' => '测试站点', 'web_id' => 1, 'user_qq' => '10000', 'index_template' => 'default', 'login_template' => 'default'], 'web');
think\facade\Session::set('user.nickname', '测试用户');
think\facade\Session::set('user.qq', '10000');
$variables = [
    'redemption_presets' => app\service\RedemptionPlan::presets(),
    'redemption_max_days' => app\service\RedemptionPlan::MAX_DAYS,
    'redemption_max_accounts' => app\service\RedemptionPlan::MAX_ACCOUNTS,
    'redemption_max_batch' => app\service\RedemptionPlan::MAX_BATCH,
    'webTitle' => '测试', 'mail' => 'test@example.com', 'users' => [], 'list' => [], 'notice' => [], 'notices' => [],
    'timeCount' => 1, 'userCount' => 1, 'quota_used' => 0, 'user_count' => 1, 'account_count' => 0, 'job_count' => 0, 'execute_count' => 0,
    'accounts' => [], 'daily_limit' => 10, 'task_rows' => [], 'timing' => '', 'signature' => '', 'info_warning' => '', 'info_available' => false, 'level_info' => null,
    'data' => ['user_id' => '42', 'state' => 1, 'timing' => '', 'uid' => 1],
    'a_data' => ['user_id' => '42', 'mid' => '42', 'nickname' => '测试用户', 'avatar' => ''],
    'details' => ['level_now' => 1, 'level_next' => 2, 'listenSongs' => 0, 'listennum' => 0, 'loginnum' => 0],
    'epic_enabled' => false, 'epic_next' => '', 'notification' => app\service\UserNotificationSettings::redact(app\service\UserNotificationSettings::defaults()),
    'notification_error' => '', 'email_available' => false, 'deliveries' => [], 'email_ready' => false, 'email_enabled' => false, 'email_password_configured' => false,
    'notification_email_available' => false, 'notification_can_epic' => false, 'notification_deliveries' => [],
    'database_auto_configured' => true, 'name' => '测试收款人', 'type' => 'wechat', 'url' => 'wxp://fixture',
] + app\admin\model\Weblist::templateSettingsData([])
    + (new app\service\SystemUpdater(sys_get_temp_dir() . '/loopdeck-render-no-updater.json'))->status();
$count = 0;
$failures = [];
$renderedPages = [];
set_error_handler(static function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
});
try {
    foreach (['index', 'admin', 'install', 'index/ruoyi', 'admin/ruoyi'] as $module) {
        $viewPath = $root . '/app/' . str_replace('/ruoyi', '', $module) . '/view/'
            . (str_ends_with($module, '/ruoyi') ? 'ruoyi/' : '');
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewPath)) as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($viewPath)));
            if ($file->getExtension() !== 'html' || str_starts_with($relative, 'common/') || str_starts_with($relative, 'ruoyi/')
                || str_contains($relative, '/sport/') || str_ends_with($relative, '/head.html')
                || ($relative === 'index/index.html' && $module === 'index')) continue;
            $engine = new think\Template(['view_path' => $viewPath, 'cache_path' => $cachePath]);
            ob_start();
            try {
                $engine->fetch($file->getPathname(), $variables);
                $html = ob_get_contents();
                functionalCheck(strlen($html) > 100, 'Empty page');
                $renderedPages[$module . '/' . $relative] = $html;
                $count++;
            } catch (Throwable $error) {
                $failures[] = $module . '/' . $relative . ': ' . $error->getMessage();
            } finally {
                ob_end_clean();
            }
        }
    }
    $accountData = ['avatar' => '', 'nickname' => '测试用户', 'displayname' => '小黑盒测试用户', 'user_id' => '42',
        'name' => '测试收款码', 'alipay_url' => 'https://pay.example/a', 'qq_url' => 'https://pay.example/q', 'wechat_url' => 'wxp://fixture'];
    $account = ['id' => 1, 'uid' => 1, 'user_id' => '42', 'mid' => '42', 'nickname' => '测试用户', 'avatar' => '',
        'state' => 1, 'timing' => '', 'addtime' => '2026-09-28 08:00:00', 'data' => serialize($accountData)];
    $task = ['icon' => 'fa-check', 'name' => '签到', 'describe' => '每日签到', 'more' => true, 'execute_name' => 'sign',
        'config' => '{}', 'config_json' => '{"text":"quoted\'value"}', 'last_execute' => '', 'next_execute' => 0, 'job_state' => 0,
        'user_id' => '42', 'is_global' => false, 'offline' => false, 'offline_reason' => ''];
    foreach (['', 'ruoyi/'] as $themePath) {
    $populatedViewPath = $root . '/app/index/view/' . $themePath;
    foreach (['netease', 'bilibili', 'heybox', 'qrcode'] as $platform) {
        foreach ($platform === 'qrcode' ? ['list'] : ['list', 'info'] as $page) {
            $relative = 'console/' . $platform . '/' . $page . '.html';
            $engine = new think\Template(['view_path' => $populatedViewPath, 'cache_path' => $cachePath]);
            ob_start();
            try {
                $engine->fetch($populatedViewPath . $relative, array_replace($variables, ['list' => [$account], 'task_rows' => [$task]]));
                $html = ob_get_contents();
                $renderedPages['populated/' . $themePath . $relative] = $html;
                if ($page === 'list' && $platform !== 'qrcode') {
                    functionalCheck(str_contains($html, '/index/console/' . $platform . '/info/42'), 'Account management link is not usable');
                }
                $count++;
            } catch (Throwable $error) {
                $failures[] = 'populated/' . $themePath . $relative . ': ' . $error->getMessage();
            } finally {
                ob_end_clean();
            }
        }
    }
    $savedUser = think\facade\Session::get('user');
    think\facade\Session::set('user.quota', app\service\RedemptionPlan::UNLIMITED_ACCOUNTS);
    think\facade\Session::set('user.vip_start', date('Y-m-d'));
    think\facade\Session::set('user.vip_end', app\service\RedemptionPlan::PERMANENT_VIP_END);
    foreach (['console/index.html', 'console/shop/card.html'] as $relative) {
        $engine = new think\Template(['view_path' => $populatedViewPath, 'cache_path' => $cachePath]);
        ob_start();
        try {
            $engine->fetch($populatedViewPath . $relative, $variables);
            $html = ob_get_contents();
            functionalCheck(str_contains($html, '永久会员') && str_contains($html, '账号总数：不限'), 'Unlimited account display missing');
            functionalCheck(!str_contains($html, '9999-12-31') && !str_contains($html, '-1 个'), 'Storage sentinels leaked into the page');
            $renderedPages['permanent/' . $themePath . $relative] = $html;
            $count++;
        } finally {
            ob_end_clean();
        }
    }
    think\facade\Session::set('user', $savedUser);
    }
} finally {
    restore_error_handler();
    foreach (glob($cachePath . '*') as $file) unlink($file);
    rmdir($cachePath);
}
functionalCheck($failures === [], implode("\n", $failures));
// Optional local browser preview, containing only the inert fixtures above.
$previewPath = getenv('LOOPDECK_PREVIEW_DIR');
if ($previewPath && is_dir($previewPath)) {
    foreach ($renderedPages as $name => $html) {
        file_put_contents($previewPath . '/' . str_replace('/', '-', $name), $html);
    }
}
$scriptInput = tempnam(sys_get_temp_dir(), 'loopdeck-scripts-');
try {
    file_put_contents($scriptInput, json_encode($renderedPages, JSON_THROW_ON_ERROR));
    $process = proc_open(['node', __DIR__ . '/RenderedJavascriptCheck.js', $scriptInput], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    functionalCheck(proc_close($process) === 0, $output);
} finally {
    unlink($scriptInput);
}
echo "Rendered {$count} templates without warnings\n";
