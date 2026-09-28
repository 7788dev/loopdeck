<?php

declare(strict_types=1);

require __DIR__ . '/FunctionalDatabaseBootstrap.php';
$source = dirname(__DIR__) . '/docker/auto-updater.php';
require is_file($source) ? $source : '/usr/local/lib/loopdeck/auto-updater.php';

use app\service\SystemUpdater;

$directory = sys_get_temp_dir() . '/loopdeck-progress-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
$stateFile = $directory . '/state.json';
$observed = [];
$updater = new LoopDeckAutoUpdater(static function (array $command, int $timeout) use (&$observed, $stateFile): array {
    $observed[] = json_decode(file_get_contents($stateFile), true)['phase'];
    return ['ok' => true, 'code' => 0, 'stdout' => '1.2.13', 'stderr' => ''];
});
$reflection = new ReflectionClass($updater);
$call = static fn(string $method, ...$args) => $reflection->getMethod($method)->invoke($updater, ...$args);
$reflection->getProperty('stateFile')->setValue($updater, $stateFile);
$reflection->getProperty('lastCheckStartedAt')->setValue($updater, time());
$reflection->getProperty('acceptsCheckRequests')->setValue($updater, true);
$web = new SystemUpdater($stateFile);
try {
    $call('writeCheckingState', gmdate('c'), 'manual', ['requested_at' => gmdate('c')]);
    $call('progress', 'versions', '正在检查版本');
    $state = $web->status();
    functionalCheck($state['phase'] === 'versions' && $state['status'] === 'checking', 'Stage missing from web status');
    functionalCheck($state['cooldown_seconds'] > 0 && $state['cooldown_seconds'] <= 60, 'Cooldown is not bounded');
    functionalCheck($state['heartbeat_age_seconds'] === 0, 'Fresh heartbeat was not exposed');

    // Real cURL handles must remain distinct on PHP 8; no upstream services are contacted.
    $urls = [];
    foreach (['one', 'two', 'three'] as $name) {
        $file = $directory . '/' . $name;
        file_put_contents($file, $name);
        $urls[] = 'file://' . (PHP_OS_FAMILY === 'Windows' ? '/' : '') . str_replace('\\', '/', $file);
    }
    $responses = $call('fetchMany', $urls, false);
    functionalCheck(count($responses) === 3, 'Parallel source handles were overwritten');
    functionalCheck(array_column($responses, 'body') === ['one', 'two', 'three'], 'Source bodies were mixed');
    $state = $web->status();
    functionalCheck($state['probe_completed'] === 3 && $state['probe_total'] === 3, 'Source progress not recorded');

    $result = $call('pullVerifiedImage', '1.2.13', [['repository' => 'ghcr.io/example/panel']]);
    functionalCheck($result !== null && $observed === ['pull', 'verify'], 'Download and verification stages were not published before commands');
    $call('writeState', ['status' => 'up_to_date', 'message' => '已是最新', 'checked_at' => gmdate('c')]);
    $state = $web->status();
    functionalCheck($state['phase'] === 'complete' && $state['finished_at'] !== null, 'Completion has no finish time');
    functionalCheck($state['check_started_at'] !== null, 'Completion lost the start time');
    $call('progress', 'pull', '测试阻塞命令心跳');
    $beforeHeartbeat = $web->status()['updated_at'];
    $reflection->getProperty('commandRunner')->setValue($updater, null);
    $command = $call('runCommand', [PHP_BINARY, '-r', 'usleep(1200000);'], 5);
    functionalCheck($command['ok'], 'Heartbeat command fixture failed');
    functionalCheck($web->status()['updated_at'] !== $beforeHeartbeat, 'A running command stopped publishing heartbeat');
    functionalCheck($reflection->getConstant('CHECK_REQUEST_POLL_SECONDS') === 1, 'Manual wakeup is not checked every second');
} finally {
    foreach (glob($directory . '/*') as $file) unlink($file);
    rmdir($directory);
}

echo "Updater phases, heartbeat, cooldown and parallel source progress tests passed\n";
