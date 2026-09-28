<?php

declare(strict_types=1);

require __DIR__ . '/FunctionalDatabaseBootstrap.php';

use app\admin\model\Accounts as AdminAccounts;
use app\index\model\Accounts;
use think\facade\Db;
use think\facade\Session;

fixtureTable('accounts', 'id INTEGER PRIMARY KEY AUTOINCREMENT, uid INTEGER, zid INTEGER, type TEXT,
    user_id TEXT, data TEXT, timing TEXT, state INTEGER DEFAULT 1, addtime TEXT');
fixtureTable('jobs', 'id INTEGER PRIMARY KEY AUTOINCREMENT, uid INTEGER, type TEXT, user_id TEXT');
fixtureTable('task_logs', 'id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, user_id TEXT');

foreach (['bilibili', 'heybox'] as $type) {
    Db::name('accounts')->insert(['uid' => 1, 'zid' => 1, 'type' => $type, 'user_id' => '42']);
    Db::name('jobs')->insert(['uid' => 1, 'type' => $type, 'user_id' => '42']);
    Db::name('task_logs')->insert(['type' => $type, 'user_id' => '42']);
}
Db::name('accounts')->insert(['uid' => 2, 'zid' => 2, 'type' => 'heybox', 'user_id' => '99']);
functionalCheck(!AdminAccounts::delByid(3), 'An archived site account was deleted');
functionalCheck(AdminAccounts::delByid(1), 'Account deletion failed');
functionalCheck(Db::name('accounts')->where('id', 2)->count() === 1, 'Deleting Bilibili also deleted same-number Heybox');
functionalCheck(Db::name('jobs')->where('type', 'heybox')->count() === 1, 'Other platform jobs were deleted');
functionalCheck(Db::name('task_logs')->where('type', 'heybox')->count() === 1, 'Other platform logs were deleted');
functionalCheck(Db::name('jobs')->where('type', 'bilibili')->count() === 0, 'Deleted account retained jobs');
functionalCheck(!AdminAccounts::delByid(1), 'Repeated deletion reported success');

$data = ['name' => 'fixture', 'alipay_url' => 'https://pay.example/a', 'qq_url' => 'https://pay.example/q', 'wechat_url' => 'wxp://fixture'];
functionalCheck(Accounts::addQrcode('qrcode', 'fixture', $data)->getData()['code'] === 1, 'QR save failed');
Session::set('user.uid', 2);
functionalCheck(Accounts::addQrcode('qrcode', 'fixture', $data)->getData()['code'] === 0, 'Public QR name collided across users');
Session::set('user.uid', 1);
Session::set('user.quota', 0);
functionalCheck(Accounts::addQrcode('qrcode', 'fixture', $data)->getData()['code'] === 1, 'Existing QR update incorrectly requires extra quota');
fixtureRequest($data + ['name' => 'new']);
fixtureRequest(array_replace($data, ['name' => 'new']));
$result = (new app\index\controller\Ajax())->qrcode('create')->getData();
functionalCheck($result['code'] === 0 && str_contains($result['message'], '配额'), 'QR creation reported success after quota rejection');
echo "Account lifecycle and QR persistence tests passed\n";

