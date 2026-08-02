<?php

declare(strict_types=1);

function jobClaimingCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$jobs = file_get_contents($root . '/app/index/model/Jobs.php');
$scheduler = file_get_contents($root . '/app/cron/controller/Task.php');
$neteaseCommand = file_get_contents($root . '/app/command/Netease.php');
$bilibiliCommand = file_get_contents($root . '/app/command/Bilibili.php');
$legacyNetease = file_get_contents($root . '/app/cron/controller/Netease.php');
$legacyBilibili = file_get_contents($root . '/app/cron/controller/Bilibili.php');

foreach ([$jobs, $scheduler, $neteaseCommand, $bilibiliCommand, $legacyNetease, $legacyBilibili] as $source) {
    jobClaimingCheck(is_string($source), 'Unable to read a job executor source file');
}

jobClaimingCheck(
    str_contains($jobs, 'public static function claimDueJob('),
    'Jobs model is missing the atomic due-job claim helper'
);
jobClaimingCheck(
    str_contains($jobs, "->where('id', '=', \$id)")
        && str_contains($jobs, "->where('state', '=', 1)")
        && str_contains($jobs, "->where('nextExecute', '=', \$expectedNextExecute)")
        && str_contains($jobs, "->where('nextExecute', '<=', \$now)"),
    'The claim must compare the selected row, enabled state and original due timestamp'
);
jobClaimingCheck(
    str_contains($jobs, "->update(['nextExecute' => \$now + self::EXECUTION_LEASE_SECONDS])"),
    'The atomic claim must install a bounded execution lease'
);

foreach ([$scheduler, $neteaseCommand, $bilibiliCommand, $legacyNetease, $legacyBilibili] as $source) {
    jobClaimingCheck(
        str_contains($source, 'Jobs::claimDueJob('),
        'Every scheduler and CLI execution path must claim a due job before execution'
    );
}

jobClaimingCheck(
    strpos($scheduler, 'Jobs::claimDueJob(') < strpos($scheduler, '$this->executeNetease('),
    'Unified scheduler claims must happen before upstream NetEase execution'
);
jobClaimingCheck(
    strpos($neteaseCommand, 'Jobs::claimDueJob(') < strpos($neteaseCommand, 'new NeteaseAPI('),
    'NetEase CLI claims must happen before creating the upstream client'
);
jobClaimingCheck(
    strpos($bilibiliCommand, 'Jobs::claimDueJob(') < strpos($bilibiliCommand, '$executor->execute('),
    'Bilibili CLI claims must happen before upstream task execution'
);
jobClaimingCheck(
    str_contains($neteaseCommand, '$count = $executed;'),
    'NetEase CLI must report claimed executions instead of merely selected rows'
);

echo "Atomic job claiming tests passed\n";
