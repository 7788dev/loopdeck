<?php

declare(strict_types=1);

require __DIR__ . '/DouyinTestBootstrap.php';

$root = dirname(__DIR__);
$worker = file_get_contents($root . '/extend/douyin/runtime/worker.cjs');
$client = file_get_contents($root . '/extend/douyin/sdk/Client.php');
$controller = file_get_contents($root . '/app/index/controller/Douyin.php');
$console = file_get_contents($root . '/app/index/controller/Console.php');
$navigation = file_get_contents($root . '/app/index/view/console/head.html');
$view = file_get_contents($root . '/app/index/view/console/douyin/login.html');
$listView = file_get_contents($root . '/app/index/view/console/douyin/list.html');
$routes = file_get_contents($root . '/app/index/route/app.php');

douyinCheck(is_string($worker) && str_contains($worker, "only login.douyin.com/passport/ URLs are accepted"), 'worker target boundary is missing');
douyinCheck(!str_contains((string)$worker, 'globalThis.fetch.bind'), 'worker still contains a native fetch bridge');
douyinCheck(!str_contains((string)$worker, "require('node:http')"), 'worker can issue direct HTTP requests');
douyinCheck(!str_contains((string)$worker, "require('node:https')"), 'worker can issue direct HTTPS requests');
douyinCheck(str_contains((string)$worker, 'DTraitSDK'), 'worker does not initialize the local DTrait core');
douyinCheck(str_contains((string)$worker, 'X-TT-Session-Dtrait'), 'worker does not return the DTrait request header');

$bundleHash = hash_file('sha256', $root . '/extend/douyin/runtime/vendor/bdms.js');
douyinCheck(
    $bundleHash === 'd211c62a7ab5eb5d8bc2a0bde54657999fcbaa5dc869964c46dd79cc0865895d',
    'BDMS runtime bundle hash changed without updating the verified fixture'
);
$dtraitHash = hash_file('sha256', $root . '/extend/douyin/runtime/vendor/dtrait.js');
douyinCheck(
    $dtraitHash === 'af6984d4fdf37eb38be717ec0601528a477a070a646d3f4ec2a87e8eadac74d6',
    'DTrait runtime bundle hash changed without updating the verified fixture'
);
$captchaHash = hash_file('sha256', $root . '/public/static/js/douyin-captcha-runtime.js');
douyinCheck(
    $captchaHash === 'f5c075614a54fd57ac13f84a2e6d5e2952250e17a7b91a730b735d63227ddc3a',
    'Douyin captcha renderer hash changed without updating the verified fixture'
);

douyinCheck(is_string($routes) && str_contains($routes, "Route::rule('douyin/[:act]', 'douyin/handle')"), 'Douyin AJAX route is missing');
douyinCheck(is_string($client) && str_contains($client, 'consumeAuthRedirect'), 'SDK does not consume the confirmed redirect');
douyinCheck(str_contains((string)$client, 'X-TT-Passport-Verify-Portrait'), 'SDK does not send the portrait ID');
douyinCheck(str_contains((string)$client, "'dtrait_key' => \$this->dtraitKey"), 'SDK does not persist DTrait state');
douyinCheck(str_contains((string)$client, "'/passport/account/info/v2/'"), 'SDK does not request Douyin account info');
douyinCheck(is_string($controller) && str_contains($controller, "'state' => \$state"), 'controller does not persist protocol state server-side');
douyinCheck(str_contains((string)$controller, "Accounts::add('douyin'"), 'confirmed login is not stored as an account');
douyinCheck(str_contains((string)$controller, "'cookie' => \$cookieHeader"), 'account storage is missing the final cookie header');
douyinCheck(!str_contains((string)$controller, "'cookies' => \$client"), 'controller exposes login cookies in an AJAX response');
douyinCheck(str_contains((string)$controller, "Request::post('login_id'"), 'controller does not use an opaque login ID');
douyinCheck(!str_contains((string)$controller, "Request::post('session_id'"), 'controller collides with the PHP session ID parameter');
douyinCheck(is_string($navigation) && str_contains($navigation, 'aria-disabled="true"'), 'Douyin navigation is not disabled');
douyinCheck(str_contains((string)$navigation, '>开发中</span>'), 'Douyin navigation is missing its development status');
douyinCheck(!str_contains((string)$navigation, "href=\"{:url('/index/console/douyin/"), 'Douyin navigation still links to unfinished pages');
douyinCheck(is_string($view) && str_contains($view, '/index/ajax/douyin/poll'), 'QR page does not poll the protocol endpoint');
douyinCheck(str_contains((string)$view, "x.ajax('/index/ajax/douyin/start', {}"), 'QR session creation is not sent with POST');
douyinCheck(str_contains((string)$view, 'id="qr-img"'), 'QR page does not use the NetEase add-page QR container');
douyinCheck(str_contains((string)$view, '扫码完成，点击验证'), 'QR page is missing the explicit verification action');
douyinCheck(!str_contains((string)$view, 'setInterval('), 'QR page still performs automatic background polling');
douyinCheck(str_contains((string)$view, '/index/console/douyin/list'), 'successful login does not navigate to the account list');
douyinCheck(!str_contains((string)$view, '{session_id:'), 'QR page submits the reserved PHP session ID parameter');
douyinCheck(str_contains((string)$view, 'douyin-verification-frame'), 'QR page does not render human verification');
douyinCheck(str_contains((string)$view, 'sandbox="allow-scripts allow-forms'), 'verification iframe is not sandboxed');
douyinCheck(str_contains((string)$view, 'window.TTGCaptcha.render'), 'QR page does not render Bdturing verification data');
douyinCheck(!str_contains((string)$view, 'document.cookie'), 'QR page reads browser cookies directly');
douyinCheck(is_string($console) && str_contains($console, "case 'list':"), 'Douyin account list route is missing');
douyinCheck(is_string($listView) && str_contains($listView, '/index/ajax/douyin/delete'), 'Douyin account list cannot delete an account');
echo "Douyin login module tests passed\n";
