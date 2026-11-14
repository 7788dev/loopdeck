<?php

declare(strict_types=1);

require __DIR__ . '/FunctionalDatabaseBootstrap.php';

use app\index\controller\Ajax;
use app\index\model\Accounts;
use think\facade\Db;
use think\facade\Session;

fixtureTable('accounts', 'id INTEGER PRIMARY KEY AUTOINCREMENT, uid INTEGER, zid INTEGER, type TEXT,
    user_id TEXT, data TEXT, timing TEXT, state INTEGER DEFAULT 1, addtime TEXT');
Db::name('users')->where('uid', 1)->update(['quota' => 0]);
$directory = (string)config('app.task_lock_directory');
$handles = [];

try {
    for ($i = 0; $i < 768; $i++) {
        fixtureRequest(['name' => 'quota-limit-' . $i, 'alipay_url' => 'https://pay.example/a',
            'qq_url' => 'https://pay.example/q', 'wechat_url' => 'wxp://fixture']);
        $result = (new Ajax())->qrcode('create')->getData();
        functionalCheck($result['code'] === 0 && str_contains($result['message'], '配额'), 'Expected an actual quota rejection');
    }
    $files = glob($directory . '/*.lock') ?: [];
    functionalCheck(count($files) > 0 && count($files) <= 256,
        'Rejected account names created ' . count($files) . ' persistent lock files; expected at most 256');
    functionalCheck(Db::name('accounts')->count() === 0, 'Rejected additions persisted account data');

    // Hold the real files without knowing the key mapping: the same binding
    // must be refused while its shared lock is owned by another handle.
    foreach ($files as $file) {
        $handle = fopen($file, 'r+b');
        functionalCheck(is_resource($handle) && flock($handle, LOCK_EX | LOCK_NB), 'Cannot hold an isolated binding lock');
        $handles[] = $handle;
    }
    Db::name('users')->where('uid', 1)->update(['quota' => 10]);
    $busy = Accounts::addQrcode('qrcode', 'quota-limit-0', [])->getData();
    functionalCheck($busy['code'] === 0 && str_contains($busy['message'], '正在保存'), 'Binding bypassed an owned shared lock');
    foreach ($handles as $handle) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
    $handles = [];
    functionalCheck(Accounts::addQrcode('qrcode', 'quota-limit-0', [])->getData()['code'] === 1, 'Binding did not resume after release');
    Db::name('users')->insert(['uid' => 2, 'web_id' => 1, 'quota' => 10]);
    Session::set('user.uid', 2);
    functionalCheck(Accounts::addQrcode('qrcode', 'quota-limit-0', [])->getData()['code'] === 0, 'Shared binding allowed a second owner');
    functionalCheck(Db::name('accounts')->count() === 1, 'Account ownership changed unexpectedly');
    echo "Bounded account binding locks and ownership checks passed\n";
} finally {
    foreach ($handles as $handle) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
    $resolved = realpath($directory);
    functionalCheck(is_string($resolved) && str_starts_with($resolved,
        realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'loopdeck-test-locks-'), 'Unsafe lock fixture cleanup path');
    foreach (glob($resolved . '/*.lock') ?: [] as $file) {
        unlink($file);
    }
    rmdir($resolved);
}
