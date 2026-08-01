<?php

declare(strict_types=1);

function dependencyIntegrityCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$lock = json_decode((string)file_get_contents($root . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
$packages = [];
foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
    $packages[$package['name']] = ltrim((string)$package['version'], 'v');
}

dependencyIntegrityCheck(
    isset($packages['topthink/framework'])
        && version_compare($packages['topthink/framework'], '8.1.4', '>='),
    'ThinkPHP must remain on the audited 8.1.4 or newer release'
);
dependencyIntegrityCheck(
    isset($packages['guzzlehttp/guzzle'])
        && version_compare($packages['guzzlehttp/guzzle'], '7.15.2', '>='),
    'Guzzle must remain on the audited 7.15.2 or newer release'
);
dependencyIntegrityCheck(
    !isset($packages['league/flysystem'])
        && !isset($packages['league/flysystem-cached-adapter']),
    'Unused legacy Flysystem packages must not return to the dependency tree'
);

$appConfig = file_get_contents($root . '/config/app.php');
dependencyIntegrityCheck(
    is_string($appConfig) && str_contains($appConfig, "'app_express'      => true"),
    'ThinkPHP 8 must route application-less URLs through the default index app'
);

$configMiddleware = file_get_contents($root . '/app/middleware/LoadConfigs.php');
dependencyIntegrityCheck(
    is_string($configMiddleware)
        && str_contains($configMiddleware, "['healthcheck', 'version']"),
    'Health and version probes must not perform database configuration queries'
);

echo "Dependency integrity tests passed\n";
