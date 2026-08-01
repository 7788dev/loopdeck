<?php

declare(strict_types=1);

function taskLogsOrderingCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$model = file_get_contents($root . '/app/index/model/TaskLogs.php');
$table = file_get_contents($root . '/public/static/js/task_logs_datatables.js');

taskLogsOrderingCheck(is_string($model), 'Unable to read TaskLogs model');
taskLogsOrderingCheck(is_string($table), 'Unable to read task log table script');
taskLogsOrderingCheck(
    str_contains($model, "order(['addtime' => 'desc', 'id' => 'desc'])"),
    'Task logs must be queried newest first with descending IDs as the tie-breaker'
);
taskLogsOrderingCheck(
    str_contains($table, '{"title": "ID", "data": "id"}'),
    'Task log table must display the real database ID'
);
taskLogsOrderingCheck(
    !str_contains($table, 'log_index'),
    'Task log table must not replace IDs with page-relative sequence numbers'
);
taskLogsOrderingCheck(
    str_contains($table, 'ordering: false'),
    'Task log table must preserve the server-provided newest-first order'
);

echo "Task log ordering tests passed\n";
