<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use app\service\Honeypot;
use app\service\HoneypotLog;

function honeypotLogCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = sys_get_temp_dir() . '/loopdeck-honeypot-log-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
$directory = $root . '/samples';
$log = new HoneypotLog($directory);
$now = time();
try {
    honeypotLogCheck($log->snapshot()['state'] === 'empty' && !file_exists($directory), 'Reading empty statistics wrote state');
    // A delayed previous-second sample must not reopen the current budget.
    for ($round = 0; $round < 3; $round++) {
        $lateDirectory = $root . '/late-' . $round;
        $lateLog = new HoneypotLog($lateDirectory);
        $second = $now + 1;
        for ($i = 0; $i < HoneypotLog::SAMPLES_PER_SECOND; $i++) {
            honeypotLogCheck($lateLog->record('blocked', '/index/ajax', 'GET', '', $second),
                'The current sampling window did not accept its budget');
        }
        $stateBefore = file_get_contents($lateDirectory . '/sample.lock');
        $staleAccepted = $lateLog->record('blocked', '/index/ajax', 'GET', '', $second - 1);
        $stateAfter = file_get_contents($lateDirectory . '/sample.lock');
        $extraAccepted = 0;
        for ($i = 0; $i < HoneypotLog::SAMPLES_PER_SECOND; $i++) {
            $extraAccepted += (int)$lateLog->record('blocked', '/index/ajax', 'GET', '', $second);
        }
        $counts = array_count_values(array_column($lateLog->snapshot()['recent'], 'at'));
        honeypotLogCheck(!$staleAccepted && $stateAfter === $stateBefore && $extraAccepted === 0
            && ($counts[$second] ?? 0) === HoneypotLog::SAMPLES_PER_SECOND,
            'A delayed sample reset the budget: stale=' . (int)$staleAccepted . ', same-second records=' . ($counts[$second] ?? 0));
        honeypotLogCheck($lateLog->record('blocked', '/index/ajax', 'GET', '', $second + 1),
            'Rejecting a stale sample prevented the next sampling window');
    }
    $accepted = 0;
    for ($i = 0; $i < 100; $i++) {
        $accepted += (int)$log->record('blocked', '/index/ajax', 'POST', '198.51.100.1', $now);
    }
    honeypotLogCheck($accepted === 5 && $log->snapshot()['total'] === 5, 'Per-second sampling was not bounded across instances');
    $lock = fopen($directory . '/sample.lock', 'r+b');
    flock($lock, LOCK_EX);
    $start = microtime(true);
    honeypotLogCheck(!(new HoneypotLog($directory))->record('blocked', '/index/ajax', 'GET', '', $now + 1)
        && $log->snapshot()['state'] === 'busy', 'Writer/reader did not skip a busy lock');
    honeypotLogCheck(microtime(true) - $start < 1, 'A sample waited for a held lock');
    flock($lock, LOCK_UN);
    fclose($lock);

    $workers = [];
    for ($i = 0; $i < 4; $i++) {
        $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/honeypot-log-worker.php', $directory, (string)($now + 1)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        honeypotLogCheck(is_resource($process), 'Unable to start a sample writer');
        $workers[] = [$process, $pipes];
    }
    $concurrent = 0;
    foreach ($workers as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        honeypotLogCheck(proc_close($process) === 0 && ctype_digit($output), 'Concurrent writer failed: ' . $error);
        $concurrent += (int)$output;
    }
    honeypotLogCheck($concurrent > 0 && $concurrent <= 5 && $log->snapshot()['total'] === 5 + $concurrent,
        'Multiple workers exceeded the shared sample budget or damaged a line');

    $beforeMemory = memory_get_usage(true);
    for ($i = 0; $i < 12000; $i++) {
        honeypotLogCheck($log->record('sql_result', '/index/ajax', 'GET', '2001:db8::1', $now + 10 + intdiv($i, 5)),
            'A scheduled sample could not be written');
    }
    $snapshot = $log->snapshot();
    honeypotLogCheck($snapshot['state'] === 'ready' && $snapshot['total'] < 12000 && $snapshot['total'] > 1000,
        'Rotation failed: ' . json_encode(array_diff_key($snapshot, ['recent' => true])));
    honeypotLogCheck(count($snapshot['recent']) === 50 && $snapshot['recent'][0]['at'] === $now + 10 + intdiv(11999, 5),
        'Recent records did not retain the newest 50 samples');
    honeypotLogCheck(memory_get_usage(true) - $beforeMemory <= 2097152, 'Repeated records retained growing request state');
    honeypotLogCheck($snapshot['bytes_read'] <= HoneypotLog::READ_BYTES, 'Snapshot exceeded its input budget');
    $files = glob($directory . '/*');
    honeypotLogCheck(count($files) === 3, 'Rotation created unbounded files');
    foreach ($files as $file) {
        clearstatcache(true, $file);
        honeypotLogCheck(filesize($file) <= (str_ends_with($file, '.lock') ? HoneypotLog::STATE_BYTES : HoneypotLog::FILE_BYTES),
            'A sample file exceeded its byte budget');
    }
    foreach (glob($directory . '/*.jsonl') as $file) {
        $handle = fopen($file, 'rb');
        while (($line = fgets($handle)) !== false) {
            honeypotLogCheck(strlen($line) <= HoneypotLog::RECORD_BYTES && is_array(json_decode($line, true)), 'Invalid/oversized stored line');
        }
        fclose($handle);
    }

    $broken = $root . '/broken';
    mkdir($broken);
    file_put_contents($broken . '/sample.lock', 'broken');
    file_put_contents($broken . '/events.0.jsonl', str_repeat('x', HoneypotLog::READ_BYTES + 100));
    file_put_contents($broken . '/events.1.jsonl', "{invalid}\n");
    $brokenLog = new HoneypotLog($broken);
    $snapshot = $brokenLog->snapshot();
    honeypotLogCheck($snapshot['state'] === 'partial' && $snapshot['bytes_read'] <= HoneypotLog::READ_BYTES
        && $snapshot['total'] === 0, 'Damaged files escaped bounded parsing');
    honeypotLogCheck($brokenLog->record('blocked', '/index/ajax', 'GET', '', $now), 'Damaged state could not recover');
    clearstatcache();
    honeypotLogCheck(filesize($broken . '/events.0.jsonl') <= HoneypotLog::FILE_BYTES, 'Oversized active log was preserved');
    honeypotLogCheck(!$log->record('unknown', '/index/ajax', 'GET', '', $now + 3000)
        && !$log->record('blocked', str_repeat('x', 2000), 'GET', '', $now + 3000), 'Unbounded event fields were accepted');

    file_put_contents($root . '/not-directory', 'fixture');
    honeypotLogCheck(!(new HoneypotLog($root . '/not-directory'))->record('blocked', '/index/ajax', 'GET', '')
        && (new HoneypotLog($root . '/not-directory'))->snapshot()['state'] === 'unavailable', 'Unavailable storage was reported as healthy');
    mkdir($root . '/write-failure');
    mkdir($root . '/write-failure/events.0.jsonl');
    honeypotLogCheck(!(new HoneypotLog($root . '/write-failure'))->record('blocked', '/index/ajax', 'GET', ''),
        'An invalid log file accepted a write');
    mkdir($root . '/invalid-lock');
    mkdir($root . '/invalid-lock/sample.lock');
    honeypotLogCheck((new HoneypotLog($root . '/invalid-lock'))->snapshot()['state'] === 'unavailable',
        'A non-regular lock file was opened for statistics');

    foreach (['/index/login_ajax/reg/password/private-canary', '/admin/system/set/secret/private-canary',
        '/unknown/private-canary', '/console/netease/info/private-canary?token=private-canary'] as $route) {
        honeypotLogCheck(!str_contains(Honeypot::entry($route), 'private-canary') && !str_contains(Honeypot::entry($route), '?'),
            'Credentials in a route entered its entry identifier');
    }
    foreach (['UNION SELECT 1', 'union all select 1', 'UNION/**/SELECT 1'] as $value) {
        honeypotLogCheck(Honeypot::isSqlExtraction($value, 'GET'), 'Known UNION classification failed');
    }
    honeypotLogCheck(!Honeypot::isSqlExtraction('sleep(3)', 'GET')
        && !Honeypot::isSqlExtraction('UNION SELECT 1', 'UPLOAD')
        && !Honeypot::isSqlExtraction(str_repeat('x', 5000) . ' UNION SELECT 1', 'POST'), 'Classification expanded outside its bounded role');
    foreach (['decoy_config', 'decoy_backup', 'sql_result'] as $kind) {
        [, $body] = Honeypot::payload($kind);
        honeypotLogCheck(strlen($body) <= Honeypot::RESPONSE_BYTES && str_contains($body, '恭喜，你成功导出了空气'),
            'A fixed payload exceeded its budget or lost the final remark');
    }
    $previous = getenv('HONEYPOT_ENABLED');
    try {
        putenv('HONEYPOT_ENABLED');
        honeypotLogCheck(Honeypot::enabled(), 'Honeypots were not enabled by default');
        putenv('HONEYPOT_ENABLED=0');
        honeypotLogCheck(!Honeypot::enabled(), 'Emergency deployment switch was ignored');
    } finally {
        putenv($previous === false ? 'HONEYPOT_ENABLED' : 'HONEYPOT_ENABLED=' . $previous);
    }
    echo "Honeypot sample tests passed: delayed samples, 12000 records, concurrency, rotation, bounded reads, privacy and failure handling\n";
} finally {
    $resolved = realpath($root);
    honeypotLogCheck(is_string($resolved) && str_starts_with($resolved,
        realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'loopdeck-honeypot-log-'), 'Unsafe cleanup path');
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($resolved);
}
