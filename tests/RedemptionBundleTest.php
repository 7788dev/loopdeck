<?php

declare(strict_types=1);

require __DIR__ . '/FunctionalDatabaseBootstrap.php';

use app\admin\controller\Ajax as AdminAjax;
use app\index\controller\Ajax;
use app\index\model\Kms;
use app\service\RedemptionPlan;
use think\facade\Db;
use think\facade\Session;

fixtureTable('kms', 'id INTEGER PRIMARY KEY AUTOINCREMENT, uid INTEGER, type TEXT, km TEXT, value TEXT,
    addtime TEXT, zid INTEGER, useid INTEGER DEFAULT 0, usetime TEXT');
Db::name('users')->where('uid', 1)->update(['quota' => 0]);

function bundleIssue(int $days, int $accounts, int $count = 1): array
{
    fixtureRequest(['vip_days' => (string)$days, 'account_limit' => (string)$accounts, 'num' => (string)$count]);
    $result = (new AdminAjax())->data('add', 'km')->getData();
    functionalCheck($result['code'] === 1, 'Bundle generation endpoint failed: ' . $result['message']);
    $codes = explode("\n", trim($result['data']['copy']));
    functionalCheck(count(array_unique($codes)) === $count, 'Batch contains duplicate or missing codes');
    functionalCheck($result['data']['count'] === $count, 'Issued count is incorrect');
    return $codes;
}

set_error_handler(static function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
});

$codes = bundleIssue(30, 7, 2);
$stored = Db::name('kms')->where('km', $codes[0])->find();
functionalCheck($stored['type'] === 'bundle', 'New cards must use the combined format');
functionalCheck(json_decode($stored['value'], true) === ['vip_days' => 30, 'account_limit' => 7],
    'Combined benefits were not persisted in the existing value column');
fixtureRequest(['km' => $codes[0]]);
$result = (new Ajax())->shop('activate')->getData();
functionalCheck($result['code'] === 1, 'Combined redemption endpoint failed');
functionalCheck($result['data']['account_limit'] === 7, 'Combined card did not set the account total');
$firstEnd = date('Y-m-d', strtotime('+30 day'));
functionalCheck($result['data']['vip_end'] === $firstEnd, 'Combined card did not grant membership days');
functionalCheck((int)Session::get('user.quota') === 7 && Session::get('user.vip_end') === $firstEnd,
    'Successful redemption did not refresh the session');
functionalCheck(Kms::activate(['km' => $codes[0]])->getData()['code'] !== 1, 'Combined code was redeemed twice');

Db::name('users')->where('uid', 1)->update(['vip_start' => '2026-01-01']);
functionalCheck(Kms::activate(['km' => $codes[1]])->getData()['code'] === 1, 'Renewal failed');
$renewed = Db::name('users')->find(1);
functionalCheck((int)$renewed['quota'] === 7, 'Renewing the same plan accumulated slots');
functionalCheck($renewed['vip_end'] === date('Y-m-d', strtotime('+30 day', strtotime($firstEnd))), 'Renewal lost remaining days');
functionalCheck($renewed['vip_start'] === '2026-01-01', 'Renewal reset the original membership start');

Db::name('users')->where('uid', 1)->update(['quota' => 20]);
functionalCheck(Kms::activate(['km' => bundleIssue(7, 3)[0]])->getData()['code'] === 1, 'Smaller plan failed');
functionalCheck((int)Db::name('users')->where('uid', 1)->value('quota') === 20, 'Smaller plan reduced existing slots');
functionalCheck(Kms::activate(['km' => bundleIssue(0, 7)[0]])->getData()['code'] === 1, 'Zero days did not grant permanent membership');
functionalCheck(Db::name('users')->where('uid', 1)->value('vip_end') === RedemptionPlan::PERMANENT_VIP_END,
    'Permanent membership was not persisted');
$unchanged = bundleIssue(0, 7)[0];
functionalCheck(Kms::activate(['km' => $unchanged])->getData()['code'] === 0, 'An unnecessary permanent code reported success');
functionalCheck((int)Db::name('kms')->where('km', $unchanged)->value('useid') === 0, 'An unnecessary code was consumed');
$before = Db::name('users')->find(1);
functionalCheck(Kms::activate(['km' => bundleIssue(0, 25)[0]])->getData()['code'] === 1, 'Permanent account upgrade failed');
functionalCheck(Db::name('users')->where('uid', 1)->value('vip_end') === $before['vip_end'], 'Permanent expiry overflowed');
$unlimited = Kms::activate(['km' => bundleIssue(3, 0)[0]])->getData();
functionalCheck($unlimited['code'] === 1, 'Zero accounts did not grant unlimited accounts');
functionalCheck((int)Db::name('users')->where('uid', 1)->value('quota') === RedemptionPlan::UNLIMITED_ACCOUNTS,
    'Unlimited account state was not persisted');
