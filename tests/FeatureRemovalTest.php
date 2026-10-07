<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/common.php';

use app\admin\controller\Ajax as AdminAjax;
use app\admin\controller\System;
use app\admin\model\Weblist;
use app\index\controller\Ajax;
use app\index\controller\Console;
use app\index\model\Kms;
use app\service\NotificationSite;
use think\facade\Config;
use think\facade\Session;

function removalCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$cache = sys_get_temp_dir() . '/loopdeck-removal-test-' . bin2hex(random_bytes(6)) . '/';
$app = new think\App($root . '/');
$app->setRuntimePath($cache);
$app->initialize();
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
});

foreach (['agent', 'site', 'vip', 'quota', 'money', 'unknown'] as $shop) {
    removalCheck((new Console())->shop($shop)->getCode() === 404, 'Retired shop page remains reachable');
}
removalCheck((new Ajax())->shop('buy')->getCode() === 404, 'Purchase action remains reachable');
removalCheck((new Ajax())->sport('add')->getCode() === 404, 'Retired sport AJAX action remains reachable');
removalCheck((new Console())->sport('add', '1')->getCode() === 404, 'Retired sport console remains reachable');
removalCheck(!method_exists(System::class, 'pay') && !method_exists(AdminAjax::class, 'pay'),
    'Payment settings or order actions remain auto-routable');
removalCheck(!method_exists(app\index\model\Users::class, 'spendBalance'), 'Balance spending logic remains');
foreach (['app/index/controller/Epay.php', 'app/index/controller/Alipay.php',
    'app/index/model/Pays.php', 'app/service/PaymentSettlement.php', 'app/admin/model/Order.php',
    'app/index/view/common/alipay.html', 'public/static/js/admin_order_datatables.js',
    'app/index/view/console/shop/vip.html',
    'app/index/view/console/shop/quota.html', 'app/index/view/console/shop/money.html'] as $path) {
    removalCheck(!is_file($root . '/' . $path), 'Payment implementation remains: ' . $path);
}
foreach (['extend/epay', 'extend/alipay', 'app/admin/view/system/pay'] as $directory) {
    removalCheck(glob($root . '/' . $directory . '/*') === [], 'Payment SDK or settings remain');
}
removalCheck((new System())->set('template')->getCode() === 404, 'Template chooser remains reachable');
foreach (['app/index/view/index/default', 'app/index/view/login/default', 'public/static/template',
    'public/static/css/themes', 'public/static/js/codebase.app.min-5.0.js',
    'app/admin/view/system/set/template.html'] as $path) {
    removalCheck(!file_exists($root . '/' . $path), 'Original template remains: ' . $path);
}
$schema = file_get_contents($root . '/app/install/install.sql');
removalCheck(!preg_match('/cloud_(?:pays|order)|vip_price_|quota_price_|OrderPlacementMethod|`money`/', $schema),
    'Fresh installation recreates payment schema or settings');
removalCheck((new System())->data('sites')->getCode() === 404, 'Retired site management page remains reachable');
foreach (['list' => 'sites', 'add' => 'site', 'delete' => 'site', 'set' => 'site', 'info' => 'site'] as $act => $action) {
    removalCheck((new AdminAjax())->data($act, $action)->getCode() === 404, 'Retired management operation remains reachable');
}
removalCheck(!method_exists(Console::class, 'agent') && !method_exists(Ajax::class, 'agent'), 'Reseller controller action remains');
removalCheck(!method_exists(Console::class, 'douyin'), 'Retired platform console remains');
removalCheck(!method_exists(Kms::class, 'agent_add'), 'Resellers can still issue cards');
removalCheck(Kms::admin_add(['type' => 'agent'])->getData()['code'] === 0, 'Administrator can issue a retired card');
removalCheck(!Weblist::updateByWebid(2, ['webname' => 'Archived']), 'Archived site settings can still be changed');
removalCheck(!(new NotificationSite())->get(2)['exists'], 'Archived site still provides notification configuration');

