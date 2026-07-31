<?php

namespace netease\sdk;

use RuntimeException;

final class Crypto
{
    private const IV = '0102030405060708';
    private const WEAPI_KEY = '0CoJUm6Qyw8W8jud';
    private const LINUXAPI_KEY = 'rFgB&h#%2?^eDg:Q';
    private const EAPI_KEY = 'e82ckenh8dichen8';
    private const BASE62 = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    private const XEAPI_STATIC_KEY_HEX = 'ab1d5a430f6bb04a3f01e81ddd72bd916d5ce591248ac128714806d7f8fb1b84';
    private const XEAPI_SIGN_KEY = 'mUHCwVNWJbunMqAHf5MImuirT6plvs6VSFW62MGHstFQxhBGdEoIhLItH3djc4+FB/OKty3+lL2rGeoFBpVe5g==';

    private const WEAPI_PUBLIC_KEY = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQDgtQn2JZ34ZC28NWYpAUd98iZ37BUrX/aKzmFbt7clFSs6sXqHauqKWqdtLkF2KexO40H1YTX8z2lSgBBOAxLsvaklV8k4cBFK9snQXE9/DDaFt6Rr7iVZMldczhC0JNgTz+SHXT6CBHuX3e9SdB1Ua44oncaTWz7OBGLbCiK45wIDAQAB
-----END PUBLIC KEY-----
PEM;

    /** @var callable */
    private $randomBytes;

    /** @var callable|null */
    private $randomString;

    public function __construct(?callable $randomBytes = null, ?callable $randomString = null)
    {
        $this->randomBytes = $randomBytes ?? static fn(int $length): string => random_bytes($length);
        $this->randomString = $randomString;
    }

    public function weapi(array $data, ?string $secretKey = null): array
    {
        $secretKey = $secretKey ?? $this->makeRandomString(16, self::BASE62);
        if (strlen($secretKey) !== 16) {
            throw new RuntimeException('The WEAPI secret key must be 16 bytes');
        }

        $first = $this->aesEncrypt($this->jsonEncode($data), 'aes-128-cbc', self::WEAPI_KEY, self::IV);
        $second = $this->aesEncrypt(base64_encode($first), 'aes-128-cbc', $secretKey, self::IV);
        $paddedSecret = str_pad(strrev($secretKey), 128, "\0", STR_PAD_LEFT);
        $encryptedSecret = '';
        if (!openssl_public_encrypt($paddedSecret, $encryptedSecret, self::WEAPI_PUBLIC_KEY, OPENSSL_NO_PADDING)) {
            throw new RuntimeException('Unable to encrypt the WEAPI secret key');
        }

        return [
            'params' => base64_encode($second),
            'encSecKey' => bin2hex($encryptedSecret),
        ];
    }

    public function linuxapi(array $data): array
    {
        $encrypted = $this->aesEncrypt($this->jsonEncode($data), 'aes-128-ecb', self::LINUXAPI_KEY);
        return ['eparams' => strtoupper(bin2hex($encrypted))];
    }

    public function eapi(string $uri, array $data): array
    {
        $text = $this->jsonEncode($data);
        $digest = md5('nobody' . $uri . 'use' . $text . 'md5forencrypt');
        $plain = $uri . '-36cd479b6b5-' . $text . '-36cd479b6b5-' . $digest;
        $encrypted = $this->aesEncrypt($plain, 'aes-128-ecb', self::EAPI_KEY);
        return ['params' => strtoupper(bin2hex($encrypted))];
    }

    public function decryptEapiResponse(string $body): array
    {
        $decrypted = $this->aesDecrypt($body, 'aes-128-ecb', self::EAPI_KEY);
        return $this->decodeJson($decrypted);
    }

