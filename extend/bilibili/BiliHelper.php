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

    public function manga(): array
    {
        return $this->compose([
            ['签到', parent::manga_sign()],
            ['分享', parent::manga_share()],
        ]);
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
                'text' => in_array($status, [TaskMessage::FAILED, TaskMessage::NONE, TaskMessage::SKIPPED], true)
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
