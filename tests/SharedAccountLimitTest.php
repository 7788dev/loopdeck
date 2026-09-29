<?php

declare(strict_types=1);

require __DIR__ . '/FunctionalDatabaseBootstrap.php';

use app\index\model\Accounts;
use think\facade\Db;
use think\facade\Session;
use app\service\RedemptionPlan;

fixtureTable('accounts', 'id INTEGER PRIMARY KEY AUTOINCREMENT, uid INTEGER, zid INTEGER, type TEXT,
    user_id TEXT, data TEXT, timing TEXT, state INTEGER DEFAULT 1, addtime TEXT');
fixtureTable('tasks', 'id INTEGER PRIMARY KEY, type TEXT, execute_name TEXT, name TEXT, describe TEXT, icon TEXT,
    execute_url TEXT, execute_rate INTEGER, more INTEGER, state INTEGER, vip INTEGER, time TEXT, "order" INTEGER');
fixtureTable('jobs', 'id INTEGER PRIMARY KEY, uid INTEGER, type TEXT, user_id TEXT, do TEXT, state INTEGER, nextExecute INTEGER');
Db::name('users')->where('uid', 1)->update(['quota' => 7]);
Session::set('user.quota', 100); // A stale browser must not bypass the actual total.

foreach (['bilibili', 'bilibili', 'netease', 'netease', 'netease', 'netease', 'netease'] as $index => $type) {
    $result = Accounts::add($type, 'mixed-' . $index, ['nickname' => 'fixture'])->getData();
    functionalCheck($result['code'] === 1, 'Mixed-platform slot rejected: ' . $result['message']);
}
functionalCheck(Accounts::getMyAccountNum() === 7, 'Mixed platforms were not counted together');
foreach (['netease', 'bilibili', 'heybox'] as $type) {
    functionalCheck(Accounts::add($type, 'eighth', [])->getData()['code'] === 0,
        'A platform exceeded the shared account total');
}
functionalCheck(Accounts::addQrcode('qrcode', 'eighth', [])->getData()['code'] === 0, 'QR entry bypassed the total');
Db::name('accounts')->where('uid', 1)->update(['state' => 0]);
functionalCheck(Accounts::add('netease', 'eighth', [])->getData()['code'] === 0, 'Disabled accounts stopped occupying slots');
$jobs = Db::name('jobs')->count();
functionalCheck(Accounts::add('netease', 'mixed-2', ['nickname' => 'updated'])->getData()['code'] === 1,
    'Refreshing an existing account incorrectly needs another slot');
functionalCheck(Accounts::getMyAccountNum() === 7 && Db::name('jobs')->count() === $jobs,
    'Account refresh duplicated accounts or jobs');
functionalCheck(Accounts::delByUserId('bilibili', 'mixed-0') === 1, 'Unable to free an account slot');
functionalCheck(Accounts::add('netease', 'replacement', [])->getData()['code'] === 1,
    'Freed slot could not be reused by another platform');

Db::name('users')->insert(['uid' => 2, 'quota' => 7, 'username' => 'second', 'web_id' => 1]);
Session::set('user', ['uid' => 2, 'quota' => 0]); // Fresh database grant beats stale session.
for ($index = 0; $index < 7; $index++) {
    functionalCheck(Accounts::add('netease', 'only-music-' . $index, [])->getData()['code'] === 1,
        'Seven slots could not all be used by NetEase');
}
functionalCheck(Accounts::getMyAccountNum() === 7, 'Another user consumed this user\'s total');
functionalCheck(Accounts::add('bilibili', 'eighth', [])->getData()['code'] === 0, 'Single-platform user exceeded total');

Db::name('users')->where('uid', 2)->update(['quota' => 8]);
Db::name('tasks')->insert(['type' => 'heybox', 'execute_name' => 'sign', 'state' => 1, 'vip' => 0, 'order' => 1]);
Db::execute("CREATE TRIGGER reject_job BEFORE INSERT ON qa_jobs BEGIN SELECT RAISE(FAIL, 'fixture task failure'); END");
functionalCheck(Accounts::add('heybox', 'failed-job', [])->getData()['code'] === 0, 'Failed job creation reported account success');
functionalCheck(Accounts::getMyAccountNum() === 7, 'Failed job creation consumed an account slot');
Db::execute('DROP TRIGGER reject_job');
functionalCheck(Accounts::add('heybox', 'failed-job', [])->getData()['code'] === 1, 'Rolled-back account could not be retried');

Db::name('users')->where('uid', 2)->update(['quota' => RedemptionPlan::UNLIMITED_ACCOUNTS]);
Session::set('user.quota', 0);
for ($index = 0; $index < 12; $index++) {
    functionalCheck(Accounts::add($index % 2 ? 'netease' : 'bilibili', 'unlimited-' . $index, [])->getData()['code'] === 1,
        'Unlimited account user was blocked by an old finite total');
}
functionalCheck(Accounts::addQrcode('qrcode', 'unlimited-qr', [])->getData()['code'] === 1, 'Unlimited QR account was blocked');
Db::name('users')->where('uid', 2)->update(['quota' => 0]);
functionalCheck(Accounts::add('netease', 'zero-is-empty', [])->getData()['code'] === 0,
    'Existing zero-slot user was unintentionally upgraded to unlimited');

echo "Shared cross-platform account totals, stale sessions and account rollback tests passed\n";