// Legacy stored card values remain redeemable; generation no longer accepts product IDs.
foreach (['vip' => [3, 7, 30, 90, 180, 365], 'quota' => [1, 3, 5, 10]] as $type => $values) {
    foreach ($values as $value) {
        removalCheck(app\service\RedemptionPlan::fromCard($type, (string)$value) !== null, 'A supported card cannot be redeemed');
    }
    foreach (['', '0', '-1', '2', '9999', '1.5', 'abc'] as $value) {
        removalCheck(app\service\RedemptionPlan::fromCard($type, $value) === null, 'An invalid card value is accepted');
    }
    removalCheck(Kms::admin_add(['type' => $type, 'value' => '9999', 'num' => 1])->getData()['code'] === 0,
        'Invalid card product reaches issuance');
}
removalCheck(app\service\RedemptionPlan::fromCard('agent', '3') === null, 'Legacy reseller card still grants access');
foreach (['vip', 'quota'] as $type) {
    removalCheck(Kms::admin_add(['type' => $type, 'value' => '1', 'num' => '1'])->getData()['code'] === 0,
        'Split card generation is still reachable');
}
removalCheck(!is_file($root . '/app/index/validate/Kms.php'), 'Obsolete product-ID validator remains');

foreach ([
    'app/index/controller/Douyin.php', 'app/admin/validate/Weblist.php',
    'app/index/view/console/douyin/login.html', 'app/index/view/console/douyin/list.html',
    'app/index/view/console/agent/index.html', 'app/index/view/console/shop/agent.html',
    'app/index/view/console/shop/site.html', 'app/admin/view/system/data/sites.html',
    'app/admin/view/system/pay/agent.html', 'app/admin/view/system/pay/site.html',
    'extend/douyin/sdk/Client.php', 'extend/douyin/runtime/worker.cjs',
    'extend/douyin/runtime/vendor/bdms.js', 'extend/douyin/runtime/vendor/dtrait.js',
    'public/static/js/douyin-captcha-runtime.js', 'public/static/site.sql',
] as $path) {
    removalCheck(!is_file($root . '/' . $path), 'Retired feature file remains: ' . $path);
}

// The retired per-platform shells (sport/iqiyi/tieba/mihoyo) must stay gone,
// and their URL surface must keep its explicit 404 mapping in both shells.
foreach (['app/command/Sport.php', 'app/cron/controller/Sport.php', 'extend/sport/Step.php',
    'app/index/validate/Sport.php', 'app/index/model/Order.php'] as $path) {
    removalCheck(!is_file($root . '/' . $path), 'Retired platform file remains: ' . $path);
}
removalCheck(glob($root . '/extend/sport/*') === [], 'Retired sport SDK remains');
removalCheck(!method_exists(app\index\model\Jobs::class, 'addSportJob'), 'Retired sport job factory remains');
foreach (['app/cron/route/app.php', 'app/index/route/app.php'] as $routeFile) {
    $routeSource = file_get_contents($root . '/' . $routeFile);
    removalCheck(is_string($routeSource), 'Unable to inspect route file: ' . $routeFile);
    removalCheck(preg_match("/\[\s*'iqiyi'\s*,\s*'tieba'\s*,\s*'mihoyo'\s*,\s*'sport'\s*\]/", $routeSource) === 1,
        'Retired platform URLs lost their 404 mapping: ' . $routeFile);
}
$consoleCommands = file_get_contents($root . '/config/console.php');
removalCheck(!preg_match('/[\'"]sport[\'"]/i', $consoleCommands), 'Retired sport command remains registered');

