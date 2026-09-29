<?php

declare(strict_types=1);

require __DIR__ . '/FunctionalDatabaseBootstrap.php';

use app\admin\controller\Ajax as AdminAjax;
use app\index\controller\Ajax;
use app\index\model\Kms;
use app\index\model\Users;
use think\facade\Db;
use think\facade\Session;

fixtureTable('kms', 'id INTEGER PRIMARY KEY AUTOINCREMENT, uid INTEGER, type TEXT, km TEXT, value INTEGER,
    addtime TEXT, zid INTEGER, useid INTEGER DEFAULT 0, usetime TEXT');
think\Validate::maker(static function ($validator) {
    $validator->extend('captcha', static fn($value) => $value === 'fixture-captcha');
});

foreach ([[0, 5, 0], [1, 0, 0], [1, 3, 3]] as $index => [$enabled, $gift, $expected]) {
    $app->config->set(['reg_free_quota' => $enabled, 'reg_free_quota_num' => $gift,
        'reg_free_money' => 1, 'reg_free_money_num' => 100], 'sys');
    $username = 'quotauser' . $index;
    $result = Users::reg(['username' => $username, 'password' => 'fixture-password',
        'qq' => '123450' . $index, 'captcha' => 'fixture-captcha']);
    functionalCheck($result->getData()['code'] === 1, 'Registration failed');
    $user = Db::name('users')->where('username', $username)->find();
    functionalCheck((int)$user['quota'] === $expected, 'Registration gift setting was not respected');
    functionalCheck((float)$user['money'] === 0.0, 'Legacy settings still grant balance');
}

fixtureRequest(['id' => 2, 'quota' => 5, 'state' => 1, 'qq' => '1234500', 'mail' => 'qa@example.invalid']);
functionalCheck((new AdminAjax())->data('set', 'user')->getData()['code'] === 1, 'Admin quota assignment failed');
functionalCheck((int)Db::name('users')->where('uid', 2)->value('quota') === 5, 'Admin quota assignment was not persisted');

Db::name('kms')->insertAll([
    ['type' => 'quota', 'value' => 3, 'km' => 'legacy-quota-1', 'zid' => 1],
    ['type' => 'quota', 'value' => 3, 'km' => 'legacy-quota-2', 'zid' => 1],
]);
functionalCheck(Db::name('kms')->count() === 2, 'Card batch was not stored');
$cards = Db::name('kms')->column('km');
Session::set('user', Db::name('users')->find(2));
fixtureRequest(['km' => $cards[0]]);
functionalCheck((new Ajax())->shop('activate')->getData()['code'] === 1, 'Card redemption endpoint failed');
functionalCheck((int)Db::name('users')->where('uid', 2)->value('quota') === 8, 'Quota card did not add its face value');
functionalCheck((int)Session::get('user.quota') === 8, 'Redemption did not refresh session quota');
functionalCheck(Kms::activate(['km' => $cards[0]])->getData()['code'] !== 1, 'Card was redeemed twice');

Db::execute("CREATE TRIGGER fail_quota BEFORE UPDATE OF quota ON qa_users BEGIN SELECT RAISE(FAIL, 'fixture grant failure'); END");
functionalCheck(Kms::activate(['km' => $cards[1]])->getData()['code'] !== 1, 'Failed grant reported success');
functionalCheck((int)Db::name('kms')->where('km', $cards[1])->value('useid') === 0, 'Failed grant consumed the card');
functionalCheck((int)Db::name('users')->where('uid', 2)->value('quota') === 8, 'Failed grant changed quota');
Db::execute('DROP TRIGGER fail_quota');
functionalCheck(Kms::activate(['km' => $cards[1]])->getData()['code'] === 1, 'Failed card could not be retried');

Session::set('user', Db::name('users')->find(3));
functionalCheck(Kms::activate(['km' => $cards[0]])->getData()['code'] !== 1, 'A second user reused the same card');
functionalCheck((int)Db::name('users')->where('uid', 3)->value('quota') === 0, 'Reused card granted quota');
Db::name('kms')->insert(['km' => 'foreign-card', 'type' => 'quota', 'value' => 3, 'zid' => 2]);
functionalCheck(Kms::activate(['km' => 'foreign-card'])->getData()['code'] !== 1, 'Foreign-site card was redeemed');
Db::name('kms')->insert(['km' => 'old-money-card', 'type' => 'money', 'value' => 10, 'zid' => 1]);
functionalCheck(Kms::activate(['km' => 'old-money-card'])->getData()['code'] !== 1, 'Legacy balance card was redeemed');

Session::set('user', Db::name('users')->find(1));
Db::name('kms')->insert(['type' => 'vip', 'value' => 3, 'km' => 'legacy-vip', 'zid' => 1]);
$vipCard = Db::name('kms')->where('type', 'vip')->value('km');
Session::set('user', Db::name('users')->find(3));
functionalCheck(Kms::activate(['km' => $vipCard])->getData()['code'] === 1, 'Membership card redemption failed');
functionalCheck(strtotime((string)Db::name('users')->where('uid', 3)->value('vip_end')) > time(),
    'Membership card did not grant time');

fixtureRequest(['id' => 2, 'quota_unlimited' => '1', 'vip_permanent' => '1', 'state' => 1]);
functionalCheck((new AdminAjax())->data('set', 'user')->getData()['code'] === 1, 'Admin unlimited entitlement edit failed');
functionalCheck((int)Db::name('users')->where('uid', 2)->value('quota') === app\service\RedemptionPlan::UNLIMITED_ACCOUNTS,
    'Admin edit did not preserve unlimited accounts');
functionalCheck(Db::name('users')->where('uid', 2)->value('vip_end') === app\service\RedemptionPlan::PERMANENT_VIP_END,
    'Admin edit did not preserve permanent membership');
fixtureRequest(['id' => 2, 'quota' => 0, 'state' => 1]);
functionalCheck((new AdminAjax())->data('set', 'user')->getData()['code'] === 1, 'Admin entitlement reset failed');
functionalCheck((int)Db::name('users')->where('uid', 2)->value('quota') === 0
    && Db::name('users')->where('uid', 2)->value('vip_end') === null, 'Admin zero slots accidentally grant unlimited membership');

echo "Registration gifts, administrator quotas and redemption workflow tests passed\n";
