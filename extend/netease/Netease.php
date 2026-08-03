<?php

namespace netease;

use netease\sdk\Client as CloudMusicClient;
use netease\sdk\Ncbl;

/**
 * NetEase Cloud Music client.
 *
 * The protocol implementation follows NeteaseCloudMusicApiEnhanced/api-enhanced
 * (commit 8f4873f2e2f677153d398a62d9ca0e3826c3f86d).  The public methods intentionally
 * retain the legacy class API because the scheduler and the web console call
 * them directly.
 */
class Netease
{
    public $cookiezt = false;

    protected $musician_song_id;
    protected $_MINI_MODE = false;

    protected $userId;
    protected $csrf;
    protected $musicu;
    protected $config;
    protected $cookie;
    protected $sdk;
    protected $lastScrobbleStarts = 0;
    protected $lastScrobbleSeconds = 0;
    protected $lastScrobbleSongIds = [];
    protected $lastScrobbleElapsedSeconds = 0.0;

    protected $resourceTypeMap = [
        0 => 'R_SO_4_',
        1 => 'R_MV_5_',
        2 => 'A_PL_0_',
        3 => 'R_AL_3_',
        4 => 'A_DJ_1_',
        5 => 'R_VI_62_',
        6 => 'A_EV_2_',
        7 => 'A_DR_14_',
    ];

    public function __construct($userId = null, $csrf = null, $musicu = null, $config = [], $sdkClient = null)
    {
        $this->userId = $userId;
        $this->csrf = (string)($csrf ?? '');
        $this->musicu = (string)($musicu ?? '');
        $this->config = is_array($config) ? $config : [];
        $sdkConfig = is_array($this->config['sdk'] ?? null) ? $this->config['sdk'] : [];
        if (function_exists('config') && config('sys.is_netease_proxy') == 1) {
            $sdkConfig['proxy_url'] = 'http://forward.xdaili.cn:80';
            $sdkConfig['proxy_order_no'] = (string)config('sys.netease_proxy_orderno');
            $sdkConfig['proxy_secret'] = (string)config('sys.netease_proxy_secret');
        }
        $this->sdk = $sdkClient instanceof CloudMusicClient
            ? $sdkClient
            : new CloudMusicClient([
                'user_id' => $userId,
                'csrf' => $this->csrf,
                'music_u' => $this->musicu,
            ], $sdkConfig);
        $this->cookie = $this->sdk->sessionCookie();
    }

    /**
     * Backward-compatible raw HTTP helper.
     */
    protected function curl(
        string $method = '',
        string $url = '',
        array $params = [],
        string $cookie = null,
        string $os = null,
        bool $proxy = false,
        array $header = [],
        array $json = [],
        array $multipart = []
    ): array {
        return $this->rawRequest($method ?: 'GET', $url, [
            'params' => $params,
            'cookie' => $cookie,
            'os' => $os,
            'proxy' => $proxy,
            'headers' => $header,
            'json' => $json,
            'multipart' => $multipart,
        ]);
    }

    protected function rawRequest(string $method, string $url, array $options = []): array
    {
        return $this->sdk->rawRequest($method, $url, $options);
    }

    protected function requestApi(
        string $uri,
        array $data = [],
        string $crypto = 'eapi',
        array $options = []
    ): array {
        return $this->sdk->request($uri, $data, $crypto, $options);
    }

    protected function responseCookies(array $response): array
    {
        $cookies = [];
        foreach ($response['set_cookie'] ?? [] as $line) {
            if (preg_match('/^([^=;]+)=([^;]*)/', $line, $match)) {
                $cookies[$match[1]] = $match[2];
            }
        }
        return $cookies;
    }

    protected function decodeBody(array $response): array
    {
        $body = json_decode((string)($response['body'] ?? ''), true);
        return is_array($body) ? $body : [];
    }

    protected function jsonEncode($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? '{}' : $json;
    }

