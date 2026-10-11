<?php

declare(strict_types=1);

namespace app\service;

/**
 * Bounded scheduler retries.
 *
 * A job whose adapter keeps throwing used to be rescheduled forever at
 * minute-level cadence: every failure wrote another `[重试中]` log row and
 * another upstream call until the protocol changed back. The consecutive
 * failure count therefore lives in the job payload (`_scheduler_retry`,
 * mirroring how `_bilibili_verification` persists executor state) so every
 * scheduler entry point — unified scheduler, legacy platform schedulers,
 * CLI commands — shares one cap. A failure spaced further apart than the
 * window starts a fresh count, so a recovered job never accumulates stale
 * failures across days.
 */
final class SchedulerRetry
{
    public const DATA_KEY = '_scheduler_retry';

    /** Failures within this window count as one consecutive run. */
    private const DEFAULT_WINDOW_SECONDS = 7200;

    public static function cap(): int
    {
        return self::envInt('SCHEDULER_MAX_RETRIES', 10, 1, 100);
    }

    public static function windowSeconds(): int
    {
        return self::envInt('SCHEDULER_RETRY_WINDOW_SECONDS', self::DEFAULT_WINDOW_SECONDS, 60, 86400);
    }

    /**
     * Consecutive failures already recorded in the decoded job payload.
     *
     * @param array<string, mixed> $data
     */
    public static function attemptsIn(array $data): int
    {
        $state = $data[self::DATA_KEY] ?? null;
        if (!is_array($state)) {
            return 0;
        }

        return max(0, (int)($state['attempts'] ?? 0));
    }

    /**
     * Record one more failure against the decoded job payload.
     *
     * Returns the payload to persist (counter incremented) or null when the
     * cap is reached and the caller must finalize the job instead: disable it
     * and leave one final `[失败]` record. The original payload is never
     * mutated.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public static function dataAfterFailure(array $data, ?int $now = null): ?array
    {
        $now ??= time();
        $attempts = self::attemptsIn($data);
        $lastAt = self::lastFailureAt($data);
        if ($lastAt > 0 && ($now - $lastAt) > self::windowSeconds()) {
            $attempts = 0;
        }

        $attempts++;
        if ($attempts > self::cap()) {
            return null;
        }

        $data[self::DATA_KEY] = ['attempts' => $attempts, 'at' => $now];
        return $data;
    }

    /**
     * Payload without the retry tracker, for final records: a disabled job
     * must not carry a stale counter that outlives its failure streak.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function settledData(array $data): array
    {
        unset($data[self::DATA_KEY]);
        return $data;
    }

    /** User-facing copy for the final record written when the cap is reached. */
    public static function finalMessage(int $attempts): string
    {
        return sprintf('已连续失败 %d 次，已停止自动重试并停用任务，请排查后重新启用', max(1, $attempts));
    }

    /**
     * Payload after a run that returned a result (success or a reported
     * failure without an exception): the streak counts *consecutive*
     * execution exceptions, so a completed run drops the tracker and the
     * next exception starts from zero.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function dataAfterSuccess(array $data): array
    {
        unset($data[self::DATA_KEY]);
        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function lastFailureAt(array $data): int
    {
        $state = $data[self::DATA_KEY] ?? null;
        if (!is_array($state)) {
            return 0;
        }

        return max(0, (int)($state['at'] ?? 0));
    }

    private static function envInt(string $name, int $default, int $minimum, int $maximum): int
    {
        $value = getenv($name);
        if ($value === false || $value === '' || filter_var($value, FILTER_VALIDATE_INT) === false) {
            return max($minimum, min($maximum, $default));
        }

        return max($minimum, min($maximum, (int)$value));
    }
}
