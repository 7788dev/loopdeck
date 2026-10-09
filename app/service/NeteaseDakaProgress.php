<?php

declare(strict_types=1);

namespace app\service;

final class NeteaseDakaProgress
{
    /** Read progress without making an upstream request or changing task state. */
    public static function describe(string $userId, int $jobState, ?string $runtimeRoot = null, ?int $now = null): string
    {
        if ($userId === '' || $jobState !== 1) {
            return '';
        }
        $now ??= time();
        $runtimeRoot = rtrim($runtimeRoot ?? root_path() . 'runtime', '/\\');
        $file = hash('sha256', $userId) . '.daily.json';
        $latest = [];
        $updated = 0;
        // HTTP cron, historical CLI and index tasks have used these locations.
        foreach (['cron/netease-daka', 'netease-daka', 'index/netease-daka'] as $directory) {
            $path = $runtimeRoot . '/' . $directory . '/' . $file;
            if (!is_file($path)) {
                continue;
            }
            $state = json_decode((string)@file_get_contents($path), true);
            if (!is_array($state) || ($state['date'] ?? '') !== date('Y-m-d', $now)) {
                continue;
            }
            $timestamp = (int)(strtotime((string)($state['updated_at'] ?? '')) ?: @filemtime($path));
            if ($timestamp >= $updated) {
                $updated = $timestamp;
                $latest = $state;
            }
        }
        if ($latest === []) {
            return '等待执行；开始后自动补齐与核验，最长30分钟';
        }
        $target = max(1, min(300, (int)($latest['target'] ?? 300)));
        $progress = min($target, max(0, (int)($latest['actual_progress'] ?? 0)));
        $message = '已确认 ' . $progress . '/' . $target . ' 首';
        if (!empty($latest['completed'])) {
            return $message . '，今日已完成';
        }
        if (!empty($latest['sealed'])) {
            return $message . '，本轮已结束，请查看最终日志';
        }
        $deadline = (int)($latest['deadline_at'] ?? 0);
        if ($deadline > 0 && $deadline <= $now) {
            return $message . '，核验已到期，等待最终结果';
        }
        return $message . '，正在自动补齐与核验'
            . ($deadline > 0 ? '（本轮截止 ' . date('H:i', $deadline) . '）' : '');
    }
}
