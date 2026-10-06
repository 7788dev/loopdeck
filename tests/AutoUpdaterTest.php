<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$implementationCandidates = [
    $projectRoot . '/docker/auto-updater.php',
    '/usr/local/lib/loopdeck/auto-updater.php',
];
$implementationPath = null;
foreach ($implementationCandidates as $candidate) {
    if (is_file($candidate)) {
        $implementationPath = $candidate;
        break;
    }
}
if ($implementationPath === null) {
    throw new RuntimeException('Automatic updater implementation is not available');
}
require $implementationPath;

function autoUpdaterCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

putenv('APP_IMAGE=ghcr.io/7788dev/loopdeck:latest');
putenv('UPDATE_VERSION_SOURCES=https://one.example/VERSION,https://two.example/VERSION');
putenv('UPDATE_IMAGE_REPOSITORIES=mirror.example/7788dev/loopdeck');
putenv('UPDATE_CHECK_INTERVAL_SECONDS=1');
putenv('UPDATE_PROBE_TIMEOUT_SECONDS=999');

$updater = new LoopDeckAutoUpdater();
$reflection = new ReflectionClass($updater);
$versionSources = $reflection->getProperty('versionSources');
$versionSources->setAccessible(true);
$repositories = $reflection->getProperty('imageRepositories');
$repositories->setAccessible(true);
$checkInterval = $reflection->getProperty('checkInterval');
$checkInterval->setAccessible(true);
$probeTimeout = $reflection->getProperty('probeTimeout');
$probeTimeout->setAccessible(true);

autoUpdaterCheck(in_array('https://one.example/VERSION', $versionSources->getValue($updater), true), 'Configured version source was ignored');
autoUpdaterCheck(in_array('mirror.example/7788dev/loopdeck', $repositories->getValue($updater), true), 'Configured mirror was ignored');
autoUpdaterCheck($checkInterval->getValue($updater) === 60, 'Check interval was not clamped safely');
autoUpdaterCheck($probeTimeout->getValue($updater) === 60, 'Probe timeout was not clamped safely');

$normalize = $reflection->getMethod('normalizeVersion');
$normalize->setAccessible(true);
autoUpdaterCheck($normalize->invoke($updater, " v1.2.3\n") === '1.2.3', 'Version normalization failed');
autoUpdaterCheck($normalize->invoke($updater, 'latest') === null, 'Invalid version was accepted');

$parse = $reflection->getMethod('parseVersionBody');
$parse->setAccessible(true);
$encoded = base64_encode("1.2.4\n");
autoUpdaterCheck($parse->invoke($updater, json_encode(['content' => $encoded])) === '1.2.4', 'GitHub contents response was not decoded');

$repository = $reflection->getMethod('imageRepository');
$repository->setAccessible(true);
autoUpdaterCheck($repository->invoke($updater, 'ghcr.io/7788dev/loopdeck:latest') === 'ghcr.io/7788dev/loopdeck', 'Image tag was not stripped');
autoUpdaterCheck($repository->invoke($updater, 'ghcr.io/7788dev/loopdeck@sha256:' . str_repeat('a', 64)) === 'ghcr.io/7788dev/loopdeck', 'Image digest was not stripped');
autoUpdaterCheck($repository->invoke($updater, 'bad;command') === null, 'Unsafe image repository was accepted');

$oldImageId = 'sha256:' . str_repeat('a', 64);
$newImageId = 'sha256:' . str_repeat('b', 64);
$otherImageId = 'sha256:' . str_repeat('e', 64);
$runningContainer = str_repeat('d', 40);
$targetImageId = $newImageId;
$replacementImageId = $newImageId;
$containerListed = true;
$replacementUpdater = new LoopDeckAutoUpdater(static function (array $arguments, int $timeout) use (
    &$targetImageId, &$replacementImageId, &$containerListed, $runningContainer
): array {
    if (array_slice($arguments, 0, 3) === ['docker', 'image', 'inspect']) {
        return ['ok' => true, 'code' => 0, 'stdout' => $targetImageId, 'stderr' => ''];
    }
    if (array_slice($arguments, -3) === ['ps', '-q', 'app']) {
        return ['ok' => true, 'code' => 0, 'stdout' => $containerListed ? $runningContainer : '', 'stderr' => ''];
    }
    if (($arguments[1] ?? '') === 'inspect') {
        return ['ok' => true, 'code' => 0, 'stdout' => $replacementImageId, 'stderr' => ''];
    }
    throw new RuntimeException('Unexpected Docker operation in replacement test');
});
$verifyReplacement = (new ReflectionClass($replacementUpdater))->getMethod('verifyReplacement');
$verifyReplacement->setAccessible(true);
$target = (string)$targetImageId;

