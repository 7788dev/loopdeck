<?php

declare(strict_types=1);

/**
 * Offline protocol regressions for the check-in adapters. A scripted
 * transport stands in for the upstream services, so success, expired
 * sessions, rate limits, network failures, partial failures and token
 * rotation are exercised without contacting any platform.
 */

use aliyundrive\AliyunDrive;
use app\service\CheckinTransport;
use app\service\CheckinView;
use app\service\PlatformException;
use quark\Quark;
use tianyi\Tianyi;
use tieba\Tieba;

require dirname(__DIR__) . '/vendor/autoload.php';

function adapterCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function adapterThrows(callable $operation, string $message, ?bool $accountInvalid = null): PlatformException
{
    try {
        $operation();
    } catch (PlatformException $exception) {
        if ($accountInvalid !== null && $exception->accountInvalid !== $accountInvalid) {
            throw new RuntimeException($message . ' (wrong accountInvalid flag: ' . $exception->getMessage() . ')');
        }
        return $exception;
    }
    throw new RuntimeException($message . ' (no exception)');
}

/** Answers requests from per-endpoint queues; the last answer repeats. */
final class ScriptedTransport implements CheckinTransport
{
    public array $requests = [];
    private array $routes = [];

    public function on(string $method, string $url, callable|array|Throwable ...$answers): self
    {
        $this->routes[$method . ' ' . $url] = array_merge($this->routes[$method . ' ' . $url] ?? [], $answers);
        return $this;
    }

    public function request(string $method, string $url, array $options = []): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
        $key = $method . ' ' . explode('?', $url, 2)[0];
        if (empty($this->routes[$key])) {
            throw new RuntimeException('Unexpected request ' . $key);
        }
        $answer = count($this->routes[$key]) > 1 ? array_shift($this->routes[$key]) : $this->routes[$key][0];
        if (is_callable($answer)) {
            $answer = $answer($options);
        }
        if ($answer instanceof Throwable) {
            throw $answer;
        }
        [$status, $body, $headers] = $answer + [2 => []];
        return ['status' => $status, 'body' => is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE),
            'headers' => $headers, 'url' => $url];
    }

    public function count(string $method, string $url): int
    {
        return count(array_filter($this->requests, static fn(array $r): bool
            => $r['method'] === $method && explode('?', $r['url'], 2)[0] === $url));
    }
}

$noSleep = static function (float $seconds): void {
};
$persisted = [];
$persist = static function (array $credentials) use (&$persisted): void {
    $persisted[] = $credentials;
};

// --- Shared helpers ------------------------------------------------------

adapterCheck(CheckinView::bytes(1536) === '1.5 KB' && CheckinView::bytes(null) === '暂不可用', 'Byte formatting is wrong');
adapterCheck(CheckinView::capacity(['capacity_total' => 100, 'capacity_used' => 91])['level'] === 'danger', 'Capacity level is wrong');
adapterCheck(CheckinView::capacity(['capacity_total' => 0, 'capacity_used' => 0]) === null, 'Zero capacity rendered a bar');
adapterCheck(CheckinView::percent(5, 0) === 0 && CheckinView::percent(7, 5) === 100, 'Percent is not clamped');

// --- 百度贴吧 -------------------------------------------------------------

$bduss = str_repeat('AbC-~', 30);
$signed = Tieba::sign(['b' => '2', 'a' => '1']);
adapterCheck($signed['sign'] === strtoupper(md5('a=1b=2tiebaclient!!!')), 'Tieba client signature is wrong');

$login = [200, ['error_code' => '0', 'user' => ['id' => '123456', 'name' => 'raw_name', 'portrait' => 'tb.1.abc?t=1'],
    'anti' => ['tbs' => 'tbs-1']]];
$transport = (new ScriptedTransport())
    ->on('POST', 'https://c.tieba.baidu.com/c/s/login', $login)
    ->on('GET', 'https://tieba.baidu.com/home/get/panel', [200, ['data' => ['show_nickname' => '贴吧昵称']]]);
$identity = (new Tieba($transport, null, $noSleep))->authenticate(['cookie' => 'STOKEN=x; BDUSS=' . $bduss . '; PTOKEN=y']);
adapterCheck($identity['user_id'] === '123456' && $identity['nickname'] === '贴吧昵称', 'Tieba identity is wrong');
adapterCheck($identity['credentials'] === ['bduss' => $bduss], 'Tieba stored more than the BDUSS');
adapterCheck($identity['avatar'] === 'https://himg.bdimg.com/sys/portrait/item/tb.1.abc', 'Tieba avatar is wrong');
adapterThrows(static fn() => (new Tieba(new ScriptedTransport()))->authenticate(['cookie' => 'STOKEN=only']),
    'Tieba accepted a cookie without BDUSS', true);