    public function decryptEapiRequest(string $params): array
    {
        $binary = ctype_xdigit($params) ? hex2bin($params) : false;
        if ($binary === false) {
            throw new RuntimeException('Invalid EAPI hexadecimal payload');
        }
        $plain = $this->aesDecrypt($binary, 'aes-128-ecb', self::EAPI_KEY);
        if (!preg_match('/^(.*?)-36cd479b6b5-(.*?)-36cd479b6b5-([a-f0-9]{32})$/s', $plain, $match)) {
            throw new RuntimeException('Invalid EAPI payload');
        }
        return ['uri' => $match[1], 'data' => $this->decodeJson($match[2]), 'digest' => $match[3]];
    }

    public function xeapiSign(string $timestamp, string $nonce): string
    {
        return base64_encode(hash_hmac('sha256', $timestamp . $nonce, self::XEAPI_SIGN_KEY, true));
    }

    public function decryptXeapiPublicKey(string $encryptedData): array
    {
        $binary = base64_decode($encryptedData, true);
        if ($binary === false) {
            throw new RuntimeException('Invalid XEAPI public key payload');
        }
        return $this->decodeJson($this->aesDecrypt($binary, 'aes-256-ecb', $this->xeapiStaticKey()));
    }

    public function xeapi(
        string $uri,
        array $data,
        array $publicKeyState,
        array $options = [],
        string $sessionId = '',
        string $sessionKey = ''
    ): array {
        if (empty($publicKeyState['publicKey']) || empty($publicKeyState['version'])) {
            throw new RuntimeException('XEAPI public key state is incomplete');
        }

        $dynamicKey = $sessionKey !== '' ? $sessionKey : $this->bytes(16);
        if (!in_array(strlen($dynamicKey), [16, 24, 32], true)) {
            throw new RuntimeException('Invalid XEAPI session key length');
        }
        $plain = $this->buildXeapiPlaintext($uri, $data, $options);
        $inner = $this->aesEncrypt($plain, 'aes-256-ecb', $this->xeapiStaticKey());
        $middle = $this->xeapiMidTransform($inner);
        $b = $this->aesEncrypt($middle, $this->ecbCipherForKey($dynamicKey), $dynamicKey);
        $s = $this->xeapiEncryptSession($dynamicKey, $publicKeyState, (string)($options['os'] ?? 'android'));
        $rPlain = (string)$publicKeyState['version'] . '|' . ($sessionKey !== '' ? $sessionId : '');
        $r = $this->aesEncrypt($rPlain, 'aes-256-ecb', $this->xeapiStaticKey());

        return ['B' => base64_encode($b), 'S' => base64_encode($s), 'R' => base64_encode($r)];
    }

    public function decryptXeapiResponse(string $body): array
    {
        $plain = $this->aesDecrypt($body, 'aes-128-ecb', self::EAPI_KEY);
        if (substr($plain, 0, 2) === "\x1f\x8b") {
            $decoded = gzdecode($plain);
            if ($decoded === false) {
                throw new RuntimeException('Unable to decompress XEAPI response');
            }
            $plain = $decoded;
        }
        return $this->decodeJson($plain);
    }

    public function buildXeapiPlaintext(string $uri, array $data, array $options = []): string
    {
        $fields = [];
        $contentType = (string)($options['content_type'] ?? 'application/x-www-form-urlencoded;charset=utf-8');
        if (strtolower(strtok($contentType, ';')) !== 'application/x-www-form-urlencoded') {
            $fields['contentType'] = $contentType;
        }
        $method = strtoupper((string)($options['method'] ?? 'POST'));
        if ($method !== 'POST') {
            $fields['method'] = $method;
        }
        $query = (string)(parse_url($uri, PHP_URL_QUERY) ?? '');
        if ($query !== '') {
            $fields['queryString'] = $query;
        }
        unset($data['e_r']);
        $fields['body'] = base64_encode(self::formEncode($data));
        $fields['queryString'] = ($fields['queryString'] ?? '') !== ''
            ? $fields['queryString'] . '&e_r=true'
            : 'e_r=true';
        return $this->jsonEncode($fields);
    }

    public static function formEncode(array $data): string
    {
        $normalized = [];
        foreach ($data as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif ($value === null) {
                $value = 'null';
            } elseif (is_array($value)) {
                $value = implode(',', $value);
            }
            $normalized[(string)$key] = (string)$value;
        }
        return http_build_query($normalized, '', '&', PHP_QUERY_RFC1738);
    }

