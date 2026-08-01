<?php

declare(strict_types=1);

namespace app\service;

use app\admin\model\Weblist;
use think\facade\Db;
use Throwable;

final class BarkNotificationService
{
    private UserNotificationSettings $settings;
    private BarkClient $client;

    public function __construct(
        ?UserNotificationSettings $settings = null,
        ?BarkClient $client = null
    ) {
        $this->settings = $settings ?? new UserNotificationSettings();
        $this->client = $client ?? new BarkClient();
    }

    public function sendTest($user): array
    {
        return $this->sendForUser(
            $user,
            'LoopDeck Bark 测试',
            'Bark 信息推送配置成功，后续账号失效和会员到期提醒会发送到此设备。'
        );
    }

    public function sendAccountInvalid($user, string $provider): array
    {
        return $this->sendForUser(
            $user,
            '账号失效提醒',
            '您的 ' . trim($provider) . ' 账号状态已失效，请登录 LoopDeck 更新凭据。'
        );
    }

    public function sendVipExpired($user): array
    {
        return $this->sendForUser(
            $user,
            '会员到期提醒',
            '您的 LoopDeck 会员已到期，请登录后续费。'
        );
    }

    public function sendForUser($user, string $title, string $body): array
    {
        $data = $this->userData($user);
        $uid = (int)($data['uid'] ?? 0);
        $webId = (int)($data['web_id'] ?? 0);
        if ($uid <= 0 || $webId <= 0) {
            return $this->skipped('用户信息无效');
        }

        try {
            $site = $this->site($webId);
            if (!$site['bark_enabled']) {
                return $this->skipped('管理员未开启 Bark 推送');
            }
            $token = $this->settings->barkToken($uid, $webId);
            if ($token === '') {
                return $this->skipped('用户未配置 Bark Token');
            }
            $siteName = trim((string)$site['webname']) ?: 'LoopDeck';
            return $this->client->send(
                $token,
                $siteName . ' - ' . trim($title),
                $body,
                $siteName,
                (string)$site['url']
            );
        } catch (Throwable $exception) {
            return [
                'success' => false,
                'status' => 0,
                'message' => 'Bark 推送配置不可用',
                'response' => [],
            ];
        }
    }

    /** @return array{bark_enabled:bool,webname:string,url:string} */
    private function site(int $webId): array
    {
        $site = Weblist::where('web_id', $webId)->field('web_id,prefix,webname,domain')->find();
        if (!$site) {
            return ['bark_enabled' => false, 'webname' => 'LoopDeck', 'url' => ''];
        }

        $table = Weblist::configTableName((string)$site['prefix']);
        $enabled = false;
        if ($table !== null) {
            $enabled = (int)Db::table($table)->where('k', 'bark_enabled')->value('v') === 1;
        }

        $domain = trim((string)$site['domain']);
        if ($domain !== '' && !preg_match('#\Ahttps?://#i', $domain)) {
            $domain = 'https://' . $domain;
        }
        if ($domain !== '' && !filter_var($domain, FILTER_VALIDATE_URL)) {
            $domain = '';
        }

        return [
            'bark_enabled' => $enabled,
            'webname' => (string)$site['webname'],
            'url' => $domain,
        ];
    }

    private function userData($user): array
    {
        if (is_array($user)) {
            return $user;
        }
        if (is_object($user) && method_exists($user, 'toArray')) {
            $data = $user->toArray();
            return is_array($data) ? $data : [];
        }
        return [];
    }

    private function skipped(string $message): array
    {
        return [
            'success' => false,
            'status' => 0,
            'message' => $message,
            'response' => [],
        ];
    }
}
