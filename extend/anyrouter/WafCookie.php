<?php

declare(strict_types=1);

namespace anyrouter;

/** The ACW v2 cookie challenge served by AnyRouter; never evaluate remote JavaScript. */
final class WafCookie
{
    private const ORDER = [15, 35, 29, 24, 33, 16, 1, 38, 10, 9, 19, 31, 40, 27, 22, 23, 25, 13, 6, 11,
        39, 18, 20, 8, 14, 21, 32, 26, 2, 30, 7, 4, 17, 5, 3, 28, 34, 37, 12, 36];
    private const MASK = '3000176000856006061501533003690027800375';

    public static function fromHtml(string $html): ?string
    {
        if (!str_contains($html, 'acw_sc__v2')
            || preg_match('/\barg1\s*=\s*[\'"]([a-fA-F0-9]{40})[\'"]/', $html, $match) !== 1) {
            return null;
        }
        $mixed = '';
        foreach (self::ORDER as $position) {
            $mixed .= $match[1][$position - 1];
        }
        return bin2hex(hex2bin($mixed) ^ hex2bin(self::MASK));
    }
}
