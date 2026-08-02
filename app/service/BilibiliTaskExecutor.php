<?php

declare(strict_types=1);

namespace app\service;

use bilibili\BiliHelper;
use Closure;
use Throwable;

final class BilibiliTaskExecutor
{
    public const TASKS = [
        'manga',
        'dailybag',
        'doubleheart',
        'groupsignIn',
        'giftheart',
        'silver2coin',
        'watchaid',
        'shareaid',
        'coinadd',
        'dailyexperience',
        'vipexperience',
    ];

    public const OFFLINE_TASKS = [
        'dailytask' => '直播签到功能已下线',
    ];

    private Closure $helperFactory;

    public function __construct(?Closure $helperFactory = null)
    {
        $this->helperFactory = $helperFactory ?? static function (array $account, array $config): BiliHelper {
            return new BiliHelper(
                $account['mid'],
                $account['mid_md5'],
                $account['token'],
                $account['csrf'],
                $account['access_key'],
                $config
            );
        };
    }

    public static function supports(string $task): bool
    {
        return in_array($task, self::TASKS, true);
    }

    public static function offlineReason(string $task): ?string
    {
        return self::OFFLINE_TASKS[$task] ?? null;
    }

    public static function decodeSerializedArray(?string $value): ?array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        try {
            $decoded = @unserialize($value, ['allowed_classes' => false]);
        } catch (Throwable $exception) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array{code:int,message:string,account_invalid:bool}
     */
    public function execute(string $task, array $account, array $config = []): array
    {
        $offlineReason = self::offlineReason($task);
        if ($offlineReason !== null) {
            return $this->failure($offlineReason);
        }
        if (!self::supports($task)) {
            return $this->failure('任务不在允许执行范围内');
        }

        $account = self::normalizeAccountData($account);
        if ($account === null) {
            return $this->failure('账号凭据不完整');
        }

        $config = $this->normalizeConfig($config);
        $config['sid'] = $account['sid'];

        try {
            $helper = ($this->helperFactory)($account, $config);
            if (!is_object($helper) || !method_exists($helper, $task)) {
                return $this->failure('任务执行器不可用');
            }
            $result = $helper->{$task}();
            if (!is_array($result)) {
                return $this->failure('任务返回数据格式错误');
            }

            return [
                'code' => (int)($result['code'] ?? 0),
                'message' => trim((string)($result['message'] ?? '')) ?: '任务执行完成',
                'account_invalid' => !empty($helper->cookiezt),
            ];
        } catch (Throwable $exception) {
            return $this->failure('任务执行异常：' . $exception->getMessage());
        }
    }

    public static function normalizeAccountData(array $account): ?array
    {
        $normalized = [];
        foreach (['mid', 'mid_md5', 'token', 'csrf', 'sid', 'access_key', 'refresh_token'] as $field) {
            $normalized[$field] = trim((string)($account[$field] ?? ''));
        }
        if ($normalized['mid'] === '' || !ctype_digit($normalized['mid'])) {
            return null;
        }
        foreach (['mid_md5', 'token', 'csrf'] as $required) {
            if ($normalized[$required] === '') {
                return null;
            }
        }
        return $normalized;
    }

    private function normalizeConfig(array $config): array
    {
        $normalized = [];
        $roomId = trim((string)($config['global_room'] ?? ''));
        if ($roomId !== '' && ctype_digit($roomId) && (int)$roomId > 0) {
            $normalized['global_room'] = $roomId;
        }

        $mode = (string)($config['add_coin_mode'] ?? '');
        if (in_array($mode, ['random', 'fixed'], true)) {
            $normalized['add_coin_mode'] = $mode;
        }
        $coinCount = (int)($config['add_coin_num'] ?? 0);
        if ($coinCount >= 1 && $coinCount <= 5) {
            $normalized['add_coin_num'] = $coinCount;
        }
        return $normalized;
    }

    /** @return array{code:int,message:string,account_invalid:bool} */
    private function failure(string $message): array
    {
        return ['code' => 0, 'message' => $message, 'account_invalid' => false];
    }
}
