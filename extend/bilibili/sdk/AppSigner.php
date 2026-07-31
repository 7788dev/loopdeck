<?php

declare(strict_types=1);

namespace bilibili\sdk;

final class AppSigner
{
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
    public function sign(array $params, string $accessKey = '', string $profile = 'legacy'): array
    {
        $credentials = self::PROFILES[$profile] ?? self::PROFILES['legacy'];
        $defaults = [
            'actionKey' => 'appkey',
            'appkey' => $credentials['appkey'],
            'build' => '6510400',
            'device' => 'phone',
            'mobi_app' => 'android',
            'platform' => 'android',
            'ts' => time(),
        ];
        if ($accessKey !== '') {
            $defaults['access_key'] = $accessKey;
        }
        $params = array_replace($params, $defaults);
        unset($params['sign']);
        ksort($params, SORT_STRING);
        $params['sign'] = md5(http_build_query($params, '', '&', PHP_QUERY_RFC3986) . $credentials['secret']);
        return $params;
    }
}
