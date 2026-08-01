<?php

namespace netease\sdk;

use RuntimeException;
use Throwable;

final class Client
{
    public const UPSTREAM_VERSION = '4.39.0';
    public const UPSTREAM_COMMIT = '8f4873f2e2f677153d398a62d9ca0e3826c3f86d';

    private const PROFILES = [
        'pc' => [
            'os' => 'pc',
            'appver' => '3.1.17.204416',
            'osver' => 'Microsoft-Windows-10-Professional-build-19045-64bit',
            'channel' => 'netease',
        ],
        'linux' => [
            'os' => 'linux',
            'appver' => '1.2.1.0428',
            'osver' => 'Deepin 20.9',
            'channel' => 'netease',
        ],
        'android' => [
            'os' => 'android',
            'appver' => '8.20.20.231215173437',
            'osver' => '14',
            'channel' => 'xiaomi',
        ],
        'iphone' => [
            'os' => 'iPhone OS',
            'appver' => '9.0.90',
            'osver' => '16.2',
            'channel' => 'distribution',
        ],
        'osx' => [
            'os' => 'osx',
            'appver' => '3.1.10.5100',
            'osver' => '15.5',
            'channel' => 'netease',
        ],
    ];

    private const USER_AGENTS = [
        'weapi' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 Edg/124.0.0.0',
        'linuxapi' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/60.0.3112.90 Safari/537.36',
        'api_pc' => 'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Safari/537.36 Chrome/91.0.4472.164 NeteaseMusicDesktop/3.1.29.205117',
        'api_android' => 'NeteaseMusic/9.1.65.240927161425(9001065);Dalvik/2.1.0 (Linux; U; Android 14; 23013RK75C Build/UKQ1.230804.001)',
        'api_iphone' => 'NeteaseMusic 9.0.90/5038 (iPhone; iOS 16.2; zh_CN)',
        'api_osx' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    ];

    private static ?array $sharedXeapiPublicKey = null;
    private static string $sharedAntiCheatTokenV3 = '';

    private TransportInterface $transport;
    private Crypto $crypto;
    private array $config;
    private array $sessionCookies;
    private string $deviceId;
    private string $nuid;
    private string $wnmcid;
    private string $anonymousToken = '';
    private string $antiCheatTokenV3 = '';
    private ?array $xeapiPublicKey = null;
    private string $xeapiSessionId = '';
    private string $xeapiSessionKey = '';
    private bool $registeringAnonymous = false;

    public function __construct(
        array $session = [],
        array $config = [],
        ?TransportInterface $transport = null,
        ?Crypto $crypto = null
    ) {
        $this->config = array_replace([
            'domain' => 'https://music.163.com',
            'api_domain' => 'https://interface.music.163.com',
            'eapi_domain' => 'https://interfacepc.music.163.com',
            'xeapi_domain' => 'https://interface3.music.163.com',
            'timeout' => 30.0,
            'connect_timeout' => 15.0,
            'verify' => true,
            'auto_anonymous_token' => true,
            'cache_dir' => rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'netease-php-sdk',
            'proxy_url' => '',
            'proxy_order_no' => '',
            'proxy_secret' => '',
        ], $config);
        $this->transport = $transport ?? new GuzzleTransport();
        $this->crypto = $crypto ?? new Crypto();

        $csrf = (string)($session['csrf'] ?? $session['__csrf'] ?? '');
        $musicU = (string)($session['music_u'] ?? $session['musicu'] ?? $session['MUSIC_U'] ?? '');
        $this->sessionCookies = [];
        if ($csrf !== '') {
            $this->sessionCookies['__csrf'] = $csrf;
        }
        if ($musicU !== '') {
            $this->sessionCookies['MUSIC_U'] = $musicU;
        }
        if (!empty($session['cookie'])) {
            $this->sessionCookies = array_replace($this->sessionCookies, $this->parseCookie($session['cookie']));
        }

        $seed = $musicU !== '' ? $musicU : (string)($session['user_id'] ?? '');
        $configuredDeviceId = (string)($config['device_id'] ?? '');
        $cachedDeviceId = $configuredDeviceId === '' && $seed === ''
            ? (string)$this->readCache('device_id.txt', false)
            : '';
        if ($configuredDeviceId !== '') {
            $this->deviceId = $configuredDeviceId;
        } elseif ($seed !== '') {
            $this->deviceId = substr(strtoupper(hash('sha256', $seed)), 0, 52);
        } elseif (preg_match('/^[A-F0-9]{52}$/', $cachedDeviceId)) {
            $this->deviceId = $cachedDeviceId;
        } else {
            $this->deviceId = $this->randomHex(52);
            $this->writeCache('device_id.txt', $this->deviceId);
        }
        $this->nuid = (string)($config['nuid'] ?? strtolower($this->randomHex(64)));
        $this->wnmcid = (string)($config['wnmcid'] ?? ($this->randomLowercase(6) . '.' . (string)round(microtime(true) * 1000) . '.01.0'));
        $this->anonymousToken = (string)($config['anonymous_token'] ?? '');
        $this->antiCheatTokenV3 = (string)($config['anti_cheat_token_v3'] ?? '');
        $this->xeapiPublicKey = isset($config['xeapi_public_key']) && is_array($config['xeapi_public_key'])
            ? $config['xeapi_public_key']
            : null;
    }

