<?php

declare(strict_types=1);

namespace xiaoheihe;

class BlackBox
{
    public bool $cookiezt = false;

    private string $heyboxId;
    private string $pkey;
    private string $imei;

    public function __construct($heybox_id = '', $pkey = '')
    {
        if (is_array($heybox_id)) {
            $account = $heybox_id;
            $this->heyboxId = (string)($account['heybox_id'] ?? '');
            $this->pkey = (string)($account['pkey'] ?? '');
            $this->imei = (string)($account['imei'] ?? $this->generateDeviceId());
        } else {
            $this->heyboxId = (string)$heybox_id;
            $this->pkey = (string)$pkey;
            $this->imei = $this->generateDeviceId();
        }
    }

    public function login($phone, $password): array
    {
        return [
            'code' => 0,
            'message' => '为避免向第三方传输账号密码，已移除密码登录；请使用 heybox_id 与 pkey 导入。',
        ];
    }

    public function sign(): array
    {
        if ($this->heyboxId === '' || $this->pkey === '') {
            $this->cookiezt = true;
            return ['code' => 0, 'message' => '小黑盒登录凭据不完整'];
        }

        $path = '/task/sign';
        $timestamp = time();
        $nonce = $this->genNonceStr(32);
        $params = [
            'heybox_id' => $this->heyboxId,
            'imei' => $this->imei,
            'os_type' => 'Android',
            'os_version' => '12',
            'version' => '1.3.207',
            '_time' => $timestamp,
            'nonce' => $nonce,
            'hkey' => (new Hkeyencode($path, $timestamp, $nonce))->encode(),
            'channel' => 'heybox_yingyongbao',
        ];
        $response = $this->curl('GET', 'https://api.xiaoheihe.cn/task/sign/', $path, $params, 'pkey=' . $this->pkey);
        if (!is_array($response)) {
            return ['code' => 0, 'message' => '小黑盒接口请求失败'];
        }
        if (($response['status'] ?? '') === 'ok') {
            $result = $response['result'] ?? [];
            return [
                'code' => 1,
                'message' => sprintf(
                    '签到成功，连续 %s 天，获得 %s 盒币 / %s 经验',
                    $result['sign_in_streak'] ?? '-',
                    $result['sign_in_coin'] ?? '-',
                    $result['sign_in_exp'] ?? '-'
                ),
                'data' => $result,
            ];
        }
        $message = (string)($response['msg'] ?? '小黑盒签到失败，接口协议可能已更新');
        if (str_contains($message, '登录') || str_contains($message, 'pkey')) {
            $this->cookiezt = true;
        }
        return ['code' => 0, 'message' => $message, 'data' => $response];
    }

    public function genNonceStr(int $length = 32): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $value = '';
        while (strlen($value) < $length) {
            $value .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $value;
    }

    public function generateDeviceId(): string
    {
        return bin2hex(random_bytes(8));
    }

    public function rsa_encrypt(string $data, string $publicKey = ''): string
    {
        if ($publicKey === '') {
            return '';
        }
        $encrypted = '';
        if (!openssl_public_encrypt($data, $encrypted, $publicKey, OPENSSL_PKCS1_PADDING)) {
            return '';
        }
        return base64_encode($encrypted);
    }

    public function curl(
        string $method,
        string $url,
        string $urlpath = '',
        array $params = [],
        string $cookie = '',
        array $header = [],
        bool $json = true,
        bool $multipart = false
    ) {
        $method = strtoupper($method);
        if ($method === 'GET' && $params) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Linux; Android 12) OneTool/clean',
            CURLOPT_HTTPHEADER => array_merge([
                'Accept: application/json',
                'Referer: https://api.xiaoheihe.cn/',
            ], $header),
        ]);
        if ($cookie !== '') {
            curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        }
        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $params : http_build_query($params));
        }
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($body) || $status < 200 || $status >= 300) {
            return $json ? [] : '';
        }
        if (!$json) {
            return $body;
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }
}