    protected function makeResult(int $code, string $message, $data = null): array
    {
        if (function_exists('resultArray')) {
            return resultArray($code, $message, $data);
        }
        $result = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $result['data'] = $data;
        }
        return $result;
    }

    public function randIP()
    {
        return random_int(36, 223) . '.' . random_int(1, 254) . '.' . random_int(1, 254) . '.' . random_int(1, 254);
    }

    private function generateRandomString($length = 4, string $alphabet = '0123456789abcdef')
    {
        $value = '';
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < $length; $i++) {
            $value .= $alphabet[random_int(0, $max)];
        }
        return $value;
    }

    public function getRandNumber($start = 0, $end = 9, $length = 8)
    {
        $numbers = range($start, $end);
        shuffle($numbers);
        return implode('', array_slice($numbers, 0, $length));
    }

    public function login(string $cell, string $pwd, string $countrycode = '86')
    {
        $response = $this->requestApi('/api/w/login/cellphone', [
            'type' => '1',
            'https' => 'true',
            'phone' => $cell,
            'countrycode' => $countrycode,
            'password' => $pwd,
            'remember' => 'true',
            'secureCaptcha' => '',
        ], 'weapi', ['cookie' => '', 'os' => 'android', 'proxy' => true]);
        return $this->loginResult($response);
    }

    public function loginByEmail(string $cell, string $pwd): array
    {
        $response = $this->requestApi('/api/w/login', [
            'type' => '0',
            'https' => 'true',
            'username' => $cell,
            'password' => $pwd,
            'rememberLogin' => 'true',
        ], 'eapi', ['cookie' => '', 'proxy' => true]);
        return $this->loginResult($response);
    }

    protected function loginResult(array $response): array
    {
        $body = $this->decodeBody($response);
        if (($body['code'] ?? 0) !== 200) {
            return $this->makeResult(202, (string)($body['message'] ?? $body['msg'] ?? '登录失败'));
        }
        $cookies = $this->responseCookies($response);
        $profile = $body['profile'] ?? [];
        if (empty($profile)) {
            $sessionCookie = $this->sdk->serializeCookie($cookies);
            $status = $this->decodeBody($this->getInfo($sessionCookie));
            $profile = $status['profile'] ?? [];
        }
        if (empty($profile['userId']) || empty($cookies['MUSIC_U'])) {
            return $this->makeResult(201, '登录成功，但未获取到完整会话信息');
        }
        return $this->makeResult(200, '登录成功', [
            'user_id' => $profile['userId'],
            'nickname' => $profile['nickname'] ?? '',
            'avatar' => $profile['avatarUrl'] ?? '',
            'musicu' => $cookies['MUSIC_U'],
            'csrf' => $cookies['__csrf'] ?? '',
        ]);
    }

    protected function get_curl(
        string $url,
        $data = null,
        string $cookie = null,
        string $os = 'pc',
        bool $proxy = false,
        array $header = []
    ) {
        $options = [
            'params' => is_array($data) ? $data : [],
            'cookie' => $cookie,
            'os' => $os,
            'proxy' => $proxy,
            'headers' => $header,
        ];
        if (is_string($data)) {
            $options['body'] = $data;
        }
        return $this->rawRequest($data === null ? 'GET' : 'POST', $url, $options);
    }

    public function get_qr_key()
    {
        $body = $this->decodeBody($this->requestApi('/api/login/qrcode/unikey', ['type' => 3], 'eapi', ['cookie' => '']));
        return $body['unikey'] ?? ($body['data']['unikey'] ?? '');
    }

    public function qrLogin($key)
    {
        $response = $this->requestApi('/api/login/qrcode/client/login', [
            'key' => $key,
            'type' => 3,
        ], 'eapi', ['cookie' => '']);
        return $this->resolveQrResponse($response, false);
    }

    /**
     * Poll the 8810 security-verify QR. Same endpoint as qrLogin, but the unikey
     * comes from the risk-control `toast` of a prior 8810 response. The app must
     * already be logged into the account being verified.
     *
     * Mirrors yun_tool's `qr_check` against `/api/login/qrcode/client/login`:
     * 801 waiting -> 802 scanned -> 803 success; 800 expired.
     */
    public function qrCheckVerify($verifyUnikey)
    {
        $response = $this->requestApi('/api/login/qrcode/client/login', [
            'key' => (string)$verifyUnikey,
            'type' => 3,
        ], 'eapi', ['cookie' => '']);
        return $this->resolveQrResponse($response, true);
    }

    /**
     * Shared resolver for the qrcode/client/login endpoint.
     *
     * $isVerify switches the waiting/scanned copy so the verify flow tells the
     * user to scan the *verify* QR (already-logged-in app) rather than the login QR.
     */
    protected function resolveQrResponse(array $response, bool $isVerify): array
    {
        $body = $this->decodeBody($response);
        $code = (int)($body['code'] ?? 0);

        // 8810 risk control: "您当前的网络环境存在安全风险". When toast.type==1 the
        // toast carries a `unikey` for a second security-verify QR — scanning it
        // with an already-logged-in app clears the gate (yun_tool raise_phone_security_error).
        if ($code === 8810) {
            $toast = is_array($body['toast'] ?? null) ? $body['toast'] : [];
            $verifyUnikey = (string)($toast['unikey'] ?? '');
            if ($verifyUnikey !== '' && (int)($toast['type'] ?? 0) === 1) {
                return $this->makeResult(8810, (string)($body['message'] ?? '当前网络环境存在风险，请扫码完成安全验证'), [
                    'verify_unikey' => $verifyUnikey,
                    'verify_qrurl' => 'https://music.163.com/login?codekey=' . rawurlencode($verifyUnikey),
                    'nickname' => (string)($toast['nickname'] ?? ''),
                ]);
            }
            // 8810 without a verify unikey cannot be auto-resolved server-side.
            return $this->makeResult(8810, (string)($body['message'] ?? '当前网络环境存在风险，请稍后重试或更换网络'));
        }

        if ($code === 803) {
            $cookies = $this->responseCookies($response);
            $cookie = $this->sdk->serializeCookie($cookies);
            $status = $this->decodeBody($this->getInfo($cookie));
            $profile = $status['profile'] ?? ($status['account'] ?? []);
            if (!empty($profile['userId']) && !empty($cookies['MUSIC_U'])) {
                return $this->makeResult(200, '登录成功', [
                    'user_id' => $profile['userId'],
                    'nickname' => $profile['nickname'] ?? '',
                    'avatar' => $profile['avatarUrl'] ?? '',
                    'musicu' => $cookies['MUSIC_U'],
                    'csrf' => $cookies['__csrf'] ?? '',
                ]);
            }
            return $this->makeResult(0, '获取用户个人信息失败');
        }
        if ($code === 801) {
            return $this->makeResult(-1, $isVerify
                ? '请使用网易云音乐APP扫描验证二维码'
                : '请使用网易云音乐APP扫描二维码');
        }
        if ($code === 802) {
            return $this->makeResult(-1, $isVerify
                ? '验证二维码已扫描，请在APP中确认'
                : '二维码已扫描，请在APP中确认登录');
        }
        if ($code === 800) {
            return $this->makeResult(800, $isVerify ? '验证二维码已过期，请重新获取' : '请重新获取二维码');
        }
        return $this->makeResult($code, (string)($body['message'] ?? '二维码登录失败'));
    }

    public function getInfo($cookie)
    {
        return $this->requestApi('/api/w/nuser/account/get', [], 'weapi', ['cookie' => (string)$cookie]);
    }

    public function detail($uid)
    {
        return $this->requestApi('/api/v1/user/detail/' . (string)$uid, [], 'weapi');
    }

    public function level()
    {
        return $this->requestApi('/api/user/level', [], 'weapi');
    }

    public function getMusicUserInfo()
    {
        $musicInfo = $this->decodeBody($this->detail($this->userId));
        $levelInfo = $this->decodeBody($this->level());
        if (($levelInfo['code'] ?? 0) !== 200) {
            return false;
        }
        foreach (['nextLoginCount', 'nowLoginCount', 'nextPlayCount', 'nowPlayCount'] as $key) {
            $musicInfo[$key] = $levelInfo['data'][$key] ?? 0;
        }
        if (!isset($musicInfo['level'])) {
            $musicInfo['level'] = $levelInfo['data']['level'] ?? 0;
        }
        return $musicInfo;
    }

    public function login_work()
    {
        $status = $this->decodeBody($this->getInfo($this->cookie));
        if (($status['code'] ?? 0) === 301) {
            $this->cookiezt = true;
            return $this->makeResult(201, '登录状态已失效');
        }
        return $this->makeResult(200, '每日登录成功');
    }

    public function sign()
    {
        $body = $this->decodeBody($this->requestApi('/api/point/dailyTask', ['type' => 1], 'eapi', ['os' => 'pc']));
        if (($body['code'] ?? 0) === 301) {
            $this->cookiezt = true;
            return $this->makeResult(201, '登录状态已失效');
        }
        if (($body['code'] ?? 0) === -2) {
            return $this->makeResult(200, '今日已签到，无需重复执行');
        }
        return $this->makeResult(($body['code'] ?? 0) === 200 ? 200 : 201, (string)($body['message'] ?? $body['msg'] ?? '每日签到完成'));
    }

    public function personalized($limit)
    {
        $body = $this->decodeBody($this->requestApi('/api/personalized/playlist', [
            'limit' => (int)$limit,
            'total' => true,
            'n' => 1000,
        ], 'weapi'));
        $ids = [];
        foreach ($body['result'] ?? [] as $playlist) {
            if (isset($playlist['id'])) {
                $ids[] = $playlist['id'];
            }
        }
        return $ids;
    }

    public function getsongid($playlist_id)
    {
        $body = $this->playlist_detail($playlist_id);
        return $body['playlist']['trackIds'] ?? [];
    }

    public function get_search_playlist($keywords = '冷门', $type = 1000, $limit = 100)
    {
        $terms = ['冷门', '小众', '没人听的', '高级感vlog', '无人', '器乐', '后摇', '独立'];
        $keyword = $keywords === '冷门' ? $terms[array_rand($terms)] : $keywords;
        $body = $this->decodeBody($this->requestApi('/api/cloudsearch/pc', [
            's' => $keyword,
            'type' => $type,
            'limit' => $limit,
            'offset' => random_int(0, 10) * max(1, (int)$limit),
            'total' => true,
        ], 'eapi'));
        return $this->playlistIdsFromSearch($body);
    }

    public function get_search_playlist2($keywords = '冷门', $type = 1000, $limit = 50): array
    {
        $keyword = $keywords === '冷门' ? $this->generateRandomString(2) : $keywords;
        $body = $this->decodeBody($this->requestApi('/api/cloudsearch/pc', [
            's' => $keyword,
            'type' => $type,
            'limit' => $limit,
            'offset' => 0,
            'total' => true,
        ], 'eapi'));
        $ids = $this->playlistIdsFromSearch($body);
        return $ids ?: $this->personalized($limit);
    }

    /** @return array<int,array{id:int,sourceId:int,time:int}> */
    public function search_songs(
        string $keywords,
        int $limit = 100,
        int $offset = 0,
        ?string $preferredArtist = null
    ): array
    {
        $body = $this->decodeBody($this->requestApi('/api/cloudsearch/pc', [
            's' => $keywords,
            'type' => 1,
            'limit' => max(1, min(100, $limit)),
            'offset' => max(0, $offset),
            'total' => true,
        ], 'eapi'));
        $songs = [];
        foreach ($body['result']['songs'] ?? [] as $song) {
            $id = (int)($song['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if ($preferredArtist !== null) {
                $matched = false;
                foreach (($song['ar'] ?? $song['artists'] ?? []) as $artist) {
                    $name = trim((string)($artist['name'] ?? ''));
                    if ($name !== '' && str_contains($name, $preferredArtist)) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    continue;
                }
            }
            $songs[] = [
                'id' => $id,
                'sourceId' => 0,
                'time' => max(30, (int)ceil(($song['dt'] ?? $song['duration'] ?? 240000) / 1000)),
            ];
        }
        return $songs;
    }

    protected function playlistIdsFromSearch(array $body): array
    {
        $ids = [];
        foreach ($body['result']['playlists'] ?? [] as $playlist) {
            if (isset($playlist['id'])) {
                $ids[] = $playlist['id'];
            }
        }
        return $ids;
    }

    public function get_highquality_playlist($limit, $before = 0)
    {
        $tags = ['全部', '华语', '欧美', '韩语', '日语', '粤语', '小语种', '运动', 'ACG', '影视原声', '流行', '摇滚', '后摇', '古风', '民谣', '轻音乐', '电子', '器乐', '说唱', '古典', '爵士'];
        $body = $this->decodeBody($this->requestApi('/api/playlist/highquality/list', [
            'cat' => $tags[array_rand($tags)],
            'limit' => (int)$limit,
            'lasttime' => (int)$before,
            'total' => true,
        ], 'weapi'));
        $ids = [];
        foreach ($body['playlists'] ?? [] as $playlist) {
            if (isset($playlist['id'])) {
                $ids[] = $playlist['id'];
            }
        }
        return $ids;
    }

    public function playlist_detail($playlist_id)
    {
        return $this->decodeBody($this->requestApi('/api/v6/playlist/detail', [
            'id' => $playlist_id,
            'n' => 100000,
            's' => 8,
        ], 'eapi'));
    }

    public function getCacheKey($params)
    {
        $keys = array_keys($params);
        sort($keys, SORT_STRING);
        $record = [];
        foreach ($keys as $key) {
            $record[$key] = $params[$key];
        }
        $plain = http_build_query($record);
        $block = 16 - (strlen($plain) % 16);
        $plain .= str_repeat("\0", $block === 16 ? 0 : $block);
        return base64_encode(openssl_encrypt($plain, 'AES-128-ECB', ')(13daqP@ssw0rd~', OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING));
    }

    public function recommand_songs()
    {
        return $this->decodeBody($this->requestApi('/api/v3/discovery/recommend/songs', [], 'weapi'));
    }

    /** @return array<int,array{id:int,sourceId:int,time:int}> */
    public function daily_recommend_songs(): array
    {
        $body = $this->recommand_songs();
        $items = $body['data']['dailySongs'] ?? ($body['recommend'] ?? []);
        $songs = [];
        foreach (is_array($items) ? $items : [] as $item) {
            $song = is_array($item['song'] ?? null) ? $item['song'] : $item;
            $id = (int)($song['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $songs[] = [
                'id' => $id,
                'sourceId' => 0,
                'time' => max(30, (int)ceil(($song['dt'] ?? $song['duration'] ?? 240000) / 1000)),
            ];
        }
        return $songs;
    }

    public function recommend_playlist()
    {
        $body = $this->decodeBody($this->requestApi('/api/v1/discovery/recommend/resource', [], 'weapi'));
        $ids = [];
        foreach ($body['recommend'] ?? [] as $playlist) {
            if (isset($playlist['id'])) {
                $ids[] = $playlist['id'];
            }
        }
        return $ids;
    }

    public function personalized_playlist($limit)
    {
        $body = $this->decodeBody($this->requestApi('/api/personalized/playlist', [
            'limit' => (int)$limit,
            'total' => true,
            'n' => 1000,
        ], 'weapi'));
        return $body['result'] ?? [];
    }

    public function get_new_songs()
    {
        $body = $this->decodeBody($this->requestApi('/api/personalized/newsong', [
            'type' => 'recommend',
            'limit' => 100,
            'areaId' => 0,
        ], 'weapi'));
        return $body['result'] ?? ($body['data'] ?? []);
    }

    public function get_songs_detail($songids)
    {
        $ids = preg_split('/\s*,\s*/', (string)$songids, -1, PREG_SPLIT_NO_EMPTY);
        $items = [];
        foreach ($ids as $id) {
            $items[] = ['id' => (int)$id];
        }
        return $this->requestApi('/api/v3/song/detail', ['c' => $this->jsonEncode($items)], 'weapi');
    }

    protected function scrobbleSong($songId, $sourceId, $time): bool
    {
        return $this->scrobbleBatch([[
            'id' => (int)$songId,
            'sourceId' => (int)$sourceId,
            'time' => max(1, (int)$time),
        ]]) === 1;
    }

    /**
     * Apply the current desktop-client PLV/PLD protocol. Each phase is uploaded
     * as an encrypted NCBL file, and a phase counts only when the response names
     * that exact file in data.successfiles.
     */
    protected function scrobbleBatch(array $songs): int
    {
        $startedAt = microtime(true);
        $this->lastScrobbleStarts = 0;
        $this->lastScrobbleSeconds = 0;
        $this->lastScrobbleSongIds = [];
        $this->lastScrobbleElapsedSeconds = 0.0;
        $songs = array_values(array_filter($songs, static fn($song): bool =>
            is_array($song) && (int)($song['id'] ?? 0) > 0
        ));
        if ($songs === []) {
            return 0;
        }

        $context = $this->sdk->desktopLogContext();
        if ((string)($context['auth']['token'] ?? '') === '') {
            $this->cookiezt = true;
            return 0;
        }
        $metaJson = Ncbl::buildMetaJson($context);
        $concurrency = max(1, min(16, (int)($this->config['daka_concurrency'] ?? 8)));
        $success = 0;
        foreach (array_chunk($songs, $concurrency, true) as $chunk) {
            $plvRequests = [];
            $plvFiles = [];
            $preparedSongs = [];
            foreach ($chunk as $index => $song) {
                $songId = (int)$song['id'];
                $duration = max(1, (int)($song['time'] ?? 240));
                $sourceId = (int)($song['sourceId'] ?? 0) > 0
                    ? (string)(int)$song['sourceId']
                    : (string)$songId;
                $logSong = [
                    'id' => $songId,
                    'bitrate' => max(1, (int)($song['bitrate'] ?? 320)),
                    'level' => (string)($song['level'] ?? 'exhigh'),
                    'vip' => !empty($song['vip']),
                    'time' => $duration,
                ];
                $source = [
                    'id' => $sourceId,
                    'type' => 'track',
                    'name' => (string)($song['source'] ?? 'list'),
                ];
                $timestamp = time();
                $nowMs = (int)round(microtime(true) * 1000);
                $plvBody = Ncbl::buildRecords([[
                    'time' => $timestamp,
                    'action' => '_plv',
                    'data' => Ncbl::buildPlv($context, $logSong, $source, $nowMs),
                ]]);
                $upload = $this->prepareNcblUpload($context, $metaJson, $plvBody);
                $plvRequests[$index] = $upload['request'];
                $plvFiles[$index] = $upload['fileName'];
                $preparedSongs[$index] = [
                    'song' => $logSong,
                    'source' => $source,
                    'timestamp' => $timestamp,
                    'nowMs' => $nowMs,
                ];
            }

            $plvResponses = $this->sdk->rawRequestMany($plvRequests, $concurrency);
            $pldRequests = [];
            $pldFiles = [];
            foreach ($preparedSongs as $index => $prepared) {
                $response = $plvResponses[$index] ?? [];
                $this->captureNcblAuthFailure($response);
                if (!Ncbl::uploadAccepted($response, (string)$plvFiles[$index])) {
                    continue;
                }
                $this->lastScrobbleStarts++;
                $logSong = $prepared['song'];
                $source = $prepared['source'];
                $duration = max(1, (int)$logSong['time']);
                $pldBody = Ncbl::buildRecords([[
                    'time' => (int)$prepared['timestamp'],
                    'action' => '_pld',
                    'data' => Ncbl::buildPld(
                        $context,
                        $logSong,
                        $source,
                        $duration,
                        (int)round(microtime(true) * 1000)
                    ),
                ]]);
                $upload = $this->prepareNcblUpload($context, $metaJson, $pldBody);
                $pldRequests[$index] = $upload['request'];
                $pldFiles[$index] = $upload['fileName'];
            }

            if ($pldRequests === []) {
                continue;
            }
            $pldResponses = $this->sdk->rawRequestMany($pldRequests, $concurrency);
            foreach ($pldRequests as $index => $_request) {
                $response = $pldResponses[$index] ?? [];
                $this->captureNcblAuthFailure($response);
                if (!Ncbl::uploadAccepted($response, (string)$pldFiles[$index])) {
                    continue;
                }
                $song = $preparedSongs[$index]['song'];
                $success++;
                $this->lastScrobbleSeconds += max(1, (int)($song['time'] ?? 240));
                $this->lastScrobbleSongIds[] = (int)$song['id'];
            }
        }
        $this->lastScrobbleElapsedSeconds = microtime(true) - $startedAt;
        return $success;
    }

    /**
     * Reproduce api-enhanced/module/scrobble.js for the daily unique-song task.
     * The two weblog phases are submitted immediately; the `time` value is only
     * a protocol field and this method never waits for real playback.
     *
     * api-enhanced submits exactly one log per request. Keep that wire shape for
     * compatibility, but send the independent requests concurrently so a normal
     * 300-song run reports immediately instead of simulating playback time.
     */
    protected function weblogScrobbleBatch(array $songs): int
    {
        $startedAt = microtime(true);
        $this->lastScrobbleStarts = 0;
        $this->lastScrobbleSeconds = 0;
        $this->lastScrobbleSongIds = [];
        $this->lastScrobbleElapsedSeconds = 0.0;
        $songs = array_values(array_filter($songs, static fn($song): bool =>
            is_array($song) && (int)($song['id'] ?? 0) > 0
        ));
        if ($songs === []) {
            return 0;
        }

        $concurrency = max(1, min(16, (int)($this->config['daka_concurrency'] ?? 8)));
        $options = [
            'domain' => 'https://clientlog.music.163.com',
            'os' => 'osx',
            'timeout' => max(2.0, min(30.0, (float)($this->config['daka_timeout'] ?? 8.0))),
            'connect_timeout' => max(1.0, min(15.0, (float)($this->config['daka_connect_timeout'] ?? 4.0))),
        ];
        $success = 0;
        foreach (array_chunk($songs, $concurrency, true) as $chunk) {
            $startRequests = [];
            $playRequests = [];
            foreach ($chunk as $index => $song) {
                // api-enhanced receives HTTP query values, so these identifiers
                // are strings in the upstream JSON payload as well.
                $songId = (string)(int)$song['id'];
                $sourceId = (string)(int)($song['sourceId'] ?? 0);
                $playedSeconds = max(1, (int)($song['time'] ?? 240));
                $startRequests[$index] = [
                    'uri' => '/api/feedback/weblog',
                    'data' => [
                        'logs' => $this->jsonEncode([[
                            'action' => 'startplay',
                            'json' => [
                                'id' => $songId,
                                'type' => 'song',
                                'mainsite' => '1',
                                'mainsiteWeb' => '1',
                                'content' => 'id=' . $sourceId,
                            ],
                        ]]),
                    ],
                    'crypto' => 'eapi',
                    'options' => $options,
                ];
                $playRequests[$index] = [
                    'uri' => '/api/feedback/weblog',
                    'data' => [
                        'logs' => $this->jsonEncode([[
                            'action' => 'play',
                            'json' => [
                                'download' => 0,
                                'end' => 'playend',
                                'id' => $songId,
                                'sourceId' => $sourceId,
                                'time' => (string)$playedSeconds,
                                'type' => 'song',
                                'wifi' => 0,
                                'source' => 'list',
                                'mainsite' => '1',
                                'mainsiteWeb' => '1',
                                'content' => 'id=' . $sourceId,
                            ],
                        ]]),
                    ],
                    'crypto' => 'eapi',
                    'options' => $options,
                ];
            }

            $startResponses = $this->sendDakaWeblogMany($startRequests, $concurrency);
            foreach ($startResponses as $response) {
                if ($this->isDakaWeblogAccepted($response)) {
                    $this->lastScrobbleStarts++;
                }
            }

            // Upstream always sends play after startplay, even if the first
            // response is rejected. Preserve that behavior for compatibility.
            $playResponses = $this->sendDakaWeblogMany($playRequests, $concurrency);
            foreach ($chunk as $index => $song) {
                if (!$this->isDakaWeblogAccepted($playResponses[$index] ?? [])) {
                    continue;
                }
                $success++;
                $this->lastScrobbleSeconds += max(1, (int)($song['time'] ?? 240));
                $this->lastScrobbleSongIds[] = (int)$song['id'];
            }

            if ($this->cookiezt) {
                break;
            }
        }

        $this->lastScrobbleElapsedSeconds = microtime(true) - $startedAt;
        return $success;
    }

    /**
     * @param array<int|string,array{uri:string,data:array,crypto:string,options:array}> $requests
     * @return array<int|string,array>
     */
    private function sendDakaWeblogMany(array $requests, int $concurrency): array
    {
        $responses = $this->sdk->requestMany($requests, $concurrency);
        $retryRequests = [];
        foreach ($requests as $index => $request) {
            $body = $this->decodeBody($responses[$index] ?? []);
            $code = (int)($body['code'] ?? 0);
            if (in_array($code, [301, 401], true)) {
                $this->cookiezt = true;
            } elseif ($code !== 200) {
                $retryRequests[$index] = $request;
            }
        }

        if ($retryRequests !== []) {
            $retried = $this->sdk->requestMany($retryRequests, $concurrency);
            foreach ($retryRequests as $index => $_request) {
                $responses[$index] = $retried[$index] ?? [];
                $code = (int)($this->decodeBody($responses[$index])['code'] ?? 0);
                if (in_array($code, [301, 401], true)) {
                    $this->cookiezt = true;
                }
            }
        }

        return $responses;
    }

    private function isDakaWeblogAccepted(array $response): bool
    {
        return (int)($this->decodeBody($response)['code'] ?? 0) === 200;
    }

    /**
     * @param array<string,mixed> $context
     * @return array{request:array{method:string,url:string,options:array},fileName:string}
     */
    private function prepareNcblUpload(array $context, string $metaJson, string $body): array
    {
        $payload = Ncbl::encrypt($metaJson, $body);
        $multipart = Ncbl::buildMultipart($payload);
        return [
            'request' => [
                'method' => 'POST',
                'url' => Ncbl::UPLOAD_URL,
                'options' => [
                    'cookie' => '',
                    'headers' => Ncbl::uploadHeaders($context, $multipart['boundary']),
                    'body' => $multipart['body'],
                    'timeout' => (float)($this->config['daka_timeout'] ?? 15.0),
                    'connect_timeout' => (float)($this->config['daka_connect_timeout'] ?? 8.0),
                ],
            ],
            'fileName' => $multipart['fileName'],
        ];
    }

    private function captureNcblAuthFailure(array $response): void
    {
        $body = json_decode((string)($response['body'] ?? ''), true);
        $code = is_array($body) ? (int)($body['code'] ?? 0) : 0;
        if (in_array($code, [301, 401], true)) {
            $this->cookiezt = true;
        }
    }

    /** @return array<int,array{id:int,sourceId:int,time:int}> */
    protected function dakaSongs(string $source, array $history, int $limit = 300): array
    {
        $songs = [];
        if ($source === 'daily_recommend') {
            // Fill the daily target with the account's own recommendations
            // first, then fresh chart releases. NetEase only counts songs the
            // account has never heard before, so brand-new releases are far
            // more valuable than popular charts for high-history accounts.
            $this->appendSearchSongs($songs, $this->daily_recommend_songs(), $history, $limit);
            $this->appendPlaylistSongs($songs, [3779629], $history, $limit);
            $this->appendSearchSongs($songs, $this->get_new_songs(), $history, $limit);
            $this->appendPlaylistSongs($songs, $this->recommend_playlist(), $history, $limit);

            $this->appendPopularDakaSongs($songs, $history, $limit);
            return $songs;
        }

        if (in_array($source, ['personalized', 'highquality'], true)) {
            $playlists = $source === 'highquality'
                ? $this->get_highquality_playlist(50)
                : $this->personalized(50);
            $this->appendPlaylistSongs($songs, $playlists, $history, min($limit, 140));
        }

        $this->appendPopularDakaSongs($songs, $history, $limit);

        return $songs;
    }

    /** @return array<int,array{id:int,sourceId:int,time:int}> */
    protected function dakaSupplementSongs(array $history, int $limit = 300): array
    {
        $songs = [];
        $year = date('Y');

        // Later batches must leave the first-run pool. New-release charts and
        // search playlists are the most reliable source of genuinely unheard
        // songs for accounts with a long listening history.
        $this->appendPlaylistSongs($songs, [3779629], $history, $limit);
        if (count($songs) >= $limit) {
            return $songs;
        }
        $this->appendSearchSongs($songs, $this->get_new_songs(), $history, $limit);
        if (count($songs) >= $limit) {
            return $songs;
        }

        foreach ([
            $year . '华语新歌',
            $year . '新歌速递',
            $year . '新歌首发',
            '本周新歌',
            '新歌榜',
            '原创歌曲',
            '小众新歌',
            '冷门宝藏歌曲',
        ] as $term) {
            $this->appendPlaylistSongs(
                $songs,
                $this->get_search_playlist($term, 1000, 30),
                $history,
                $limit
            );
            if (count($songs) >= $limit) {
                return $songs;
            }
        }

        $this->appendSearchSongs($songs, $this->get_new_songs(), $history, $limit);
        if (count($songs) >= $limit) {
            return $songs;
        }

        foreach ([19723756, 2884035, 3778678] as $chartPlaylistId) {
            $this->appendPlaylistSongs($songs, [$chartPlaylistId], $history, $limit);
            if (count($songs) >= $limit) {
                return $songs;
            }
        }

        foreach (['华语新声', '宝藏华语', '独立音乐', '影视新歌', '国风新歌', '新歌首发', '每日新歌'] as $term) {
            foreach ([0, 100, 200, 300] as $offset) {
                $this->appendSearchSongs(
                    $songs,
                    $this->search_songs($term, 100, $offset),
                    $history,
                    $limit
                );
                if (count($songs) >= $limit) {
                    return $songs;
                }
            }
        }

        $fallback = array_values(array_unique(array_merge(
            $this->get_highquality_playlist(50),
            $this->personalized(50)
        )));
        shuffle($fallback);
        $this->appendPlaylistSongs($songs, $fallback, $history, $limit);
        return $songs;
    }

    protected function appendPopularDakaSongs(
        array &$songs,
        array $history,
        int $limit
    ): void {
        if (count($songs) >= $limit) {
            return;
        }

        // Stable official chart playlist IDs: soaring, new songs, original,
        // and hot songs.
        foreach ([19723756, 3779629, 2884035, 3778678] as $chartPlaylistId) {
            $chartTarget = min($limit, count($songs) + 20);
            $this->appendPlaylistSongs($songs, [$chartPlaylistId], $history, $chartTarget);
        }

        // “坏女孩”与“幻听”分别对应徐良、许嵩；薛之谦是用户指定的
        // 同类热门华语风格。每位歌手设置独立配额，避免某一位占满结果。
        foreach (['徐良', '许嵩', '薛之谦'] as $artist) {
            $artistTarget = min($limit, count($songs) + 20);
            $this->appendSearchSongs(
                $songs,
                $this->search_songs($artist, 100, 0, $artist),
                $history,
                $artistTarget
            );
        }

        foreach (['汪苏泷', '周杰伦', '林俊杰', '陈奕迅'] as $artist) {
            $artistTarget = min($limit, count($songs) + 5);
            $this->appendSearchSongs(
                $songs,
                $this->search_songs($artist, 100, 0, $artist),
                $history,
                $artistTarget
            );
        }

        if (count($songs) < $limit) {
            $terms = [
                '华语流行', '网易云热歌', '经典华语', '网络热歌', '流行男声',
                '流行女声', '伤感情歌', '影视金曲', 'KTV热歌', '国风热歌',
                '热门翻唱', '青春回忆',
            ];
            shuffle($terms);
            foreach ($terms as $term) {
                $offsets = [0, 100, 200];
                shuffle($offsets);
                $this->appendSearchSongs(
                    $songs,
                    $this->search_songs($term, 100, $offsets[0]),
                    $history,
                    $limit
                );
                if (count($songs) >= $limit) {
                    break;
                }
            }
        }

        if (count($songs) < $limit) {
            $fallback = array_values(array_unique(array_merge(
                $this->get_highquality_playlist(50),
                $this->personalized(50)
            )));
            shuffle($fallback);
            $this->appendPlaylistSongs($songs, $fallback, $history, $limit);
        }
    }

    protected function isDakaSongDurationEligible(int $duration): bool
    {
        $minimum = max(60, min(600, (int)($this->config['daka_min_song_seconds'] ?? 120)));
        return $duration >= $minimum;
    }

    protected function appendSearchSongs(
        array &$songs,
        array $candidates,
        array $history,
        int $limit
    ): void {
        if (count($songs) >= $limit) {
            return;
        }
        shuffle($candidates);
        foreach ($candidates as $song) {
            $id = (int)($song['id'] ?? 0);
            if ($id <= 0 || isset($songs[$id]) || isset($history[$id])) {
                continue;
            }
            $duration = max(1, (int)ceil(($song['dt'] ?? $song['duration'] ?? 240000) / 1000));
            if (!$this->isDakaSongDurationEligible($duration)) {
                continue;
            }
            $songs[$id] = [
                'id' => $id,
                'sourceId' => max(0, (int)($song['sourceId'] ?? 0)),
                'time' => $duration,
            ];
            if (count($songs) >= $limit) {
                return;
            }
        }
    }

    protected function appendPlaylistSongs(
        array &$songs,
        array $playlists,
        array $history,
        int $limit
    ): void {
        if (count($songs) >= $limit) {
            return;
        }
        shuffle($playlists);
        foreach ($playlists as $playlistId) {
            $playlist = $this->playlist_detail($playlistId);
            $tracks = is_array($playlist['playlist']['tracks'] ?? null)
                ? $playlist['playlist']['tracks']
                : [];
            shuffle($tracks);
            foreach ($tracks as $song) {
                $id = (int)($song['id'] ?? 0);
                if ($id <= 0 || isset($songs[$id]) || isset($history[$id])) {
                    continue;
                }
                $duration = max(1, (int)ceil(($song['dt'] ?? $song['duration'] ?? 240000) / 1000));
                if (!$this->isDakaSongDurationEligible($duration)) {
                    continue;
                }
                $songs[$id] = [
                    'id' => $id,
                    'sourceId' => (int)$playlistId,
                    'time' => $duration,
                ];
                if (count($songs) >= $limit) {
                    return;
                }
            }
        }
    }

    protected function loadDakaHistory(): array
    {
        $path = $this->dakaHistoryPath();
        if ($path === null || !is_file($path)) {
            return [];
        }
        $decoded = json_decode((string)@file_get_contents($path), true);
        if (!is_array($decoded)) {
            return [];
        }
        $history = [];
        foreach ($decoded as $songId) {
            $id = (int)$songId;
            if ($id > 0) {
                $history[$id] = true;
            }
        }
        return $history;
    }

    /** @return array<int,true> */
    protected function loadRemoteDakaHistory(): array
    {
        $history = [];
        foreach ([
            ['uri' => '/api/v1/play/record', 'data' => ['uid' => $this->userId, 'type' => 0]],
            ['uri' => '/api/play-record/song/list', 'data' => ['limit' => 300, 'offset' => 0]],
        ] as $source) {
            try {
                $body = $this->decodeBody($this->requestApi(
                    (string)$source['uri'],
                    is_array($source['data'] ?? null) ? $source['data'] : [],
                    'weapi'
                ));
            } catch (Throwable $exception) {
                continue;
            }
            if ((int)($body['code'] ?? 0) !== 200) {
                continue;
            }
            foreach ($body['allData'] ?? [] as $record) {
                $id = (int)($record['song']['id'] ?? 0);
                if ($id > 0) {
                    $history[$id] = true;
                }
            }
            foreach ($body['data']['list'] ?? [] as $record) {
                $type = (string)($record['resourceType'] ?? '');
                $id = (int)($record['resourceId'] ?? 0);
                if ($id <= 0) {
                    $id = (int)($record['data']['song']['id'] ?? 0);
                }
                if ($id > 0 && ($type === '' || $type === 'song' || $type === '1')) {
                    $history[$id] = true;
                }
            }
        }
        return $history;
    }

    protected function rememberDakaSongs(array $songIds): void
    {
        $path = $this->dakaHistoryPath();
        if ($path === null) {
            return;
        }
        $existing = array_keys($this->loadDakaHistory());
        $merged = array_values(array_unique(array_map('intval', array_merge($existing, $songIds))));
        if (count($merged) > 30000) {
            $merged = array_slice($merged, -30000);
        }
        @file_put_contents(
            $path,
            json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
            LOCK_EX
        );
    }

    /** @return array<string,mixed> */
    protected function loadDakaDailyState(): array
    {
        $path = $this->dakaDailyStatePath();
        if ($path === null || !is_file($path)) {
            return [];
        }
        $decoded = json_decode((string)@file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $state */
    protected function rememberDakaDailyState(array $state): void
    {
        $path = $this->dakaDailyStatePath();
        if ($path === null) {
            return;
        }
        @file_put_contents(
            $path,
            json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            LOCK_EX
        );
    }

    protected function dakaDailyStatePath(): ?string
    {
        $historyPath = $this->dakaHistoryPath();
        if ($historyPath === null) {
            return null;
        }
        return substr($historyPath, 0, -5) . '.daily.json';
    }

    protected function dakaHistoryPath(): ?string
    {
        if (array_key_exists('daka_history_dir', $this->config)
            && trim((string)$this->config['daka_history_dir']) === '') {
            return null;
        }
        $configured = trim((string)($this->config['daka_history_dir'] ?? ''));
        $directory = $configured !== ''
            ? $configured
            : (function_exists('runtime_path')
                ? rtrim((string)runtime_path(), '/\\') . DIRECTORY_SEPARATOR . 'netease-daka'
                : rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'loopdeck-netease-daka');
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            return null;
        }
        return $directory . DIRECTORY_SEPARATOR . hash('sha256', (string)$this->userId) . '.json';
    }

    public function _listen($songid, $times)
    {
        $songs = [];
        for ($i = 0; $i < (int)$times; $i++) {
            $songs[] = ['id' => $songid, 'sourceId' => 0, 'time' => 240];
        }
        $count = $this->scrobbleBatch($songs);
        return $this->jsonEncode(['code' => 200, 'count' => $count]);
    }

    public function listen()
    {
        $songId = $this->config['songid'] ?? '';
        $times = max(1, (int)($this->config['times'] ?? 1));
        if ($songId === '') {
            return $this->makeResult(201, '歌曲ID不能为空');
        }
        $detail = $this->decodeBody($this->get_songs_detail($songId));
        if (empty($detail['songs'][0]['id'])) {
            return $this->makeResult(201, '歌曲ID：' . $songId . ' 不存在或暂时无法读取');
        }
        $duration = (int)ceil(($detail['songs'][0]['dt'] ?? 240000) / 1000);
        $songs = [];
        for ($i = 0; $i < $times; $i++) {
            $songs[] = ['id' => $songId, 'sourceId' => 0, 'time' => $duration];
        }
        $success = $this->scrobbleBatch($songs);
        if ($success <= 0) {
            return $this->makeResult(201, '歌曲ID：' . $songId . ' 的NCBL日志未获服务器文件确认，请稍后重试');
        }
        return $this->makeResult(
            200,
            '歌曲ID：' . $songId . ' 的NCBL完播文件确认' . $success . '/' . $times . '次；起播文件确认'
            . $this->lastScrobbleStarts . '/' . $times . '次，提交时长约'
            . (int)ceil($this->lastScrobbleSeconds / 60) . '分钟，耗时约'
            . round($this->lastScrobbleElapsedSeconds, 1) . '秒；文件确认不等同于累计统计已入账',
            [
                'plv_confirmed' => $this->lastScrobbleStarts,
                'pld_confirmed' => $success,
                'submitted_seconds' => $this->lastScrobbleSeconds,
                'elapsed_seconds' => round($this->lastScrobbleElapsedSeconds, 3),
            ]
        );
    }

    public function _daka_new()
    {
        return $this->daka_new();
    }

    public function daka_new()
    {
        $before = $this->decodeBody($this->detail($this->userId));
        $beforeCode = (int)($before['code'] ?? 0);
        $listenSongs = (int)($before['listenSongs'] ?? 0);
        if (in_array($beforeCode, [301, 401], true)) {
            $this->cookiezt = true;
            return $this->makeResult(201, '登录状态已失效');
        }
        if ($beforeCode !== 200) {
            return $this->makeResult(201, '读取网易云累计听歌失败，本次未上报，稍后自动重试', [
                'submitted' => 0,
                'retry_after_seconds' => 600,
            ]);
        }

        $source = (string)($this->config['daka_music_from'] ?? 'daily_recommend');
        $target = max(1, min(300, (int)($this->config['daka_limit'] ?? 300)));
        $today = date('Y-m-d');
        $dailyState = $this->loadDakaDailyState();
        $sameDay = (string)($dailyState['date'] ?? '') === $today;
        $baseline = $sameDay
            ? (int)($dailyState['listen_songs_baseline']
                ?? $dailyState['listen_songs_before']
                ?? $listenSongs)
            : $listenSongs;
        $observedBefore = max(
            $listenSongs,
            $sameDay ? (int)($dailyState['listen_songs_observed']
                ?? $dailyState['listen_songs_after']
                ?? $listenSongs) : $listenSongs
        );
        $baseline = min($baseline, $observedBefore);
        $actualProgressBefore = min($target, max(0, $observedBefore - $baseline));
        $remainingBefore = max(0, $target - $actualProgressBefore);
        $submittedTotal = $sameDay ? max(0, (int)($dailyState['submitted_total']
            ?? $dailyState['submitted']
            ?? 0)) : 0;
        $startAcceptedTotal = $sameDay ? max(0, (int)($dailyState['startplay_accepted_total']
            ?? $dailyState['plv_confirmed']
            ?? 0)) : 0;
        $playAcceptedTotal = $sameDay ? max(0, (int)($dailyState['play_accepted_total']
            ?? $dailyState['pld_confirmed']
            ?? 0)) : 0;
        $reportedSecondsTotal = $sameDay ? max(0, (int)($dailyState['reported_play_seconds']
            ?? $dailyState['submitted_seconds']
            ?? 0)) : 0;
        $attempts = $sameDay
            ? max(0, (int)($dailyState['attempts'] ?? ($submittedTotal > 0 ? 1 : 0)))
            : 0;
        $stalledRuns = $sameDay ? max(0, (int)($dailyState['stalled_runs'] ?? 0)) : 0;
        $lastProgress = $sameDay ? max(0, (int)($dailyState['actual_progress'] ?? 0)) : 0;
        $progressChanged = $actualProgressBefore > $lastProgress;

        // NetEase counts at most 300 play events per account per day, and a
        // play only increases 累计听歌 when the song was never heard before.
        // The event budget (target - submitted_total) therefore limits how
        // many additional songs may ever count today.
        $maxBatches = max(1, min(30, (int)($this->config['daka_max_batches_per_day'] ?? 20)));
        $maxVerificationRuns = max(1, min(10, (int)($this->config['daka_max_verification_runs'] ?? 2)));
        $retryInterval = max(120, min(3600, (int)($this->config['daka_retry_seconds'] ?? 900)));
        $submitBudget = max(0, $target - $submittedTotal);
        $submitLimit = min($remainingBefore, $submitBudget);

        if ($actualProgressBefore >= $target) {
            return $this->makeResult(200,
                '网易云累计听歌当前' . $listenSongs . '首；今日实际新增'
                . $actualProgressBefore . '/' . $target . '首，目标已完成',
                [
                    'submitted' => 0,
                    'daily_target' => $target,
                    'daily_confirmed' => $actualProgressBefore,
                    'daily_actual_progress' => $actualProgressBefore,
                    'daily_remaining' => 0,
                    'target_reached' => true,
                    'skipped_duplicate' => true,
                    'attempts' => $attempts,
                    'retry_after_seconds' => 0,
                    'protocol_wait_seconds' => 0,
                    'listen_songs_before' => $listenSongs,
                    'listen_songs_after' => $listenSongs,
                    'listen_songs_delta' => 0,
                ]
            );
        }

        // Verification-only run: the daily event budget is consumed or no new
        // songs remain, so this run only waits for NetEase's asynchronous
        // counting to land. Stop after a few unchanged checks.
        if ($submitLimit <= 0) {
            $stalledAfter = $progressChanged ? 0 : $stalledRuns + 1;
            $stop = $stalledAfter >= $maxVerificationRuns;
            $this->rememberDakaDailyState([
                'date' => $today,
                'target' => $target,
                'listen_songs_baseline' => $baseline,
                'listen_songs_observed' => $observedBefore,
                'actual_progress' => $actualProgressBefore,
                'submitted_total' => $submittedTotal,
                'startplay_accepted_total' => $startAcceptedTotal,
                'play_accepted_total' => $playAcceptedTotal,
                'reported_play_seconds' => $reportedSecondsTotal,
                'attempts' => $attempts,
                'stalled_runs' => $stalledAfter,
                'updated_at' => date('c'),
            ]);
            $message = '网易云累计听歌当前' . $listenSongs . '首；今日实际新增'
                . $actualProgressBefore . '/' . $target . '首，仍差' . $remainingBefore . '首';
            $message .= $submittedTotal >= $target
                ? '；今日已提交' . $submittedTotal . '首上报事件，网易云每天最多统计300次播放且只统计未听过的歌曲，本日额度已用尽'
                : '；已无可上报的新歌候选';
            if (!$stop && !$this->cookiezt) {
                $message .= '，累计统计可能仍在异步更新，约' . (int)ceil($retryInterval / 60) . '分钟后复核';
            } else {
                $message .= '，已停止自动复核';
            }
            return $this->makeResult(201, $message, [
                'submitted' => 0,
                'verification_only' => true,
                'candidate_count' => 0,
                'daily_target' => $target,
                'daily_confirmed' => $actualProgressBefore,
                'daily_actual_progress' => $actualProgressBefore,
                'daily_remaining' => $remainingBefore,
                'target_reached' => false,
                'attempts' => $attempts,
                'stalled_runs' => $stalledAfter,
                'retry_after_seconds' => (!$stop && !$this->cookiezt) ? $retryInterval : 0,
                'protocol_wait_seconds' => 0,
            ]);
        }

        if ($attempts >= $maxBatches) {
            return $this->makeResult(201,
                '网易云累计听歌当前' . $listenSongs . '首；今日实际新增'
                . $actualProgressBefore . '/' . $target . '首，仍差' . $remainingBefore
                . '首；已达到当日补齐批次上限' . $maxBatches . '次',
                [
                    'submitted' => 0,
                    'daily_target' => $target,
                    'daily_confirmed' => $actualProgressBefore,
                    'daily_actual_progress' => $actualProgressBefore,
                    'daily_remaining' => $remainingBefore,
                    'target_reached' => false,
                    'attempts' => $attempts,
                    'retry_after_seconds' => 0,
                    'protocol_wait_seconds' => 0,
                ]
            );
        }

        $candidateLimit = min(450, $submitLimit + max(30, (int)ceil($submitLimit * 0.25)));
        $history = $this->loadDakaHistory() + $this->loadRemoteDakaHistory();
        $candidates = ($attempts > 0 || $submittedTotal > 0)
            ? $this->dakaSupplementSongs($history, $candidateLimit)
            : $this->dakaSongs($source, $history, $candidateLimit);
        $candidateCount = count($candidates);
        $songs = array_slice($candidates, 0, $submitLimit, true);
        if ($songs === []) {
            $attemptsAfter = $attempts + 1;
            $stalledAfter = $progressChanged ? 0 : $stalledRuns + 1;
            $retryAfter = ($attemptsAfter < $maxBatches
                && $stalledAfter < $maxVerificationRuns
                && !$this->cookiezt)
                ? $retryInterval
                : 0;
            $this->rememberDakaDailyState([
                'date' => $today,
                'target' => $target,
                'listen_songs_baseline' => $baseline,
                'listen_songs_observed' => $observedBefore,
                'actual_progress' => $actualProgressBefore,
                'submitted_total' => $submittedTotal,
                'startplay_accepted_total' => $startAcceptedTotal,
                'play_accepted_total' => $playAcceptedTotal,
                'reported_play_seconds' => $reportedSecondsTotal,
                'attempts' => $attemptsAfter,
                'stalled_runs' => $stalledAfter,
                'updated_at' => date('c'),
            ]);
            $message = '未获取到新的候选歌曲，本次未上报';
            $message .= $retryAfter > 0
                ? '，约' . (int)ceil($retryAfter / 60) . '分钟后重试'
                : '，已停止自动重试';
            return $this->makeResult(201, $message, [
                'submitted' => 0,
                'candidate_count' => $candidateCount,
                'daily_target' => $target,
                'daily_confirmed' => $actualProgressBefore,
                'daily_actual_progress' => $actualProgressBefore,
                'daily_remaining' => $remainingBefore,
                'target_reached' => false,
                'attempts' => $attemptsAfter,
                'stalled_runs' => $stalledAfter,
                'retry_after_seconds' => $retryAfter,
                'protocol_wait_seconds' => 0,
            ]);
        }

        $success = $this->weblogScrobbleBatch($songs);
        if ($success > 0) {
            $this->rememberDakaSongs($this->lastScrobbleSongIds);
        }
        $after = $this->decodeBody($this->detail($this->userId));
        $afterCode = (int)($after['code'] ?? 0);
        if (in_array($afterCode, [301, 401], true)) {
            $this->cookiezt = true;
        }
        $current = $afterCode === 200
            ? (int)($after['listenSongs'] ?? $listenSongs)
            : $listenSongs;
        $delta = max(0, $current - $listenSongs);
        $submitted = count($songs);
        $observedAfter = max($observedBefore, $current);
        $actualProgressAfter = min($target, max(0, $observedAfter - $baseline));
        $remainingAfter = max(0, $target - $actualProgressAfter);
        $attemptsAfter = $attempts + 1;
        $submittedTotal += $submitted;
        $startAcceptedTotal += $this->lastScrobbleStarts;
        $playAcceptedTotal += $success;
        $reportedSecondsTotal += $this->lastScrobbleSeconds;
        $submitBudgetAfter = max(0, $target - $submittedTotal);
        $retryAfter = 0;
        if ($remainingAfter > 0 && !$this->cookiezt) {
            if ($attemptsAfter < $maxBatches && $submitBudgetAfter > 0) {
                // More songs may still count today; fetch a fresh batch later.
                $retryAfter = $retryInterval;
            } elseif ($submitBudgetAfter <= 0) {
                // The daily event budget is consumed; the next run only
                // verifies whether the asynchronous count finally landed.
                $retryAfter = $retryInterval;
            }
        }
        $this->rememberDakaDailyState([
            'date' => $today,
            'target' => $target,
            'listen_songs_baseline' => $baseline,
            'listen_songs_observed' => $observedAfter,
            'actual_progress' => $actualProgressAfter,
            'submitted_total' => $submittedTotal,
            'startplay_accepted_total' => $startAcceptedTotal,
            'play_accepted_total' => $playAcceptedTotal,
            'reported_play_seconds' => $reportedSecondsTotal,
            'attempts' => $attemptsAfter,
            'stalled_runs' => 0,
            'submit_budget' => $submitBudgetAfter,
            // Keep legacy fields readable during a rolling deployment.
            'submitted' => $submittedTotal,
            'plv_confirmed' => $startAcceptedTotal,
            'pld_confirmed' => $playAcceptedTotal,
            'submitted_seconds' => $reportedSecondsTotal,
            'listen_songs_before' => $baseline,
            'listen_songs_after' => $observedAfter,
            'listen_songs_delta' => $actualProgressAfter,
            'updated_at' => date('c'),
        ]);

        $message = '网易云累计听歌' . $listenSongs . '→' . $current . '首；今日实际新增'
            . $actualProgressAfter . '/' . $target . '首';
        $message .= '；本批即时上报' . $submitted . '首，起播记录接受'
            . $this->lastScrobbleStarts . '/' . $submitted . '首，听歌记录接受'
            . $success . '/' . $submitted . '首';
        $message .= '，协议耗时约' . round($this->lastScrobbleElapsedSeconds, 1)
            . '秒，未等待歌曲播放';
        if ($remainingAfter > 0) {
            $message .= '；仍差' . $remainingAfter . '首';
            if ($submitBudgetAfter <= 0) {
                $message .= '，今日300首上报额度已用尽（网易云只统计未听过的歌曲），将仅复核最终累计值';
            } elseif ($afterCode !== 200) {
                $message .= '，本次未能读取更新后的累计值';
            } elseif ($delta === 0 && $success > 0) {
                $message .= '，累计统计可能异步更新';
            }
            if ($retryAfter > 0) {
                $message .= '，约' . (int)ceil($retryAfter / 60) . '分钟后'
                    . ($submitBudgetAfter <= 0 ? '复核' : '换一批新歌继续补齐');
            } elseif ($attemptsAfter >= $maxBatches) {
                $message .= '，已达到当日批次上限' . $maxBatches . '次，已停止自动重试';
            }
        }

        return $this->makeResult($remainingAfter === 0 ? 200 : 201, $message, [
            'submitted' => $submitted,
            'candidate_count' => $candidateCount,
            'plv_confirmed' => $this->lastScrobbleStarts,
            'pld_confirmed' => $success,
            'startplay_accepted' => $this->lastScrobbleStarts,
            'play_accepted' => $success,
            'submitted_seconds' => $this->lastScrobbleSeconds,
            'reported_play_seconds' => $this->lastScrobbleSeconds,
            'elapsed_seconds' => round($this->lastScrobbleElapsedSeconds, 3),
            'protocol_wait_seconds' => 0,
            'report_mode' => 'api-enhanced-scrobble',
            'listen_songs_before' => $listenSongs,
            'listen_songs_after' => $current,
            'listen_songs_delta' => $delta,
            'daily_target' => $target,
            'daily_confirmed' => $actualProgressAfter,
            'daily_actual_progress' => $actualProgressAfter,
            'daily_remaining' => $remainingAfter,
            'target_reached' => $remainingAfter === 0,
            'attempts' => $attemptsAfter,
            'stalled_runs' => 0,
            'retry_after_seconds' => $retryAfter,
            'submit_budget' => $submitBudgetAfter,
            'skipped_duplicate' => false,
        ]);
    }

    public function evaluate()
    {
        $body = $this->decodeBody($this->requestApi('/api/music/partner/daily/task/get', [], 'weapi', [
            'domain' => 'https://mp.music.163.com',
        ]));
        if (($body['code'] ?? 0) !== 200) {
            return $this->makeResult(201, (string)($body['message'] ?? '你还不是音乐合伙人，无法评分'));
        }
        if (!empty($body['data']['completed'])) {
            return $this->makeResult(200, '今日评分任务已完成，无需重复执行');
        }
        return $this->evaluate_Execute($body);
    }

    public function evaluate_Execute($data)
    {
        $range = array_values(array_filter(explode(',', (string)($this->config['evaluate_star'] ?? '2,3')), 'strlen'));
        $min = isset($range[0]) ? max(1, min(5, (int)$range[0])) : 2;
        $max = isset($range[1]) ? max($min, min(5, (int)$range[1])) : $min;
        $done = 0;
        foreach ($data['data']['works'] ?? [] as $work) {
            if (!empty($work['completed']) || empty($work['work']['id'])) {
                continue;
            }
            $star = $min === $max ? $min : random_int($min, $max);
            $response = $this->rawRequest('POST', 'https://mp.music.163.com/api/music/partner/work/evaluate', [
                'params' => [
                    'taskId' => $data['data']['id'] ?? '',
                    'score' => (float)$star,
                    'tags' => $star . '-A-1',
                    'workId' => $work['work']['id'],
                ],
                'cookie' => $this->cookie,
                'os' => 'android',
            ]);
            if (($this->decodeBody($response)['code'] ?? 0) === 200) {
                $done++;
            }
        }
        return $this->makeResult(200, '音乐合伙人歌曲评分完成，共评分' . $done . '首');
    }

    public function get_evaluate_star()
    {
        return random_int(1, 10) <= 4 ? 2 : 3;
    }

    public function yunbei_task()
    {
        $messages = [];
        foreach ([
            $this->yunbei_sign(),
            $this->visit_mall(),
            $this->vipcenter_task_external(),
            $this->yunbei_rcmd_submit(),
        ] as $result) {
            if (is_array($result) && isset($result['message'])) {
                $messages[] = $result['message'];
            }
        }
        $this->yunbei_tasks();
        $this->livetask();
        $reward = $this->yunbei_finished_task();
        if (isset($reward['message'])) {
            $messages[] = $reward['message'];
        }
        return $this->makeResult(200, implode('；', array_unique($messages)));
    }

    public function yunbei_sign()
    {
        $status = $this->decodeBody($this->requestApi('/api/point/signed/get', [], 'weapi'));
        if (!empty($status['data']['signed']) || !empty($status['data']['todaySignedIn'])) {
            return $this->makeResult(200, '今日云贝已签到，无需重复执行');
        }
        $body = $this->decodeBody($this->requestApi('/api/pointmall/user/sign', [], 'weapi'));
        $code = (int)($body['code'] ?? 0);
        if ($code === 200 || $code === -2) {
            $point = $body['data']['point'] ?? ($body['point'] ?? 0);
            return $this->makeResult(200, $point ? '云贝签到成功，云贝+' . $point : '云贝签到成功');
        }
        return $this->makeResult(201, (string)($body['message'] ?? '云贝签到失败'));
    }

    public function yunbei_signin_progress($moduleId)
    {
        $body = $this->decodeBody($this->requestApi('/api/act/modules/signin/v2/progress', [
            'moduleId' => $moduleId ?: '1207signin-1207signin',
        ], 'weapi'));
        return ($body['code'] ?? 0) === 200 ? $body : $this->makeResult(201, '签到进度获取失败');
    }

    public function visit_mall()
    {
        $body = $this->decodeBody($this->requestApi('/api/yunbei/task/visit/mall', [], 'weapi'));
        return $this->makeResult(
            ($body['code'] ?? 0) === 200 ? 200 : 201,
            ($body['code'] ?? 0) === 200 ? '云贝任务：浏览商城成功' : (string)($body['message'] ?? '云贝任务：浏览商城失败')
        );
    }

    public function vipcenter_task_external()
    {
        $body = $this->decodeBody($this->requestApi('/api/vipnewcenter/app/level/task/external', ['type' => 1], 'eapi'));
        $success = ($body['code'] ?? 0) === 200 && (($body['data']['code'] ?? 200) === 200);
        return $this->makeResult($success ? 200 : 201, $success ? '云贝任务：浏览会员中心成功' : '云贝任务：浏览会员中心失败');
    }

    public function yunbei_rcmd_submit(
        $yunbeiNum = 10,
        $reason = '有些美好会迟到，但音乐能带你找到',
        $scene = '',
        $fromUserId = -1,
        $songid = ''
    ) {
        if ($songid === '') {
            $songid = $this->pickLocalSongId();
        }
        if ($songid === '') {
            return $this->makeResult(201, '云贝任务：未获取到可推荐歌曲');
        }
        $body = $this->decodeBody($this->requestApi('/api/yunbei/rcmd/song/submit', [
            'songId' => $songid,
            'reason' => $reason,
            'scene' => $scene,
            'yunbeiNum' => (int)$yunbeiNum,
            'fromUserId' => $fromUserId,
        ], 'weapi'));
        return $this->makeResult(
            ($body['code'] ?? 0) === 200 ? 200 : 201,
            ($body['code'] ?? 0) === 200 ? '云贝任务：云贝推歌成功' : (string)($body['message'] ?? '云贝任务：云贝推歌失败')
        );
    }

    protected function pickLocalSongId()
    {
        $recommend = $this->recommand_songs();
        $songs = $recommend['data']['dailySongs'] ?? ($recommend['recommend'] ?? []);
        if (!empty($songs)) {
            $song = $songs[array_rand($songs)];
            return $song['id'] ?? '';
        }
        $songs = $this->get_new_songs();
        if (!empty($songs)) {
            $song = $songs[array_rand($songs)];
            return $song['id'] ?? ($song['song']['id'] ?? '');
        }
        return '';
    }

    public function yunbei_share()
    {
        $body = $this->decodeBody($this->requestApi('/api/point/dailyTask', ['type' => 3], 'eapi', ['os' => 'android']));
        return $this->makeResult(
            in_array((int)($body['code'] ?? 0), [200, -2], true) ? 200 : 201,
            in_array((int)($body['code'] ?? 0), [200, -2], true) ? '云贝任务：分享歌曲/歌单成功' : '云贝任务：分享歌曲/歌单失败'
        );
    }

    public function yunbei_finished_task()
    {
        $body = $this->decodeBody($this->requestApi('/api/usertool/task/todo/query', [], 'weapi'));
        $count = 0;
        foreach ($body['data'] ?? [] as $task) {
            if (empty($task['completed']) || empty($task['userTaskId'])) {
                continue;
            }
            $claimed = $this->decodeBody($this->requestApi('/api/usertool/task/point/receive', [
                'userTaskId' => $task['userTaskId'],
                'depositCode' => $task['depositCode'] ?? '0',
            ], 'weapi'));
            if (($claimed['code'] ?? 0) === 200) {
                $count += (int)($task['taskPoint'] ?? 0);
            }
        }
        return $this->makeResult($count > 0 ? 200 : 201, $count > 0 ? '云贝任务完成，共领取' . $count . '云贝' : '没有待领取的云贝奖励');
    }

    public function musician_task()
    {
        $detail = $this->decodeBody($this->detail($this->userId));
        $auth = $detail['profile']['mainAuthType']['desc'] ?? '';
        if ($auth !== '网易音乐人') {
            return $this->makeResult(201, '你还不是音乐人，无法完成任务');
        }
        $albums = $this->musician_album_list();
        if (!empty($albums[0]['id'])) {
            $songs = $this->album($albums[0]['id']);
            $this->musician_song_id = $songs[0] ?? null;
        }
        $this->musician_sign();
        $this->watch_teaching_video();
        $this->share_resource();
        $this->musician_publishComment();
        $this->musician_sendPrivateMsg();
        $this->shareyourself();
        $reward = $this->musician_finished_task();
        return $this->makeResult(200, '音乐人任务完成' . (!empty($reward['message']) ? '；' . $reward['message'] : ''));
    }

    public function musician_album_list(): array
    {
        $body = $this->decodeBody($this->requestApi('/api/nmusician/production/common/artist/album/item/list/get', [], 'weapi'));
        return $body['data']['list'] ?? [];
    }

    public function album($id): array
    {
        $body = $this->decodeBody($this->requestApi('/api/v1/album/' . (string)$id, [], 'weapi'));
        $ids = [];
        foreach ($body['songs'] ?? [] as $song) {
            if (isset($song['id'])) {
                $ids[] = $song['id'];
            }
        }
        return $ids;
    }

    public function musician_sign()
    {
        return $this->requestApi('/api/creator/user/access', [], 'weapi');
    }

    public function artist_homepage($artistId)
    {
        if (!$artistId) {
            return null;
        }
        $body = $this->decodeBody($this->requestApi('/api/personal/home/page/artist', ['artistId' => $artistId], 'weapi'));
        foreach ($body['data']['blocks'] ?? [] as $block) {
            foreach ($block['creatives'] ?? [] as $creative) {
                foreach ($creative['resources'] ?? [] as $resource) {
                    if (($resource['resourceType'] ?? '') === 'CIRCLE' && !empty($resource['resourceId'])) {
                        return $this->circle_get($resource['resourceId']);
                    }
                }
            }
        }
        return null;
    }

    public function circle_get($circleId)
    {
        return $this->requestApi('/api/circle/get', ['circleId' => $circleId], 'weapi');
    }

    public function watch_teaching_video()
    {
        $last = [];
        for ($i = 0; $i < 2; $i++) {
            $last = $this->requestApi('/api/nmusician/workbench/creator/watch/college/lesson', [], 'weapi');
        }
        return $last;
    }

    public function share_resource()
    {
        $songId = $this->config['musician_song_id'] ?? $this->musician_song_id;
        if (!$songId) {
            return $this->makeResult(201, '音乐人任务：没有可分享的歌曲');
        }
        $message = '我真想拉起你的手，逃向初晴的天空和田野不畏缩也不回顾。';
        $body = $this->decodeBody($this->requestApi('/api/share/friends/resource', [
            'type' => 'song',
            'msg' => $message,
            'id' => $songId,
        ], 'xeapi', ['os' => 'android', 'check_token' => 'v3']));
        if (($body['code'] ?? 0) !== 200) {
            return $this->makeResult(201, (string)($body['message'] ?? '音乐人任务：分享歌曲失败'));
        }
        $threadId = $body['event']['threadId'] ?? null;
        $eventId = $body['id'] ?? ($body['event']['id'] ?? null);
        if ($threadId) {
            $comment = $this->decodeBody($this->comments_add(null, $message, 6, $threadId));
            if (!empty($comment['comment']['commentId'])) {
                $this->comments_delete(null, $comment['comment']['commentId'], 6, $threadId);
            }
        }
        if ($eventId) {
            $this->event_delete($eventId);
        }
        return $this->makeResult(200, '音乐人任务：分享歌曲成功');
    }

    protected function threadId($songId, $type, $threadId = null)
    {
        return (int)$type === 6 ? $threadId : (($this->resourceTypeMap[$type] ?? '') . $songId);
    }

    public function comments_add($song_id, $content, $type, $threadId = null)
    {
        return $this->requestApi('/api/resource/comments/add', [
            'threadId' => $this->threadId($song_id, $type, $threadId),
            'content' => $content,
            'resourceType' => '0',
            'expressionPicId' => '-1',
            'bubbleId' => '-1',
        ], 'xeapi', ['os' => 'android', 'check_token' => 'v3']);
    }

    public function comments_delete($song_id, $commentId, $type, $threadId = null)
    {
        return $this->requestApi('/api/resource/comments/delete', [
            'threadId' => $this->threadId($song_id, $type, $threadId),
            'commentId' => $commentId,
        ], 'xeapi', ['os' => 'android']);
    }

    public function comments_reply($song_id, $commentId, $content, $type, $threadId = null)
    {
        return $this->requestApi('/api/v1/resource/comments/reply', [
            'threadId' => $this->threadId($song_id, $type, $threadId),
            'commentId' => $commentId,
            'content' => $content,
            'resourceType' => '0',
        ], 'xeapi', ['os' => 'android', 'check_token' => 'v3']);
    }

    public function event_delete($id)
    {
        $body = $this->decodeBody($this->requestApi('/api/event/delete', ['id' => $id], 'weapi'));
        return $this->makeResult(($body['code'] ?? 0) === 200 ? 200 : 201, ($body['code'] ?? 0) === 200 ? '删除动态成功' : '删除动态失败');
    }

    public function _event_delete($id): array
    {
        return $this->event_delete($id);
    }

    public function musician_publishComment($content = '好听')
    {
        $songId = $this->config['musician_song_id'] ?? $this->musician_song_id;
        if (!$songId) {
            return $this->makeResult(201, '音乐人任务：没有可评论的歌曲');
        }
        $content = date('Y年m月d日') . '，希望你可以开心';
        $commentIds = [];
        for ($i = 0; $i < 2; $i++) {
            $body = $this->decodeBody($this->comments_add($songId, $content, 0));
            if (!empty($body['comment']['commentId'])) {
                $commentIds[] = $body['comment']['commentId'];
            }
        }
        foreach ($commentIds as $commentId) {
            $this->comments_delete($songId, $commentId, 0);
        }
        return $this->makeResult($commentIds ? 200 : 201, $commentIds ? '音乐人任务：发布主创说成功' : '音乐人任务：发布主创说失败');
    }

    public function musician_sendPrivateMsg($type = 'text'): array
    {
        $userId = $this->config['musician_follows_id'] ?? '';
        if ($userId === '') {
            return $this->makeResult(201, '音乐人任务：未配置私信用户ID');
        }
        $message = $this->config['musician_follows_msg'] ?? '';
        if ($message === '') {
            $message = date('Y年m月d日') . '，希望你可以开心';
        }
        $body = $this->decodeBody($this->requestApi('/api/msg/private/send', [
            'type' => $type,
            'msg' => $message,
            'userIds' => '[' . $userId . ']',
        ], 'eapi', ['os' => 'pc']));
        return $this->makeResult(($body['code'] ?? 0) === 200 ? 200 : 201, ($body['code'] ?? 0) === 200 ? '音乐人任务：回复私信成功' : '音乐人任务：回复私信失败');
    }

    public function shareyourself()
    {
        $songId = $this->config['musician_song_id'] ?? $this->musician_song_id;
        if (!$songId) {
            return null;
        }
        return $this->requestApi('/api/music/songshare/share/property', ['songId' => $songId], 'eapi', ['os' => 'pc']);
    }

    public function publishMlog()
    {
        $songId = $this->pickLocalSongId();
        if (!$songId) {
            return $this->makeResult(201, '歌曲信息获取失败');
        }
        $detail = $this->decodeBody($this->get_songs_detail($songId));
        $song = $detail['songs'][0] ?? [];
        $picture = $song['al']['picUrl'] ?? '';
        if ($picture === '') {
            return $this->makeResult(201, '专辑图片获取失败');
        }
        $image = $this->rawRequest('GET', $picture . '?param=500y500')['body'];
        if (!function_exists('putRuntimeCache')) {
            return $this->makeResult(201, '运行时缓存函数不可用');
        }
        $path = putRuntimeCache('netease', 'album_' . $this->userId . '.jpg', $image);
        $token = $this->mlog_nos_token($path);
        if (empty($token['token']) || empty($token['objectKey'])) {
            return $this->makeResult(201, '图片上传凭证获取失败');
        }
        $this->upload_file($path, $token);
        $artists = $song['ar'] ?? [];
        $artist = $artists[0]['name'] ?? '未知';
        $result = $this->mlog_pub($token, 500, 500, $songId, $song['name'] ?? '', '分享歌曲：' . ($song['name'] ?? '') . ' - ' . $artist);
        if (($result['code'] ?? 0) === 200 && !empty($result['data']['event']['id'])) {
            $this->event_delete($result['data']['event']['id']);
        }
        if (is_file($path)) {
            @unlink($path);
        }
        return $result;
    }

    public function mlog_nos_token($filepath)
    {
        $body = $this->decodeBody($this->requestApi('/api/nos/token/whalealloc', [
            'bizKey' => substr(md5((string)random_int(1, PHP_INT_MAX)), 0, 8),
            'filename' => basename($filepath),
            'bucket' => 'yyimgs',
            'md5' => md5_file($filepath),
            'type' => 'image',
            'fileSize' => filesize($filepath),
        ], 'weapi'));
        return $body['data'] ?? [];
    }

    public function upload_file($filepath, $token)
    {
        $objectKey = str_replace('/', '%2F', (string)($token['objectKey'] ?? ''));
        $url = $token['uploadUrl'] ?? ('https://nosup-hz1.127.net/' . ($token['bucket'] ?? 'yyimgs') . '/' . $objectKey . '?offset=0&complete=true&version=1.0');
        return $this->rawRequest('POST', $url, [
            'headers' => [
                'x-nos-token' => $token['token'] ?? '',
                'Content-Type' => 'image/jpeg',
            ],
            'body' => fopen($filepath, 'rb'),
            'cookie' => '',
        ]);
    }

    public function mlog_pub($token, $height, $width, $songid, $songname, $text)
    {
        $content = [
            'image' => [[
                'height' => $height,
                'width' => $width,
                'more' => false,
                'nosKey' => ($token['bucket'] ?? 'yyimgs') . '/' . ($token['objectKey'] ?? ''),
                'picKey' => $token['resourceId'] ?? '',
            ]],
            'needAudio' => false,
            'song' => ['endTime' => 0, 'name' => $songname, 'songId' => $songid, 'startTime' => 30000],
            'text' => $text,
        ];
        return $this->decodeBody($this->requestApi('/api/mlog/publish/v1', [
            'type' => 1,
            'mlog' => $this->jsonEncode(['content' => $content, 'from' => 0, 'type' => 1]),
        ], 'eapi', ['os' => 'pc']));
    }

    public function musician_finished_task()
    {
        $tasks = [];
        $cycle = $this->decodeBody($this->requestApi('/api/nmusician/workbench/mission/cycle/list', [], 'weapi'));
        foreach ($cycle['data']['list'] ?? [] as $task) {
            if (($task['status'] ?? 0) === 20) {
                $tasks[] = $task;
            }
        }
        $stage = $this->decodeBody($this->requestApi('/api/nmusician/workbench/mission/stage/list', [], 'weapi'));
        foreach ($stage['data']['list'] ?? [] as $period) {
            foreach ($period['userStageTargetList'] ?? [] as $target) {
                if (($target['status'] ?? 0) === 20) {
                    $tasks[] = ['userMissionId' => $target['userMissionId'], 'period' => $period['period']];
                }
            }
        }
        return $tasks ? $this->musician_cloudbean_obtain($tasks) : $this->makeResult(201, '没有待领取的音乐人云豆奖励');
    }

    public function musician_cloudbean_obtain($task)
    {
        $count = 0;
        foreach ($task as $item) {
            $body = $this->decodeBody($this->requestApi('/api/nmusician/workbench/mission/reward/obtain/new', [
                'userMissionId' => $item['userMissionId'] ?? '',
                'period' => $item['period'] ?? '',
            ], 'weapi'));
            if (($body['code'] ?? 0) === 200) {
                $count++;
            }
        }
        return $this->makeResult($count ? 200 : 201, $count ? '音乐人云豆奖励领取成功，共' . $count . '项' : '音乐人云豆奖励领取失败');
    }

    /**
     * Complete the currently automatable VIP growth actions.
     *
     * Black Vinyl LeQian is part of this workflow. Actions that change the
     * user's library, such as liking VIP songs, are intentionally excluded.
     */
    public function vip_growth_task()
    {
        $before = $this->vip_growthpoint();
        $userLevel = $before['data']['userLevel'] ?? null;
        if ((int)($before['code'] ?? 0) !== 200 || !is_array($userLevel)) {
            return $this->makeResult(201, (string)($before['message'] ?? '未检测到有效的网易云黑胶会员'));
        }
        $active = !array_key_exists('normal', $userLevel) || !empty($userLevel['normal']);
        $expireTime = (int)($userLevel['expireTime'] ?? 0);
        if (!$active || ($expireTime > 0 && $expireTime < (int)round(microtime(true) * 1000))) {
            return $this->makeResult(201, '网易云黑胶会员已过期，无法执行VIP成长任务');
        }

        $messages = [];
        $success = true;
        $beforePoint = (int)($userLevel['growthPoint'] ?? 0);

        $sign = $this->vip_sign();
        $messages[] = (string)($sign['message'] ?? '黑胶乐签执行失败');
        if (empty($sign['signed'])) {
            $success = false;
        }

        $timeMachine = $this->vip_timemachine();
        if ((int)($timeMachine['code'] ?? 0) === 200) {
            $messages[] = '黑胶时光机浏览完成';
        } else {
            $messages[] = '黑胶时光机浏览失败';
            $success = false;
        }

        $listen = $this->listen_vip_songs();
        $messages[] = (string)($listen['message'] ?? 'VIP歌曲听歌上报失败');
        if ((int)($listen['code'] ?? 0) !== 200) {
            $success = false;
        }

        $legacyTasks = $this->get_vip_tasks();
        $unclaimedIds = $this->vipUnclaimedTaskIds($legacyTasks);
        if ($unclaimedIds) {
            $claim = $this->vip_growthpoint_get($unclaimedIds);
            if ((int)($claim['code'] ?? 0) === 200) {
                $messages[] = '已领取完成任务的成长值';
            } else {
                $messages[] = (string)($claim['message'] ?? '成长值领取失败');
                $success = false;
            }
        }

        $newTasks = $this->vip_tasks_v1();
        $unclaimedWorth = $this->vipUnclaimedWorth($newTasks);
        $remainingWorth = $unclaimedWorth;
        $claimedWorth = 0;
        $claimAll = $this->vip_growthpoint_getall();
        if ((int)($claimAll['code'] ?? 0) === 200) {
            $remainingWorth = $this->vipUnclaimedWorth($this->vip_tasks_v1());
            $claimedWorth = max(0, $unclaimedWorth - $remainingWorth);
            if ($unclaimedWorth > 0) {
                $messages[] = $remainingWorth === 0
                    ? '已领取' . $claimedWorth . '成长值奖励'
                    : '成长值领取已提交，剩余待领取' . $remainingWorth;
            } else {
                $messages[] = '当前没有遗漏的成长值奖励';
            }
        } elseif ($unclaimedWorth > 0) {
            $messages[] = (string)($claimAll['message'] ?? '一键领取成长值失败');
            $success = false;
        }

        $after = $this->vip_growthpoint();
        $afterPoint = (int)($after['data']['userLevel']['growthPoint'] ?? $beforePoint);
        $delta = max(0, $afterPoint - $beforePoint);
        $messages[] = '当前成长值' . $afterPoint . ($delta > 0 ? '，本次+' . $delta : '');

        return $this->makeResult($success ? 200 : 201, implode('；', array_unique($messages)), [
            'signed' => !empty($sign['signed']),
            'listened' => (int)($listen['data']['reported'] ?? 0),
            'unclaimed_worth_before' => $unclaimedWorth,
            'claimed_worth' => $claimedWorth,
            'unclaimed_worth_after' => $remainingWorth,
            'growth_before' => $beforePoint,
            'growth_after' => $afterPoint,
            'growth_delta' => $delta,
        ]);
    }

    public function vip_growthpoint()
    {
        return $this->decodeBody($this->requestApi('/api/vipnewcenter/app/level/growhpoint/basic', [], 'weapi'));
    }

    public function vip_growthpoint_details($limit = 20, $offset = 0)
    {
        return $this->decodeBody($this->requestApi('/api/vipnewcenter/app/level/growth/details', [
            'limit' => max(1, (int)$limit),
            'offset' => max(0, (int)$offset),
        ], 'weapi'));
    }

    public function get_vip_tasks()
    {
        return $this->decodeBody($this->requestApi('/api/vipnewcenter/app/level/task/list', [], 'weapi'));
    }

    public function vip_tasks_v1($userId = null)
    {
        return $this->decodeBody($this->requestApi('/api/middle/vip/mission/user/progress/list', [
            'taskType' => 'app_vip_task_center',
            'userId' => (string)($userId ?? $this->userId),
        ], 'xeapi'));
    }

    public function vip_growthpoint_get($taskIds)
    {
        if (is_array($taskIds)) {
            $taskIds = implode(',', array_values(array_filter(array_map('strval', $taskIds), 'strlen')));
        }
        return $this->decodeBody($this->requestApi('/api/vipnewcenter/app/level/task/reward/get', [
            'taskIds' => (string)$taskIds,
        ], 'weapi'));
    }

    public function vip_growthpoint_getall()
    {
        return $this->decodeBody($this->requestApi('/api/vipnewcenter/app/level/task/reward/getall', [], 'xeapi'));
    }

    public function vip_sign()
    {
        $taskSign = $this->decodeBody($this->requestApi('/api/vip-center-bff/task/sign', [], 'weapi'));
        $checkinDetail = $this->vip_sign_detail();
        $signed = (int)($taskSign['code'] ?? 0) === 200
            && (int)($checkinDetail['code'] ?? 0) === 200;

        return [
            'code' => 200,
            'taskSign' => $taskSign,
            'checkinDetail' => $checkinDetail,
            'signed' => $signed,
            'message' => $signed ? '黑胶乐签打卡成功' : '黑胶乐签打卡失败',
        ];
    }

    public function vip_sign_detail($timestamp = null)
    {
        return $this->decodeBody($this->requestApi('/api/vipnewcenter/app/level/user/checkin/history/detail', [
            'signDayTime' => $timestamp === null ? (int)round(microtime(true) * 1000) : (int)$timestamp,
            'type' => 1,
        ], 'eapi'));
    }

    public function vip_sign_history($type = 0)
    {
        return $this->decodeBody($this->requestApi('/api/vipnewcenter/app/minidesk/music/sign/pc', [
            'type' => (string)$type,
        ], 'eapi'));
    }

    public function vip_sign_info()
    {
        return $this->decodeBody($this->requestApi('/api/vipnewcenter/app/user/sign/info', [], 'weapi'));
    }

    public function vip_timemachine($startTime = null, $endTime = null, $limit = 60)
    {
        $data = [];
        if ($startTime !== null && $endTime !== null) {
            $data = [
                'startTime' => $startTime,
                'endTime' => $endTime,
                'type' => 1,
                'limit' => max(1, (int)$limit),
            ];
        }
        return $this->decodeBody($this->requestApi('/api/vipmusic/newrecord/weekflow', $data, 'weapi'));
    }

    public function listen_vip_songs()
    {
        $result = [];
        $reported = 0;
        foreach ([205342, 174944, 416700305] as $songId) {
            $detail = $this->decodeBody($this->get_songs_detail($songId));
            $duration = (int)ceil(($detail['songs'][0]['dt'] ?? 240000) / 1000);
            $result[$songId] = $this->scrobbleSong($songId, 0, $duration);
            if ($result[$songId]) {
                $reported++;
            }
        }
        return $this->makeResult(
            $reported === 3 ? 200 : 201,
            $reported === 3 ? 'VIP歌曲听歌上报完成，共3首' : 'VIP歌曲听歌上报完成' . $reported . '/3首',
            ['reported' => $reported, 'songs' => $result]
        );
    }

    protected function vipUnclaimedTaskIds(array $tasks): array
    {
        $ids = [];
        foreach ($tasks['data']['taskList'] ?? [] as $group) {
            foreach ($group['taskItems'] ?? [] as $task) {
                $value = $task['unGetIds'] ?? null;
                $values = is_array($value) ? $value : preg_split('/\s*,\s*/', (string)$value, -1, PREG_SPLIT_NO_EMPTY);
                foreach ($values ?: [] as $id) {
                    if ((string)$id !== '') {
                        $ids[] = (string)$id;
                    }
                }
            }
        }
        return array_values(array_unique($ids));
    }

    protected function vipUnclaimedWorth(array $tasks): int
    {
        $worth = 0;
        foreach ($tasks['data'] ?? [] as $task) {
            $worth += max(0, (int)($task['historyUnObtainRewardWorth'] ?? 0));
            foreach ($task['children'] ?? [] as $child) {
                $worth += max(0, (int)($child['historyUnObtainRewardWorth'] ?? 0));
            }
        }
        return $worth;
    }

    public function yunbei_tasks()
    {
        $all = $this->decodeBody($this->requestApi('/api/usertool/task/list/all', [], 'weapi'));
        $legacy = [];
        foreach ([648, 647, 601, 624, 626, 614] as $taskCode) {
            $legacy[$taskCode] = $this->decodeBody($this->requestApi('/api/task/podcast/complete/report', [
                'taskCode' => $taskCode,
                'verifyId' => 1,
            ], 'eapi', ['domain' => 'https://interface3.music.163.com', 'os' => 'pc']));
        }
        return ['code' => 200, 'data' => $all['data'] ?? [], 'legacy' => $legacy];
    }

    public function livetask()
    {
        return $this->requestApi('/api/livestream/yunbeitask/finish', [], 'eapi', [
            'domain' => 'https://api.iplay.163.com',
            'os' => 'android',
        ]);
    }

    public function rlogin()
    {
        return null;
    }
}
