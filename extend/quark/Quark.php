<?php

declare(strict_types=1);

namespace quark;

use app\service\CheckinAdapterBase;
use app\service\PlatformException;

/**
 * 夸克网盘 daily sign-in ("签到领空间").
 *
 * Protocol checked 2026-09-23. Signing with only the web Cookie stopped
 * working in 2025-12 (agluo/ql-script-hub #36, #43); the growth endpoints now
 * take the kps/sign/vcode values captured once from the Quark app
 * (BNDou/Auto_Check_In checkIn_Quark.py 2025-11, Cp0204/quark-auto-save
 * 2026-04, sign-ins confirmed 2026-01 to 2026-06). The web Cookie still serves
 * account/info and member (ctwj/urldb 2026-09, OpenList quark_uc 2026-01) and
 * is only needed for the identity and the used-space overview.
 */
final class Quark extends CheckinAdapterBase
{
    protected const HOSTS = ['pan.quark.cn', 'drive-m.quark.cn', 'drive.quark.cn'];
    private const APP_QUERY = ['pr' => 'ucpro', 'fr' => 'android'];
    private const WEB_HEADERS = ['Referer' => 'https://pan.quark.cn/', 'Origin' => 'https://pan.quark.cn'];
    private const MAX_UNVERIFIED_RUNS = 3;
    private const MEMBER_TYPES = ['NORMAL' => '普通用户', 'EXP_SVIP' => '体验超级会员', 'SUPER_VIP' => '超级会员',
        'Z_VIP' => 'Z 会员', 'VIP' => '会员'];

    public function authenticate(array $credentials): array
    {
        $cookie = $this->secret($credentials, 'cookie');
        $uid = self::cookieValue($cookie, '__uid') ?? self::cookieValue($cookie, '__kps');
        if ($uid === null) {
            throw new PlatformException('Cookie 中缺少 __uid，请在已登录的 pan.quark.cn 复制完整 Cookie', true, 0);
        }
        $this->credentials = ['cookie' => $cookie] + self::appParameters((string)($credentials['app_url'] ?? ''));
        $info = $this->account();
        $name = self::mask(trim((string)$info['nickname']) ?: '夸克用户');
        return $this->identity(substr(hash('sha256', 'quark:' . $uid), 0, 32), $name, $this->overview(true),
            (string)($info['avatarUri'] ?? ''));
    }

    public function profile(array $account): array
    {
        $this->restore($account);
        return $this->overview(false);
    }

    public function execute(array $account, array $state = [], ?callable $checkpoint = null): array
    {
        $this->restore($account);
        $today = date('Y-m-d');
        if (($state['date'] ?? '') !== $today) {
            $state = ['date' => $today, 'unverified' => 0];
        }
        $sign = self::capSign($this->growth());
        if (!array_key_exists('sign_daily', $sign)) {
            throw new PlatformException('夸克签到状态缺失，未提交签到');
        }
        if (self::yes($sign['sign_daily'])) {
            return $this->result(true, '签到=今日已完成 | 连签进度=' . self::progress($sign), $state, 0, self::summary($sign));
        }
        $response = $this->jsonResponse('POST', 'https://drive-m.quark.cn/1/clouddrive/capacity/growth/sign', [
            'query' => self::APP_QUERY + $this->appQuery(), 'json' => ['sign_cyclic' => true],
        ]);
        self::assertAppLogin($response);
        // Upstream answers a same-day duplicate with HTTP 400, so the growth
        // state, not the sign response, decides the outcome.
        $verified = self::capSign($this->growth());
        if (!self::yes($verified['sign_daily'] ?? false)) {
            $state['unverified'] = (int)($state['unverified'] ?? 0) + 1;
            if ($checkpoint !== null) {
                $checkpoint($state);
            }
            $final = $state['unverified'] >= self::MAX_UNVERIFIED_RUNS;
            return $this->result(false, '签到=已提交 | 核验=' . ($final ? '平台多次未确认今日签到' : '等待平台确认'),
                $state, $final ? 0 : 1800, self::summary($verified));
        }
        $reward = $response['data']['data']['sign_daily_reward'] ?? $verified['sign_daily_reward'] ?? null;
        return $this->result(true, '签到=完成 | 空间奖励=' . self::bytes($reward) . ' | 连签进度=' . self::progress($verified),
            $state, 0, self::summary($verified) + ['reward' => is_numeric($reward) ? (int)$reward : 0]);
    }

