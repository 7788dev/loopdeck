<?php

namespace netease;

use netease\sdk\Client as CloudMusicClient;
use netease\sdk\Ncbl;
use Throwable;

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
    /**
     * Official chart playlists used as the last-resort candidate pool for the
     * daily task: 热歌榜, 飙升榜, 新歌榜 and 原创榜. 热歌榜 alone carries more
     * tracks than a full 300-song day.
     */
    protected const DAKA_CHART_PLAYLISTS = [3778678, 19723756, 3779629, 2884035];

    public $cookiezt = false;

    protected $musician_song_id;
    protected $_MINI_MODE = false;

    protected $userId;
    protected $csrf;
    protected $musicu;
    protected $config = [];
    protected $cookie;
    protected $sdk;
    protected $lastScrobbleStarts = 0;
    protected $lastScrobbleSeconds = 0;
    protected $lastScrobbleSongIds = [];
    protected $lastScrobbleElapsedSeconds = 0.0;
    protected $lastScrobbleRejections = [];

    /** @var array<int,array<int,array{id:int,time:int}>> */
    protected $dakaTrackCache = [];

    /** @var array<int,true>|null */
    protected $dakaHistoryCache = null;

    /**
     * True when the initial play-record history could not be completely
     * persisted. A partial seed is unsafe for a lifetime-unheard filter, so
     * the daily task must wait for a later retry instead of reporting songs
     * whose prior listening history is unknown.
     */
    protected bool $dakaHistorySeedIncomplete = false;

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
        // A short random hexadecimal query often returns no useful playlists.
        // Rotate through long-tail terms instead, while keeping a little
        // randomness so consecutive accounts do not walk the same results.
        $terms = [
            '冷门', '小众', '宝藏歌曲', '独立音乐', '器乐', '后摇', '古典',
            '氛围音乐', '影视原声', 'ACG', '粤语', '日语', '民谣', '翻唱',
        ];
        $keyword = $keywords === '冷门'
            ? $terms[array_rand($terms)]
            : (string)$keywords;
        $body = $this->decodeBody($this->requestApi('/api/cloudsearch/pc', [
            's' => $keyword,
            'type' => $type,
            'limit' => $limit,
            'offset' => random_int(0, 4) * max(1, (int)$limit),
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

    /**
     * `n` used to be 100000, which pulls every track of a large playlist and
     * can be several megabytes of JSON. The daily task never needs more than a
     * few hundred, so ask for a bounded slice.
     */
    public function playlist_detail($playlist_id)
    {
        $trackLimit = max(1, min(100000, (int)($this->config['playlist_track_limit'] ?? 600)));
        return $this->decodeBody($this->requestApi('/api/v6/playlist/detail', [
            'id' => $playlist_id,
            'n' => $trackLimit,
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
        $this->lastScrobbleRejections = [];
        $songs = array_values(array_filter($songs, static fn($song): bool =>
            is_array($song) && (int)($song['id'] ?? 0) > 0
        ));
        if ($songs === []) {
            return 0;
        }

        $concurrency = max(1, min(16, (int)($this->config['daka_concurrency'] ?? 12)));
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
                } else {
                    $this->recordDakaRejection('startplay', $response);
                }
            }

            // Upstream always sends play after startplay, even if the first
            // response is rejected. Preserve that behavior for compatibility.
            $playResponses = $this->sendDakaWeblogMany($playRequests, $concurrency);
            foreach ($chunk as $index => $song) {
                if (!$this->isDakaWeblogAccepted($playResponses[$index] ?? [])) {
                    $this->recordDakaRejection('play', $playResponses[$index] ?? []);
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
     * Keep a tally of the codes NetEase answered with so an operator can tell
     * "reported but not counted" apart from "the request was refused".
     */
    private function recordDakaRejection(string $phase, array $response): void
    {
        $code = (int)($this->decodeBody($response)['code'] ?? 0);
        $key = $phase . ':' . $code;
        $this->lastScrobbleRejections[$key] = ($this->lastScrobbleRejections[$key] ?? 0) + 1;
    }

    protected function dakaRejectionSummary(): string
    {
        if ($this->lastScrobbleRejections === []) {
            return '';
        }
        $parts = [];
        foreach ($this->lastScrobbleRejections as $key => $count) {
            $parts[] = $key . '×' . $count;
        }
        return implode(' ', $parts);
    }

    /**
     * Read the account's cumulative listen counter without allowing a broken
     * verification request to abort the whole daily task.
     *
     * @return array{code:int,listen_songs:int}
     */
    protected function dakaListenSongs(int $fallback): array
    {
        try {
            $body = $this->decodeBody($this->detail($this->userId));
        } catch (Throwable $exception) {
            return ['code' => 0, 'listen_songs' => $fallback];
        }

        $code = (int)($body['code'] ?? 0);
        if (in_array($code, [301, 401], true)) {
            $this->cookiezt = true;
        }

        return [
            'code' => $code,
            'listen_songs' => $code === 200
                ? max(0, (int)($body['listenSongs'] ?? $fallback))
                : $fallback,
        ];
    }

    /**
     * Give NetEase's asynchronous listen counter a short, bounded settlement
     * window before submitting a replacement batch. Tests can override this
     * hook to keep their transport fully deterministic.
     */
    protected function waitDakaSettlement(int $seconds): void
    {
        $seconds = max(0, min(60, $seconds));
        if ($seconds > 0) {
            sleep($seconds);
        }
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

    /**
     * Ordered playlist pools for the daily task.
     *
     * Upstream reports `sourceid=<playlist>`, so every candidate has to come
     * from a real playlist. Search is only used to discover whole playlists;
     * the songs themselves still come from `playlist_detail` so they always
     * name a real source. The personalised new-song feed cannot name one and
     * stays excluded.
     *
     * Only songs the account has never heard count towards the daily tally,
     * so the pool order follows fresh-song supply: the account's own
     * playlists first (cheap, personalised), then random-keyword search
     * playlists — the long tail where an account that already heard the
     * popular catalog still finds unheard songs — and the official charts
     * last, since hot charts are exactly what everyone has already heard.
     * Each search round uses a fresh random keyword; every pool is resolved
     * lazily, so an earlier pool that fills the quota never triggers the
     * later ones.
     *
     * @return array<int,callable():array<int,int>>
     */
    protected function dakaPlaylistPools(string $source): array
    {
        $pools = [];
        $configured = $this->configuredDakaPlaylistIds();
        if ($configured !== []) {
            $pools[] = static fn(): array => $configured;
        }

        if ($source === 'highquality') {
            $pools[] = fn(): array => $this->get_highquality_playlist(50);
        } elseif ($source === 'personalized') {
            $pools[] = fn(): array => $this->personalized(50);
        } else {
            $pools[] = fn(): array => $this->recommend_playlist();
        }
        // Search is the long-tail source. Keep every configured search round
        // ahead of the hot charts: popular chart tracks are the least likely
        // to be fresh for an account with a large listening history.
        $searchRounds = max(1, min(8, (int)($this->config['daka_search_rounds'] ?? 4)));
        $searchPlaylistLimit = max(
            1,
            min(50, (int)($this->config['daka_search_playlists_per_round'] ?? 12))
        );
        for ($round = 0; $round < $searchRounds; $round++) {
            $pools[] = function () use ($searchPlaylistLimit): array {
                $ids = $this->normalizePlaylistIds($this->get_search_playlist2());
                shuffle($ids);
                return array_slice($ids, 0, $searchPlaylistLimit);
            };
        }
        // Official charts are the final safety net, not the first deep pool.
        $pools[] = static fn(): array => self::DAKA_CHART_PLAYLISTS;

        return $pools;
    }

    /**
     * @param mixed $playlists
     * @return array<int,int>
     */
    protected function normalizePlaylistIds($playlists): array
    {
        $ids = [];
        foreach (is_array($playlists) ? $playlists : [] as $playlistId) {
            $playlistId = (int)$playlistId;
            if ($playlistId > 0 && !in_array($playlistId, $ids, true)) {
                $ids[] = $playlistId;
            }
        }
        return $ids;
    }

    /** @return array<int,int> */
    protected function configuredDakaPlaylistIds(): array
    {
        $raw = trim((string)($this->config['daka_playlist_ids'] ?? ''));
        if ($raw === '') {
            return [];
        }
        $ids = [];
        foreach (preg_split('/[^0-9]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $value) {
            $id = (int)$value;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        return array_slice($ids, 0, 10);
    }

    /**
     * Collect candidates for one batch.
     *
     * `$limit` is a fresh-song quota: NetEase only counts songs the account
     * has never heard, so candidates are collected until that many unheard
     * songs are found, digging into progressively deeper pools, and songs
     * already in the per-account history (or in `$exclude`, submitted earlier
     * today) are skipped entirely instead of padding the batch with plays
     * that cannot count.
     *
     * @param array<int,true> $exclude
     * @return array<int,array{id:int,sourceId:int,time:int}>
     */
    protected function dakaCandidates(string $source, array $exclude, int $limit): array
    {
        $pools = $this->dakaPlaylistPools($source);
        $resolved = [];
        $songs = [];
        // Lifetime-heard songs can never count again, so they are excluded
        // during collection instead of only being ranked last: the quota is
        // spent on songs that can actually add to today's tally.
        $history = $this->dakaHistory();
        if ($this->dakaHistorySeedIncomplete) {
            return [];
        }
        $floors = [$this->dakaMinimumSongSeconds()];
        $relaxed = 30;
        if ($relaxed < $floors[0]) {
            // Second pass only widens the duration filter; the track cache
            // means it costs no additional requests.
            $floors[] = $relaxed;
        }
        foreach ($floors as $minimumSeconds) {
            foreach ($pools as $index => $pool) {
                if (!array_key_exists($index, $resolved)) {
                    try {
                        $resolved[$index] = $this->normalizePlaylistIds($pool());
                    } catch (Throwable $exception) {
                        $resolved[$index] = [];
                    }
                }
                $this->appendPlaylistSongs($songs, $resolved[$index], $exclude, $history, $limit, $minimumSeconds);
                if (count($songs) >= $limit) {
                    return $songs;
                }
            }
        }
        return $songs;
    }

    /**
     * Playlist tracks, fetched at most once per run.
     *
     * Public playlist track lists are the same for every account, so they are
     * cached process-independently (6 hours): with N accounts per day a chart
     * or discovered long-tail playlist costs one upstream request instead of
     * N. The cache is best-effort — selection works without it.
     *
     * @return array<int,array{id:int,time:int}>
     */
    protected function dakaPlaylistTracks(int $playlistId): array
    {
        if (isset($this->dakaTrackCache[$playlistId])) {
            return $this->dakaTrackCache[$playlistId];
        }
        $trackLimit = max(1, min(100000, (int)($this->config['playlist_track_limit'] ?? 600)));
        // A configured playlist may be private. Never put its track list in a
        // process-wide cache, and use a versioned key so entries written by an
        // older release (before this isolation) cannot be reused.
        $sharedKey = $playlistId > 0
            && !in_array($playlistId, $this->configuredDakaPlaylistIds(), true)
            ? 'daka_public_playlist_tracks_v2_' . $playlistId . '_' . $trackLimit
            : null;
        if ($sharedKey !== null) {
            $cached = $this->dakaSharedCacheGet($sharedKey);
            if ($cached !== null) {
                $validCached = [];
                foreach ($cached as $track) {
                    if (!is_array($track)) {
                        continue;
                    }
                    $id = (int)($track['id'] ?? 0);
                    if ($id <= 0) {
                        continue;
                    }
                    $validCached[] = [
                        'id' => $id,
                        'time' => max(1, (int)($track['time'] ?? 240)),
                    ];
                }
                if ($validCached !== []) {
                    // Cache the public data, not the selection order. Each
                    // account/run should get an independent shuffle.
                    shuffle($validCached);
                    return $this->dakaTrackCache[$playlistId] = $validCached;
                }
            }
        }
        try {
            $playlist = $this->playlist_detail($playlistId);
        } catch (Throwable $exception) {
            return $this->dakaTrackCache[$playlistId] = [];
        }
        $playlistCode = (int)($playlist['code'] ?? 0);
        if (in_array($playlistCode, [301, 401], true)) {
            $this->cookiezt = true;
            return $this->dakaTrackCache[$playlistId] = [];
        }
        $tracks = [];
        foreach (is_array($playlist['playlist']['tracks'] ?? null) ? $playlist['playlist']['tracks'] : [] as $song) {
            $id = (int)($song['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $tracks[] = [
                'id' => $id,
                'time' => max(1, (int)ceil(($song['dt'] ?? $song['duration'] ?? 240000) / 1000)),
            ];
        }
        shuffle($tracks);
        if ($sharedKey !== null && $tracks !== []) {
            $this->dakaSharedCacheSet($sharedKey, $tracks, 21600);
        }
        return $this->dakaTrackCache[$playlistId] = $tracks;
    }

    /** Best-effort cross-account cache read; null when unavailable. */
    protected function dakaSharedCacheGet(string $key): ?array
    {
        try {
            $cached = \think\facade\Cache::get($key);
        } catch (Throwable $exception) {
            // No bootstrapped cache container (offline tests, bare CLI runs):
            // behave as a cache miss.
            return null;
        }
        return is_array($cached) ? $cached : null;
    }

    /** @param array<int,array{id:int,time:int>> $value */
    protected function dakaSharedCacheSet(string $key, array $value, int $ttlSeconds): void
    {
        try {
            \think\facade\Cache::set($key, $value, $ttlSeconds);
        } catch (Throwable $exception) {
            // Cache is an optimization; failures must not break selection.
        }
    }

    protected function dakaMinimumSongSeconds(): int
    {
        // Plays shorter than a minute are not counted by NetEase. Upstream
        // applies no filter at all, so stay just above that floor instead of
        // shrinking the candidate pool.
        return max(30, min(600, (int)($this->config['daka_min_song_seconds'] ?? 60)));
    }

    protected function isDakaSongDurationEligible(int $duration): bool
    {
        return $duration >= $this->dakaMinimumSongSeconds();
    }

    /**
     * @param array<int,array{id:int,sourceId:int,time:int}> $songs
     * @param array<int,int> $playlists
     * @param array<int,true> $exclude
     * @param array<int,true> $history
     */
    protected function appendPlaylistSongs(
        array &$songs,
        array $playlists,
        array $exclude,
        array $history,
        int $limit,
        ?int $minimumSeconds = null
    ): void {
        if (count($songs) >= $limit) {
            return;
        }
        $minimumSeconds ??= $this->dakaMinimumSongSeconds();
        shuffle($playlists);
        foreach ($playlists as $playlistId) {
            $playlistId = (int)$playlistId;
            if ($playlistId <= 0) {
                continue;
            }
            foreach ($this->dakaPlaylistTracks($playlistId) as $track) {
                $id = (int)$track['id'];
                $duration = (int)$track['time'];
                if (isset($songs[$id])
                    || isset($exclude[$id])
                    || isset($history[$id])
                    || $duration < $minimumSeconds) {
                    continue;
                }
                $songs[$id] = [
                    'id' => $id,
                    'sourceId' => $playlistId,
                    'time' => $duration,
                ];
                if (count($songs) >= $limit) {
                    return;
                }
            }
        }
    }

    /**
     * Lifetime-reported songs. A play only counts towards the daily tally
     * the first time this account plays it, so this set is the exclusion
     * filter during candidate collection.
     *
     * On the first run the account's play-record charts (all-time + weekly)
     * seed the set with the songs NetEase itself reports as already played,
     * so songs heard before the tool existed are not treated as fresh. The
     * seed marker persists next to the history file; a failing fetch leaves
     * the marker unset and is retried on a later day.
     *
     * @return array<int,true>
     */
    protected function dakaHistory(): array
    {
        if ($this->dakaHistoryCache === null) {
            $this->dakaHistoryCache = $this->loadDakaHistory();
            if (!$this->dakaPlayRecordSeeded()) {
                $this->seedDakaHistoryFromPlayRecord();
            }
        }
        return $this->dakaHistoryCache;
    }

    /** @return array<int,true> */
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

    protected function dakaPlayRecordSeeded(): bool
    {
        $historyPath = $this->dakaHistoryPath();
        $path = $this->dakaPlayRecordSeedPath();
        if ($historyPath === null || $path === null) {
            return true;
        }
        if (!is_file($path) || !is_file($historyPath)) {
            return false;
        }
        // A marker without a valid history array can be left behind by an
        // interrupted deployment or a failed write. Re-seed rather than
        // silently treating every song as fresh. JSON `null` is syntactically
        // valid, but it is not a usable history document.
        $decoded = json_decode((string)@file_get_contents($historyPath), true);
        return is_array($decoded) && json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Merge the account's play-record charts into the history set and mark
     * the seed as done. The api-enhanced `/api/v1/play/record` wrapper mirrors
     * NetEase's response (`allData` for type 0 and `weekData` for type 1), not
     * a generic `list` field. A failing endpoint is left unmarked so the next
     * run can retry; a valid empty response is still a successful seed for a
     * new account.
     */
    protected function seedDakaHistoryFromPlayRecord(): void
    {
        $this->dakaHistorySeedIncomplete = false;
        $seededIds = [];
        $failed = false;
        foreach ([0, 1] as $type) {
            try {
                $body = $this->decodeBody($this->requestApi('/api/v1/play/record', [
                    // api-enhanced's user_record module requires uid
                    // explicitly; the SDK session cookie does not inject it.
                    'uid' => $this->userId,
                    'type' => $type,
                ], 'weapi'));
            } catch (Throwable $exception) {
                $body = [];
            }
            if ((int)($body['code'] ?? 0) !== 200) {
                if (in_array((int)($body['code'] ?? 0), [301, 401], true)) {
                    $this->cookiezt = true;
                }
                $failed = true;
                continue;
            }
            $records = $type === 0
                ? ($body['allData'] ?? $body['list'] ?? $body['data'] ?? [])
                : ($body['weekData'] ?? $body['list'] ?? $body['data'] ?? []);
            foreach (is_array($records) ? $records : [] as $entry) {
                $this->collectDakaPlayRecordIds($entry, $seededIds);
            }
        }

        $history = $this->dakaHistoryCache ?? $this->loadDakaHistory();
        foreach (array_keys($seededIds) as $id) {
            $history[(int)$id] = true;
        }
        $this->dakaHistoryCache = $history;
        $historyPersisted = $this->rememberDakaHistoryIds(array_keys($history));

        // Do not create a permanent marker after a transient/network failure.
        // Partial IDs are persisted for diagnostics and the next run, but the
        // current daka run remains fail-closed until both charts are known.
        if ($failed || !$historyPersisted) {
            $this->dakaHistorySeedIncomplete = true;
            return;
        }

        $marker = $this->dakaPlayRecordSeedPath();
        if ($marker !== null) {
            if (!$this->writeDakaStateFile($marker, date('c'))) {
                $this->dakaHistorySeedIncomplete = true;
            }
        }
    }

    /**
     * Extract IDs from both the current API response and older compatible
     * response variants. A record normally contains `song.id`, while some
     * deployments expose `songId` or `id` directly.
     *
     * @param mixed $entry
     * @param array<int,true> $ids
     */
    protected function collectDakaPlayRecordIds($entry, array &$ids): void
    {
        if (!is_array($entry)) {
            return;
        }
        $id = (int)($entry['songId'] ?? $entry['song']['id'] ?? $entry['id'] ?? 0);
        if ($id > 0) {
            $ids[$id] = true;
        }
    }

    /** Persist the history set without touching the cache. */
    protected function rememberDakaHistoryIds(array $songIds): bool
    {
        $path = $this->dakaHistoryPath();
        if ($path === null) {
            return false;
        }
        $ids = [];
        foreach ($songIds as $songId) {
            $id = (int)$songId;
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        return $this->writeDakaStateFile($path, json_encode(
            array_keys($ids),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: '[]');
    }

    protected function dakaPlayRecordSeedPath(): ?string
    {
        $historyPath = $this->dakaHistoryPath();
        if ($historyPath === null) {
            return null;
        }
        return substr($historyPath, 0, -5) . '.seeded';
    }

    protected function rememberDakaSongs(array $songIds): void
    {
        $history = $this->dakaHistory();
        foreach ($songIds as $songId) {
            $id = (int)$songId;
            if ($id > 0) {
                $history[$id] = true;
            }
        }
        $this->dakaHistoryCache = $history;

        $path = $this->dakaHistoryPath();
        if ($path === null) {
            return;
        }
        // Never truncate this set: dropping old IDs would allow a lifetime
        // repeat to be submitted again after an account passes 30,000 songs.
        $persisted = $this->writeDakaStateFile($path, json_encode(
            array_keys($history),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: '[]');
        if (!$persisted) {
            // Force the next process to rebuild from NetEase's play record.
            // Keeping a seeded marker beside a stale history file could allow
            // these accepted songs to be selected again on a later day.
            $marker = $this->dakaPlayRecordSeedPath();
            if ($marker !== null && is_file($marker)) {
                @unlink($marker);
            }
            $this->dakaHistorySeedIncomplete = true;
        }
    }

    /**
     * Replace a state file atomically. Readers may run in another scheduler
     * process, so writing directly to the destination could expose a partial
     * JSON document even when file_put_contents uses LOCK_EX.
     */
    protected function writeDakaStateFile(string $path, string $contents): bool
    {
        $temporary = '';
        try {
            $temporary = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
            if (@file_put_contents($temporary, $contents, LOCK_EX) === false) {
                @unlink($temporary);
                return false;
            }
            @chmod($temporary, 0660);
            if (!@rename($temporary, $path)) {
                @unlink($temporary);
                return false;
            }
            return true;
        } catch (Throwable $exception) {
            if ($temporary !== '') {
                @unlink($temporary);
            }
            return false;
        }
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
        $this->writeDakaStateFile(
            $path,
            json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'
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

    /**
     * Remove the per-account state files. Account deletion used to leave both
     * JSON files behind forever.
     */
    public function forgetDakaState(): void
    {
        $path = $this->dakaHistoryPath();
        if ($path === null) {
            return;
        }
        $this->dakaHistoryCache = [];
        $this->dakaTrackCache = [];
        foreach ([$path, substr($path, 0, -5) . '.daily.json', substr($path, 0, -5) . '.seeded'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
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
        $startedAt = microtime(true);
        $before = $this->decodeBody($this->detail($this->userId));
        $beforeCode = (int)($before['code'] ?? 0);
        $listenSongs = (int)($before['listenSongs'] ?? 0);
        if (in_array($beforeCode, [301, 401], true)) {
            $this->cookiezt = true;
            return $this->makeResult(201, '登录状态已失效', [
                'submitted' => 0,
                'retry_after_seconds' => 0,
            ]);
        }
        if ($beforeCode !== 200) {
            // Daily 300-song execution is intentionally a single scheduler
            // transaction. Returning a retry delay here would create another
            // task-log row and defeat that contract; the next normal daily
            // schedule can try again if the counter endpoint is unavailable.
            return $this->makeResult(201, '读取网易云累计听歌失败，本次任务未完成', [
                'submitted' => 0,
                'retry_after_seconds' => 0,
                'target_reached' => false,
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
        $repeatSubmittedTotal = $sameDay ? max(0, (int)($dailyState['repeat_submitted_total'] ?? 0)) : 0;
        $attempts = $sameDay
            ? max(0, (int)($dailyState['attempts'] ?? ($submittedTotal > 0 ? 1 : 0)))
            : 0;
        $stalledRuns = $sameDay ? max(0, (int)($dailyState['stalled_runs'] ?? 0)) : 0;
        $topupsUsed = $sameDay ? max(0, (int)($dailyState['topups_used'] ?? 0)) : 0;
        $verifications = $sameDay ? max(0, (int)($dailyState['verifications'] ?? 0)) : 0;
        $submittedToday = [];
        if ($sameDay && is_array($dailyState['submitted_song_ids'] ?? null)) {
            foreach ($dailyState['submitted_song_ids'] as $songId) {
                $songId = (int)$songId;
                if ($songId > 0) {
                    $submittedToday[$songId] = true;
                }
            }
        }

        $maxBatches = max(1, min(30, (int)($this->config['daka_max_batches_per_day'] ?? 6)));
        $maxIdleBatches = max(1, min(10, (int)($this->config['daka_max_verification_runs'] ?? 3)));
        $settlementWait = max(0, min(60, (int)($this->config['daka_internal_wait_seconds'] ?? 15)));
        // Keep a small replacement cushion for accepted weblogs that NetEase
        // declines to count. This is a submission ceiling, not a new quota;
        // candidates remain lifetime- and same-day-unique.
        $submissionCushion = max(4, min(60, (int)ceil($target * 0.2)));
        $submissionCeiling = max($target + $submissionCushion, $submittedTotal + $remainingBefore);

        $currentListenSongs = $listenSongs;
        $remaining = $remainingBefore;
        $submittedThisRun = 0;
        $startAcceptedThisRun = 0;
        $playAcceptedThisRun = 0;
        $reportedSecondsThisRun = 0;
        $candidateCountTotal = 0;
        $internalBatches = 0;
        $protocolWaitSeconds = 0;
        $noProgressBatches = 0;
        $lastDelta = 0;
        $reason = '';
        $runRejections = [];

        $persist = function (bool $completed, bool $sealed) use (
            &$observedBefore,
            &$actualProgressBefore,
            &$remaining,
            &$submittedTotal,
            &$submittedToday,
            &$startAcceptedTotal,
            &$playAcceptedTotal,
            &$reportedSecondsTotal,
            &$repeatSubmittedTotal,
            &$topupsUsed,
            &$attempts,
            &$stalledRuns,
            &$verifications,
            $today,
            $target,
            $baseline,
            &$currentListenSongs,
            &$internalBatches,
            &$protocolWaitSeconds
        ): void {
            $this->rememberDakaDailyState([
                'date' => $today,
                'target' => $target,
                'listen_songs_baseline' => $baseline,
                'listen_songs_observed' => $observedBefore,
                'actual_progress' => $actualProgressBefore,
                'submitted_total' => $submittedTotal,
                'submitted_song_ids' => array_keys($submittedToday),
                'startplay_accepted_total' => $startAcceptedTotal,
                'play_accepted_total' => $playAcceptedTotal,
                'reported_play_seconds' => $reportedSecondsTotal,
                'repeat_submitted_total' => $repeatSubmittedTotal,
                'topups_used' => $topupsUsed,
                'attempts' => $attempts,
                'stalled_runs' => $stalledRuns,
                'verifications' => $verifications,
                'completed' => $completed,
                'sealed' => $sealed,
                'internal_batches' => $internalBatches,
                // Keep legacy fields readable during a rolling deployment.
                'submitted' => $submittedTotal,
                'plv_confirmed' => $startAcceptedTotal,
                'pld_confirmed' => $playAcceptedTotal,
                'submitted_seconds' => $reportedSecondsTotal,
                'listen_songs_before' => $baseline,
                'listen_songs_after' => $currentListenSongs,
                'listen_songs_delta' => $actualProgressBefore,
                'protocol_wait_seconds' => $protocolWaitSeconds,
                'updated_at' => date('c'),
            ]);
        };

        if ($actualProgressBefore >= $target) {
            $persist(true, true);
            return $this->makeResult(200,
                '网易云每日300首打卡成功 | 进度 ' . $actualProgressBefore . '/' . $target
                . ' | 累计 ' . $listenSongs,
                [
                    'submitted' => 0,
                    'daily_target' => $target,
                    'daily_confirmed' => $actualProgressBefore,
                    'daily_actual_progress' => $actualProgressBefore,
                    'daily_remaining' => 0,
                    'target_reached' => true,
                    'skipped_duplicate' => true,
                    'attempts' => $attempts,
                    'internal_batches' => 0,
                    'retry_after_seconds' => 0,
                    'protocol_wait_seconds' => 0,
                    'listen_songs_before' => $listenSongs,
                    'listen_songs_after' => $listenSongs,
                    'listen_songs_delta' => 0,
                ]
            );
        }

        // `sealed` is written only by this implementation. It prevents a
        // manual duplicate trigger after a final failure from submitting the
        // same day's replacement songs again. Legacy states (including an
        // exhausted `topups_used` counter) are deliberately not sealed and get
        // one internal rescue run during the rolling deployment.
        if ($sameDay && !empty($dailyState['sealed'])) {
            $persist(false, true);
            return $this->makeResult(201,
                '网易云每日300首打卡失败 | 进度 ' . $actualProgressBefore . '/' . $target
                . ' | 本日任务已结束，未再提交新歌',
                [
                    'submitted' => 0,
                    'verification_only' => true,
                    'daily_target' => $target,
                    'daily_confirmed' => $actualProgressBefore,
                    'daily_actual_progress' => $actualProgressBefore,
                    'daily_remaining' => $remainingBefore,
                    'daily_submitted_total' => $submittedTotal,
                    'target_reached' => false,
                    'sealed' => true,
                    'attempts' => $attempts,
                    'retry_after_seconds' => 0,
                    'protocol_wait_seconds' => 0,
                ]
            );
        }

        $refresh = function () use (
            &$currentListenSongs,
            &$observedBefore,
            &$actualProgressBefore,
            &$remaining,
            &$lastDelta,
            $baseline,
            $target
        ): int {
            $previous = $currentListenSongs;
            $probe = $this->dakaListenSongs($currentListenSongs);
            if ($probe['code'] !== 200) {
                return $probe['code'];
            }
            $currentListenSongs = max($currentListenSongs, $probe['listen_songs']);
            $lastDelta = max(0, $currentListenSongs - $previous);
            $observedBefore = max($observedBefore, $currentListenSongs);
            $actualProgressBefore = min($target, max(0, $observedBefore - $baseline));
            $remaining = max(0, $target - $actualProgressBefore);
            return 200;
        };

        for ($batchIndex = 0; $batchIndex < $maxBatches && $remaining > 0; $batchIndex++) {
            // Never rush a replacement into the endpoint while the previous
            // batch may still be settling. The wait is internal, so the
            // scheduler sees one final result and writes one log row.
            if ($batchIndex > 0 && $remaining > 0 && $lastDelta <= 0 && $settlementWait > 0) {
                $this->waitDakaSettlement($settlementWait);
                $protocolWaitSeconds += $settlementWait;
                $probeCode = $refresh();
                if ($probeCode !== 200) {
                    $reason = $this->cookiezt ? '登录状态已失效' : '累计听歌核验失败';
                    break;
                }
                if ($remaining === 0) {
                    break;
                }
            }

            $available = max(0, $submissionCeiling - $submittedTotal);
            if ($available <= 0) {
                $reason = '已达到本次补齐安全上限';
                break;
            }
            $candidateLimit = min(1000, max(1, min($remaining, $available)));
            $candidates = $this->dakaCandidates($source, $submittedToday, $candidateLimit);
            $candidateCountTotal += count($candidates);
            if ($this->cookiezt) {
                $reason = '登录状态已失效';
                break;
            }
            if ($this->dakaHistorySeedIncomplete) {
                $reason = '读取历史听歌记录失败';
                break;
            }
            $songs = array_slice($candidates, 0, $candidateLimit, true);
            if ($songs === []) {
                $reason = '未获取到可用于补齐的新歌曲';
                break;
            }

            $hadSubmitted = $submittedTotal > 0;
            try {
                $success = max(0, (int)$this->weblogScrobbleBatch($songs));
            } catch (Throwable $exception) {
                $reason = '播放上报异常';
                break;
            }
            $internalBatches++;
            $submitted = count($songs);
            $submittedThisRun += $submitted;
            $startAcceptedThisRun += (int)$this->lastScrobbleStarts;
            $playAcceptedThisRun += $success;
            $reportedSecondsThisRun += (int)$this->lastScrobbleSeconds;
            $submittedTotal += $submitted;
            $startAcceptedTotal += (int)$this->lastScrobbleStarts;
            $playAcceptedTotal += $success;
            $reportedSecondsTotal += (int)$this->lastScrobbleSeconds;
            $attempts++;
            if ($hadSubmitted && $submitted > 0) {
                $topupsUsed++;
            }
            foreach ($songs as $song) {
                $songId = (int)($song['id'] ?? 0);
                if ($songId > 0) {
                    $submittedToday[$songId] = true;
                }
            }
            if ($success > 0) {
                $this->rememberDakaSongs($this->lastScrobbleSongIds);
            }
            foreach ($this->lastScrobbleRejections as $key => $count) {
                $runRejections[$key] = ($runRejections[$key] ?? 0) + (int)$count;
            }

            $probeCode = $refresh();
            if ($probeCode !== 200) {
                $reason = $this->cookiezt ? '登录状态已失效' : '累计听歌核验失败';
                $lastDelta = 0;
            } elseif ($lastDelta > 0) {
                $noProgressBatches = 0;
                $stalledRuns = 0;
            } else {
                $noProgressBatches++;
                $stalledRuns++;
            }
            $verifications++;
            $persist(false, false);

            if ($this->cookiezt || $probeCode !== 200 || $remaining === 0) {
                break;
            }
            if ($noProgressBatches >= $maxIdleBatches) {
                $reason = '累计听歌未产生新增';
                break;
            }
        }

        // One last bounded settlement check catches the common case where the
        // endpoint acknowledges the batch before the profile counter catches
        // up. It never schedules another external invocation.
        if ($remaining > 0 && $internalBatches > 0 && !$this->cookiezt
            && $lastDelta <= 0 && $settlementWait > 0) {
            $this->waitDakaSettlement($settlementWait);
            $protocolWaitSeconds += $settlementWait;
            $probeCode = $refresh();
            if ($probeCode !== 200) {
                $reason = $this->cookiezt ? '登录状态已失效' : '累计听歌核验失败';
            } elseif ($remaining === 0) {
                $reason = '';
            }
        }

        $completed = $remaining === 0;
        if (!$completed && $reason === '') {
            $reason = '本次内部补齐未达到目标';
        }
        $persist($completed, true);

        $message = '网易云每日300首打卡' . ($completed ? '成功' : '失败')
            . ' | 进度 ' . $actualProgressBefore . '/' . $target
            . ' | 本次内部批次 ' . $internalBatches
            . ' | 本次上报 ' . $submittedThisRun
            . ' | 累计 ' . $listenSongs . '→' . $currentListenSongs;
        if ($reason !== '' && !$completed) {
            $message .= ' | ' . $reason;
        }
        $rejectionSummary = '';
        if ($runRejections !== []) {
            $parts = [];
            foreach ($runRejections as $key => $count) {
                $parts[] = $key . '×' . $count;
            }
            $rejectionSummary = implode(' ', $parts);
            $message .= ' | 拒绝 ' . $rejectionSummary;
        }

        return $this->makeResult($completed ? 200 : 201, $message, [
            'submitted' => $submittedThisRun,
            'candidate_count' => $candidateCountTotal,
            'plv_confirmed' => $startAcceptedThisRun,
            'pld_confirmed' => $playAcceptedThisRun,
            'startplay_accepted' => $startAcceptedThisRun,
            'play_accepted' => $playAcceptedThisRun,
            'submitted_seconds' => $reportedSecondsThisRun,
            'reported_play_seconds' => $reportedSecondsThisRun,
            'elapsed_seconds' => round(microtime(true) - $startedAt, 3),
            'protocol_wait_seconds' => $protocolWaitSeconds,
            'report_mode' => 'api-enhanced-scrobble',
            'listen_songs_before' => $listenSongs,
            'listen_songs_after' => $currentListenSongs,
            'listen_songs_delta' => max(0, $currentListenSongs - $listenSongs),
            'daily_target' => $target,
            'daily_confirmed' => $actualProgressBefore,
            'daily_actual_progress' => $actualProgressBefore,
            'daily_remaining' => $remaining,
            'daily_submitted_total' => $submittedTotal,
            'repeat_submitted' => 0,
            'repeat_submitted_total' => $repeatSubmittedTotal,
            'topups_used' => $topupsUsed,
            'rejections' => $runRejections,
            'target_reached' => $completed,
            'attempts' => $attempts,
            'stalled_runs' => $stalledRuns,
            'internal_batches' => $internalBatches,
            'sealed' => true,
            // daka_new never asks the outer scheduler to create a second log.
            'retry_after_seconds' => 0,
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
