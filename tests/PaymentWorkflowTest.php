<?php

declare(strict_types=1);

require __DIR__ . '/FunctionalDatabaseBootstrap.php';

use app\index\model\Pays;
use app\index\model\Kms;
use think\facade\Db;

fixtureTable('pays', 'id INTEGER PRIMARY KEY AUTOINCREMENT, uid INTEGER, qq TEXT, orderid TEXT, addtime TEXT,
    name TEXT, money DECIMAL, type TEXT, shop TEXT, shopid TEXT, zid INTEGER, status INTEGER DEFAULT 0, endtime TEXT');
fixtureTable('kms', 'id INTEGER PRIMARY KEY AUTOINCREMENT, uid INTEGER, type TEXT, km TEXT, value INTEGER,
    addtime TEXT, zid INTEGER, useid INTEGER DEFAULT 0, usetime TEXT');
fixtureTable('order', 'trade_no TEXT PRIMARY KEY, uid INTEGER, type TEXT, orderid TEXT, time TEXT,
    name TEXT, money DECIMAL, status INTEGER, zid INTEGER');
$app->config->set(['quota_price_1' => 2, 'vip_price_1' => 5, 'is_alipay' => 0], 'sys');
functionalCheck(Pays::Submit_Pay([])->getData()['code'] === 0, 'Missing purchase input accepted');
functionalCheck(Pays::Submit_Pay(['shop' => 'money', 'shopid' => '10', 'pay_type' => 'alipay'])->getData()['code'] === 0, 'Disabled payment channel accepted');
functionalCheck(Pays::YpayQuota(['shop' => 'quota', 'shopid' => 1])->getData()['code'] === 1, 'Balance quota purchase failed');
functionalCheck((float)Db::name('users')->value('money') === 98.0, 'Balance was not debited');
functionalCheck((int)Db::name('users')->value('quota') > 10, 'Quota was not granted');
Db::execute("CREATE TRIGGER fail_quota BEFORE UPDATE OF quota ON qa_users BEGIN SELECT RAISE(FAIL, 'fixture grant failure'); END");
functionalCheck(Pays::YpayQuota(['shop' => 'quota', 'shopid' => 1])->getData()['code'] === 0, 'Failed grant reported success');
functionalCheck((float)Db::name('users')->value('money') === 98.0, 'Failed grant consumed balance');
Db::execute('DROP TRIGGER fail_quota');

$generated = Kms::admin_add(['type' => 'quota', 'value' => '1', 'num' => '2']);
functionalCheck($generated->getData()['code'] === 1, 'Custom-prefix card generation failed');
functionalCheck(Db::name('kms')->count() === 2, 'Card batch was not stored');
$card = Db::name('kms')->value('km');
functionalCheck(Kms::activate(['km' => $card])->getData()['code'] === 1, 'Card redemption failed');
functionalCheck(Kms::activate(['km' => $card])->getData()['code'] !== 1, 'Card was redeemed twice');

$gateway = ['key' => 'fixture-only-key', 'apiurl' => 'https://pay.example/'];
$callback = ['out_trade_no' => 'fixture-order', 'money' => '10.00', 'trade_status' => 'TRADE_SUCCESS', 'trade_no' => 'trade-1'];
$core = new epay\AliPayCore();
$callback['sign'] = md5($core->createLinkstring($core->argSort($callback)) . $gateway['key']);
Db::name('pays')->insert(['orderid' => 'fixture-order', 'uid' => 1, 'shop' => 'money', 'shopid' => '10',
    'money' => 10, 'type' => 'alipay', 'name' => '充值', 'zid' => 1]);
$before = (float)Db::name('users')->value('money');
Db::execute("CREATE TRIGGER fail_payment BEFORE INSERT ON qa_order BEGIN SELECT RAISE(FAIL, 'fixture receipt failure'); END");
$settled = app\service\PaymentSettlement::settle('money', $callback, $gateway);
functionalCheck(!$settled['ok'], 'Receipt failure returned a successful payment');
functionalCheck((float)Db::name('users')->value('money') === $before, 'Receipt failure retained the balance credit');
functionalCheck((int)Db::name('pays')->value('status') === 0, 'Failed payment cannot be retried');
Db::execute('DROP TRIGGER fail_payment');
$settled = app\service\PaymentSettlement::settle('money', $callback, $gateway);
functionalCheck($settled['ok'] && $settled['applied'], 'Valid signed payment did not settle');
functionalCheck((float)Db::name('users')->value('money') === $before + 10, 'Payment did not credit balance');
$replay = app\service\PaymentSettlement::settle('money', $callback, $gateway);
functionalCheck($replay['ok'] && !$replay['applied'], 'Repeated callback credited twice');
functionalCheck((float)Db::name('users')->value('money') === $before + 10, 'Repeated callback changed balance');
functionalCheck(!app\service\PaymentSettlement::settle('vip', $callback, $gateway)['ok'], 'Callback granted a different product');
$callback['money'] = '100';
functionalCheck(!app\service\PaymentSettlement::settle('money', $callback, $gateway)['ok'], 'Tampered signature accepted');
echo "Payment balance, rollback and custom-prefix card tests passed\n";
