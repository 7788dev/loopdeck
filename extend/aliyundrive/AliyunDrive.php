<?php

declare(strict_types=1);

namespace aliyundrive;

use app\service\CheckinAdapterBase;
use app\service\PlatformException;

/**
 * 阿里云盘 daily sign-in with a web refresh token.
 *
 * Protocol checked 2026-09-23 against OpenList drivers/aliyundrive (2026-01),
 * power721/alist-tvbox AccountService (2026-09) and Se7eni aliyun_signin.py
 * (2026-08). Calling sign_in_list performs today's sign-in; a log's `day` is
 * the sign-in ordinal, so today's entry is `day == signInCount`. Claiming the
 * rewards has required the mobile app's native request signature since
 * 2024-05 and is deliberately not attempted.
 */
final class AliyunDrive extends CheckinAdapterBase
{
    protected const HOSTS = ['auth.alipan.com', 'api.alipan.com', 'member.aliyundrive.com'];
    private const TOKEN_URL = 'https://auth.alipan.com/v2/account/token';
    private const SIGN_URL = 'https://member.aliyundrive.com/v1/activity/sign_in_list';
    private const HEADERS = ['Referer' => 'https://www.alipan.com/', 'Origin' => 'https://www.alipan.com'];
    private const MAX_UNVERIFIED_RUNS = 3;

    public function authenticate(array $credentials): array
    {
        $this->credentials = ['refresh_token' => self::refreshToken($credentials)];
        $token = $this->token(true);
        if (empty($token['user_id']) || !isset($token['nick_name'])) {
            $token = $this->authorized('https://api.alipan.com/v2/user/get', []) + $token;
        }
        $name = trim((string)($token['nick_name'] ?? '')) ?: trim((string)($token['user_name'] ?? ''));
        $name = self::mask($name !== '' ? $name : '阿里云盘用户');
        return $this->identity((string)($token['user_id'] ?? ''), $name,
            $this->overview($name), (string)($token['avatar'] ?? ''));
    }

    /** Read-only: must never call sign_in_list, which signs the account in. */
    public function profile(array $account): array
    {
        $this->restore($account);
        $this->token();
        return $this->overview((string)($account['nickname'] ?? ''));
    }

    public function execute(array $account, array $state = [], ?callable $checkpoint = null): array
    {
        $this->restore($account);
        $today = date('Y-m-d');
        if (($state['date'] ?? '') !== $today) {
            $state = ['date' => $today, 'unverified' => 0];
        }
        $this->token();
        $response = $this->authorized(self::SIGN_URL, ['isReward' => false], true, ['_rx-s' => 'mobile']);
        $result = $response['result'] ?? null;
        if (!self::yes($response['success'] ?? false) || !is_array($result)
            || !is_array($result['signInLogs'] ?? null) || !is_numeric($result['signInCount'] ?? null)) {
            throw new PlatformException('阿里云盘签到未返回有效记录，稍后重试');
        }
        $count = max(0, (int)$result['signInCount']);
        $signedToday = false;
        $unclaimed = 0;
        $track = [];
        foreach (array_slice(array_values(array_filter($result['signInLogs'], 'is_array')), 0, 31) as $log) {
            $day = (int)($log['day'] ?? 0);
            $signed = (string)($log['status'] ?? '') === 'normal';
            $claimed = self::yes($log['isReward'] ?? false);
            if ($signed && !$claimed) {
                $unclaimed++;
            }
            if ($signed && $day > 0 && $day === $count) {
                $signedToday = true;
            }
            $reward = is_array($log['reward'] ?? null) ? $log['reward'] : [];
            $track[] = ['day' => $day, 'signed' => $signed, 'missed' => (string)($log['status'] ?? '') === 'miss',
                'claimed' => $signed && $claimed,
                'reward' => mb_substr(trim((string)($reward['name'] ?? $reward['description'] ?? '')), 0, 24)];
        }
        $summary = ['month' => date('Y-m'), 'count' => $count, 'unclaimed' => $unclaimed, 'track' => $track];
        if (!$signedToday) {
            $state['unverified'] = (int)($state['unverified'] ?? 0) + 1;
            if ($checkpoint !== null) {
                $checkpoint($state);
            }
            $final = $state['unverified'] >= self::MAX_UNVERIFIED_RUNS;
            return $this->result(false, '签到=已提交 | 核验=' . ($final ? '多次未找到今日签到记录' : '等待平台记录今日签到'),
                $state, $final ? 0 : 1800, $summary);
        }
        $message = '签到=完成 | 本月累计=' . $count . '天';
        if ($unclaimed > 0) {
            $message .= ' | 待领取奖励=' . $unclaimed . '项（请在阿里云盘 App 内领取）';
        }
        return $this->result(true, $message, $state, 0, $summary);
    }

