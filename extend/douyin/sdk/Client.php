<?php

declare(strict_types=1);

namespace douyin\sdk;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Throwable;

final class Client
{
    private const QR_PATH = '/passport/web/get_qrcode/';
    private const POLL_PATH = '/passport/web/check_qrconnect/';
    private const ACCOUNT_INFO_PATH = '/passport/account/info/v2/';
    private const MAX_AUTH_REDIRECTS = 5;
    private const STATUS_NAMES = [
        '1' => 'new',
        '2' => 'scanned',
        '3' => 'confirmed',
        '4' => 'refused',
        '5' => 'expired',
    ];
    private const VERIFICATION_HOSTS = [
        'douyin.com',
        'amemv.com',
        'bytedance.com',
        'bytedance.net',
        'bytegoofy.com',
        'byteimg.com',
        'snssdk.com',
        'toutiao.com',
        'zijieapi.com',
    ];
    private const AUTH_REDIRECT_HOSTS = [
        'douyin.com',
    ];
    private const AVATAR_HOSTS = [
        'douyin.com',
        'douyinpic.com',
        'byteimg.com',
    ];

    private TransportInterface $transport;
    private SignerInterface $urlSigner;
    private PassportSigner $passportSigner;
    private CookieSession $session;
    /** @var array<string,mixed> */
    private array $config;
    private string $msToken;
    private string $dtraitKey;
    private string $verifyPortrait;
    private string $lastVerificationData = '';
    /** @var array<string,mixed> */
    private array $qrContext = [];

    /** @param array<string,mixed>|string $cookies */
    public function __construct(
        array|string $cookies = [],
        array $config = [],
        ?TransportInterface $transport = null,
        ?SignerInterface $urlSigner = null,
        ?PassportSigner $passportSigner = null
    ) {
        $this->config = array_replace([
            'passport_domain' => 'https://login.douyin.com',
            'origin' => 'https://www.douyin.com',
            'timeout' => 20.0,
            'connect_timeout' => 8.0,
            'verify' => true,
            'node_binary' => getenv('DOUYIN_NODE_BINARY') ?: 'node',
            'signer_timeout' => 8.0,
        ], $config);
        $this->transport = $transport ?? new GuzzleTransport();
        $this->passportSigner = $passportSigner ?? new PassportSigner();
        $this->urlSigner = $urlSigner ?? new NodeSigner(
            (string)$this->config['node_binary'],
            null,
            (float)$this->config['signer_timeout']
        );
        $this->session = new CookieSession($cookies);
        if (!$this->session->has('passport_csrf_token')) {
            $csrf = bin2hex(random_bytes(16));
            $this->session->merge([
                'passport_csrf_token' => $csrf,
                'passport_csrf_token_default' => $csrf,
            ]);
        }
        $this->msToken = (string)($config['ms_token'] ?? $this->passportSigner->randomMsToken());
        $this->dtraitKey = bin2hex(random_bytes(16));
        $this->verifyPortrait = $this->newVerifyPortrait();
        if (is_array($config['state'] ?? null)) {
            $this->restoreState($config['state']);
        }
    }

