#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * LoopDeck's in-container updater.
 *
 * The updater deliberately uses the Docker socket only for the three
 * application services. It never removes volumes, prunes images, or mutates
 * the database service. A version is selected from the highest value reported
 * by all configured sources; image mirrors are then ordered by probe latency
 * and verified with the OCI version label before they are used.
 */
final class LoopDeckAutoUpdater
{
    private const DEFAULT_VERSION_SOURCES = [
        'https://api.github.com/repos/7788dev/loopdeck/contents/VERSION?ref=main',
        'https://cdn.jsdelivr.net/gh/7788dev/loopdeck@main/VERSION',
        'https://raw.githubusercontent.com/7788dev/loopdeck/main/VERSION',
        'https://gh-proxy.com/raw.githubusercontent.com/7788dev/loopdeck/main/VERSION',
        'https://ghfast.top/https://raw.githubusercontent.com/7788dev/loopdeck/main/VERSION',
    ];

    private const DEFAULT_IMAGE_REPOSITORIES = [
        'ghcr.io/7788dev/loopdeck',
        'ghcr.nju.edu.cn/7788dev/loopdeck',
        'ghcr.1ms.run/7788dev/loopdeck',
        'docker.1ms.run/ghcr.io/7788dev/loopdeck',
    ];

    private string $projectDir;
    private string $composeFile;
    private string $envFile;
    private string $stateFile;
    private string $appImage;
    private array $versionSources;
    private array $imageRepositories;
    private bool $enabled;
    private int $checkInterval;
    private int $retryInterval;
    private int $probeTimeout;
    private int $pullTimeout;
    private ?Closure $commandRunner;

    public function __construct(?callable $commandRunner = null)
    {
        $this->commandRunner = $commandRunner === null ? null : Closure::fromCallable($commandRunner);
        $this->projectDir = $this->absolutePath($this->env('UPDATE_PROJECT_DIR', '/opt/loopdeck'));
        $this->composeFile = $this->projectDir . DIRECTORY_SEPARATOR . 'compose.yaml';
        $this->envFile = $this->projectDir . DIRECTORY_SEPARATOR . '.env';
        $this->stateFile = $this->absolutePath($this->env(
            'AUTO_UPDATE_STATE_FILE',
            '/var/lib/loopdeck/runtime/auto-updater-state.json'
        ));
        $this->appImage = trim($this->env('APP_IMAGE', 'ghcr.io/7788dev/loopdeck:latest'));
        $this->enabled = $this->boolEnv('AUTO_UPDATE_ENABLED', true);
        $this->checkInterval = $this->intEnv('UPDATE_CHECK_INTERVAL_SECONDS', 21600, 60, 604800);
        $this->retryInterval = $this->intEnv('UPDATE_RETRY_INTERVAL_SECONDS', 300, 30, 86400);
        $this->probeTimeout = $this->intEnv('UPDATE_PROBE_TIMEOUT_SECONDS', 8, 2, 60);
        $this->pullTimeout = $this->intEnv('UPDATE_PULL_TIMEOUT_SECONDS', 900, 60, 7200);

        $configuredSources = $this->csvEnv('UPDATE_VERSION_SOURCES');
        $legacyVersionUrl = trim($this->env('UPDATE_VERSION_URL', ''));
        if ($legacyVersionUrl !== '') {
            array_unshift($configuredSources, $legacyVersionUrl);
        }
        $this->versionSources = $this->uniqueStrings(
            $configuredSources !== [] ? $configuredSources : self::DEFAULT_VERSION_SOURCES
        );

        $configuredRepositories = $this->csvEnv('UPDATE_IMAGE_REPOSITORIES');
        $configuredAppRepository = $this->imageRepository($this->appImage);
        if ($configuredAppRepository !== null) {
            array_unshift($configuredRepositories, $configuredAppRepository);
        }
        $this->imageRepositories = $this->uniqueStrings(
            $configuredRepositories !== [] ? $configuredRepositories : self::DEFAULT_IMAGE_REPOSITORIES
        );
    }

