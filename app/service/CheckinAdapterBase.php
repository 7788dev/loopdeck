<?php

declare(strict_types=1);

namespace app\service;

use Closure;

/** Shared request budget, response decoding and value helpers for adapters. */
abstract class CheckinAdapterBase implements CheckinAdapter
{
    protected const HOSTS = [];
    protected CheckinTransport $transport;
    protected array $credentials = [];
    private float $deadline;

    public function __construct(
        ?CheckinTransport $transport = null,
        protected ?Closure $persistCredentials = null,
        private ?Closure $sleep = null
    ) {
        $this->transport = $transport ?? new CheckinHttpTransport(static::HOSTS);
        $this->deadline = microtime(true) + 40.0;
    }

    public function credentials(): array
    {
        return $this->credentials;
    }

    /** Space out requests; offline tests inject a no-op sleeper. */
    protected function pause(float $seconds): void
    {
        if ($this->sleep !== null) {
            ($this->sleep)($seconds);
            return;
        }
        usleep((int)round(max(0.0, min($seconds, $this->remaining() - 1.0)) * 1_000_000));
    }

    protected function restore(array $account): void
    {
        $this->credentials = (array)($account['credentials'] ?? $account);
    }

    protected function saveCredentials(array $changes): void
    {
        $this->credentials = array_replace($this->credentials, $changes);
        if ($this->persistCredentials !== null) {
            ($this->persistCredentials)($this->credentials);
        }
    }

    /** Seconds left in this invocation's budget. */
    protected function remaining(): float
    {
        return $this->deadline - microtime(true);
    }

    protected function request(string $method, string $url, array $options = []): array
    {
        $remaining = $this->remaining();
        if ($remaining < 0.25) {
            throw new PlatformException('本次处理时间已到，稍后继续', false, 60);
        }
        $options['timeout'] = min(12.0, $remaining);
        $options['connect_timeout'] = min(4.0, $options['timeout']);
        $response = $this->transport->request($method, $url, $options);
        $status = (int)$response['status'];
        if ($status === 429 || $status >= 500 || $status === 0) {
            throw new PlatformException('平台繁忙或请求受限，稍后重试', false, $status === 429 ? 900 : 300);
        }
        if ($status === 403) {
            throw new PlatformException('平台拒绝了本次请求，请到官方客户端检查账号状态', false, 0);
        }
        return $response;
    }

    /**
     * Decode a JSON response without judging 4xx statuses, so an adapter can
     * read an upstream error code (for example an expired session) first.
     *
     * @return array{status:int,data:array,headers:array}
     */
    protected function jsonResponse(string $method, string $url, array $options = []): array
    {
        $response = $this->request($method, $url, $options);
        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            throw new PlatformException('平台返回格式异常，稍后重试');
        }
        return ['status' => (int)$response['status'], 'data' => $data, 'headers' => (array)($response['headers'] ?? [])];
    }

    protected function json(string $method, string $url, array $options = []): array
    {
        $response = $this->jsonResponse($method, $url, $options);
        if ($response['status'] === 401) {
            throw new PlatformException('登录状态已失效，请更新账号', true, 0);
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new PlatformException('平台拒绝请求，请检查账号状态', false, 0);
        }
        return $response['data'];
    }

    protected function secret(array $input, string $name, int $maximum = 8192): string
    {
        $value = is_scalar($input[$name] ?? null) ? (string)$input[$name] : '';
        if ($name !== 'password') {
            $value = trim($value);
        }
        if ($value === '' || strlen($value) > $maximum || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new PlatformException('登录凭据为空或格式不正确', true, 0);
        }
        return $value;
    }

    /**
     * The identity doubles as a route segment and an HTML/JS attribute value,
     * so only word characters are accepted.
     */
    protected function identity(string $id, string $name, array $profile, string $avatar = ''): array
    {
        if (preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $id) !== 1) {
            throw new PlatformException('未获取到有效的平台账号标识');
        }
        $name = trim(preg_replace('/[\x00-\x1f\x7f]/u', '', $name) ?? '');
        return ['user_id' => $id, 'nickname' => mb_substr($name, 0, 60),
            'avatar' => filter_var($avatar, FILTER_VALIDATE_URL) && str_starts_with($avatar, 'https://') ? $avatar : '',
            'credentials' => $this->credentials, 'profile' => $profile];
    }

    protected function result(bool $success, string $message, array $state = [], int $retryAfter = 0, array $summary = []): array
    {
        return ['success' => $success, 'message' => $message, 'account_invalid' => false,
            'retry_after_seconds' => $retryAfter, 'state' => $state, 'summary' => $summary];
    }

    protected static function yes(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true'], true);
    }

    /** Hide the middle of phone numbers and e-mail local parts. */
    public static function mask(string $value): string
    {
        $value = trim($value);
        if (preg_match('/\A(\+?\d{3})\d{4}(\d{4})(@.*)?\z/', $value, $match)) {
            return $match[1] . '****' . $match[2] . ($match[3] ?? '');
        }
        if (preg_match('/\A([^@]{1,2})[^@]*(@.+)\z/u', $value, $match)) {
            return $match[1] . '***' . $match[2];
        }
        return $value;
    }

    protected static function bytes(mixed $bytes): string
    {
        return CheckinView::bytes($bytes);
    }
}
