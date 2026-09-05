<?php

declare(strict_types=1);

namespace app\middleware;

use think\facade\Config;
use think\Response;

final class CheckCronAccess
{
    public function handle($request, \Closure $next)
    {
        $key = $request->header('x-cron-key', '');
        if ($key === '') {
            $key = $request->get('cronkey', '');
        }
        $expected = getenv('CRON_KEY');
        if (!is_string($expected) || $expected === '') {
            // A tenant's own configuration must not authorize global jobs.
            $expected = defined('WEB_ID') && (int)WEB_ID !== 1 ? '' : (string)Config::get('sys.cronkey', '');
        }
        if (!is_string($key) || $key === '' || $expected === '' || !hash_equals($expected, $key)) {
            return Response::create(['code' => -1000, 'message' => 'CronKey Access Denied!'], 'json', 403);
        }
        return $next($request);
    }
}
