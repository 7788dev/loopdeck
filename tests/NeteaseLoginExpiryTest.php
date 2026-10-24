<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use netease\Netease;

/**
 * Dead-cookie regression: a logged-out MUSIC_U must mark the account invalid
 * (cookiezt) instead of looping daily through generic failure copy. The
 * upstream shapes below were captured from a real expired production account:
 * the account endpoint answers 200 with a null account, and the VIP level
 * endpoint serves an anonymous template (userId 0 / vipType -1) while every
 * other VIP endpoint answers 301.
 */
final class LoginExpiryFixture extends Netease
{
    public string $scenario = '';

    public function __construct() {}

    public function getInfo($cookie)
    {
        if ($this->scenario === 'login-null-account') {
            return ['status' => 200, 'body' => '{"code":200,"account":null,"profile":null}'];
        }
        if ($this->scenario === 'login-301') {
            return ['status' => 200, 'body' => '{"code":301,"message":"请登录"}'];
        }
        if ($this->scenario === 'login-transport-error') {
            return ['status' => 0, 'body' => ''];
        }
        return ['status' => 200, 'body' => '{"code":200,"account":{"id":42},"profile":{"userId":42}}'];
    }

    public function vip_growthpoint()
    {
        if ($this->scenario === 'anonymous-level') {
            return ['code' => 200, 'data' => ['userLevel' => [
                'userId' => 0, 'level' => 1, 'growthPoint' => 0,
                'vipType' => -1, 'latestVipStatus' => -1, 'normal' => true,
            ]]];
        }
        return ['code' => 200, 'data' => ['userLevel' => [
            'userId' => 42, 'growthPoint' => 100, 'vipType' => 300, 'normal' => true,
        ]]];
    }

    public function get_vip_tasks()
    {
        return $this->scenario === 'legacy-301'
            ? ['code' => 301, 'message' => '请登录！']
            : ['code' => 200, 'data' => ['taskList' => []]];
    }

    public function vip_sign()
    {
        return ['code' => 200, 'signed' => $this->scenario !== 'legacy-301', 'message' => '乐签已打卡'];
    }

    public function vip_timemachine($startTime = null, $endTime = null, $limit = 60)
    {
        return $this->scenario === 'legacy-301' ? ['code' => 301] : ['code' => 200];
    }

    public function listen_vip_songs()
    {
        return ['code' => 200, 'data' => ['reported' => 3], 'message' => 'VIP 听歌 3/3'];
    }

    public function vip_tasks_v1($userId = null)
    {
        return $this->scenario === 'legacy-301' ? ['code' => 301, 'message' => '系统错误'] : ['code' => 200, 'data' => []];
    }

    protected function partnerDailyTask(): array
    {
        return $this->scenario === 'evaluate-301' ? ['code' => 301, 'message' => '请登录'] : ['code' => 200, 'data' => []];
    }

    protected function requestApi(
        string $uri,
        array $data = [],
        string $crypto = 'eapi',
        array $options = []
    ): array {
        if ($this->scenario === 'yunbei-301' && $uri === '/api/point/signed/get') {
            return ['status' => 200, 'body' => '{"code":301,"message":"请登录"}'];
        }
        return ['status' => 200, 'body' => '{"code":200,"data":{"signed":true}}'];
    }
}

$expiredCopy = '登录已失效，请重新扫码登录';

// vip_growth_task with the anonymous level template must invalidate, not fail vaguely.
$fixture = new LoginExpiryFixture();
$fixture->scenario = 'anonymous-level';
$result = $fixture->vip_growth_task();
if ((int)$result['code'] !== 201 || !str_contains($result['message'], $expiredCopy)) {
    throw new RuntimeException('vip_growth_task did not report login expiry for the anonymous level template');
}
if (!$fixture->cookiezt) {
    throw new RuntimeException('Anonymous level template did not mark the account invalid');
}

// A 301 mid-run (reward list reads) must also mark the account invalid.
$fixture = new LoginExpiryFixture();
$fixture->scenario = 'legacy-301';
$result = $fixture->vip_growth_task();
if (!$fixture->cookiezt) {
    throw new RuntimeException('A 301 reward-list read did not mark the account invalid');
}

// login_work must treat 200 + null account as logged out (real expired shape).
foreach (['login-null-account', 'login-301'] as $scenario) {
    $fixture = new LoginExpiryFixture();
    $fixture->scenario = $scenario;
    $result = $fixture->login_work();
    if ((int)$result['code'] === 200 || !str_contains($result['message'], $expiredCopy) || !$fixture->cookiezt) {
        throw new RuntimeException('login_work accepted a logged-out session for ' . $scenario);
    }
}

// A healthy session still reports 登录成功 and keeps the account valid.
$fixture = new LoginExpiryFixture();
$result = $fixture->login_work();
if ((int)$result['code'] !== 200 || $fixture->cookiezt) {
    throw new RuntimeException('login_work rejected a healthy session');
}

// A transport failure must not invalidate the account (nor claim success).
$fixture = new LoginExpiryFixture();
$fixture->scenario = 'login-transport-error';
$result = $fixture->login_work();
if ((int)$result['code'] !== 201 || !str_contains($result['message'], '登录状态检查失败') || $fixture->cookiezt) {
    throw new RuntimeException('login_work invalidated (or passed) a session after a transport failure');
}

// evaluate via the shared music-task failure path must invalidate on 301.
$fixture = new LoginExpiryFixture();
$fixture->scenario = 'evaluate-301';
$result = $fixture->evaluate();
if (!str_contains((string)$result['message'], $expiredCopy) || !$fixture->cookiezt) {
    throw new RuntimeException('evaluate did not map a 301 partner-task read to login expiry');
}

// yunbei_sign must invalidate when its sign-status probe answers 301.
$fixture = new LoginExpiryFixture();
$fixture->scenario = 'yunbei-301';
$result = $fixture->yunbei_sign();
if (!str_contains((string)$result['message'], $expiredCopy) || !$fixture->cookiezt) {
    throw new RuntimeException('yunbei_sign did not map a 301 sign-status probe to login expiry');
}

// Healthy sign-status probe keeps the account valid.
$fixture = new LoginExpiryFixture();
$result = $fixture->yunbei_sign();
if ((int)$result['code'] !== 200 || $fixture->cookiezt) {
    throw new RuntimeException('yunbei_sign rejected a healthy sign-status probe');
}

echo "Netease login-expiry regression passed: dead cookies mark the account invalid instead of looping generic failures\n";
