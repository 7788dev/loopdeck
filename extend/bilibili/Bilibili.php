<?php

declare(strict_types=1);

namespace bilibili;

use bilibili\sdk\Client;

class Bilibili
{
    public bool $cookiezt = false;

    protected ?string $mid;
    protected ?string $midMd5;
    protected ?string $token;
    protected ?string $csrf;
    protected string $accessKey;
    protected array $config;
    protected Client $client;
    protected array $lastNavFailure = [];

    public function __construct(
        $mid = null,
        $mid_md5 = null,
        $token = null,
        $csrf = null,
        $access_key = null,
        $config = [],
        ?Client $client = null
    ) {
        $this->mid = $mid !== null ? (string)$mid : null;
        $this->midMd5 = $mid_md5 !== null ? (string)$mid_md5 : null;
        $this->token = $token !== null ? (string)$token : null;
        $this->csrf = $csrf !== null ? (string)$csrf : null;
        $this->accessKey = (string)($access_key ?? '');
        $this->config = is_array($config) ? $config : [];
        $cookies = array_filter([
            'DedeUserID' => $this->mid,
            'DedeUserID__ckMd5' => $this->midMd5,
            'SESSDATA' => $this->token,
            'bili_jct' => $this->csrf,
            'sid' => $this->config['sid'] ?? null,
            'buvid3' => $this->config['buvid3'] ?? null,
            'buvid4' => $this->config['buvid4'] ?? null,
        ], static fn($value): bool => $value !== null && $value !== '');
        $this->client = $client ?? new Client($cookies, [
            'access_key' => $this->accessKey,
            'android_version' => $this->config['android_version'] ?? null,
            'android_build' => $this->config['android_build'] ?? null,
        ]);
    }

    public function sdk(): Client
    {
        return $this->client;
    }

    /**
     * APP access_key is no longer derived through the removed third-party login hack.
     * Cookie login is sufficient for the current documented workflows.
     */
    public function getAccessToken($cookie = ''): string
    {
        return $this->accessKey;
    }

    public function geetest(): array
    {
        $response = $this->client->captcha();
        if (($response['code'] ?? -1) !== 0 || empty($response['data'])) {
            return $this->failure($response, '获取人机验证参数失败');
        }
        return ['code' => 1, 'message' => '获取成功', 'data' => $response['data']];
    }

    public function login($data = []): array
    {
        return ['code' => 0, 'message' => '哔哩哔哩账密登录已停用，请使用扫码或短信登录'];
    }

    public function getQrimg(): array
    {
        $response = $this->client->qrGenerate();
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        if (($response['code'] ?? -1) !== 0 || empty($data['url']) || empty($data['qrcode_key'])) {
            return $this->failure($response, '获取二维码失败');
        }
        return [
            'code' => 1,
            'message' => '获取成功',
            'url' => (string)$data['url'],
            'oauthKey' => (string)$data['qrcode_key'],
            'qrcode_key' => (string)$data['qrcode_key'],
        ];
    }

    public function qrLogin($key): array
    {
        $key = trim((string)$key);
        if ($key === '') {
            return ['code' => 0, 'message' => '二维码登录密钥不能为空'];
        }

        $response = $this->client->qrPoll($key);
        if (($response['code'] ?? -1) !== 0) {
            return $this->failure($response, '二维码状态查询失败');
        }
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        switch ((int)($data['code'] ?? -1)) {
            case 0:
                return $this->loginSuccess((string)($data['refresh_token'] ?? ''));
            case 86101:
                return ['code' => -1, 'message' => '请使用哔哩哔哩 APP 扫描二维码'];
            case 86090:
                return ['code' => -2, 'message' => '已扫码，请在哔哩哔哩 APP 中确认登录'];
            case 86038:
                return ['code' => 800, 'message' => '二维码已失效，请重新获取'];
            case 86039:
            case 86087:
                return ['code' => 800, 'message' => '二维码登录已取消，请重新获取'];
            default:
                return ['code' => 0, 'message' => (string)($data['message'] ?? '未知二维码状态')];
        }
    }

    public function sendSms(string $phone, array $captcha, int $cid = 86): array
    {
        $response = $this->client->smsSend($phone, $captcha, $cid);
        if (($response['code'] ?? -1) !== 0 || empty($response['data']['captcha_key'])) {
            return $this->failure($response, '短信验证码发送失败');
        }
        return [
            'code' => 1,
            'message' => '短信验证码已发送',
            'data' => ['captcha_key' => (string)$response['data']['captcha_key']],
        ];
    }

