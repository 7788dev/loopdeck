<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use app\service\TaskMessage;

function taskMessageCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// 完成项合并：多个 done 只产生一个"已完成"短句。
taskMessageCheck(
    TaskMessage::compose([
        ['label' => '浏览商城', 'status' => TaskMessage::DONE],
        ['label' => '云贝推歌', 'status' => TaskMessage::DONE],
    ]) === '浏览商城、云贝推歌已完成',
    'done items were not merged into one fragment'
);

// 部分失败：完成项在前，失败项套 {label}失败 模板；没有重试机制时不得出现重试字眼。
taskMessageCheck(
    TaskMessage::compose([
        ['label' => '浏览商城', 'status' => TaskMessage::DONE],
        ['label' => '发布主创说', 'status' => TaskMessage::FAILED],
    ]) === '浏览商城已完成，发布主创说失败',
    'partial failure did not compose completed work first without a retry promise'
);

// 真有分钟级重试机制的结果才允许"将自动重试"，且整句只出现一次。
taskMessageCheck(
    TaskMessage::compose([
        ['label' => '已确认', 'status' => TaskMessage::FAILED, 'text' => '已确认 120/300'],
        ['label' => '剩余作品', 'status' => TaskMessage::FAILED, 'retry' => true],
    ]) === '已确认 120/300，剩余作品失败，将自动重试',
    'retry suffix was not appended exactly once for retryable failures'
);

// 多个失败项合并成一个失败短句。
taskMessageCheck(
    TaskMessage::compose([
        ['label' => '浏览商城', 'status' => TaskMessage::FAILED],
        ['label' => '云贝推歌', 'status' => TaskMessage::FAILED],
    ]) === '浏览商城失败、云贝推歌失败',
    'multiple failures were not merged'
);

// 已办项合并："今日已签到、已分享"——前缀只出现一次。
taskMessageCheck(
    TaskMessage::compose([
        ['label' => '签到', 'status' => TaskMessage::ALREADY],
        ['label' => '分享', 'status' => TaskMessage::ALREADY],
    ]) === '今日已签到、已分享',
    'already items did not merge under a single prefix'
);

// 暂无/跳过/自定义短语。
taskMessageCheck(
    TaskMessage::compose([
        ['label' => '可领取的云贝奖励', 'status' => TaskMessage::NONE],
    ]) === '暂无可领取的云贝奖励',
    'none status did not use the 暂无 template'
);
taskMessageCheck(
    TaskMessage::compose([
        ['label' => '当前账号非大会员', 'status' => TaskMessage::SKIPPED],
    ]) === '当前账号非大会员已跳过',
    'skipped status did not use the skip template'
);
taskMessageCheck(
    TaskMessage::compose([
        ['label' => '投币', 'status' => TaskMessage::DONE, 'text' => '已投币 5 枚，硬币余额 545'],
        ['label' => '观看', 'status' => TaskMessage::DONE],
    ]) === '观看已完成，已投币 5 枚，硬币余额 545',
    'full text override did not keep its own wording'
);

// done_suffix 选项：礼包领取这类非"已完成"动词。
taskMessageCheck(
    TaskMessage::compose([
        ['label' => 'APP 礼包', 'status' => TaskMessage::DONE],
        ['label' => 'PC 礼包', 'status' => TaskMessage::DONE],
    ], ['done_suffix' => '已领取']) === 'APP 礼包、PC 礼包已领取',
    'done_suffix option was ignored'
);

// 未知状态按失败处理，绝不静默吞掉。
taskMessageCheck(
    TaskMessage::compose([
        ['label' => '神秘步骤', 'status' => 'weird'],
    ]) === '神秘步骤失败',
    'unknown status was not treated as a failure'
);

// 聚合任务拼接：去空、去重、全角分号。
taskMessageCheck(
    TaskMessage::join(['签到成功，云贝+5', '', '签到成功，云贝+5', '浏览商城已完成']) === '签到成功，云贝+5；浏览商城已完成',
    'join did not filter empties and duplicates'
);

// 钉住文案约定：组装器输出永不包含竖线键值对和"请求成功"这类空洞表述。
$probeItems = [
    [['label' => '签到', 'status' => TaskMessage::DONE], ['label' => '分享', 'status' => TaskMessage::FAILED, 'retry' => true]],
    [['label' => '登录', 'status' => TaskMessage::ALREADY]],
    [['label' => '礼包', 'status' => TaskMessage::NONE], ['label' => '心跳', 'status' => TaskMessage::SKIPPED]],
    [['status' => TaskMessage::NONE, 'text' => '投币经验 50/50']],
];
foreach ($probeItems as $index => $items) {
    $output = TaskMessage::compose($items);
    taskMessageCheck(!str_contains($output, '|'), "composed message contained a pipe separator (case $index)");
    taskMessageCheck(!str_contains($output, '请求成功'), "composed message contained the empty phrase 请求成功 (case $index)");
}

echo "Task message composer tests passed\n";
