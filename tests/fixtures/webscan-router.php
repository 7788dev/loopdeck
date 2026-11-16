<?php

declare(strict_types=1);

// Disposable HTTP fixture: the firewall runs before framework body parsing,
// exactly as it does in public/index.php. Never initialize an installed app.
error_reporting(E_ALL & ~E_DEPRECATED);
// Browser regressions use the shipped assets, served by php -S with public/ as its document root.
$assetPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
if (is_string($assetPath) && str_starts_with($assetPath, '/static/')
    && !str_contains($assetPath, '..') && is_file(dirname(__DIR__, 2) . '/public' . $assetPath)) {
    return false;
}
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
$agentRouterTest = getenv('LOOPDECK_AGENTROUTER_HTTP_TEST') === '1';
$agentRouterAdminTest = getenv('LOOPDECK_AGENTROUTER_ADMIN_HTTP_TEST') === '1';
$anyRouterAdminTest = getenv('LOOPDECK_ANYROUTER_ADMIN_HTTP_TEST') === '1';
if ($agentRouterTest) {
    define('PJAX', !empty($_SERVER['HTTP_X_PJAX']));
    $app->config->set(require dirname(__DIR__, 2) . '/config/view.php', 'view');
    $app->config->set(['webname' => 'LoopDeck', 'title' => '隔离测试'], 'web');
    think\facade\View::assign('webTitle', 'AgentRouter');
    fixtureTable('accounts', 'id INTEGER PRIMARY KEY AUTOINCREMENT, uid INTEGER, zid INTEGER DEFAULT 1, type TEXT,
        user_id TEXT, state INTEGER DEFAULT 1, timing TEXT, cooling INTEGER DEFAULT 0, addtime TEXT, data TEXT');
    fixtureTable('tasks', 'id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, execute_name TEXT, name TEXT, describe TEXT,
        icon TEXT, execute_rate INTEGER, execute_url TEXT, more INTEGER, state INTEGER, vip INTEGER, time TEXT, "order" INTEGER');
    fixtureTable('jobs', 'id INTEGER PRIMARY KEY AUTOINCREMENT, uid INTEGER, zid INTEGER DEFAULT 1, type TEXT,
        user_id TEXT, do TEXT, state INTEGER, nextExecute INTEGER, lastExecute TEXT, data TEXT');
    fixtureTable('task_logs', 'id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, user_id TEXT, do TEXT, response TEXT, addtime TEXT');
    Db::name('users')->where('uid', 1)->update(['vip_start' => '2026-01-01', 'vip_end' => '2099-01-01']);
    think\facade\Session::set('user', Db::name('users')->find(1));
    foreach (['fixture-user', 'fixture+daily@example.invalid', 'fixture#name', 'fixture%23name', 'fixture%2Bname'] as $username) {
        app\index\model\Accounts::add('agentrouter', $username, ['username' => $username,
            'password' => 'fixture-private-password', 'nickname' => 'HTTP 测试账号',
            'profile' => ['username' => $username, 'quota' => 62500000.0,
                'quota_per_unit' => 500000.0, 'display_in_currency' => true]]);
    }
    $app->bind(app\index\controller\Agentrouter::class, static function () {
        return new class extends app\index\controller\Agentrouter {
            protected function executor(): app\service\AgentRouterTaskExecutor
            {
                return new app\service\AgentRouterTaskExecutor(static function (): agentrouter\Client {
                    $stack = GuzzleHttp\HandlerStack::create(static function ($request) {
                        $path = $request->getUri()->getPath();
                        $headers = ['Content-Type' => 'application/json'];
                        if ($path === '/api/status') {
                            $body = ['success' => true, 'data' => ['system_name' => 'Agent Router']];
                        } elseif ($path === '/api/user/login') {
                            $headers['Set-Cookie'] = 'session=fixture-session; Path=/; Secure; HttpOnly';
                            $body = ['success' => true, 'data' => ['id' => 42, 'checked_in' => true]];
                        } elseif ($request->getHeaderLine('Cookie') !== 'session=fixture-session') {
                            return new GuzzleHttp\Promise\FulfilledPromise(new GuzzleHttp\Psr7\Response(401));
                        } else {
                            $body = $path === '/api/user/self'
                                ? ['success' => true, 'data' => ['id' => 42, 'username' => 'fixture-user', 'quota' => 62500000]]
                                : ['success' => true];
                        }
                        return new GuzzleHttp\Promise\FulfilledPromise(
                            new GuzzleHttp\Psr7\Response(200, $headers, json_encode($body)));
                    });
                    $client = new agentrouter\Client(new GuzzleHttp\Client(['handler' => $stack]));
                    $client->probeAsync(microtime(true) + 5)->wait();
                    return $client;
                });
            }
        };
    });
}
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
if ($agentRouterAdminTest || $anyRouterAdminTest) {
    if (!defined('PJAX')) define('PJAX', !empty($_SERVER['HTTP_X_PJAX']));
    $app->config->set(require dirname(__DIR__, 2) . '/config/view.php', 'view');
    $app->config->set(['webname' => 'LoopDeck', 'title' => '隔离测试'], 'web');
    fixtureTable('configs', 'k TEXT PRIMARY KEY, v TEXT');
    (new app\service\AgentRouterProxySettings())->save(['kind' => 'proxy',
        'source' => 'http://fixture-user:fixture-platform-secret@127.0.0.1:8080']);
    (new app\service\AnyRouterProxySettings())->save(['kind' => 'proxy',
        'source' => 'http://fixture-user:fixture-any-platform-secret@127.0.0.1:8081']);
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
$app->middleware->add(static function ($request, $next) use ($app, $probe, $honeypotTest, $agentRouterTest, $agentRouterAdminTest, $anyRouterAdminTest) {
    $target = $request->controller() . '/' . strtolower($request->action());
    if ($agentRouterTest && in_array($target, ['Console/agentrouter', 'Agentrouter/handle', 'Ajax/agentrouter'], true)) {
        return (new app\middleware\SiteTemplate())->handle($request, $next);
    }
    if ($honeypotTest && $target === 'System/honeypot') {
        return (new app\middleware\SiteTemplate())->handle($request, $next);
    }
    if (($agentRouterAdminTest || $anyRouterAdminTest) && $app->http->getName() === 'admin'
        && in_array($target, ['System/set', 'Ajax/set'], true)) {
        return (new app\middleware\SiteTemplate())->handle($request, $next);
    }
    if ($anyRouterAdminTest && $target === 'Console/user') {
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
$response = $multiApp->handle($request, static function ($request) use ($app, $probe, $honeypotTest, $agentRouterAdminTest, $anyRouterAdminTest) {
    if ($honeypotTest && $app->http->getName() === 'cron') {
        return (new app\middleware\CheckCronAccess())->handle($request, $probe);
    }
    if (($honeypotTest || $agentRouterAdminTest || $anyRouterAdminTest) && $app->http->getName() === 'admin') {
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
