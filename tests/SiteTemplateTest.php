<?php

declare(strict_types=1);

require __DIR__ . '/FunctionalDatabaseBootstrap.php';

use app\middleware\SiteTemplate;
use think\facade\View;

$root = str_replace('\\', '/', dirname(__DIR__));
$temporary = sys_get_temp_dir() . '/loopdeck-site-template-' . bin2hex(random_bytes(6)) . '/';
$app->setRuntimePath($temporary);
$app->config->set(require $root . '/config/view.php', 'view');
$middleware = new SiteTemplate();
$request = new think\Request();
foreach (['index', 'admin'] as $module) {
    $app->http->name($module);
    foreach (['ruoyi', 'default', '../invalid', 'ruoyi', 'default'] as $theme) {
        $app->config->set(['site_template' => $theme], 'sys');
        $result = $middleware->handle($request, static fn($nextRequest) => $nextRequest);
        functionalCheck($result === $request, 'Theme middleware interrupted the request');
        $expected = $root . '/app/' . $module . '/view/' . ($theme === 'ruoyi' ? 'ruoyi/' : '');
        $actual = preg_replace('~/+~', '/', str_replace('\\', '/', View::engine()->getConfig('view_path')));
        functionalCheck($actual === $expected, 'Theme view path mismatch: ' . $actual . ' != ' . $expected);
    }
    $viewRoot = $root . '/app/' . $module . '/view/';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewRoot)) as $file) {
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($viewRoot)));
        if ($file->getExtension() !== 'html' || str_starts_with($relative, 'ruoyi/')
            || $relative === 'common/jump.html' // Historical orphan; no controller renders it.
            || $relative === 'system/data/tasks.html' // The tasks route renders data/accounts.
            || str_contains($relative, '/sport/') || preg_match('~^(login|index)/[^/]+/~', $relative)) continue;
        functionalCheck(is_file($viewRoot . 'ruoyi/' . $relative), 'Missing RuoYi page: ' . $module . '/' . $relative);
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewRoot . 'ruoyi/')) as $file) {
        if ($file->getExtension() !== 'html') continue;
        $source = file_get_contents($file->getPathname());
        functionalCheck(!str_contains($source, 'datatables-bs5') && !str_contains($source, 'responsive-bs5'), 'Bootstrap 5 plugin in RuoYi page');
        functionalCheck(!preg_match('/Codebase\.(?:block|layout)\(/', $source), 'Unguarded original-shell dependency in RuoYi page');
        functionalCheck(!preg_match('~(?:href|src)=["\'][^"\']*/demo/~', $source), 'RuoYi example page is reachable');
    }
}

foreach (['../../admin', 'unknown', ['ruoyi']] as $invalid) {
    fixtureRequest(['site_template' => $invalid]);
    functionalCheck((new app\admin\controller\Ajax())->set('config')->getData()['code'] === 0, 'Invalid site template accepted');
}
foreach (['index', 'login'] as $section) {
    $templates = $section === 'index' ? app\admin\model\Weblist::indexTemplateData() : app\admin\model\Weblist::loginTemplateData();
    foreach ($templates as $template) {
        $app->config->set([$section . '_template' => $template['id']], 'web');
        foreach (['default', 'ruoyi', 'default'] as $theme) {
            $app->config->set(['site_template' => $theme], 'sys');
            foreach ($section === 'index' ? ['index'] : ['login', 'reg', 'find', 'reset'] as $page) {
                $expected = $section . '/' . ($theme === 'default' ? $template['id'] . '/' : '') . $page;
                functionalCheck(app\service\SiteTheme::entry($section, $page) === $expected, 'Saved entry template was ignored or lost');
            }
        }
    }
    $app->config->set([$section . '_template' => '../../admin'], 'web');
    functionalCheck(app\service\SiteTheme::entry($section, $section) === $section . '/default/' . $section, 'Unsafe subtemplate path accepted');
}
fixtureTable('accounts', 'id INTEGER PRIMARY KEY, uid INTEGER, zid INTEGER, type TEXT, user_id TEXT, data TEXT');
think\facade\Db::name('accounts')->insert([
    'id' => 1, 'uid' => 1, 'zid' => 1, 'type' => 'qrcode', 'user_id' => 'fixture',
    'data' => serialize(['name' => '测试收款码', 'qq_url' => 'https://pay.example/fixture']),
]);
$app->http->name('index');
foreach (['ruoyi', 'default'] as $theme) {
    $app->config->set(['site_template' => $theme], 'sys');
    fixtureRequest([], ['name' => 'fixture', 'uid' => '1']);
    $middleware->handle($app->request, static fn($nextRequest) => $nextRequest);
    $html = (new app\index\controller\Index())->qrcode();
    functionalCheck(is_string($html) && str_contains($html, '测试收款码'), 'Public QR route did not render');
    functionalCheck(str_contains($html, '/static/ruoyi/') === ($theme === 'ruoyi'), 'Public QR route used the wrong theme');
}
define('PJAX', true);
$title = "O'Reilly </script> \"标题\"";
$app->config->set(['webname' => $title, 'title' => '测试'], 'web');
foreach (['index', 'admin'] as $module) {
    $app->http->name($module);
    foreach (['default', 'ruoyi'] as $theme) {
        $app->config->set(['site_template' => $theme], 'sys');
        $middleware->handle($app->request, static fn($request) => $request);
        $html = View::fetch('common/layout');
        functionalCheck(substr_count($html, '</script>') === 1, 'PJAX title can close its script element');
        preg_match('/document\.title = (.*);/', $html, $match);
        functionalCheck(json_decode($match[1] ?? '', true) === $title . ' - 测试', 'PJAX title was not serialized safely');
    }
}
echo "Site template switching, page coverage and dependency boundaries passed\n";
