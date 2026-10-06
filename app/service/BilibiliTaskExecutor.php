<?php

declare(strict_types=1);

namespace app\service;

use bilibili\BiliHelper;
use Closure;
use Throwable;

final class BilibiliTaskExecutor
{
    private const MAX_VERIFICATION_ATTEMPTS = 24;
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
        'dailybag' => '旧直播每日礼包已停用',
        'doubleheart' => '旧直播双端心跳已停用',
        'groupsignIn' => '旧友爱社签到已停用',
        'giftheart' => '旧心跳礼物已停用，小心心已改为粉丝团任务',
        'globalroom' => '旧直播任务已停用，无需配置直播间',
        'dailytask' => '直播签到功能已下线',
        'shareaid' => '每日分享功能已下架',
    ];

    private Closure $helperFactory;
    private Closure $clock;

    public function __construct(?Closure $helperFactory = null, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn(): int => time();
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
        return in_array($task, self::TASKS, true)
            && self::offlineReason($task) === null;
    }

    /**
     * Return task names which are still allowed to reach an adapter.
     *
     * TASKS intentionally keeps retired names so existing database rows and
     * the console can show an explicit "已下架" state. All execution queries
     * must use this filtered list instead of the historical allow-list.
     *
     * @return list<string>
     */
    public static function executableTasks(): array
    {
        return array_values(array_filter(
            self::TASKS,
            static fn(string $task): bool => self::offlineReason($task) === null
        ));
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
     * @return array<string,mixed>
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

        $now = ($this->clock)();
        $startedDate = date('Y-m-d', $now);
        $verification = $config['_bilibili_verification'] ?? [];
        $isToday = is_array($verification) && ($verification['date'] ?? '') === date('Y-m-d', $now);
        $attempt = $isToday
            ? max(0, min(self::MAX_VERIFICATION_ATTEMPTS, (int)($verification['attempt'] ?? 0))) : 0;
        $canVerify = in_array($task, ['watchaid', 'dailyexperience', 'vipexperience'], true);
        // Yesterday's submitted claim says nothing about today's benefit, and
        // inheriting it would block today's first claim the same way.
        $claimSubmitted = $isToday && !empty($verification['claim_submitted']);
        $config = $this->normalizeConfig($config);
        if ($canVerify && $attempt > 0) {
            $config['verification_only'] = true;
        }
        if ($canVerify) {
            $config['claim_submitted'] = $claimSubmitted;
        }
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

            $response = [
                'code' => (int)($result['code'] ?? 0),
                'message' => trim((string)($result['message'] ?? '')) ?: '任务执行完成',
                'account_invalid' => !empty($helper->cookiezt),
            ];
            if ($canVerify && !empty($result['pending_verification']) && !$response['account_invalid']) {
                $response['code'] = 0;
                $now = ($this->clock)();
                if ($attempt < self::MAX_VERIFICATION_ATTEMPTS && date('Y-m-d', $now + 300) === $startedDate) {
                    $response['retry_after_seconds'] = 300;
                    $response['verification_state'] = [
                        'date' => date('Y-m-d', $now),
                        'attempt' => $attempt + 1,
                        'claim_submitted' => $claimSubmitted || !empty($result['claim_submitted']),
                    ];
                    $response['message'] = TaskMessage::join([$response['message'], '5 分钟后复查到账状态']);
                } else {
                    $response['message'] = TaskMessage::join([$response['message'], '本轮核验结束，未确认到账']);
                }
            }
            return $response;
        } catch (Throwable $exception) {
            return $this->failure('任务执行异常：' . $exception->getMessage());
        }
    }

    /** Persist verification progress in the existing job payload, never in account credentials. */
    public static function jobUpdates(array $result, array $jobConfig, string $userId, string $timing, ?int $now = null): array
    {
        $now ??= time();
        unset($jobConfig['_bilibili_verification']);
        $retry = (int)($result['retry_after_seconds'] ?? 0) === 300
            && is_array($result['verification_state'] ?? null);
        if ($retry) {
            $jobConfig['_bilibili_verification'] = $result['verification_state'];
        }
        return [
            'data' => serialize($jobConfig),
            'lastExecute' => date('Y-m-d H:i:s', $now),
            'nextExecute' => $retry ? $now + 300
                : (AutomaticSchedule::nextExecution('bilibili', $userId, $timing, $now) ?? 0),
        ];
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
