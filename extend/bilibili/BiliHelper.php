<?php

declare(strict_types=1);

namespace bilibili;

use app\service\TaskMessage;
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
        return $this->compose([
            ['签到', parent::manga_sign()],
            ['分享', parent::manga_share()],
        ]);
    }

    public function dailybag(): array
    {
        return $this->compose([
            ['APP 礼包', parent::dailyBagAPP()],
            ['PC 礼包', parent::dailyBagPC()],
        ], ['done_suffix' => '已领取']);
    }

    public function doubleheart(): array
    {
        return $this->compose([
            ['PC 心跳', parent::webHeart()],
            ['APP 心跳', parent::appHeart()],
        ]);
    }

    public function groupsignIn(): array
    {
        $list = parent::getGroupList();
        if (($list['code'] ?? 0) !== 1) {
            return $list;
        }
        $groups = is_array($list['groups'] ?? null) ? $list['groups'] : [];
        if ($groups === []) {
            return ['code' => 1, 'message' => '应援团今日均已签到'];
        }
        $signed = 0;
        $intimacy = 0;
        $failures = [];
        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }
            $result = parent::signInGroup($group);
            if ((int)($result['code'] ?? 0) === 1) {
                $signed++;
                $intimacy += (int)($result['add_num'] ?? 0);
                continue;
            }
            $message = trim((string)($result['message'] ?? ''));
            if ($message !== '') {
                $failures[] = $message;
            }
        }
        if ($signed === 0) {
            return [
                'code' => 0,
                'message' => '应援团签到失败',
                'failures' => $failures,
            ];
        }
        return [
            'code' => $failures === [] ? 1 : 0,
            'message' => '已为 ' . $signed . ' 个应援团签到，亲密度+' . $intimacy
                . ($failures === [] ? '' : '，部分应援团签到失败'),
            'failures' => $failures,
        ];
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
        return $this->compose([
            ['PC', parent::pcSilver2coin()],
            ['APP', parent::appSilver2coin()],
        ], ['done_suffix' => '端银瓜子已兑换为硬币']);
    }

    public function dailyexperience(): array
    {
        return parent::dailyExperience();
    }

    public function vipexperience(): array
    {
        return parent::vipExperience();
    }

    /**
     * 把多个子任务结果按 TaskMessage 模板合成一条详情文案。失败子项保留
     * 适配器的短句；成功子项只按状态合并，不再各自成句。
     */
    private function compose(array $pairs, array $options = []): array
    {
        $items = [];
        $success = true;
        foreach ($pairs as $pair) {
            [$label, $result] = $pair;
            if (!is_array($result)) {
                $success = false;
                $items[] = ['label' => $label, 'status' => TaskMessage::FAILED];
                continue;
            }
            $code = (int)($result['code'] ?? 0);
            $success = $success && $code === 1;
            $status = (string)($result['status'] ?? ($code === 1 ? TaskMessage::DONE : TaskMessage::FAILED));
            $items[] = [
                'label' => $label,
                'status' => $status,
                'text' => $status === TaskMessage::FAILED
                    ? trim((string)($result['message'] ?? ''))
                    : null,
            ];
        }
        if ($items === []) {
            return ['code' => 1, 'message' => '没有需要执行的子任务'];
        }
        return [
            'code' => $success ? 1 : 0,
            'message' => TaskMessage::compose($items, $options),
        ];
    }
}
