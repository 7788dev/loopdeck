<?php

declare(strict_types=1);

namespace app\service;

use app\cron\controller\Common;
use app\index\model\Users;
use Closure;
use Throwable;

class NotificationService
{
    private NotificationRepository $repository;
    private UserNotificationSettings $settings;
    private NotificationSite $sites;
    private NotificationTransport $transport;
    private Closure $userLoader;

    public function __construct(
        ?NotificationRepository $repository = null,
        ?UserNotificationSettings $settings = null,
        ?NotificationSite $sites = null,
        ?NotificationTransport $transport = null,
        ?Closure $userLoader = null
    ) {
        $this->repository = $repository ?? new NotificationRepository();
        $this->settings = $settings ?? new UserNotificationSettings($this->repository);
        $this->sites = $sites ?? new NotificationSite();
        $this->transport = $transport ?? new NotificationTransport();
        $this->userLoader = $userLoader ?? static function (int $uid, int $webId): array {
            $user = Users::where('uid', $uid)->where('web_id', $webId)->where('state', 1)
                ->field('uid,web_id,state,mail')->find();
            return $user ? $user->toArray() : [];
        };
    }

    public function notify(mixed $user, string $event, string $key, string $title, string $body, string $url = ''): array
    {
        $user = self::userArray($user);
        $uid = (int)($user['uid'] ?? 0);
        $webId = (int)($user['web_id'] ?? 0);
        if ($uid <= 0 || $webId <= 0 || !in_array($event, UserNotificationSettings::EVENTS, true)) {
            return ['success' => false, 'queued' => 0, 'message' => '用户或推送事件无效'];
        }
        $settings = $this->settings->get($uid, $webId);
        $site = $this->sites->get($webId);
        if (!$settings['enabled'] || empty($settings['events'][$event]) || !$site['exists']) {
            return ['success' => false, 'queued' => 0, 'message' => '此类推送未开启'];
        }
        $eligible = 0;
        $queued = 0;
        foreach (UserNotificationSettings::CHANNELS as $channel) {
            if (empty($settings['channels'][$channel]) || ($channel === 'email' && !$site['email_available'])) {
                continue;
            }
            $eligible++;
            $queued += (int)$this->repository->enqueue([
                'uid' => $uid, 'web_id' => $webId, 'event_key' => hash('sha256', $event . '|' . $key),
                'event_type' => $event, 'channel' => $channel,
                'title' => NotificationText::clean($site['name'] . ' - ' . $title, 200),
                'body' => NotificationText::clean($body),
                'url' => NotificationSite::safeUrl($url !== '' ? $url : $site['url']),
                'available_at' => time(), 'created_at' => time(),
            ]);
        }
        return ['success' => $eligible > 0, 'queued' => $queued,
            'message' => $eligible > 0 ? '推送已加入发送队列' : '尚未配置可用的推送渠道'];
    }

    public function recordTask(mixed $user, string $type, string $accountId, string $taskKey, string $taskName, array $result): void
    {
        $user = self::userArray($user);
        $uid = (int)($user['uid'] ?? 0);
        $webId = (int)($user['web_id'] ?? 0);
        if ($uid <= 0 || $webId <= 0) {
            return;
        }
        try {
            $status = (new Common())->statusTag($result);
            $message = NotificationText::clean((string)($result['message'] ?? '任务执行完成'), 4000);
            $date = date('Y-m-d');
            $this->repository->recordTask([
                'uid' => $uid, 'web_id' => $webId, 'run_date' => $date, 'type' => $type,
                'account_id' => $accountId, 'task_key' => $taskKey, 'task_name' => $taskName,
                'status' => $status, 'message' => $message, 'updated_at' => time(),
            ]);
            // Transient failures remain "retrying" in the overview. They are
            // not final-failure alerts and do not trigger repeated pushes.
            if ($status !== '重试中' && $type !== 'epic') {
                $event = $status === '成功' ? 'task_success' : 'task_failure';
                $this->notify($user, $event, implode('|', [$date, $type, $accountId, $taskKey]),
                    NotificationText::provider($type) . ' · ' . $taskName . ' · ' . $status,
                    '账号：' . $accountId . "\n" . $message);
            }
        } catch (Throwable $exception) {
            // Notification bookkeeping must never repeat a completed task.
            error_log('LoopDeck: task notification could not be queued');
        }
    }

    public function sendAccountInvalid(mixed $user, string $provider, string $accountId = ''): array
    {
        return $this->safeNotify($user, 'account_invalid', date('Y-m-d') . '|' . $provider . '|' . $accountId,
            '账号失效提醒', '您的 ' . $provider . ' 账号' . ($accountId !== '' ? ' ' . $accountId : '')
                . '已失效，请在控制台更新登录凭据。');
    }