    public function run(bool $once): int
    {
        $lockPath = $this->stateFile . '.lock';
        $lockDirectory = dirname($lockPath);
        if (!is_dir($lockDirectory)) {
            @mkdir($lockDirectory, 0775, true);
        }
        $lock = @fopen($lockPath, 'c+');
        if (!is_resource($lock)) {
            $this->log('无法创建更新锁，退出');
            return 1;
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            $this->log('已有更新器实例运行，退出');
            return 0;
        }

        try {
            do {
                $success = $this->runOnce();
                if ($once) {
                    return $success ? 0 : 1;
                }
                sleep($success ? $this->checkInterval : $this->retryInterval);
            } while (true);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function runOnce(): bool
    {
        $checkedAt = gmdate('c');
        $metadata = [
            'checked_at' => $checkedAt,
            'current_version' => $this->currentVersion(),
            'latest_version' => null,
            'update_available' => false,
            'version_source' => null,
            'image_repository' => null,
            'image' => $this->appImage,
            'message' => null,
            'error' => null,
        ];

        if (!$this->enabled) {
            $this->writeState($metadata + [
                'status' => 'disabled',
                'enabled' => false,
                'next_check_at' => gmdate('c', time() + $this->checkInterval),
            ]);
            $this->log('自动更新已禁用');
            return true;
        }

        $metadata['enabled'] = true;
        $currentVersion = (string)$metadata['current_version'];
        $versionResult = $this->latestVersion();
        if ($versionResult === null) {
            $metadata['status'] = 'failed';
            $metadata['message'] = '版本源暂时不可用，将在稍后重试';
            $metadata['error'] = '没有可用的有效 VERSION 源';
            $metadata['next_check_at'] = gmdate('c', time() + $this->retryInterval);
            $this->writeState($metadata);
            $this->log('版本源不可用');
            return false;
        }

        $latestVersion = $versionResult['version'];
        $metadata['latest_version'] = $latestVersion;
        $metadata['version_source'] = $this->safeUrl((string)$versionResult['url']);
        $metadata['version_sources'] = $versionResult['sources'];
        $metadata['update_available'] = $currentVersion === ''
            || version_compare($latestVersion, $currentVersion, '>');

        if (!$metadata['update_available']) {
            $metadata['status'] = 'up_to_date';
            $metadata['message'] = '当前已是最新版本';
            $metadata['next_check_at'] = gmdate('c', time() + $this->checkInterval);
            $this->writeState($metadata);
            $this->log('当前已是最新版本 ' . $currentVersion);
            // Retry a previously interrupted updater replacement even when the
            // application itself already has the requested version.
            return $this->syncUpdater($metadata, $this->runningImageId('app'));
        }

        if ($this->hasDigestReference($this->appImage)) {
            $metadata['status'] = 'failed';
            $metadata['message'] = 'APP_IMAGE 使用固定 digest，无法自动替换标签';
            $metadata['error'] = '请在 .env 中改用可更新的镜像标签';
            $metadata['next_check_at'] = gmdate('c', time() + $this->retryInterval);
            $this->writeState($metadata);
            $this->log('APP_IMAGE 使用 digest，跳过更新');
            return false;
        }

        $probes = $this->probeRepositories();
        $metadata['image_probes'] = $probes;
        if ($probes === []) {
            $metadata['status'] = 'failed';
            $metadata['message'] = '没有可用的镜像代理源，将在稍后重试';
            $metadata['error'] = '所有镜像仓库探测均失败';
            $metadata['next_check_at'] = gmdate('c', time() + $this->retryInterval);
            $this->writeState($metadata);
            $this->log('镜像代理源不可用');
            return false;
        }

        // A pull may move APP_IMAGE's mutable tag. The rollback target must
        // come from the running application before any candidate is pulled.
        $oldImageId = $this->runningImageId('app');
        if ($oldImageId === null) {
            $metadata['status'] = 'failed';
            $metadata['message'] = '无法确认当前应用镜像，暂缓更新';
            $metadata['error'] = '未能保留有效的回滚镜像';
            $metadata['next_check_at'] = gmdate('c', time() + $this->retryInterval);
            $this->writeState($metadata);
            return false;
        }
        $pulled = $this->pullVerifiedImage($latestVersion, $probes);
        if ($pulled === null) {
            $metadata['status'] = 'failed';
            $metadata['message'] = '目标版本镜像尚未在可用源发布，将在稍后重试';
            $metadata['error'] = '所有候选镜像的 OCI 版本标签均未匹配 ' . $latestVersion;
            $metadata['next_check_at'] = gmdate('c', time() + $this->retryInterval);
            $this->writeState($metadata);
            $this->log('未找到已校验的镜像版本 ' . $latestVersion);
            return false;
        }

        $metadata['image_repository'] = $pulled['repository'];
        $metadata['image'] = $pulled['reference'];
        if (!$this->runDocker(['tag', $pulled['reference'], $this->appImage], 30)['ok']) {
            $metadata['status'] = 'failed';
            $metadata['message'] = '镜像标记失败';
            $metadata['error'] = '无法将已校验镜像标记为 APP_IMAGE';
            $metadata['next_check_at'] = gmdate('c', time() + $this->retryInterval);
            $this->writeState($metadata);
            return false;
        }

        if (!$this->restartApplication()) {
            $this->rollback($oldImageId);
            $metadata['status'] = 'rolled_back';
            $metadata['message'] = '新版本健康检查失败，已回滚旧镜像';
            $metadata['error'] = 'app 容器未通过 healthcheck';
            $metadata['next_check_at'] = gmdate('c', time() + $this->retryInterval);
            $this->writeState($metadata);
            $this->log('版本 ' . $latestVersion . ' 健康检查失败，已回滚');
            return false;
        }

        $metadata['status'] = 'updated';
        $metadata['message'] = '已更新到 v' . $latestVersion;
        $metadata['last_update_at'] = $checkedAt;
        $metadata['next_check_at'] = gmdate('c', time() + $this->checkInterval);
        $this->writeState($metadata);
        $this->log('应用已更新到 ' . $latestVersion . '，来源 ' . $pulled['repository']);

        return $this->syncUpdater($metadata, $this->runningImageId('app'));
    }

    /** A short-lived external container survives the old updater's shutdown. */
    private function syncUpdater(array $metadata, ?string $imageId): bool
    {
        $result = $this->scheduleUpdaterRestart($imageId);
        if ($result['ok']) {
            return true;
        }
        $metadata['status'] = 'failed';
        $metadata['message'] = '应用已更新，更新器重建暂时失败，将自动重试';
        $metadata['error'] = $this->shortError($result['stderr']);
        $metadata['next_check_at'] = gmdate('c', time() + $this->retryInterval);
        $this->writeState($metadata);
        $this->log($metadata['message'] . '：' . $metadata['error']);
        return false;
    }

    private function scheduleUpdaterRestart(?string $imageId): array
    {
        $container = $this->compose(['ps', '-q', 'updater'], 20);
        $containerId = trim($container['stdout']);
        if ($imageId === null || !$container['ok'] || !preg_match('/^[a-f0-9]{12,64}$/', $containerId)) {
            return ['ok' => false, 'stderr' => '无法确认更新器容器或目标镜像'];
        }
        $current = $this->runDocker(['inspect', '--format', '{{.Image}}', $containerId], 20);
        if ($current['ok'] && trim($current['stdout']) === $imageId) {
            return ['ok' => true, 'stderr' => ''];
        }
        if (!$current['ok'] || $this->imageId($this->appImage) !== $imageId) {
            return ['ok' => false, 'stderr' => '更新器目标镜像与当前应用不一致'];
        }
        // Inherit the real host mounts instead of assuming /opt/loopdeck is
        // also the host path. No credentials need to travel in command args.
        return $this->runDocker([
            'run', '--detach', '--rm', '--pull', 'never',
            '--label', 'io.loopdeck.updater-helper=true',
            '--label', 'io.loopdeck.updater-parent=' . $containerId,
            '--network', 'none', '--read-only', '--tmpfs', '/tmp:size=8m,noexec,nosuid,nodev',
            '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges:true',
            '--memory', '64m', '--cpus', '0.20', '--pids-limit', '64', '--user', '0:0',
            '--volumes-from', $containerId . ':ro',
            '--env', 'UPDATE_PROJECT_DIR=' . $this->projectDir,
            '--env', 'APP_IMAGE=' . $this->appImage,
            '--entrypoint', 'php', $imageId,
            '/usr/local/lib/loopdeck/auto-updater.php', '--complete-updater-restart',
        ], 30);
    }

    /** Internal helper mode: never run replacement from the container being stopped. */
    public function completeUpdaterRestart(): int
    {
        $result = $this->compose([
            'up', '--no-build', '--no-deps', '--pull', 'never', '--wait', '--wait-timeout', '90', 'updater',
        ], 180);
        if (!$result['ok']) {
            $this->log('更新器重建失败：' . $this->shortError($result['stderr']));
        }
        return $result['ok'] ? 0 : 1;
    }

    /** @return array{version:string,url:string,sources:array}|null */
    private function latestVersion(): ?array
    {
        if (!function_exists('curl_multi_init')) {
            return null;
        }

        $urls = [];
        foreach ($this->versionSources as $source) {
            if (filter_var($source, FILTER_VALIDATE_URL) === false) {
                continue;
            }
            $urls[] = $this->cacheBust($source);
        }
        $responses = $this->fetchMany($urls, false);
        $valid = [];
        $sourceSummaries = [];
        foreach ($responses as $url => $response) {
            $version = $this->parseVersionBody($response['body']);
            $summary = [
                'source' => $this->safeUrl($url),
                'http_code' => $response['http_code'],
                'latency_ms' => $response['latency_ms'],
                'version' => $version,
            ];
            if ($response['error'] !== '') {
                $summary['error'] = $this->shortError($response['error']);
            }
            $sourceSummaries[] = $summary;
            if ($version !== null && $response['http_code'] === 200 && $response['error'] === '') {
                $valid[] = [
                    'version' => $version,
                    'url' => $url,
                    'latency_ms' => $response['latency_ms'],
                ];
            }
        }
        if ($valid === []) {
            return null;
        }

        usort($valid, static function (array $left, array $right): int {
            $versionOrder = version_compare($right['version'], $left['version']);
            return $versionOrder !== 0
                ? $versionOrder
                : ($left['latency_ms'] <=> $right['latency_ms']);
        });
        return [
            'version' => $valid[0]['version'],
            'url' => $valid[0]['url'],
            'sources' => $sourceSummaries,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function probeRepositories(): array
    {
        $urls = [];
        $repositoryByUrl = [];
        foreach ($this->imageRepositories as $repository) {
            if (!$this->validRepository($repository)) {
                continue;
            }
            $host = explode('/', $repository, 2)[0];
            $url = 'https://' . $host . '/v2/';
            $urls[] = $url;
            $repositoryByUrl[$url] = $repository;
        }
        $responses = $this->fetchMany($urls, true);
        $usable = [];
        foreach ($responses as $url => $response) {
            $repository = $repositoryByUrl[$url] ?? '';
            $code = (int)$response['http_code'];
            $reachable = $response['error'] === ''
                && $code >= 200
                && $code < 500;
            $summary = [
                'repository' => $repository,
                'http_code' => $code,
                'latency_ms' => $response['latency_ms'],
                'reachable' => $reachable,
            ];
            if ($response['error'] !== '') {
                $summary['error'] = $this->shortError($response['error']);
            }
            if ($reachable) {
                $usable[] = $summary;
            }
        }
        usort($usable, static fn(array $left, array $right): int => $left['latency_ms'] <=> $right['latency_ms']);
        return $usable;
    }

    /** @return array{repository:string,reference:string}|null */
    private function pullVerifiedImage(string $version, array $probes): ?array
    {
        foreach ($probes as $probe) {
            $repository = (string)($probe['repository'] ?? '');
            if (!$this->validRepository($repository)) {
                continue;
            }
            $reference = $repository . ':' . $version;
            $pull = $this->runDocker(['pull', $reference], $this->pullTimeout);
            if (!$pull['ok']) {
                $this->log('拉取 ' . $reference . ' 失败：' . $this->shortError($pull['stderr']));
                continue;
            }
            $label = $this->runDocker([
                'image', 'inspect', '--format', '{{index .Config.Labels "org.opencontainers.image.version"}}', $reference
            ], 30);
            $imageVersion = $this->normalizeVersion(trim($label['stdout']));
            if ($label['ok'] && $imageVersion === $version) {
                return [
                    'repository' => $repository,
                    'reference' => $reference,
                ];
            }
            $this->log('拒绝未匹配版本标签的镜像 ' . $reference);
        }
        return null;
    }

    private function restartApplication(): bool
    {
        $result = $this->compose([
            'up', '-d', '--no-build', '--no-deps', '--pull', 'never', 'app', 'scheduler'
        ], 240);
        if (!$result['ok']) {
            $this->log('应用容器重建失败：' . $this->shortError($result['stderr']));
            return false;
        }
        return $this->waitForHealthy(180);
    }

    private function waitForHealthy(int $timeout): bool
    {
        $deadline = microtime(true) + max(10, $timeout);
        while (microtime(true) < $deadline) {
            $idResult = $this->compose(['ps', '-q', 'app'], 20);
            $containerId = trim($idResult['stdout']);
            if ($idResult['ok'] && $containerId !== '') {
                $health = $this->runDocker([
                    'inspect', '--format', '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}', $containerId
                ], 20);
                $state = trim($health['stdout']);
                if ($state === 'healthy') {
                    return true;
                }
                if (in_array($state, ['unhealthy', 'dead', 'exited'], true)) {
                    return false;
                }
            }
            sleep(2);
        }
        return false;
    }

    private function rollback(?string $oldImageId): void
    {
        if ($oldImageId === null || $oldImageId === '') {
            return;
        }
        $tag = $this->runDocker(['tag', $oldImageId, $this->appImage], 30);
        if (!$tag['ok']) {
            $this->log('旧镜像重新标记失败：' . $this->shortError($tag['stderr']));
            return;
        }
        $restore = $this->compose([
            'up', '-d', '--no-build', '--no-deps', '--pull', 'never', 'app', 'scheduler'
        ], 240);
        if (!$restore['ok']) {
            $this->log('回滚容器失败：' . $this->shortError($restore['stderr']));
        }
    }

    private function currentVersion(): string
    {
        $container = $this->compose(['ps', '-q', 'app'], 20);
        $containerId = trim($container['stdout']);
        if ($container['ok'] && $containerId !== '') {
            $label = $this->runDocker([
                'inspect', '--format', '{{index .Config.Labels "org.opencontainers.image.version"}}', $containerId
            ], 20);
            $version = $this->normalizeVersion(trim($label['stdout']));
            if ($label['ok'] && $version !== null) {
                return $version;
            }
        }
        $versionFile = $this->projectDir . DIRECTORY_SEPARATOR . 'VERSION';
        if (is_file($versionFile)) {
            return $this->normalizeVersion((string)@file_get_contents($versionFile)) ?? '0.0.0';
        }
        return '0.0.0';
    }

    private function imageId(string $image): ?string
    {
        $result = $this->runDocker(['image', 'inspect', '--format', '{{.Id}}', $image], 20);
        $id = trim($result['stdout']);
        return $result['ok'] && $id !== '' ? $id : null;
    }

    private function runningImageId(string $service): ?string
    {
        $container = $this->compose(['ps', '-q', $service], 20);
        $containerId = trim($container['stdout']);
        if (!$container['ok'] || !preg_match('/^[a-f0-9]{12,64}$/', $containerId)) {
            return null;
        }
        $result = $this->runDocker(['inspect', '--format', '{{.Image}}', $containerId], 20);
        $imageId = trim($result['stdout']);
        return $result['ok'] && preg_match('/^sha256:[a-f0-9]{64}$/', $imageId) ? $imageId : null;
    }

    /** @return array<string,mixed> */
    private function runDocker(array $arguments, int $timeout): array
    {
        return $this->runCommand(array_merge(['docker'], $arguments), $timeout);
    }

    /** @return array<string,mixed> */
    private function compose(array $arguments, int $timeout): array
    {
        $prefix = ['docker', 'compose', '--project-directory', $this->projectDir];
        if (is_file($this->envFile)) {
            $prefix[] = '--env-file';
            $prefix[] = $this->envFile;
        }
        if (is_file($this->composeFile)) {
            $prefix[] = '--file';
            $prefix[] = $this->composeFile;
        }
        return $this->runCommand(array_merge($prefix, $arguments), $timeout);
    }

    /** @return array<string,mixed> */
    private function runCommand(array $arguments, int $timeout): array
    {
        if ($this->commandRunner !== null) {
            return ($this->commandRunner)($arguments, $timeout);
        }
        $command = implode(' ', array_map(static fn($argument): string => escapeshellarg((string)$argument), $arguments));
        $pipes = [];
        $process = @proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            return ['ok' => false, 'code' => 127, 'stdout' => '', 'stderr' => '无法启动命令'];
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $started = microtime(true);
        $timedOut = false;
        while (true) {
            $stdout .= (string)stream_get_contents($pipes[1]);
            $stderr .= (string)stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) - $started > max(1, $timeout)) {
                $timedOut = true;
                @proc_terminate($process, 9);
                break;
            }
            usleep(100000);
        }
        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closedCode = proc_close($process);
        if (!isset($status) || (int)($status['exitcode'] ?? -1) < 0) {
            $status = ['exitcode' => $closedCode];
        }
        $code = (int)($status['exitcode'] ?? $closedCode);
        if ($code < 0) {
            $code = $closedCode;
        }
        if ($timedOut) {
            $code = 124;
            $stderr .= '命令执行超时';
        }
        return [
            'ok' => $code === 0,
            'code' => $code,
            'stdout' => substr($stdout, -131072),
            'stderr' => substr($stderr, -131072),
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function fetchMany(array $urls, bool $head): array
    {
        $multi = curl_multi_init();
        $handles = [];
        foreach ($urls as $url) {
            $handle = curl_init($url);
            if ($handle === false) {
                continue;
            }
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => $this->probeTimeout,
                CURLOPT_TIMEOUT => $this->probeTimeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'LoopDeck-AutoUpdater/1',
                CURLOPT_HTTPHEADER => [
                    'Accept: application/vnd.github.raw+json, text/plain;q=0.9, */*;q=0.1',
                    'Cache-Control: no-cache',
                ],
            ]);
            if ($head) {
                curl_setopt($handle, CURLOPT_NOBODY, true);
            }
            curl_multi_add_handle($multi, $handle);
            $handles[(int)$handle] = [$handle, $url, microtime(true)];
        }

        $active = null;
        do {
            $status = curl_multi_exec($multi, $active);
        } while ($status === CURLM_CALL_MULTI_PERFORM);
        while ($active && $status === CURLM_OK) {
            if (curl_multi_select($multi, 1.0) === -1) {
                usleep(10000);
            }
            do {
                $status = curl_multi_exec($multi, $active);
            } while ($status === CURLM_CALL_MULTI_PERFORM);
        }

        $responses = [];
        foreach ($handles as [$handle, $url, $started]) {
            $error = curl_error($handle);
            $responses[$url] = [
                'body' => $head ? '' : (string)curl_multi_getcontent($handle),
                'http_code' => (int)curl_getinfo($handle, CURLINFO_HTTP_CODE),
                'latency_ms' => (int)round(((float)curl_getinfo($handle, CURLINFO_TOTAL_TIME)) * 1000),
                'error' => $error,
            ];
            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
        }
        curl_multi_close($multi);
        return $responses;
    }

    private function parseVersionBody(string $body): ?string
    {
        $body = trim($body, "\xEF\xBB\xBF \t\r\n");
        if ($body !== '' && $body[0] === '{') {
            $decoded = json_decode($body, true);
            if (is_array($decoded) && isset($decoded['content']) && is_string($decoded['content'])) {
                $decodedContent = base64_decode(preg_replace('/\s+/', '', $decoded['content']), true);
                if ($decodedContent !== false) {
                    $body = trim($decodedContent);
                }
            }
        }
        return $this->normalizeVersion($body);
    }

    private function normalizeVersion(string $value): ?string
    {
        $value = trim($value);
        if ($value !== '' && ($value[0] === 'v' || $value[0] === 'V')) {
            $value = substr($value, 1);
        }
        return preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $value) === 1 ? $value : null;
    }

    private function imageRepository(string $image): ?string
    {
        $image = preg_replace('/@sha256:[a-f0-9]{64}$/i', '', trim($image)) ?? '';
        if ($image === '') {
            return null;
        }
        $slash = strrpos($image, '/');
        $colon = strrpos($image, ':');
        if ($colon !== false && ($slash === false || $colon > $slash)) {
            $image = substr($image, 0, $colon);
        }
        return $this->validRepository($image) ? $image : null;
    }

    private function validRepository(string $repository): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*(?:\/[A-Za-z0-9][A-Za-z0-9._-]*)+$/', $repository) === 1;
    }

