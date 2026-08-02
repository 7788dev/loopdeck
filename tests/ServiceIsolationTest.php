<?php

declare(strict_types=1);

function serviceIsolationCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$accounts = file_get_contents($root . '/app/index/model/Accounts.php');
$jobs = file_get_contents($root . '/app/index/model/Jobs.php');
$logs = file_get_contents($root . '/app/index/model/TaskLogs.php');
$neteaseController = file_get_contents($root . '/app/index/controller/Netease.php');
$bilibiliController = file_get_contents($root . '/app/index/controller/Bilibili.php');
$sportCommand = file_get_contents($root . '/app/command/Sport.php');

foreach ([$accounts, $jobs, $logs, $neteaseController, $bilibiliController, $sportCommand] as $source) {
    serviceIsolationCheck(is_string($source), 'Unable to read an isolation-sensitive source file');
}

serviceIsolationCheck(
    str_contains($accounts, 'public static function findByUserId($type, $user_id)')
        && str_contains($accounts, 'public static function delByUserId($type, $user_id)')
        && str_contains($accounts, 'public static function delById($type, $id)'),
    'Account lookup or deletion is not service-scoped'
);
serviceIsolationCheck(
    substr_count($accounts, '->where(\'type\', $type)') >= 3,
    'Account helpers accept a service type but do not enforce it'
);
serviceIsolationCheck(
    str_contains($jobs, 'public static function switchState($type, $user_id, $do)')
        && str_contains($jobs, 'public static function updateJobInfo($type, $do, $user_id, $data = [])'),
    'Job state or execution metadata is not service-scoped'
);
serviceIsolationCheck(
    str_contains($jobs, '[\'type\' => $type, \'do\' => $do, \'user_id\' => $user_id]'),
    'Job execution metadata update omits the service type'
);
serviceIsolationCheck(
    str_contains($logs, '->where(\'type\', \'=\', $type)')
        && str_contains($logs, '->where(\'user_id\', \'=\', $user_id)'),
    'Task log query/delete operations are not service/account-scoped'
);
serviceIsolationCheck(
    str_contains($neteaseController, "Jobs::switchState('netease',")
        && str_contains($bilibiliController, "Jobs::switchState('bilibili',"),
    'A service controller can still toggle another service job'
);
serviceIsolationCheck(
    str_contains($sportCommand, "Jobs::delJob('sport',")
        && !str_contains($sportCommand, "Jobs::delJob('netease',"),
    'Sport cleanup can still delete NetEase jobs'
);

echo "Service isolation tests passed\n";