    public function sendVipExpired(mixed $user): array
    {
        return $this->safeNotify($user, 'vip_expired', date('Y-m-d'),
            '会员到期提醒', '您的 LoopDeck 会员已到期，需要会员权限的任务已暂停。');
    }

    private function safeNotify(mixed $user, string $event, string $key, string $title, string $body): array
    {
        try {
            return $this->notify($user, $event, $key, $title, $body);
        } catch (Throwable $exception) {
            return ['success' => false, 'queued' => 0, 'message' => '推送暂时不可用'];
        }
    }

    public function sendTest(mixed $user, string $channel = 'bark'): array
    {
        $user = self::userArray($user);
        if (!in_array($channel, UserNotificationSettings::CHANNELS, true)) {
            return ['success' => false, 'message' => '不支持的推送渠道'];
        }
        $settings = $this->settings->get((int)$user['uid'], (int)$user['web_id']);
        if (!$settings['enabled'] || empty($settings['channels'][$channel])) {
            return ['success' => false, 'message' => '请先保存并开启此推送渠道'];
        }
        $site = $this->sites->get((int)$user['web_id']);
        $result = $this->transport->send($channel, $settings, $site,
            $site['name'] . ' - 测试推送', '推送渠道配置成功，后续按个人中心选择的事件发送通知。', $site['url']);
        return ['success' => !empty($result['success']), 'message' => NotificationText::clean((string)$result['message'], 200)];
    }

    /** Run only from the dedicated notification scheduler, outside task jobs. */
    public function tick(int $limit = 20, float $budgetSeconds = 20.0, ?int $now = null): array
    {
        $now ??= time();
        $deadline = microtime(true) + $budgetSeconds;
        $counts = ['summaries' => 0, 'sent' => 0, 'retrying' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($this->repository->dueSummaries($now, $limit) as $row) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $uid = (int)$row['uid'];
            $webId = (int)$row['web_id'];
            $settings = $this->settings->get($uid, $webId);
            $user = ($this->userLoader)($uid, $webId);
            if ($user !== [] && $settings['enabled'] && $settings['events']['daily_summary']) {
                $date = date('Y-m-d', (int)$row['next_summary_at']);
                $this->notify($user, 'daily_summary', $date, $date . ' 每日任务总览',
                    NotificationText::dailySummary($date, $this->repository->dailyTasks($uid, $webId, $date)));
                $counts['summaries']++;
            }
            // If enqueue failed, the exception leaves the due row retryable.
            // Event/channel unique keys protect partial enqueue after a crash.
            $this->repository->advanceSummary($uid, $webId, (int)$row['next_summary_at'],
                $user !== [] ? UserNotificationSettings::nextSummaryAt($settings['summary_time'], $now) : 0);
        }
        foreach ($this->repository->dueMessages($now, $limit) as $row) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $id = (int)$row['id'];
            if (!$this->repository->claimMessage($id, (int)$row['available_at'], $now)) {
                continue;
            }
            try {
                $user = ($this->userLoader)((int)$row['uid'], (int)$row['web_id']);
                $settings = $this->settings->get((int)$row['uid'], (int)$row['web_id']);
                $site = $this->sites->get((int)$row['web_id']);
                if ($user === [] || !$settings['enabled'] || empty($settings['channels'][$row['channel']])
                    || empty($settings['events'][$row['event_type']]) || !$site['exists']
                    || ($row['channel'] === 'email' && !$site['email_available'])) {
                    $this->repository->finishMessage($id, ['status' => 3, 'finished_at' => $now, 'last_error' => '用户或渠道已关闭']);
                    $counts['skipped']++;
                    continue;
                }
                $result = $this->transport->send($row['channel'], $settings, $site, $row['title'], $row['body'], $row['url']);
            } catch (Throwable $exception) {
                $result = ['success' => false, 'message' => '推送服务暂时不可用'];
            }
            $attempts = (int)$row['attempts'] + 1;
            $success = !empty($result['success']);
            $retry = !$success && $attempts < 3;
            $this->repository->finishMessage($id, [
                'status' => $success ? 1 : ($retry ? 0 : 2),
                'available_at' => $retry ? $now + 300 * $attempts : 0,
                'finished_at' => $retry ? 0 : $now,
                'last_error' => $success ? '' : NotificationText::clean((string)$result['message'], 200),
            ]);
            $counts[$success ? 'sent' : ($retry ? 'retrying' : 'failed')]++;
        }
        $this->repository->prune($now);
        return $counts;
    }

    private static function userArray(mixed $user): array
    {
        return is_array($user) ? $user : (is_object($user) && method_exists($user, 'toArray') ? $user->toArray() : []);
    }
}
