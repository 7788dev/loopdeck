<?php

declare(strict_types=1);

require __DIR__ . '/FunctionalDatabaseBootstrap.php';

use app\index\controller\LoginAjax;
use app\index\model\Users;
use think\facade\Cache;
use think\facade\Db;

$temporary = sys_get_temp_dir() . '/loopdeck-registration-network-' . bin2hex(random_bytes(6));
$app->setRuntimePath($temporary . '/runtime/');
$app->instance('cache', new think\cache\driver\File($app, ['path' => $temporary . '/cache/']));
$app->config->set(['reg_close' => 0, 'reg_free_vip' => 0, 'reg_free_quota' => 0], 'sys');
think\Validate::maker(static function ($validator): void {
    $validator->extend('captcha', static fn($value): bool => $value === 'fixture-captcha');
});

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$register = static function (string $username, string $qq, string $captcha = 'fixture-captcha'): array {
    fixtureRequest(['username' => $username, 'password' => 'fixture-password', 'qq' => $qq, 'captcha' => $captcha]);
    return (new LoginAjax())->reg()->getData();
};
$previousProxies = getenv('TRUSTED_PROXIES');
$previousServer = $_SERVER;

try {
    putenv('TRUSTED_PROXIES');
    unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    Users::logout();

    // Cover fresh installations and both values retained by old installations.
    foreach ([null, '0', '1'] as $index => $legacyValue) {
        $app->config->set(['reg_iplimit' => $legacyValue], 'sys');
        $originalIp = '198.51.100.' . (10 + $index * 2);
        $otherIp = '198.51.100.' . (11 + $index * 2);
        $owner = 'network' . $index . 'owner';
        $peer = 'network' . $index . 'peer';
        $neighbor = 'network' . $index . 'neighbor';
        $ownerQq = (string)(1234500 + $index * 3);
        $peerQq = (string)(1234501 + $index * 3);
        $neighborQq = (string)(1234502 + $index * 3);

        $_SERVER['REMOTE_ADDR'] = $originalIp;
        functionalCheck($register($owner, $ownerQq)['code'] === 1, 'Initial registration failed');
        $shared = $register($peer, $peerQq);
        $check($shared['code'] === 1,
            'Normal shared-IP registration was blocked with legacy setting ' . var_export($legacyValue, true)
            . ': ' . $shared['message']);

        $_SERVER['REMOTE_ADDR'] = $otherIp;
        fixtureRequest(['username' => $owner, 'password' => 'fixture-password']);
        functionalCheck((new LoginAjax())->login()->getData()['code'] === 1, 'Login after changing IP failed');
        functionalCheck(Db::name('users')->where('username', $owner)->value('login_ip') === $otherIp,
            'Successful login stopped recording the current IP');
        Users::logout();

        $newNetwork = $register($neighbor, $neighborQq);
        $check($newNetwork['code'] === 1, 'A login incorrectly blocked registration on the new network: '
            . $newNetwork['message']);

        // Replay the original bypass: changing networks cannot turn a duplicate
        // submission into a new account. The first normal submission already succeeded.
        $_SERVER['REMOTE_ADDR'] = $originalIp;
        $repeat = $register($peer, $peerQq);
        $check($repeat['message'] === '用户名已存在', 'Changing login IP changed the outcome of the same registration: '
            . $repeat['message']);
        $check(Db::name('users')->whereIn('username', [$owner, $peer, $neighbor])->count() === 3,
            'Normal accounts were not persisted independently');
    }

    $_SERVER['REMOTE_ADDR'] = '2001:db8::123';
    functionalCheck($register('ipv6owner', '1234600')['code'] === 1, 'Initial IPv6 registration failed');
    $check($register('ipv6peer', '1234601')['code'] === 1, 'Shared IPv6 users were blocked');

    putenv('TRUSTED_PROXIES=10.0.0.0/8');
    $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.80';
    functionalCheck($register('proxyowner', '1234700')['code'] === 1, 'Initial proxied registration failed');
    $check($register('proxypeer', '1234701')['code'] === 1, 'Users sharing a trusted proxy client IP were blocked');
    functionalCheck(Db::name('users')->where('username', 'proxyowner')->value('login_ip') === '203.0.113.80',
        'Registration stopped recording the real client IP');

    // Failed logins by somebody sharing the network do not block new registrations.
    Cache::set('login_fail_i_' . sha1(real_ip()), 8, 900);
    Cache::set('login_fail_u_' . sha1('throttlepeer'), 8, 900);
    $check($register('throttlepeer', '1234800')['code'] === 1, 'Login failure counters blocked registration');
    fixtureRequest(['username' => 'proxyowner', 'password' => 'fixture-password']);
    functionalCheck(str_contains((new LoginAjax())->login()->getData()['message'], '频繁'),
        'Removing registration IP restrictions disabled login protection');

    $beforeInvalid = Db::name('users')->count();
    $check($register('badcaptchapeer', '1234801', 'wrong')['code'] !== 1, 'Wrong registration captcha was accepted');
    $duplicateQq = $register('duplicateqqpeer', '1234700');
    $check($duplicateQq['message'] === '该QQ已存在', 'Duplicate QQ did not reach the account-identity check: '
        . $duplicateQq['message']);
    $app->config->set(['reg_close' => 1], 'sys');
    $check($register('closedregpeer', '1234802')['message'] === '已关闭注册', 'Registration closure stopped working');
    $check(Db::name('users')->count() === $beforeInvalid, 'Rejected registrations wrote users');
} finally {
    putenv($previousProxies === false ? 'TRUSTED_PROXIES' : 'TRUSTED_PROXIES=' . $previousProxies);
    $_SERVER = $previousServer;
}

functionalCheck($failures === [], implode(PHP_EOL, $failures));
echo "Registration network isolation tests passed: shared IPs, changed networks, legacy settings and retained validation\n";
