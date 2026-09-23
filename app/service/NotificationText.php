<?php

declare(strict_types=1);

namespace app\service;

use app\cron\controller\Common;

final class NotificationText
{
    private const PROVIDERS = ['netease' => '网易云音乐', 'bilibili' => '哔哩哔哩', 'heybox' => '小黑盒', 'epic' => 'Epic'];

    public static function clean(string $text, int $limit = 6000): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\b(MUSIC_U|MUSIC_A|csrf|cookie|token|pkey|password|secret|access_key|appToken)\s*[:=]\s*[^\s|&]+/iu', '$1=[已隐藏]', $text) ?? '';
        return mb_substr(trim($text), 0, $limit, 'UTF-8');
    }

    public static function provider(string $type): string
    {
        return self::PROVIDERS[$type] ?? self::clean($type, 30);
    }

    public static function dailySummary(string $date, array $rows): string
    {
        $counts = ['成功' => 0, '失败' => 0, '重试中' => 0];
        $visibleCount = 0;
        $visibleRows = [];
        foreach ($rows as $row) {
            $status = (string)($row['status'] ?? '失败');
            // Keep old in-progress records in storage, but omit them from reports.
            if (!Common::shouldReportTaskStatus(
                (string)($row['type'] ?? ''), (string)($row['task_key'] ?? ''), $status
            )) {
                continue;
            }
            $visibleCount++;
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            if (count($visibleRows) < 60) {
                $visibleRows[] = $row;
            }
        }
        $lines = [sprintf('%s 任务总览：共 %d 项，成功 %d，失败 %d，重试中 %d。',
            $date, $visibleCount, $counts['成功'], $counts['失败'], $counts['重试中'])];
        if ($visibleCount === 0) {
            $lines[] = '今日暂无已执行任务。';
        }
        $group = '';
        foreach ($visibleRows as $row) {
            $identity = self::provider((string)$row['type']) . ' · ' . self::clean((string)$row['account_id'], 60);
            if ($group !== $identity) {
                $group = $identity;
                $lines[] = "\n" . $group;
            }
            $lines[] = '[' . (string)$row['status'] . '] ' . self::clean((string)$row['task_name'], 80)
                . '：' . self::clean((string)$row['message'], 300);
        }
        if ($visibleCount > 60) {
            $lines[] = '其余 ' . ($visibleCount - 60) . ' 项请在控制台查看。';
        }
        $text = implode("\n", $lines);
        if (mb_strlen($text, 'UTF-8') > 5800) {
            $text = mb_substr($text, 0, 5750, 'UTF-8') . "\n内容较多，完整结果请在控制台查看。";
        }
        return $text;
    }
}
