<?php

declare(strict_types=1);

namespace bilibili;

use bilibili\sdk\Client;

class BiliHelper extends Bilibili
{
    public function __construct(
        $mid = null,
        $mid_md5 = null,
        $token = null,
        $csrf = null,
        $access_key = null,
        $config = [],
        ?Client $client = null
    ) {
        parent::__construct($mid, $mid_md5, $token, $csrf, $access_key, $config, $client);
    }

    public function globalroom(): array
    {
        return ['code' => 1, 'message' => '全局直播间配置已保存'];
    }

    public function manga(): array
    {
        return $this->combine([parent::manga_sign(), parent::manga_share()]);
    }

    public function dailybag(): array
    {
        return $this->combine([parent::dailyBagAPP(), parent::dailyBagPC()]);
    }

    public function doubleheart(): array
    {
        return $this->combine([parent::webHeart(), parent::appHeart()]);
    }

    public function groupsignIn(): array
    {
        $list = parent::getGroupList();
        if (($list['code'] ?? 0) !== 1) {
            return $list;
        }
        $groups = is_array($list['groups'] ?? null) ? $list['groups'] : [];
        if ($groups === []) {
            return ['code' => 1, 'message' => $list['message'] ?? '没有需要签到的应援团'];
        }
        $results = [];
        foreach ($groups as $group) {
            if (is_array($group)) {
                $results[] = parent::signInGroup($group);
            }
        }
        return $this->combine($results);
    }

    public function giftheart(): array
    {
        return parent::gift_heart();
    }

    public function dailytask(): array
    {
        return ['code' => 0, 'message' => '直播签到功能已下线'];
    }

    public function silver2coin(): array
    {
        return $this->combine([parent::pcSilver2coin(), parent::appSilver2coin()]);
    }

    private function combine(array $results): array
    {
        if ($results === []) {
            return ['code' => 1, 'message' => '没有需要执行的子任务'];
        }
        $success = true;
        $messages = [];
        foreach ($results as $result) {
            if (!is_array($result)) {
                $success = false;
                continue;
            }
            $success = $success && (int)($result['code'] ?? 0) === 1;
            if (!empty($result['message'])) {
                $messages[] = (string)$result['message'];
            }
        }
        return ['code' => $success ? 1 : 0, 'message' => implode('；', $messages)];
    }
}
