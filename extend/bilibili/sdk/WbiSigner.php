<?php

declare(strict_types=1);

namespace bilibili\sdk;

final class WbiSigner
{
    private const MIXIN_KEY_ENC_TAB = [
        46, 47, 18, 2, 53, 8, 23, 32, 15, 50, 10, 31, 58, 3, 45, 35,
        27, 43, 5, 49, 33, 9, 42, 19, 29, 28, 14, 39, 12, 38, 41, 13,
        37, 48, 7, 16, 24, 55, 40, 61, 26, 17, 0, 1, 60, 51, 30, 4,
        22, 25, 54, 21, 56, 59, 6, 63, 57, 62, 11, 36, 20, 34, 44, 52,
    ];

    public function mixinKey(string $imgKey, string $subKey): string
    {
        $source = $imgKey . $subKey;
        $mixin = '';
        foreach (self::MIXIN_KEY_ENC_TAB as $index) {
            if (isset($source[$index])) {
                $mixin .= $source[$index];
            }
        }
        return substr($mixin, 0, 32);
    }

    /** @return array<string,mixed> */
    public function sign(array $params, string $imgKey, string $subKey, ?int $timestamp = null): array
    {
        unset($params['w_rid'], $params['wts']);
        $params['wts'] = $timestamp ?? time();
        foreach ($params as $name => $value) {
            $params[$name] = $this->sanitize($value);
        }

        $sorted = $params;
        ksort($sorted, SORT_STRING);
        $query = http_build_query($sorted, '', '&', PHP_QUERY_RFC3986);
        $params['w_rid'] = md5($query . $this->mixinKey($imgKey, $subKey));
        return $params;
    }

    private function sanitize(mixed $value): string
    {
        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } elseif ($value === null) {
            $value = '';
        } elseif (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }
        return str_replace(["!", "'", '(', ')', '*'], '', (string)$value);
    }
}
