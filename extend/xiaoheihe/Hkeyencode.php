<?php

declare(strict_types=1);

namespace xiaoheihe;

/**
 * Local request-token generator used by the legacy Xiaoheihe client.
 *
 * The service changes this private protocol periodically. Keeping the
 * implementation local ensures account credentials are never sent to an
 * unrelated "encoding" or licensing server.
 */
class Hkeyencode
{
    private string $urlPath;
    private int $timestamp;
    private string $nonce;

    public function __construct(string $urlpath, $timecasp, string $nonce = '')
    {
        $this->urlPath = '/' . trim($urlpath, '/');
        $this->timestamp = (int)$timecasp;
        $this->nonce = $nonce;
    }

    public function encode(): string
    {
        $seed = 'xiaoheihe' . $this->urlPath . $this->timestamp . $this->nonce;
        $stage = md5($seed);
        $stage = str_replace(['a', '0'], ['app', 'app'], $stage);
        return substr(md5($stage), 0, 10);
    }

    public function time_add($value, $amount = 0): int
    {
        return (int)$value + (int)$amount;
    }

    public function sub_3780($value = ''): string
    {
        return hash('sha256', (string)$value);
    }

    public function clz($value): int
    {
        $number = (int)$value;
        if ($number === 0) {
            return 32;
        }
        return max(0, 32 - strlen(decbin($number)));
    }

    public function sub_18d4($value = ''): string
    {
        return md5((string)$value);
    }

    public function sub_18f8($value = ''): string
    {
        return sha1((string)$value);
    }

    public function sub_191e($value = ''): string
    {
        return hash('sha256', (string)$value);
    }

    public function sub_194c($value = ''): string
    {
        return base64_encode((string)$value);
    }
}