$forums = [200, ['error_code' => '0', 'has_more' => '0', 'forum_list' => ['non-gconforum' => [
    ['id' => '1', 'name' => '甲'], ['id' => '2', 'name' => '乙'], ['id' => '3', 'name' => '丙'], ['id' => '4', 'name' => '丁'],
]]]];
$signAnswers = [
    '1' => [[200, ['error_code' => '0', 'user_info' => ['sign_bonus_point' => '6']]]],
    '2' => [[200, ['error_code' => '160002']]],
    '3' => [[200, ['error_code' => '340006']]],
    // Rate limited once, then signed on the next step.
    '4' => [[200, ['error_code' => '340011']], [200, ['error_code' => '0', 'user_info' => ['sign_bonus_point' => '4']]]],
];
$transport = (new ScriptedTransport())
    ->on('POST', 'https://c.tieba.baidu.com/c/s/login', $login)
    ->on('POST', 'https://c.tieba.baidu.com/c/f/forum/like', $forums)
    ->on('POST', 'https://c.tieba.baidu.com/c/c/forum/sign', static function (array $options) use (&$signAnswers): array {
        $fid = (string)$options['form_params']['fid'];
        return count($signAnswers[$fid]) > 1 ? array_shift($signAnswers[$fid]) : $signAnswers[$fid][0];
    });
$checkpoints = [];
$account = ['credentials' => ['bduss' => $bduss]];
$first = (new Tieba($transport, null, $noSleep))->execute($account, [], static function (array $state) use (&$checkpoints): void {
    $checkpoints[] = $state;
});
adapterCheck(!empty($first['in_progress']) && $first['retry_after_seconds'] === 300, 'Tieba rate limit did not pause the run');
adapterCheck($checkpoints !== [] && !isset(end($checkpoints)['bduss']), 'Tieba checkpoint missing or holds credentials');
$second = (new Tieba($transport, null, $noSleep))->execute($account, $first['state']);
adapterCheck($second['success'] && empty($second['in_progress']), 'Tieba did not finish after the pause');
adapterCheck($second['summary']['done'] === 3 && $second['summary']['skipped'] === 1 && $second['summary']['bonus'] === 10,
    'Tieba summary is wrong: ' . json_encode($second['summary']));
adapterCheck($transport->count('POST', 'https://c.tieba.baidu.com/c/f/forum/like') === 1, 'Tieba re-read the forum list');
foreach ($transport->requests as $request) {
    $form = $request['options']['form_params'] ?? [];
    adapterCheck(isset($form['sign']) && $form['sign'] === Tieba::sign($form)['sign'], 'Tieba sent an unsigned request');
}

// Partial failure: an unknown answer is retried, then reported as failed.
$transport = (new ScriptedTransport())
    ->on('POST', 'https://c.tieba.baidu.com/c/s/login', $login)
    ->on('POST', 'https://c.tieba.baidu.com/c/f/forum/like', [200, ['error_code' => '0', 'has_more' => '0',
        'forum_list' => ['non-gconforum' => [['id' => '9', 'name' => '坏吧']]]]])
    ->on('POST', 'https://c.tieba.baidu.com/c/c/forum/sign', [200, ['error_code' => '999999']]);
$state = [];
for ($run = 0; $run < 5; $run++) {
    $result = (new Tieba($transport, null, $noSleep))->execute($account, $state);
    $state = $result['state'];
    if (empty($result['in_progress'])) {
        break;
    }
}
adapterCheck(!$result['success'] && $result['summary']['failed'] === 1 && $result['summary']['failed_names'] === ['坏吧'],
    'Tieba did not report the failed forum');
adapterCheck(str_contains($result['message'], '失败=1'), 'Tieba message lacks the failure count');

// Network failure during signing keeps progress and retries later.
$transport = (new ScriptedTransport())
    ->on('POST', 'https://c.tieba.baidu.com/c/s/login', $login)
    ->on('POST', 'https://c.tieba.baidu.com/c/f/forum/like', $forums)
    ->on('POST', 'https://c.tieba.baidu.com/c/c/forum/sign', new PlatformException('平台连接失败或超时，已安排稍后重试'));