    public function smsLogin(
        string $phone,
        string $code,
        string $captchaKey,
        int $cid = 86,
        array $context = []
    ): array
    {
        $response = $this->client->smsLogin($phone, $code, $captchaKey, $cid, $context);
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        if (($response['code'] ?? -1) !== 0) {
            return $this->failure($response, '短信登录失败');
        }
        if ((int)($data['status'] ?? 0) === 5) {
            return ['code' => 0, 'message' => '短信登录触发哔哩哔哩安全验证，请改用扫码登录'];
        }
        if ((int)($data['status'] ?? 0) !== 0) {
            return $this->failure($response, '短信登录失败');
        }

        $tokenInfo = is_array($data['token_info'] ?? null) ? $data['token_info'] : $data;
        return $this->loginSuccess(
            (string)($tokenInfo['refresh_token'] ?? $data['refresh_token'] ?? ''),
            (string)($tokenInfo['access_token'] ?? $data['access_token'] ?? '')
        );
    }

    /** @return array{header:string,body:string,status:int,set_cookie:array<int,string>} */
    public function login_info(): array
    {
        $response = $this->client->nav();
        return [
            'header' => '',
            'body' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            'status' => 200,
            'set_cookie' => [],
        ];
    }

    public function watchAid(): array
    {
        if ($this->authenticatedNav() === null) {
            return $this->invalidAccount();
        }
        $reward = $this->client->dailyReward();
        if (($reward['code'] ?? -1) === 0 && !empty($reward['data']['watch'])) {
            return ['code' => 1, 'message' => '主站任务：今日观看任务已完成'];
        }

        $video = $this->selectVideos(1, 'random')[0] ?? null;
        if ($video === null) {
            return ['code' => 0, 'message' => '主站任务：未找到可观看的视频'];
        }
        $start = $this->client->startVideo($video);
        if (($start['code'] ?? -1) !== 0) {
            return $this->failure($start, '主站任务：开始观看失败');
        }
        $played = max(1, min((int)($video['duration'] ?? 60), 60));
        $heartbeat = $this->client->videoHeartbeat($video, $played);
        $history = $this->client->historyReport($video, $played);
        if (($heartbeat['code'] ?? -1) !== 0 && ($history['code'] ?? -1) !== 0) {
            return $this->failure($heartbeat, '主站任务：观看进度上报失败');
        }
        return ['code' => 1, 'message' => '主站任务：av' . $video['aid'] . ' 观看成功'];
    }

    public function shareAid(): array
    {
        if ($this->authenticatedNav() === null) {
            return $this->invalidAccount();
        }
        $reward = $this->client->dailyReward();
        if (($reward['code'] ?? -1) === 0 && !empty($reward['data']['share'])) {
            return ['code' => 1, 'message' => '主站任务：今日分享任务已完成'];
        }
        $video = $this->selectVideos(1, 'random')[0] ?? null;
        if ($video === null) {
            return ['code' => 0, 'message' => '主站任务：未找到可分享的视频'];
        }
        $response = $this->client->shareVideo((int)$video['aid']);
        if (($response['code'] ?? -1) === 0 || (int)($response['code'] ?? 0) === 71000) {
            return ['code' => 1, 'message' => '主站任务：av' . $video['aid'] . ' 分享成功'];
        }
        return $this->failure($response, '主站任务：av' . $video['aid'] . ' 分享失败');
    }

