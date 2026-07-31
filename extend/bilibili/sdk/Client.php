<?php

declare(strict_types=1);

namespace bilibili\sdk;

use Throwable;

final class Client
{
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    private TransportInterface $transport;
    private CookieSession $session;
    private WbiSigner $wbiSigner;
    private AppSigner $appSigner;
    /** @var array<string,mixed> */
    private array $config;
    /** @var array{img_key:string,sub_key:string}|null */
    private ?array $wbiKeys = null;

    /** @param array<string,mixed>|string $cookies */
    public function __construct(
        array|string $cookies = [],
        array $config = [],
        ?TransportInterface $transport = null,
        ?WbiSigner $wbiSigner = null,
        ?AppSigner $appSigner = null
    ) {
        $this->config = array_replace([
            'api_domain' => 'https://api.bilibili.com',
            'web_domain' => 'https://www.bilibili.com',
            'passport_domain' => 'https://passport.bilibili.com',
            'live_domain' => 'https://api.live.bilibili.com',
            'manga_domain' => 'https://manga.bilibili.com',
            'dynamic_domain' => 'https://api.bilibili.com',
            'timeout' => 20.0,
            'connect_timeout' => 8.0,
            'verify' => true,
            'access_key' => '',
            'wbi_keys' => [],
        ], $config);
        $this->transport = $transport ?? new GuzzleTransport();
        $this->session = new CookieSession($cookies);
        $this->wbiSigner = $wbiSigner ?? new WbiSigner();
        $this->appSigner = $appSigner ?? new AppSigner();

        $configuredKeys = $this->config['wbi_keys'];
        if (is_array($configuredKeys) && !empty($configuredKeys['img_key']) && !empty($configuredKeys['sub_key'])) {
            $this->wbiKeys = [
                'img_key' => (string)$configuredKeys['img_key'],
                'sub_key' => (string)$configuredKeys['sub_key'],
            ];
        }
    }

    /**
     * @return array{status:int,headers:array<string,array<int,string>>,body:string,header:string,set_cookie:array<int,string>}
     */
    public function rawRequest(string $method, string $url, array $options = []): array
    {
        try {
            $headers = [
                'User-Agent' => (string)($options['user_agent'] ?? self::USER_AGENT),
                'Accept' => 'application/json, text/plain, */*',
                'Accept-Language' => 'zh-CN,zh;q=0.9',
            ];
            if (!empty($options['referer'])) {
                $headers['Referer'] = (string)$options['referer'];
            }
            if (!empty($options['origin'])) {
                $headers['Origin'] = (string)$options['origin'];
            }
            if (!empty($options['headers']) && is_array($options['headers'])) {
                $headers = array_replace($headers, $options['headers']);
            }
            if (($options['with_cookies'] ?? true) && $this->session->header() !== '' && !$this->hasHeader($headers, 'Cookie')) {
                $headers['Cookie'] = $this->session->header();
            }

            $requestOptions = [
                'timeout' => (float)($options['timeout'] ?? $this->config['timeout']),
                'connect_timeout' => (float)($options['connect_timeout'] ?? $this->config['connect_timeout']),
                'verify' => (bool)($options['verify'] ?? $this->config['verify']),
                'http_errors' => false,
                'allow_redirects' => false,
                'headers' => $headers,
            ];
            foreach (['query', 'form_params', 'json', 'body'] as $key) {
                if (array_key_exists($key, $options)) {
                    $requestOptions[$key] = $options[$key];
                }
            }
            if (isset($requestOptions['form_params']) && !$this->hasHeader($headers, 'Content-Type')) {
                $requestOptions['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
            }

            $response = $this->transport->request(strtoupper($method), $url, $requestOptions);
            $setCookie = is_array($response['set_cookie'] ?? null) ? $response['set_cookie'] : [];
            $this->session->capture($setCookie);
            return [
                'status' => (int)($response['status'] ?? 0),
                'headers' => is_array($response['headers'] ?? null) ? $response['headers'] : [],
                'body' => (string)($response['body'] ?? ''),
                'header' => (string)($response['header'] ?? ''),
                'set_cookie' => $setCookie,
            ];
        } catch (Throwable $exception) {
            return [
                'status' => 0,
                'headers' => [],
                'body' => $this->jsonEncode(['code' => -1, 'message' => 'bilibili sdk: ' . $exception->getMessage()]),
                'header' => '',
                'set_cookie' => [],
            ];
        }
    }

    /** @return array<string,mixed> */
    public function requestJson(string $method, string $url, array $options = []): array
    {
        $response = $this->rawRequest($method, $url, $options);
        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            return [
                'code' => -1,
                'message' => 'bilibili sdk: invalid JSON response (HTTP ' . $response['status'] . ')',
            ];
        }
        return $decoded;
    }