define('PJAX', false);
define('WEB_ID', 1);
$_SERVER['HTTP_USER_AGENT'] = 'LoopDeck offline test';
Config::set(['webname' => 'LoopDeck', 'title' => '测试面板', 'user_qq' => '10000'], 'web');
Config::set(['OrderPlacementMethod' => 1, 'is_alipay' => 1, 'is_wxpay' => 1, 'epay_url' => 'https://pay.example/', 'is_site' => 1, 'reg_free_agent' => 1], 'sys');

// Cron endpoints authenticate exclusively through the middleware: the env
// credential and the installed key are both accepted, nothing else is.
$previousCronKey = getenv('CRON_KEY');
try {
    putenv('CRON_KEY');
    Config::set(['cronkey' => 'installed-cron-key'], 'sys');
    $guard = new app\middleware\CheckCronAccess();
    $credential = new class {
        public string $headerValue = '';
        public string $queryValue = '';
        public function header(string $name, string $default = '') { return $this->headerValue; }
        public function get(string $name, $default = '') { return $this->queryValue; }
    };
    $pass = static fn() => 'allowed';
    $credential->queryValue = 'installed-cron-key';
    removalCheck($guard->handle($credential, $pass) === 'allowed', 'The installed cron key was rejected without env');
    $credential->queryValue = '';
    $credential->headerValue = 'installed-cron-key';
    removalCheck($guard->handle($credential, $pass) === 'allowed', 'The installed cron key was rejected via header');
    $credential->headerValue = 'wrong-key';
    removalCheck($guard->handle($credential, $pass)->getCode() === 403, 'A wrong cron credential was accepted');
    putenv('CRON_KEY=rotated-env-key');
    $credential->headerValue = 'rotated-env-key';
    removalCheck($guard->handle($credential, $pass) === 'allowed', 'The rotated env cron key was rejected');
    $credential->headerValue = 'installed-cron-key';
    removalCheck($guard->handle($credential, $pass) === 'allowed', 'Rotating the env key locked out the installed key');
} finally {
    putenv($previousCronKey === false ? 'CRON_KEY' : 'CRON_KEY=' . $previousCronKey);
}

Session::set('user', ['uid' => 1, 'web_id' => 1, 'power' => 6, 'agent' => 3, 'nickname' => '测试用户',
    'qq' => '10000', 'mail' => 'qa@example.invalid', 'money' => 10, 'quota' => 5, 'vip_start' => null, 'vip_end' => null]);
$data = ['redemption_presets' => app\service\RedemptionPlan::presets(), 'webTitle' => '测试页面', 'notice' => [], 'notices' => [], 'user_count' => 1,
    'redemption_max_days' => app\service\RedemptionPlan::MAX_DAYS,
    'redemption_max_accounts' => app\service\RedemptionPlan::MAX_ACCOUNTS,
    'redemption_max_batch' => app\service\RedemptionPlan::MAX_BATCH,
    'quota_used' => 0, 'account_count' => 0, 'job_count' => 0, 'execute_count' => 0];
foreach ([
    'index' => ['console/index', 'console/user/faq', 'console/shop/card'],
    'admin' => ['system/index', 'system/set/reg', 'system/data/users', 'system/data/kms'],
] as $application => $views) {
    $engine = new think\Template(['view_path' => $root . '/app/' . $application . '/view/',
        'cache_path' => $cache . 'templates/' . $application . '/']);
    foreach ($views as $view) {
        ob_start();
        try {
            $engine->fetch($view, $data);
            $html = (string)ob_get_contents();
        } finally {
            ob_end_clean();
        }
        removalCheck($html !== '', 'Remaining page failed to render: ' . $view);
        removalCheck(!str_contains($html, '/admin/system/set/template'), 'Template chooser navigation remains');
        removalCheck(!preg_match('/分站|代理|抖音|\/console\/douyin|\/shop\/(?:agent|site)/u', $html),
            'Retired navigation reappeared with legacy settings: ' . $view);
        removalCheck(!preg_match('/购买|充值|支付配置|价格设置|余额|\/shop\/(?:vip|quota|money)|\/system\/pay/u', $html),
            'Payment UI reappeared with legacy settings: ' . $view);
        removalCheck(!preg_match('/直播间|globalroom|global_room/u', $html),
            'Retired live-room configuration reappeared: ' . $view);
        if ($application === 'index') {
            removalCheck(str_contains($html, '/index/console/shop/card'), 'Legacy settings hid redemption');
        }
    }
}