functionalCheck($unlimited['data']['account_limit_label'] === '不限' && $unlimited['data']['membership_label'] === '永久会员',
    'Permanent and unlimited labels expose storage values');
functionalCheck(Db::name('users')->where('uid', 1)->value('vip_end') === RedemptionPlan::PERMANENT_VIP_END,
    'Finite membership downgraded or overflowed permanent membership');
Db::name('users')->where('uid', 1)->update(['quota' => 25]);
Db::name('users')->where('uid', 1)->update(['vip_start' => '2020-01-01', 'vip_end' => '2020-02-01']);
functionalCheck(Kms::activate(['km' => bundleIssue(14, 7)[0]])->getData()['code'] === 1, 'Expired membership activation failed');
functionalCheck(Db::name('users')->where('uid', 1)->value('vip_end') === date('Y-m-d', strtotime('+14 day')),
    'Expired membership was extended from the obsolete expiry');

$base = ['vip_days' => '30', 'account_limit' => '7', 'num' => '1'];
$invalid = [[], ['type' => 'quota'] + $base];
foreach (['vip_days' => 3651, 'account_limit' => 100001, 'num' => 1001] as $field => $over) {
    foreach (['', '-1', '1.5', '1e2', 'abc', [], null, true, 1.5, $over, '999999999999999999999999'] as $value) {
        $invalid[] = array_replace($base, [$field => $value]);
    }
}
$invalid[] = array_replace($base, ['num' => '0']);
$count = Db::name('kms')->count();
foreach ($invalid as $payload) {
    fixtureRequest($payload);
    functionalCheck((new AdminAjax())->data('add', 'km')->getData()['code'] !== 1, 'Invalid generation input accepted');
}
functionalCheck(Db::name('kms')->count() === $count, 'Invalid requests created cards');
functionalCheck(RedemptionPlan::fromInput(['vip_days' => 3650, 'account_limit' => 100000]) !== null, 'Upper bounds rejected');
foreach (RedemptionPlan::presets() as $preset) {
    functionalCheck(RedemptionPlan::fromInput($preset) !== null, 'Preset exceeds server validation');
}

foreach (['{}', 'null', '[]', '{"vip_days":30}', '{"vip_days":-1,"account_limit":7}',
    '{"vip_days":3651,"account_limit":7}',
    '{"vip_days":30,"account_limit":100001}', '{"vip_days":30,"account_limit":"7"}',
    '{"vip_days":30,"account_limit":1.5}', '{"vip_days":30,"account_limit":7,"extra":1}'] as $index => $value) {
    $km = 'invalid-bundle-' . $index;
    Db::name('kms')->insert(['km' => $km, 'type' => 'bundle', 'value' => $value, 'zid' => 1]);
    functionalCheck(Kms::activate(['km' => $km])->getData()['code'] !== 1, 'Invalid stored benefits were granted');
    functionalCheck((int)Db::name('kms')->where('km', $km)->value('useid') === 0, 'Invalid card was consumed');
}

$retry = bundleIssue(30, 40)[0];
$before = Db::name('users')->find(1);
Db::execute("CREATE TRIGGER reject_grant BEFORE UPDATE OF quota ON qa_users BEGIN SELECT RAISE(FAIL, 'fixture failure'); END");
functionalCheck(Kms::activate(['km' => $retry])->getData()['code'] !== 1, 'Failed combined grant reported success');
functionalCheck(Db::name('users')->find(1) === $before, 'Failed combined grant changed one of the benefits');
functionalCheck((int)Db::name('kms')->where('km', $retry)->value('useid') === 0, 'Failed combined grant consumed the code');
Db::execute('DROP TRIGGER reject_grant');
functionalCheck(Kms::activate(['km' => $retry])->getData()['code'] === 1, 'Failed combined code could not be retried');

