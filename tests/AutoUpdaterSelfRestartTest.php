<?php

declare(strict_types=1);

$source = dirname(__DIR__) . '/docker/auto-updater.php';
require is_file($source) ? $source : '/usr/local/lib/loopdeck/auto-updater.php';

function updaterRestartCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$oldImage = 'sha256:' . str_repeat('a', 64);
$newImage = 'sha256:' . str_repeat('b', 64);
$updaterId = str_repeat('c', 64);
$appId = str_repeat('d', 64);
$runningUpdaterImage = $oldImage;
$launchFails = false;
$completeFails = false;
$commands = [];
$updater = new LoopDeckAutoUpdater(static function (array $arguments, int $timeout) use (
    &$commands, &$runningUpdaterImage, &$launchFails, &$completeFails, $oldImage, $newImage, $updaterId, $appId
): array {
    $commands[] = $arguments;
    $stdout = '';
    $ok = true;
    if (array_slice($arguments, -3) === ['ps', '-q', 'updater']) {
        $stdout = $updaterId;
    } elseif (array_slice($arguments, -3) === ['ps', '-q', 'app']) {
        $stdout = $appId;
    } elseif (array_slice($arguments, 0, 3) === ['docker', 'image', 'inspect']) {
        $stdout = $newImage; // The mutable tag has already moved to the candidate.
    } elseif (($arguments[1] ?? '') === 'inspect') {
        $stdout = end($arguments) === $appId ? $oldImage : $runningUpdaterImage;
    } elseif (($arguments[1] ?? '') === 'run') {
        $stdout = str_repeat('e', 64);
        $ok = !$launchFails;
    } elseif (in_array('up', $arguments, true)) {
        $ok = !$completeFails;
    } else {
        throw new RuntimeException('Unexpected Docker operation in updater test');
    }
    return ['ok' => $ok, 'code' => $ok ? 0 : 1, 'stdout' => $stdout, 'stderr' => $ok ? '' : 'fixture Docker failure'];
});
$reflection = new ReflectionClass($updater);
$restart = $reflection->getMethod('scheduleUpdaterRestart');
$restart->setAccessible(true);
updaterRestartCheck($restart->invoke($updater, $newImage)['ok'], 'Detached updater replacement was not scheduled');
$launch = end($commands);
updaterRestartCheck(array_slice($launch, 0, 2) === ['docker', 'run']
    && in_array('--detach', $launch, true) && in_array('--rm', $launch, true)
    && in_array($updaterId . ':ro', $launch, true) && in_array($newImage, $launch, true)
    && end($launch) === '--complete-updater-restart', 'Replacement would run inside the container it stops');
updaterRestartCheck(in_array('--network', $launch, true) && in_array('none', $launch, true)
    && in_array('--read-only', $launch, true) && in_array('--memory', $launch, true),
    'Transient helper lost its resource or access restrictions');
updaterRestartCheck(!in_array('up', $launch, true), 'Parent invoked Compose self-replacement synchronously');

$commands = [];
$runningUpdaterImage = $newImage;
updaterRestartCheck($restart->invoke($updater, $newImage)['ok'], 'Matching updater image was not accepted');
updaterRestartCheck(count(array_filter($commands, static fn(array $command): bool => ($command[1] ?? '') === 'run')) === 0,
    'An up-to-date updater launched a redundant helper');
updaterRestartCheck(!$restart->invoke($updater, null)['ok'], 'Missing verified image was allowed to replace the updater');

$running = $reflection->getMethod('runningImageId');
$running->setAccessible(true);
updaterRestartCheck($running->invoke($updater, 'app') === $oldImage,
    'Rollback retained the moved image tag instead of the running application image');
$commands = [];
updaterRestartCheck($updater->completeUpdaterRestart() === 0, 'Helper did not complete replacement');
$operation = array_slice(end($commands), array_search('up', end($commands), true));
updaterRestartCheck(end($operation) === 'updater' && in_array('--no-deps', $operation, true)
    && in_array('--wait', $operation, true) && !in_array('app', $operation, true)
    && !in_array('db', $operation, true), 'Helper touched application/database services or skipped startup verification');

$launchFails = true;
$runningUpdaterImage = $oldImage;
$stateFile = tempnam(sys_get_temp_dir(), 'loopdeck-updater-test-');
$stateProperty = $reflection->getProperty('stateFile');
$stateProperty->setAccessible(true);
$stateProperty->setValue($updater, $stateFile);
$sync = $reflection->getMethod('syncUpdater');
$sync->setAccessible(true);
try {
    updaterRestartCheck(!$sync->invoke($updater, ['current_version' => '1.2.3'], $newImage),
        'A failed helper launch was reported as completed');
    $state = json_decode(file_get_contents($stateFile), true, 16, JSON_THROW_ON_ERROR);
    updaterRestartCheck($state['status'] === 'failed' && strtotime($state['next_check_at']) > time(),
        'A failed helper launch lost its retry state');
} finally {
    unlink($stateFile);
}
$completeFails = true;
updaterRestartCheck($updater->completeUpdaterRestart() === 1, 'Helper hid a failed Compose replacement');

echo "Automatic updater self-restart tests passed: detached helper, scoped mounts, retry and running-image rollback\n";