    /** @return array<string,mixed> */
    public function captcha(string $source = 'main_web'): array
    {
        return $this->requestJson('GET', $this->passport('/x/passport-login/captcha'), [
            'query' => ['source' => $source, 't' => (int)round(microtime(true) * 1000)],
            'with_cookies' => false,
            'referer' => 'https://www.bilibili.com/',
        ]);
    }

    /** @return array<string,mixed> */
    public function qrGenerate(): array
    {
        return $this->requestJson('GET', $this->passport('/x/passport-login/web/qrcode/generate'), [
            'with_cookies' => false,
            'referer' => 'https://passport.bilibili.com/login',
        ]);
    }

    /** @return array<string,mixed> */
    public function qrPoll(string $qrcodeKey): array
    {
        return $this->requestJson('GET', $this->passport('/x/passport-login/web/qrcode/poll'), [
            'query' => ['qrcode_key' => $qrcodeKey],
            'referer' => 'https://passport.bilibili.com/login',
        ]);
    }

    /** @return array<string,mixed> */
    public function smsSend(string $phone, array $captcha, int $cid = 1, string $source = 'main_web'): array
    {
        return $this->requestJson('POST', $this->passport('/x/passport-login/web/sms/send'), [
            'form_params' => [
                'cid' => $cid,
                'tel' => $phone,
                'source' => $source,
                'token' => (string)($captcha['token'] ?? ''),
                'challenge' => (string)($captcha['challenge'] ?? ''),
                'validate' => (string)($captcha['validate'] ?? ''),
                'seccode' => (string)($captcha['seccode'] ?? ''),
            ],
            'with_cookies' => false,
            'origin' => 'https://passport.bilibili.com',
            'referer' => 'https://passport.bilibili.com/login',
        ]);
    }

    /** @return array<string,mixed> */
    public function smsLogin(string $phone, string $code, string $captchaKey, int $cid = 1): array
    {
        return $this->requestJson('POST', $this->passport('/x/passport-login/web/login/sms'), [
            'form_params' => [
                'cid' => $cid,
                'tel' => $phone,
                'code' => $code,
                'source' => 'main_web',
                'captcha_key' => $captchaKey,
                'go_url' => 'https://www.bilibili.com/',
                'keep' => 'true',
            ],
            'origin' => 'https://passport.bilibili.com',
            'referer' => 'https://passport.bilibili.com/login',
        ]);
    }

    /** @return array<string,mixed> */
    public function nav(): array
    {
        return $this->requestJson('GET', $this->api('/x/web-interface/nav'), [
            'referer' => 'https://www.bilibili.com/',
        ]);
    }

    /** @return array<string,mixed> */
    public function dailyReward(): array
    {
        return $this->requestJson('GET', $this->api('/x/member/web/exp/reward'));
    }

    /** @return array<string,mixed> */
    public function todayCoinExp(): array
    {
        return $this->requestJson('GET', $this->api('/x/web-interface/coin/today/exp'));
    }

