<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use netease\Netease;

/**
 * Daily-login success copy appends the login days still missing for the next
 * level (nextLoginCount - nowLoginCount, the same metric the console shows).
 * The level read is decorative: any failure must fall back to the plain
 * 登录成功 copy and never downgrade the successful login into a retry.
 */
final class LoginLevelFixture extends Netease
{
    public string $scenario = '';

    public function __construct() {}

    public function getInfo($cookie)
    {
        return ['status' => 200, 'body' => '{"code":200,"account":{"id":42},"profile":{"userId":42}}'];
    }

    public function level()
    {
        return match ($this->scenario) {
            'zero-days' => ['status' => 200, 'body' => '{"code":200,"data":{"level":9,"nowLoginCount":850,"nextLoginCount":850}}'],
            'zero-logins' => ['status' => 200, 'body' => '{"code":200,"data":{"nowLoginCount":0,"nextLoginCount":3}}'],
            'string-counts' => ['status' => 200, 'body' => '{"code":200,"data":{"nowLoginCount":"797","nextLoginCount":"800"}}'],
            'missing-data' => ['status' => 200, 'body' => '{"code":200}'],
            'missing-now' => ['status' => 200, 'body' => '{"code":200,"data":{"nextLoginCount":800}}'],
            'null-now' => ['status' => 200, 'body' => '{"code":200,"data":{"nextLoginCount":800,"nowLoginCount":null}}'],
            'missing-next' => ['status' => 200, 'body' => '{"code":200,"data":{"nowLoginCount":797}}'],
            'null-next' => ['status' => 200, 'body' => '{"code":200,"data":{"nowLoginCount":797,"nextLoginCount":null}}'],
            'invalid-now' => ['status' => 200, 'body' => '{"code":200,"data":{"nowLoginCount":"unknown","nextLoginCount":800}}'],
            'invalid-next' => ['status' => 200, 'body' => '{"code":200,"data":{"nowLoginCount":797,"nextLoginCount":true}}'],
            'array-now' => ['status' => 200, 'body' => '{"code":200,"data":{"nowLoginCount":[],"nextLoginCount":800}}'],
            'negative-now' => ['status' => 200, 'body' => '{"code":200,"data":{"nowLoginCount":-1,"nextLoginCount":800}}'],
            'fractional-now' => ['status' => 200, 'body' => '{"code":200,"data":{"nowLoginCount":797.5,"nextLoginCount":800}}'],
            'not-ok' => ['status' => 200, 'body' => '{"code":800,"message":"boom"}'],
            'throw' => throw new RuntimeException('transport down'),
            default => ['status' => 200, 'body' => '{"code":200,"data":{"level":8,"nowLoginCount":797,"nextLoginCount":800,"nowPlayCount":10000,"nextPlayCount":20000}}'],
        };
    }
}

$fixture = new LoginLevelFixture();
$result = $fixture->login_work();
if ((int)$result['code'] !== 200 || $result['message'] !== '登录成功，3天后升级') {
    throw new RuntimeException('login_work did not append the days-until-level-up suffix');
}

// Login days already at the next level's threshold keep the plain copy.
$fixture = new LoginLevelFixture();
$fixture->scenario = 'zero-days';
$result = $fixture->login_work();
if ((int)$result['code'] !== 200 || $result['message'] !== '登录成功') {
    throw new RuntimeException('login_work appended a level suffix without remaining days');
}

// Integer zero and digit strings are valid counters, not missing data.
foreach (['zero-logins', 'string-counts'] as $scenario) {
    $fixture = new LoginLevelFixture();
    $fixture->scenario = $scenario;
    $result = $fixture->login_work();
    if ((int)$result['code'] !== 200 || $result['message'] !== '登录成功，3天后升级' || $fixture->cookiezt) {
        throw new RuntimeException('login_work rejected valid login counters for ' . $scenario);
    }
}

// Missing/invalid counters, a non-200 level body and a transport failure all fall back to
// the plain success copy without invalidating the account.
foreach ([
    'missing-data', 'missing-now', 'null-now', 'missing-next', 'null-next',
    'invalid-now', 'invalid-next', 'array-now', 'negative-now', 'fractional-now',
    'not-ok', 'throw',
] as $scenario) {
    $fixture = new LoginLevelFixture();
    $fixture->scenario = $scenario;
    $result = $fixture->login_work();
    if ((int)$result['code'] !== 200 || $result['message'] !== '登录成功' || $fixture->cookiezt) {
        throw new RuntimeException('login_work did not keep the plain success copy for ' . $scenario . ': ' . json_encode($result, JSON_UNESCAPED_UNICODE));
    }
}

echo "Netease login level-suffix regression passed: successful daily login reports days until the next level and stays successful when the level read fails\n";
