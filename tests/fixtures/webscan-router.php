<?php

declare(strict_types=1);

// Disposable HTTP fixture: the firewall runs before framework body parsing,
// exactly as it does in public/index.php. Never initialize an installed app.
error_reporting(E_ALL & ~E_DEPRECATED);
$temporary = (string)getenv('LOOPDECK_WEBSCAN_TEST_DIRECTORY');
if ($temporary === '' || !is_dir($temporary)) {
    throw new RuntimeException('The webscan HTTP fixture requires its disposable directory');
}
define('LOOPDECK_HONEYPOT_DIRECTORY', $temporary . '/honeypot');
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__, 2) . '/public/index.php';
require dirname(__DIR__, 2) . '/extend/360webscan.php';
file_put_contents($temporary . '/business-entered', 'framework entry reached');

use think\facade\Db;

$fixtureRuntimePath = $temporary . '/runtime/';
require dirname(__DIR__) . '/FunctionalDatabaseBootstrap.php';
$app->instance('cache', new think\cache\driver\File($app, ['path' => $temporary . '/cache/']));
$app->config->set(require dirname(__DIR__, 2) . '/config/route.php', 'route');
$app->config->set(['default_app' => 'index', 'app_express' => true], 'app');
$app->config->set(['reg_close' => 0, 'reg_iplimit' => 1, 'reg_free_vip' => 0, 'reg_free_quota' => 0], 'sys');
$honeypotTest = getenv('LOOPDECK_HONEYPOT_HTTP_TEST') === '1';
if ($honeypotTest) {
    define('PJAX', !empty($_SERVER['HTTP_X_PJAX']));
    $app->config->set(require dirname(__DIR__, 2) . '/config/view.php', 'view');
    $app->config->set(['webname' => 'LoopDeck', 'title' => '隔离测试'], 'web');
    $role = $_SERVER['HTTP_X_LOOPDECK_TEST_ROLE'] ?? 'user';
    if ($role === 'guest') {
        think\facade\Session::delete('user');
    } else {
        Db::name('users')->where('uid', 1)->update(['power' => $role === 'admin' ? 6 : 0, 'nickname' => '测试用户']);
    }
}
think\Validate::maker(static function ($validator): void {
    $validator->extend('captcha', static fn($value): bool => $value === 'fixture-captcha');
});
fixtureTable('captcha', 'id INTEGER PRIMARY KEY AUTOINCREMENT, type INTEGER, send TEXT, code TEXT,
    time INTEGER, status INTEGER DEFAULT 0, ip TEXT');
Db::name('users')->where('uid', 1)->update(['mail' => 'fixture@example.invalid',
    'password' => password_hash('Blue-sleep(3)-password', PASSWORD_DEFAULT)]);
Db::name('captcha')->insert(['type' => 2, 'send' => 'fixture@example.invalid',
    'code' => str_repeat('a', 48), 'time' => time()]);
$app->instance('request', think\Request::__make($app));
$request = $app->request;
$path = '/' . $request->pathinfo();

$probe = static function () use ($request, $path): think\Response {
    $files = [];
    foreach ($_FILES as $name => $upload) {
        if (is_string($upload['tmp_name'] ?? null) && is_uploaded_file($upload['tmp_name'])) {
            $files[$name] = ['bytes' => filesize($upload['tmp_name']),
                'mime' => (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name'])];
        }
    }
    return think\Response::create(['path' => $path, 'get' => $request->get(),
        'post' => $request->post(), 'files' => $files], 'json');
};

// Use the real multi-app and controller dispatchers, including controller-name
// conversion and path parameters, while keeping installed-app loaders disabled.
$multiApp = new class($app) extends think\app\MultiApp {
    protected function loadApp(string $appName, string $appPath): void
    {
    }
};
$app->middleware->add(static function ($request, $next) use ($probe, $honeypotTest) {
    $target = $request->controller() . '/' . strtolower($request->action());
    if ($honeypotTest && $target === 'System/honeypot') {
        return (new app\middleware\SiteTemplate())->handle($request, $next);
    }
    if (!in_array($target, ['Index/version', 'Index/healthcheck', 'LoginAjax/login', 'LoginAjax/reg',
        'LoginAjax/reset', 'Ajax/qrcode', 'Ajax/user'], true)) {
        return $probe();
    }
    $operation = $request->route('act', $request->get('act', ''));
    if (($target === 'Ajax/qrcode' && $operation !== 'uploads')
        || ($target === 'Ajax/user' && $operation !== 'passWord')) {
        return $probe();
    }
    return $next($request);
}, 'route');
$response = $multiApp->handle($request, static function ($request) use ($app, $probe, $honeypotTest) {
    if ($honeypotTest && $app->http->getName() === 'cron') {
        return (new app\middleware\CheckCronAccess())->handle($request, $probe);
    }
    if ($honeypotTest && $app->http->getName() === 'admin') {
        return $app->route->dispatch($request, static function () use ($app): void {
            require $app->getAppPath() . 'route/app.php';
        });
    }
    if ($app->http->getName() !== 'index') {
        return $probe();
    }
    return $app->route->dispatch($request, static function () use ($app): void {
        require $app->getAppPath() . 'route/app.php';
    });
});
$response->send();
