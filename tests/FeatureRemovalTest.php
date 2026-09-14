<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/common.php';

use app\admin\controller\Ajax as AdminAjax;
use app\admin\controller\System;
use app\admin\model\Weblist;
use app\index\controller\Ajax;
use app\index\controller\Console;
use app\index\controller\Epay;
use app\index\model\Kms;
use app\index\model\Pays;
use app\service\NotificationSite;
use app\service\PaymentSettlement;
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

foreach (['agent', 'site', 'unknown'] as $shop) {
    removalCheck((new Console())->shop($shop)->getCode() === 404, 'Retired shop page remains reachable');
    removalCheck((new System())->pay($shop)->getCode() === 404, 'Retired price settings remain reachable');
    removalCheck(Pays::Submit_Pay(['shop' => $shop])->getData()['code'] === 0, 'Retired product creates an order');
    $result = PaymentSettlement::settle($shop, [], []);
    removalCheck(!$result['ok'] && !$result['applied'], 'Retired callback grants a product');
    removalCheck(!method_exists(Epay::class, $shop . '_Notify') && !method_exists(Epay::class, $shop . '_Return'),
        'Retired callback remains auto-routable');
}
removalCheck(PaymentSettlement::SHOPS === ['vip', 'quota', 'money'], 'Supported products changed unexpectedly');
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

// Stored card values are days/counts, whereas the generation form submits product IDs.
$validateCard = new ReflectionMethod(Kms::class, 'cardValueValid');
foreach (['vip' => [3, 7, 30, 90, 180, 365], 'quota' => [1, 3, 5, 10]] as $type => $values) {
    foreach ($values as $value) {
        removalCheck($validateCard->invoke(null, $type, (string)$value), 'A supported card cannot be redeemed');
    }
    foreach (['', '0', '-1', '2', '9999', '1.5', 'abc'] as $value) {
        removalCheck(!$validateCard->invoke(null, $type, $value), 'An invalid card value is accepted');
    }
    removalCheck(Kms::admin_add(['type' => $type, 'value' => '9999', 'num' => 1])->getData()['code'] === 0,
        'Invalid card product reaches issuance');
}
removalCheck(!$validateCard->invoke(null, 'agent', '3'), 'Legacy reseller card still grants access');

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

define('PJAX', true);
define('WEB_ID', 1);
$_SERVER['HTTP_USER_AGENT'] = 'LoopDeck offline test';
Config::set(['webname' => 'LoopDeck', 'title' => '测试面板', 'user_qq' => '10000'], 'web');
Config::set(['OrderPlacementMethod' => 0, 'is_site' => 1, 'reg_free_agent' => 1], 'sys');
Session::set('user', ['uid' => 1, 'web_id' => 1, 'power' => 6, 'agent' => 3, 'nickname' => '测试用户',
    'qq' => '10000', 'mail' => 'qa@example.invalid', 'money' => 10, 'quota' => 5, 'vip_start' => null, 'vip_end' => null]);
$data = ['webTitle' => '测试页面', 'notice' => [], 'notices' => [], 'user_count' => 1,
    'quota_used' => 0, 'account_count' => 0, 'job_count' => 0, 'execute_count' => 0];
foreach ([
    'index' => ['console/index', 'console/user/faq', 'console/shop/vip', 'console/shop/quota', 'console/shop/card'],
    'admin' => ['system/index', 'system/set/reg', 'system/pay/set', 'system/data/users', 'system/data/kms'],
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
        removalCheck(!preg_match('/分站|代理|抖音|\/console\/douyin|\/shop\/(?:agent|site)/u', $html),
            'Retired navigation reappeared with legacy settings: ' . $view);
    }
}

echo "Feature removal and retained commerce view tests passed\n";
