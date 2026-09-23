<?php

declare(strict_types=1);

namespace tianyi;

use app\service\CheckinAdapterBase;
use app\service\PlatformException;
use app\service\PlatformVerification;
use GuzzleHttp\Cookie\CookieJar;

/**
 * 天翼云盘 PC-client login, session renewal and daily sign-in.
 *
 * Protocol checked 2026-09-23 against OpenList drivers/189pc (2026-08/09,
 * token refresh from PR #2245), wes-lin/cloud189-sdk v1.0.9 (2026-03) and
 * wes-lin/Cloud189Checkin (maintainer confirmed working on 2026-03-14).
 * The password is used for the interactive login only and never stored; the
 * session is renewed with the access/refresh token pair afterwards.
 */
final class Tianyi extends CheckinAdapterBase
{
    protected const HOSTS = ['cloud.189.cn', 'open.e.189.cn', 'api.cloud.189.cn'];
    private const APP_ID = '8025431004';
    private const RETURN_URL = 'https://m.cloud.189.cn/zhuanti/2020/loginErrorPc/index.html';
    private const CLIENT = ['clientType' => 'TELEPC', 'version' => '6.2', 'channelId' => 'web_cloud.189.cn'];
    private const JSON = 'application/json;charset=UTF-8';
    private const CAPTCHA_CODES = ['-2', '51177', '-1101', '51175', '51176'];

    public function authenticate(array $credentials): array
    {
        $username = $this->secret($credentials, 'username', 128);
        $password = $this->secret($credentials, 'password', 512);
        $this->credentials = [];
        $this->login($username, $password, (array)($credentials['login_context'] ?? []),
            trim((string)($credentials['captcha_code'] ?? '')));
        $loginName = trim((string)($this->credentials['login_name'] ?? '')) ?: $username;
        return $this->identity(substr(hash('sha256', 'tianyi:' . strtolower($loginName)), 0, 32),
            self::mask($loginName), $this->overview());
    }

    public function profile(array $account): array
    {
        $this->restore($account);
        $this->ensureSession();
        return $this->overview();
    }

    public function execute(array $account, array $state = [], ?callable $checkpoint = null): array
    {
        $this->restore($account);
        $this->ensureSession();
        // A signed call first: it renews an expired session, whereas the web
        // sign-in host would only answer with a login page.
        $overview = $this->overview();
        $response = $this->sign();
        $already = self::yes($response['isSign']);
        $bonus = max(0, (int)$response['netdiskBonus']);
        $summary = ['signed_before' => $already, 'bonus_mb' => $already ? 0 : $bonus] + $overview['stats'];
        return $this->result(true, $already ? '签到=今日已完成（此前已签到）' : '签到=完成 | 空间奖励=' . $bonus . ' MB',
            [], 0, $summary);
    }

    public static function signature(string $secret, string $key, string $path, string $date, string $method = 'GET'): string
    {
        return strtoupper(hash_hmac('sha1', 'SessionKey=' . $key . '&Operate=' . $method . '&RequestURI=' . $path
            . '&Date=' . $date, $secret));
    }

    private function ensureSession(): void
    {
        if (empty($this->credentials['session_key']) || empty($this->credentials['session_secret'])) {
            $this->renewSession();
        }
    }

    private function overview(): array
    {
        $capacity = $this->signed('/portal/getUserSizeInfo.action');
        $cloud = is_array($capacity['cloudCapacityInfo'] ?? null) ? $capacity['cloudCapacityInfo'] : [];
        $family = is_array($capacity['familyCapacityInfo'] ?? null) ? $capacity['familyCapacityInfo'] : [];
        $number = static fn(mixed $value): ?int => is_numeric($value) ? max(0, (int)$value) : null;
        $rows = [
            ['label' => '个人云容量', 'value' => self::bytes($number($cloud['totalSize'] ?? null))],
            ['label' => '已用空间', 'value' => self::bytes($number($cloud['usedSize'] ?? null))],
            ['label' => '剩余空间', 'value' => self::bytes($number($cloud['freeSize'] ?? null))],
        ];
        if ($number($family['totalSize'] ?? null) !== null) {
            $rows[] = ['label' => '家庭云容量', 'value' => self::bytes($number($family['totalSize']))];
        }
        $rows[] = ['label' => '登录状态', 'value' => '正常（会话自动续期）'];
        return ['rows' => $rows, 'stats' => array_filter([
            'capacity_total' => $number($cloud['totalSize'] ?? null),
            'capacity_used' => $number($cloud['usedSize'] ?? null),
            'family_total' => $number($family['totalSize'] ?? null),
            'family_used' => $number($family['usedSize'] ?? null),
        ], 'is_int')];
    }

