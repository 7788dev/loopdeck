<?php

declare(strict_types=1);

namespace app\service;

use app\index\model\Accounts;
use app\index\model\Info;
use app\index\model\Jobs;
use app\index\model\Users;
use think\facade\Cache;

final class ConsoleStatistics
{
    /** Only public site totals are cached; account quotas always read current data. */
    public static function totals(): array
    {
        $key = 'console_totals_primary_v1';
        $totals = Cache::get($key);
        if (is_array($totals)) {
            return $totals;
        }
        $totals = [
            'user_count' => Users::userCount(),
            'account_count' => Accounts::accountCount(),
            'job_count' => Jobs::jobCount(),
            'execute_count' => Info::executeCount(),
        ];
        Cache::set($key, $totals, 30);
        return $totals;
    }
}
