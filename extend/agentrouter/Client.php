<?php

declare(strict_types=1);

namespace agentrouter;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\PromiseInterface;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * AgentRouter speaks the new-api protocol. The daily check-in is triggered by
 * the login POST itself, so exactly one POST is sent per execution and the
 * balance is read in that session before logging out. Every execution creates
 * a fresh session. The site is reached directly; no proxy is involved.
 */
final class Client
{
    public const ORIGIN = 'https://ps.air-outer.com';
    public const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36';
    private ClientInterface $http;
    private CookieJar $cookies;
    private bool $loggedIn = false;
    private bool $ready = false;
    private string $apiUser = '';
    private array $secrets = [];
    private array $site = [];

    public function __construct(?ClientInterface $http = null)
    {
        $this->http = $http ?? new HttpClient();
        $this->cookies = new CookieJar();
    }

    public static function normalizeUsername(string $value): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 128 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException('请填写有效的 AgentRouter 用户名');
        }
        return $value;
    }

    public static function normalizePassword(string $value): string
    {
        if ($value === '' || strlen($value) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException('请填写有效的 AgentRouter 密码');
        }
        return $value;
    }

    /** Read-only probe, with no account credentials attached. */
    public function probeAsync(float $deadline): PromiseInterface
    {
        return $this->getAsync('/api/status', $deadline)->then(function (ResponseInterface $response): bool {
            $body = self::payload($response);
            $this->ready = $response->getStatusCode() === 200 && ($body['success'] ?? null) === true
                && is_array($body['data'] ?? null)
                && (isset($body['data']['system_name']) || isset($body['data']['version']));
            if ($this->ready) {
                $this->site = array_intersect_key($body['data'], array_flip(['quota_per_unit', 'display_in_currency']));
            }
            return $this->ready;
        }, static fn(): bool => false);
    }

    /**
     * Fresh login, balance lookup and logout form one daily execution.
     * Balance failure is display-only; logout must still close the session.
     */
    public function login(string $username, string $password): array
    {
        if (!$this->ready) {
            throw new RuntimeException('尚未通过 AgentRouter 连通性验证');
        }
        $username = self::normalizeUsername($username);
        $password = self::normalizePassword($password);
        $this->cookies->clear();
        $this->apiUser = '';
        $this->loggedIn = false;
        $this->secrets = [$password];
        $loggedOut = true;
        try {
            $result = $this->loginAndReadProfile($username, $password);
        } catch (Throwable $exception) {
            $result = $this->failure('登录响应未确认，请稍后重试', false, true);
        } finally {
            if ($this->loggedIn) {
                $loggedOut = $this->logout();
            }
            // Never persist this session or reuse it on tomorrow's login.
            $this->cookies->clear();
            $this->apiUser = '';
            $this->loggedIn = false;
        }
        if ($result['success'] && !$loggedOut) {
            return $this->failure('登录已完成，但退出登录未确认，请稍后重试', false, true);
        }
        return $result;
    }

    private function loginAndReadProfile(string $username, string $password): array
    {
        $response = $this->http->request('POST', self::ORIGIN . '/api/user/login',
            $this->options(microtime(true) + 15) + ['json' => ['username' => $username, 'password' => $password]]);
        $text = (string)$response->getBody();
        if (stripos($text, 'aliyun_waf') !== false) {
            return $this->failure('网站验证未通过，请稍后重试', false, true);
        }
        $body = self::payload($response);
        $status = $response->getStatusCode();
        if ($status === 401 || ($body !== null && ($body['success'] ?? null) === false
            && preg_match('/用户名或密码|密码错误|账号或密码|密码不正确|invalid (?:username|password)|incorrect (?:username|password)|user.*not.*exist/iu', $this->message($body)))) {
            return $this->failure('用户名或密码错误，请更新账号凭据', true, false);
        }
        if ($body === null) {
            return $this->failure('网站验证未通过，请稍后重试', false, true);
        }
        if ($status !== 200) {
            return $this->failure('AgentRouter 暂时无法访问（HTTP ' . $status . '）', false, $status === 403 || $status === 429 || $status >= 500);
        }
        if (($body['success'] ?? null) !== true || !is_array($body['data'] ?? null)) {
            return $this->failure($this->message($body), false, true);
        }
        $this->loggedIn = true;
        $data = $body['data'];
        $id = trim((string)($data['id'] ?? ''));
        if ($id === '' || preg_match('/\A[1-9]\d{0,18}\z/', $id) !== 1) {
            return $this->failure('登录响应缺少有效的用户 ID', false, true);
        }
        $this->apiUser = $id;
        $checkedIn = !empty($data['checked_in']);
        $accessToken = is_string($data['access_token'] ?? null) ? trim($data['access_token']) : '';
        if ($accessToken !== '') {
            $this->secrets[] = $accessToken;
        }
        return ['success' => true, 'account_invalid' => false, 'retryable' => false,
            'already' => !$checkedIn, 'profile' => $this->fetchProfile($data, $username, $accessToken)];
    }

    private function logout(): bool
    {
        try {
            $headers = $this->apiUser !== '' ? ['new-api-user' => $this->apiUser] : [];
            // Logout changes the session: send it once, without the read-only WAF retry loop.
            $response = $this->http->request('GET', self::ORIGIN . '/api/user/logout',
                $this->options(microtime(true) + 15, $headers));
            $body = self::payload($response);
            return $response->getStatusCode() === 200 && ($body['success'] ?? null) === true;
        } catch (Throwable $exception) {
            return false;
        }
    }

    /** Read-only balance/profile lookup using the login session and user id. */
    private function fetchProfile(array $loginData, string $username, string $accessToken): array
    {
        $data = $loginData;
        try {
            $headers = ['new-api-user' => $this->apiUser];
            if ($accessToken !== '') {
                $headers['Authorization'] = $accessToken;
            }
            $response = $this->getAsync('/api/user/self', microtime(true) + 15, 0, $headers)->wait();
            $body = self::payload($response);
            if ($response->getStatusCode() === 200 && ($body['success'] ?? null) === true && is_array($body['data'] ?? null)) {
                $data = array_replace($loginData, $body['data']);
            }
        } catch (Throwable $exception) {
            // The check-in already succeeded; keep whatever the login response provided.
        }
        $name = self::profileText($data['display_name'] ?? '') ?: (self::profileText($data['username'] ?? '') ?: $username);
        $unit = self::number($this->site['quota_per_unit'] ?? null);
        $requests = self::number($data['request_count'] ?? null);
        // /self also contains tokens and private account fields. Never retain the full payload.
        return ['id' => $this->apiUser, 'nickname' => $name, 'username' => self::profileText($data['username'] ?? '') ?: $username,
            'quota' => self::number($data['quota'] ?? null),
            'used_quota' => self::number($data['used_quota'] ?? null),
            'request_count' => $requests !== null && $requests >= 0 && floor($requests) === $requests ? (int)$requests : null,
            'group' => self::profileText($data['group'] ?? ''),
            'quota_per_unit' => $unit !== null && $unit > 0 ? $unit : null,
            'display_in_currency' => is_bool($this->site['display_in_currency'] ?? null) ? $this->site['display_in_currency'] : null];
    }

    private function getAsync(string $path, float $deadline, int $attempt = 0, array $extraHeaders = []): PromiseInterface
    {
        return $this->http->requestAsync('GET', self::ORIGIN . $path, $this->options($deadline, $extraHeaders))->then(
            function (ResponseInterface $response) use ($path, $deadline, $attempt, $extraHeaders) {
                $body = (string)$response->getBody();
                if (stripos($body, 'aliyun_waf') !== false && $attempt < 2 && microtime(true) < $deadline) {
                    return $this->getAsync($path, $deadline, $attempt + 1, $extraHeaders);
                }
                return $response;
            }
        );
    }

    private function options(float $deadline, array $extraHeaders = []): array
    {
        $remaining = max(0.05, $deadline - microtime(true));
        $headers = ['User-Agent' => self::USER_AGENT, 'Accept' => 'application/json',
            'Content-Type' => 'application/json', 'Origin' => self::ORIGIN, 'Referer' => self::ORIGIN . '/console',
            'X-Requested-With' => 'XMLHttpRequest'] + $extraHeaders;
        return ['headers' => $headers, 'cookies' => $this->cookies,
            // Direct connection: explicitly defeat ALL_PROXY / NO_PROXY on the host.
            'proxy' => '', 'curl' => [CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*'],
            'verify' => true, 'http_errors' => false, 'allow_redirects' => false,
            'connect_timeout' => min(2.5, $remaining), 'timeout' => min(8.0, $remaining),
            'progress' => static function ($total, $downloaded): void {
                if ($total > 1048576 || $downloaded > 1048576) {
                    throw new RuntimeException('上游响应过大');
                }
            }];
    }

    private static function payload(ResponseInterface $response): ?array
    {
        $text = (string)$response->getBody();
        $body = strlen($text) <= 1048576 ? json_decode($text, true) : null;
        return is_array($body) ? $body : null;
    }

    private function message(array $body): string
    {
        $value = $body['message'] ?? $body['msg'] ?? '';
        $message = is_string($value) ? trim(strip_tags($value)) : '';
        foreach ($this->secrets as $secret) {
            if ($secret !== '') {
                $message = str_replace($secret, '[已隐藏]', $message);
            }
        }
        $message = preg_replace('/[\x00-\x1f\x7f]|(?:https?|socks5h?):\/\/\S+/i', '', $message) ?? '';
        return $message !== '' ? mb_substr($message, 0, 180) : '网站未返回有效的签到结果';
    }

    private function failure(string $reason, bool $invalid, bool $retryable): array
    {
        return ['success' => false, 'account_invalid' => $invalid, 'retryable' => $retryable, 'reason' => $reason];
    }

    private static function number($value): ?float
    {
        if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value)) {
            return null;
        }
        $number = (float)$value;
        return is_finite($number) && abs($number) <= 1.0e18 ? $number : null;
    }

    private static function profileText($value): string
    {
        if (!is_string($value)) {
            return '';
        }
        return mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]/', '', strip_tags($value)) ?? ''), 0, 80);
    }
}