    /** Accept the captured request URL or `kps=…; sign=…; vcode=…` text. */
    private static function appParameters(string $input): array
    {
        $input = trim($input);
        if ($input === '' || strlen($input) > 8192) {
            throw new PlatformException('请粘贴从夸克 App 抓取的签到请求地址', true, 0);
        }
        $query = preg_match('#\Ahttps?://#i', $input) === 1 ? (string)parse_url($input, PHP_URL_QUERY) : $input;
        $pairs = [];
        foreach (preg_split('/[&;\s]+/', $query) ?: [] as $part) {
            [$name, $value] = array_pad(explode('=', $part, 2), 2, '');
            $pairs[strtolower(trim($name))] = rawurldecode(trim($value));
        }
        $parameters = [];
        foreach (['kps', 'sign', 'vcode'] as $name) {
            $value = (string)($pairs[$name] ?? '');
            if (preg_match('/\A[A-Za-z0-9+\/=_\-.]{4,2048}\z/', $value) !== 1) {
                throw new PlatformException('签到参数缺少 ' . $name . '，请粘贴抓包得到的完整请求地址', true, 0);
            }
            $parameters[$name] = $value;
        }
        return $parameters;
    }

    private function appQuery(): array
    {
        return ['kps' => $this->secret($this->credentials, 'kps', 2048),
            'sign' => $this->secret($this->credentials, 'sign', 2048),
            'vcode' => $this->secret($this->credentials, 'vcode', 2048)];
    }

    private function growth(): array
    {
        $response = $this->jsonResponse('GET', 'https://drive-m.quark.cn/1/clouddrive/capacity/growth/info',
            ['query' => self::APP_QUERY + $this->appQuery()]);
        self::assertAppLogin($response);
        if ($response['status'] !== 200 || (int)($response['data']['code'] ?? -1) !== 0
            || !is_array($response['data']['data'] ?? null)) {
            throw new PlatformException('夸克签到信息读取失败，稍后重试');
        }
        return $response['data']['data'];
    }

    private static function assertAppLogin(array $response): void
    {
        if ($response['status'] === 401 || (string)($response['data']['code'] ?? '') === '31001') {
            throw new PlatformException('夸克签到参数已失效，请在 App 中重新抓取后更新账号', true, 0);
        }
    }

    /** @return array{nickname:string,avatarUri?:string} */
    private function account(): array
    {
        $response = $this->web('https://pan.quark.cn/account/info', ['fr' => 'pc', 'platform' => 'pc']);
        $data = $response['data']['data'] ?? null;
        if (!is_array($data) || !is_string($data['nickname'] ?? null) || $data['nickname'] === '') {
            throw new PlatformException('夸克 Cookie 已失效或不完整，请重新复制', true, 0);
        }
        return $data;
    }

