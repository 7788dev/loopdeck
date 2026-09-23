<?php

declare(strict_types=1);

namespace app\service;

final class AccountProfileFetcher
{
    public function fetch(array $account): array
    {
        $data = safe_unserialize_array((string)$account['data']);
        if ($account['type'] === 'netease') {
            $client = new \netease\Netease($account['user_id'], $data['csrf'] ?? '', $data['musicu'] ?? '',
                ['sdk' => ['timeout' => 8.0, 'connect_timeout' => 3.0]]);
            $info = $client->getMusicUserInfo();
            if (!is_array($info)) {
                throw new PlatformException('暂时无法获取网易云等级信息', (bool)$client->cookiezt);
            }
            $level = max(0, (int)($info['level'] ?? 0));
            return ['signature' => (string)($info['profile']['signature'] ?? ''), 'rows' => [
                ['label' => '当前等级', 'value' => 'LV ' . $level],
                ['label' => '累计听歌', 'value' => max(0, (int)($info['listenSongs'] ?? 0)) . ' 首'],
                ['label' => '还需听歌', 'value' => $level >= 10 ? '已满级' : max(0,
                    (int)($info['nextPlayCount'] ?? 0) - (int)($info['nowPlayCount'] ?? 0)) . ' 首'],
                ['label' => '还需登录', 'value' => $level >= 10 ? '已满级' : max(0,
                    (int)($info['nextLoginCount'] ?? 0) - (int)($info['nowLoginCount'] ?? 0)) . ' 天'],
            ]];
        }
        if ($account['type'] === 'bilibili') {
            $credentials = BilibiliTaskExecutor::normalizeAccountData($data);
            if ($credentials === null) {
                throw new PlatformException('账号凭据不完整，请重新登录', true, 0);
            }
            $client = new \bilibili\Bilibili($credentials['mid'], $credentials['mid_md5'],
                $credentials['token'], $credentials['csrf'], $credentials['access_key'],
                ['sid' => $credentials['sid'], 'timeout' => 8.0, 'connect_timeout' => 3.0]);
            $response = $client->sdk()->nav();
            $info = (array)($response['data'] ?? []);
            if ($client->sdk()->isAuthenticationFailure($response)
                || (($response['code'] ?? -1) === 0 && array_key_exists('isLogin', $info) && !$info['isLogin'])) {
                throw new PlatformException('登录状态已失效，请更新账号', true, 0);
            }
            if (($response['code'] ?? -1) !== 0 || empty($info['isLogin'])) {
                throw new PlatformException('暂时无法获取哔哩哔哩等级信息');
            }
            $level = (array)($info['level_info'] ?? []);
            $current = max(0, (int)($level['current_level'] ?? 0));
            return ['rows' => [
                ['label' => '当前等级', 'value' => 'LV ' . $current],
                ['label' => '硬币数', 'value' => (string)($info['money'] ?? '0') . ' 个'],
                ['label' => '当前经验', 'value' => (string)max(0, (int)($level['current_exp'] ?? 0))],
                ['label' => '升级所需经验', 'value' => $current >= 6 ? '已满级' : (string)max(0,
                    (int)($level['next_exp'] ?? 0) - (int)($level['current_exp'] ?? 0))],
            ]];
        }
        return (new CheckinAccounts())->profile($account);
    }
}