    /** @return array<string,mixed> */
    public function popular(int $page = 1, int $pageSize = 20): array
    {
        return $this->requestJson('GET', $this->api('/x/web-interface/popular'), [
            'query' => ['pn' => max(1, $page), 'ps' => max(1, min(50, $pageSize))],
            'referer' => 'https://www.bilibili.com/v/popular/all',
        ]);
    }

    /** @return array<string,mixed> */
    public function dynamicFeed(string $type = 'video'): array
    {
        return $this->requestJson('GET', $this->dynamic('/x/polymer/web-dynamic/v1/feed/all'), [
            'query' => [
                'type' => $type,
                'timezone_offset' => '-480',
                'platform' => 'web',
            ],
            'referer' => 'https://t.bilibili.com/',
        ]);
    }

    /** @return array<string,mixed> */
    public function videoDetail(int|string $video): array
    {
        $params = is_int($video) || ctype_digit((string)$video)
            ? ['aid' => (string)$video]
            : ['bvid' => (string)$video];
        return $this->wbiGet('/x/web-interface/wbi/view/detail', $params + ['need_elec' => '0']);
    }

    /** @return array<string,mixed> */
    public function startVideo(array $video): array
    {
        $now = time();
        $aid = (string)($video['aid'] ?? '');
        $referer = 'https://www.bilibili.com/video/av' . $aid;
        return $this->requestJson('POST', $this->api('/x/click-interface/click/web/h5'), [
            'form_params' => [
                'mid' => $this->session->get('DedeUserID'),
                'aid' => $aid,
                'cid' => (string)($video['cid'] ?? ''),
                'part' => 1,
                'ftime' => $now,
                'stime' => $now,
                'type' => 3,
                'referer_url' => $referer,
                'csrf' => $this->csrf(),
            ],
            'origin' => 'https://www.bilibili.com',
            'referer' => $referer,
        ]);
    }

    /** @return array<string,mixed> */
    public function videoHeartbeat(array $video, int $playedTime): array
    {
        $aid = (string)($video['aid'] ?? '');
        $duration = max(1, (int)($video['duration'] ?? $playedTime));
        $referer = 'https://www.bilibili.com/video/av' . $aid;
        return $this->requestJson('POST', $this->api('/x/click-interface/web/heartbeat'), [
            'form_params' => [
                'aid' => $aid,
                'bvid' => (string)($video['bvid'] ?? ''),
                'cid' => (string)($video['cid'] ?? ''),
                'mid' => $this->session->get('DedeUserID'),
                'played_time' => max(1, min($playedTime, $duration)),
                'realtime' => max(1, min($playedTime, $duration)),
                'real_played_time' => max(1, min($playedTime, $duration)),
                'video_duration' => $duration,
                'start_ts' => time(),
                'type' => 3,
                'dt' => 2,
                'play_type' => 0,
                'csrf' => $this->csrf(),
            ],
            'origin' => 'https://www.bilibili.com',
            'referer' => $referer,
        ]);
    }

    /** @return array<string,mixed> */
    public function historyReport(array $video, int $progress): array
    {
        return $this->requestJson('POST', $this->api('/x/v2/history/report'), [
            'form_params' => [
                'aid' => (string)($video['aid'] ?? ''),
                'cid' => (string)($video['cid'] ?? ''),
                'progress' => max(0, $progress),
                'platform' => 'android',
                'csrf' => $this->csrf(),
            ],
            'origin' => 'https://www.bilibili.com',
            'referer' => 'https://www.bilibili.com/video/av' . (string)($video['aid'] ?? ''),
        ]);
    }

    /** @return array<string,mixed> */
    public function shareVideo(int|string $video): array
    {
        $id = (string)$video;
        $body = ctype_digit($id) ? ['aid' => $id] : ['bvid' => $id];
        $body += [
            'csrf' => $this->csrf(),
            'source' => 'web_normal',
            'eab_x' => 2,
            'ramval' => 0,
            'ga' => 1,
        ];
        return $this->requestJson('POST', $this->api('/x/web-interface/share/add'), [
            'form_params' => $body,
            'origin' => 'https://www.bilibili.com',
            'referer' => 'https://www.bilibili.com/video/' . (ctype_digit($id) ? 'av' : '') . $id,
        ]);
    }

