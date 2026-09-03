<?php

declare(strict_types=1);

/**
 * Regression tests for the legacy cron controllers after the in-process
 * execution refactor.
 *
 * Covers: the Epic full-table UPDATE regression ($job->where() drops the
 * primary-key condition and would disable every enabled job), per-job
 * exception isolation in Heybox/Netease/Bilibili/Epic, and the Heybox task
 * name whitelist.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

function cronSafetyCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);

// --- Epic: scoped UPDATE, isolated notify --------------------------------

$epicSource = file_get_contents($root . '/app/cron/controller/Epic.php');
cronSafetyCheck(is_string($epicSource), 'Unable to inspect the epic controller');

// A Model instance chained with where() loses its primary key condition, so
// '$job->where(...)->update(...)' was a full-table UPDATE. The disable must
// address the job by id.
cronSafetyCheck(
    !preg_match('/^\s*\$job->where\(/m', $epicSource),
    'epic still disables jobs through a full-table model UPDATE'
);
cronSafetyCheck(
    str_contains($epicSource, "Jobs::where('id', (int)\$job['id'])"),
    'epic does not disable the timing-less job by primary key'
);
// A mail/upstream exception must not abort the whole scheduling round.
cronSafetyCheck(
    str_contains($epicSource, 'try {')
        && str_contains($epicSource, '邮件通知异常'),
    'epic notify failures are not isolated per job'
);
cronSafetyCheck(
    str_contains($epicSource, 'use Throwable;'),
    'epic controller does not import Throwable'
);

// --- Heybox: whitelist + exception isolation ------------------------------

$heyboxSource = file_get_contents($root . '/app/cron/controller/Heybox.php');
cronSafetyCheck(is_string($heyboxSource), 'Unable to inspect the heybox controller');

// The task name dispatched to BlackBox must be whitelisted, not straight
// from the jobs.do column.
cronSafetyCheck(
    str_contains($heyboxSource, "in_array(\$do, self::TASKS, true)"),
    'heybox dispatches unwhitelisted task names to BlackBox'
);
// The sign task is the only defined heybox task today.
cronSafetyCheck(
    preg_match("/private const TASKS = \[[^\]]*'sign'/s", $heyboxSource) === 1,
    'heybox task whitelist does not include the sign task'
);
// An exception in one BlackBox call must not abort the whole round.
cronSafetyCheck(
    str_contains($heyboxSource, 'catch (Throwable $exception)'),
    'heybox runJob does not isolate exceptions'
);
cronSafetyCheck(
    str_contains($heyboxSource, '[重试中] 任务调度异常'),
    'heybox exceptions are not logged as retrying'
);
cronSafetyCheck(
    str_contains($heyboxSource, 'private function runJob(int $jobId')
        && str_contains($heyboxSource, 'if ($result === null)'),
    'heybox advances the normal schedule after a failed execution'
);
cronSafetyCheck(
    !str_contains($heyboxSource, 'Jobs::updateJobInfo(')
        && str_contains($heyboxSource, "->where('uid'"),
    'heybox job/account updates are not scoped to the current tenant and job'
);

// --- Netease: exception isolation -----------------------------------------

$neteaseSource = file_get_contents($root . '/app/cron/controller/Netease.php');
cronSafetyCheck(is_string($neteaseSource), 'Unable to inspect the netease controller');

cronSafetyCheck(
    str_contains($neteaseSource, 'catch (Throwable $exception)')
        && str_contains($neteaseSource, '[重试中] 任务调度异常'),
    'netease runJob does not isolate exceptions as retrying'
);
cronSafetyCheck(
    str_contains($neteaseSource, 'private function runJob(int $jobId')
        && str_contains($neteaseSource, 'if ($result === null)'),
    'netease advances the normal schedule after a failed execution'
);
cronSafetyCheck(
    !str_contains($neteaseSource, 'Jobs::updateJobInfo(')
        && str_contains($neteaseSource, "->where('uid'"),
    'netease job/account updates are not scoped to the current tenant and job'
);

// --- Bilibili: exception tag ----------------------------------------------

$bilibiliSource = file_get_contents($root . '/app/cron/controller/Bilibili.php');
cronSafetyCheck(is_string($bilibiliSource), 'Unable to inspect the bilibili controller');

// Exceptions reschedule the job through the lease; the log tag must say so.
cronSafetyCheck(
    str_contains($bilibiliSource, '[重试中] 任务调度异常，已安排稍后重试'),
    'bilibili scheduler exceptions are not tagged as retrying'
);
cronSafetyCheck(
    !str_contains($bilibiliSource, "任务调度异常：' . \$exception"),
    'bilibili still leaks raw exception text into task logs'
);

echo "Cron controller safety tests passed\n";
