<?php

declare(strict_types=1);

// Run explicitly against an empty disposable MySQL database. No upstream calls.
require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/vendor/topthink/framework/src/helper.php';
require_once dirname(__DIR__) . '/app/common.php';

use think\facade\Db;
use think\facade\Session;

$database = (string)getenv('LOOPDECK_TEST_DATABASE');
if (!preg_match('/\Aloopdeck_qa_[a-z0-9_]+\z/', $database)) {
    throw new RuntimeException('Use a disposable loopdeck_qa_* database');
}
$app = new think\App(dirname(__DIR__) . '/');
$app->config->set(['default' => 'mysql', 'connections' => ['mysql' => [
    'type' => 'mysql', 'hostname' => getenv('LOOPDECK_TEST_HOST') ?: '127.0.0.1',
    'database' => $database, 'username' => getenv('LOOPDECK_TEST_USER'),
    'password' => getenv('LOOPDECK_TEST_PASSWORD'), 'prefix' => 'cloud_', 'charset' => 'utf8mb4',
]]], 'database');
$app->config->set(['task_lock_directory' => sys_get_temp_dir() . '/loopdeck-binding-' . hash('sha256', $database)], 'app');
$manager = new think\DbManager();
$manager->setConfig($app->config->get('database'));
$app->instance('db', $manager);
(new think\service\ModelService($app))->boot();
define('WEB_ID', 1);

if (($argv[1] ?? '') === 'worker') {
    Session::set('user', Db::name('users')->find((int)$argv[2]));
    echo "READY\n";
    flush();
    $method = new ReflectionMethod(app\index\controller\Netease::class, 'storeAccount');
    $response = $method->invoke(new app\index\controller\Netease(), [
        'user_id' => $argv[3], 'csrf' => 'fixture', 'musicu' => 'fixture', 'nickname' => 'fixture',
    ]);
    echo json_encode($response->getData(), JSON_UNESCAPED_UNICODE) . "\n";
    exit;
}

if (Db::query('SELECT DATABASE() AS db')[0]['db'] !== $database || Db::query('SHOW TABLES') !== []) {
    throw new RuntimeException('The disposable database is not empty or has the wrong identity');
}
$pdo = Db::connect()->getPdo();
$pdo->exec(file_get_contents(dirname(__DIR__) . '/app/install/install.sql'));
foreach ([1, 2] as $uid) {
    Db::name('users')->insert(['uid' => $uid, 'web_id' => 1, 'username' => 'fixture-' . $uid,
        'password' => 'inert-fixture', 'vip_start' => '2026-01-01', 'vip_end' => '2099-12-31', 'quota' => 10]);
}
$failed = 0;
for ($round = 1; $round <= 3; $round++) {
    $accountId = (string)(424242 + $round);
    $workers = [];
    $pdo->beginTransaction();
    $pdo->query('SELECT uid FROM cloud_users WHERE uid IN (1,2) FOR UPDATE')->fetchAll();
    try {
        foreach ([1, 2] as $uid) {
            $process = proc_open([PHP_BINARY, __FILE__, 'worker', (string)$uid, $accountId],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) {
                throw new RuntimeException('Could not create binding worker');
            }
            fclose($pipes[0]);
            if (trim((string)fgets($pipes[1])) !== 'READY') {
                throw new RuntimeException('Binding worker did not reach the barrier');
            }
            $workers[] = [$process, $pipes];
        }
        // Both controller pre-checks finish before either owner row becomes writable.
        usleep(700000);
    } finally {
        $pdo->commit();
    }
    foreach ($workers as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('Binding worker failed: ' . $error . $output);
        }
    }
    $owners = Db::name('accounts')->where('type', 'netease')->where('user_id', $accountId)->count();
    echo ($owners === 1 ? 'PASS' : 'FAIL') . " binding round {$round}: {$owners} owners\n";
    $failed += (int)($owners !== 1);
}
if ($failed) {
    throw new RuntimeException('Concurrent users bypassed exclusive platform-account ownership');
}
echo "MySQL concurrent account binding tests passed\n";
