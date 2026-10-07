<?php

declare(strict_types=1);

/**
 * Regression tests for the legacy cron controllers after the in-process
 * execution refactor.
 *
 * Covers: the Epic full-table UPDATE regression ($job->where() drops the
 * primary-key condition and would disable every enabled job), per-job
 * exception isolation in Netease/Bilibili/Epic.
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
$epicRunner = file_get_contents($root . '/app/service/EpicJobRunner.php');
cronSafetyCheck(is_string($epicSource), 'Unable to inspect the epic controller');

// A Model instance chained with where() loses its primary key condition, so
// '$job->where(...)->update(...)' was a full-table UPDATE. The disable must
// address the job by id.
cronSafetyCheck(
    !preg_match('/^\s*\$job->where\(/m', $epicRunner),
    'epic still disables jobs through a full-table model UPDATE'
);
cronSafetyCheck(
    str_contains($epicRunner, "Jobs::where('id', \$id)") && str_contains($epicRunner, 'Jobs::claimDueJob('),
    'epic does not disable the timing-less job by primary key'
);
// A mail/upstream exception must not abort the whole scheduling round.
cronSafetyCheck(
    str_contains($epicRunner, 'try {')
        && str_contains($epicRunner, 'Epic 周免提醒执行异常'),
    'epic notify failures are not isolated per job'
);
cronSafetyCheck(
    str_contains($epicRunner, 'use Throwable;') && str_contains($epicSource, 'EpicJobRunner'),
    'epic controller does not import Throwable'
);

// --- Netease: exception isolation -----------------------------------------

$neteaseSource = file_get_contents($root . '/app/cron/controller/Netease.php');
cronSafetyCheck(is_string($neteaseSource), 'Unable to inspect the netease controller');

cronSafetyCheck(
    str_contains($neteaseSource, 'catch (Throwable $exception)')
        && str_contains($neteaseSource, '执行异常，稍后自动重试')
        && str_contains($neteaseSource, "\$this->statusTag(['retry_after_seconds' => 300])"),
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
    str_contains($bilibiliSource, '[重试中] 执行异常，稍后自动重试'),
    'bilibili scheduler exceptions are not tagged as retrying'
);
cronSafetyCheck(
    !str_contains($bilibiliSource, "任务调度异常：' . \$exception"),
    'bilibili still leaks raw exception text into task logs'
);

// --- Cron authentication is single-point -----------------------------------

// Every cron endpoint sits behind app\middleware\CheckCronAccess; controllers
// must not grow their own credential checks that drift from the middleware's
// accepted credential sources.
foreach (['Netease', 'Bilibili', 'Epic', 'Notifications', 'Task'] as $controller) {
    $source = file_get_contents($root . '/app/cron/controller/' . $controller . '.php');
    cronSafetyCheck(is_string($source), 'Unable to inspect cron controller ' . $controller);
    cronSafetyCheck(
        !str_contains($source, 'CronKey Access Denied!'),
        $controller . ' still performs its own cron credential check'
    );
}

// The monitor URL copy must not carry the credential in the query string,
// which lands in web server access logs; the header is the documented channel.
foreach (['system/set/cron', 'system/task/set'] as $view) {
    $viewSource = file_get_contents($root . '/app/admin/view/' . $view . '.html');
    cronSafetyCheck(is_string($viewSource), 'Unable to inspect admin view ' . $view);
    cronSafetyCheck(
        !str_contains($viewSource, '?cronkey='),
        'The cron key is still advertised inside a URL: ' . $view
    );
}

echo "Cron controller safety tests passed\n";
