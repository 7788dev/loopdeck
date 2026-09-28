<?php

declare(strict_types=1);

require __DIR__ . '/FunctionalDatabaseBootstrap.php';

use app\index\model\Users;
use think\facade\Db;
use think\facade\Session;

$app->instance('cache', new class {
    private array $data = [];
    public function get($key, $default = null) { return $this->data[$key] ?? $default; }
    public function set($key, $value, $ttl = null) { $this->data[$key] = $value; }
    public function delete($key) { unset($this->data[$key]); }
});
fixtureTable('captcha', 'id INTEGER PRIMARY KEY AUTOINCREMENT, type INTEGER, send TEXT, code TEXT, time INTEGER, status INTEGER DEFAULT 0, ip TEXT');
think\Validate::maker(static function ($validator) {
    $validator->extend('captcha', static fn($value) => $value === 'fixture-captcha');
});
$registration = ['username' => 'newuser', 'password' => 'fixture-password', 'qq' => '1234567', 'captcha' => 'fixture-captcha'];
functionalCheck(Users::reg(array_replace($registration, ['captcha' => 'wrong']))->getData()['code'] !== 1, 'Wrong captcha accepted during registration');
functionalCheck(Users::reg($registration)->getData()['code'] === 1, 'Valid registration failed');
functionalCheck(Users::reg($registration)->getData()['code'] !== 1, 'Duplicate username registered');
functionalCheck(Users::login(['username' => 'newuser', 'password' => 'fixture-password'])->getData()['code'] === 1, 'Registered user cannot log in');
functionalCheck(Session::get('user.username') === 'newuser', 'Login did not set session owner');
functionalCheck(Users::logout()->getData()['code'] === 1 && !Session::has('user'), 'Logout retained the session');
Session::set('user', Db::name('users')->where('uid', 1)->find());
Db::name('users')->where('uid', 1)->update(['mail' => 'fixture@example.com', 'nickname' => 'QQ1234567890', 'qq' => '1234567890', 'password' => password_hash('fixture-old-password', PASSWORD_DEFAULT)]);
$profile = ['nickname' => 'QQ1234567890', 'qq' => '1234567890', 'mail' => 'fixture@example.com'];
functionalCheck((new app\index\validate\Users())->scene('profile')->check($profile), 'Default generated nickname cannot be saved');
fixtureRequest($profile);
functionalCheck((new app\index\controller\Ajax())->user('profile')->getData()['code'] === 1, 'Unchanged profile was reported as failure');
Db::name('users')->insert(['uid' => 3, 'mail' => 'other@example.com']);
fixtureRequest(array_replace($profile, ['mail' => 'other@example.com']));
functionalCheck((new app\index\controller\Ajax())->user('profile')->getData()['code'] === 0, 'Duplicate recovery email accepted');
functionalCheck(app\index\model\Captcha::send_captcha('invalid', '', '')['code'] === 0, 'Invalid email reported success');
$sidBefore = Db::name('users')->where('uid', 1)->value('sid');
functionalCheck(Users::findPass(['mail' => 'fixture@example.com', 'captcha' => 'fixture-captcha'])->getData()['code'] !== 1, 'Unconfigured email sender reported success');
functionalCheck(Db::name('users')->where('uid', 1)->value('sid') === $sidBefore, 'Failed recovery email logged out the user');
functionalCheck(Db::name('captcha')->where('type', 2)->count() === 0, 'Failed recovery email minted a token');

$token = str_repeat('a', 48);
$reset = ['mail' => 'fixture@example.com', 'password' => 'fixture-new-password', 'repass' => 'fixture-new-password', 'token' => $token];
Db::name('captcha')->insert(['type' => 2, 'send' => $reset['mail'], 'code' => $token, 'time' => time() - 2000]);
functionalCheck(Users::reset($reset)->getData()['code'] !== 1, 'Expired reset link accepted');
Db::name('captcha')->where('id', 1)->update(['time' => time()]);
functionalCheck(Users::reset(array_replace($reset, ['token' => 'wrong']))->getData()['code'] !== 1, 'Wrong reset token accepted');
Db::execute("CREATE TRIGGER fail_password BEFORE UPDATE OF password ON qa_users BEGIN SELECT RAISE(FAIL, 'fixture password failure'); END");
functionalCheck(Users::reset($reset)->getData()['code'] === 0, 'Password write failure returned success');
functionalCheck((int)Db::name('captcha')->value('status') === 0, 'Password write failure consumed reset token');
Db::execute('DROP TRIGGER fail_password');
functionalCheck(Users::reset($reset)->getData()['code'] === 1, 'Valid reset failed');
functionalCheck(password_verify($reset['password'], Db::name('users')->where('uid', 1)->value('password')), 'Reset did not update the password');
functionalCheck(!Session::has('user'), 'Reset retained an old authenticated session');
functionalCheck(Users::reset($reset)->getData()['code'] !== 1, 'Reset token was replayed');
for ($i = 0; $i < 8; $i++) {
    functionalCheck(Users::login(['username' => 'fixture', 'password' => 'wrong-password'])->getData()['code'] !== 1, 'Wrong password accepted');
}
functionalCheck(str_contains(Users::login(['username' => 'fixture', 'password' => $reset['password']])->getData()['message'], '频繁'), 'Login attempts were not throttled');
echo "Authentication, profile and password recovery tests passed\n";