    private function overview(bool $strict): array
    {
        $growth = $this->growth();
        $sign = self::capSign($growth);
        $total = is_numeric($growth['total_capacity'] ?? null) ? (int)$growth['total_capacity'] : null;
        $used = null;
        $memberType = (string)($growth['member_type'] ?? '');
        $cookieValid = true;
        try {
            $member = $this->web('https://drive.quark.cn/1/clouddrive/member', ['pr' => 'ucpro', 'fr' => 'pc',
                'uc_param_str' => '', 'fetch_subscribe' => 'false', '_ch' => 'home', 'fetch_identity' => 'false']);
            $data = is_array($member['data']['data'] ?? null) ? $member['data']['data'] : [];
            $total = is_numeric($data['total_capacity'] ?? null) ? (int)$data['total_capacity'] : $total;
            $used = is_numeric($data['use_capacity'] ?? null) ? (int)$data['use_capacity'] : null;
            $memberType = (string)($data['member_type'] ?? $memberType);
        } catch (PlatformException $exception) {
            // Only the used-space figure depends on the web Cookie.
            if ($strict) {
                throw $exception;
            }
            $cookieValid = !$exception->accountInvalid;
        }
        $rows = [
            ['label' => '会员类型', 'value' => self::MEMBER_TYPES[$memberType] ?? ($memberType !== '' ? '会员' : '未知')],
            ['label' => '总容量', 'value' => self::bytes($total)],
            ['label' => '已用空间', 'value' => $cookieValid ? self::bytes($used) : '网页 Cookie 已过期（不影响签到）'],
            ['label' => '今日签到', 'value' => self::yes($sign['sign_daily'] ?? false) ? '已签到' : '未签到'],
            ['label' => '连签进度', 'value' => self::progress($sign)],
            ['label' => '今日奖励', 'value' => self::bytes($sign['sign_daily_reward'] ?? null)],
        ];
        return ['rows' => $rows, 'stats' => array_filter(['capacity_total' => $total, 'capacity_used' => $used],
            'is_int') + self::summary($sign)];
    }

    /** Cookie requests; rotated __puus/__pus values are written back. */
    private function web(string $url, array $query): array
    {
        $response = $this->jsonResponse('GET', $url, ['query' => $query,
            'headers' => self::WEB_HEADERS + ['Cookie' => $this->secret($this->credentials, 'cookie')]]);
        if ($response['status'] === 401 || (string)($response['data']['code'] ?? '') === '31001') {
            throw new PlatformException('夸克网页 Cookie 已失效，请重新复制', true, 0);
        }
        if ($response['status'] !== 200) {
            throw new PlatformException('夸克账号信息读取失败，稍后重试');
        }
        $this->rotateCookies($response['headers']);
        return $response;
    }

    private function rotateCookies(array $headers): void
    {
        $cookie = (string)($this->credentials['cookie'] ?? '');
        $updated = $cookie;
        foreach ($headers as $name => $values) {
            if (strcasecmp((string)$name, 'Set-Cookie') !== 0) {
                continue;
            }
            foreach ((array)$values as $line) {
                if (!preg_match('/\A(__puus|__pus)=([^;\s]+)/', (string)$line, $match)) {
                    continue;
                }
                $pattern = '/(^|;\s*)' . preg_quote($match[1], '/') . '=[^;]*/';
                $updated = preg_match($pattern, $updated) === 1
                    ? (string)preg_replace($pattern, '${1}' . $match[1] . '=' . $match[2], $updated, 1)
                    : rtrim($updated, "; \t") . '; ' . $match[1] . '=' . $match[2];
            }
        }
        if ($updated !== $cookie && strlen($updated) <= 8192) {
            $this->saveCredentials(['cookie' => $updated]);
        }
    }

    private static function cookieValue(string $cookie, string $name): ?string
    {
        if (preg_match('/(?:^|;\s*)' . preg_quote($name, '/') . '=([^;\s]+)/', $cookie, $match) !== 1) {
            return null;
        }
        return $match[1];
    }

    private static function capSign(array $growth): array
    {
        return is_array($growth['cap_sign'] ?? null) ? $growth['cap_sign'] : [];
    }

    private static function progress(array $sign): string
    {
        return max(0, (int)($sign['sign_progress'] ?? 0)) . '/' . max(0, (int)($sign['sign_target'] ?? 0));
    }

    private static function summary(array $sign): array
    {
        return ['signed_today' => self::yes($sign['sign_daily'] ?? false),
            'sign_progress' => max(0, (int)($sign['sign_progress'] ?? 0)),
            'sign_target' => max(0, (int)($sign['sign_target'] ?? 0)),
            'sign_reward' => is_numeric($sign['sign_daily_reward'] ?? null) ? (int)$sign['sign_daily_reward'] : 0];
    }
}
