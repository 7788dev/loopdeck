<?php

declare(strict_types=1);

namespace app\service;

use Throwable;

/**
 * View of the in-container automatic updater state, plus a request to check
 * earlier than scheduled.
 *
 * Updating is intentionally owned by docker/auto-updater.php. The web process
 * never invokes Docker: a check request is only an empty marker file next to
 * the shared state file, so a compromised admin session can at most move the
 * next check earlier. It cannot choose an image, a version source or a command.
 */
final class SystemUpdater
{
    private const DEFAULT_STATE_FILE = '/var/lib/loopdeck/runtime/auto-updater-state.json';
    private const DEFAULT_VERSION_SOURCE = 'https://api.github.com/repos/7788dev/loopdeck/contents/VERSION?ref=main';
    private const DEFAULT_INTERVAL_SECONDS = 21600;
    // docker/auto-updater.php derives the same marker path from the state file.
    private const CHECK_REQUEST_SUFFIX = '.check-request';
    // The updater polls every second and spaces checks at least 60 seconds apart.
    private const CHECK_REQUEST_STALE_SECONDS = 120;
    // Longer than the default worst case: every mirror pull timing out, then a restart.
    private const CHECKING_STALE_SECONDS = 7200;

    private const CLOCK_SKEW_TOLERANCE_SECONDS = 300;

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
            'check_started_at' => null,
            'trigger' => null,
            'phase' => null, 'phase_started_at' => null, 'updated_at' => null, 'finished_at' => null,
            'manual_check_not_before' => null, 'cooldown_seconds' => 0, 'elapsed_seconds' => 0,
            'heartbeat_age_seconds' => null, 'probe_completed' => 0, 'probe_total' => 0,
            'mirror_attempt' => 0, 'mirror_total' => 0,
            'manual_check_available' => false,
            'manual_check_hint' => $this->enabled ? '尚未检测到自动更新器，请确认 updater 容器正在运行' : '自动更新已禁用',
            'check_requested_at' => null,
            'check_request_stale' => false,
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
            'check_started_at',
            'trigger', 'phase', 'phase_started_at', 'updated_at', 'finished_at', 'manual_check_not_before',
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
        $startedAt = strtotime((string)($status['check_started_at'] ?? ''));
        $heartbeatAt = strtotime((string)($status['updated_at'] ?? ''));
        // A state timestamp ahead of this clock means the host clock moved
        // backwards; no elapsed measurement from it can prove the check is alive.
        $clockSkewed = ($startedAt !== false && $startedAt > time() + self::CLOCK_SKEW_TOLERANCE_SECONDS)
            || ($heartbeatAt !== false && $heartbeatAt > time() + self::CLOCK_SKEW_TOLERANCE_SECONDS);
        if ($status['status'] === 'checking') {
            $heartbeatAge = $heartbeatAt === false ? null : time() - $heartbeatAt;
            if ($clockSkewed
                || ($startedAt !== false && time() - $startedAt > self::CHECKING_STALE_SECONDS)
                || ($heartbeatAge !== null && $heartbeatAge > self::CHECKING_STALE_SECONDS)) {
                $status['status'] = 'failed';
                $status['message'] = '更新器检查长时间未结束';
                $status['error'] = $clockSkewed
                    ? '更新器状态的时间戳超前于系统时钟，请检查宿主机时间同步'
                    : '检查已超过 2 小时未完成，请查看 updater 容器日志';
            }
        }
        if ($status['status'] === 'failed' && $status['error'] === null) {
            $status['error'] = '自动更新器报告失败，请查看 updater 容器日志';
        }

        // Updaters older than this feature neither poll nor advertise the marker.
        $status['manual_check_available'] = $status['updater_available']
            && ($decoded['accepts_check_requests'] ?? false) === true;
        if ($status['manual_check_available']) {
            $status['manual_check_hint'] = null;
        } elseif ($status['updater_available']) {
            $status['manual_check_hint'] = '当前更新器尚未支持立即检查，updater 自动升级后即可使用，请稍后刷新或查看 updater 容器日志';
        } else {
            $status['manual_check_hint'] = '自动更新已禁用';
        }
        $requestFile = $this->checkRequestFile();
        $requestedAt = is_file($requestFile) ? @filemtime($requestFile) : false;
        if ($requestedAt !== false) {
            $status['check_requested_at'] = gmdate('c', $requestedAt);
            $status['check_request_stale'] = $status['status'] !== 'checking'
                && time() - $requestedAt > self::CHECK_REQUEST_STALE_SECONDS;
        }

        foreach (['probe_completed', 'probe_total', 'mirror_attempt', 'mirror_total'] as $field) {
            $status[$field] = max(0, min(1000, (int)($decoded[$field] ?? 0)));
        }
        $started = strtotime((string)($status['check_started_at'] ?? $status['checked_at'] ?? ''));
        $notBefore = strtotime((string)($status['manual_check_not_before'] ?? ''));
        $status['cooldown_seconds'] = max(0, ($notBefore ?: ($started ? $started + 60 : 0)) - time());
        $finished = strtotime((string)($status['finished_at'] ?? ''));
        $status['elapsed_seconds'] = $started ? max(0, ($finished ?: time()) - $started) : 0;
        $heartbeat = strtotime((string)($status['updated_at'] ?? ''));
        $status['heartbeat_age_seconds'] = $heartbeat ? max(0, time() - $heartbeat) : null;
        $checkedAt = strtotime((string)($status['checked_at'] ?? ''));
        $status['state_age_seconds'] = $checkedAt === false ? null : max(0, time() - $checkedAt);
        return $status;
    }

    /**
     * Ask the updater to run its next check now.
     *
     * @return array{accepted:bool,message:string}
     */
    public function requestCheck(): array
    {
        $status = $this->status();
        if (!$status['manual_check_available']) {
            return ['accepted' => false, 'message' => (string)$status['manual_check_hint']];
        }
        $pending = ['accepted' => true, 'message' => '已有立即检查请求在等待更新器处理'];
        if ($status['check_requested_at'] !== null) {
            return $pending;
        }

        // Exclusive creation queues exactly one request across concurrent clicks.
        $marker = @fopen($this->checkRequestFile(), 'x');
        if ($marker === false) {
            clearstatcache(true, $this->checkRequestFile());
            return is_file($this->checkRequestFile())
                ? $pending
                : ['accepted' => false, 'message' => '检查请求写入失败，请确认 app_data 卷可写'];
        }
        fclose($marker);
        return [
            'accepted' => true,
            'message' => $status['status'] === 'checking'
                ? '已提交检查请求，将在本次检查结束后执行'
                : '已通知更新器立即检查，通常几秒内开始',
        ];
    }

    private function checkRequestFile(): string
    {
        return $this->stateFile . self::CHECK_REQUEST_SUFFIX;
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
