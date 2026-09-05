<?php

declare(strict_types=1);

namespace app\service;

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
        foreach ($rows as $row) {
            $status = (string)($row['status'] ?? '失败');
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }
        $lines = [sprintf('%s 任务总览：共 %d 项，成功 %d，失败 %d，重试中 %d。',
            $date, count($rows), $counts['成功'], $counts['失败'], $counts['重试中'])];
        if ($rows === []) {
            $lines[] = '今日暂无已执行任务。';
        }
        $group = '';
        foreach (array_slice($rows, 0, 60) as $row) {
            $identity = self::provider((string)$row['type']) . ' · ' . self::clean((string)$row['account_id'], 60);
            if ($group !== $identity) {
                $group = $identity;
                $lines[] = "\n" . $group;
            }
            $lines[] = '[' . (string)$row['status'] . '] ' . self::clean((string)$row['task_name'], 80)
                . '：' . self::clean((string)$row['message'], 300);
        }
        if (count($rows) > 60) {
            $lines[] = '其余 ' . (count($rows) - 60) . ' 项请在控制台查看。';
        }
        $text = implode("\n", $lines);
        if (mb_strlen($text, 'UTF-8') > 5800) {
            $text = mb_substr($text, 0, 5750, 'UTF-8') . "\n内容较多，完整结果请在控制台查看。";
        }
        return $text;
    }
}