    public function coinAdd(): array
    {
        $nav = $this->authenticatedNav();
        if ($nav === null) {
            return $this->invalidAccount();
        }
        $estimate = max(0, min(5, (int)($this->config['add_coin_num'] ?? 0)));
        if ($estimate === 0) {
            return ['code' => 0, 'message' => '主站任务：投币数量未配置'];
        }
        $coinExp = $this->client->todayCoinExp();
        $used = ($coinExp['code'] ?? -1) === 0 ? intdiv(max(0, (int)($coinExp['data'] ?? 0)), 10) : 0;
        $stock = max(0, (int)floor((float)($nav['money'] ?? 0)));
        if ($used >= $estimate) {
            return ['code' => 1, 'message' => '主站任务：今日投币已达到配置数量'];
        }
        if ($stock <= 0) {
            return ['code' => 1, 'message' => '主站任务：硬币余额不足'];
        }
        if ($used >= 5) {
            return ['code' => 1, 'message' => '主站任务：今日投币经验已满'];
        }
        $target = min(max(0, $estimate - $used), $stock, max(0, 5 - $used));

        $mode = ($this->config['add_coin_mode'] ?? 'random') === 'fixed' ? 'fixed' : 'random';
        $videos = $this->selectVideos($target, $mode);
        if ($videos === []) {
            return ['code' => 0, 'message' => '主站任务：未找到可投币的视频'];
        }

        $success = 0;
        $errors = [];
        foreach (array_slice($videos, 0, $target) as $video) {
            $response = $this->client->coinVideo((int)$video['aid']);
            if (($response['code'] ?? -1) === 0) {
                $success++;
                continue;
            }
            if ((int)($response['code'] ?? 0) === 34005) {
                break;
            }
            $errors[] = (string)($response['message'] ?? $response['msg'] ?? '未知错误');
        }
        if ($success === 0 && $errors !== []) {
            return ['code' => 0, 'message' => '主站任务：每日投币失败：' . implode('；', array_unique($errors))];
        }
        return [
            'code' => 1,
            'message' => "主站任务：每日投币，硬币库存 {$stock}，计划 {$target}，成功 {$success}",
        ];
    }

    public function manga_sign(): array
    {
        if ($this->authenticatedNav() === null) {
            return $this->invalidAccount();
        }
        $response = $this->client->mangaClockIn();
        $duplicateMessage = (string)($response['msg'] ?? $response['message'] ?? '');
        $duplicate = (($response['code'] ?? null) === 'invalid_argument'
                && str_contains(strtolower($duplicateMessage), 'duplicate'))
            || ((int)($response['code'] ?? 0) === 1 && str_contains($duplicateMessage, '不能重复签到'));
        if (($response['code'] ?? -1) === 0 || $duplicate) {
            return ['code' => 1, 'message' => $duplicate ? '漫画签到：今日已签到' : '漫画签到：成功'];
        }
        return $this->failure($response, '漫画签到：失败');
    }

    public function manga_share(): array
    {
        if ($this->authenticatedNav() === null) {
            return $this->invalidAccount();
        }
        $response = $this->client->mangaShare();
        if (($response['code'] ?? -1) === 0) {
            $message = (string)($response['msg'] ?? '');
            return ['code' => 1, 'message' => $message === '今日已分享' ? '漫画分享：今日已分享' : '漫画分享：成功'];
        }
        return $this->failure($response, '漫画分享：失败');
    }

    public function dailyBagPC(): array
    {
        return $this->liveCompatibility('PC 日常/周常礼包', fn(): array => $this->client->liveDailyBagPc());
    }

    public function dailyBagAPP(): array
    {
        return $this->liveCompatibility('APP 日常/周常礼包', fn(): array => $this->client->liveDailyBagApp());
    }

    public function webHeart(): array
    {
        $roomId = $this->config['global_room'] ?? 1;
        return $this->liveCompatibility('PC 在线心跳', fn(): array => $this->client->liveWebHeart($roomId));
    }

    public function appHeart(): array
    {
        $roomId = $this->config['global_room'] ?? 1;
        return $this->liveCompatibility('APP 在线心跳', fn(): array => $this->client->liveAppHeart($roomId));
    }

    public function getGroupList(): array
    {
        if ($this->authenticatedNav() === null) {
            return $this->invalidAccount();
        }
        $response = $this->client->liveGroupList();
        if (($response['code'] ?? -1) !== 0) {
            return $this->failure($response, '应援团列表获取失败');
        }
        $groups = is_array($response['data']['list'] ?? null) ? $response['data']['list'] : [];
        return [
            'code' => 1,
            'message' => $groups === [] ? '没有需要签到的应援团' : '获取应援团列表成功',
            'groups' => $groups,
        ];
    }

