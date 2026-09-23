<?php

declare(strict_types=1);

namespace app\service;

use Closure;
use InvalidArgumentException;

/**
 * Daily check-in platforms whose adapters live in `extend/<platform>/`.
 * `theme` picks a Codebase gradient (`bg-gd-*`), `accent` a Codebase colour
 * class and `color` the matching hex, so each console page keeps the site's
 * look while staying recognisable.
 */
final class PlatformRegistry
{
    public const DAILY_TASK = 'daily_sign';

    public static function platforms(): array
    {
        return [
            'tieba' => ['name' => '百度贴吧', 'short' => '贴吧', 'icon' => 'fa fa-paw', 'theme' => 'sea', 'color' => '#026da4',
                'accent' => 'primary', 'task' => '关注贴吧签到',
                'description' => '逐个签到全部关注的贴吧，失败项目自动延后重试', 'url' => 'https://tieba.baidu.com/',
                'class' => \tieba\Tieba::class,
                'fields' => [['name' => 'cookie', 'label' => 'BDUSS 或完整 Cookie', 'max' => 8192]]],
            'quark' => ['name' => '夸克网盘', 'short' => '夸克', 'icon' => 'fa fa-atom', 'theme' => 'elegance', 'color' => '#8f55f2',
                'accent' => 'elegance', 'task' => '每日签到领空间',
                'description' => '每日签到领取容量奖励，并核验连续签到进度', 'url' => 'https://pan.quark.cn/',
                'class' => \quark\Quark::class,
                'fields' => [['name' => 'cookie', 'label' => '网页 Cookie', 'max' => 8192],
                    ['name' => 'app_url', 'label' => '签到请求地址', 'max' => 8192]]],
            'tianyi' => ['name' => '天翼云盘', 'short' => '天翼', 'icon' => 'fa fa-cloud', 'theme' => 'aqua', 'color' => '#2facb2',
                'accent' => 'corporate', 'task' => '每日签到领空间',
                'description' => '每日签到领取空间，登录会话自动续期', 'url' => 'https://cloud.189.cn/',
                'class' => \tianyi\Tianyi::class,
                'fields' => [['name' => 'username', 'label' => '天翼账号', 'max' => 128],
                    ['name' => 'password', 'label' => '登录密码', 'max' => 512]]],
            'aliyundrive' => ['name' => '阿里云盘', 'short' => '阿里云盘', 'icon' => 'fa fa-hdd', 'theme' => 'sun', 'color' => '#d97706',
                'accent' => 'warning', 'task' => '每日签到',
                'description' => '每日签到并核验本月签到记录，令牌自动续期', 'url' => 'https://www.alipan.com/',
                'class' => \aliyundrive\AliyunDrive::class,
                'fields' => [['name' => 'refresh_token', 'label' => 'Refresh Token', 'max' => 8192]]],
        ];
    }

    public static function types(): array
    {
        return array_keys(self::platforms());
    }

    public static function supports(string $type): bool
    {
        return isset(self::platforms()[$type]);
    }

    public static function get(string $type): array
    {
        return (self::platforms()[$type] ?? throw new InvalidArgumentException('不支持的平台')) + ['type' => $type];
    }

    public static function adapter(string $type, ?CheckinTransport $transport = null, ?Closure $persist = null): CheckinAdapter
    {
        $class = self::get($type)['class'];
        return new $class($transport, $persist);
    }

    public static function tasks(string $type): array
    {
        if (!self::supports($type)) {
            return [];
        }
        $platform = self::get($type);
        return [[
            'type' => $type, 'name' => $platform['task'], 'describe' => $platform['description'],
            'icon' => $platform['icon'], 'execute_name' => self::DAILY_TASK, 'execute_url' => null,
            'execute_rate' => '86400', 'more' => 0, 'state' => 1, 'vip' => 0,
            'time' => '2026-09-23 00:00:00', 'order' => 1,
        ]];
    }
}
