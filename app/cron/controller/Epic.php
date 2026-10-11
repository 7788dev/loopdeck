<?php

declare(strict_types=1);

namespace app\cron\controller;

use app\index\model\Jobs;
use app\service\EpicJobRunner;

/** Compatibility entry point; the container normally uses the unified task scheduler. */
class Epic extends Common
{
    public function index()
    {
        $jobs = Jobs::where('type', 'epic')->where('zid', 1)->where('do', 'weeklyGameNotify')->where('state', 1)
            ->where('nextExecute', '>', 0)->where('nextExecute', '<=', time())
            ->order('nextExecute')->limit(50)->select();
        $runner = new EpicJobRunner();
        foreach ($jobs as $job) {
            try {
                $runner->run($job);
            } catch (\Throwable $exception) {
                // 一条任务的领取或记账异常不能中断本轮其余任务；租约若已
                // 领取会在 EpicJobRunner 的 finally 中释放。
            }
        }
        return resultJson(1000, 'Epic 提醒任务处理完成');
    }

    public function notify()
    {
        return resultJson(-1001, 'RunKey Access Denied!');
    }
}