    public function signInGroup(array $groupInfo): array
    {
        $response = $this->client->liveGroupSign($groupInfo);
        $name = (string)($groupInfo['group_name'] ?? $groupInfo['group_id'] ?? '未知应援团');
        if (($response['code'] ?? -1) === 0 && (int)($response['data']['status'] ?? 0) === 0) {
            return [
                'code' => 1,
                'message' => '在应援团 ' . $name . ' 中签到成功，增加 ' . (int)($response['data']['add_num'] ?? 0) . ' 点亲密度',
            ];
        }
        return $this->failure($response, '在应援团 ' . $name . ' 中签到失败');
    }

    public function gift_heart(): array
    {
        $roomId = $this->config['global_room'] ?? 1;
        if ($this->authenticatedNav() === null) {
            return $this->invalidAccount();
        }
        $response = $this->client->liveGiftHeart($roomId);
        if (($response['code'] ?? -1) !== 0) {
            return $this->failure($response, '心跳礼物领取失败');
        }
        if ((int)($response['data']['heart_status'] ?? 0) === 0) {
            return ['code' => 1, 'message' => '心跳礼物：当前没有可领取的礼物'];
        }
        return ['code' => 1, 'message' => '心跳礼物领取请求成功'];
    }

    public function check_daily(): array
    {
        if ($this->authenticatedNav() === null) {
            return $this->invalidAccount();
        }
        return $this->client->liveSignInfo();
    }

    public function sign_info($info): array
    {
        if (is_array($info) && (int)($info['status'] ?? 0) === 1) {
            return ['code' => 1, 'message' => '直播签到：今日已签到'];
        }
        $response = $this->client->liveSign();
        if (($response['code'] ?? -1) === 0) {
            return ['code' => 1, 'message' => '直播签到：成功'];
        }
        return $this->failure($response, '直播签到失败（上游已标记该活动下线）');
    }

    public function appSilver2coin(): array
    {
        return $this->silverExchange('APP 银瓜子兑换硬币', fn(): array => $this->client->liveSilverToCoinApp());
    }

    public function pcSilver2coin(): array
    {
        return $this->silverExchange('PC 银瓜子兑换硬币', fn(): array => $this->client->liveSilverToCoinPc());
    }

    /** @return array<string,mixed>|null */
    protected function authenticatedNav(): ?array
    {
        $response = $this->client->nav();
        if (($response['code'] ?? -1) === 0 && !empty($response['data']['isLogin'])) {
            $this->lastNavFailure = [];
            return is_array($response['data']) ? $response['data'] : [];
        }

        $this->lastNavFailure = $response;
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $explicitlyLoggedOut = ($response['code'] ?? -1) === 0
            && array_key_exists('isLogin', $data)
            && empty($data['isLogin']);
        if ($explicitlyLoggedOut || $this->client->isAuthenticationFailure($response)) {
            $this->cookiezt = true;
        }
        return null;
    }

    /** @return array<int,array<string,mixed>> */
    protected function selectVideos(int $count, string $mode): array
    {
        $count = max(1, min(10, $count));
        $response = $mode === 'fixed' ? $this->client->dynamicFeed('video') : $this->client->popular(1, max(20, $count * 3));
        $candidates = $mode === 'fixed'
            ? $this->dynamicCandidates($response)
            : $this->popularCandidates($response);
        if ($candidates === [] && $mode === 'fixed') {
            $candidates = $this->popularCandidates($this->client->popular(1, max(20, $count * 3)));
        }

        $videos = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            $identity = (string)($candidate['aid'] ?? $candidate['bvid'] ?? '');
            if ($identity === '' || isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $detail = $this->client->videoDetail($identity);
            $view = $detail['data']['View'] ?? $detail['data'] ?? [];
            if (is_array($view) && !empty($view['aid']) && !empty($view['cid'])) {
                $videos[] = [
                    'aid' => (int)$view['aid'],
                    'bvid' => (string)($view['bvid'] ?? $candidate['bvid'] ?? ''),
                    'cid' => (int)$view['cid'],
                    'duration' => max(1, (int)($view['duration'] ?? $candidate['duration'] ?? 60)),
                ];
            } elseif (!empty($candidate['aid']) && !empty($candidate['cid'])) {
                $videos[] = $candidate;
            }
            if (count($videos) >= $count) {
                break;
            }
        }
        return $videos;
    }

