<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$updaterSource = $root . '/docker/auto-updater.php';
require is_file($updaterSource) ? $updaterSource : '/usr/local/lib/loopdeck/auto-updater.php';

use app\service\SystemUpdater;
use think\facade\Config;

function checkRequestCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function writeUpdaterFixtureState(string $stateFile, array $state): void
{
    file_put_contents($stateFile, json_encode($state + ['schema' => 1, 'enabled' => true], JSON_UNESCAPED_SLASHES));
    clearstatcache();
}

function removeFixtureDirectory(string $directory): void
{
    foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
        $path = $directory . DIRECTORY_SEPARATOR . $entry;
        is_dir($path) ? removeFixtureDirectory($path) : @unlink($path);
    }
    @rmdir($directory);
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'loopdeck-check-request-' . bin2hex(random_bytes(6));
checkRequestCheck(mkdir($directory, 0700, true), 'Unable to create the check request fixture directory');
$stateFile = $directory . DIRECTORY_SEPARATOR . 'auto-updater-state.json';
$marker = $stateFile . '.check-request';
$web = new SystemUpdater(null, ['state_file' => $stateFile, 'enabled' => true]);

try {
    // The page only offers the button to an updater that advertises polling.
    $status = $web->status();
    checkRequestCheck(!$status['manual_check_available'] && str_contains((string)$status['manual_check_hint'], 'updater'),
        'A missing updater state offered manual checks');
    checkRequestCheck(!$web->requestCheck()['accepted'] && !is_file($marker), 'A request was queued without a running updater');

    $checkedAt = gmdate('c', time() - 3600);
    writeUpdaterFixtureState($stateFile, ['status' => 'up_to_date', 'checked_at' => $checkedAt, 'latest_version' => '1.2.10']);
    $status = $web->status();
    checkRequestCheck(!$status['manual_check_available'] && str_contains((string)$status['manual_check_hint'], '尚未支持'),
        'An updater that never polls for requests was offered manual checks');
    checkRequestCheck(!$web->requestCheck()['accepted'] && !is_file($marker), 'A request was queued for an old updater');

    $disabled = new SystemUpdater(null, ['state_file' => $stateFile, 'enabled' => false]);
    checkRequestCheck(!$disabled->requestCheck()['accepted'] && !is_file($marker), 'Disabled updates accepted a check request');

    writeUpdaterFixtureState($stateFile, [
        'status' => 'up_to_date',
        'checked_at' => $checkedAt,
        'latest_version' => '1.2.10',
        'accepts_check_requests' => true,
    ]);
    $status = $web->status();
    checkRequestCheck($status['manual_check_available'] && $status['manual_check_hint'] === null
        && $status['check_requested_at'] === null, 'A polling updater was not offered manual checks');
    $result = $web->requestCheck();
    checkRequestCheck($result['accepted'] && str_contains($result['message'], '立即检查'), 'The manual check request was refused');
    checkRequestCheck(is_file($marker) && filesize($marker) === 0, 'The check request was not an empty marker');
    $status = $web->status();
    checkRequestCheck($status['check_requested_at'] !== null && !$status['check_request_stale'],
        'A queued request was not reported as pending');
    $again = $web->requestCheck();
    checkRequestCheck($again['accepted'] && str_contains($again['message'], '已有'), 'A repeated click did not reuse the pending request');
    touch($marker, time() - 600);
    clearstatcache();
    checkRequestCheck($web->status()['check_request_stale'], 'An unhandled request was not flagged');

    // The updater wakes early, honours the cooldown and reads nothing but mtime.
    $commands = [];
    $stateDuringCheck = null;
    $updater = new LoopDeckAutoUpdater(static function (array $arguments, int $timeout) use (
        &$commands, &$stateDuringCheck, $stateFile
    ): array {
        $commands[] = $arguments;
        if (array_slice($arguments, -3) === ['ps', '-q', 'app']) {
            $stateDuringCheck ??= json_decode((string)file_get_contents($stateFile), true);
            return ['ok' => true, 'code' => 0, 'stdout' => str_repeat('d', 64), 'stderr' => ''];
        }
        if (($arguments[1] ?? '') === 'inspect') {
            return ['ok' => true, 'code' => 0, 'stdout' => '1.2.10', 'stderr' => ''];
        }
        throw new RuntimeException('Unexpected Docker operation in check request test');
    });
    $reflection = new ReflectionClass($updater);
    $set = static function (string $name, $value) use ($reflection, $updater): void {
        $reflection->getProperty($name)->setValue($updater, $value);
    };
    $call = static function (string $name, ...$arguments) use ($reflection, $updater) {
        return $reflection->getMethod($name)->invoke($updater, ...$arguments);
    };
    $set('stateFile', $stateFile);
    $set('appImage', 'ghcr.io/7788dev/loopdeck:latest');
    $set('versionSources', []);

    $set('lastCheckStartedAt', time());
    checkRequestCheck($call('consumeCheckRequest') === null && is_file($marker), 'A request bypassed the check cooldown');
    $set('lastCheckStartedAt', time() - 61);
    file_put_contents($marker, '{"image":"attacker.example/loopdeck:evil","sources":["https://attacker.example/VERSION"]}');
    $requestTime = time() - 30;
    touch($marker, $requestTime);
    $started = microtime(true);
    $request = $call('waitForNextCheck', 3600);
    clearstatcache();
    checkRequestCheck(microtime(true) - $started < 2, 'The updater did not wake early for a pending request');
    checkRequestCheck($request === ['requested_at' => gmdate('c', $requestTime)], 'The request carried more than its timestamp');
    checkRequestCheck(!is_file($marker), 'A consumed request was left behind');

    $set('acceptsCheckRequests', true);
    checkRequestCheck($call('runOnce', $request) === false, 'Unavailable version sources were reported as success');
    checkRequestCheck(is_array($stateDuringCheck) && $stateDuringCheck['status'] === 'checking'
        && $stateDuringCheck['trigger'] === 'manual' && $stateDuringCheck['checked_at'] === $checkedAt
        && $stateDuringCheck['latest_version'] === '1.2.10', 'The in-progress state dropped the previous result');
    $final = json_decode((string)file_get_contents($stateFile), true, 16, JSON_THROW_ON_ERROR);
    checkRequestCheck($final['status'] === 'failed' && $final['trigger'] === 'manual'
        && $final['requested_at'] === gmdate('c', $requestTime) && $final['accepts_check_requests'] === true,
        'The manual check result was not recorded');
    checkRequestCheck($final['image'] === 'ghcr.io/7788dev/loopdeck:latest'
        && !str_contains(json_encode($commands), 'attacker.example'), 'Request content reached the updater configuration');
    $status = $web->status();
    checkRequestCheck($status['trigger'] === 'manual' && $status['check_requested_at'] === null
        && $status['manual_check_available'], 'The status page did not reflect the handled request');

    checkRequestCheck($updater->run(true) === 1, 'A one-off run hid its failed check');
    $once = json_decode((string)file_get_contents($stateFile), true, 16, JSON_THROW_ON_ERROR);
    checkRequestCheck($once['accepts_check_requests'] === false && $once['trigger'] === 'automatic',
        'A one-off run advertised request polling it never performs');
    checkRequestCheck(!$web->status()['manual_check_available'], 'The page offered manual checks with no polling updater');

    // A request made during a check waits for it; abandoned checks are not shown as running.
    writeUpdaterFixtureState($stateFile, [
        'status' => 'checking',
        'checked_at' => $checkedAt,
        'check_started_at' => gmdate('c', time() - 30),
        'accepts_check_requests' => true,
    ]);
    checkRequestCheck($web->status()['status'] === 'checking', 'A running check was not reported');
    $queued = $web->requestCheck();
    checkRequestCheck($queued['accepted'] && str_contains($queued['message'], '本次检查结束后'),
        'A request during a running check was not queued behind it');
    touch($marker, time() - 600);
    clearstatcache();
    checkRequestCheck(!$web->status()['check_request_stale'], 'A request waiting behind a running check was flagged');
    unlink($marker);
    writeUpdaterFixtureState($stateFile, [
        'status' => 'checking',
        'checked_at' => $checkedAt,
        'check_started_at' => gmdate('c', time() - 3 * 3600),
        'accepts_check_requests' => true,
    ]);
    $abandoned = $web->status();
    checkRequestCheck($abandoned['status'] === 'failed' && str_contains((string)$abandoned['error'], 'updater'),
        'An abandoned check was reported as still running');

    // The web side may queue a check but never execute processes or pick the image.
    $serviceSource = (string)file_get_contents($root . '/app/service/SystemUpdater.php');
    checkRequestCheck(preg_match('/(?<![\w>$:])(?:proc_open|shell_exec|exec|passthru|popen|system|pcntl_exec)\s*\(/', $serviceSource) !== 1
        && !str_contains($serviceSource, 'docker.sock'), 'The web updater service can execute processes');
    $ajaxSource = str_replace("\r\n", "\n", (string)file_get_contents($root . '/app/admin/controller/Ajax.php'));
    $action = preg_match('/public function updater\(.*?\n    }\n/s', $ajaxSource, $match) === 1 ? $match[0] : '';
    preg_match_all('/\$updater->(\w+)\(/', $action, $calls);
    checkRequestCheck($action !== '' && $calls[1] !== [] && array_diff($calls[1], ['status', 'requestCheck']) === [],
        'The admin updater action does more than read status and queue a check');
    checkRequestCheck(str_contains($action, 'Request::isPost()')
        && str_contains($ajaxSource, "'app\\middleware\\CheckAjaxRequest'"), 'Check requests skip the POST and AJAX origin guards');

    // The page offers the button only when a request can be handled.
    if (!defined('PJAX')) {
        define('PJAX', true);
    }
    if (!defined('WEB_ID')) {
        define('WEB_ID', 1);
    }
    $app = new think\App($root);
    $app->initialize();
    Config::set(['webname' => 'LoopDeck', 'title' => 'Test'], 'web');
    $engine = new think\Template([
        'view_path' => $root . '/app/admin/view/',
        'cache_path' => $directory . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR,
    ]);
    $render = static function () use ($engine, $web): array {
        ob_start();
        try {
            $engine->fetch('system/update', $web->status() + ['webTitle' => '自动更新状态']);
            $html = (string)ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $button = preg_match('/<button id="updater-check-button".*?>/s', $html, $match) === 1 ? $match[0] : '';
        return [$html, $button];
    };

    writeUpdaterFixtureState($stateFile, [
        'status' => 'up_to_date',
        'checked_at' => $checkedAt,
        'latest_version' => '1.2.10',
        'accepts_check_requests' => true,
    ]);
    [$html, $button] = $render();
    checkRequestCheck($button !== '' && !str_contains($button, 'disabled') && str_contains($button, 'data-watch="0"')
        && str_contains($button, 'data-checked-at="' . $checkedAt . '"'), 'The idle page did not offer an immediate check');
    checkRequestCheck(str_contains($html, "'/admin/ajax/updater/check'") && str_contains($html, "'/admin/ajax/updater/status'"),
        'The page does not call the check request endpoints');

    checkRequestCheck($web->requestCheck()['accepted'], 'The render fixture could not queue a request');
    [$html, $button] = $render();
    checkRequestCheck(str_contains($button, 'disabled') && str_contains($button, 'data-watch="1"')
        && str_contains($html, '已提交检查请求'), 'The pending request was not shown or could be queued twice');
    unlink($marker);

    writeUpdaterFixtureState($stateFile, ['status' => 'up_to_date', 'checked_at' => $checkedAt]);
    [$html, $button] = $render();
    checkRequestCheck(str_contains($button, 'disabled') && str_contains($html, '尚未支持立即检查'),
        'An old updater was offered the button without an explanation');
} finally {
    removeFixtureDirectory($directory);
}

echo "Updater check request tests passed: marker-only request, cooldown, in-progress state and page states\n";