$result = (new Tieba($transport, null, $noSleep))->execute($account);
adapterCheck(!empty($result['in_progress']) && $result['retry_after_seconds'] === 300 && $result['state']['scanned'],
    'Tieba network failure lost progress');

// Expired BDUSS and upstream rate limiting.
$transport = (new ScriptedTransport())->on('POST', 'https://c.tieba.baidu.com/c/s/login', [200, ['error_code' => '1']]);
adapterThrows(static fn() => (new Tieba($transport))->execute($account), 'Tieba accepted an expired BDUSS', true);
$transport = (new ScriptedTransport())->on('POST', 'https://c.tieba.baidu.com/c/s/login', [429, '']);
$limited = adapterThrows(static fn() => (new Tieba($transport))->profile($account), 'Tieba ignored HTTP 429', false);
adapterCheck($limited->retryAfter === 900, 'HTTP 429 is not delayed long enough');

// --- 夸克网盘 -------------------------------------------------------------

$appUrl = 'https://drive-m.quark.cn/1/clouddrive/capacity/growth/info?pr=ucpro&fr=android&kps=KPS1&sign=SIGN1&vcode=1727000000000';
$growth = static fn(bool $signed, int $progress = 2): array => [200, ['code' => 0, 'data' => [
    'total_capacity' => 10 * 1073741824, 'member_type' => 'NORMAL',
    'cap_sign' => ['sign_daily' => $signed, 'sign_progress' => $progress, 'sign_target' => 7, 'sign_daily_reward' => 20971520],
]]];
$quarkAccount = ['credentials' => ['cookie' => '__uid=u1; __puus=old', 'kps' => 'KPS1', 'sign' => 'SIGN1', 'vcode' => '1727000000000']];

adapterThrows(static fn() => (new Quark(new ScriptedTransport()))->authenticate(['cookie' => 'a=b', 'app_url' => $appUrl]),
    'Quark accepted a cookie without __uid', true);
adapterThrows(static fn() => (new Quark(new ScriptedTransport()))->authenticate(['cookie' => '__uid=u1', 'app_url' => 'kps=1']),
    'Quark accepted incomplete app parameters', true);

$transport = (new ScriptedTransport())
    ->on('GET', 'https://pan.quark.cn/account/info', [200, ['data' => ['nickname' => '13812345678', 'avatarUri' => 'https://img.example/a.png']]])
    ->on('GET', 'https://drive-m.quark.cn/1/clouddrive/capacity/growth/info', $growth(false))
    ->on('GET', 'https://drive.quark.cn/1/clouddrive/member', [200, ['data' => ['total_capacity' => 10 * 1073741824,
        'use_capacity' => 1073741824, 'member_type' => 'SUPER_VIP']]]);
$identity = (new Quark($transport))->authenticate(['cookie' => '__uid=u1; __puus=p', 'app_url' => $appUrl]);
adapterCheck($identity['nickname'] === '138****5678', 'Quark nickname was not masked');
adapterCheck(preg_match('/\A[a-f0-9]{32}\z/', $identity['user_id']) === 1, 'Quark identity is not a stable hash');
adapterCheck($identity['credentials']['kps'] === 'KPS1' && $identity['credentials']['vcode'] === '1727000000000', 'Quark app parameters lost');
adapterCheck($identity['profile']['stats']['capacity_used'] === 1073741824, 'Quark used capacity missing');

$transport = (new ScriptedTransport())->on('GET', 'https://drive-m.quark.cn/1/clouddrive/capacity/growth/info', $growth(true, 3));
$result = (new Quark($transport))->execute($quarkAccount);
adapterCheck($result['success'] && str_contains($result['message'], '今日已完成'), 'Quark re-signed an already signed day');
adapterCheck($transport->count('POST', 'https://drive-m.quark.cn/1/clouddrive/capacity/growth/sign') === 0, 'Quark posted a duplicate sign');

// Upstream answers a same-day duplicate with HTTP 400; growth state decides.
$transport = (new ScriptedTransport())
    ->on('GET', 'https://drive-m.quark.cn/1/clouddrive/capacity/growth/info', $growth(false), $growth(true, 3))
    ->on('POST', 'https://drive-m.quark.cn/1/clouddrive/capacity/growth/sign', [400, ['code' => 44210, 'message' => 'repeat']]);
