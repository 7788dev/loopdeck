<?php

declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;

final class UserNotificationSettings
{
    public const CHANNELS = ['bark', 'pushplus', 'wxpusher', 'email'];
    public const EVENTS = ['task_success', 'task_failure', 'daily_summary', 'account_invalid', 'vip_expired', 'epic_free_games'];
    private NotificationRepository $repository;
    private array $cache = [];

    public function __construct(?NotificationRepository $repository = null)
    {
        $this->repository = $repository ?? new NotificationRepository();
    }

    public static function defaults(): array
    {
        return [
            'enabled' => false, 'channels' => array_fill_keys(self::CHANNELS, false),
            'events' => ['task_success' => false, 'task_failure' => true, 'daily_summary' => true,
                'account_invalid' => true, 'vip_expired' => true, 'epic_free_games' => true],
            'summary_time' => '22:00', 'epic_time' => '09:00', 'bark_token' => '', 'pushplus_token' => '',
            'wxpusher_app_token' => '', 'wxpusher_uid' => '', 'email_address' => '',
        ];
    }

    public function get(int $uid, int $webId): array
    {
        self::assertIdentity($uid, $webId);
        $key = $webId . ':' . $uid;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $row = $this->repository->preferences($uid, $webId);
        $stored = $row ? json_decode((string)$row['settings'], true) : null;
        $settings = array_replace_recursive(self::defaults(), is_array($stored) ? $stored : []);
        if ($row === null) {
            $token = $this->repository->legacyBarkToken($uid, $webId);
            $bark = BarkClient::normalizeToken($token) && $token !== '';
            $legacyEpic = $this->repository->legacyEpicSubscription($uid, $webId);
            $email = trim((string)($legacyEpic['user_id'] ?? ''));
            $epicConfig = $legacyEpic ? @unserialize((string)$legacyEpic['data'], ['allowed_classes' => false]) : [];
            $epicTiming = is_array($epicConfig) ? AutomaticSchedule::normalize((string)($epicConfig['timing'] ?? '')) : null;
            $epic = $epicTiming !== null && strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
            if ($bark || $epic) {
                $settings['enabled'] = true;
                $settings['channels']['bark'] = (bool)$bark;
                $settings['bark_token'] = $bark ? $token : '';
                $settings['channels']['email'] = $epic;
                $settings['email_address'] = $epic ? $email : '';
                $settings['epic_time'] = $epic ? $epicTiming : '09:00';
                // Preserve existing subscriptions without opting into new event categories.
                $settings['events'] = array_fill_keys(self::EVENTS, false);
                $settings['events']['account_invalid'] = (bool)$bark;
                $settings['events']['vip_expired'] = (bool)$bark;
                $settings['events']['epic_free_games'] = $epic;
                $this->repository->migratePreferences($uid, $webId, $settings);
                $row = $this->repository->preferences($uid, $webId);
                $settings = array_replace_recursive(self::defaults(), json_decode($row['settings'], true, 512, JSON_THROW_ON_ERROR));
            }
        }
        return $this->cache[$key] = $settings;
    }

    public function publicSettings(int $uid, int $webId): array
    {
        return self::redact($this->get($uid, $webId));
    }

    public static function redact(array $settings): array
    {
        foreach (['bark_token', 'pushplus_token', 'wxpusher_app_token', 'wxpusher_uid'] as $key) {
            $settings[$key . '_configured'] = $settings[$key] !== '';
            unset($settings[$key]);
        }
        return $settings;
    }

    public function save(int $uid, int $webId, array $input, bool $emailAvailable): array
    {
        $current = $this->get($uid, $webId);
        $settings = self::sanitize($input, $current, $emailAvailable);
        $next = $settings['enabled'] && $settings['events']['daily_summary']
            ? self::nextSummaryAt($settings['summary_time'], time()) : 0;
        if ($next > 0 && $current['enabled'] && $current['events']['daily_summary']
            && $current['summary_time'] === $settings['summary_time']) {
            $row = $this->repository->preferences($uid, $webId);
            // Saving a token must not skip a report already waiting for the scheduler.
            $next = (int)($row['next_summary_at'] ?? 0) ?: $next;
        }
        $this->repository->savePreferences($uid, $webId, $settings, $next);
        $this->cache[$webId . ':' . $uid] = $settings;
        return $this->publicSettings($uid, $webId);
    }

