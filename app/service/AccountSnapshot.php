<?php

declare(strict_types=1);

namespace app\service;

use think\facade\Cache;
use Throwable;

final class AccountSnapshot
{
    private const TTL = 7 * 86400;

    public function key(array $account): string
    {
        return 'account_snapshot_v2_' . hash('sha256', implode("\0", [
            (string)($account['uid'] ?? ''), (string)($account['type'] ?? ''),
            (string)($account['user_id'] ?? ''), self::generation($account),
        ]));
    }

    /** Reading a page never starts an upstream request, including a cold cache. */
    public function read(array $account): array
    {
        $snapshot = Cache::get($this->key($account));
        $snapshot = is_array($snapshot) ? $snapshot : [];
        return array_replace([
            'rows' => [], 'stats' => [], 'signature' => '', 'updated_at' => 0, 'warning' => '',
            'account_invalid' => false, 'retry_at' => 0,
        ], $snapshot, [
            'type' => (string)$account['type'], 'user_id' => (string)$account['user_id'],
            'stale' => (int)($snapshot['updated_at'] ?? 0) < time() - 300
                && (int)($snapshot['retry_at'] ?? 0) <= time() && (int)($account['state'] ?? 0) === 1,
        ]);
    }

    /** Store the overview obtained while the user added or updated an account. */
    public function prime(array $account, array $data): void
    {
        Cache::set($this->key($account), self::clean($data) + [
            'updated_at' => time(), 'warning' => '', 'account_invalid' => false, 'retry_at' => 0,
        ], self::TTL);
    }

    public function forget(array $account): void
    {
        Cache::delete($this->key($account));
    }

    public function refresh(array $account, ?callable $fetcher = null): array
    {
        $current = $this->read($account);
        if ((int)$current['updated_at'] > time() - 30 || (int)$current['retry_at'] > time()) {
            return $current;
        }
        $directory = runtime_path() . 'profile-locks' . DIRECTORY_SEPARATOR;
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('无法创建账号刷新锁');
        }
        $lock = @fopen($directory . hash('sha256', $this->key($account)) . '.lock', 'c+');
        if (!is_resource($lock)) {
            throw new \RuntimeException('无法读取账号刷新锁');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return array_replace($current, ['warning' => '账号信息正在刷新，请稍后查看']);
        }
        try {
            $current = $this->read($account);
            if ((int)$current['updated_at'] > time() - 30 || (int)$current['retry_at'] > time()) {
                return $current;
            }
            try {
                $data = ($fetcher ?? [new AccountProfileFetcher(), 'fetch'])($account);
                $saved = self::clean(is_array($data) ? $data : []) + [
                    'updated_at' => time(), 'warning' => '', 'account_invalid' => false, 'retry_at' => 0,
                ];
            } catch (Throwable $exception) {
                $invalid = $exception instanceof PlatformVerification
                    || ($exception instanceof PlatformException && $exception->accountInvalid);
                $warning = match (true) {
                    $exception instanceof PlatformVerification => '重新登录需要验证码，请更新账号',
                    $exception instanceof PlatformException => $exception->getMessage(),
                    default => '暂时无法刷新账号信息，请稍后重试',
                };
                $saved = array_intersect_key($current, array_flip(['rows', 'stats', 'signature', 'updated_at']));
                $saved += ['warning' => $warning, 'account_invalid' => $invalid, 'retry_at' => time() + 60];
            }
            Cache::set($this->key($account), $saved, self::TTL);
            return $this->read($account);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Changes when the user signs in again, not when an adapter rotates its
     * own tokens, so a fresh overview survives automatic token renewal.
     */
    private static function generation(array $account): string
    {
        $data = safe_unserialize_array((string)($account['data'] ?? ''));
        if (is_numeric($data['profile_updated_at'] ?? null)) {
            return 'p' . (int)$data['profile_updated_at'];
        }
        return 'a' . (string)($account['addtime'] ?? '');
    }

    /** Keep only short display rows and scalar statistics. */
    private static function clean(array $data): array
    {
        $rows = [];
        foreach (array_slice((array)($data['rows'] ?? []), 0, 12) as $row) {
            if (is_array($row) && is_scalar($row['label'] ?? null) && is_scalar($row['value'] ?? null)) {
                $rows[] = ['label' => mb_substr((string)$row['label'], 0, 40),
                    'value' => mb_substr((string)$row['value'], 0, 200)];
            }
        }
        $stats = [];
        foreach (array_slice((array)($data['stats'] ?? []), 0, 16, true) as $name => $value) {
            if (is_string($name) && preg_match('/\A[a-z_]{1,32}\z/', $name) === 1 && (is_int($value) || is_bool($value))) {
                $stats[$name] = $value;
            }
        }
        return ['rows' => $rows, 'stats' => $stats, 'signature' => mb_substr((string)($data['signature'] ?? ''), 0, 300)];
    }
}
