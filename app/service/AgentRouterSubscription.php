<?php

declare(strict_types=1);

namespace app\service;

use anyrouter\ProxyEndpoint;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/** Only node data is accepted; providers, rules, files and executable config are discarded. */
final class AgentRouterSubscription
{
    public const MAX_BYTES = 1048576;
    public const MAX_NODES = 200;

    public function __construct(private readonly ?ClientInterface $http = null)
    {
    }

    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if ($url === '' || strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            || !is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('请填写有效的 HTTP 或 HTTPS 订阅链接');
        }
        ProxyEndpoint::normalize('http://' . $parts['host'] . ':'
            . ($parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80)));
        return $url;
    }

    public function fetch(string $url): array
    {
        $url = self::normalizeUrl($url);
        $parts = parse_url($url);
        $port = (int)($parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80));
        $host = trim($parts['host'], '[]');
        // Keep the URL hostname for SNI/certificate verification, pin only its socket address.
        $fixed = ProxyEndpoint::resolve('http://' . $parts['host'] . ':' . $port);
        $ip = trim(parse_url($fixed)['host'], '[]');
        try {
            $response = ($this->http ?? new HttpClient())->request('GET', $url, [
                'headers' => ['User-Agent' => 'clash.meta', 'Accept' => 'application/yaml, application/json, text/plain'],
                'proxy' => '', 'curl' => [CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
                    CURLOPT_RESOLVE => [$host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)]],
                'verify' => true, 'allow_redirects' => false, 'http_errors' => false,
                'connect_timeout' => 3, 'timeout' => 12,
                'progress' => static function ($total, $downloaded): void {
                    if ($total > self::MAX_BYTES || $downloaded > self::MAX_BYTES) {
                        throw new RuntimeException('订阅响应过大');
                    }
                },
            ]);
            if ($response->getStatusCode() !== 200) {
                throw new RuntimeException('订阅请求失败');
            }
            return self::parse((string)$response->getBody());
        } catch (Throwable $exception) {
            // Transport errors may include the secret subscription URL.
            throw new RuntimeException('AgentRouter 订阅暂不可用，请站长检查后重试');
        }
    }

    public static function parse(string $text): array
    {
        if (strlen($text) > self::MAX_BYTES || trim($text) === '') {
            throw new InvalidArgumentException('订阅为空或超过 1 MB');
        }
        $text = trim($text);
        if (!str_contains($text, '://') && preg_match('/\A[A-Za-z0-9+\/_=\s-]+\z/', $text)) {
            $decoded = self::base64($text);
            if ($decoded !== null && str_contains($decoded, '://')) {
                $text = $decoded;
            }
        }
        $raw = [];
        if (preg_match('/\A(?:vmess|vless|trojan|ss|hysteria2|hy2|https?|socks5h?):\/\//i', $text)) {
            foreach (array_slice(preg_split('/\R/', $text) ?: [], 0, self::MAX_NODES) as $line) {
                try {
                    $node = self::uri(trim($line));
                    if ($node !== null) {
                        $raw[] = $node;
                    }
                } catch (Throwable $exception) {
                    continue;
                }
            }
        } else {
            try {
                $document = json_decode($text, true);
                $document ??= Yaml::parse($text, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
                $raw = is_array($document) && is_array($document['proxies'] ?? null)
                    ? array_slice($document['proxies'], 0, self::MAX_NODES) : [];
            } catch (Throwable $exception) {
                throw new InvalidArgumentException('订阅格式无效，请提供 Clash 配置或节点订阅');
            }
        }
        $nodes = [];
        foreach ($raw as $value) {
            if (!is_array($value)) {
                continue;
            }
            try {
                $node = self::normalizeNode($value);
                $nodes[self::nodeId($node)] = $node;
            } catch (InvalidArgumentException $exception) {
                continue;
            }
        }
        if ($nodes === []) {
            throw new InvalidArgumentException('订阅没有受支持的代理节点');
        }
        return array_values($nodes);
    }

    public static function endpoint(string $url, bool $publicOnly = true): array
    {
        $url = ProxyEndpoint::normalize($url, $publicOnly);
        return ['type' => 'endpoint', 'url' => $url,
            'name' => str_starts_with($url, 'http:') ? 'HTTP 代理' : 'SOCKS5 代理'];
    }

    public static function nodeId(array $node): string
    {
        unset($node['name']);
        $sort = static function (array $values) use (&$sort): array {
            if (!array_is_list($values)) {
                ksort($values);
            }
            foreach ($values as &$value) {
                if (is_array($value)) {
                    $value = $sort($value);
                }
            }
            return $values;
        };
        $node = $sort($node);
        return hash('sha256', json_encode($node, JSON_THROW_ON_ERROR));
    }

    public static function normalizeNode(array $value): array
    {
        if (strlen(json_encode($value, JSON_THROW_ON_ERROR)) > 8192) {
            throw new InvalidArgumentException('节点配置过长');
        }
        if (($value['type'] ?? '') === 'endpoint') {
            $node = self::endpoint((string)($value['url'] ?? ''), false);
            $node['name'] = self::label($value['name'] ?? $node['name']);
            return $node;
        }
        $type = strtolower((string)($value['type'] ?? ''));
        if (!in_array($type, ['vmess', 'vless', 'trojan', 'ss', 'hysteria2', 'http', 'socks5'], true)) {
            throw new InvalidArgumentException('节点协议暂不支持');
        }
        $server = (string)($value['server'] ?? '');
        $port = filter_var($value['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($port === false) {
            throw new InvalidArgumentException('节点端口无效');
        }
        $host = str_contains($server, ':') ? '[' . trim($server, '[]') . ']' : $server;
        ProxyEndpoint::normalize('http://' . $host . ':' . $port);
        if (in_array($type, ['http', 'socks5'], true)) {
            if (!empty($value['tls'])) {
                throw new InvalidArgumentException('HTTPS 代理暂不支持');
            }
            $auth = isset($value['username']) ? rawurlencode((string)$value['username']) . ':'
                . rawurlencode((string)($value['password'] ?? '')) . '@' : '';
            $node = self::endpoint(($type === 'http' ? 'http' : 'socks5h') . '://' . $auth . $host . ':' . $port);
            $node['name'] = self::label($value['name'] ?? $node['name']);
            return $node;
        }
        $node = ['name' => self::label($value['name'] ?? '代理节点'), 'type' => $type, 'server' => $server, 'port' => $port];
        foreach (['uuid', 'cipher', 'password', 'servername', 'sni', 'network', 'flow', 'client-fingerprint',
            'obfs', 'obfs-password', 'packet-encoding'] as $key) {
            if (isset($value[$key])) {
                if (!is_string($value[$key]) || strlen($value[$key]) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $value[$key])) {
                    throw new InvalidArgumentException('节点参数格式无效');
                }
                $node[$key] = $value[$key];
            }
        }
        if (in_array($type, ['vmess', 'vless'], true)
            && preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $node['uuid'] ?? '') !== 1) {
            throw new InvalidArgumentException('节点 UUID 无效');
        }
        if (in_array($type, ['ss', 'trojan', 'hysteria2'], true) && ($node['password'] ?? '') === '') {
            throw new InvalidArgumentException('节点认证信息缺失');
        }
        if ($type === 'ss' && ($node['cipher'] ?? '') === '') {
            throw new InvalidArgumentException('节点加密方式缺失');
        }
        if (isset($node['network']) && !in_array($node['network'], ['tcp', 'ws', 'grpc', 'h2', 'http'], true)) {
            throw new InvalidArgumentException('节点传输方式暂不支持');
        }
        foreach (['tls', 'udp'] as $key) {
            if (isset($value[$key])) {
                $node[$key] = filter_var($value[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }
        $node['skip-cert-verify'] = false;
        if ($type === 'vmess') {
            $node['alterId'] = max(0, min(65535, (int)($value['alterId'] ?? 0)));
            $node['cipher'] ??= 'auto';
        }
        $nestedKeys = ['ws-opts' => ['path', 'headers'], 'grpc-opts' => ['grpc-service-name'],
            'h2-opts' => ['host', 'path'], 'http-opts' => ['method', 'path', 'headers'],
            'reality-opts' => ['public-key', 'short-id']];
        foreach ($nestedKeys as $key => $allowed) {
            if (!is_array($value[$key] ?? null)) {
                continue;
            }
            $options = array_intersect_key($value[$key], array_flip($allowed));
            if (isset($options['headers'])) {
                $options['headers'] = is_array($options['headers'])
                    ? array_intersect_key($options['headers'], ['Host' => true]) : [];
            }
            if (self::safeOptions($options)) {
                $node[$key] = $options;
            }
        }
        if (is_array($value['alpn'] ?? null)) {
            $node['alpn'] = array_values(array_filter(array_slice($value['alpn'], 0, 8),
                static fn($entry): bool => is_string($entry) && preg_match('/\A[a-zA-Z0-9.\/-]{1,40}\z/', $entry) === 1));
        }
        return $node;
    }

    private static function safeOptions(array $values, int $depth = 0): bool
    {
        if ($depth > 3 || count($values) > 12) {
            return false;
        }
        foreach ($values as $value) {
            if (is_array($value)) {
                if (!self::safeOptions($value, $depth + 1)) {
                    return false;
                }
            } elseif (!is_string($value) || strlen($value) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                return false;
            }
        }
        return true;
    }

    private static function label($value): string
    {
        return is_string($value) ? mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]/', '', strip_tags($value)) ?? ''), 0, 80)
            : '代理节点';
    }

    private static function base64(string $value): ?string
    {
        $value = strtr(preg_replace('/\s+/', '', $value) ?? '', '-_', '+/');
        $decoded = base64_decode($value . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        return $decoded !== false && strlen($decoded) <= self::MAX_BYTES ? $decoded : null;
    }

    private static function uri(string $url): ?array
    {
        if (str_starts_with($url, 'vmess://')) {
            $value = json_decode(self::base64(explode('#', substr($url, 8))[0]) ?? '', true);
            if (!is_array($value)) {
                return null;
            }
            $node = ['type' => 'vmess', 'name' => $value['ps'] ?? 'VMess 节点', 'server' => $value['add'] ?? '',
                'port' => $value['port'] ?? 0, 'uuid' => $value['id'] ?? '', 'alterId' => $value['aid'] ?? 0,
                'cipher' => $value['scy'] ?? 'auto', 'network' => $value['net'] ?? 'tcp',
                'tls' => ($value['tls'] ?? '') === 'tls', 'servername' => $value['sni'] ?? $value['host'] ?? ''];
            if ($node['network'] === 'ws') {
                $node['ws-opts'] = ['path' => $value['path'] ?? '/', 'headers' => ['Host' => $value['host'] ?? '']];
            } elseif ($node['network'] === 'grpc') {
                $node['grpc-opts'] = ['grpc-service-name' => $value['path'] ?? ''];
            }
            return $node;
        }
        if (preg_match('/\A(?:http|socks5h?):\/\//', $url)) {
            return self::endpoint($url);
        }
        if (str_starts_with($url, 'ss://') && !str_contains(explode('#', substr($url, 5))[0], '@')) {
            $base = self::base64(explode('#', substr($url, 5))[0]);
            $url = $base !== null ? 'ss://' . $base : $url;
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        $type = ['hy2' => 'hysteria2', 'hysteria2' => 'hysteria2', 'vless' => 'vless', 'trojan' => 'trojan', 'ss' => 'ss'][$scheme] ?? null;
        if ($type === null) {
            return null;
        }
        parse_str($parts['query'] ?? '', $query);
        $node = ['type' => $type, 'name' => rawurldecode($parts['fragment'] ?? strtoupper($type) . ' 节点'),
            'server' => trim($parts['host'] ?? '', '[]'), 'port' => $parts['port'] ?? 443];
        if ($type === 'vless') {
            $node['uuid'] = rawurldecode($parts['user'] ?? '');
            $node['tls'] = in_array($query['security'] ?? '', ['tls', 'reality'], true);
            $node['servername'] = $query['sni'] ?? $query['host'] ?? '';
            $node['network'] = $query['type'] ?? 'tcp';
            if (($query['security'] ?? '') === 'reality') {
                $node['reality-opts'] = ['public-key' => $query['pbk'] ?? '', 'short-id' => $query['sid'] ?? ''];
                $node['client-fingerprint'] = $query['fp'] ?? 'chrome';
            }
            if (isset($query['flow'])) {
                $node['flow'] = $query['flow'];
            }
            if ($node['network'] === 'ws') {
                $node['ws-opts'] = ['path' => $query['path'] ?? '/', 'headers' => ['Host' => $query['host'] ?? '']];
            } elseif ($node['network'] === 'grpc') {
                $node['grpc-opts'] = ['grpc-service-name' => $query['serviceName'] ?? ''];
            }
        } elseif ($type === 'ss') {
            if (isset($query['plugin'])) {
                return null;
            }
            $auth = isset($parts['pass']) ? rawurldecode($parts['user'] . ':' . $parts['pass'])
                : self::base64(rawurldecode($parts['user'] ?? ''));
            [$node['cipher'], $node['password']] = array_pad(explode(':', $auth ?? '', 2), 2, '');
        } else {
            $node['password'] = rawurldecode($parts['user'] ?? '')
                . (isset($parts['pass']) ? ':' . rawurldecode($parts['pass']) : '');
            $node['sni'] = $query['sni'] ?? '';
            if ($type === 'hysteria2' && isset($query['obfs'])) {
                $node['obfs'] = $query['obfs'];
                $node['obfs-password'] = $query['obfs-password'] ?? '';
            }
        }
        return $node;
    }
}
