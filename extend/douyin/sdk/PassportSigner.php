<?php

declare(strict_types=1);

namespace douyin\sdk;

use JsonException;
use RuntimeException;

final class PassportSigner
{
    public const APP_KEY = '163e7ce78d58971a41f5b969996d85c2';
    public const AID = '6383';
    public const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36';

    /** @return array{sign:string,qs:string} */
    public function sign(array $query, array $body = []): array
    {
        $queryPart = $this->serialize($query, 10);
        $bodyPart = $this->serialize($body);
        return [
            'sign' => hash('sha256', $queryPart['value'] . '&' . $bodyPart['value'] . '&app_key=' . self::APP_KEY),
            'qs' => $this->xorHex(implode(',', $queryPart['keys'])),
        ];
    }

    /** @return array<string,int|string> */
    public function baseQuery(
        string $pCa = '',
        ?int $nowMs = null,
        ?string $traceId = null,
        ?string $browserToken = null
    ): array {
        $nowMs ??= (int)round(microtime(true) * 1000);
        $traceId ??= bin2hex(random_bytes(4));
        if (!preg_match('/^[a-f0-9]{8}$/i', $traceId)) {
            throw new RuntimeException('trace id must contain exactly 8 hexadecimal characters');
        }

        $pNoValues = [
            'passport_jssdk_version' => '3.1.3',
            'p_bd' => '1.0.1.19-fix.01',
            'p_ca' => $pCa !== '' ? $pCa : '0',
            'p_ts' => $nowMs,
            'p_ver' => '1.1.3',
            'p_zt' => '3.3.14',
        ];

        return [
            'passport_jssdk_version' => '3.1.3',
            'passport_jssdk_type' => 'normal',
            'is_from_ttaccountsdk' => '1',
            'aid' => self::AID,
            'language' => 'zh',
            'account_app_language' => 'zh-CN',
            'ts' => $this->dailyTimestamp($nowMs),
            'account_sdk_source' => 'web',
            'account_sdk_source_info' => $this->accountSdkSourceInfo($nowMs, $browserToken),
            'p_js_v' => '3.1.3',
            'p_js_t' => 'pro',
            'p_zt' => '3.3.14',
            'p_ver' => '1.1.3',
            'p_ver_real' => '0',
            'request_host' => rawurlencode('https://www.douyin.com'),
            'p_bd' => '1.0.1.19-fix.01',
            'p_ts' => $nowMs,
            'p_no' => hash('sha256', $this->serialize($pNoValues)['value']),
            'biz_trace_id' => strtolower($traceId),
            'device_platform' => 'web_app',
        ];
    }

    public function dailyTimestamp(?int $nowMs = null): int
    {
        $seconds = intdiv($nowMs ?? (int)round(microtime(true) * 1000), 1000);
        return gmmktime(
            12,
            0,
            0,
            (int)gmdate('n', $seconds),
            (int)gmdate('j', $seconds),
            (int)gmdate('Y', $seconds)
        );
    }

    public function aidSign(string $path, int|string $timestamp): string
    {
        if (!str_starts_with($path, '/passport/')) {
            throw new RuntimeException('passport aid signature path is invalid');
        }
        $extract = hash_hmac('sha256', self::APP_KEY, (string)$timestamp, true);
        $derivedKey = hash_hmac('sha256', "\x01", $extract, true);
        return hash_hmac(
            'sha256',
            'aid=' . self::AID . '&path=' . $path . '&ts=' . $timestamp,
            $derivedKey
        );
    }

    public function randomMsToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(137)), '+/', '-_'), '=') . '=';
    }

    public function xorHex(string $value, int $key = 5): string
    {
        $encoded = '';
        for ($index = 0, $length = strlen($value); $index < $length; $index++) {
            $encoded .= dechex(ord($value[$index]) ^ $key);
        }
        return $encoded;
    }

    /** @return array{keys:array<int,string>,value:string} */
    private function serialize(array $values, int $limit = -1): array
    {
        $keys = array_map('strval', array_keys($values));
        sort($keys, SORT_STRING);
        if ($limit >= 0) {
            $keys = array_slice($keys, 0, $limit);
        }

        $parts = [];
        foreach ($keys as $key) {
            $parts[] = $key . '=' . $this->stringify($values[$key] ?? null);
        }
        return ['keys' => $keys, 'value' => implode('&', $parts)];
    }

    private function stringify(mixed $value): string
    {
        if (is_array($value) || is_object($value) || $value === null) {
            try {
                return json_encode(
                    $value,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            } catch (JsonException $exception) {
                throw new RuntimeException('passport signature contains invalid JSON', 0, $exception);
            }
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return (string)$value;
    }

    private function accountSdkSourceInfo(int $nowMs, ?string $browserToken): string
    {
        $browserToken ??= (string)hexdec(bin2hex(random_bytes(6)));
        $source = [
            'hardwareConcurrency' => 8,
            'webdriver' => false,
            'chromedriver' => false,
            'shelldriver' => false,
            'plugins' => 5,
            'innerHeight' => 947,
            'innerWidth' => 1920,
            'outerHeight' => 1040,
            'outerWidth' => 1920,
            'webgl' => [
                'vendor' => 'Google Inc. (NVIDIA)',
                'renderer' => 'ANGLE (NVIDIA, NVIDIA GeForce RTX, Direct3D11 vs_5_0 ps_5_0, D3D11)',
            ],
            'performance' => [
                'timeOrigin' => $nowMs - 1000,
                'usedJSHeapSize' => 33554432,
                'navigationTiming' => [
                    'decodedBodySize' => 0,
                    'entryType' => 'navigation',
                    'initiatorType' => 'navigation',
                    'name' => 'https://www.douyin.com/',
                    'renderBlockingStatus' => 'non-blocking',
                    'serverTiming' => '',
                    'guleStart' => 0,
                    'guleDuration' => 0,
                ],
            ],
            'browser' => [
                't' => $browserToken,
                'bit_protocol' => 'false',
                'bit_helper' => false,
            ],
        ];
        return $this->xorHex($this->stringify($source));
    }
}