    /** @return array<int,array<string,mixed>> */
    private function popularCandidates(array $response): array
    {
        $list = is_array($response['data']['list'] ?? null) ? $response['data']['list'] : [];
        $result = [];
        foreach ($list as $item) {
            if (!is_array($item) || empty($item['aid'])) {
                continue;
            }
            $result[] = [
                'aid' => (int)$item['aid'],
                'bvid' => (string)($item['bvid'] ?? ''),
                'cid' => (int)($item['cid'] ?? 0),
                'duration' => max(1, (int)($item['duration'] ?? 60)),
            ];
        }
        return $result;
    }

    /** @return array<int,array<string,mixed>> */
    private function dynamicCandidates(array $response): array
    {
        $items = is_array($response['data']['items'] ?? null) ? $response['data']['items'] : [];
        $result = [];
        foreach ($items as $item) {
            $archive = $item['modules']['module_dynamic']['major']['archive'] ?? [];
            if (!is_array($archive)) {
                continue;
            }
            $aid = $archive['aid'] ?? $item['basic']['rid_str'] ?? null;
            $bvid = $archive['bvid'] ?? '';
            if (!$aid && !$bvid) {
                continue;
            }
            $result[] = [
                'aid' => (int)($aid ?? 0),
                'bvid' => (string)$bvid,
                'cid' => (int)($archive['cid'] ?? 0),
                'duration' => max(1, (int)($archive['duration'] ?? 60)),
            ];
        }
        return $result;
    }

    private function liveCompatibility(string $label, callable $request): array
    {
        if ($this->authenticatedNav() === null) {
            return $this->invalidAccount();
        }
        $response = $request();
        if (($response['code'] ?? -1) === 0) {
            return ['code' => 1, 'message' => $label . '：请求成功'];
        }
        return $this->failure($response, $label . '：上游接口不可用或任务已下线');
    }

    private function silverExchange(string $label, callable $request): array
    {
        if ($this->authenticatedNav() === null) {
            return $this->invalidAccount();
        }
        $response = $request();
        if (($response['code'] ?? -1) === 0) {
            return ['code' => 1, 'message' => $label . '：请求成功'];
        }
        $message = (string)($response['message'] ?? $response['msg'] ?? '');
        if (str_contains($message, '余额不足')) {
            return ['code' => 1, 'message' => $label . '：银瓜子余额不足'];
        }
        return $this->failure($response, $label . '：兑换失败');
    }

    private function loginSuccess(string $refreshToken, string $accessKey = ''): array
    {
        $cookies = $this->client->cookies();
        foreach (['DedeUserID', 'DedeUserID__ckMd5', 'SESSDATA', 'bili_jct'] as $required) {
            if (empty($cookies[$required])) {
                return ['code' => 0, 'message' => '登录响应缺少必要 Cookie：' . $required];
            }
        }
        $nav = $this->client->nav();
        if (($nav['code'] ?? -1) !== 0 || empty($nav['data']['isLogin'])) {
            return $this->failure($nav, '登录 Cookie 校验失败');
        }
        $profile = $nav['data'];
        return [
            'code' => 1,
            'message' => '登录成功',
            'data' => [
                'cookie' => $this->client->cookieHeader(),
                'cookies' => $cookies,
                'nickname' => (string)($profile['uname'] ?? ''),
                'avatar' => (string)($profile['face'] ?? ''),
                'mid' => (string)$cookies['DedeUserID'],
                'mid_md5' => (string)$cookies['DedeUserID__ckMd5'],
                'token' => (string)$cookies['SESSDATA'],
                'csrf' => (string)$cookies['bili_jct'],
                'sid' => (string)($cookies['sid'] ?? ''),
                'access_key' => $accessKey,
                'refresh_token' => $refreshToken,
            ],
        ];
    }

    private function invalidAccount(): array
    {
        if ($this->cookiezt) {
            return ['code' => 0, 'message' => '哔哩哔哩登录状态已失效，请重新扫码登录'];
        }
        return $this->failure($this->lastNavFailure, '哔哩哔哩登录状态校验失败');
    }

    private function failure(array $response, string $fallback): array
    {
        $message = trim((string)($response['message'] ?? $response['msg'] ?? ''));
        $code = $response['code'] ?? null;
        if ($message === '' || $message === '0') {
            $message = $fallback;
        } elseif (!str_contains($fallback, $message)) {
            $message = $fallback . '：' . $message;
        }
        return ['code' => 0, 'message' => $message, 'upstream_code' => $code];
    }
}
