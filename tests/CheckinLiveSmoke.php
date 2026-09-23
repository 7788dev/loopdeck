<?php

declare(strict_types=1);

/**
 * Explicit, read-only online check of the check-in protocols. Never part of
 * the offline suite (the file name does not end in Test.php).
 *
 * Without credentials it confirms each endpoint still answers in the shape the
 * adapter expects (an invalid credential must be reported as account-invalid,
 * the 天翼 login page must still carry its parameters). With credentials from
 * the process environment it logs in and reads the overview; it never signs in.
 *
 *   TIEBA_BDUSS                     百度贴吧 BDUSS
 *   QUARK_COOKIE, QUARK_APP_URL     夸克网页 Cookie 与 App 签到请求地址
 *   TIANYI_USERNAME, TIANYI_PASSWORD 天翼账号（登录会创建一个新会话）
 *   ALIYUNDRIVE_REFRESH_TOKEN       阿里云盘 Refresh Token；验证会轮换令牌，
 *   ALIYUNDRIVE_ALLOW_ROTATE=1      必须同时设置，旧令牌随后失效且新令牌不会打印
 *
 * Credentials and tokens are never printed.
 */

use app\service\PlatformException;
use app\service\PlatformRegistry;
use app\service\PlatformVerification;

require dirname(__DIR__) . '/vendor/autoload.php';

$failures = 0;
$report = static function (string $platform, string $check, bool $ok, string $detail = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    echo sprintf("[%s] %s %s%s\n", $ok ? '通过' : '失败', $platform, $check, $detail !== '' ? '：' . $detail : '');
};
$expectInvalid = static function (string $platform, string $check, callable $operation) use ($report): void {
    try {
        $operation();
        $report($platform, $check, false, '无效凭据未被拒绝');
    } catch (PlatformException $exception) {
        $report($platform, $check, $exception->accountInvalid, $exception->getMessage());
    } catch (Throwable $exception) {
        $report($platform, $check, false, get_class($exception));
    }
};
$overview = static function (string $platform, callable $operation) use ($report): void {
    try {
        $identity = $operation();
        $rows = array_map(static fn(array $row): string => $row['label'] . '=' . $row['value'], $identity['profile']['rows'] ?? []);
        $report($platform, '登录与概览', true, implode(' | ', $rows));
    } catch (PlatformVerification $verification) {
        $report($platform, '登录与概览', true, '需要图片验证码，请在页面中添加账号');
    } catch (Throwable $exception) {
        $report($platform, '登录与概览', false, $exception instanceof PlatformException ? $exception->getMessage() : get_class($exception));
    }
};
$env = static fn(string $name): string => trim((string)getenv($name));

// 百度贴吧
$expectInvalid('百度贴吧', '失效 BDUSS 判定', static fn() => PlatformRegistry::adapter('tieba')
    ->authenticate(['cookie' => str_repeat('x', 192)]));
if ($env('TIEBA_BDUSS') !== '') {
    $overview('百度贴吧', static fn() => PlatformRegistry::adapter('tieba')->authenticate(['cookie' => $env('TIEBA_BDUSS')]));
}

// 夸克网盘
$expectInvalid('夸克网盘', '失效签到参数判定', static fn() => PlatformRegistry::adapter('quark')->execute(['credentials' => [
    'cookie' => '__uid=invalid', 'kps' => 'invalid', 'sign' => 'invalid', 'vcode' => (string)(time() * 1000)]]));
if ($env('QUARK_COOKIE') !== '' && $env('QUARK_APP_URL') !== '') {
    $overview('夸克网盘', static fn() => PlatformRegistry::adapter('quark')
        ->authenticate(['cookie' => $env('QUARK_COOKIE'), 'app_url' => $env('QUARK_APP_URL')]));
}

// 天翼云盘：失效会话与令牌必须被判定为需要重新登录。
$expectInvalid('天翼云盘', '失效会话判定', static fn() => PlatformRegistry::adapter('tianyi')->profile(['credentials' => [
    'session_key' => 'invalid', 'session_secret' => 'invalid', 'access_token' => 'invalid', 'refresh_token' => 'invalid']]));
if ($env('TIANYI_USERNAME') !== '' && $env('TIANYI_PASSWORD') !== '') {
    $overview('天翼云盘', static fn() => PlatformRegistry::adapter('tianyi')
        ->authenticate(['username' => $env('TIANYI_USERNAME'), 'password' => (string)getenv('TIANYI_PASSWORD')]));
}

// 阿里云盘
$expectInvalid('阿里云盘', '失效令牌判定', static fn() => PlatformRegistry::adapter('aliyundrive')
    ->authenticate(['refresh_token' => str_repeat('0', 32)]));
if ($env('ALIYUNDRIVE_REFRESH_TOKEN') !== '') {
    if ($env('ALIYUNDRIVE_ALLOW_ROTATE') === '1') {
        $overview('阿里云盘', static fn() => PlatformRegistry::adapter('aliyundrive')
            ->authenticate(['refresh_token' => $env('ALIYUNDRIVE_REFRESH_TOKEN')]));
    } else {
        $report('阿里云盘', '登录与概览', true, '已跳过：验证会轮换令牌，需同时设置 ALIYUNDRIVE_ALLOW_ROTATE=1');
    }
}

echo $failures === 0 ? "Check-in live smoke finished\n" : "Check-in live smoke found {$failures} problem(s)\n";
exit($failures === 0 ? 0 : 1);
