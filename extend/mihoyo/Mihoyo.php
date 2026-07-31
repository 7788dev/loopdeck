<?php

declare(strict_types=1);

namespace mihoyo;

class Mihoyo
{
    public bool $cookiezt = false;

    private const ROLE_URL = 'https://api-takumi-record.mihoyo.com/binding/api/getUserGameRolesByCookie';
    private const SIGN_INFO_URL = 'https://api-takumi.mihoyo.com/event/bbs_sign_reward/info';
    private const SIGN_URL = 'https://api-takumi.mihoyo.com/event/bbs_sign_reward/sign';
    private const ACT_ID = 'e202311201442471';

    private array $account;
    private string $cookie;

    public function __construct(array $account = [])
    {
        $this->account = $account;
        $this->cookie = $this->buildCookie($account);
    }

    public function getUserGameRoles(): array
    {
        if ($this->cookie === '') {
            $this->cookiezt = true;
            return ['retcode' => -100, 'message' => '米游社 Cookie 为空', 'data' => ['list' => []]];
        }
        $result = $this->request('GET', self::ROLE_URL, ['game_biz' => 'hk4e_cn']);
        $this->detectInvalidCookie($result);
        return $result;
    }

    public function getAccountSummary(): array
    {
        $result = $this->getUserGameRoles();
        $roles = $result['data']['list'] ?? [];
        $ltuid = $this->cookieValue('ltuid_v2')
            ?: $this->cookieValue('ltuid')
            ?: $this->cookieValue('account_id_v2')
            ?: $this->cookieValue('account_id');
        return [
            'ltuid' => (string)$ltuid,
            'displayname' => (string)($roles[0]['nickname'] ?? ('米游社用户 ' . $ltuid)),
            'avatar' => (string)($roles[0]['game_biz'] ?? ''),
            'roles' => is_array($roles) ? $roles : [],
            'cookie' => $this->cookie,
        ];
    }

    public function genshin_sign(): array
    {
        $roleResult = $this->getUserGameRoles();
        $roles = $roleResult['data']['list'] ?? [];
        if (!is_array($roles) || !$roles) {
            return ['code' => 0, 'message' => $roleResult['message'] ?? '未找到原神角色'];
        }

        $messages = [];
        foreach ($roles as $role) {
            $region = (string)($role['region'] ?? '');
            $uid = (string)($role['game_uid'] ?? '');
            if ($region === '' || $uid === '') {
                continue;
            }
            $info = $this->request('GET', self::SIGN_INFO_URL, [
                'act_id' => self::ACT_ID,
                'region' => $region,
                'uid' => $uid,
            ]);
            if (($info['data']['is_sign'] ?? false) === true) {
                $messages[] = ($role['nickname'] ?? $uid) . ' 今日已签到';
                continue;
            }

            $result = $this->request('POST', self::SIGN_URL, [], [
                'act_id' => self::ACT_ID,
                'region' => $region,
                'uid' => $uid,
            ]);
            $this->detectInvalidCookie($result);
            $retcode = (int)($result['retcode'] ?? -1);
            if ($retcode === 0 || $retcode === -5003) {
                $messages[] = ($role['nickname'] ?? $uid) . ($retcode === -5003 ? ' 今日已签到' : ' 签到成功');
            } else {
                $messages[] = ($role['nickname'] ?? $uid) . '：' . ($result['message'] ?? '签到失败');
            }
        }

        return [
            'code' => $this->cookiezt ? 0 : 1,
            'message' => $messages ? implode('；', $messages) : '没有可签到的原神角色',
        ];
    }

    public function mihoyo_bbs_task(): array
    {
        return [
            'code' => 1,
            'message' => '米游社社区浏览/点赞模拟已停用以避免账号风控；原神签到不受影响。',
        ];
    }

    public function curl(
        string $method,
        string $url,
        array $params = [],
        string $cookie = '',
        array $headers = [],
        bool $json = true
    ) {
        $oldCookie = $this->cookie;
        if ($cookie !== '') {
            $this->cookie = $cookie;
        }
        $result = $this->request($method, $url, $params, $method === 'GET' ? null : $params, $headers, $json);
        $this->cookie = $oldCookie;
        return $result;
    }

    private function request(
        string $method,
        string $url,
        array $query = [],
        ?array $body = null,
        array $extraHeaders = [],
        bool $decodeJson = true
    ) {
        $method = strtoupper($method);
        if ($query) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        $headers = array_merge([
            'Accept: application/json',
            'Content-Type: application/json;charset=UTF-8',
            'Referer: https://act.mihoyo.com/',
            'Origin: https://act.mihoyo.com',
            'x-rpc-app_version: 2.71.1',
            'x-rpc-client_type: 5',
            'DS: ' . $this->makeDs(),
        ], $extraHeaders);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Linux; Android 12) miHoYoBBS/2.71.1',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_COOKIE => $this->cookie,
        ]);
        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body ?? [], JSON_UNESCAPED_UNICODE));
        }
        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($response) || $status < 200 || $status >= 300) {
            return $decodeJson ? ['retcode' => -1, 'message' => '米游社接口请求失败'] : '';
        }
        if (!$decodeJson) {
            return $response;
        }
        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : ['retcode' => -1, 'message' => '米游社返回数据格式错误'];
    }

    private function buildCookie(array $account): string
    {
        if (!empty($account['cookie'])) {
            return trim((string)$account['cookie']);
        }
        $fields = [
            'ltuid', 'ltoken', 'ltuid_v2', 'ltoken_v2', 'cookie_token',
            'cookie_token_v2', 'account_id', 'account_id_v2', 'login_uid',
            'login_ticket', 'stuid', 'stoken',
        ];
        $parts = [];
        foreach ($fields as $field) {
            if (isset($account[$field]) && $account[$field] !== '') {
                $parts[] = $field . '=' . $account[$field];
            }
        }
        return implode('; ', $parts);
    }

    private function cookieValue(string $name): string
    {
        if (preg_match('/(?:^|;\s*)' . preg_quote($name, '/') . '=([^;]+)/', $this->cookie, $match)) {
            return trim($match[1]);
        }
        return '';
    }

    private function makeDs(): string
    {
        $time = time();
        $random = (string)random_int(100000, 200000);
        $salt = 'h8w582wxwgqvahcdkpvdhbh2w9casgfl';
        return $time . ',' . $random . ',' . md5("salt={$salt}&t={$time}&r={$random}");
    }

    private function detectInvalidCookie(array $result): void
    {
        $retcode = (int)($result['retcode'] ?? 0);
        if (in_array($retcode, [-100, -101, -1071], true)) {
            $this->cookiezt = true;
        }
    }
}
