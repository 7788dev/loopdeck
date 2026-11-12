<?php

declare(strict_types=1);

namespace anyrouter;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Promise\PromiseInterface;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

final class Client
{
    public const ORIGIN = 'https://anyrouter.top';
    public const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36';
    private ClientInterface $http;
    private CookieJar $cookies;
    private bool $ready = false;
    private string $apiUser = '';
    private array $secrets = [];
    private array $site = [];

    public function __construct(private string $proxy, ?ClientInterface $http = null)
    {
        if ($proxy === '') {
            throw new InvalidArgumentException('AnyRouter 需要通过代理访问');
        }
        $this->http = $http ?? new HttpClient();
        $this->cookies = new CookieJar();
        $parts = parse_url($proxy);
        $this->secrets = [$proxy, rawurldecode($parts['pass'] ?? '')];
    }

    public static function parseCookies(string $input): array
    {
        $input = trim($input);
        if ($input === '' || strlen($input) > 16384 || preg_match('/[\x00-\x1f\x7f]/', $input)) {
            throw new InvalidArgumentException('请填写有效的 AnyRouter Cookie');
        }
        $values = str_starts_with($input, '{') ? json_decode($input, true) : null;
        if (!is_array($values)) {
            $values = [];
            foreach (explode(';', $input) as $part) {
                $pair = explode('=', trim($part), 2);
                if (count($pair) === 2) {
                    $values[trim($pair[0])] = trim($pair[1]);
                }
            }
        }
        $session = $values['session'] ?? null;
        if (!is_string($session) || $session === '' || strlen($session) > 8192
            || preg_match('/[\x00-\x20\x7f;,]/', $session)) {
            throw new InvalidArgumentException('Cookie 中缺少有效的 session，请重新复制');
        }
        // WAF cookies must be refreshed using this run's proxy, never the browser's old exit IP.
        return ['session' => $session];
    }

