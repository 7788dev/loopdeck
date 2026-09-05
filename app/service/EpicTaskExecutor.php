<?php

declare(strict_types=1);

namespace app\service;

use Closure;

final class EpicTaskExecutor
{
    private NotificationService $notifications;
    private Closure $catalog;

    public function __construct(?NotificationService $notifications = null, ?Closure $catalog = null)
    {
        $this->notifications = $notifications ?? new NotificationService();
        $this->catalog = $catalog ?? static fn(): array => (new \epic\Epic())->getWeeklyFreeGames();
    }

    public function execute(mixed $user): array
    {
        $games = ($this->catalog)();
        if ($games === []) {
            return ['success' => false, 'message' => 'Epic 周免目录暂不可用，稍后重试', 'account_invalid' => false, 'retry_after_seconds' => 900];
        }
        $lines = ['Epic 免费游戏提醒，请在活动期间打开商店自行领取：'];
        $identities = [];
        foreach ($games as $game) {
            $available = !empty($game['available']);
            $lines[] = ($available ? '【限时免费】' : '【即将免费】') . NotificationText::clean((string)$game['title'], 120);
            $lines[] = '开始：' . date('m-d H:i', (int)$game['start_at']) . '，截止：' . date('m-d H:i', (int)$game['end_at']);
            $lines[] = (string)$game['productUrl'];
            $identities[] = (string)$game['productUrl'] . '|' . (int)$game['start_at'];
        }
        sort($identities);
        $queued = $this->notifications->notify($user, 'epic_free_games', date('o-W') . '|' . implode(';', $identities),
            'Epic 本周免费游戏', implode("\n", $lines), 'https://store.epicgames.com/zh-CN/free-games');
        return ['success' => !empty($queued['success']), 'message' => !empty($queued['success'])
            ? 'Epic 周免提醒已加入推送队列 | 游戏 ' . count($games) . ' 款 | 请自行前往商店领取'
            : 'Epic 周免提醒未开启或没有可用渠道，请在个人中心配置',
            'account_invalid' => false, 'retry_after_seconds' => 0, 'disable_job' => empty($queued['success'])];
    }
}
