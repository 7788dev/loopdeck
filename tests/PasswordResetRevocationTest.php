<?php

declare(strict_types=1);

require __DIR__ . '/FunctionalDatabaseBootstrap.php';

use app\index\model\Users;
use think\facade\Db;
use think\facade\Session;

fixtureTable('captcha', 'id INTEGER PRIMARY KEY AUTOINCREMENT, type INTEGER, send TEXT, code TEXT,
    time INTEGER, status INTEGER DEFAULT 0, ip TEXT');
Db::name('users')->insert(['uid' => 2, 'web_id' => 1, 'username' => 'second']);

function seedResetRevocation(): void
{
    Db::name('captcha')->delete(true);
    foreach ([1, 2] as $uid) {
        Db::name('users')->where('uid', $uid)->update(['mail' => 'user' . $uid . '@example.invalid',
            'password' => password_hash('old-password', PASSWORD_DEFAULT), 'sid' => 'session-' . $uid]);
        foreach (['older', 'latest'] as $suffix) {
            Db::name('captcha')->insert(['type' => 2, 'send' => 'user' . $uid . '@example.invalid',
                'code' => 'token-' . $uid . '-' . $suffix, 'time' => time(), 'status' => 0]);
        }
    }
    Db::name('captcha')->insert(['type' => 1, 'send' => 'user1@example.invalid', 'code' => '123456', 'time' => time()]);
    Session::set('user', Db::name('users')->where('uid', 1)->find());
}

function resetWithOldToken(int $uid): array
{
    return Users::reset(['mail' => 'user' . $uid . '@example.invalid', 'token' => 'token-' . $uid . '-latest',
        'password' => 'token-password', 'repass' => 'token-password'])->getData();
}

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$change = ['outpass' => 'old-password', 'password' => 'new-password', 'repass' => 'new-password'];

seedResetRevocation();
$response = Users::changePassWord(1, $change)->getData();
$check($response['code'] === 1 && !Session::has('user'), 'Normal password change failed or retained its session');
$check(Db::name('captcha')->where('send', 'user1@example.invalid')->where('type', 2)->where('status', 0)->count() === 0,
    'User password change left unused recovery tokens valid');
$check(resetWithOldToken(1)['code'] !== 1, 'An old recovery token overwrote the user-changed password');
$check(Db::name('captcha')->where('send', 'user2@example.invalid')->where('status', 0)->count() === 2,
    'Password change invalidated another user recovery tokens');
$check(Db::name('captcha')->where('type', 1)->value('status') === 0, 'Password change invalidated an unrelated email code');

seedResetRevocation();
$response = Users::changePassWord(1, array_replace($change, ['outpass' => 'wrong-password']))->getData();
$check($response['code'] !== 1 && resetWithOldToken(1)['code'] === 1,
    'A rejected password change made normal recovery unusable');

seedResetRevocation();
$check(Users::adminUpdateByUid(2, ['password' => password_hash('admin-password', PASSWORD_DEFAULT), 'sid' => null]),
    'Administrator password change failed');
$check(resetWithOldToken(2)['code'] !== 1, 'An old recovery token overwrote the administrator-changed password');
$check(Db::name('captcha')->where('send', 'user1@example.invalid')->where('type', 2)->where('status', 0)->count() === 2,
    'Administrator password change invalidated an unrelated user tokens');

seedResetRevocation();
$check(Users::adminUpdateByUid(2, ['quota' => 8]), 'Ordinary administrator edit failed');
$check(resetWithOldToken(2)['code'] === 1, 'An ordinary administrator edit invalidated password recovery');

seedResetRevocation();
Users::changePassWord(1, $change);
Db::name('captcha')->insert(['type' => 2, 'send' => 'user1@example.invalid', 'code' => 'fresh-token', 'time' => time()]);
$fresh = Users::reset(['mail' => 'user1@example.invalid', 'token' => 'fresh-token',
    'password' => 'fresh-password', 'repass' => 'fresh-password'])->getData();
$check($fresh['code'] === 1, 'A recovery token issued after password change was rejected');

foreach (['user', 'admin'] as $actor) {
    seedResetRevocation();
    Db::execute("CREATE TRIGGER fail_revocation BEFORE UPDATE OF status ON qa_captcha "
        . "BEGIN SELECT RAISE(FAIL, 'fixture revocation failure'); END");
    try {
        $response = $actor === 'user' ? Users::changePassWord(1, $change)->getData()['code'] === 1
            : Users::adminUpdateByUid(1, ['password' => password_hash('admin-password', PASSWORD_DEFAULT), 'sid' => null]);
        $check(!$response, $actor . ' password change reported success when token invalidation failed');
        $check(password_verify('old-password', Db::name('users')->where('uid', 1)->value('password')),
            $actor . ' password write was not rolled back when token invalidation failed');
        $check(Session::has('user'), $actor . ' failed change destroyed the active session');
    } catch (Throwable $exception) {
        $failures[] = $actor . ' password change leaked the invalidation error: ' . $exception->getMessage();
    } finally {
        Db::execute('DROP TRIGGER fail_revocation');
    }
}

functionalCheck($failures === [], implode("\n", $failures));
echo "Password reset revocation and atomic credential updates passed\n";
