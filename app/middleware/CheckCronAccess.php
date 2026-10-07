<?php

declare(strict_types=1);

namespace app\middleware;

use think\facade\Config;
use think\Response;

final class CheckCronAccess
{
    public function handle($request, \Closure $next)
    {
        $headerKey = $request->header('x-cron-key', '');
        $key = is_string($headerKey) ? $headerKey : '';
        if ($key === '') {
            $queryKey = $request->get('cronkey', '');
            $key = is_string($queryKey) ? $queryKey : '';
        }
        if ($key === '') {
            return Response::create(['code' => -1000, 'message' => 'CronKey Access Denied!'], 'json', 403);
        }
        // The env credential and the primary installation's stored key are
        // both valid. Accepting either lets operators rotate through one
        // channel without locking out the other; every cron endpoint behind
        // app/cron/middleware.php authenticates here and nowhere else.
        $candidates = array_values(array_filter([
            (string)(getenv('CRON_KEY') ?: ''),
            defined('WEB_ID') && (int)WEB_ID !== 1 ? '' : (string)Config::get('sys.cronkey', ''),
        ]));
        foreach ($candidates as $expected) {
            if ($expected !== '' && hash_equals($expected, $key)) {
                return $next($request);
            }
        }
        return Response::create(['code' => -1000, 'message' => 'CronKey Access Denied!'], 'json', 403);
    }
}
