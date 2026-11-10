<?php

declare(strict_types=1);

namespace bilibili\sdk;

use app\service\TaskMessage;

/** Daily sign-in and the three observed, non-purchase VIP point tasks. */
final class VipPoints
{
    private const TASK_LABELS = [
        'bonus' => '福利积分',
        'privilege' => '浏览大会员权益',
        'dress-view' => '浏览装扮商城',
    ];

    private Client $client;
    private array $config;

    public function __construct(Client $client, array $config = [])
    {
        $this->client = $client;
        $this->config = $config;
    }

    public function run(): array
    {
        $submitted = self::submittedTasks($this->config['points_submitted'] ?? []);
        $response = $this->client->vipPointTasks();
        if (($response['code'] ?? -1) !== 0) {
            return $this->readFailure($response, '积分任务读取失败', $submitted);
        }
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $vip = is_array($data['vip_info'] ?? null) ? $data['vip_info'] : [];
        if (in_array($vip['status'] ?? null, [2, 3], true)) {
            return ['code' => 0, 'message' => '大会员权益已冻结或封禁，无法执行积分任务'];
        }
        if (!in_array($vip['status'] ?? null, [0, 1], true)
            || !in_array($vip['type'] ?? null, [0, 1, 2], true)) {
            return $this->readFailure($response, '大会员积分身份状态暂未确认', $submitted);
        }
        if ($vip['status'] === 0 || $vip['type'] === 0) {
            return ['code' => 1, 'message' => '当前账号非大会员，积分任务已跳过'];
        }

        $before = $this->snapshot($data);
        if ($before === null) {
            return $this->readFailure($response, '积分任务或余额数据不完整，暂不执行', $submitted);
        }
        $newSign = $before['sign_group'] === 3;
        $signBefore = $newSign ? $this->client->vipPointSignState() : $response;
        $signedBefore = $this->signed($signBefore, $newSign);
        if ($signedBefore === null) {
            return $this->readFailure($signBefore, '今日积分签到状态暂未确认', $submitted);
        }

        $attempted = [];
        $errors = [];
        $notSubmitted = [];
        $actions = $signedBefore ? [] : ['sign'];
        foreach (self::TASK_LABELS as $code => $label) {
            if (($before['tasks'][$code]['state'] ?? null) === 1) {
                $actions[] = $code;
            }
        }
        foreach ($actions as $code) {
            if (in_array($code, $submitted, true)) {
                continue;
            }
            // Record before calling: a lost response cannot prove that the
            // upstream did not commit. Verification must never repeat it.
            $submitted[] = $code;
            $attempted[] = $code;
            $action = $code === 'sign'
                ? $this->client->vipPointSign($newSign)
                : $this->client->completeVipPointTask($code);
            if (($action['request_submitted'] ?? null) === false) {
                // Only a local preflight failure proves that no action POST
                // occurred. A transport failure after POST keeps its marker.
                $submitted = array_values(array_diff($submitted, [$code]));
                $notSubmitted[] = $code;
            }
            if (($action['code'] ?? -1) !== 0) {
                $errors[$code] = $this->reason($action, '请求结果暂未确认');
                if ($this->client->isAuthenticationFailure($action)) {
                    return $this->failure($action, '积分任务登录状态失效') + ['points_submitted' => $submitted];
                }
                // A risk challenge is an explicit refusal, not delayed
                // settlement. End this run so a scheduled check cannot go on
                // to submit the other tasks before the user resolves it.
                if ((int)($action['code'] ?? -1) === 73319) {
                    return $this->failure($action, '积分任务需在哔哩哔哩客户端完成验证后重试')
                        + ['points_submitted' => $submitted];
                }
            }
        }

        $afterResponse = $attempted === [] ? $response : $this->client->vipPointTasks();
        $after = ($afterResponse['code'] ?? -1) === 0 && is_array($afterResponse['data'] ?? null)
            ? $this->snapshot($afterResponse['data']) : null;
        $signAfter = $newSign && $attempted !== [] ? $this->client->vipPointSignState()
            : ($newSign ? $signBefore : $afterResponse);
        $signedAfter = $this->signed($signAfter, $newSign);
        if ($after === null || $signedAfter === null) {
            $failedRead = $after === null ? $afterResponse : $signAfter;
            return $this->readFailure($failedRead, '积分到账状态暂未确认', $submitted, $notSubmitted !== []);
        }

        $items = [];
        $pending = $notSubmitted !== [];
        $failed = false;
        if ($signedAfter) {
            $items[] = ['label' => '积分签到', 'status' => $signedBefore ? TaskMessage::ALREADY : TaskMessage::DONE];
        } else {
            $pending = $pending || in_array('sign', $submitted, true);
            $failed = true;
            $items[] = ['status' => TaskMessage::NONE, 'text' => '积分签到尚未确认'
                . (isset($errors['sign']) ? '：' . $errors['sign'] : '')];
        }

        foreach (self::TASK_LABELS as $code => $label) {
            $state = $after['tasks'][$code]['state'] ?? null;
            $wasSubmitted = in_array($code, $submitted, true);
            if ($state === 3) {
                $items[] = ['label' => $label, 'status' => TaskMessage::DONE];
            } elseif ($wasSubmitted) {
                $pending = true;
                $failed = true;
                $items[] = ['status' => TaskMessage::NONE, 'text' => $label . '积分尚未确认'
                    . (isset($errors[$code]) ? '：' . $errors[$code] : '')];
            } elseif (isset($errors[$code])) {
                $failed = true;
                $items[] = ['status' => TaskMessage::NONE, 'text' => $label . '暂未提交：' . $errors[$code]];
            } elseif ($state === 0) {
                $items[] = ['status' => TaskMessage::SKIPPED, 'text' => $label . '任务待在客户端领取，已跳过'];
            } elseif (!isset($after['tasks'][$code])) {
                $items[] = ['status' => TaskMessage::SKIPPED, 'text' => $label . '任务未开放，已跳过'];
            } else {
                $failed = true;
                $items[] = ['status' => TaskMessage::NONE, 'text' => $label . '任务状态暂未确认'];
            }
        }

        $unsupported = array_diff(array_keys($after['tasks']), array_keys(self::TASK_LABELS));
        if ($unsupported !== []) {
            $items[] = ['status' => TaskMessage::SKIPPED,
                'text' => '手机专属、消费及未接入的积分任务已跳过'];
        }
        $balance = '大会员积分余额 ' . $after['balance'];
        if ($after['balance'] > $before['balance']) {
            $balance .= '（本次增加 ' . ($after['balance'] - $before['balance']) . '）';
        }
        $items[] = ['status' => TaskMessage::NONE, 'text' => $balance];
        return [
            'code' => $failed ? 0 : 1,
            'pending_verification' => $pending,
            'points_submitted' => $submitted,
            'message' => TaskMessage::compose($items),
        ];
    }

