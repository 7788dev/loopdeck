<?php

declare(strict_types=1);

namespace tieba;

use DOMDocument;
use DOMXPath;

class TiebaHelper
{
    public array $msg = [];
    public bool $cookiezt = false;

    private string $cookie = '';

    public function __construct($account = [], $pwd = '')
    {
        if (is_array($account)) {
            $this->cookie = trim((string)($account['cookie'] ?? ''));
        } elseif (is_string($account) && str_contains($account, '=')) {
            $this->cookie = trim($account);
        }
    }

    public function getAccountInfo(): array
    {
        $tbs = $this->fetchTbs();
        if ($this->cookiezt) {
            return [];
        }
        $profile = $this->request('GET', 'https://tieba.baidu.com/f/user/json_userinfo');
        $data = is_array($profile) ? ($profile['data'] ?? $profile) : [];
        $uid = (string)($data['user_id'] ?? $data['id'] ?? '');
        $name = (string)($data['user_name_show'] ?? $data['user_name'] ?? '');
        if ($uid === '') {
            $uid = substr(hash('sha256', $this->cookie), 0, 20);
        }
        return [
            'uid' => $uid,
            'displayname' => $name !== '' ? $name : '贴吧用户',
            'avatar' => (string)($data['portrait_url'] ?? $data['avatar_url'] ?? ''),
            'cookie' => $this->cookie,
            'tbs' => $tbs,
        ];
    }

    public function sign($signDelay = 0): array
    {
        $this->msg = [];
        $tbs = $this->fetchTbs();
        if ($this->cookiezt || $tbs === '') {
            $this->msg[] = '贴吧 Cookie 已失效';
            return ['code' => 0, 'message' => end($this->msg)];
        }

        $forums = $this->getLikedForums();
        if (!$forums) {
            $this->msg[] = '未获取到已关注贴吧，可能没有关注贴吧或接口已变更';
            return ['code' => 0, 'message' => end($this->msg)];
        }

        foreach ($forums as $forum) {
            $result = $this->request('POST', 'https://tieba.baidu.com/sign/add', [
                'ie' => 'utf-8',
                'kw' => $forum,
                'tbs' => $tbs,
            ]);
            $code = (int)($result['no'] ?? $result['error_code'] ?? -1);
            $message = (string)($result['error'] ?? $result['error_msg'] ?? '');
            if ($code === 0) {
                $this->msg[] = $forum . '：签到成功';
            } elseif (str_contains($message, '已签到') || $code === 1101) {
                $this->msg[] = $forum . '：今日已签到';
            } else {
                $this->msg[] = $forum . '：' . ($message !== '' ? $message : '签到失败');
            }
            if ((int)$signDelay > 0) {
                usleep(min((int)$signDelay, 3) * 100000);
            }
        }

        return ['code' => 1, 'message' => implode('；', $this->msg)];
    }

    public function SetCookie($cookie): self
    {
        $this->cookie = trim((string)$cookie);
        return $this;
    }

    public function GetTBS(): string
    {
        return $this->fetchTbs();
    }

    private function fetchTbs(): string
    {
        if ($this->cookie === '') {
            $this->cookiezt = true;
            return '';
        }
        $result = $this->request('GET', 'https://tieba.baidu.com/dc/common/tbs');
        if (!is_array($result) || empty($result['is_login']) || empty($result['tbs'])) {
            $this->cookiezt = true;
            return '';
        }
        return (string)$result['tbs'];
    }

    private function getLikedForums(): array
    {
        $html = $this->request('GET', 'https://tieba.baidu.com/f/like/mylike?pn=1', [], false);
        if (!is_string($html) || $html === '') {
            return [];
        }
        libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new DOMXPath($document);
        $forums = [];
        foreach ($xpath->query('//a[contains(@href, "/f?kw=")]') ?: [] as $link) {
            $href = (string)$link->getAttribute('href');
            parse_str((string)parse_url(html_entity_decode($href), PHP_URL_QUERY), $query);
            $name = trim((string)($query['kw'] ?? $link->textContent));
            if ($name !== '') {
                $forums[] = $name;
            }
        }
        libxml_clear_errors();
        return array_values(array_unique($forums));
    }

    private function request(string $method, string $url, array $params = [], bool $decodeJson = true)
    {
        $method = strtoupper($method);
        if ($method === 'GET' && $params) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_COOKIE => $this->cookie,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) OneTool/clean',
            CURLOPT_HTTPHEADER => [
                'Accept: application/json,text/html;q=0.9,*/*;q=0.8',
                'Referer: https://tieba.baidu.com/',
            ],
        ]);
        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($body) || $status < 200 || $status >= 300) {
            return $decodeJson ? [] : '';
        }
        if (!$decodeJson) {
            return $body;
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }
}