    /** @return array<string,mixed> */
    public function qrGenerate(): array
    {
        $lastPayload = [];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $query = array_merge($this->passportSigner->baseQuery(), [
                'next' => (string)$this->config['origin'],
                'need_short_url' => 'true',
                'need_logo' => 'false',
                'is_new_login' => '1',
                'is_from_iesaccountsaas' => '1',
                'p_ui' => '2.1.9-alpha.6',
            ]);
            $query = array_merge($query, $this->passportSigner->sign($query));
            $previousMsToken = $this->msToken;
            $query['msToken'] = $this->msToken;

            $lastPayload = $this->passportRequestJson('GET', self::QR_PATH, $query);
            $this->capturePayload($lastPayload);
            if ($this->qrContext['token'] ?? '') {
                return $lastPayload;
            }
            if ($this->msToken === $previousMsToken) {
                break;
            }
        }
        return $lastPayload ?: $this->localError('Douyin QR request returned an empty response');
    }

    /** @return array<string,mixed> */
    public function qrPoll(?string $token = null, ?bool $isFrontier = null): array
    {
        $token = trim($token ?? (string)($this->qrContext['token'] ?? ''));
        if ($token === '') {
            return $this->localError('Douyin QR token is missing');
        }
        $isFrontier ??= (bool)($this->qrContext['is_frontier'] ?? false);
        $body = [
            'need_logo' => 'false',
            'is_frontier' => $isFrontier ? 'true' : 'false',
            'token' => $token,
            'is_new_login' => '1',
            'next' => (string)$this->config['origin'],
            'need_short_url' => 'true',
        ];
        $query = array_merge($this->passportSigner->baseQuery('4.0.17'), [
            'is_from_iesaccountsaas' => '1',
            'p_ui' => '2.1.9-alpha.6',
            'p_ca' => '4.0.17',
            'p_ca_real' => '1.0.0.852',
        ]);
        $query = array_merge($query, $this->passportSigner->sign($query, $body));
        $query['msToken'] = $this->msToken;

        $payload = $this->passportRequestJson('POST', self::POLL_PATH, $query, $body);
        $this->capturePayload($payload);
        if (($this->qrContext['status'] ?? '') === 'confirmed') {
            $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
            $this->completeQrLogin($data);
        }
        return $payload;
    }

    /** @return array<string,mixed> */
    public function accountInfo(): array
    {
        $query = array_merge($this->passportSigner->baseQuery(), [
            'is_from_iesaccountsaas' => '1',
            'p_ui' => '2.1.9-alpha.6',
        ]);
        $query = array_merge($query, $this->passportSigner->sign($query));
        $query['msToken'] = $this->msToken;

        return $this->passportRequestJson('GET', self::ACCOUNT_INFO_PATH, $query);
    }

    /** @return array<string,mixed> */
    public function state(): array
    {
        return [
            'version' => 2,
            'cookies' => $this->session->all(),
            'ms_token' => $this->msToken,
            'dtrait_key' => $this->dtraitKey,
            'verify_portrait' => $this->verifyPortrait,
            'token' => (string)($this->qrContext['token'] ?? ''),
            'is_frontier' => (bool)($this->qrContext['is_frontier'] ?? false),
            'expire_time' => (int)($this->qrContext['expire_time'] ?? 0),
            'status' => (string)($this->qrContext['status'] ?? 'new'),
            'qrcode' => (string)($this->qrContext['qrcode'] ?? ''),
            'qrcode_index_url' => (string)($this->qrContext['qrcode_index_url'] ?? ''),
            'auth_redirect_consumed' => (bool)($this->qrContext['auth_redirect_consumed'] ?? false),
            'profile_fetched' => (bool)($this->qrContext['profile_fetched'] ?? false),
            'profile' => $this->profile(),
            'verification' => is_array($this->qrContext['verification'] ?? null)
                ? $this->qrContext['verification']
                : [
                    'required' => false,
                    'mode' => 'none',
                    'url' => '',
                    'verify_data' => '',
                    'description' => '',
                ],
            'message' => (string)($this->qrContext['message'] ?? ''),
            'updated_at' => (int)($this->qrContext['updated_at'] ?? time()),
        ];
    }

    /** @return array<string,mixed> */
    public function publicState(): array
    {
        $state = $this->state();
        return [
            'status' => $state['status'],
            'expire_time' => $state['expire_time'],
            'is_frontier' => $state['is_frontier'],
            'authenticated' => $this->session->authenticated(),
            'verification' => $state['verification'],
            'message' => $state['message'],
        ];
    }

    /** @return array{cookies:array<string,string>,cookie_header:string} */
    public function credentials(): array
    {
        return [
            'cookies' => $this->session->all(),
            'cookie_header' => $this->session->header(),
        ];
    }

    /** @return array{user_id:string,sec_user_id:string,nickname:string,avatar:string} */
    public function profile(): array
    {
        $profile = is_array($this->qrContext['profile'] ?? null) ? $this->qrContext['profile'] : [];
        $userId = trim((string)($profile['user_id'] ?? ''));
        $secUserId = trim((string)($profile['sec_user_id'] ?? ''));
        if ($userId === '') {
            $userId = $secUserId;
        }
        if ($userId === '') {
            $userId = $this->session->get('uid_tt', $this->session->get('uid_tt_ss'));
        }

        return [
            'user_id' => $this->boundedIdentifier($userId),
            'sec_user_id' => $this->boundedIdentifier($secUserId),
            'nickname' => $this->boundedText((string)($profile['nickname'] ?? ''), 128),
            'avatar' => $this->normalizeAvatar((string)($profile['avatar'] ?? '')),
        ];
    }

    /** @param array<string,mixed> $state */
    public function restoreState(array $state): void
    {
        if (is_array($state['cookies'] ?? null)) {
            $this->session->replace($state['cookies']);
        }
        if (!empty($state['ms_token']) && is_string($state['ms_token'])) {
            $this->msToken = $state['ms_token'];
        }
        if (is_string($state['dtrait_key'] ?? null)
            && preg_match('/^[a-f0-9]{32}$/iD', $state['dtrait_key'])
        ) {
            $this->dtraitKey = strtolower($state['dtrait_key']);
        }
        if (is_string($state['verify_portrait'] ?? null)
            && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\.login$/iD', $state['verify_portrait'])
        ) {
            $this->verifyPortrait = strtolower($state['verify_portrait']);
        }
        foreach ([
            'token',
            'is_frontier',
            'expire_time',
            'status',
            'qrcode',
            'qrcode_index_url',
            'auth_redirect_consumed',
            'profile_fetched',
            'profile',
            'verification',
            'message',
            'updated_at',
        ] as $key) {
            if (array_key_exists($key, $state)) {
                $this->qrContext[$key] = $state[$key];
            }
        }
    }

    /** @param array<string,mixed> $confirmedData */
    private function completeQrLogin(array $confirmedData): void
    {
        $redirectUrl = $this->findStringForKey($confirmedData, 'redirect_url');
        if ($redirectUrl !== '' && empty($this->qrContext['auth_redirect_consumed'])) {
            $this->qrContext['auth_redirect_consumed'] = $this->consumeAuthRedirect($redirectUrl);
        }

        if (!$this->session->authenticated() || !empty($this->qrContext['profile_fetched'])) {
            return;
        }

        $accountPayload = $this->accountInfo();
        $this->qrContext['profile'] = $this->normalizeProfile($accountPayload, $confirmedData);
        $accountData = is_array($accountPayload['data'] ?? null) ? $accountPayload['data'] : [];
        $this->qrContext['profile_fetched'] = (int)($accountData['error_code'] ?? -1) === 0;
    }

    private function consumeAuthRedirect(string $url): bool
    {
        $currentUrl = $this->allowedAuthRedirectUrl($url);
        if ($currentUrl === '') {
            return false;
        }

        for ($hop = 0; $hop < self::MAX_AUTH_REDIRECTS; $hop++) {
            $response = $this->rawRequest('GET', $currentUrl, [
                'headers' => [
                    'Accept' => 'application/json, text/plain, */*',
                    'Origin' => (string)$this->config['origin'],
                    'Referer' => rtrim((string)$this->config['origin'], '/') . '/',
                ],
            ]);
            $status = (int)$response['status'];
            if (!in_array($status, [301, 302, 303, 307, 308], true)) {
                return $this->session->authenticated();
            }

            $location = trim($this->headerValue($response['headers'], 'location'));
            if ($location === '') {
                return $this->session->authenticated();
            }
            try {
                $nextUrl = (string)UriResolver::resolve(new Uri($currentUrl), new Uri($location));
            } catch (Throwable $exception) {
                return false;
            }
            $currentUrl = $this->allowedAuthRedirectUrl($nextUrl);
            if ($currentUrl === '') {
                return false;
            }
        }

        return false;
    }

    private function allowedAuthRedirectUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 8192) {
            return '';
        }
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && (int)$parts['port'] !== 443)
        ) {
            return '';
        }
        $host = strtolower(rtrim((string)$parts['host'], '.'));
        if (!$this->hostAllowed($host, self::AUTH_REDIRECT_HOSTS)) {
            return '';
        }
        return $url;
    }

    /** @param array<string,mixed> $accountPayload
     *  @param array<string,mixed> $confirmedData
     *  @return array{user_id:string,sec_user_id:string,nickname:string,avatar:string}
     */
    private function normalizeProfile(array $accountPayload, array $confirmedData): array
    {
        $sources = [$accountPayload, $confirmedData];
        $userId = $this->findFirstString($sources, ['user_id', 'uid']);
        $secUserId = $this->findFirstString($sources, ['sec_user_id']);
        $nickname = $this->findFirstString($sources, ['screen_name', 'nickname', 'display_name']);
        $avatar = $this->findFirstString($sources, ['avatar_url', 'avatar', 'avatar_thumb']);

        return [
            'user_id' => $this->boundedIdentifier($userId),
            'sec_user_id' => $this->boundedIdentifier($secUserId),
            'nickname' => $this->boundedText($nickname, 128),
            'avatar' => $this->normalizeAvatar($avatar),
        ];
    }

    /** @param array<int,mixed> $sources
     *  @param array<int,string> $keys
     */
    private function findFirstString(array $sources, array $keys): string
    {
        foreach ($sources as $source) {
            foreach ($keys as $key) {
                $value = $this->findStringForKey($source, $key);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return '';
    }

    private function findStringForKey(mixed $value, string $key, int $depth = 0): string
    {
        if (!is_array($value) || $depth > 6) {
            return '';
        }
        if (array_key_exists($key, $value)) {
            $candidate = $this->firstScalarString($value[$key]);
            if ($candidate !== '') {
                return $candidate;
            }
        }
        foreach ($value as $child) {
            if (!is_array($child)) {
                continue;
            }
            $candidate = $this->findStringForKey($child, $key, $depth + 1);
            if ($candidate !== '') {
                return $candidate;
            }
        }
        return '';
    }

    private function firstScalarString(mixed $value, int $depth = 0): string
    {
        if (is_string($value) || is_int($value)) {
            return trim((string)$value);
        }
        if (!is_array($value) || $depth > 3) {
            return '';
        }
        foreach ($value as $child) {
            $candidate = $this->firstScalarString($child, $depth + 1);
            if ($candidate !== '') {
                return $candidate;
            }
        }
        return '';
    }

    private function boundedIdentifier(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 255 || !preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
            return '';
        }
        return $value;
    }

    private function boundedText(string $value, int $maxLength): string
    {
        $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '');
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength, 'UTF-8');
        }
        return substr($value, 0, $maxLength);
    }

    private function normalizeAvatar(string $url): string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048) {
            return '';
        }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            return '';
        }
        $host = strtolower(rtrim((string)$parts['host'], '.'));
        return $this->hostAllowed($host, self::AVATAR_HOSTS) ? $url : '';
    }

    /** @param array<int,string> $allowedHosts */
    private function hostAllowed(string $host, array $allowedHosts): bool
    {
        foreach ($allowedHosts as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{status:int,headers:array<string,array<int,string>>,body:string,header:string,set_cookie:array<int,string>}
     */
    public function rawRequest(string $method, string $url, array $options = []): array
    {
        try {
            $headers = [
                'Accept' => 'application/json, text/javascript',
                'Accept-Language' => 'zh-CN,zh;q=0.9',
                'Origin' => (string)$this->config['origin'],
                'Referer' => rtrim((string)$this->config['origin'], '/') . '/',
                'User-Agent' => PassportSigner::USER_AGENT,
                'Sec-CH-UA' => '"Not=A?Brand";v="99", "Google Chrome";v="151", "Chromium";v="151"',
                'Sec-CH-UA-Mobile' => '?0',
                'Sec-CH-UA-Platform' => '"Windows"',
            ];
            if ($this->session->header() !== '') {
                $headers['Cookie'] = $this->session->header();
            }
            if (is_array($options['headers'] ?? null)) {
                $headers = array_replace($headers, $options['headers']);
            }
            $requestOptions = [
                'timeout' => (float)($options['timeout'] ?? $this->config['timeout']),
                'connect_timeout' => (float)($options['connect_timeout'] ?? $this->config['connect_timeout']),
                'verify' => (bool)($options['verify'] ?? $this->config['verify']),
                'http_errors' => false,
                'allow_redirects' => false,
                'headers' => $headers,
            ];
            if (array_key_exists('body', $options)) {
                $requestOptions['body'] = (string)$options['body'];
            }

            $response = $this->transport->request(strtoupper($method), $url, $requestOptions);
            $setCookie = is_array($response['set_cookie'] ?? null) ? $response['set_cookie'] : [];
            $this->session->capture($setCookie);
            $headers = is_array($response['headers'] ?? null) ? $response['headers'] : [];
            $nextMsToken = $this->headerValue($headers, 'x-ms-token');
            if ($nextMsToken !== '') {
                $this->msToken = $nextMsToken;
            }
            return [
                'status' => (int)($response['status'] ?? 0),
                'headers' => $headers,
                'body' => (string)($response['body'] ?? ''),
                'header' => (string)($response['header'] ?? ''),
                'set_cookie' => $setCookie,
            ];
        } catch (Throwable $exception) {
            return [
                'status' => 0,
                'headers' => [],
                'body' => json_encode($this->localError('douyin sdk: ' . $exception->getMessage()), JSON_UNESCAPED_SLASHES) ?: '{}',
                'header' => '',
                'set_cookie' => [],
            ];
        }
    }

    /** @return array<string,mixed> */
    private function passportRequestJson(string $method, string $path, array $query, array $body = []): array
    {
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $bodyString = http_build_query($body, '', '&', PHP_QUERY_RFC3986);
        $unsignedUrl = rtrim((string)$this->config['passport_domain'], '/') . $path . '?' . $queryString;

        try {
            $environmentHeaders = [];
            if (method_exists($this->urlSigner, 'signRequest')) {
                /** @var mixed $signedRequest */
                $signedRequest = $this->urlSigner->signRequest(
                    $unsignedUrl,
                    $method,
                    $bodyString,
                    $this->dtraitKey
                );
                if (!is_array($signedRequest)) {
                    throw new \RuntimeException('Douyin signer returned an invalid request result');
                }
                $signedUrl = (string)($signedRequest['url'] ?? '');
                $candidateHeaders = is_array($signedRequest['headers'] ?? null)
                    ? $signedRequest['headers']
                    : [];
                $dtrait = trim((string)($candidateHeaders['X-TT-Session-Dtrait'] ?? ''));
                if (!$this->validDtraitHeader($dtrait)) {
                    throw new \RuntimeException('Douyin signer returned an invalid DTrait header');
                }
                $environmentHeaders['X-TT-Session-Dtrait'] = $dtrait;
            } else {
                $signedUrl = $this->urlSigner->sign($unsignedUrl, $method, $bodyString);
            }
        } catch (Throwable $exception) {
            return $this->localError('douyin signer: ' . $exception->getMessage());
        }

        $headers = array_replace([
            'X-TT-Passport-Aid-Sign' => $this->passportSigner->aidSign($path, (string)($query['ts'] ?? '')),
            'X-TT-Passport-CSRF-Token' => $this->session->get('passport_csrf_token'),
            'X-TT-Passport-Trace-Id' => (string)($query['biz_trace_id'] ?? ''),
            'X-TT-Passport-Verify-Portrait' => $this->verifyPortrait,
        ], $environmentHeaders);
        if (strtoupper($method) === 'POST') {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        }
        $response = $this->rawRequest($method, $signedUrl, [
            'headers' => $headers,
            'body' => $bodyString,
        ]);
        $this->lastVerificationData = $this->verificationHeader($response['headers']);
        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            return $this->localError('Douyin returned invalid JSON (HTTP ' . $response['status'] . ')');
        }
        return $decoded;
    }

    private function newVerifyPortrait(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s.login',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    private function validDtraitHeader(string $value): bool
    {
        if (strlen($value) < 700 || strlen($value) > 1200) {
            return false;
        }
        $parts = explode('_', $value);
        return count($parts) === 3
            && $parts[0] === 'd0'
            && (bool)preg_match('/^[A-Za-z0-9+\/]+={0,2}$/D', $parts[1])
            && (bool)preg_match('/^[A-Za-z0-9+\/]+={0,2}$/D', $parts[2]);
    }

    /** @param array<string,mixed> $payload */
    private function capturePayload(array $payload): void
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        foreach (['token', 'qrcode', 'qrcode_index_url'] as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '') {
                $this->qrContext[$key] = $data[$key];
            }
        }
        if (array_key_exists('is_frontier', $data)) {
            $this->qrContext['is_frontier'] = filter_var($data['is_frontier'], FILTER_VALIDATE_BOOL);
        }
        if (isset($data['expire_time']) && is_numeric($data['expire_time'])) {
            $this->qrContext['expire_time'] = (int)$data['expire_time'];
        }
        if (array_key_exists('status', $data)) {
            $this->qrContext['status'] = $this->normalizeStatus($data['status']);
        } elseif (!isset($this->qrContext['status'])) {
            $this->qrContext['status'] = 'new';
        }
        $this->qrContext['verification'] = $this->verification($data);
        $this->qrContext['message'] = (string)($data['description'] ?? $payload['message'] ?? '');
        $this->qrContext['updated_at'] = time();
    }

    private function normalizeStatus(mixed $status): string
    {
        $status = strtolower(trim((string)$status));
        return self::STATUS_NAMES[$status] ?? $status ?: 'unknown';
    }

    /** @param array<string,mixed> $data
     *  @return array{required:bool,mode:string,url:string,verify_data:string,description:string}
     */
    private function verification(array $data): array
    {
        $description = trim((string)($data['description'] ?? ''));
        foreach (['desc_url', 'captcha'] as $key) {
            if (!array_key_exists($key, $data) || $data[$key] === '' || $data[$key] === null) {
                continue;
            }
            $url = $this->findVerificationUrl($data[$key]);
            if ($url !== '') {
                return [
                    'required' => true,
                    'mode' => 'url',
                    'url' => $url,
                    'verify_data' => '',
                    'description' => $description,
                ];
            }
            if ($key === 'captcha' && $this->lastVerificationData === '') {
                $this->lastVerificationData = $this->normalizeVerifyData($data[$key]);
            }
        }
        if ($this->lastVerificationData !== '') {
            return [
                'required' => true,
                'mode' => 'turing',
                'url' => '',
                'verify_data' => $this->lastVerificationData,
                'description' => $description,
            ];
        }
        $required = !empty($data['captcha']) || !empty($data['desc_url']);
        return [
            'required' => $required,
            'mode' => $required ? 'unsupported' : 'none',
            'url' => '',
            'verify_data' => '',
            'description' => $description,
        ];
    }

    /** @param array<string,mixed> $headers */
    private function verificationHeader(array $headers): string
    {
        $value = $this->headerValue($headers, 'bdturing-verify');
        if ($value === '') {
            $value = $this->headerValue($headers, 'x-vc-bdturing-parameters');
        }
        return $this->normalizeVerifyData($value);
    }

    private function normalizeVerifyData(mixed $value): string
    {
        if (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return is_string($encoded) && strlen($encoded) <= 262144 ? $encoded : '';
        }
        if (!is_string($value)) {
            return '';
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > 349528) {
            return '';
        }

        $decodedBase64 = base64_decode($value, true);
        if (is_string($decodedBase64) && $decodedBase64 !== '') {
            $normalizedInput = rtrim(str_replace(["\r", "\n"], '', $value), '=');
            $normalizedRoundTrip = rtrim(base64_encode($decodedBase64), '=');
            if (hash_equals($normalizedRoundTrip, $normalizedInput)) {
                $value = $decodedBase64;
            }
        }
        if (strlen($value) > 262144) {
            return '';
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return '';
        }
        $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($encoded) ? $encoded : '';
    }

    private function findVerificationUrl(mixed $value, int $depth = 0): string
    {
        if ($depth > 5) {
            return '';
        }
        if (is_string($value)) {
            $value = trim($value);
            if ($this->isAllowedVerificationUrl($value)) {
                return $value;
            }
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $this->findVerificationUrl($decoded, $depth + 1) : '';
        }
        if (!is_array($value)) {
            return '';
        }

        foreach (['desc_url', 'verify_url', 'captcha_url', 'web_url', 'url'] as $key) {
            if (array_key_exists($key, $value)) {
                $url = $this->findVerificationUrl($value[$key], $depth + 1);
                if ($url !== '') {
                    return $url;
                }
            }
        }
        foreach ($value as $candidate) {
            $url = $this->findVerificationUrl($candidate, $depth + 1);
            if ($url !== '') {
                return $url;
            }
        }
        return '';
    }

    private function isAllowedVerificationUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return false;
        }
        $host = strtolower((string)$parts['host']);
        foreach (self::VERIFICATION_HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $headers */
    private function headerValue(array $headers, string $name): string
    {
        foreach ($headers as $headerName => $values) {
            if (strcasecmp((string)$headerName, $name) !== 0) {
                continue;
            }
            if (is_array($values)) {
                return (string)($values[0] ?? '');
            }
            return (string)$values;
        }
        return '';
    }

    /** @return array{data:array{error_code:int},message:string} */
    private function localError(string $message): array
    {
        return ['data' => ['error_code' => -1], 'message' => $message];
    }
}