$result = (new Quark($transport))->execute($quarkAccount);
adapterCheck($result['success'] && $result['summary']['sign_progress'] === 3, 'Quark trusted the sign response over growth state');
$query = $transport->requests[1]['options']['query'];
adapterCheck($query['kps'] === 'KPS1' && $query['pr'] === 'ucpro' && $transport->requests[1]['options']['json'] === ['sign_cyclic' => true],
    'Quark sign request parameters are wrong');

$transport = (new ScriptedTransport())
    ->on('GET', 'https://drive-m.quark.cn/1/clouddrive/capacity/growth/info', $growth(false))
    ->on('POST', 'https://drive-m.quark.cn/1/clouddrive/capacity/growth/sign', [200, ['code' => 0, 'data' => []]]);
$state = [];
for ($run = 1; $run <= 3; $run++) {
    $result = (new Quark($transport))->execute($quarkAccount, $state);
    $state = $result['state'];
    adapterCheck(!$result['success'] && $result['retry_after_seconds'] === ($run < 3 ? 1800 : 0), 'Quark unverified retry is wrong');
}
$transport = (new ScriptedTransport())
    ->on('GET', 'https://drive-m.quark.cn/1/clouddrive/capacity/growth/info', [200, ['code' => 31001, 'message' => 'require login']]);
adapterThrows(static fn() => (new Quark($transport))->execute($quarkAccount), 'Quark ignored expired app parameters', true);

// Cookie rotation is written back; an expired web Cookie does not break the overview.
$persisted = [];
$transport = (new ScriptedTransport())
    ->on('GET', 'https://drive-m.quark.cn/1/clouddrive/capacity/growth/info', $growth(true))
    ->on('GET', 'https://drive.quark.cn/1/clouddrive/member', [200, ['data' => ['use_capacity' => 5]], ['Set-Cookie' => ['__puus=new; Path=/']]]);
(new Quark($transport, $persist))->profile($quarkAccount);
adapterCheck(($persisted[0]['cookie'] ?? '') === '__uid=u1; __puus=new', 'Quark rotated cookie was not saved');
$transport = (new ScriptedTransport())
    ->on('GET', 'https://drive-m.quark.cn/1/clouddrive/capacity/growth/info', $growth(true))
    ->on('GET', 'https://drive.quark.cn/1/clouddrive/member', [401, ['code' => 31001]]);
$profile = (new Quark($transport))->profile($quarkAccount);
adapterCheck(in_array('网页 Cookie 已过期（不影响签到）', array_column($profile['rows'], 'value'), true), 'Quark overview failed on an expired cookie');

// --- 天翼云盘 -------------------------------------------------------------

$date = 'Thu, 24 Sep 2026 00:00:00 GMT';
adapterCheck(Tianyi::signature('secret', 'key', '/portal/getUserSizeInfo.action', $date)
    === strtoupper(hash_hmac('sha1', 'SessionKey=key&Operate=GET&RequestURI=/portal/getUserSizeInfo.action&Date=' . $date, 'secret')),
    'Tianyi request signature is wrong');
$size = [200, ['res_code' => 0, 'cloudCapacityInfo' => ['totalSize' => 2199023255552, 'usedSize' => 1099511627776, 'freeSize' => 1099511627776],
    'familyCapacityInfo' => ['totalSize' => 1099511627776, 'usedSize' => 0]]];
$tianyiAccount = ['credentials' => ['session_key' => 'SK1', 'session_secret' => 'SS1', 'access_token' => 'AT1', 'refresh_token' => 'RT1']];

$transport = (new ScriptedTransport())
    ->on('GET', 'https://api.cloud.189.cn/portal/getUserSizeInfo.action', $size)
    ->on('GET', 'https://cloud.189.cn/mkt/userSign.action', [200, ['isSign' => false, 'netdiskBonus' => 30]]);
$result = (new Tianyi($transport))->execute($tianyiAccount);
adapterCheck($result['success'] && $result['summary']['bonus_mb'] === 30 && str_contains($result['message'], '30 MB'), 'Tianyi sign reward missing');
adapterCheck($result['summary']['family_total'] === 1099511627776, 'Tianyi family capacity missing');
$signQuery = $transport->requests[1]['options']['query'];
adapterCheck($signQuery['sessionKey'] === 'SK1' && $signQuery['clientType'] === 'TELEANDROID', 'Tianyi sign query is wrong');
$headers = $transport->requests[0]['options']['headers'];
adapterCheck($headers['Signature'] === Tianyi::signature('SS1', 'SK1', '/portal/getUserSizeInfo.action', $headers['Date']),
    'Tianyi signed call used the wrong signature');

