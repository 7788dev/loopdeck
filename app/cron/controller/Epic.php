<?php

declare(strict_types=1);

namespace app\cron\controller;

use app\index\model\Jobs;
use app\service\EpicJobRunner;
use think\facade\Request;

/** Compatibility entry point; the container normally uses the unified task scheduler. */
class Epic extends Common
{
    public function index()
    {
        $key = (string)Request::header('x-cron-key', Request::get('cronkey', ''));
        $expected = (string)(getenv('CRON_KEY') ?: config('sys.cronkey'));
        if ($key === '' || $expected === '' || !hash_equals($expected, $key)) {
            return resultJson(-1000, 'CronKey Access Denied!');
        }
        $jobs = Jobs::where('type', 'epic')->where('do', 'weeklyGameNotify')->where('state', 1)
            ->where('nextExecute', '>', 0)->where('nextExecute', '<=', time())
            ->order('nextExecute')->limit(50)->select();
        $runner = new EpicJobRunner();
        foreach ($jobs as $job) {
            $runner->run($job);
        }
        return resultJson(1000, 'Epic 提醒任务处理完成');
    }

    public function notify()
    {
        return resultJson(-1001, 'RunKey Access Denied!');
    }
}
