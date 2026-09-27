#!/usr/bin/env php
<?php

declare(strict_types=1);

// Usage: php check-ghcr-image.php <version> [owner/repo]
// Anonymously confirms what the updater will pull: the <version> tag lists
// linux/amd64 and linux/arm64, and both carry org.opencontainers.image.version.

$version = $argv[1] ?? '';
$repository = $argv[2] ?? '7788dev/loopdeck';
if (preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $version) !== 1) {
    fwrite(STDERR, "Usage: php check-ghcr-image.php <version> [owner/repo]\n");
    exit(2);
}

function ghcrJson(string $url, array $headers = []): array
{
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $body = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);
    $decoded = is_string($body) ? json_decode($body, true) : null;
    if ($status !== 200 || !is_array($decoded)) {
        throw new RuntimeException("GET {$url} returned HTTP {$status} {$error}");
    }
    return $decoded;
}

$failures = [];
try {
    $token = (string)(ghcrJson("https://ghcr.io/token?scope=repository:{$repository}:pull")['token'] ?? '');
    $auth = ['Authorization: Bearer ' . $token];
    $index = ghcrJson("https://ghcr.io/v2/{$repository}/manifests/{$version}", array_merge($auth, [
        'Accept: application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json',
    ]));
    foreach (['amd64', 'arm64'] as $architecture) {
        $digest = null;
        foreach ($index['manifests'] ?? [] as $entry) {
            if (($entry['platform']['os'] ?? '') === 'linux' && ($entry['platform']['architecture'] ?? '') === $architecture) {
                $digest = (string)$entry['digest'];
            }
        }
        if ($digest === null) {
            $failures[] = "linux/{$architecture} is missing";
            continue;
        }
        $manifest = ghcrJson("https://ghcr.io/v2/{$repository}/manifests/{$digest}", array_merge($auth, [
            'Accept: application/vnd.oci.image.manifest.v1+json, application/vnd.docker.distribution.manifest.v2+json',
        ]));
        $config = ghcrJson("https://ghcr.io/v2/{$repository}/blobs/" . ($manifest['config']['digest'] ?? ''), $auth);
        $label = $config['config']['Labels']['org.opencontainers.image.version'] ?? null;
        if ($label !== $version) {
            $failures[] = "linux/{$architecture} version label is " . var_export($label, true);
            continue;
        }
        echo "linux/{$architecture} {$digest} version={$label}\n";
    }
} catch (Throwable $exception) {
    $failures[] = $exception->getMessage();
}

if ($failures !== []) {
    fwrite(STDERR, "ghcr.io/{$repository}:{$version} is not ready: " . implode('; ', $failures) . "\n");
    exit(1);
}
echo "ghcr.io/{$repository}:{$version} is published for linux/amd64 and linux/arm64\n";