$retiredLiveTasks = ['dailybag', 'doubleheart', 'groupsignIn', 'giftheart', 'globalroom'];
$factoryCalls = 0;
$executor = new app\service\BilibiliTaskExecutor(static function () use (&$factoryCalls) {
    $factoryCalls++;
    throw new RuntimeException('Retired task reached an adapter');
});
foreach ($retiredLiveTasks as $task) {
    removalCheck(!app\service\BilibiliTaskExecutor::supports($task), 'Retired live task is supported: ' . $task);
    removalCheck(!in_array($task, app\service\BilibiliTaskExecutor::executableTasks(), true), 'Retired live task is schedulable');
    $result = $executor->execute($task, []);
    removalCheck($result['code'] === 0 && str_contains($result['message'], '已停用'), 'Retired task is not rejected clearly');
}
removalCheck($factoryCalls === 0, 'Retired live tasks reached upstream adapters');
removalCheck(app\service\BilibiliTaskExecutor::executableTasks() ===
    ['manga', 'silver2coin', 'watchaid', 'coinadd', 'dailyexperience', 'vipexperience'],
    'Retiring live tasks changed the retained task set');
removalCheck(!str_contains((string)$schema, "'globalroom'"),
    'A fresh installation still seeds the retired global live-room task');

// Render the real Bilibili console page: a retired row must stay visible but
// disabled, and the page must no longer carry any live-room configuration UI.
$engine = new think\Template(['view_path' => $root . '/app/index/view/',
    'cache_path' => $cache . 'templates/bilibili/']);
ob_start();
try {
    $engine->fetch('console/bilibili/info', $data + [
        'data' => ['user_id' => '42', 'mid' => '42', 'state' => 1],
        'a_data' => ['mid' => '42', 'nickname' => '测试账号'],
        'timing' => '09:00',
        'level_info' => [],
        'info_warning' => '',
        'task_rows' => [
            ['execute_name' => 'coinadd', 'name' => '每日投币', 'describe' => '投币视频（主站任务）',
                'icon' => 'si si-badge', 'more' => true, 'offline' => false, 'offline_reason' => '',
                'last_execute' => '--', 'job_state' => 1, 'user_id' => '42', 'config_json' => '{}'],
            ['execute_name' => 'globalroom', 'name' => '全局配置', 'describe' => '全局配置',
                'icon' => 'si si-compass', 'more' => false, 'offline' => true,
                'offline_reason' => app\service\BilibiliTaskExecutor::offlineReason('globalroom'),
                'last_execute' => '--', 'job_state' => 0, 'user_id' => '42', 'config_json' => '{}'],
        ],
    ]);
    $bilibiliHtml = (string)ob_get_contents();
} finally {
    ob_end_clean();
}
removalCheck($bilibiliHtml !== '', 'The Bilibili console page failed to render');
removalCheck(!preg_match('/globalRoom|global_room|is_global/u', $bilibiliHtml),
    'Live-room configuration markup remains in the Bilibili console page');
removalCheck(!str_contains($bilibiliHtml, "updateConfig('globalroom'"),
    'The retired live-room row still offers a configuration dialog');
removalCheck(str_contains($bilibiliHtml, '旧直播任务已停用') && str_contains($bilibiliHtml, '已下架'),
    'The retired live-room row is no longer explained as disabled');
removalCheck(str_contains($bilibiliHtml, "updateConfig('coinadd'"),
    'Retiring live tasks removed the configuration dialog of a retained task');

echo "Feature removal and retained entitlement view tests passed\n";
