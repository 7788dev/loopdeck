<?php

declare(strict_types=1);

namespace app\service;

use agentrouter\Client;
use anyrouter\ProxyEndpoint;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

/** Private database records, deliberately excluded from the shared sys configuration. */
final class AgentRouterProxySettings
{
    public const PREFIX = 'agentrouter_private.';
    public const SETTINGS_KEY = self::PREFIX . 'proxy';
    public const STATE_KEY = self::PREFIX . 'nodes';
    public const LOCK = 'agentrouter-platform-proxy';

    public function load(): array
    {
        return $this->read(self::SETTINGS_KEY) + ['kind' => '', 'source' => '', 'origin' => Client::ORIGIN];
    }

    public function save(array $input): void
    {
        foreach (['kind', 'source', 'origin', 'clear'] as $key) {
            if (isset($input[$key]) && !is_scalar($input[$key])) {
                throw new InvalidArgumentException('AgentRouter 代理配置格式无效');
            }
        }
        // The shipped jQuery serialize() submits an empty value for unchecked checkboxes.
        $clear = $input['clear'] ?? '0';
        if ($clear === '') {
            $clear = '0';
        }
        if (!in_array($clear, ['0', '1', 0, 1], true)) {
            throw new InvalidArgumentException('代理清除选项无效');
        }
        if (!TaskExecutionLock::acquire(self::LOCK)) {
            throw new RuntimeException('平台代理正在使用，请稍后再保存');
        }
        try {
            $old = $this->load();
            $origin = (string)($input['origin'] ?? $old['origin']);
            if (!in_array($origin, [Client::ORIGIN, 'https://agentrouter.org'], true)) {
                throw new InvalidArgumentException('请选择 AgentRouter 官方域名');
            }
            if ((string)$clear === '1') {
                $next = ['kind' => '', 'source' => '', 'origin' => $origin];
            } else {
                $kind = (string)($input['kind'] ?? '');
                if (!in_array($kind, ['proxy', 'subscription'], true)) {
                    throw new InvalidArgumentException('请选择常规代理或订阅链接');
                }
                $source = trim((string)($input['source'] ?? ''));
                if ($source === '' && $kind === $old['kind']) {
                    $source = (string)$old['source'];
                }
                $source = $kind === 'proxy' ? ProxyEndpoint::normalize($source, false)
                    : AgentRouterSubscription::normalizeUrl($source);
                $next = ['kind' => $kind, 'source' => $source, 'origin' => $origin];
            }
            Db::transaction(function () use ($old, $next): void {
                $this->write(self::SETTINGS_KEY, $next);
                if (self::fingerprint($old) !== self::fingerprint($next)) {
                    Db::name('configs')->where('k', self::STATE_KEY)->delete();
                }
            });
        } finally {
            TaskExecutionLock::release(self::LOCK);
        }
    }

    /** The only information about the pool passed to an administrator's template. */
    public function present(): array
    {
        $settings = $this->load();
        $state = $this->state(self::fingerprint($settings));
        $rows = [];
        foreach ($state['verified'] as $index => $record) {
            $rows[] = ['position' => $index + 1, 'name' => (string)($record['node']['name'] ?? '代理节点'),
                'last_success' => date('Y-m-d H:i:s', (int)$record['last_success']),
                'failures' => (int)$record['failures']];
        }
        return ['kind' => $settings['kind'] ?: 'subscription', 'configured' => $settings['source'] !== '',
            'origin' => $settings['origin'], 'origin_main' => $settings['origin'] === 'https://agentrouter.org', 'nodes' => $rows];
    }

    public static function fingerprint(array $settings): string
    {
        return hash('sha256', json_encode([$settings['kind'] ?? '', $settings['source'] ?? '',
            $settings['origin'] ?? Client::ORIGIN], JSON_THROW_ON_ERROR));
    }

    public function state(string $fingerprint): array
    {
        $empty = ['source_hash' => $fingerprint, 'verified' => [], 'cursor' => 0];
        $state = $this->read(self::STATE_KEY);
        if (($state['source_hash'] ?? '') !== $fingerprint) {
            return $empty;
        }
        $empty['cursor'] = max(0, (int)($state['cursor'] ?? 0));
        foreach (array_slice((array)($state['verified'] ?? []), 0, 3) as $record) {
            if (!is_array($record) || !is_array($record['node'] ?? null)) {
                continue;
            }
            try {
                $node = AgentRouterSubscription::normalizeNode($record['node']);
                $empty['verified'][] = ['id' => AgentRouterSubscription::nodeId($node), 'node' => $node,
                    'last_success' => max(0, (int)($record['last_success'] ?? 0)),
                    'failures' => min(1, max(0, (int)($record['failures'] ?? 0))),
                    'last_failure_day' => (string)($record['last_failure_day'] ?? ''),
                    'cooldown_until' => max(0, (int)($record['cooldown_until'] ?? 0))];
            } catch (InvalidArgumentException $exception) {
                continue;
            }
        }
        return $empty;
    }

    /** Caller holds LOCK throughout selection and the completed session. */
    public function storeState(array $state): void
    {
        $this->write(self::STATE_KEY, $state);
    }

    private function read(string $key): array
    {
        $value = Db::name('configs')->where('k', $key)->value('v');
        $decoded = is_string($value) && strlen($value) <= 65535 ? json_decode($value, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    private function write(string $key, array $value): void
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > 65535) {
            throw new RuntimeException('代理记录过长');
        }
        if (Db::name('configs')->where('k', $key)->find()) {
            Db::name('configs')->where('k', $key)->update(['v' => $json]);
        } else {
            Db::name('configs')->insert(['k' => $key, 'v' => $json]);
        }
    }
}
