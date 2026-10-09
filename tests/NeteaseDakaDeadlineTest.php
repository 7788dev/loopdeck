<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use netease\Netease;

final class DeadlineDakaProbe extends Netease
{
    public int $now;
    public int $counter = 58000;
    public int $countPerBatch = 0;
    public int $reportSeconds = 0;
    public int $candidateSeconds = 0;
    public bool $counterFails = false;
    public array $batches = [];

    public function __construct(string $directory, int $now, array $config = [])
    {
        $this->now = $now;
        parent::__construct('42', 'fixture', 'fixture', $config + [
            'daka_history_dir' => $directory,
            'daka_internal_wait_seconds' => 0,
            'sdk' => ['auto_anonymous_token' => false, 'cache_dir' => ''],
        ]);
    }

    protected function dakaNow(): int { return $this->now; }
    protected function waitDakaSettlement(int $seconds): void { $this->now += $seconds; }
    protected function seedDakaHistoryFromPlayRecord(): void {}

    public function detail($uid)
    {
        return ['body' => json_encode($this->counterFails
            ? ['code' => 503] : ['code' => 200, 'listenSongs' => $this->counter])];
    }

    protected function dakaCandidates(string $source, array $exclude, int $limit): array
    {
        $this->now += $this->candidateSeconds;
        $songs = [];
        for ($id = 1; count($songs) < $limit; $id++) {
            if (!isset($exclude[$id])) {
                $songs[$id] = ['id' => $id, 'sourceId' => 123, 'time' => 180];
            }
        }
        return $songs;
    }

    protected function weblogScrobbleBatch(array $songs): int
    {
        $this->batches[] = array_column($songs, 'id');
        $this->now += $this->reportSeconds;
        $this->counter += min($this->countPerBatch, count($songs));
        $this->lastScrobbleStarts = count($songs);
        $this->lastScrobbleSongIds = array_column($songs, 'id');
        return count($songs);
    }
}

function deadlineCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$directories = [];
$newDirectory = static function () use (&$directories): string {
    $directory = sys_get_temp_dir() . '/loopdeck-daka-deadline-' . bin2hex(random_bytes(6));
    mkdir($directory, 0770, true);
    $directories[] = $directory;
    return $directory;
};
$readState = static fn(string $directory): array => json_decode(
    (string)file_get_contents($directory . '/' . hash('sha256', '42') . '.daily.json'), true
);
$start = strtotime('2026-10-10 15:00:00');