    private function sign(bool $retry = true): array
    {
        $response = $this->jsonResponse('GET', 'https://cloud.189.cn/mkt/userSign.action', [
            'headers' => ['Accept' => self::JSON, 'Referer' => 'https://cloud.189.cn/web/main/'],
            'query' => ['rand' => self::millis(), 'clientType' => 'TELEANDROID', 'version' => '9.0.6',
                'model' => 'KB2000', 'sessionKey' => $this->secret($this->credentials, 'session_key', 512)],
        ]);
        if (self::sessionExpired($response)) {
            if (!$retry) {
                throw new PlatformException('天翼登录会话已过期，请重新登录', true, 0);
            }
            $this->renewSession();
            return $this->sign(false);
        }
        $data = $response['data'];
        if ($response['status'] !== 200 || !array_key_exists('isSign', $data) || !is_numeric($data['netdiskBonus'] ?? null)
            || !in_array($data['isSign'], [true, false, 'true', 'false', 0, 1, '0', '1'], true)) {
            throw new PlatformException('天翼签到未返回有效结果，稍后核验');
        }
        return $data;
    }

    private function signed(string $path, array $query = [], bool $retry = true): array
    {
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $key = $this->secret($this->credentials, 'session_key', 512);
        $response = $this->jsonResponse('GET', 'https://api.cloud.189.cn' . $path, [
            'headers' => ['Accept' => self::JSON, 'Date' => $date, 'SessionKey' => $key,
                'Signature' => self::signature($this->secret($this->credentials, 'session_secret', 512), $key, $path, $date),
                'X-Request-ID' => bin2hex(random_bytes(16))],
            'query' => array_replace(self::CLIENT, $query),
        ]);
        if (self::sessionExpired($response)) {
            if (!$retry) {
                throw new PlatformException('天翼登录会话已过期，请重新登录', true, 0);
            }
            $this->renewSession();
            return $this->signed($path, $query, false);
        }
        if ($response['status'] !== 200 || (string)($response['data']['res_code'] ?? '0') !== '0') {
            throw new PlatformException('天翼云盘接口未返回成功结果，稍后重试');
        }
        return $response['data'];
    }

    private static function sessionExpired(array $response): bool
    {
        $data = $response['data'];
        foreach (['errorCode', 'res_code', 'code'] as $field) {
            if (in_array((string)($data[$field] ?? ''), ['InvalidSessionKey', 'InvalidSession', 'UserInvalidOpenToken'], true)) {
                return true;
            }
        }
        return in_array($response['status'], [400, 401], true)
            && str_contains(strtolower(json_encode($data) ?: ''), 'usersessionbo is null');
    }

    /** sessionKey -> accessToken -> refreshToken; never the stored password. */
    private function renewSession(): void
    {
        $accessToken = trim((string)($this->credentials['access_token'] ?? ''));
        $session = $accessToken !== '' ? $this->sessionByToken($accessToken) : null;
        if ($session === null) {
            $refresh = trim((string)($this->credentials['refresh_token'] ?? ''));
            if ($refresh === '') {
                throw new PlatformException('天翼登录会话已过期，请重新登录', true, 0);
            }
            $tokens = $this->jsonResponse('POST', 'https://open.e.189.cn/api/oauth2/refreshToken.do', [
                'headers' => ['Accept' => 'application/json'],
                'form_params' => ['clientId' => self::APP_ID, 'refreshToken' => $refresh,
                    'grantType' => 'refresh_token', 'format' => 'json'],
            ])['data'];
            if (!is_string($tokens['accessToken'] ?? null) || $tokens['accessToken'] === '') {
                throw new PlatformException('天翼登录会话已过期，请重新登录', true, 0);
            }
            $this->saveCredentials(['access_token' => $tokens['accessToken'],
                'refresh_token' => is_string($tokens['refreshToken'] ?? null) && $tokens['refreshToken'] !== ''
                    ? $tokens['refreshToken'] : $refresh]);
            $session = $this->sessionByToken($tokens['accessToken']);
            if ($session === null) {
                throw new PlatformException('天翼登录会话已过期，请重新登录', true, 0);
            }
        }
        $this->storeSession($session);
    }

