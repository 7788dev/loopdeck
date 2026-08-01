<?php

declare(strict_types=1);

namespace bilibili\sdk;

final class AppSigner
{
    public const FALLBACK_ANDROID_VERSION = '9.5.0';
    public const FALLBACK_ANDROID_BUILD = '9050300';

    private const PROFILES = [
        'legacy' => [
            'appkey' => '1d8b6e7d45233436',
            'secret' => '560c52ccd288fed045859ed18bffd973',
        ],
        'android' => [
            'appkey' => '783bbb7264451d82',
            'secret' => '2653583c8873dea268ab9386918b1d65',
        ],
    ];

    /** @return array<string,mixed> */
    public function sign(
        array $params,
        string $accessKey = '',
        string $profile = 'legacy',
        array $protocol = []
    ): array
    {
        $credentials = self::PROFILES[$profile] ?? self::PROFILES['legacy'];
        $metadata = $this->protocol($protocol);
        $defaults = [
            'actionKey' => 'appkey',
            'appkey' => $credentials['appkey'],
            'build' => $metadata['build'],
            'device' => 'phone',
            'mobi_app' => 'android',
            'platform' => 'android',
            'ts' => time(),
        ];
        if ($accessKey !== '') {
            $defaults['access_key'] = $accessKey;
        }
        return $this->applySignature(array_replace($params, $defaults), $credentials['secret']);
    }

    /** @return array<string,mixed> */
    public function signLogin(array $params, array $protocol = []): array
    {
        $credentials = self::PROFILES['android'];
        $metadata = $this->protocol($protocol);
        $defaults = [
            'appkey' => $credentials['appkey'],
            'build' => $metadata['build'],
            'c_locale' => 'zh_CN',
            'channel' => 'bili',
            'mobi_app' => 'android',
            'platform' => 'android',
            's_locale' => 'zh_CN',
            'statistics' => json_encode([
                'appId' => 1,
                'platform' => 3,
                'version' => $metadata['version'],
                'abtest' => '',
            ], JSON_UNESCAPED_SLASHES),
            'ts' => time(),
        ];
        return $this->applySignature(array_replace($params, $defaults), $credentials['secret']);
    }

    /** @return array{version:string,build:string} */
    public function protocol(array $protocol = []): array
    {
        return [
            'version' => $this->normalizeVersion($protocol['version'] ?? null),
            'build' => $this->normalizeBuild($protocol['build'] ?? null),
        ];
    }

    public function userAgent(array $protocol = []): string
    {
        $metadata = $this->protocol($protocol);
        return sprintf(
            'Mozilla/5.0 BiliDroid/%s (bbcallen@gmail.com) os/android model/Pixel 8 mobi_app/android build/%s channel/bili innerVer/%s osVer/15 network/2',
            $metadata['version'],
            $metadata['build'],
            $metadata['build']
        );
    }

    /** @return array<string,mixed> */
    private function applySignature(array $params, string $secret): array
    {
        unset($params['sign']);
        ksort($params, SORT_STRING);
        $params['sign'] = md5(http_build_query($params, '', '&', PHP_QUERY_RFC3986) . $secret);
        return $params;
    }

    private function normalizeVersion(mixed $version): string
    {
        $version = trim((string)$version);
        return preg_match('/^\d+\.\d+\.\d+$/', $version) === 1
            ? $version
            : self::FALLBACK_ANDROID_VERSION;
    }

    private function normalizeBuild(mixed $build): string
    {
        $build = trim((string)$build);
        return preg_match('/^\d{6,9}$/', $build) === 1
            ? $build
            : self::FALLBACK_ANDROID_BUILD;
    }
}