    private function hasDigestReference(string $image): bool
    {
        return preg_match('/@sha256:[a-f0-9]{64}$/i', trim($image)) === 1;
    }

    private function cacheBust(string $url): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';
        return $url . $separator . '_loopdeck=' . time();
    }

    private function safeUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        return strtolower((string)$parts['scheme']) . '://' . (string)$parts['host']
            . $port
            . (string)($parts['path'] ?? '');
    }

    private function writeState(array $state): void
    {
        $directory = dirname($this->stateFile);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }
        $state['schema'] = 1;
        $state['enabled'] = $this->enabled;
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($json)) {
            return;
        }
        $temporary = $this->stateFile . '.tmp.' . getmypid();
        if (@file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false) {
            return;
        }
        @chmod($temporary, 0644);
        @rename($temporary, $this->stateFile);
    }

    private function env(string $name, string $default): string
    {
        $value = getenv($name);
        return $value === false ? $default : (string)$value;
    }

    private function boolEnv(string $name, bool $default): bool
    {
        $value = strtolower(trim($this->env($name, $default ? 'true' : 'false')));
        return !in_array($value, ['0', 'false', 'no', 'off'], true);
    }

    private function intEnv(string $name, int $default, int $minimum, int $maximum): int
    {
        $value = filter_var($this->env($name, (string)$default), FILTER_VALIDATE_INT);
        if ($value === false) {
            return $default;
        }
        return max($minimum, min($maximum, (int)$value));
    }

    /** @return list<string> */
    private function csvEnv(string $name): array
    {
        $value = trim($this->env($name, ''));
        if ($value === '') {
            return [];
        }
        return $this->uniqueStrings(preg_split('/[,\r\n]+/', $value) ?: []);
    }

    /** @param array<int,mixed> $values */
    private function uniqueStrings(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value !== '' && !in_array($value, $result, true)) {
                $result[] = $value;
            }
        }
        return $result;
    }

    private function absolutePath(string $path): string
    {
        if ($path === '' || $path[0] === DIRECTORY_SEPARATOR) {
            return $path !== '' ? $path : DIRECTORY_SEPARATOR;
        }
        return getcwd() . DIRECTORY_SEPARATOR . $path;
    }

    private function shortError(string $error): string
    {
        $error = trim(preg_replace('/\s+/', ' ', $error) ?? '');
        return mb_substr($error, 0, 300);
    }

    private function log(string $message): void
    {
        fwrite(STDOUT, gmdate('c') . ' ' . $message . PHP_EOL);
    }
}

if (basename(__FILE__) === basename((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    $options = getopt('', ['once', 'help', 'complete-updater-restart']);
    if (isset($options['help'])) {
        fwrite(STDOUT, "LoopDeck automatic updater\n  --once  check once and exit\n");
        exit(0);
    }

    $updater = new LoopDeckAutoUpdater();
    if (isset($options['complete-updater-restart'])) {
        exit($updater->completeUpdaterRestart());
    }
    exit($updater->run(isset($options['once'])));
}