    private function sessionByToken(string $accessToken): ?array
    {
        $response = $this->jsonResponse('GET', 'https://api.cloud.189.cn/getSessionForPC.action', [
            'headers' => ['Accept' => self::JSON],
            'query' => ['appId' => self::APP_ID, 'accessToken' => $accessToken] + self::CLIENT + ['rand' => self::millis()],
        ]);
        if ((string)($response['data']['res_code'] ?? '') === '0') {
            return $response['data'];
        }
        if (self::sessionExpired($response) || in_array($response['status'], [400, 401], true)) {
            return null;
        }
        throw new PlatformException('天翼登录会话续期失败，稍后重试');
    }

    private function storeSession(array $session): void
    {
        if ((string)($session['res_code'] ?? '') !== '0' || !is_string($session['sessionKey'] ?? null)
            || !is_string($session['sessionSecret'] ?? null) || $session['sessionKey'] === '' || $session['sessionSecret'] === '') {
            throw new PlatformException('天翼登录会话创建失败，稍后重试');
        }
        $changes = ['session_key' => $session['sessionKey'], 'session_secret' => $session['sessionSecret']];
        foreach (['accessToken' => 'access_token', 'refreshToken' => 'refresh_token', 'loginName' => 'login_name'] as $from => $to) {
            if (is_string($session[$from] ?? null) && $session[$from] !== '') {
                $changes[$to] = $session[$from];
            }
        }
        $this->saveCredentials($changes);
    }

    private function login(string $username, string $password, array $context, string $captcha): void
    {
        $cookies = new CookieJar(false, (array)($context['cookies'] ?? []));
        if ($context === [] || (int)($context['expires_at'] ?? 0) < time()
            || !hash_equals((string)($context['account'] ?? ''), self::accountHash($username))) {
            $cookies = new CookieJar();
            $context = $this->prepareLogin($username, $cookies);
            $captcha = '';
        }
        if (!empty($context['needs_captcha']) && $captcha === '') {
            $this->challenge($context, $cookies, '请输入图片验证码后继续登录');
        }
        $response = $this->jsonResponse('POST', 'https://open.e.189.cn/api/logbox/oauth2/loginSubmit.do', [
            'cookies' => $cookies,
            'headers' => ['lt' => $context['lt'], 'REQID' => $context['reqId'], 'Referer' => 'https://open.e.189.cn/'],
            'form_params' => ['appKey' => self::APP_ID, 'accountType' => '02',
                'userName' => $this->encrypt($username, $context), 'password' => $this->encrypt($password, $context),
                'validateCode' => $captcha, 'captchaToken' => $context['captchaToken'], 'returnUrl' => self::RETURN_URL,
                'dynamicCheck' => 'FALSE', 'clientType' => '10020', 'cb_SaveName' => '1', 'isOauth2' => 'false',
                'state' => '', 'paramId' => $context['paramId']],
        ]);
        $code = (string)($response['data']['result'] ?? '');
        if ($code === '0' && is_string($response['data']['toUrl'] ?? null) && $response['data']['toUrl'] !== '') {
            $session = $this->json('POST', 'https://api.cloud.189.cn/getSessionForPC.action', [
                'cookies' => $cookies, 'headers' => ['Accept' => self::JSON],
                'query' => self::CLIENT + ['rand' => self::millis(), 'redirectURL' => $response['data']['toUrl']],
            ]);
            $this->storeSession($session);
            return;
        }
        if (in_array($code, self::CAPTCHA_CODES, true)) {
            // A captcha is single-use: start over with a new page and image.
            $cookies = new CookieJar();
            $this->challenge($this->prepareLogin($username, $cookies), $cookies,
                $captcha === '' ? '请输入图片验证码后继续登录' : '验证码错误或已过期，请重新输入');
        }
        throw self::loginError($code);
    }

    private function prepareLogin(string $username, CookieJar $cookies): array
    {
        $page = $this->request('GET', 'https://cloud.189.cn/api/portal/unifyLoginForPC.action', [
            'cookies' => $cookies, 'headers' => ['Accept' => 'text/html,application/xhtml+xml'],
            'query' => ['appId' => self::APP_ID, 'clientType' => '10020', 'returnURL' => self::RETURN_URL,
                'timeStamp' => self::millis()],
        ]);
        $context = ['account' => self::accountHash($username), 'expires_at' => time() + 300];
        foreach (['lt', 'reqId', 'paramId'] as $field) {
            if (!preg_match('/\b' . $field . '\s*=\s*[\'"]([^\'"]+)[\'"]/', $page['body'], $match)) {
                throw new PlatformException('天翼登录页面发生变化，请稍后重试');
            }
            $context[$field] = $match[1];
        }
        preg_match('/[\'"]captchaToken[\'"][^>]*value=[\'"]([^\'"]*)[\'"]/', $page['body'], $match);
        $context['captchaToken'] = $match[1] ?? '';
        preg_match('/id=[\'"]j_rsaKey[\'"][^>]*value=[\'"]([A-Za-z0-9+\/=]+)[\'"]/', $page['body'], $match);
        [$context['pub_key'], $context['pre']] = $this->encryptionKey($cookies, $match[1] ?? '');
        $need = $this->request('POST', 'https://open.e.189.cn/api/logbox/oauth2/needcaptcha.do', [
            'cookies' => $cookies, 'headers' => ['REQID' => $context['reqId'], 'Referer' => 'https://open.e.189.cn/'],
            'form_params' => ['appKey' => self::APP_ID, 'accountType' => '02', 'userName' => $this->encrypt($username, $context)],
        ]);
        $context['needs_captcha'] = trim($need['body']) !== '0';
        return $context;
    }

