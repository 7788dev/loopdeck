<?php

declare(strict_types=1);

namespace app\cron\controller;

use app\service\NotificationService;
use think\facade\Request;
use Throwable;

final class Notifications extends Common
{
    public function index()
    {
        $key = trim((string)Request::header('x-cron-key', ''));
        $expected = (string)(getenv('CRON_KEY') ?: config('sys.cronkey'));
        if ($key === '' || $expected === '' || !hash_equals($expected, $key)) {
            return \think\Response::create(['code' => -1000, 'message' => 'CronKey Access Denied!'], 'json', 403);
        }
        try {
            return resultJson(1000, '推送队列处理完成', (new NotificationService())->tick());
        } catch (Throwable $exception) {
            return \think\Response::create(['code' => -1003, 'message' => '推送队列暂不可用，将在下一轮重试'], 'json', 503);
        }
    }
}
