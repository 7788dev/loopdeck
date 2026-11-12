<?php

declare(strict_types=1);

namespace app\service;

use anyrouter\Client;
use anyrouter\ProxyEndpoint;
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

    public function __construct(?ClientInterface $http = null, ?string $cacheFile = null)
    {
        $this->http = $http ?? new HttpClient();
        $this->cacheFile = $cacheFile ?? root_path() . 'runtime/anyrouter-proxies.json';
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
        $configured = trim((string)(getenv('ANYROUTER_PLATFORM_PROXY') ?: ''));
        if ($configured !== '') {
            // Only a deployment operator can opt into a LAN/local relay.
            $client = new Client(ProxyEndpoint::resolve($configured, false), $this->http);
            if (!$client->probeAsync(microtime(true) + 12)->wait()) {
                throw new RuntimeException('平台代理暂时不可用，请稍后重试');
            }
            return $client;
        }

        $directory = dirname($this->cacheFile);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('平台代理缓存暂不可用');
        }
        $lock = @fopen($this->cacheFile . '.lock', 'c+');
        if (!is_resource($lock)) {
            throw new RuntimeException('平台代理缓存暂不可用');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('平台代理正在刷新，请稍后重试');
        }
        try {
            $state = $this->readCache();
            $known = array_keys($state['verified']);
            $usable = $this->probe($known, microtime(true) + 9, $state);
            if ($usable !== null) {
                $this->writeCache($state);
                return $usable;
            }
            if ($state['updated_at'] < time() - 3600 || $state['candidates'] === []) {
                $candidates = $this->fetchCandidates();
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
                    $this->writeCache($state);
                    return $usable;
                }
            }
            $this->writeCache($state);
            throw new RuntimeException('暂未找到可用的平台代理，请稍后重试或提供代理');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
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

    private function fetchCandidates(): array
    {
        $all = [];
        foreach (['http' => 'http', 'socks5' => 'socks5h'] as $file => $scheme) {
            $path = 'monosans/proxy-list';
            $sources = [
                "https://cdn.jsdelivr.net/gh/{$path}@main/proxies/{$file}.txt",
                "https://raw.githubusercontent.com/{$path}/main/proxies/{$file}.txt",
                "https://ghfast.top/https://raw.githubusercontent.com/{$path}/main/proxies/{$file}.txt",
            ];
            foreach ($sources as $source) {
                try {
                    $response = $this->http->request('GET', $source, [
                        'proxy' => '', 'curl' => [CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*'],
                        'verify' => true, 'allow_redirects' => false, 'http_errors' => false,
                        'connect_timeout' => 2, 'timeout' => 4,
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

    private function readCache(): array
    {
        $raw = is_file($this->cacheFile) ? @file_get_contents($this->cacheFile, false, null, 0, 1048576) : false;
        $cache = is_string($raw) ? json_decode($raw, true) : null;
        $state = ['candidates' => [], 'verified' => [], 'updated_at' => 0, 'cursor' => 0];
        if (!is_array($cache)) {
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
