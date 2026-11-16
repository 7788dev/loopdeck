<?php

declare(strict_types=1);

namespace app\service;

use anyrouter\Client;
use anyrouter\ProxyEndpoint;
use Closure;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Pool;
use RuntimeException;
use Throwable;

/** Platform endpoints stay server-side. Account cookies are never sent during candidate probes. */
final class AnyRouterProxyPool
{
    private ClientInterface $http;
    private string $cacheFile;
    private Closure $settingsLoader;
    private Closure $subscriptionLoader;
    private Closure $relayFactory;

    public function __construct(?ClientInterface $http = null, ?string $cacheFile = null,
        ?Closure $settingsLoader = null, ?Closure $subscriptionLoader = null, ?Closure $relayFactory = null)
    {
        $this->http = $http ?? new HttpClient();
        $this->cacheFile = $cacheFile ?? root_path() . 'runtime/anyrouter-proxies.json';
        $this->settingsLoader = $settingsLoader ?? (new AnyRouterProxySettings())->load(...);
        $this->subscriptionLoader = $subscriptionLoader ?? (new AgentRouterSubscription($this->http))->fetch(...);
        $this->relayFactory = $relayFactory ?? AgentRouterRelay::open(...);
    }

    public function connect(array $account): Client
    {
        $mode = $account['proxy_mode'] ?? 'platform';
        if ($mode === 'custom') {
            $proxy = ProxyEndpoint::resolve((string)($account['proxy_url'] ?? ''));
            $client = new Client($proxy, $this->http);
            if (!$client->probeAsync(microtime(true) + 12)->wait()) {
                throw new RuntimeException('提供的代理暂时无法访问 AnyRouter，请检查代理后重试');
            }
            return $client;
        }
        if ($mode !== 'platform') {
            throw new \InvalidArgumentException('请选择平台代理或提供代理');
        }
        if (!TaskExecutionLock::acquire(AnyRouterProxySettings::LOCK)) {
            throw new RuntimeException('平台代理正在使用，请稍后重试');
        }
        $lock = null;
        $transferred = false;
        try {
            $settings = ($this->settingsLoader)();
            if ($settings['kind'] === 'proxy') {
                $client = $this->probeNode(AgentRouterSubscription::endpoint($settings['source'], false),
                    microtime(true) + 12, false);
                if ($client === null) {
                    throw new RuntimeException('平台代理暂时不可用，请站长检查后重试');
                }
            } elseif (in_array($settings['kind'], ['builtin', 'subscription'], true)) {
                $directory = dirname($this->cacheFile);
                if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
                    throw new RuntimeException('平台代理缓存暂不可用');
                }
                $lock = @fopen($this->cacheFile . '.lock', 'c+');
                if (!is_resource($lock)) {
                    throw new RuntimeException('平台代理缓存暂不可用');
                }
                if (!flock($lock, LOCK_EX | LOCK_NB)) {
                    throw new RuntimeException('平台代理正在刷新，请稍后重试');
                }
                $state = $this->readCache($settings);
                $client = $settings['kind'] === 'builtin' ? $this->connectBuiltin($settings['sources'], $state)
                    : $this->connectSubscription($settings['source'], $state);
                $this->writeCache($state);
            } else {
                throw new RuntimeException('平台代理配置无效，请站长重新保存');
            }
            $client->onClose(static function () use ($lock): void {
                if (is_resource($lock)) {
                    flock($lock, LOCK_UN);
                    fclose($lock);
                }
                TaskExecutionLock::release(AnyRouterProxySettings::LOCK);
            });
            $transferred = true;
            return $client;
        } finally {
            if (!$transferred) {
                if (is_resource($lock)) {
                    flock($lock, LOCK_UN);
                    fclose($lock);
                }
                TaskExecutionLock::release(AnyRouterProxySettings::LOCK);
            }
        }
    }

    private function connectBuiltin(array $sources, array &$state): Client
    {
        $known = array_keys($state['verified']);
        $usable = $this->probe($known, microtime(true) + 9, $state);
        if ($usable !== null) {
            return $usable;
        }
        if ($state['updated_at'] < time() - 3600 || $state['candidates'] === []) {
            $candidates = $this->fetchCandidates($sources);
            if ($candidates !== []) {
                shuffle($candidates);
                $state['candidates'] = $candidates;
                $state['cursor'] = 0;
                $state['updated_at'] = time();
            }
        }
        if ($state['candidates'] === []) {
            throw new RuntimeException('平台代理源暂不可用，请稍后重试或提供代理');
        }
        $deadline = microtime(true) + 25;
        $tested = 0;
        $total = count($state['candidates']);
        // Rotate the cursor so a failed run never retests only the first dead entries.
        while ($tested < min(160, $total) && microtime(true) < $deadline) {
            $batch = [];
            $batchSize = min(12, $total - $tested, 160 - $tested);
            for ($i = 0; $i < $batchSize; $i++) {
                $batch[] = $state['candidates'][$state['cursor'] % $total];
                $state['cursor'] = ($state['cursor'] + 1) % $total;
                $tested++;
            }
            $usable = $this->probe($batch, min($deadline, microtime(true) + 6), $state);
            if ($usable !== null) {
                return $usable;
            }
        }
        $this->writeCache($state);
        throw new RuntimeException('暂未找到可用的平台代理，请稍后重试或提供代理');
    }

    private function connectSubscription(string $source, array &$state): Client
    {
        $tried = [];
        $deadline = microtime(true) + 9;
        foreach ($state['nodes'] as $id => $record) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $tried[$id] = true;
            $client = $this->probeNode($record['node'], min($deadline, microtime(true) + 6));
            if ($client !== null) {
                $state['nodes'][$id]['at'] = time();
                return $client;
            }
            unset($state['nodes'][$id]);
        }
        try {
            $nodes = array_slice(($this->subscriptionLoader)($source), 0, AgentRouterSubscription::MAX_NODES);
        } catch (Throwable $exception) {
            $this->writeCache($state);
            throw new RuntimeException('平台代理订阅暂不可用，请站长检查后重试');
        }
        $total = count($nodes);
        $deadline = microtime(true) + 25;
        for ($tested = 0; $tested < min(40, $total) && microtime(true) < $deadline; $tested++) {
            $node = $nodes[$state['cursor'] % $total];
            $state['cursor'] = ($state['cursor'] + 1) % $total;
            try {
                $node = AgentRouterSubscription::normalizeNode($node);
                $id = AgentRouterSubscription::nodeId($node);
            } catch (Throwable $exception) {
                continue;
            }
            if (isset($tried[$id])) {
                continue;
            }
            $tried[$id] = true;
            $client = $this->probeNode($node, min($deadline, microtime(true) + 6));
            if ($client !== null) {
                $state['nodes'] = [$id => ['node' => $node, 'at' => time()]] + $state['nodes'];
                $state['nodes'] = array_slice($state['nodes'], 0, 3, true);
                return $client;
            }
        }
        $this->writeCache($state);
        throw new RuntimeException('暂未找到可用的平台代理，请站长检查订阅后重试或提供代理');
    }

    private function probeNode(array $node, float $deadline, bool $publicOnly = true): ?Client
    {
        $relay = null;
        $client = null;
        try {
            $relay = ($this->relayFactory)($node, $publicOnly);
            $client = new Client($relay->url, $this->http);
            $client->onClose($relay->close(...));
            if ($client->probeAsync($deadline)->wait()) {
                return $client;
            }
        } catch (Throwable $exception) {
            // A transport/engine error can contain subscription node credentials.
        }
        $client?->close();
        $relay?->close();
        return null;
    }

    private function probe(array $endpoints, float $deadline, array &$state): ?Client
    {
        $winner = null;
        $requests = function () use ($endpoints, $deadline, &$state, &$winner) {
            foreach ($endpoints as $endpoint) {
                yield function () use ($endpoint, $deadline, &$state, &$winner) {
                    $client = new Client($endpoint, $this->http);
                    return $client->probeAsync($deadline)->then(function (bool $valid) use ($client, $endpoint, &$state, &$winner): void {
                        if ($valid) {
                            $state['verified'][$endpoint] = time();
                            $winner ??= $client;
                        } else {
                            unset($state['verified'][$endpoint]);
                        }
                    });
                };
            }
        };
        (new Pool($this->http, $requests(), ['concurrency' => 12]))->promise()->wait();
        return $winner;
    }

    private function fetchCandidates(array $sources): array
    {
        $all = [];
        $deadline = microtime(true) + 16;
        foreach (['http', 'socks5h'] as $scheme) {
            foreach ($sources[$scheme] as $source) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    break 2;
                }
                try {
                    $source = AgentRouterSubscription::normalizeUrl($source);
                    $parts = parse_url($source);
                    $host = trim($parts['host'], '[]');
                    $port = (int)($parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80));
                    $fixed = ProxyEndpoint::resolve('http://' . $parts['host'] . ':' . $port);
                    $ip = trim(parse_url($fixed)['host'], '[]');
                    $response = $this->http->request('GET', $source, [
                        'proxy' => '', 'curl' => [CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
                            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)]],
                        'verify' => true, 'allow_redirects' => false, 'http_errors' => false,
                        'connect_timeout' => min(2.0, $remaining), 'timeout' => min(4.0, $remaining),
                        'progress' => static function ($total, $downloaded): void {
                            if ($total > 524288 || $downloaded > 524288) {
                                throw new RuntimeException('代理列表过大');
                            }
                        },
                    ]);
                    $text = (string)$response->getBody();
                    $parsed = $response->getStatusCode() === 200 && strlen($text) <= 524288
                        ? ProxyEndpoint::parseList($text, $scheme) : [];
                    if ($parsed !== []) {
                        $all = array_merge($all, $parsed);
                        break;
                    }
                } catch (Throwable $exception) {
                    continue;
                }
            }
        }
        return array_slice(array_values(array_unique($all)), 0, 5000);
    }

    private function readCache(array $settings): array
    {
        $raw = is_file($this->cacheFile) ? @file_get_contents($this->cacheFile, false, null, 0, 1048576) : false;
        $cache = is_string($raw) ? json_decode($raw, true) : null;
        $state = ['source_hash' => AnyRouterProxySettings::fingerprint($settings), 'candidates' => [],
            'verified' => [], 'nodes' => [], 'updated_at' => 0, 'cursor' => 0];
        if (!is_array($cache) || ($cache['source_hash'] ?? '') !== $state['source_hash']) {
            return $state;
        }
        foreach (array_slice((array)($cache['candidates'] ?? []), 0, 5000) as $endpoint) {
            if (is_string($endpoint) && $this->isPublicLiteral($endpoint)) {
                $state['candidates'][] = $endpoint;
            }
        }
        foreach (array_slice((array)($cache['verified'] ?? []), 0, 12, true) as $endpoint => $at) {
            if (is_string($endpoint) && $this->isPublicLiteral($endpoint)
                && is_int($at) && $at > time() - 3600 && $at <= time()) {
                $state['verified'][$endpoint] = $at;
            }
        }
        if ($settings['kind'] === 'subscription') {
            foreach (array_slice((array)($cache['nodes'] ?? []), 0, 3, true) as $record) {
                if (!is_array($record) || !is_array($record['node'] ?? null)
                    || !is_int($record['at'] ?? null) || $record['at'] < time() - 3600 || $record['at'] > time()) {
                    continue;
                }
                try {
                    $node = AgentRouterSubscription::normalizeNode($record['node']);
                    $state['nodes'][AgentRouterSubscription::nodeId($node)] = ['node' => $node, 'at' => $record['at']];
                } catch (Throwable $exception) {
                    continue;
                }
            }
        }
        $updatedAt = (int)($cache['updated_at'] ?? 0);
        $state['updated_at'] = $updatedAt <= time() ? $updatedAt : 0;
        $state['cursor'] = max(0, (int)($cache['cursor'] ?? 0));
        return $state;
    }

    private function isPublicLiteral(string $endpoint): bool
    {
        try {
            $parts = parse_url(ProxyEndpoint::normalize($endpoint));
            return !isset($parts['user']) && ProxyEndpoint::publicIp(trim($parts['host'], '[]'));
        } catch (\InvalidArgumentException $exception) {
            return false;
        }
    }

    private function writeCache(array $state): void
    {
        // Readers hold the same lock, so a truncated write is treated as an empty cache.
        if (@file_put_contents($this->cacheFile, json_encode($state, JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
            throw new RuntimeException('平台代理缓存保存失败，请稍后重试');
        }
        @chmod($this->cacheFile, 0600);
    }
}
