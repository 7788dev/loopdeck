<?php

declare(strict_types=1);

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
    }

    echo "Netease scheduler settlement retry tests passed\n";
}