try {
    $directory = $newDirectory();
    $first = new DeadlineDakaProbe($directory, $start);
    $pending = $first->daka_new();
    $state = $readState($directory);
    deadlineCheck(($state['deadline_at'] ?? 0) === $start + 1800,
        'The first attempt did not persist a 30-minute completion deadline');
    deadlineCheck($pending['code'] !== 200 && $pending['data']['retry_after_seconds'] > 0,
        'Accepted reports without a counter increase were marked successful');

    $expired = new DeadlineDakaProbe($directory, $start + 1800);
    $expired->counter = 58144;
    $failure = $expired->daka_new();
    deadlineCheck($expired->batches === [] && $failure['code'] !== 200
        && $failure['data']['retry_after_seconds'] === 0,
        'An unfinished task kept uploading or retrying beyond 30 minutes');
    deadlineCheck(str_contains($failure['message'], '144/300') && str_contains($failure['message'], '30'),
        'The terminal result did not explain the confirmed shortfall and time limit');

    // Restarting a worker or clicking retry must not reset the original budget.
    $again = new DeadlineDakaProbe($directory, $start + 2100);
    $again->counter = 58144;
    deadlineCheck($again->daka_new()['data']['retry_after_seconds'] === 0 && $again->batches === [],
        'A new worker reopened the expired listening window');
    deadlineCheck($readState($directory)['deadline_at'] === $start + 1800,
        'A subsequent request moved the deadline');

    // Persistent read failures also have a deadline, without inventing a zero baseline.
    $unavailableDirectory = $newDirectory();
    $unavailable = new DeadlineDakaProbe($unavailableDirectory, $start);
    $unavailable->counterFails = true;
    deadlineCheck($unavailable->daka_new()['data']['retry_after_seconds'] > 0, 'A transient read failure was not retried');
    $unavailable = new DeadlineDakaProbe($unavailableDirectory, $start + 1800);
    $unavailable->counterFails = true;
    deadlineCheck($unavailable->daka_new()['data']['retry_after_seconds'] === 0,
        'Counter outages silently retried forever');
    deadlineCheck(!isset($readState($unavailableDirectory)['listen_songs_baseline']), 'An outage invented a counter baseline');

    // Delayed accounting succeeds from the original baseline inside the window.
    $settledDirectory = $newDirectory();
    (new DeadlineDakaProbe($settledDirectory, $start))->daka_new();
    $settled = new DeadlineDakaProbe($settledDirectory, $start + 120);
    $settled->counter = 58300;
    $success = $settled->daka_new();
    deadlineCheck($success['code'] === 200 && $success['data']['daily_actual_progress'] === 300
        && $settled->batches === [] && $success['data']['retry_after_seconds'] === 0,
        'A normal account lost its successful read-only settlement');

    // A slow playlist response must not start a network upload past the window.
    $slow = new DeadlineDakaProbe($newDirectory(), $start);
    $slow->candidateSeconds = 1800;
    $slowResult = $slow->daka_new();
    deadlineCheck($slow->batches === [] && $slowResult['data']['retry_after_seconds'] === 0,
        'Candidate collection crossed the deadline and still started reporting');

    // A minute-based scheduler may wake after midnight. Keep the old result
    // and do not attribute a new baseline or extra uploads to that retry.
    $midnightDirectory = $newDirectory();
    $lateStart = strtotime('2026-10-10 23:55:00');
    (new DeadlineDakaProbe($midnightDirectory, $lateStart))->daka_new();
    $nextDay = new DeadlineDakaProbe($midnightDirectory, strtotime('2026-10-11 00:01:00'));
    $nextDay->counter = 58300;
    $lateFailure = $nextDay->daka_new();
    deadlineCheck($lateFailure['code'] !== 200 && $lateFailure['data']['retry_after_seconds'] === 0
        && $lateFailure['data']['run_date'] === '2026-10-10' && $nextDay->batches === [],
        'A delayed midnight retry discarded yesterday and started uploading again');
    deadlineCheck($readState($midnightDirectory)['date'] === '2026-10-10', 'Yesterday\'s baseline was overwritten');

    // Even legacy intervals cannot push the last scheduled read past 30 minutes.
    $configured = new DeadlineDakaProbe($newDirectory(), $start,
        ['daka_retry_seconds' => 3600, 'daka_max_verification_runs' => 10]);
    $configuredResult = $configured->daka_new();
    deadlineCheck($configuredResult['data']['retry_after_seconds'] <= 120,
        'A legacy setting defeated the bounded verification schedule');

    // Reproduce a heavy account whose accepted batches only add 60 unique
    // songs, with accounting settling on the following minute's scheduler tick.
    $heavyDirectory = $newDirectory();
    $confirmed = 58000;
    $settling = 0;
    $submittedIds = [];
    $heavyResult = [];
    for ($elapsed = 0; $elapsed <= 1800; $elapsed += 60) {
        $confirmed += $settling;
        $settling = 0;
        $heavy = new DeadlineDakaProbe($heavyDirectory, $start + $elapsed);
        $heavy->counter = $confirmed;
        $heavyResult = $heavy->daka_new();
        foreach ($heavy->batches as $batch) {
            deadlineCheck(array_intersect($submittedIds, $batch) === [], 'Replacement reporting reused a reserved song');
            $submittedIds = array_merge($submittedIds, $batch);
            $settling += 60;
        }
        if ($heavyResult['data']['retry_after_seconds'] === 0) {
            break;
        }
    }
    deadlineCheck($heavyResult['code'] === 200 && $heavyResult['data']['daily_actual_progress'] === 300 && $elapsed < 1800,
        'Bounded replacements did not finish a low-yield account successfully within 30 minutes');

    $sanitize = new ReflectionMethod(app\index\controller\Netease::class, 'sanitizeJobConfig');
    $sanitize->setAccessible(true);
    $saved = $sanitize->invoke(null, ['daka_retry_seconds' => '60', 'daka_max_verification_runs' => '2']);
    deadlineCheck($saved['daka_retry_seconds'] === 60, 'The settings endpoint rejected the new default verification interval');
    try {
        $sanitize->invoke(null, ['daka_retry_seconds' => '3600']);
        throw new RuntimeException('The settings endpoint accepted an interval outside the completion window');
    } catch (InvalidArgumentException $expected) {
    }

    echo "Netease 30-minute completion window tests passed\n";
} finally {
    foreach ($directories as $directory) {
        foreach (glob($directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
}