    /**
     * Return the legacy response shape used by the existing project.
     *
     * @return array{header:string,body:string,status:int,set_cookie:array<int,string>}
     */
    public function request(string $uri, array $data = [], string $crypto = 'eapi', array $options = []): array
    {
        try {
            $prepared = $this->prepare($uri, $data, $crypto, $options);
            $response = $this->transport->request('POST', $prepared['url'], $prepared['options']);
            $this->captureXeapiSession($response['headers'] ?? []);
            $body = (string)($response['body'] ?? '');

            if ($prepared['crypto'] === 'xeapi') {
                $body = $this->jsonEncode($this->crypto->decryptXeapiResponse($body));
            } elseif ($prepared['encrypted_response']) {
                $body = $this->jsonEncode($this->crypto->decryptEapiResponse($body));
            }

            return [
                'header' => (string)($response['header'] ?? ''),
                'body' => $body,
                'status' => (int)($response['status'] ?? 0),
                'set_cookie' => is_array($response['set_cookie'] ?? null) ? $response['set_cookie'] : [],
            ];
        } catch (Throwable $exception) {
            return $this->errorResponse($exception->getMessage());
        }
    }

    /**
     * Build a request without sending it. This is useful for SDK consumers and protocol tests.
     *
     * @return array{url:string,crypto:string,encrypted_response:bool,options:array}
     */
    public function prepare(string $uri, array $data = [], string $crypto = 'eapi', array $options = []): array
    {
        $crypto = strtolower($crypto);
        if (!in_array($crypto, ['api', 'weapi', 'eapi', 'linuxapi', 'xeapi'], true)) {
            throw new RuntimeException('Unsupported NetEase crypto mode: ' . $crypto);
        }
        if ($uri === '' || $uri[0] !== '/') {
            throw new RuntimeException('NetEase API URI must start with /');
        }

        $data['e_r'] = $this->toBoolean($options['e_r'] ?? $data['e_r'] ?? false);
        $osKey = $this->resolveOsKey((string)($options['os'] ?? ''), $crypto);
        $cookies = $this->completeCookies($options['cookie'] ?? $this->sessionCookies, $osKey, $uri, !empty($options['skip_anonymous']));
        $headers = is_array($options['headers'] ?? null) ? $options['headers'] : [];
        $domain = rtrim((string)($options['domain'] ?? ''), '/');
        $form = [];
        $url = '';

        if ($crypto === 'weapi') {
            $headers['Referer'] = $domain !== '' ? $domain : $this->config['domain'];
            $headers['User-Agent'] = (string)($options['user_agent'] ?? self::USER_AGENTS['weapi']);
            $headers['Cookie'] = $this->cookieString($cookies, true);
            $data['csrf_token'] = (string)($cookies['__csrf'] ?? '');
            $form = $this->crypto->weapi($data, $options['weapi_secret'] ?? null);
            $url = ($domain !== '' ? $domain : $this->config['domain']) . '/weapi/' . substr($uri, 5);
        } elseif ($crypto === 'linuxapi') {
            $headers['User-Agent'] = (string)($options['user_agent'] ?? self::USER_AGENTS['linuxapi']);
            $headers['Cookie'] = $this->cookieString($cookies, true);
            $form = $this->crypto->linuxapi([
                'method' => 'POST',
                'url' => ($domain !== '' ? $domain : $this->config['domain']) . $uri,
                'params' => $data,
            ]);
            $url = ($domain !== '' ? $domain : $this->config['domain']) . '/api/linux/forward';
        } elseif ($crypto === 'xeapi') {
            $state = $this->ensureXeapiPublicKey();
            $profile = self::PROFILES['android'];
            $appver = ($cookies['os'] ?? '') === 'android' && !empty($cookies['appver'])
                ? (string)$cookies['appver']
                : '9.1.65';
            $osver = ($cookies['os'] ?? '') === 'android' && !empty($cookies['osver'])
                ? (string)$cookies['osver']
                : '16';
            $buildver = (string)($cookies['buildver'] ?? substr((string)time(), 0, 10));
            $cookies = array_replace($cookies, $profile, [
                'appver' => $appver,
                'osver' => $osver,
                'buildver' => $buildver,
                'deviceId' => $this->deviceId,
                'sDeviceId' => (string)($cookies['sDeviceId'] ?? $this->deviceId),
            ]);
            $headers = array_replace($headers, [
                'User-Agent' => (string)($options['user_agent'] ?? self::USER_AGENTS['api_android']),
                'X-Client-Enc-State' => 'ENCRYPTED',
                'x-aeapi' => 'true',
                'Content-Type' => 'application/x-www-form-urlencoded;charset=utf-8',
                'x-deviceid' => $this->deviceId,
                'x-os' => 'android',
                'x-osver' => $osver,
                'x-appver' => $appver,
                'x-sdeviceid' => (string)$cookies['sDeviceId'],
                'x-buildver' => $buildver,
                'Cookie' => $this->cookieString($cookies, true),
            ]);
            if (!empty($cookies['MUSIC_U'])) {
                $headers['x-music-u'] = (string)$cookies['MUSIC_U'];
            }
            if (!empty($options['check_token'])) {
                $headers['X-antiCheatToken'] = $this->ensureAntiCheatTokenV3();
            }
            $form = $this->crypto->xeapi($uri, $data, $state, [
                'os' => 'android',
                'method' => 'POST',
                'content_type' => 'application/x-www-form-urlencoded;charset=utf-8',
            ], $this->xeapiSessionId, $this->xeapiSessionKey);
            $url = ($domain !== '' ? $domain : $this->config['xeapi_domain']) . '/xeapi/' . substr($uri, 5);
        } else {
            $header = $this->eapiHeader($cookies);
            $headers['Cookie'] = $this->cookieString($header, true);
            $headers['User-Agent'] = (string)($options['user_agent'] ?? (($cookies['os'] ?? '') === 'osx'
                ? self::USER_AGENTS['api_osx']
                : self::USER_AGENTS['api_iphone']));
            if ($crypto === 'eapi') {
                $data['header'] = $header;
                $form = $this->crypto->eapi($uri, $data);
                $url = ($domain !== '' ? $domain : $this->config['eapi_domain']) . '/eapi/' . substr($uri, 5);
            } else {
                $form = $data;
                $url = ($domain !== '' ? $domain : $this->config['api_domain']) . $uri;
            }
        }

        if (!empty($options['check_token']) && $crypto !== 'xeapi') {
            $headers['X-antiCheatToken'] = $this->ensureAntiCheatTokenV3();
        }

        $this->addProxyAuthorization($headers, $options);
        $requestOptions = $this->baseOptions($options);
        $requestOptions['headers'] = $headers;
        if (!$this->hasHeader($headers, 'Content-Type')) {
            $requestOptions['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
        }
        $requestOptions['body'] = Crypto::formEncode($form);

        return [
            'url' => $url,
            'crypto' => $crypto,
            'encrypted_response' => in_array($crypto, ['eapi', 'weapi'], true) && $data['e_r'] === true,
            'options' => $requestOptions,
        ];
    }

    /**
     * Send a non-protocol request through the same transport and proxy configuration.
     *
     * @return array{header:string,body:string,status:int,set_cookie:array<int,string>}
     */
    public function rawRequest(string $method, string $url, array $options = []): array
    {
        try {
            $headers = [
                'User-Agent' => (string)($options['user_agent'] ?? self::USER_AGENTS['weapi']),
                'Referer' => (string)($options['referer'] ?? $this->config['domain'] . '/'),
                'Accept' => '*/*',
            ];
            if (!empty($options['headers']) && is_array($options['headers'])) {
                $headers = array_replace($headers, $options['headers']);
            }
            $cookieInput = array_key_exists('cookie', $options) && $options['cookie'] !== null
                ? $options['cookie']
                : $this->sessionCookies;
            if ($cookieInput !== '') {
                $cookies = $this->completeCookies($cookieInput, $this->resolveOsKey((string)($options['os'] ?? ''), 'api'), $url);
                $headers['Cookie'] = $this->cookieString($cookies, true);
            }

            $this->addProxyAuthorization($headers, $options);
            $requestOptions = $this->baseOptions($options);
            $requestOptions['headers'] = $headers;
            foreach (['body', 'json', 'multipart'] as $key) {
                if (array_key_exists($key, $options)) {
                    $requestOptions[$key] = $options[$key];
                }
            }
            if (!array_key_exists('body', $requestOptions) && !array_key_exists('json', $requestOptions) && !array_key_exists('multipart', $requestOptions)) {
                if (strtoupper($method) === 'GET') {
                    if (!empty($options['params'])) {
                        $requestOptions['query'] = $options['params'];
                    }
                } else {
                    $requestOptions['form_params'] = $options['params'] ?? [];
                }
            }
            $response = $this->transport->request($method, $url, $requestOptions);
            return [
                'header' => (string)($response['header'] ?? ''),
                'body' => (string)($response['body'] ?? ''),
                'status' => (int)($response['status'] ?? 0),
                'set_cookie' => is_array($response['set_cookie'] ?? null) ? $response['set_cookie'] : [],
            ];
        } catch (Throwable $exception) {
            return $this->errorResponse($exception->getMessage());
        }
    }

    public function sessionCookie(): string
    {
        return $this->cookieString($this->sessionCookies, false);
    }

    public function serializeCookie(array $cookies, bool $encode = false): string
    {
        return $this->cookieString($cookies, $encode);
    }

    public function deviceId(): string
    {
        return $this->deviceId;
    }

    private function completeCookies($cookie, string $osKey, string $uri, bool $skipAnonymous = false): array
    {
        $cookies = $this->parseCookie($cookie);
        $profile = self::PROFILES[$osKey] ?? self::PROFILES['pc'];
        $now = (string)round(microtime(true) * 1000);
        $cookies += [
            '__remember_me' => 'true',
            'ntes_kaola_ad' => '1',
            '_ntes_nuid' => $this->nuid,
            '_ntes_nnid' => $this->nuid . ',' . $now,
            'WNMCID' => $this->wnmcid,
            'WEVNSM' => '1.0.0',
            'osver' => $profile['osver'],
            'deviceId' => $this->deviceId,
            'os' => $profile['os'],
            'channel' => $profile['channel'],
            'appver' => $profile['appver'],
        ];
        if (strpos($uri, 'login') === false) {
            $cookies['NMTID'] = strtolower($this->randomHex(32));
        }
        if (empty($cookies['MUSIC_U']) && !$skipAnonymous && !empty($this->config['auto_anonymous_token'])) {
            $token = $this->ensureAnonymousToken();
            if ($token !== '') {
                $cookies['MUSIC_A'] = $cookies['MUSIC_A'] ?? $token;
            }
        } elseif (empty($cookies['MUSIC_U']) && $this->anonymousToken !== '') {
            $cookies['MUSIC_A'] = $cookies['MUSIC_A'] ?? $this->anonymousToken;
        }
        return $cookies;
    }

    private function eapiHeader(array $cookies): array
    {
        $header = [
            'osver' => (string)($cookies['osver'] ?? ''),
            'deviceId' => (string)($cookies['deviceId'] ?? $this->deviceId),
            'os' => (string)($cookies['os'] ?? 'pc'),
            'appver' => (string)($cookies['appver'] ?? self::PROFILES['pc']['appver']),
            'versioncode' => (string)($cookies['versioncode'] ?? '140'),
            'mobilename' => (string)($cookies['mobilename'] ?? ''),
            'buildver' => (string)($cookies['buildver'] ?? time()),
            'resolution' => (string)($cookies['resolution'] ?? '1920x1080'),
            '__csrf' => (string)($cookies['__csrf'] ?? ''),
            'channel' => (string)($cookies['channel'] ?? 'netease'),
            'requestId' => (string)round(microtime(true) * 1000) . '_' . str_pad((string)random_int(0, 999), 4, '0', STR_PAD_LEFT),
        ];
        foreach (['MUSIC_U', 'MUSIC_A'] as $name) {
            if (!empty($cookies[$name])) {
                $header[$name] = (string)$cookies[$name];
            }
        }
        return $header;
    }

    private function ensureXeapiPublicKey(): array
    {
        if ($this->xeapiPublicKey !== null && !empty($this->config['xeapi_public_key'])) {
            return $this->xeapiPublicKey;
        }
        if (self::$sharedXeapiPublicKey !== null) {
            $this->xeapiPublicKey = self::$sharedXeapiPublicKey;
            return self::$sharedXeapiPublicKey;
        }
        $cached = $this->xeapiPublicKey ?? $this->readCache('xeapi_public_key.json', true);
        $nonce = '';
        for ($i = 0; $i < 16; $i++) {
            $nonce .= (string)random_int(0, 9);
        }
        $timestamp = (string)round(microtime(true) * 1000);
        $data = [
            'appVersion' => '9.1.65',
            'currentKeyVersion' => (string)($cached['version'] ?? ''),
            'deviceId' => $this->deviceId,
            'nonce' => $nonce,
            'os' => 'android',
            'requestType' => 'active',
            'signature' => $this->crypto->xeapiSign($timestamp, $nonce),
            't1' => '',
            't2' => '',
            'timestamp' => $timestamp,
            'uid' => '',
        ];
        $response = $this->transport->request('POST', $this->config['api_domain'] . '/api/gorilla/anti/crawler/security/key/get', array_replace($this->baseOptions([]), [
            'headers' => [
                'User-Agent' => self::USER_AGENTS['api_android'],
                'Cookie' => 'deviceId=' . $this->encodeComponent($this->deviceId),
            ],
            'form_params' => $data,
        ]));
        $body = json_decode((string)($response['body'] ?? ''), true);
        if (is_array($body) && ($body['code'] ?? 0) === 200 && !empty($body['data']['encryptedData'])) {
            $signature = (string)($body['data']['signature'] ?? '');
            $responseTimestamp = (string)($body['data']['timestamp'] ?? '');
            if ($signature === '' || !hash_equals($this->crypto->xeapiSign($responseTimestamp, $nonce), $signature)) {
                throw new RuntimeException('XEAPI public key response signature mismatch');
            }
            $state = $this->crypto->decryptXeapiPublicKey((string)$body['data']['encryptedData']);
            if (empty($state['sk']) && !empty($cached['sk'])) {
                $state['sk'] = $cached['sk'];
            }
            if (empty($state['sk']) || empty($state['publicKey']) || empty($state['version'])) {
                throw new RuntimeException('XEAPI public key response is incomplete');
            }
            $state['deviceId'] = $this->deviceId;
            $this->xeapiPublicKey = $state;
            self::$sharedXeapiPublicKey = $state;
            $this->writeCache('xeapi_public_key.json', $state);
            return $state;
        }
        if (is_array($cached) && !empty($cached['sk']) && !empty($cached['publicKey']) && !empty($cached['version'])) {
            $this->xeapiPublicKey = $cached;
            self::$sharedXeapiPublicKey = $cached;
            return $cached;
        }
        throw new RuntimeException('XEAPI public key request failed');
    }

    private function ensureAntiCheatTokenV3(): string
    {
        if ($this->antiCheatTokenV3 !== '') {
            return $this->antiCheatTokenV3;
        }
        if (self::$sharedAntiCheatTokenV3 !== '') {
            $this->antiCheatTokenV3 = self::$sharedAntiCheatTokenV3;
            return self::$sharedAntiCheatTokenV3;
        }
        $cached = $this->readCache('anti_cheat_v3.txt', false);
        if (is_string($cached) && $cached !== '') {
            $this->antiCheatTokenV3 = $cached;
            self::$sharedAntiCheatTokenV3 = $cached;
            return $cached;
        }
        $response = $this->transport->request('GET', 'https://ac.dun.163yun.com/v3/b?pn=YD00000558929251', $this->baseOptions(['timeout' => 10]));
        $body = (string)($response['body'] ?? '');
        if (!preg_match('/null\(\[(\d+),\d+,"([^"]+)"\]\)/', $body, $match) || $match[1] !== '200') {
            throw new RuntimeException('NetEase anti-cheat v3 token request failed');
        }
        $this->antiCheatTokenV3 = $match[2];
        self::$sharedAntiCheatTokenV3 = $match[2];
        $this->writeCache('anti_cheat_v3.txt', $match[2]);
        return $match[2];
    }

    private function ensureAnonymousToken(): string
    {
        if ($this->anonymousToken !== '' || $this->registeringAnonymous) {
            return $this->anonymousToken;
        }
        $cached = $this->readCache('anonymous_token.txt', false);
        $cachedDeviceId = $this->readCache('anonymous_device_id.txt', false);
        if (is_string($cached) && $cached !== '' && hash_equals($this->deviceId, (string)$cachedDeviceId)) {
            $this->anonymousToken = $cached;
            return $cached;
        }

        $this->registeringAnonymous = true;
        try {
            $xorKey = '3go8&$8*3*3h0k(2)2';
            $xored = '';
            for ($i = 0, $length = strlen($this->deviceId); $i < $length; $i++) {
                $xored .= chr(ord($this->deviceId[$i]) ^ ord($xorKey[$i % strlen($xorKey)]));
            }
            $encodedId = base64_encode($this->deviceId . ' ' . base64_encode(md5($xored, true)));
            $response = $this->request('/api/register/anonimous', ['username' => $encodedId], 'xeapi', [
                'skip_anonymous' => true,
            ]);
            foreach ($response['set_cookie'] ?? [] as $line) {
                if (preg_match('/(?:^|;\s*)MUSIC_A=([^;]*)/', (string)$line, $match)) {
                    $this->anonymousToken = $match[1];
                    break;
                }
            }
            if ($this->anonymousToken !== '') {
                $this->writeCache('anonymous_token.txt', $this->anonymousToken);
                $this->writeCache('anonymous_device_id.txt', $this->deviceId);
            }
        } finally {
            $this->registeringAnonymous = false;
        }
        return $this->anonymousToken;
    }

    private function captureXeapiSession(array $headers): void
    {
        $sessionId = $this->headerValue($headers, 'x-encr-ssid');
        $sessionKey = $this->headerValue($headers, 'x-encr-sskey');
        if ($sessionId !== '' && $sessionKey !== '') {
            $this->xeapiSessionId = $sessionId;
            $this->xeapiSessionKey = $sessionKey;
        }
    }

    private function headerValue(array $headers, string $name): string
    {
        foreach ($headers as $key => $values) {
            if (strcasecmp((string)$key, $name) === 0) {
                return is_array($values) ? (string)($values[0] ?? '') : (string)$values;
            }
        }
        return '';
    }

    private function baseOptions(array $options): array
    {
        $result = [
            'timeout' => (float)($options['timeout'] ?? $this->config['timeout']),
            'connect_timeout' => (float)($options['connect_timeout'] ?? $this->config['connect_timeout']),
            'verify' => (bool)($options['verify'] ?? $this->config['verify']),
            'http_errors' => false,
        ];
        if (!empty($options['proxy'])) {
            $proxyUrl = is_string($options['proxy']) ? $options['proxy'] : (string)$this->config['proxy_url'];
            if ($proxyUrl !== '') {
                $result['proxy'] = $proxyUrl;
            }
        }
        return $result;
    }

    private function addProxyAuthorization(array &$headers, array $options): void
    {
        if (empty($options['proxy'])) {
            return;
        }
        $orderNo = (string)$this->config['proxy_order_no'];
        $secret = (string)$this->config['proxy_secret'];
        if ($orderNo === '' || $secret === '') {
            return;
        }
        $timestamp = time();
        $headers['Proxy-Authorization'] = 'sign=' .
            strtoupper(md5("orderno={$orderNo},secret={$secret},timestamp={$timestamp}")) .
            "&orderno={$orderNo}&timestamp={$timestamp}";
    }

    private function hasHeader(array $headers, string $name): bool
    {
        foreach (array_keys($headers) as $key) {
            if (strcasecmp((string)$key, $name) === 0) {
                return true;
            }
        }
        return false;
    }

    private function resolveOsKey(string $os, string $crypto): string
    {
        if ($os !== '' && isset(self::PROFILES[$os])) {
            return $os;
        }
        return $crypto === 'weapi' ? 'pc' : 'pc';
    }

    private function parseCookie($cookie): array
    {
        if (is_array($cookie)) {
            return $cookie;
        }
        $result = [];
        foreach (explode(';', (string)$cookie) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) === 2 && $pair[0] !== '') {
                $result[$pair[0]] = $pair[1];
            }
        }
        return $result;
    }

    private function cookieString(array $cookies, bool $encode): string
    {
        $parts = [];
        foreach ($cookies as $key => $value) {
            if ($value === null) {
                continue;
            }
            $name = $encode ? $this->encodeComponent((string)$key) : (string)$key;
            $content = $encode ? $this->encodeComponent((string)$value) : (string)$value;
            $parts[] = $name . '=' . $content;
        }
        return implode('; ', $parts);
    }

    private function encodeComponent(string $value): string
    {
        return strtr(rawurlencode($value), [
            '%21' => '!', '%27' => "'", '%28' => '(', '%29' => ')', '%2A' => '*',
        ]);
    }

    private function toBoolean($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return $value === 'true' || $value === '1' || $value === 1;
    }

    private function randomHex(int $length): string
    {
        $bytes = random_bytes((int)ceil($length / 2));
        return strtoupper(substr(bin2hex($bytes), 0, $length));
    }

    private function randomLowercase(int $length): string
    {
        $value = '';
        for ($i = 0; $i < $length; $i++) {
            $value .= chr(random_int(97, 122));
        }
        return $value;
    }

    private function readCache(string $name, bool $json)
    {
        if ((string)$this->config['cache_dir'] === '') {
            return $json ? [] : '';
        }
        $path = $this->cachePath($name);
        if (!is_file($path)) {
            return $json ? [] : '';
        }
        $value = file_get_contents($path);
        if ($value === false) {
            return $json ? [] : '';
        }
        if (!$json) {
            return trim($value);
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function writeCache(string $name, $value): void
    {
        $directory = (string)$this->config['cache_dir'];
        if ($directory === '') {
            return;
        }
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return;
        }
        $content = is_array($value) ? $this->jsonEncode($value) : (string)$value;
        @file_put_contents($this->cachePath($name), $content, LOCK_EX);
    }

    private function cachePath(string $name): string
    {
        return rtrim((string)$this->config['cache_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
    }

    private function jsonEncode(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? '{}' : $json;
    }

    private function errorResponse(string $message): array
    {
        return [
            'header' => '',
            'body' => $this->jsonEncode(['code' => -1, 'message' => 'netease sdk: ' . $message]),
            'status' => 0,
            'set_cookie' => [],
        ];
    }
}
