<?php

declare(strict_types=1);

namespace app\service;

/**
 * 用户日志详情文案组装器。
 *
 * 适配器不再手写完整句子，只上报每个子项的结构化状态，句子由这里的模板
 * 统一拼装。不同失败组合（哪个子项失败、失败几个）全部由模板处理，适配器
 * 侧禁止为组合硬编码文案；新增子项只需登记一个 label。
 *
 * "将自动重试"后缀是条件化的：只有真带分钟级重试机制的结果
 * （retry_after_seconds 或调度租约未推进）才允许 retry=true。普通失败
 * 由次日定时计划兜底，文案不得承诺当天重试。
 */
final class TaskMessage
{
    public const DONE = 'done';
    public const ALREADY = 'already';
    public const NONE = 'none';
    public const SKIPPED = 'skipped';
    public const FAILED = 'failed';

    /**
     * 按模板把子项状态拼成一句面向用户的详情文案。
     *
     * item 字段：
     * - label:  子项名，如"浏览商城"；done/already/failed 参与合并
     * - status: DONE/ALREADY/NONE/SKIPPED/FAILED，未知状态按失败处理
     * - retry:  仅 FAILED 有效，失败后是否真有分钟级自动重试
     * - text:   完整短语覆盖（如"已投币 5 枚，硬币余额 545"），
     *           覆盖后该子项不参与任何合并
     *
     * options：
     * - done_suffix:    完成后缀，默认"已完成"（如礼包任务传"已领取"）
     * - already_prefix: 已办前缀，默认"今日已"
     *
     * 输出顺序：完成项合并 → 已办项合并 → 其余短语（按传入顺序）→ 失败项。
     *
     * @param list<array{label?: string, status?: string, retry?: bool, text?: string|null}> $items
     * @param array{done_suffix?: string, already_prefix?: string} $options
     */
    public static function compose(array $items, array $options = []): string
    {
        $doneSuffix = (string)($options['done_suffix'] ?? '已完成');
        $alreadyPrefix = (string)($options['already_prefix'] ?? '今日已');

        $done = [];
        $already = [];
        $failed = [];
        $others = [];
        $retry = false;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $text = trim((string)($item['text'] ?? ''));
            if ($text !== '') {
                $others[] = $text;
                continue;
            }
            $label = trim((string)($item['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            switch ((string)($item['status'] ?? self::FAILED)) {
                case self::DONE:
                    $done[] = $label;
                    break;
                case self::ALREADY:
                    $already[] = $label;
                    break;
                case self::NONE:
                    $others[] = '暂无' . $label;
                    break;
                case self::SKIPPED:
                    $others[] = $label . '已跳过';
                    break;
                case self::FAILED:
                default:
                    $failed[] = $label . '失败';
                    $retry = $retry || !empty($item['retry']);
                    break;
            }
        }

        $fragments = [];
        if ($done !== []) {
            $fragments[] = implode('、', $done) . $doneSuffix;
        }
        if ($already !== []) {
            // "今日已签到、已分享"：前缀只出现一次，后续项复用它的尾字。
            $joiner = '、' . mb_substr($alreadyPrefix, -1);
            $fragments[] = $alreadyPrefix . implode($joiner, $already);
        }
        foreach ($others as $text) {
            $fragments[] = $text;
        }
        if ($failed !== []) {
            $fragments[] = implode('、', $failed) . ($retry ? '，将自动重试' : '');
        }

        return implode('，', $fragments);
    }

    /**
     * 独立子任务结果之间的拼接（网易云云贝/VIP 成长这类聚合任务）。
     *
     * @param list<string> $parts
     */
    public static function join(array $parts, string $glue = '；'): string
    {
        $parts = array_values(array_unique(array_filter(
            array_map(static fn($part): string => trim((string)$part), $parts),
            static fn(string $part): bool => $part !== ''
        )));

        return implode($glue, $parts);
    }
}