    private function xeapiEncryptSession(string $dynamicKey, array $state, string $os): string
    {
        $peer = base64_decode((string)$state['publicKey'], true);
        if ($peer === false || strlen($peer) !== SODIUM_CRYPTO_SCALARMULT_BYTES) {
            throw new RuntimeException('Invalid XEAPI X25519 public key');
        }
        $private = $this->bytes(SODIUM_CRYPTO_SCALARMULT_SCALARBYTES);
        $public = sodium_crypto_scalarmult_base($private);
        $shared = sodium_crypto_scalarmult($private, $peer);
        $zero = str_repeat("\0", 32);
        $prk = hash_hmac('sha256', $shared !== '' ? $shared : $zero, $zero, true);
        $aesKey = substr(hash_hmac('sha256', $public . "\x01", $prk, true), 0, 16);
        $iv = $this->bytes(12);
        $tag = '';
        $plain = base64_encode($dynamicKey) . '|' . $os . '|' . (string)($state['sk'] ?? '');
        $encrypted = openssl_encrypt($plain, 'aes-128-gcm', $aesKey, OPENSSL_RAW_DATA, $iv, $tag);
        if ($encrypted === false || strlen($tag) !== 16) {
            throw new RuntimeException('Unable to encrypt the XEAPI session');
        }
        return $public . $iv . $encrypted . $tag;
    }

    private function xeapiMidTransform(string $ciphertext): string
    {
        $mask = $this->bytes(16);
        $xored = '';
        $length = strlen($ciphertext);
        for ($i = 0; $i < $length; $i++) {
            $xored .= $ciphertext[$i] ^ $mask[$i & 15];
        }
        $base64 = base64_encode($xored);
        $rotation = strlen($base64) > 0 ? (ord($mask[0]) & 15) % strlen($base64) : 0;
        return $mask . substr($base64, $rotation) . substr($base64, 0, $rotation);
    }

    private function aesEncrypt(string $plain, string $cipher, string $key, string $iv = ''): string
    {
        $result = openssl_encrypt($plain, $cipher, $key, OPENSSL_RAW_DATA, $iv);
        if ($result === false) {
            throw new RuntimeException('AES encryption failed for ' . $cipher);
        }
        return $result;
    }

    private function aesDecrypt(string $ciphertext, string $cipher, string $key, string $iv = ''): string
    {
        $result = openssl_decrypt($ciphertext, $cipher, $key, OPENSSL_RAW_DATA, $iv);
        if ($result === false) {
            throw new RuntimeException('AES decryption failed for ' . $cipher);
        }
        return $result;
    }

    private function ecbCipherForKey(string $key): string
    {
        return 'aes-' . (strlen($key) * 8) . '-ecb';
    }

    private function xeapiStaticKey(): string
    {
        return hex2bin(self::XEAPI_STATIC_KEY_HEX) ?: '';
    }

    private function bytes(int $length): string
    {
        $bytes = ($this->randomBytes)($length);
        if (!is_string($bytes) || strlen($bytes) !== $length) {
            throw new RuntimeException('Random byte provider returned an invalid length');
        }
        return $bytes;
    }

    private function makeRandomString(int $length, string $alphabet): string
    {
        if ($this->randomString !== null) {
            $value = ($this->randomString)($length, $alphabet);
            if (!is_string($value) || strlen($value) !== $length) {
                throw new RuntimeException('Random string provider returned an invalid length');
            }
            return $value;
        }
        $value = '';
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < $length; $i++) {
            $value .= $alphabet[random_int(0, $max)];
        }
        return $value;
    }

    private function jsonEncode(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Unable to encode JSON: ' . json_last_error_msg());
        }
        return $json;
    }

    private function decodeJson(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new RuntimeException('Unable to decode protocol JSON: ' . json_last_error_msg());
        }
        return $data;
    }
}
