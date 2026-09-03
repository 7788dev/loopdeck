<?php

declare(strict_types=1);

namespace app\service;

use Throwable;

/**
 * Read-only view of the in-container automatic updater state.
 *
 * Updating is intentionally owned by docker/auto-updater.php. Keeping the
 * web process read-only removes the old privileged POST endpoint and makes a
 * compromised admin session unable to invoke Docker through the application.
 */
final class SystemUpdater
{
    private const DEFAULT_STATE_FILE = '/var/lib/loopdeck/runtime/auto-updater-state.json';
    private const DEFAULT_VERSION_SOURCE = 'https://api.github.com/repos/7788dev/loopdeck/contents/VERSION?ref=main';
    private const DEFAULT_INTERVAL_SECONDS = 21600;

    private string $stateFile;
    private bool $enabled;
    private int $checkInterval;

    /**
     * The first argument is kept intentionally loose for compatibility with
     * older integrations that passed an HTTP client. Such clients are ignored;
     * status is now read from the shared updater state file only.
     */
    public function __construct($stateFile = null, array $config = [])
    {
        if (is_array($stateFile) && $config === []) {
            $config = $stateFile;
            $stateFile = null;
        }

        $configuredPath = $config['state_file']
            ?? (is_string($stateFile) ? $stateFile : null)
            ?? getenv('AUTO_UPDATE_STATE_FILE')
            ?: self::DEFAULT_STATE_FILE;
        $this->stateFile = trim((string)$configuredPath) ?: self::DEFAULT_STATE_FILE;

        $configuredEnabled = array_key_exists('enabled', $config)
            ? $config['enabled']
            : (getenv('AUTO_UPDATE_ENABLED') === false ? null : getenv('AUTO_UPDATE_ENABLED'));
        if ($configuredEnabled === false) {
            $this->enabled = false;
        } elseif ($configuredEnabled === null || $configuredEnabled === '') {
            $this->enabled = true;
        } else {
            $this->enabled = !in_array(strtolower(trim((string)$configuredEnabled)), ['0', 'false', 'no', 'off'], true);
        }

        $interval = $config['check_interval_seconds']
            ?? getenv('UPDATE_CHECK_INTERVAL_SECONDS')
            ?: self::DEFAULT_INTERVAL_SECONDS;
        $interval = filter_var($interval, FILTER_VALIDATE_INT);
        $this->checkInterval = $interval === false
            ? self::DEFAULT_INTERVAL_SECONDS
            : max(60, min(604800, (int)$interval));
    }

    /**
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $current = ApplicationVersion::current();
        $status = [
            'current_version' => $current,
            'latest_version' => null,
            'update_available' => false,
            'updater_available' => $this->enabled,
            'auto_update_enabled' => $this->enabled,
            'status' => $this->enabled ? 'waiting' : 'disabled',
            'message' => $this->enabled ? '等待自动更新器首次检查' : '自动更新已禁用',
            'version_url' => $this->configuredVersionSource(),
            'version_source' => null,
            'version_sources' => [],
            'image_repository' => null,
            'image' => null,
            'image_probes' => [],
            'checked_at' => null,
            'last_checked_at' => null,
            'last_update_at' => null,
            'next_check_at' => null,
            'state_file' => $this->stateFile,
            'check_interval_seconds' => $this->checkInterval,
            'error' => null,
        ];

        if (!$this->enabled || !is_file($this->stateFile)) {
            return $status;
        }

        try {
            $decoded = json_decode((string)file_get_contents($this->stateFile), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            $status['status'] = 'failed';
            $status['message'] = '自动更新状态文件无法读取';
            $status['error'] = '状态文件格式无效';
            return $status;
        }
        if (!is_array($decoded)) {
            $status['status'] = 'failed';
            $status['message'] = '自动更新状态文件无法读取';
            $status['error'] = '状态文件格式无效';
            return $status;
        }

        $latest = ApplicationVersion::normalize((string)($decoded['latest_version'] ?? ''));
        if ($latest !== null) {
            $status['latest_version'] = $latest;
            $status['update_available'] = version_compare($latest, $current, '>');
        }

        foreach ([
            'status',
            'message',
            'version_source',
            'image_repository',
            'image',
            'checked_at',
            'last_update_at',
            'next_check_at',
        ] as $field) {
            if (isset($decoded[$field]) && is_scalar($decoded[$field])) {
                $status[$field] = mb_substr(trim((string)$decoded[$field]), 0, 500);
            }
        }
        if ($status['version_source'] !== null && $status['version_source'] !== '') {
            $status['version_url'] = $status['version_source'];
        }
        $status['last_checked_at'] = $status['checked_at'];

        foreach (['version_sources', 'image_probes'] as $field) {
            if (isset($decoded[$field]) && is_array($decoded[$field])) {
                $status[$field] = array_slice($decoded[$field], 0, 20);
            }
        }

        if (isset($decoded['enabled'])) {
            $status['updater_available'] = $this->enabled && (bool)$decoded['enabled'];
        }
        if (isset($decoded['error']) && is_scalar($decoded['error'])) {
            $status['error'] = mb_substr(trim((string)$decoded['error']), 0, 500);
        }
        if ($status['status'] === 'failed' && $status['error'] === null) {
            $status['error'] = '自动更新器报告失败，请查看 updater 容器日志';
        }

        $checkedAt = strtotime((string)($status['checked_at'] ?? ''));
        $status['state_age_seconds'] = $checkedAt === false ? null : max(0, time() - $checkedAt);
        return $status;
    }

    private function configuredVersionSource(): string
    {
        $sources = trim((string)(getenv('UPDATE_VERSION_SOURCES') ?: ''));
        if ($sources !== '') {
            $first = preg_split('/[,\r\n]+/', $sources)[0] ?? '';
            if (filter_var($first, FILTER_VALIDATE_URL) !== false) {
                return trim($first);
            }
        }
        return self::DEFAULT_VERSION_SOURCE;
    }
}
