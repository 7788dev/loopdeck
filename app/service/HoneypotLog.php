<?php

declare(strict_types=1);

namespace app\service;

/** A bounded, best-effort sample store; never part of the blocking decision. */
final class HoneypotLog
{
    public const FILE_BYTES = 524288;
    public const RECORD_BYTES = 512;
    public const STATE_BYTES = 64;
    public const READ_BYTES = 1048576;
    public const SAMPLES_PER_SECOND = 5;
    public const RECENT_LIMIT = 50;
    public const TYPES = [
        'decoy_config' => '诱饵访问 · 配置',
        'decoy_backup' => '诱饵访问 · 备份',
        'sql_result' => '攻击拦截 · 假查询结果',
        'blocked' => '攻击拦截 · 拒绝请求',
    ];
    private const METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'OTHER'];
    private readonly string $directory;

    public function __construct(?string $directory = null)
    {
        // An in-process override lets isolated fixtures bind storage BEFORE the
        // firewall loads. No request parameter or header can choose this path.
        $this->directory = $directory ?? (defined('LOOPDECK_HONEYPOT_DIRECTORY')
            ? (string)constant('LOOPDECK_HONEYPOT_DIRECTORY') : dirname(__DIR__, 2) . '/runtime/honeypot');
    }

    public function record(string $type, string $entry, string $method, string $ip, ?int $now = null): bool
    {
        $lock = null;
        $file = null;
        try {
            if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
                return false;
            }
            foreach (['sample.lock', 'events.0.jsonl', 'events.1.jsonl'] as $name) {
                $candidate = $this->directory . '/' . $name;
                if (is_link($candidate) || (file_exists($candidate) && !is_file($candidate))) {
                    return false;
                }
            }
            $lock = @fopen($this->directory . '/sample.lock', 'c+b');
            if (!is_resource($lock) || !@flock($lock, LOCK_EX | LOCK_NB)) {
                return false;
            }
            // A worker can be delayed before taking the shared sampling lock.
            // Read its timestamp only after that delay, while holding the lock.
            $now ??= time();
            $event = ['at' => $now, 'type' => $type, 'method' => in_array($method, self::METHODS, true) ? $method : 'OTHER',
                'entry' => $entry, 'ip' => filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : ''];
            if (!self::validEvent($event)) {
                return false;
            }
            $line = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
            if (strlen($line) > self::RECORD_BYTES) {
                return false;
            }
            [$second, $count, $active] = self::state((string)@fread($lock, self::STATE_BYTES));
            // Stale timestamps or a clock rollback must not rewind the budget.
            if ($now < $second) {
                return false;
            }
            $count = $second === $now ? $count : 0;
            if ($count >= self::SAMPLES_PER_SECOND) {
                return false;
            }
            $path = $this->directory . '/events.' . $active . '.jsonl';
            clearstatcache(true, $path);
            $size = is_file($path) ? filesize($path) : 0;
            $mode = 'ab';
            if ($size > self::FILE_BYTES) {
                // Discard a damaged oversized file instead of copying it.
                $mode = 'wb';
            } elseif ($size + strlen($line) > self::FILE_BYTES - self::STATE_BYTES) {
                $active = 1 - $active;
                $path = $this->directory . '/events.' . $active . '.jsonl';
                $mode = 'wb';
            }
            // Reserve the sample before writing it, including failed writes.
            // A stable lock inode protects the two alternating files from all
            // FPM workers, without renaming files or waiting on another worker.
            rewind($lock);
            $state = str_pad(sprintf('%020d %d %d', $now, $count + 1, $active), self::STATE_BYTES - 1) . "\n";
            if (@fwrite($lock, $state) !== self::STATE_BYTES || !@ftruncate($lock, self::STATE_BYTES)) {
                return false;
            }
            $file = @fopen($path, $mode);
            return is_resource($file) && @fwrite($file, $line) === strlen($line);
        } catch (\Throwable) {
            return false;
        } finally {
            if (is_resource($file)) {
                @fclose($file);
            }
            if (is_resource($lock)) {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }
        }
    }

    public function snapshot(): array
    {
        $result = ['state' => 'ready', 'total' => 0, 'probes' => 0, 'blocked' => 0,
            'counts' => array_fill_keys(array_keys(self::TYPES), 0), 'recent' => [], 'bytes_read' => 0];
        if (!is_dir($this->directory)) {
            $result['state'] = file_exists($this->directory) ? 'unavailable' : 'empty';
            return $result;
        }
        $lock = null;
        try {
            if (is_link($this->directory . '/sample.lock') || !is_file($this->directory . '/sample.lock')) {
                $result['state'] = 'unavailable';
                return $result;
            }
            $lock = @fopen($this->directory . '/sample.lock', 'rb');
            if (!is_resource($lock)) {
                $result['state'] = 'unavailable';
                return $result;
            }
            if (!@flock($lock, LOCK_SH | LOCK_NB)) {
                $result['state'] = 'busy';
                return $result;
            }
            $state = (string)@fread($lock, self::STATE_BYTES);
            $result['bytes_read'] += strlen($state);
            [, , $active] = self::state($state);
            if (!preg_match('/\A\d{20} [0-5] [01] *\n\z/', $state)) {
                $result['state'] = 'partial';
            }
            foreach ([1 - $active, $active] as $index) {
                $path = $this->directory . '/events.' . $index . '.jsonl';
                if (is_link($path) || (file_exists($path) && !is_file($path))) {
                    $result['state'] = 'partial';
                    continue;
                }
                if (!file_exists($path)) {
                    continue;
                }
                $file = @fopen($path, 'rb');
                if (!is_resource($file)) {
                    $result['state'] = 'partial';
                    continue;
                }
                try {
                    $size = (int)(fstat($file)['size'] ?? 0);
                    $budget = min(self::FILE_BYTES, self::READ_BYTES - $result['bytes_read']);
                    if ($size > $budget) {
                        $result['state'] = 'partial';
                    }
                    $skip = false;
                    while ($budget > 0 && !feof($file)) {
                        $line = @fgets($file, min(self::RECORD_BYTES, $budget) + 1);
                        if ($line === false) {
                            break;
                        }
                        $bytes = strlen($line);
                        $budget -= $bytes;
                        $result['bytes_read'] += $bytes;
                        $complete = str_ends_with($line, "\n");
                        if ($skip || !$complete) {
                            $skip = !$complete;
                            $result['state'] = 'partial';
                            continue;
                        }
                        $event = json_decode($line, true, 8);
                        if (!is_array($event) || !self::validEvent($event)) {
                            $result['state'] = 'partial';
                            continue;
                        }
                        $result['total']++;
                        $result['counts'][$event['type']]++;
                        $result[str_starts_with($event['type'], 'decoy_') ? 'probes' : 'blocked']++;
                        $event['label'] = self::TYPES[$event['type']];
                        $event['time'] = date('Y-m-d H:i:s', $event['at']);
                        $result['recent'][] = $event;
                        if (count($result['recent']) > self::RECENT_LIMIT) {
                            array_shift($result['recent']);
                        }
                    }
                } finally {
                    @fclose($file);
                }
            }
            $result['recent'] = array_reverse($result['recent']);
            if ($result['total'] === 0 && $result['state'] === 'ready') {
                $result['state'] = 'empty';
            }
        } catch (\Throwable) {
            $result['state'] = 'unavailable';
        } finally {
            if (is_resource($lock)) {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }
        }
        return $result;
    }

    private static function state(string $state): array
    {
        return preg_match('/\A(\d{20}) ([0-5]) ([01]) *\n\z/', $state, $parts)
            ? [(int)$parts[1], (int)$parts[2], (int)$parts[3]] : [0, 0, 0];
    }

    private static function validEvent(array $event): bool
    {
        return count($event) === 5 && is_int($event['at'] ?? null) && $event['at'] > 0
            && is_string($event['type'] ?? null) && isset(self::TYPES[$event['type']])
            && in_array($event['method'] ?? null, self::METHODS, true)
            && is_string($event['entry'] ?? null) && strlen($event['entry']) <= 128
            && is_string($event['ip'] ?? null) && ($event['ip'] === '' || filter_var($event['ip'], FILTER_VALIDATE_IP) !== false);
    }
}
