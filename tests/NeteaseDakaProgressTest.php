<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use app\service\NeteaseDakaProgress;

$directory = sys_get_temp_dir() . '/loopdeck-daka-progress-' . bin2hex(random_bytes(6));
mkdir($directory . '/cron/netease-daka', 0770, true);
$path = $directory . '/cron/netease-daka/' . hash('sha256', '42') . '.daily.json';
$now = strtotime('2026-10-10 15:05:00');
$state = ['date' => '2026-10-10', 'target' => 300, 'actual_progress' => 144,
    'deadline_at' => $now + 1500, 'updated_at' => date('c', $now), 'completed' => false, 'sealed' => false];
$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
try {
    file_put_contents($path, json_encode($state));
    $pending = NeteaseDakaProgress::describe('42', 1, $directory, $now);
    $check(str_contains($pending, '144/300') && str_contains($pending, '15:30') && str_contains($pending, '正在'),
        'Active listening progress did not show confirmed count and deadline');
    $check(NeteaseDakaProgress::describe('42', 0, $directory, $now) === '', 'A disabled job was presented as running');
    $check(!str_contains(NeteaseDakaProgress::describe('43', 1, $directory, $now), '144/300'),
        'Progress from another account was exposed');
    file_put_contents($path, json_encode(array_replace($state, ['completed' => true, 'actual_progress' => 300])));
    $check(str_contains(NeteaseDakaProgress::describe('42', 1, $directory, $now), '今日已完成'), 'Completed progress was hidden');
    file_put_contents($path, json_encode(array_replace($state, ['sealed' => true])));
    $check(str_contains(NeteaseDakaProgress::describe('42', 1, $directory, $now), '本轮已结束'), 'Terminal failure still looked pending');
    file_put_contents($path, json_encode(array_replace($state, ['date' => '2026-10-09'])));
    $check(!str_contains(NeteaseDakaProgress::describe('42', 1, $directory, $now), '144/300'), 'Yesterday\'s count leaked into today');
    echo "Netease listening progress tests passed\n";
} finally {
    unlink($path);
    rmdir($directory . '/cron/netease-daka');
    rmdir($directory . '/cron');
    rmdir($directory);
}