$transport = (new ScriptedTransport())
    ->on('GET', 'https://api.cloud.189.cn/portal/getUserSizeInfo.action', $size)
    ->on('GET', 'https://cloud.189.cn/mkt/userSign.action', [200, ['isSign' => true, 'netdiskBonus' => 30]]);
$result = (new Tianyi($transport))->execute($tianyiAccount);
adapterCheck($result['success'] && $result['summary']['signed_before'] && $result['summary']['bonus_mb'] === 0,
    'Tianyi counted a repeated reward');

// Expired session: access token renews it and the new keys are persisted.
$persisted = [];
$transport = (new ScriptedTransport())
    ->on('GET', 'https://api.cloud.189.cn/portal/getUserSizeInfo.action', [400, ['errorCode' => 'InvalidSessionKey']], $size)
    ->on('GET', 'https://api.cloud.189.cn/getSessionForPC.action', [200, ['res_code' => 0, 'sessionKey' => 'SK2',
        'sessionSecret' => 'SS2', 'accessToken' => 'AT2', 'refreshToken' => 'RT2']])
    ->on('GET', 'https://cloud.189.cn/mkt/userSign.action', [200, ['isSign' => false, 'netdiskBonus' => 10]]);
$result = (new Tianyi($transport, $persist))->execute($tianyiAccount);
adapterCheck($result['success'] && ($persisted[0]['session_key'] ?? '') === 'SK2' && ($persisted[0]['refresh_token'] ?? '') === 'RT2',
    'Tianyi did not persist the renewed session');
adapterCheck($transport->requests[3]['options']['query']['sessionKey'] === 'SK2', 'Tianyi signed with the stale session');

// Access token rejected: the refresh token is used, then the session is created.
$persisted = [];
$transport = (new ScriptedTransport())
    ->on('GET', 'https://api.cloud.189.cn/portal/getUserSizeInfo.action', [400, ['errorCode' => 'InvalidSessionKey']], $size)
    ->on('GET', 'https://api.cloud.189.cn/getSessionForPC.action', [400, ['res_code' => 'UserInvalidOpenToken']],
        [200, ['res_code' => 0, 'sessionKey' => 'SK3', 'sessionSecret' => 'SS3']])
    ->on('POST', 'https://open.e.189.cn/api/oauth2/refreshToken.do', [200, ['accessToken' => 'AT3', 'refreshToken' => 'RT3']])
    ->on('GET', 'https://cloud.189.cn/mkt/userSign.action', [200, ['isSign' => false, 'netdiskBonus' => 10]]);
$result = (new Tianyi($transport, $persist))->execute($tianyiAccount);
adapterCheck($result['success'] && end($persisted)['session_key'] === 'SK3' && end($persisted)['access_token'] === 'AT3',
    'Tianyi refresh-token renewal failed');

$transport = (new ScriptedTransport())
    ->on('GET', 'https://api.cloud.189.cn/portal/getUserSizeInfo.action', [400, ['errorCode' => 'InvalidSessionKey']]);
adapterThrows(static fn() => (new Tianyi($transport))->execute(['credentials' => ['session_key' => 'a', 'session_secret' => 'b']]),
    'Tianyi accepted an expired session without tokens', true);
$transport = (new ScriptedTransport())
    ->on('GET', 'https://api.cloud.189.cn/portal/getUserSizeInfo.action', $size)
    ->on('GET', 'https://cloud.189.cn/mkt/userSign.action', [200, ['errorCode' => 'Other']]);
adapterThrows(static fn() => (new Tianyi($transport))->execute($tianyiAccount), 'Tianyi accepted a malformed sign result', false);

// --- 阿里云盘 -------------------------------------------------------------

$token = static fn(string $next): array => [200, ['access_token' => 'AT-' . $next, 'refresh_token' => 'RT-' . $next,
    'expires_in' => 7200, 'user_id' => 'aliuser01', 'nick_name' => 'alice@example.com', 'avatar' => 'https://img.example/b.png']];
$capacity = [200, ['drive_total_size' => 100, 'drive_used_size' => 40]];
$transport = (new ScriptedTransport())
    ->on('POST', 'https://auth.alipan.com/v2/account/token', $token('1'))
    ->on('POST', 'https://api.alipan.com/adrive/v1/user/driveCapacityDetails', $capacity);
