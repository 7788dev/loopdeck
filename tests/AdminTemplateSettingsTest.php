<?php

declare(strict_types=1);
require __DIR__ . '/FunctionalDatabaseBootstrap.php';

functionalCheck((new app\admin\controller\System())->set('template')->getCode() === 404,
    'Retired template settings route remains reachable');
foreach (['site_template', 'index_template', 'login_template'] as $key) {
    foreach (['default', 'ruoyi', 'onebox'] as $value) {
        fixtureRequest([$key => $value]);
        functionalCheck((new app\admin\controller\Ajax())->set('config')->getData()['code'] === 0,
            'Retired config field is writable: ' . $key);
    }
}
foreach (['index_template', 'login_template'] as $key) {
    functionalCheck(!app\admin\model\Weblist::updateByWebid(1, [$key => 'default']),
        'Retired site field is writable: ' . $key);
}
echo "Retired template settings and writes are inaccessible\n";
