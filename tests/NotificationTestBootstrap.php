<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use app\service\NotificationRepository;
use app\service\NotificationSite;
use app\service\NotificationTransport;

final class MemoryNotifications extends NotificationRepository
{
    public array $preferences = [];
    public array $legacy = [];
    public array $legacyEpic = [];
    public array $tasks = [];
    public array $messages = [];
    public bool $failRecord = false;
    public array $failSummaryUsers = [];

    public function ensureSchema(): void {}
    public function preferences(int $uid, int $webId): ?array { return $this->preferences[$webId . ':' . $uid] ?? null; }
    public function legacyBarkToken(int $uid, int $webId): string { return $this->legacy[$webId . ':' . $uid] ?? ''; }
    public function legacyEpicSubscription(int $uid, int $webId): ?array { return $this->legacyEpic[$webId . ':' . $uid] ?? null; }
    public function migratePreferences(int $uid, int $webId, array $settings): void
    {
        if (!isset($this->preferences[$webId . ':' . $uid])) {
            $this->savePreferences($uid, $webId, $settings, 0);
        }
    }
    public function savePreferences(int $uid, int $webId, array $settings, int $nextSummaryAt): void
    {
        $this->preferences[$webId . ':' . $uid] = ['uid' => $uid, 'web_id' => $webId, 'settings' => json_encode($settings),
            'enabled' => (int)$settings['enabled'], 'summary_enabled' => (int)$settings['events']['daily_summary'],
            'next_summary_at' => $nextSummaryAt];
    }
    public function recordTask(array $row): void
    {
        if ($this->failRecord) { throw new RuntimeException('Simulated storage failure'); }
        $key = implode('|', array_intersect_key($row, array_flip(['uid', 'web_id', 'run_date', 'type', 'account_id', 'task_key'])));
        $this->tasks[$key] = $row;
    }
    public function dailyTasks(int $uid, int $webId, string $date): array
    {
        if (in_array($uid, $this->failSummaryUsers, true)) {
            throw new RuntimeException('Simulated summary read failure');
        }
        return array_values(array_filter($this->tasks, static fn($r) => $r['uid'] === $uid && $r['web_id'] === $webId && $r['run_date'] === $date));
    }
    public function enqueue(array $row): bool
    {
        foreach ($this->messages as $existing) {
            if ($existing['uid'] === $row['uid'] && $existing['web_id'] === $row['web_id']
                && $existing['event_key'] === $row['event_key'] && $existing['channel'] === $row['channel']) { return false; }
        }
        $id = count($this->messages) + 1;
        $this->messages[$id] = $row + ['id' => $id, 'status' => 0, 'attempts' => 0, 'finished_at' => 0];
        return true;
    }
    public function dueMessages(int $now, int $limit): array
    {
        return array_slice(array_values(array_filter($this->messages, static fn($r) => $r['status'] === 0 && $r['available_at'] <= $now)), 0, $limit);
    }
    public function claimMessage(int $id, int $expectedAt, int $now): bool
    {
        if ($this->messages[$id]['status'] !== 0 || $this->messages[$id]['available_at'] !== $expectedAt || $expectedAt > $now) { return false; }
        $this->messages[$id]['available_at'] = $now + 120;
        $this->messages[$id]['attempts']++;
        return true;
    }
    public function finishMessage(int $id, array $changes): void { $this->messages[$id] = array_replace($this->messages[$id], $changes); }
    public function dueSummaries(int $now, int $limit): array
    {
        return array_slice(array_values(array_filter($this->preferences,
            static fn($r) => $r['enabled'] && $r['summary_enabled'] && $r['next_summary_at'] > 0 && $r['next_summary_at'] <= $now)), 0, $limit);
    }
    public function advanceSummary(int $uid, int $webId, int $expectedAt, int $nextAt): void
    {
        $key = $webId . ':' . $uid;
        if ($this->preferences[$key]['next_summary_at'] === $expectedAt) { $this->preferences[$key]['next_summary_at'] = $nextAt; }
    }
    public function prune(int $now): void {}
}

final class FixtureNotificationSite extends NotificationSite
{
    public array $value = ['exists' => true, 'name' => 'LoopDeck', 'url' => 'https://panel.example', 'email_available' => false, 'smtp' => []];
    public function get(int $webId): array { return $this->value; }
}

final class FixtureNotificationTransport extends NotificationTransport
{
    public array $calls = [];
    public array $fail = [];
    public function send(string $channel, array $settings, array $site, string $title, string $body, string $url = ''): array
    {
        $this->calls[] = compact('channel', 'title', 'body');
        return ['success' => empty($this->fail[$channel]), 'message' => empty($this->fail[$channel]) ? 'accepted' : 'temporarily unavailable'];
    }
}

function notificationCheck(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