$identity = (new AliyunDrive($transport))->authenticate(['refresh_token' => '{"refresh_token":"abcdefghijklmnop0123","x":1}']);
adapterCheck($identity['user_id'] === 'aliuser01' && $identity['nickname'] === 'al***@example.com', 'Aliyun identity is wrong');
adapterCheck($identity['credentials']['refresh_token'] === 'RT-1', 'Aliyun kept the consumed refresh token');
adapterCheck($transport->requests[0]['options']['json']['refresh_token'] === 'abcdefghijklmnop0123', 'Aliyun token JSON was not parsed');
adapterCheck($transport->count('POST', 'https://member.aliyundrive.com/v1/activity/sign_in_list') === 0, 'Aliyun signed in while adding');

$aliAccount = ['credentials' => ['refresh_token' => 'RT-0']];
$transport = (new ScriptedTransport())
    ->on('POST', 'https://auth.alipan.com/v2/account/token', $token('2'))
    ->on('POST', 'https://api.alipan.com/adrive/v1/user/driveCapacityDetails', $capacity);
(new AliyunDrive($transport))->profile($aliAccount);
adapterCheck($transport->count('POST', 'https://member.aliyundrive.com/v1/activity/sign_in_list') === 0, 'Aliyun profile signed in');

$persisted = [];
$logs = [
    ['day' => 1, 'status' => 'normal', 'isReward' => true, 'reward' => ['name' => '50GB']],
    ['day' => 2, 'status' => 'miss', 'isReward' => false],
    ['day' => 3, 'status' => 'normal', 'isReward' => false, 'reward' => ['name' => '好运卡']],
];
$transport = (new ScriptedTransport())
    ->on('POST', 'https://auth.alipan.com/v2/account/token', $token('3'))
    ->on('POST', 'https://member.aliyundrive.com/v1/activity/sign_in_list',
        [401, ['code' => 'AccessTokenInvalid']], [200, ['success' => true, 'result' => ['signInCount' => 3, 'signInLogs' => $logs]]]);
$result = (new AliyunDrive($transport, $persist))->execute($aliAccount);
adapterCheck($result['success'] && $result['summary']['count'] === 3 && $result['summary']['unclaimed'] === 1, 'Aliyun summary is wrong');
adapterCheck(str_contains($result['message'], '待领取奖励=1项'), 'Aliyun did not report the unclaimed reward');
adapterCheck(count($result['summary']['track']) === 3 && $result['summary']['track'][1]['missed'], 'Aliyun sign track is wrong');
adapterCheck($transport->requests[1]['options']['query'] === ['_rx-s' => 'mobile']
    && $transport->requests[1]['options']['json'] === ['isReward' => false], 'Aliyun sign request is wrong');
adapterCheck(count($persisted) === 2 && $persisted[0]['refresh_token'] === 'RT-3', 'Aliyun rotated token was not saved before use');
adapterCheck($transport->count('POST', 'https://auth.alipan.com/v2/account/token') === 2, 'Aliyun did not renew after HTTP 401');

$transport = (new ScriptedTransport())
    ->on('POST', 'https://auth.alipan.com/v2/account/token', $token('4'))
    ->on('POST', 'https://member.aliyundrive.com/v1/activity/sign_in_list',
        [200, ['success' => true, 'result' => ['signInCount' => 4, 'signInLogs' => $logs]]]);
$result = (new AliyunDrive($transport))->execute($aliAccount);
adapterCheck(!$result['success'] && $result['retry_after_seconds'] === 1800, 'Aliyun trusted a sign list without today');

$transport = (new ScriptedTransport())->on('POST', 'https://auth.alipan.com/v2/account/token', [400, ['code' => 'InvalidParameter.RefreshToken']]);
adapterThrows(static fn() => (new AliyunDrive($transport))->execute($aliAccount), 'Aliyun accepted a revoked token', true);
$transport = (new ScriptedTransport())->on('POST', 'https://auth.alipan.com/v2/account/token', [503, '']);
adapterThrows(static fn() => (new AliyunDrive($transport))->execute($aliAccount), 'Aliyun treated an outage as invalid', false);
adapterThrows(static fn() => (new AliyunDrive(new ScriptedTransport()))->authenticate(['refresh_token' => 'short']),
    'Aliyun accepted a malformed token', true);

echo "Check-in adapter tests passed\n";
