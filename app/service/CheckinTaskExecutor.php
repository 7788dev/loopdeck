<?php

declare(strict_types=1);

namespace app\service;

use think\facade\Cache;

final class CheckinTaskExecutor
{
    private const SUMMARY_TTL = 35 * 86400;

    public function __construct(private ?CheckinAccounts $accounts = null)
    {
        $this->accounts ??= new CheckinAccounts();
    }

    public static function progressKey(array $account, ?string $date = null): string
    {
        return 'checkin_progress_v1_' . hash('sha256', implode('|', [
            (int)$account['uid'], $account['type'], $account['user_id'], $date ?? date('Y-m-d'),
        ]));
    }

    public static function summaryKey(array $account): string
    {
        return 'checkin_summary_v1_' . hash('sha256', implode('|', [
            (int)$account['uid'], $account['type'], $account['user_id'],
        ]));
    }

    /** Today's resumable state; it never contains credentials. */
    public static function progress(array $account): array
    {
        $state = Cache::get(self::progressKey($account));
        return is_array($state) ? $state : [];
    }

    /** Structured result of the latest run, shown on the account page. */
    public static function summary(array $account): array
    {
        $summary = Cache::get(self::summaryKey($account));
        return is_array($summary) ? $summary : [];
    }

    public static function forget(array $account): void
    {
        Cache::delete(self::progressKey($account));
        Cache::delete(self::summaryKey($account));
    }

    public function execute(string $task, array $account): array
    {
        if ($task !== PlatformRegistry::DAILY_TASK || !PlatformRegistry::supports((string)$account['type'])) {
            throw new \InvalidArgumentException('不支持的任务');
        }
        try {
            $result = $this->accounts->withAccount($account, static function (CheckinAdapter $adapter, array $payload) use ($account): array {
                $key = self::progressKey($account);
                $state = Cache::get($key);
                $checkpoint = static function (array $state) use ($key): void {
                    if (!Cache::set($key, $state, 2 * 86400)) {
                        throw new PlatformException('无法保存任务进度，暂停提交并稍后重试');
                    }
                };
                $result = $adapter->execute($payload, is_array($state) ? $state : [], $checkpoint);
                if (is_array($result['state'] ?? null) && $result['state'] !== []) {
                    // Best effort: the upstream action has already happened.
                    Cache::set($key, $result['state'], 2 * 86400);
                }
                return $result;
            });
        } catch (PlatformVerification $exception) {
            return ['success' => false, 'message' => '登录=需要验证码 | 请到添加账号页面更新',
                'account_invalid' => true, 'retry_after_seconds' => 0, 'in_progress' => false];
        } catch (PlatformException $exception) {
            return ['success' => false, 'message' => '结果=' . $exception->getMessage(),
                'account_invalid' => $exception->accountInvalid, 'retry_after_seconds' => $exception->retryAfter,
                'in_progress' => false];
        }
        $normalized = [
            'success' => !empty($result['success']),
            'message' => trim((string)($result['message'] ?? '')) ?: '签到任务执行完成',
            'account_invalid' => !empty($result['account_invalid']),
            'retry_after_seconds' => max(0, (int)($result['retry_after_seconds'] ?? 0)),
            // A resumable step that is merely continuing: nothing to report yet.
            'in_progress' => !empty($result['in_progress']),
        ];
        if (is_array($result['summary'] ?? null) && $result['summary'] !== []) {
            Cache::set(self::summaryKey($account), [
                'updated_at' => time(), 'date' => date('Y-m-d'), 'success' => $normalized['success'],
                'in_progress' => $normalized['in_progress'], 'message' => mb_substr($normalized['message'], 0, 300),
            ] + $result['summary'], self::SUMMARY_TTL);
        }
        return $normalized;
    }
}