    /** Accept the bare token or the whole `token` JSON object from localStorage. */
    private static function refreshToken(array $input): string
    {
        $value = is_scalar($input['refresh_token'] ?? null) ? trim((string)$input['refresh_token']) : '';
        if (str_starts_with($value, '{')) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) && is_string($decoded['refresh_token'] ?? null) ? trim($decoded['refresh_token']) : '';
        }
        $value = trim($value, " \t\r\n\"'");
        if (preg_match('/\A[A-Za-z0-9._\-]{16,512}\z/', $value) !== 1) {
            throw new PlatformException('Refresh Token 格式不正确，请复制 token 中 refresh_token 的值', true, 0);
        }
        return $value;
    }

    private function token(bool $force = false): array
    {
        if (!$force && !empty($this->credentials['access_token'])
            && (int)($this->credentials['expires_at'] ?? 0) > time() + 120) {
            return [];
        }
        $response = $this->jsonResponse('POST', self::TOKEN_URL, ['headers' => self::HEADERS, 'json' => [
            'grant_type' => 'refresh_token', 'refresh_token' => $this->secret($this->credentials, 'refresh_token', 512),
        ]]);
        $data = $response['data'];
        if ($response['status'] !== 200 || !is_string($data['access_token'] ?? null) || $data['access_token'] === '') {
            if (in_array($response['status'], [400, 401], true)) {
                throw new PlatformException('阿里云盘 Refresh Token 已失效，请重新获取后更新账号', true, 0);
            }
            throw new PlatformException('阿里云盘登录刷新失败，稍后重试');
        }
        // The refresh token rotates on every use: persist it before any other
        // call can fail, or the account would be locked out.
        $this->saveCredentials([
            'access_token' => $data['access_token'],
            'refresh_token' => is_string($data['refresh_token'] ?? null) && $data['refresh_token'] !== ''
                ? $data['refresh_token'] : $this->credentials['refresh_token'],
            'expires_at' => time() + max(300, min(86400, (int)($data['expires_in'] ?? 7200))),
        ]);
        return $data;
    }

    private function authorized(string $url, array $body, bool $retry = true, array $query = []): array
    {
        $response = $this->jsonResponse('POST', $url, ['query' => $query,
            'headers' => self::HEADERS + ['Authorization' => 'Bearer ' . $this->secret($this->credentials, 'access_token', 4096)],
            'json' => $body === [] ? new \stdClass() : $body,
        ]);
        if ($response['status'] === 401) {
            if ($retry) {
                $this->token(true);
                return $this->authorized($url, $body, false, $query);
            }
            throw new PlatformException('阿里云盘登录状态已失效，请更新账号', true, 0);
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new PlatformException('阿里云盘拒绝了请求，稍后重试');
        }
        return $response['data'];
    }

    private function overview(string $name): array
    {
        try {
            $space = $this->authorized('https://api.alipan.com/adrive/v1/user/driveCapacityDetails', []);
        } catch (PlatformException $exception) {
            if ($exception->accountInvalid) {
                throw $exception;
            }
            $space = [];
        }
        $total = is_numeric($space['drive_total_size'] ?? null) ? (int)$space['drive_total_size'] : null;
        $used = is_numeric($space['drive_used_size'] ?? null) ? (int)$space['drive_used_size'] : null;
        return ['rows' => [
            ['label' => '账号昵称', 'value' => $name !== '' ? $name : '阿里云盘用户'],
            ['label' => '总容量', 'value' => self::bytes($total)],
            ['label' => '已用空间', 'value' => self::bytes($used)],
            ['label' => '登录状态', 'value' => '正常（令牌自动续期）'],
        ], 'stats' => array_filter(['capacity_total' => $total, 'capacity_used' => $used], 'is_int')];
    }
}