// Compose can leave the previous container running, and it can also leave some
// third image running; only the verified target image counts as this update.
$unchanged = $verifyReplacement->invoke($replacementUpdater, $target);
autoUpdaterCheck($unchanged['ok'] === true && $unchanged['image_id'] === $target,
    'The verified target image was not accepted as the running image');
$replacementImageId = $oldImageId;
$stale = $verifyReplacement->invoke($replacementUpdater, $target);
autoUpdaterCheck($stale['ok'] === false && str_contains($stale['error'], '目标镜像'),
    'The previous image was accepted as a completed update');
$replacementImageId = $otherImageId;
$foreign = $verifyReplacement->invoke($replacementUpdater, $target);
autoUpdaterCheck($foreign['ok'] === false && $foreign['image_id'] === $otherImageId,
    'An unrelated image was accepted because it differed from the old one');
$containerListed = false;
$unresolved = $verifyReplacement->invoke($replacementUpdater, $target);
autoUpdaterCheck($unresolved['ok'] === false && $unresolved['image_id'] === null,
    'An unreadable app container was treated as proof of replacement');

$imageId = (new ReflectionClass($replacementUpdater))->getMethod('imageId');
$imageId->setAccessible(true);
autoUpdaterCheck($imageId->invoke($replacementUpdater, 'ghcr.io/7788dev/loopdeck:latest') === $newImageId,
    'The target image id could not be read from the tagged image');

$implementationSource = (string)file_get_contents($implementationPath);
autoUpdaterCheck(substr_count($implementationSource, "['status'] = 'updated'") === 1,
    'A second writer of the updated status appeared');
autoUpdaterCheck(
    strpos($implementationSource, '$this->verifyReplacement(')
        < strpos($implementationSource, "['status'] = 'updated'"),
    'The updated status is no longer written behind the replacement proof'
);

$composePath = $projectRoot . '/compose.yaml';
$compose = is_file($composePath) ? str_replace("\r\n", "\n", (string)file_get_contents($composePath)) : '';
$wrapperCandidates = [
    $projectRoot . '/docker/auto-updater.sh',
    '/usr/local/bin/loopdeck-auto-updater',
];
$wrapper = '';
foreach ($wrapperCandidates as $candidate) {
    if (is_file($candidate)) {
        $wrapper = (string)file_get_contents($candidate);
        break;
    }
}
if ($compose !== '') {
    $updaterBlock = '';
    if (preg_match('/(?ms)^  updater:\R(?<block>.*?)(?=^  [A-Za-z0-9_-]+:\s*$|\z)/', (string)$compose, $matches) === 1) {
        $updaterBlock = (string)($matches['block'] ?? '');
    }
    autoUpdaterCheck(str_contains($updaterBlock, '/usr/local/bin/loopdeck-auto-updater'), 'Compose does not start the automatic updater');
    autoUpdaterCheck(str_contains($updaterBlock, '/var/run/docker.sock'), 'Updater does not have the Docker socket');
    autoUpdaterCheck(str_contains($updaterBlock, 'UPDATE_IMAGE_REPOSITORIES'), 'Compose does not expose mirror configuration');
    autoUpdaterCheck(!str_contains((string)$compose, 'watchtower'), 'Legacy Watchtower updater remains configured');
    autoUpdaterCheck(str_contains($updaterBlock, "healthcheck:\n      disable: true"), 'Updater inherited an HTTP healthcheck');
    autoUpdaterCheck(str_contains($updaterBlock, "cap_add:\n      - DAC_OVERRIDE"), 'Updater cannot write its shared state volume');
}
autoUpdaterCheck($wrapper !== '' && str_contains($wrapper, 'auto-updater.php'), 'Updater wrapper does not invoke the implementation');
$dockerfilePath = dirname(__DIR__) . '/Dockerfile';
if (is_file($dockerfilePath)) {
    $dockerfile = (string)file_get_contents($dockerfilePath);
    autoUpdaterCheck(
        str_contains($dockerfile, 'COPY docker/auto-updater.php')
            && str_contains($dockerfile, './docker/'),
        'Docker build test stage cannot load the updater implementation'
    );
    autoUpdaterCheck(str_contains($dockerfile, 'rm -f /var/www/html/docker/auto-updater.php'), 'Updater source was left in the web root');
}

putenv('UPDATE_VERSION_SOURCES');
putenv('UPDATE_IMAGE_REPOSITORIES');
putenv('UPDATE_CHECK_INTERVAL_SECONDS');
putenv('UPDATE_PROBE_TIMEOUT_SECONDS');
echo "Automatic updater configuration tests passed\n";