$forever = bundleIssue(0, 0)[0];
$before = Db::name('users')->find(1);
Db::execute("CREATE TRIGGER reject_unlimited BEFORE UPDATE OF quota ON qa_users BEGIN SELECT RAISE(FAIL, 'fixture failure'); END");
functionalCheck(Kms::activate(['km' => $forever])->getData()['code'] === 0, 'Failed permanent grant reported success');
functionalCheck(Db::name('users')->find(1) === $before, 'Failed permanent grant changed user benefits');
functionalCheck((int)Db::name('kms')->where('km', $forever)->value('useid') === 0, 'Failed permanent grant consumed the code');
Db::execute('DROP TRIGGER reject_unlimited');
functionalCheck(Kms::activate(['km' => $forever])->getData()['code'] === 1, 'Both-zero code failed');
functionalCheck(Session::get('user.vip_end') === RedemptionPlan::PERMANENT_VIP_END
    && (int)Session::get('user.quota') === RedemptionPlan::UNLIMITED_ACCOUNTS, 'Both-zero code did not refresh unlimited benefits');
$passedLogin = false;
(new app\middleware\CheckLoginUser())->handle($app->request, static function ($request) use (&$passedLogin) {
    $passedLogin = true;
    return response('ok');
});
functionalCheck($passedLogin && Session::get('user.vip_end') === RedemptionPlan::PERMANENT_VIP_END,
    'Login middleware revoked permanent membership');
functionalCheck(RedemptionPlan::description('bundle', '{"vip_days":0,"account_limit":0}') === '永久会员 · 账号数量不限',
    'Both-zero card description is incorrect');
foreach ([[0, 0], [30, 7]] as [$days, $accounts]) {
    $code = bundleIssue($days, $accounts)[0];
    functionalCheck(Kms::activate(['km' => $code])->getData()['code'] === 0, 'Redundant code was consumed by an unlimited user');
    functionalCheck((int)Db::name('kms')->where('km', $code)->value('useid') === 0, 'Redundant code lost its unused state');
}
Db::name('users')->where('uid', 1)->update(['vip_start' => date('Y-m-d'), 'vip_end' => $firstEnd]);
functionalCheck(Kms::activate(['km' => bundleIssue(30, 7)[0]])->getData()['code'] === 1, 'Unlimited account user could not renew finite membership');
functionalCheck((int)Db::name('users')->where('uid', 1)->value('quota') === RedemptionPlan::UNLIMITED_ACCOUNTS,
    'Finite plan lowered an unlimited account total');
Db::name('kms')->insert(['km' => 'quota-on-unlimited', 'type' => 'quota', 'value' => 3, 'zid' => 1]);
functionalCheck(Kms::activate(['km' => 'quota-on-unlimited'])->getData()['code'] === 0, 'Legacy quota downgraded unlimited accounts');
Db::name('kms')->insert(['km' => 'vip-on-unlimited', 'type' => 'vip', 'value' => 3, 'zid' => 1]);
functionalCheck(Kms::activate(['km' => 'vip-on-unlimited'])->getData()['code'] === 1, 'Legacy membership renewal failed with unlimited accounts');
functionalCheck((int)Db::name('users')->where('uid', 1)->value('quota') === RedemptionPlan::UNLIMITED_ACCOUNTS,
    'Legacy membership changed the account entitlement');

$count = Db::name('kms')->count();
$failAfter = $count + 500;
Db::execute("CREATE TRIGGER reject_batch BEFORE INSERT ON qa_kms WHEN (SELECT COUNT(*) FROM qa_kms) >= {$failAfter}
    BEGIN SELECT RAISE(FAIL, 'fixture batch failure'); END");
fixtureRequest(['vip_days' => '30', 'account_limit' => '7', 'num' => '501']);
functionalCheck((new AdminAjax())->data('add', 'km')->getData()['code'] !== 1, 'Partial batch reported success');
functionalCheck(Db::name('kms')->count() === $count, 'Failed batch left the first chunk stored');
Db::execute('DROP TRIGGER reject_batch');
bundleIssue(30, 7, 1000);
fixtureRequest(['start' => 0, 'length' => 2, 'page' => 1, 'search' => ['type' => 'bundle', 'status' => '0']]);
$list = Kms::getKmList();
functionalCheck(count($list['data']) === 2 && $list['total'] > 1000, 'Filtered pagination lost the total count');
functionalCheck($list['data'][0]['benefits'] === '会员 30 天 · 账号总数 7 个', 'Admin list did not display both benefits');

restore_error_handler();
echo "Combined redemption, renewal, input validation and atomic rollback tests passed\n";
