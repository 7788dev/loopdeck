<?php

declare(strict_types=1);

// Explicit opt-in. Reproduce legacy engine behavior using synthetic records only.
require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/vendor/topthink/framework/src/helper.php';
require_once dirname(__DIR__) . '/app/common.php';

use app\index\model\Kms;
use app\service\SchemaMaintenance;
use think\facade\Db;
use think\facade\Session;

$database = (string)getenv('LOOPDECK_TEST_DATABASE');
if (!preg_match('/\Aloopdeck_qa_[a-z0-9_]+\z/', $database)) {
    throw new RuntimeException('Use an empty disposable loopdeck_qa_* database');
}
$app = new think\App(dirname(__DIR__) . '/');
$runtime = sys_get_temp_dir() . '/loopdeck-legacy-' . bin2hex(random_bytes(6)) . '/';
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
define('WEB_ID', 1);
if (Db::query('SELECT DATABASE() AS db')[0]['db'] !== $database || Db::query('SHOW TABLES') !== []) {
    throw new RuntimeException('The disposable database must be empty');
}
$pdo = Db::connect()->getPdo();
$originalMode = Db::query('SELECT @@SESSION.sql_mode AS mode')[0]['mode'];
$pdo->exec(file_get_contents(dirname(__DIR__) . '/app/install/install.sql'));
Db::execute('ALTER TABLE cloud_kms ENGINE=MyISAM');
Db::execute('ALTER TABLE cloud_notice CONVERT TO CHARACTER SET utf8mb3');
Db::execute('ALTER TABLE cloud_notice ENGINE=MyISAM');
if (Db::query("SHOW INDEX FROM cloud_notice WHERE Key_name='idx_notice_site'") === []) {
    Db::execute('ALTER TABLE cloud_notice ADD KEY idx_notice_site (zid,type,sort,addtime)');
}
$pdo->exec('SET SESSION sql_mode = ' . $pdo->quote($originalMode));
Db::execute('ALTER TABLE cloud_users DROP INDEX udx_users_username');
foreach ([1, 2] as $uid) {
    Db::name('users')->insert(['uid' => $uid, 'web_id' => 1, 'username' => 'legacy-fixture',
        'password' => 'inert-fixture', 'quota' => 0]);
}
Session::set('user', Db::name('users')->find(1));
$failures = [];
$check = static function (bool $ok, string $name) use (&$failures): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n";
    if (!$ok) $failures[] = $name;
};
try {
    // The duplicate username makes one unrelated index migration fail.
    SchemaMaintenance::runIfDue();
    $engine = Db::query("SHOW TABLE STATUS LIKE 'cloud_kms'")[0]['Engine'];
    $check(strtoupper($engine) === 'INNODB', 'legacy card engine migrates despite an unrelated index failure');
    $notice = Db::query("SHOW TABLE STATUS LIKE 'cloud_notice'")[0];
    $check(strtoupper($notice['Engine']) === 'INNODB' && str_starts_with($notice['Collation'], 'utf8mb4'),
        'legacy indexed notice table converts engine and character set');
    Db::execute('ALTER TABLE cloud_users ADD CONSTRAINT qa_reject_grant CHECK (quota = 0)');
    foreach ([1, 2] as $round) {
        $code = 'qa-legacy-card-' . $round;
        Db::name('kms')->insert(['type' => 'quota', 'km' => $code, 'value' => '3', 'useid' => 0, 'zid' => 1]);
        $response = Kms::activate(['km' => $code])->getData();
        $check($response['code'] !== 1 && (int)Db::name('kms')->where('km', $code)->value('useid') === 0
            && (int)Db::name('users')->where('uid', 1)->value('quota') === 0, 'failed grant preserves unused card, round ' . $round);
    }
    Db::execute('ALTER TABLE cloud_users DROP CHECK qa_reject_grant');
    $check(Kms::activate(['km' => 'qa-legacy-card-1'])->getData()['code'] === 1
        && (int)Db::name('users')->where('uid', 1)->value('quota') === 3, 'failed card remains redeemable');
    Db::name('users')->where('uid', 2)->delete();
    SchemaMaintenance::runIfDue();
    $check(Db::query("SHOW INDEX FROM cloud_users WHERE Key_name='udx_users_username'") !== [],
        'failed migration retries after the underlying conflict is resolved');
    if ($failures !== []) {
        throw new RuntimeException(implode('; ', $failures));
    }
    echo "Legacy MySQL redemption rollback and independent migrations passed\n";
} finally {
    $resolved = realpath($runtime);
    if ($resolved !== false && str_starts_with($resolved, realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'loopdeck-legacy-')) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($resolved);
    }
}
