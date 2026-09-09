<?php

declare(strict_types=1);

namespace app\index\model {
    final class TaskLogs
    {
        public static array $rows = [];

        public static function operateExecuteLog($type, $account, $task, $response): bool
        {
            self::$rows[] = compact('type', 'account', 'task', 'response');
            return true;
        }
    }
}

namespace netease {
    // Isolate the scheduler boundary from the network-facing adapter.
    final class Netease
    {
        public static array $response = [];
        public bool $cookiezt = false;

        public function __construct(...$arguments)
        {
        }

        public function daka_new(): array
        {
            return self::$response;
        }
    }
}

namespace {
    require dirname(__DIR__) . '/vendor/autoload.php';

    $scheduler = new app\cron\controller\Task();
    $execute = new ReflectionMethod($scheduler, 'executeNetease');
    $execute->setAccessible(true);
    $credentials = ['user_id' => '1', 'csrf' => 'test-csrf', 'musicu' => 'test-session'];
    $legacy = new app\cron\controller\Netease();
    $writers = [];
    foreach ([$scheduler, $legacy] as $controller) {
        $method = new ReflectionMethod($controller, 'writeLog');
        $method->setAccessible(true);
        $writers[] = [$controller, $method];
    }

    foreach ([300, 900, 0] as $delay) {
        netease\Netease::$response = [
            'code' => $delay === 0 ? 200 : 201,
            'message' => $delay === 0 ? '进度 300/300' : '进度 0/300 | 等待入账',
            'data' => ['retry_after_seconds' => $delay],
        ];
        $result = $execute->invoke($scheduler, 'daka_new', '1', $credentials, []);
        if ($result['retry_after_seconds'] !== $delay) {
            throw new RuntimeException('The scheduler dropped the daily settlement retry delay');
        }
        if ($scheduler->statusTag($result) !== ($delay === 0 ? '成功' : '重试中')) {
            throw new RuntimeException('The scheduler logged pending accounting as a final failure');
        }
        foreach ($writers as [$controller, $writer]) {
            app\index\model\TaskLogs::$rows = [];
            $writer->invoke($controller, 'netease', '1', 'daka_new', $result['message'], $scheduler->statusTag($result));
            if (count(app\index\model\TaskLogs::$rows) !== ($delay === 0 ? 1 : 0)) {
                throw new RuntimeException('Daily verification published an intermediate log or lost its final result');
            }
        }
    }

    foreach ($writers as [$controller, $writer]) {
        app\index\model\TaskLogs::$rows = [];
        $writer->invoke($controller, 'netease', '1', 'daka_new', '今日核验窗口已结束', '失败');
        $writer->invoke($controller, 'netease', '1', 'sign', '签到等待重试', '重试中');
        $writer->invoke($controller, 'bilibili', '1', 'daka_new', '其他平台任务等待重试', '重试中');
        $rows = app\index\model\TaskLogs::$rows;
        if (count($rows) !== 3 || !str_starts_with($rows[0]['response'], '[失败] ')
            || !str_starts_with($rows[1]['response'], '[重试中] ')
            || !str_starts_with($rows[2]['response'], '[重试中] ')) {
            throw new RuntimeException('Final-only listening logs changed other tasks or hid a terminal failure');
        }
    }

    echo "Netease scheduler tests passed: internal retries retained, only final listening results logged\n";
}