    public static function sanitize(array $input, array $current, bool $emailAvailable): array
    {
        foreach ($input as $value) {
            if (!is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException('推送设置格式无效');
            }
        }
        $settings = array_replace_recursive(self::defaults(), $current);
        $settings['enabled'] = self::flag($input['enabled'] ?? 0);
        foreach (self::CHANNELS as $channel) {
            $settings['channels'][$channel] = self::flag($input[$channel . '_enabled'] ?? 0);
        }
        foreach (self::EVENTS as $event) {
            $settings['events'][$event] = self::flag($input[$event] ?? 0);
        }
        if (!$emailAvailable && $settings['channels']['email']) {
            throw new InvalidArgumentException('管理员尚未开启并配置可用的邮件推送');
        }
        $time = trim((string)($input['summary_time'] ?? '22:00'));
        if (AutomaticSchedule::normalize($time) === null) {
            throw new InvalidArgumentException('总览时间格式应为 HH:MM');
        }
        $settings['summary_time'] = $time;
        $epicTime = trim((string)($input['epic_time'] ?? $settings['epic_time']));
        if (AutomaticSchedule::normalize($epicTime) === null) {
            throw new InvalidArgumentException('Epic 提醒时间格式应为 HH:MM');
        }
        $settings['epic_time'] = $epicTime;
        $patterns = [
            'bark_token' => '/\A[A-Za-z0-9_-]{6,255}\z/',
            'pushplus_token' => '/\A[A-Za-z0-9_-]{8,255}\z/',
            'wxpusher_app_token' => '/\AAT_[A-Za-z0-9_-]{6,200}\z/',
            'wxpusher_uid' => '/\AUID_[A-Za-z0-9_-]{6,200}\z/',
        ];
        foreach ($patterns as $key => $pattern) {
            if (self::flag($input['clear_' . $key] ?? 0)) {
                $settings[$key] = '';
            }
            $value = $input[$key] ?? '';
            if (!is_string($value)) {
                throw new InvalidArgumentException('推送凭据格式无效');
            }
            $value = trim($value);
            if ($value !== '') {
                if (preg_match($pattern, $value) !== 1) {
                    throw new InvalidArgumentException('推送凭据格式无效，请填写 Key/Token/UID，不要填写网址');
                }
                $settings[$key] = $value;
            }
        }
        $email = trim((string)($input['email_address'] ?? $settings['email_address']));
        if ($email !== '' && (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            throw new InvalidArgumentException('通知邮箱格式无效');
        }
        $settings['email_address'] = $email;
        foreach (['bark' => ['bark_token'], 'pushplus' => ['pushplus_token'],
            'wxpusher' => ['wxpusher_app_token', 'wxpusher_uid'], 'email' => ['email_address']] as $channel => $keys) {
            if ($settings['channels'][$channel]) {
                foreach ($keys as $key) {
                    if ($settings[$key] === '') {
                        throw new InvalidArgumentException('请先填写已开启渠道的推送凭据或通知邮箱');
                    }
                }
            }
        }
        if ($settings['enabled'] && !in_array(true, $settings['channels'], true)) {
            throw new InvalidArgumentException('请至少开启一个推送渠道');
        }
        return $settings;
    }

    public static function nextSummaryAt(string $time, int $now): int
    {
        if (AutomaticSchedule::normalize($time) === null) {
            throw new InvalidArgumentException('总览时间格式无效');
        }
        $today = strtotime(date('Y-m-d', $now) . ' ' . $time);
        return $today > $now ? $today : (int)strtotime('+1 day', $today);
    }

    public function barkToken(int $uid, int $webId): string
    {
        return (string)$this->get($uid, $webId)['bark_token'];
    }

    public function saveBarkToken(int $uid, int $webId, string $token): bool
    {
        $token = BarkClient::normalizeToken($token);
        if ($token === null) {
            throw new InvalidArgumentException('Bark Token 格式无效');
        }
        $settings = $this->get($uid, $webId);
        $settings['bark_token'] = $token;
        $settings['channels']['bark'] = $token !== '';
        $settings['enabled'] = in_array(true, $settings['channels'], true);
        $this->repository->savePreferences($uid, $webId, $settings,
            $settings['enabled'] && $settings['events']['daily_summary'] ? self::nextSummaryAt($settings['summary_time'], time()) : 0);
        $this->cache[$webId . ':' . $uid] = $settings;
        return true;
    }

    public function ensureSchema(): void
    {
        $this->repository->ensureSchema();
    }

    private static function flag(mixed $value): bool
    {
        if (!in_array($value, [0, 1, '0', '1', false, true], true)) {
            throw new InvalidArgumentException('推送开关只能为开启或关闭');
        }
        return in_array($value, [1, '1', true], true);
    }

    private static function assertIdentity(int $uid, int $webId): void
    {
        if ($uid <= 0 || $webId <= 0) {
            throw new InvalidArgumentException('用户信息无效');
        }
    }
}
