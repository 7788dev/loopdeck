<?php

declare(strict_types=1);

namespace anyrouter;

use InvalidArgumentException;
use RuntimeException;

final class ProxyEndpoint
{
    public static function normalize(string $value, bool $publicOnly = true): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $value)) {
            throw new InvalidArgumentException('请填写有效的 HTTP 或 SOCKS5 代理地址');
        }
        $parts = parse_url($value);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'socks5', 'socks5h'], true)
            || empty($parts['host']) || empty($parts['port']) || $parts['port'] < 1 || $parts['port'] > 65535
            || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new InvalidArgumentException('代理格式应为 http://主机:端口 或 socks5://主机:端口');
        }
        $host = strtolower(trim($parts['host'], '[]'));
        if (!filter_var($host, FILTER_VALIDATE_IP)
            && preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/', $host) !== 1) {
            throw new InvalidArgumentException('代理主机格式无效');
        }
        if ($publicOnly && filter_var($host, FILTER_VALIDATE_IP) && !self::publicIp($host)) {
            throw new InvalidArgumentException('请填写服务器可访问的公网代理地址');
        }
        $credentials = '';
        if (isset($parts['user'])) {
            $user = rawurldecode($parts['user']);
            $password = rawurldecode($parts['pass'] ?? '');
            if ($user === '' || preg_match('/[\x00-\x1f\x7f]/', $user . $password)) {
                throw new InvalidArgumentException('代理认证信息格式无效');
            }
            $credentials = rawurlencode($user) . ':' . rawurlencode($password) . '@';
        }
        $scheme = strtolower($parts['scheme']) === 'http' ? 'http' : 'socks5h';
        return $scheme . '://' . $credentials . (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . $parts['port'];
    }

    /** Resolve once, validate every answer, then connect to that IP to prevent DNS rebinding. */
    public static function resolve(string $value, bool $publicOnly = true, ?\Closure $resolver = null): string
    {
        $value = self::normalize($value, $publicOnly);
        $parts = parse_url($value);
        $host = trim($parts['host'], '[]');
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $value;
        }
        $addresses = $resolver !== null ? $resolver($host) : array_merge(
            gethostbynamel($host) ?: [],
            array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6')
        );
        if ($addresses === []) {
            // A valid saved hostname can fail temporarily; keep it on the bounded network retry path.
            throw new RuntimeException('代理地址暂时无法解析，请稍后重试');
        }
        foreach ($addresses as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP) || ($publicOnly && !self::publicIp($ip))) {
                throw new InvalidArgumentException('请填写服务器可访问的公网代理地址');
            }
        }
        $ip = (string)reset($addresses);
        $replacement = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        return str_replace('://' . (isset($parts['user']) ? $parts['user'] . ':' . ($parts['pass'] ?? '') . '@' : '') . $parts['host'] . ':',
            '://' . (isset($parts['user']) ? $parts['user'] . ':' . ($parts['pass'] ?? '') . '@' : '') . $replacement . ':', $value);
    }

    public static function publicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            [$first, $second] = array_map('intval', explode('.', $ip));
            // PHP's NO_RES_RANGE omits shared address space (including cloud metadata),
            // benchmarking networks and multicast. None is a public relay destination.
            return $first !== 0 && $first < 224
                && !($first === 100 && $second >= 64 && $second <= 127)
                && !($first === 198 && in_array($second, [18, 19], true))
                && !($first === 192 && $second === 0);
        }
        $packed = inet_pton($ip);
        // Only global-unicast v6; excludes mapped IPv4, multicast, ULA and link-local.
        return $packed !== false && (ord($packed[0]) & 0xe0) === 0x20
            && !str_starts_with(strtolower($ip), '2001:db8:')
            && !str_starts_with(strtolower($ip), '2002:');
    }

    /** Only literal public endpoints from lists are eligible; list contents cannot choose destinations. */
    public static function parseList(string $text, string $scheme): array
    {
        $proxies = [];
        foreach (preg_split('/\R/', substr($text, 0, 524288)) ?: [] as $line) {
            $line = trim($line);
            if (preg_match('/\A(?:\d{1,3}\.){3}\d{1,3}:\d{1,5}\z/', $line) !== 1) {
                continue;
            }
            try {
                $proxy = self::normalize($scheme . '://' . $line);
                $proxies[$proxy] = $proxy;
            } catch (InvalidArgumentException $exception) {
                continue;
            }
        }
        return array_values($proxies);
    }
}