    /** @return array<string,mixed> */
    public function deviceFingerprint(): array
    {
        $response = $this->requestJson('GET', $this->api('/x/frontend/finger/spi'), [
            'with_cookies' => false,
            'referer' => 'https://www.bilibili.com/',
        ]);
        if (($response['code'] ?? -1) !== 0) {
            return $response;
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $buvid3 = (string)($data['b_3'] ?? '');
        if (!$this->session->has('buvid3') && $buvid3 !== '') {
            $this->session->merge(['buvid3' => $buvid3]);
        }
        $buvid4 = (string)($data['b_4'] ?? '');
        if ($buvid4 !== '') {
            $this->session->merge(['buvid4' => $buvid4]);
        }
        if (!$this->session->has('buvid3')) {
            return ['code' => -1, 'message' => 'bilibili sdk: fingerprint response missing buvid3'];
        }
        return $response;
    }

    /** @return array<string,mixed> */
    public function webTicket(): array
    {
        $timestamp = time();
        $response = $this->requestJson('POST', $this->api('/bapis/bilibili.api.ticket.v1.Ticket/GenWebTicket'), [
            'query' => [
                'key_id' => 'ec02',
                'hexsign' => hash_hmac('sha256', 'ts' . $timestamp, 'XgwSnGZ1p'),
                'context[ts]' => $timestamp,
                'csrf' => $this->csrf(),
            ],
            'referer' => $this->web('/'),
        ]);
        $ticket = (string)($response['data']['ticket'] ?? '');
        if (($response['code'] ?? -1) === 0 && $ticket !== '') {
            $ttl = max(1, (int)($response['data']['ttl'] ?? 259200));
            $this->session->merge([
                'bili_ticket' => $ticket,
                'bili_ticket_expires' => (string)($timestamp + $ttl),
            ]);
        }
        return $response;
    }

    /** @return array<string,mixed> */
    public function coinVideo(int|string $video, int $multiply = 1, bool $like = false): array
    {
        $deviceSession = $this->prepareWebDeviceSession();
        if (($deviceSession['code'] ?? -1) !== 0) {
            return $deviceSession;
        }

        $id = (string)$video;
        $body = ctype_digit($id) ? ['aid' => $id] : ['bvid' => $id];
        $body += [
            'multiply' => max(1, min(2, $multiply)),
            'select_like' => $like ? 1 : 0,
            'cross_domain' => 'true',
            'csrf' => $this->csrf(),
        ];
        return $this->requestJson('POST', $this->api('/x/web-interface/coin/add'), [
            'form_params' => $body,
            'origin' => 'https://www.bilibili.com',
            'referer' => 'https://www.bilibili.com/video/' . (ctype_digit($id) ? 'av' : '') . $id,
        ]);
    }

    /** @return array<string,mixed> */
    public function mangaClockIn(): array
    {
        return $this->requestJson('POST', $this->manga('/twirp/activity.v1.Activity/ClockIn'), [
            'form_params' => ['platform' => 'android'],
            'origin' => 'https://manga.bilibili.com',
            'referer' => 'https://manga.bilibili.com/',
        ]);
    }

    /** @return array<string,mixed> */
    public function mangaShare(): array
    {
        return $this->requestJson('POST', $this->manga('/twirp/activity.v1.Activity/ShareComic'), [
            'form_params' => ['platform' => 'android'],
            'origin' => 'https://manga.bilibili.com',
            'referer' => 'https://manga.bilibili.com/',
        ]);
    }

    /** @return array<string,mixed> */
    public function liveDailyBagPc(): array
    {
        return $this->legacyAppRequest('GET', '/gift/v2/live/receive_daily_bag');
    }

    /** @return array<string,mixed> */
    public function liveDailyBagApp(): array
    {
        return $this->legacyAppRequest('GET', '/AppBag/sendDaily');
    }

    /** @return array<string,mixed> */
    public function liveWebHeart(int|string $roomId): array
    {
        return $this->requestJson('POST', $this->live('/User/userOnlineHeart'), [
            'form_params' => [
                'csrf' => $this->csrf(),
                'csrf_token' => $this->csrf(),
                'room_id' => (string)$roomId,
                '_' => (int)round(microtime(true) * 1000),
            ],
            'referer' => 'https://live.bilibili.com/' . $roomId,
            'origin' => 'https://live.bilibili.com',
        ]);
    }

    /** @return array<string,mixed> */
    public function liveAppHeart(int|string $roomId): array
    {
        return $this->legacyAppRequest('POST', '/mobile/userOnlineHeart', ['room_id' => (string)$roomId]);
    }

    /** @return array<string,mixed> */
    public function liveGroupList(): array
    {
        return $this->legacyAppRequest('GET', 'https://api.vc.bilibili.com/link_group/v1/member/my_groups');
    }

    /** @return array<string,mixed> */
    public function liveGroupSign(array $group): array
    {
        return $this->legacyAppRequest('GET', 'https://api.vc.bilibili.com/link_setting/v1/link_setting/sign_in', [
            'group_id' => (string)($group['group_id'] ?? ''),
            'owner_id' => (string)($group['owner_uid'] ?? ''),
        ]);
    }

    /** @return array<string,mixed> */
    public function liveGiftHeart(int|string $roomId): array
    {
        return $this->legacyAppRequest('GET', '/gift/v2/live/heart_gift_receive', ['roomid' => (string)$roomId]);
    }

    /** @return array<string,mixed> */
    public function liveTaskInfo(): array
    {
        return $this->legacyAppRequest('GET', '/i/api/taskInfo');
    }

    /** @return array<string,mixed> */
    public function liveSignInfo(): array
    {
        return $this->requestJson('GET', $this->live('/xlive/web-ucenter/v1/sign/WebGetSignInfo'), [
            'referer' => 'https://live.bilibili.com/',
        ]);
    }

    /** @return array<string,mixed> */
    public function liveSign(): array
    {
        return $this->requestJson('GET', $this->live('/xlive/web-ucenter/v1/sign/DoSign'), [
            'referer' => 'https://live.bilibili.com/',
        ]);
    }

    /** @return array<string,mixed> */
    public function liveSilverToCoinApp(): array
    {
        return $this->legacyAppRequest('POST', '/AppExchange/silver2coin');
    }

    /** @return array<string,mixed> */
    public function liveSilverToCoinPc(): array
    {
        return $this->requestJson('POST', $this->live('/xlive/revenue/v1/wallet/silver2coin'), [
            'form_params' => [
                'csrf' => $this->csrf(),
                'csrf_token' => $this->csrf(),
                'visit_id' => '',
            ],
            'origin' => 'https://live.bilibili.com',
            'referer' => 'https://live.bilibili.com/',
        ]);
    }

    public function csrf(): string
    {
        return $this->session->get('bili_jct');
    }

    public function cookieHeader(): string
    {
        return $this->session->header();
    }

    /** @return array<string,string> */
    public function cookies(): array
    {
        return $this->session->all();
    }

    public function authenticated(): bool
    {
        return $this->session->authenticated();
    }

    public function isAuthenticationFailure(array $response): bool
    {
        $code = $response['code'] ?? null;
        return $code === -101 || $code === -111 || $code === '-101' || $code === '-111' || $code === 'unauthenticated';
    }

    /** @return array<string,mixed> */
    private function wbiGet(string $path, array $params): array
    {
        $keys = $this->wbiKeys();
        if ($keys === null) {
            return ['code' => -1, 'message' => 'bilibili sdk: WBI keys unavailable'];
        }
        return $this->requestJson('GET', $this->api($path), [
            'query' => $this->wbiSigner->sign($params, $keys['img_key'], $keys['sub_key']),
            'referer' => 'https://www.bilibili.com/',
        ]);
    }

    /** @return array{img_key:string,sub_key:string}|null */
    private function wbiKeys(): ?array
    {
        if ($this->wbiKeys !== null) {
            return $this->wbiKeys;
        }
        $nav = $this->nav();
        $imgUrl = (string)($nav['data']['wbi_img']['img_url'] ?? '');
        $subUrl = (string)($nav['data']['wbi_img']['sub_url'] ?? '');
        $imgKey = $this->fileToken($imgUrl);
        $subKey = $this->fileToken($subUrl);
        if ($imgKey === '' || $subKey === '') {
            return null;
        }
        $this->wbiKeys = ['img_key' => $imgKey, 'sub_key' => $subKey];
        return $this->wbiKeys;
    }

    /** @return array<string,mixed> */
    private function legacyAppRequest(string $method, string $pathOrUrl, array $params = []): array
    {
        $url = str_starts_with($pathOrUrl, 'http') ? $pathOrUrl : $this->live($pathOrUrl);
        if (strtoupper($method) === 'POST' && $this->csrf() !== '') {
            $params += [
                'csrf' => $this->csrf(),
                'csrf_token' => $this->csrf(),
            ];
        }
        $signed = $this->appSigner->sign($params, (string)$this->config['access_key']);
        $options = [
            strtoupper($method) === 'GET' ? 'query' : 'form_params' => $signed,
            'referer' => 'https://live.bilibili.com/',
        ];
        if (strtoupper($method) === 'POST') {
            $options['origin'] = 'https://live.bilibili.com';
        }
        return $this->requestJson($method, $url, $options);
    }

    /** @return array<string,mixed> */
    private function prepareWebDeviceSession(): array
    {
        if (!$this->session->has('buvid3') || !$this->session->has('b_nut')) {
            $this->rawRequest('GET', $this->web('/'), [
                'with_cookies' => false,
                'headers' => [
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                ],
            ]);
        }

        if (!$this->session->has('buvid3') || !$this->session->has('buvid4')) {
            $fingerprint = $this->deviceFingerprint();
            if (($fingerprint['code'] ?? -1) !== 0 && !$this->session->has('buvid3')) {
                return $fingerprint;
            }
        }
        if (!$this->session->has('buvid3')) {
            return ['code' => -1, 'message' => 'bilibili sdk: valid buvid3 is unavailable'];
        }

        if (!$this->session->has('bili_ticket')) {
            $this->webTicket();
        }
        return ['code' => 0, 'message' => '0'];
    }

    private function fileToken(string $url): string
    {
        $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        return pathinfo($path, PATHINFO_FILENAME);
    }

    private function hasHeader(array $headers, string $name): bool
    {
        foreach (array_keys($headers) as $header) {
            if (strcasecmp((string)$header, $name) === 0) {
                return true;
            }
        }
        return false;
    }

    private function api(string $path): string
    {
        return rtrim((string)$this->config['api_domain'], '/') . $path;
    }

    private function web(string $path): string
    {
        return rtrim((string)$this->config['web_domain'], '/') . $path;
    }

    private function passport(string $path): string
    {
        return rtrim((string)$this->config['passport_domain'], '/') . $path;
    }

    private function live(string $path): string
    {
        return rtrim((string)$this->config['live_domain'], '/') . $path;
    }

    private function manga(string $path): string
    {
        return rtrim((string)$this->config['manga_domain'], '/') . $path;
    }

    private function dynamic(string $path): string
    {
        return rtrim((string)$this->config['dynamic_domain'], '/') . $path;
    }

    private function jsonEncode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