    /** Persist only known action identifiers; never accept an upstream task URL or arbitrary task code. */
    public static function submittedTasks(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_unique(array_filter($value,
            static fn($code): bool => is_string($code) && in_array($code, ['sign', 'bonus', 'privilege', 'dress-view'], true))));
    }

    private function snapshot(array $data): ?array
    {
        $balance = $data['point_info']['point'] ?? null;
        $info = is_array($data['task_info'] ?? null) ? $data['task_info'] : [];
        if (!is_int($balance) || $balance < 0 || !is_array($info['modules'] ?? null)
            || !in_array($info['exp_value'] ?? null, [0, 1, 2, 3], true)) {
            return null;
        }
        $tasks = [];
        foreach ($info['modules'] as $module) {
            if (!is_array($module) || !is_array($module['common_task_item'] ?? null)) {
                return null;
            }
            foreach ($module['common_task_item'] as $task) {
                if (!is_array($task) || !is_string($task['task_code'] ?? null)
                    || isset($tasks[$task['task_code']])) {
                    return null;
                }
                $tasks[$task['task_code']] = $task;
            }
        }
        return ['balance' => $balance, 'tasks' => $tasks, 'sign_group' => $info['exp_value']];
    }

    private function signed(array $response, bool $newSign): ?bool
    {
        if (($response['code'] ?? -1) !== 0) {
            return null;
        }
        if ($newSign) {
            $signed = $response['data']['three_day_sign']['signed'] ?? null;
            return is_bool($signed) ? $signed : null;
        }
        $histories = $response['data']['task_info']['sing_task_item']['histories'] ?? [];
        if (!is_array($histories)) {
            return null;
        }
        foreach ($histories as $day) {
            if (is_array($day) && ($day['is_today'] ?? false) === true) {
                return is_bool($day['signed'] ?? null) ? $day['signed'] : null;
            }
        }
        return null;
    }

    private function reason(array $response, string $fallback): string
    {
        $reason = trim((string)($response['message'] ?? $response['msg'] ?? ''));
        return $reason !== '' && $reason !== '0' ? $reason : $fallback;
    }

    private function failure(array $response, string $fallback): array
    {
        return [
            'code' => 0,
            'message' => TaskMessage::join([$fallback, $this->reason($response, $fallback)]),
            'account_invalid' => $this->client->isAuthenticationFailure($response),
        ];
    }

    private function readFailure(array $response, string $fallback, array $submitted, bool $retryPreflight = false): array
    {
        return $this->failure($response, $fallback) + [
            'pending_verification' => ($submitted !== [] || $retryPreflight || !empty($this->config['verification_only']))
                && !$this->client->isAuthenticationFailure($response),
            'points_submitted' => $submitted,
        ];
    }
}
