<?php

declare(strict_types=1);

// Explicit opt-in, with an empty disposable MySQL database and inert records.
require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/vendor/topthink/framework/src/helper.php';
require_once dirname(__DIR__) . '/app/common.php';

use app\index\model\Captcha;
use app\service\SchemaMaintenance;
use think\facade\Db;

$database = (string)getenv('LOOPDECK_TEST_DATABASE');
if (!preg_match('/\Aloopdeck_qa_[a-z0-9_]+\z/', $database)) {
    throw new RuntimeException('Use an empty disposable loopdeck_qa_* database');
}
$app = new think\App(dirname(__DIR__) . '/');
$runtime = sys_get_temp_dir() . '/loopdeck-recovery-' . bin2hex(random_bytes(6)) . '/';
$app->setRuntimePath($runtime);
mkdir($runtime, 0700, true);
$app->config->set(['default' => 'mysql', 'connections' => ['mysql' => [
    'type' => 'mysql', 'hostname' => getenv('LOOPDECK_TEST_HOST') ?: '127.0.0.1',
    'database' => $database, 'username' => getenv('LOOPDECK_TEST_USER'),
    'password' => getenv('LOOPDECK_TEST_PASSWORD'), 'prefix' => 'cloud_', 'charset' => 'utf8mb4',
]]], 'database');
$manager = new think\DbManager();
$manager->setConfig($app->config->get('database'));
$app->instance('db', $manager);
(new think\service\ModelService($app))->boot();
if (Db::query('SELECT DATABASE() AS db')[0]['db'] !== $database || Db::query('SHOW TABLES') !== []) {
    throw new RuntimeException('The disposable database is not empty or has the wrong identity');
}
Db::connect()->getPdo()->exec(file_get_contents(dirname(__DIR__) . '/app/install/install.sql'));
Db::execute("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
$failures = [];
$attempt = static function (string $name, string $mail, string $ip) use (&$failures): void {
    $_SERVER['REMOTE_ADDR'] = $ip;
    try {
        Captcha::add(['type' => 2, 'code' => str_repeat('a', 48), 'send' => $mail]);
        $row = Db::name('captcha')->order('id', 'desc')->find();
        if ($row['send'] !== $mail || $row['ip'] !== $ip) {
            throw new RuntimeException('Stored recovery identity was truncated');
        }
        echo "PASS {$name}\n";
    } catch (Throwable $exception) {
        $failures[] = $name;
        echo "FAIL {$name}: " . get_class($exception) . "\n";
    }
};
try {
    $attempt('short mailbox control', 'qa@example.com', '192.0.2.10');
    $attempt('long mailbox', 'recovery.integration.fixture@example.com', '192.0.2.10');
    $attempt('IPv6 client', 'qa@example.com', '2001:db8:1234:5678:90ab:cdef:1234:5678');

    // Recreate the shipped legacy widths, then exercise the actual upgrade sweep.
    Db::name('captcha')->delete(true);
    Db::execute('ALTER TABLE cloud_captcha MODIFY send varchar(20) NOT NULL, MODIFY ip varchar(20) NOT NULL');
    SchemaMaintenance::runIfDue();
    $attempt('upgraded long mailbox and IPv6', 'recovery.integration.fixture@example.com', '2001:db8:1234:5678:90ab:cdef:1234:5678');
    if ($failures !== []) {
        throw new RuntimeException('Recovery storage failures: ' . implode(', ', $failures));
    }
    echo "MySQL recovery storage and upgrade tests passed\n";
} finally {
    foreach (glob($runtime . '*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($runtime);
}
