<?php

declare(strict_types=1);

namespace app\service;

use anyrouter\ProxyEndpoint;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

/** Administrator sources are private; only editable list URLs and configured flags reach the form. */
final class AnyRouterProxySettings
{
    public const PREFIX = 'anyrouter_private.';
    public const SETTINGS_KEY = self::PREFIX . 'proxy';
    public const LOCK = 'anyrouter-platform-proxy';

    public static function defaults(): array
    {
        $sources = [];
        foreach (['http' => 'http', 'socks5h' => 'socks5'] as $scheme => $file) {
            $sources[$scheme] = [
                "https://cdn.jsdelivr.net/gh/monosans/proxy-list@main/proxies/{$file}.txt",
                "https://raw.githubusercontent.com/monosans/proxy-list/main/proxies/{$file}.txt",
                "https://ghfast.top/https://raw.githubusercontent.com/monosans/proxy-list/main/proxies/{$file}.txt",
            ];
        }
        return ['kind' => 'builtin', 'source' => '', 'sources' => $sources];
    }

    public function load(): array
    {
        $value = Db::name('configs')->where('k', self::SETTINGS_KEY)->value('v');
        $decoded = is_string($value) && strlen($value) <= 65535 ? json_decode($value, true) : null;
        if (is_array($decoded)) {
            return $decoded + self::defaults();
        }
        $defaults = self::defaults();
        // Preserve old deployments until their first administrator save, without a hidden override afterwards.
        $legacy = trim((string)(getenv('ANYROUTER_PLATFORM_PROXY') ?: ''));
        if ($legacy !== '') {
            $defaults['kind'] = 'proxy';
            $defaults['source'] = $legacy;
        }
        return $defaults;
    }

    public function save(array $input): void
    {
        foreach (['kind', 'source', 'http_sources', 'socks5_sources', 'clear'] as $key) {
            if (isset($input[$key]) && !is_scalar($input[$key])) {
                throw new InvalidArgumentException('AnyRouter 代理配置格式无效');
            }
        }
        // The shipped serializer emits clear= for an unchecked checkbox.
        $clear = $input['clear'] ?? '0';
        $clear = $clear === '' ? '0' : $clear;
        if (!in_array($clear, ['0', '1', 0, 1], true)) {
            throw new InvalidArgumentException('代理清除选项无效');
        }
        if (!TaskExecutionLock::acquire(self::LOCK)) {
            throw new RuntimeException('平台代理正在使用，请稍后再保存');
        }
        try {
            $old = $this->load();
            $kind = (string)$clear === '1' ? 'builtin' : (string)($input['kind'] ?? '');
            if (!in_array($kind, ['builtin', 'subscription', 'proxy'], true)) {
                throw new InvalidArgumentException('请选择内置代理池、订阅链接或常规代理');
            }
            $sources = $old['sources'];
            foreach (['http_sources' => 'http', 'socks5_sources' => 'socks5h'] as $field => $scheme) {
                if (array_key_exists($field, $input)) {
                    $sources[$scheme] = self::parseSources((string)$input[$field]);
                }
            }
            if ($sources['http'] === [] && $sources['socks5h'] === []) {
                throw new InvalidArgumentException('请至少保留一个代理池列表地址');
            }
            $source = '';
            if ($kind !== 'builtin') {
                $source = trim((string)($input['source'] ?? ''));
                if ($source === '' && $kind === $old['kind']) {
                    $source = (string)$old['source'];
                }
                $source = $kind === 'proxy' ? ProxyEndpoint::normalize($source, false)
                    : AgentRouterSubscription::normalizeUrl($source);
            }
            $next = ['kind' => $kind, 'source' => $source, 'sources' => $sources];
            $json = json_encode($next, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (strlen($json) > 65535) {
                throw new InvalidArgumentException('代理配置内容过长');
            }
            Db::transaction(static function () use ($json): void {
                if (Db::name('configs')->where('k', self::SETTINGS_KEY)->find()) {
                    Db::name('configs')->where('k', self::SETTINGS_KEY)->update(['v' => $json]);
                } else {
                    Db::name('configs')->insert(['k' => self::SETTINGS_KEY, 'v' => $json]);
                }
            });
        } finally {
            TaskExecutionLock::release(self::LOCK);
        }
    }

    public function present(): array
    {
        $settings = $this->load();
        $defaults = self::defaults();
        return ['kind' => $settings['kind'], 'configured' => $settings['source'] !== '',
            'http_sources' => implode("\n", $settings['sources']['http']),
            'socks5_sources' => implode("\n", $settings['sources']['socks5h']),
            'default_http_sources' => implode("\n", $defaults['sources']['http']),
            'default_socks5_sources' => implode("\n", $defaults['sources']['socks5h'])];
    }

    public static function fingerprint(array $settings): string
    {
        return hash('sha256', json_encode([$settings['kind'], $settings['kind'] === 'builtin'
            ? $settings['sources'] : $settings['source']], JSON_THROW_ON_ERROR));
    }

    private static function parseSources(string $input): array
    {
        if (strlen($input) > 16384) {
            throw new InvalidArgumentException('代理池列表地址内容过长');
        }
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $input) ?: []),
            static fn(string $line): bool => $line !== ''));
        if (count($lines) > 8) {
            throw new InvalidArgumentException('每种协议最多填写 8 个代理池列表地址');
        }
        $sources = [];
        foreach ($lines as $line) {
            try {
                $sources[] = AgentRouterSubscription::normalizeUrl($line);
            } catch (InvalidArgumentException $exception) {
                throw new InvalidArgumentException('请填写有效的公网 HTTP 或 HTTPS 代理池列表地址，每行一个');
            }
        }
        return array_values(array_unique($sources));
    }
}