    private function encryptionKey(CookieJar $cookies, string $pageKey): array
    {
        try {
            $config = $this->json('POST', 'https://open.e.189.cn/api/logbox/config/encryptConf.do',
                ['cookies' => $cookies, 'form_params' => ['appId' => self::APP_ID]]);
            $key = (string)($config['data']['pubKey'] ?? '');
            if (preg_match('/\A[A-Za-z0-9+\/=]{64,2048}\z/', $key) === 1) {
                return [$key, (string)($config['data']['pre'] ?? '') ?: '{RSA}'];
            }
        } catch (PlatformException $exception) {
            if ($pageKey === '') {
                throw $exception;
            }
        }
        // The official page falls back to the embedded key with {RSA}.
        if ($pageKey !== '') {
            return [$pageKey, '{RSA}'];
        }
        throw new PlatformException('天翼登录加密参数缺失，稍后重试');
    }

    private function encrypt(string $value, array $context): string
    {
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split((string)$context['pub_key'], 64, "\n") . "-----END PUBLIC KEY-----\n";
        if (!openssl_public_encrypt($value, $encrypted, $pem, OPENSSL_PKCS1_PADDING)) {
            throw new PlatformException('天翼登录加密失败，请检查账号和密码长度', false, 0);
        }
        return (string)$context['pre'] . strtoupper(bin2hex($encrypted));
    }

    private function challenge(array $context, CookieJar $cookies, string $message): never
    {
        $image = $this->request('GET', 'https://open.e.189.cn/api/logbox/oauth2/picCaptcha.do', [
            'cookies' => $cookies, 'headers' => ['Accept' => 'image/*', 'Referer' => 'https://open.e.189.cn/'],
            'query' => ['token' => $context['captchaToken'], 'REQID' => $context['reqId'], 'rnd' => self::millis()],
        ]);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($image['body']);
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true) || strlen($image['body']) > 1024 * 1024) {
            throw new PlatformException('天翼验证码获取失败，请稍后重新添加', false, 0);
        }
        $context['cookies'] = $cookies->toArray();
        $context['needs_captcha'] = true;
        throw new PlatformVerification($context, 'data:' . $mime . ';base64,' . base64_encode($image['body']), $message);
    }

    private static function loginError(string $code): PlatformException
    {
        $message = match (true) {
            in_array($code, ['-1', '-9', '-10', '-15', '-16', '-17', '-69', '-149', '-150', '51001', '51002', '-51002',
                '51005', '51101'], true) => '天翼账号或密码错误',
            in_array($code, ['-66', '51323'], true) => '登录失败次数过多，请稍后再试',
            $code === '51179' => '天翼账号已被冻结，请通过官方渠道处理',
            $code === '51325' => '天翼账号已被停用',
            in_array($code, ['-70', '68', '-3'], true) => '当前服务器网络被天翼限制登录，请稍后再试',
            in_array($code, ['-4', '51317', '-51317', '51014'], true) => '天翼要求提升密码强度，请先在官方渠道修改密码',
            $code === '-30199' => '天翼要求修改密码，请在官方渠道修改后重新添加',
            in_array($code, ['-134', '-135', '-136'], true) => '账号开启了设备锁或二次验证，请在天翼云盘中关闭后重试',
            default => '天翼登录失败（错误码 ' . (preg_match('/\A-?\d{1,8}\z/', $code) === 1 ? $code : '未知') . '）',
        };
        return new PlatformException($message, true, 0);
    }

    private static function accountHash(string $username): string
    {
        return hash('sha256', strtolower(trim($username)));
    }

    private static function millis(): string
    {
        return (string)(int)round(microtime(true) * 1000);
    }
}
