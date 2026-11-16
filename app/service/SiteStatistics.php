<?php

declare(strict_types=1);

namespace app\service;

use app\index\model\Accounts;
use app\index\model\Info;
use app\index\model\Jobs;
use app\index\model\Users;
use think\facade\Cache;

/**
 * Display-only site counters shared by the user dashboard, the administrator
 * dashboard and the public home page. They are read on every page view but
 * only change through ordinary registrations and scheduler runs, so one
 * short-lived snapshot replaces four per-request aggregate queries.
 */
final class SiteStatistics
{
    private const CACHE_KEY = 'site_display_statistics';
    private const TTL_SECONDS = 60;

    /**
     * @return array{user_count:int,account_count:int,job_count:int,execute_count:int}
     */
    public static function counters(): array
    {
        return Cache::remember(self::CACHE_KEY, static function (): array {
            return [
                'user_count' => (int)Users::userCount(),
                'account_count' => (int)Accounts::accountCount(),
                'job_count' => (int)Jobs::jobCount(),
                'execute_count' => (int)Info::executeCount(),
            ];
        }, self::TTL_SECONDS);
    }
}