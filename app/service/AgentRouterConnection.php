<?php

declare(strict_types=1);

namespace app\service;

use agentrouter\Client;
use anyrouter\ProxyEndpoint;
use Closure;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Prefer at most three previously successful nodes before scanning the operator's subscription. */
final class AgentRouterConnection
{
    public const CONNECTION_MESSAGES = [
        '平台代理正在使用，请稍后重试',
        '提供的代理暂时无法访问 AgentRouter，请检查后重试',
        'AgentRouter 订阅暂不可用，请站长检查后重试',
        '暂未找到可用的 AgentRouter 平台代理，请稍后重试或提供自有代理',
    ];

    private AgentRouterProxySettings $settings;
    private Closure $loader;
    private Closure $relayFactory;
    private Closure $clock;

    public function __construct(private readonly ?ClientInterface $http = null, ?AgentRouterProxySettings $settings = null,
        ?Closure $loader = null, ?Closure $relayFactory = null, ?Closure $clock = null)
    {
        $this->settings = $settings ?? new AgentRouterProxySettings();
        $this->loader = $loader ?? (new AgentRouterSubscription())->fetch(...);
        $this->relayFactory = $relayFactory ?? AgentRouterRelay::open(...);
        $this->clock = $clock ?? time(...);
    }

    public function connect(array $account): Client
    {
        $mode = $account['proxy_mode'] ?? 'platform';
        if (!in_array($mode, ['platform', 'custom'], true)) {
            throw new InvalidArgumentException('请选择平台代理或自有代理');
        }
        if ($mode === 'custom') {
            $proxy = ProxyEndpoint::resolve((string)($account['proxy_url'] ?? ''));
            $client = new Client($this->http, $proxy, (string)$this->settings->load()['origin']);
            if (!$client->probeAsync(microtime(true) + 12)->wait()) {
                throw new RuntimeException(self::CONNECTION_MESSAGES[1]);
            }
            return $client;
        }
        if (!TaskExecutionLock::acquire(AgentRouterProxySettings::LOCK)) {
            throw new RuntimeException(self::CONNECTION_MESSAGES[0]);
        }
        $leased = false;
        try {
            $settings = $this->settings->load();
            if ($settings['source'] === '' || !in_array($settings['kind'], ['proxy', 'subscription'], true)) {
                throw new InvalidArgumentException('站长尚未配置 AgentRouter 平台代理，请联系站长或提供自有代理');
            }
            $state = $this->settings->state(AgentRouterProxySettings::fingerprint($settings));
            $now = ($this->clock)();
            $deadline = microtime(true) + 55;
            $tested = [];
            $publicOnly = $settings['kind'] === 'subscription';
            foreach ($state['verified'] as $record) {
                $tested[$record['id']] = true;
                if ($record['cooldown_until'] > $now) {
                    continue;
                }
                $client = $this->candidate($record['node'], $settings, $state, $publicOnly, $deadline);
                if ($client !== null) {
                    $leased = true;
                    return $client;
                }
                self::failed($state, $record['id'], $now);
            }
            $this->settings->storeState($state);
            $nodes = $settings['kind'] === 'proxy' ? [AgentRouterSubscription::endpoint($settings['source'], false)]
                : ($this->loader)($settings['source']);
            if ($nodes === []) {
                throw new RuntimeException(self::CONNECTION_MESSAGES[2]);
            }
            $count = count($nodes);
            for ($attempt = 0; $attempt < min(8, $count) && microtime(true) < $deadline; $attempt++) {
                $node = AgentRouterSubscription::normalizeNode($nodes[$state['cursor'] % $count]);
                $state['cursor'] = ($state['cursor'] + 1) % $count;
                $id = AgentRouterSubscription::nodeId($node);
                if (isset($tested[$id])) {
                    continue;
                }
                $tested[$id] = true;
                $client = $this->candidate($node, $settings, $state, $publicOnly, $deadline);
                if ($client !== null) {
                    $leased = true;
                    return $client;
                }
            }
            $this->settings->storeState($state);
            throw new RuntimeException(self::CONNECTION_MESSAGES[3]);
        } finally {
            if (!$leased) {
                TaskExecutionLock::release(AgentRouterProxySettings::LOCK);
            }
        }
    }

    private function candidate(array $node, array $settings, array &$state, bool $publicOnly, float $deadline): ?Client
    {
        $relay = null;
        try {
            $relay = ($this->relayFactory)($node, $publicOnly);
            $client = new Client($this->http, $relay->url, (string)$settings['origin']);
            if (!$client->probeAsync(min($deadline, microtime(true) + 8))->wait()) {
                $relay->close();
                return null;
            }
            $this->settings->storeState($state);
            $snapshot = $state;
            $client->onCompletion(function (?array $result) use ($node, $relay, $snapshot): void {
                try {
                    $state = $snapshot;
                    if (!empty($result['success'])) {
                        self::succeeded($state, $node, ($this->clock)());
                    } elseif (!empty($result['proxy_failure']) && empty($result['account_invalid'])) {
                        self::failed($state, AgentRouterSubscription::nodeId($node), ($this->clock)());
                    }
                    $this->settings->storeState($state);
                } finally {
                    $relay->close();
                    TaskExecutionLock::release(AgentRouterProxySettings::LOCK);
                }
            });
            return $client;
        } catch (Throwable $exception) {
            $relay?->close();
            return null;
        }
    }

    private static function failed(array &$state, string $id, int $now): void
    {
        foreach ($state['verified'] as $index => &$record) {
            if ($record['id'] !== $id) {
                continue;
            }
            // Scheduler retries within one day must not count as another failed day.
            if ($record['last_failure_day'] !== date('Y-m-d', $now)) {
                $record['failures']++;
                $record['last_failure_day'] = date('Y-m-d', $now);
            }
            $record['cooldown_until'] = $now + 600;
            if ($record['failures'] >= 2) {
                unset($state['verified'][$index]);
            }
            break;
        }
        unset($record);
        $state['verified'] = array_values($state['verified']);
    }

    private static function succeeded(array &$state, array $node, int $now): void
    {
        $id = AgentRouterSubscription::nodeId($node);
        foreach ($state['verified'] as &$record) {
            if ($record['id'] === $id) {
                $record['last_success'] = $now;
                $record['failures'] = 0;
                $record['last_failure_day'] = '';
                $record['cooldown_until'] = 0;
                return;
            }
        }
        unset($record);
        if (count($state['verified']) >= 3) {
            array_pop($state['verified']);
        }
        $state['verified'][] = ['id' => $id, 'node' => $node, 'last_success' => $now,
            'failures' => 0, 'last_failure_day' => '', 'cooldown_until' => 0];
    }
}
