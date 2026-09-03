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

$composePath = $projectRoot . '/compose.yaml';
$compose = is_file($composePath) ? file_get_contents($composePath) : '';
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