    public static function normalizeUser(string $value): string
    {
        $value = trim($value);
        if (preg_match('/\A[1-9]\d{0,18}\z/', $value) !== 1) {
            throw new InvalidArgumentException('API User 应为登录后请求头中的正整数用户 ID');
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

    public function authenticate(array $cookies, string $apiUser): array
    {
        if (!$this->ready) {
            throw new RuntimeException('代理尚未通过 AnyRouter 连通性验证');
        }
        $this->apiUser = self::normalizeUser($apiUser);
        $auth = self::parseCookies('session=' . (string)($cookies['session'] ?? ''));
        $this->secrets[] = $auth['session'];
        $this->cookies->setCookie(new SetCookie(['Name' => 'session', 'Value' => $auth['session'],
            'Domain' => 'anyrouter.top', 'Path' => '/', 'Secure' => true, 'HttpOnly' => true]));
        try {
            $response = $this->getAsync('/api/user/self', microtime(true) + 15)->wait();
            $body = self::payload($response);
            $error = $this->responseError($response, $body);
            if ($error !== null) {
                return $error;
            }
            if (($body['success'] ?? null) !== true || !is_array($body['data'] ?? null)) {
                return $this->failure($this->message($body), false, false);
            }
            if ((string)($body['data']['id'] ?? '') !== $this->apiUser) {
                return $this->failure('API User 与 Cookie 对应的账号不一致，请重新复制', true, false);
            }
            $data = $body['data'];
            $username = self::profileText($data['username'] ?? '');
            $name = self::profileText($data['display_name'] ?? '') ?: ($username ?: $this->apiUser);
            $unit = self::number($this->site['quota_per_unit'] ?? null);
            $requests = self::number($data['request_count'] ?? null);
            return ['success' => true, 'account_invalid' => false, 'retryable' => false,
                // /self also contains tokens and private account fields. Never retain the full payload.
                'profile' => ['id' => $this->apiUser, 'nickname' => $name, 'username' => $username,
                    'quota' => self::number($data['quota'] ?? null),
                    'used_quota' => self::number($data['used_quota'] ?? null),
                    'request_count' => $requests !== null && $requests >= 0 && floor($requests) === $requests ? (int)$requests : null,
                    'group' => self::profileText($data['group'] ?? ''),
                    'quota_per_unit' => $unit !== null && $unit > 0 ? $unit : null,
                    'display_in_currency' => is_bool($this->site['display_in_currency'] ?? null)
                        ? $this->site['display_in_currency'] : null]];
        } catch (Throwable $exception) {
            return $this->failure('代理连接中断或响应超时，请稍后重试', false, true);
        }
    }

    /** Exactly one POST per execution. Only read-only GETs may refresh and replay a WAF challenge. */
    public function signIn(): array
    {
        if (!$this->ready || $this->apiUser === '') {
            throw new RuntimeException('请先验证 AnyRouter 账号');
        }
        try {
            $response = $this->http->request('POST', self::ORIGIN . '/api/user/sign_in',
                $this->options(microtime(true) + 15) + ['body' => '']);
            $body = self::payload($response);
            $error = $this->responseError($response, $body);
            if ($error !== null) {
                return $error;
            }
            $message = $this->message($body);
            $already = preg_match('/已经签到|已签到|重复签到|already (?:checked|signed)/iu', $message) === 1;
            // A contradictory explicit failure wins over a generic code=0 field.
            $success = array_key_exists('success', $body) ? $body['success'] === true
                : (($body['ret'] ?? null) === 1 || ($body['code'] ?? null) === 0);
            return ['success' => $success || $already, 'already' => $already, 'account_invalid' => false,
                'retryable' => false, 'reason' => $success || $already ? '' : $message];
        } catch (Throwable $exception) {
            return $this->failure('签到响应未确认，请稍后重试', false, true);
        }
    }

    private function getAsync(string $path, float $deadline, int $attempt = 0): PromiseInterface
    {
        return $this->http->requestAsync('GET', self::ORIGIN . $path, $this->options($deadline))->then(
            function (ResponseInterface $response) use ($path, $deadline, $attempt) {
                $body = (string)$response->getBody();
                $cookie = strlen($body) <= 1048576 ? WafCookie::fromHtml($body) : null;
                if ($cookie !== null && $attempt < 2 && microtime(true) < $deadline) {
                    $this->cookies->setCookie(new SetCookie(['Name' => 'acw_sc__v2', 'Value' => $cookie,
                        'Domain' => 'anyrouter.top', 'Path' => '/', 'Secure' => true, 'Expires' => time() + 3600]));
                    return $this->getAsync($path, $deadline, $attempt + 1);
                }
                return $response;
            }
        );
    }

    private function options(float $deadline): array
    {
        $remaining = max(0.05, $deadline - microtime(true));
        $headers = ['User-Agent' => self::USER_AGENT, 'Accept' => 'application/json',
            'Content-Type' => 'application/json', 'Origin' => self::ORIGIN, 'Referer' => self::ORIGIN . '/console',
            'X-Requested-With' => 'XMLHttpRequest'];
        if ($this->apiUser !== '') {
            $headers['new-api-user'] = $this->apiUser;
        }
        return ['headers' => $headers, 'cookies' => $this->cookies, 'proxy' => $this->proxy,
            // Explicit curl options also defeat ALL_PROXY / NO_PROXY on the host.
            'curl' => [CURLOPT_PROXY => $this->proxy, CURLOPT_NOPROXY => ''],
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

    private function responseError(ResponseInterface $response, ?array $body): ?array
    {
        $status = $response->getStatusCode();
        if ($status === 401 || ($body !== null && ($body['success'] ?? null) === false
            && preg_match('/未登录|登录已过期|登录.*失效|无权进行此操作.*登录|invalid session|not logged in|unauthorized/iu', $this->message($body)))) {
            return $this->failure('登录状态已失效，请更新 Cookie', true, false);
        }
        if ($body === null) {
            return $this->failure('网站验证未通过，请稍后重试或更新代理', false, true);
        }
        if ($status !== 200) {
            return $this->failure('AnyRouter 暂时无法访问（HTTP ' . $status . '）', false, $status === 403 || $status === 429 || $status >= 500);
        }
        return null;
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
