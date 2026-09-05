<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use netease\Netease;

class SettlementProbe extends Netease
{
    public int $now;
    public int $counter = 7449;
    public int $countPerBatch = 0;
    public array $batches = [];
    public ?array $counterResponse = null;
    public bool $counterThrows = false;
    public bool $reportThrows = false;
    public bool $writesFail = false;

    public function __construct(string $directory, int $now, array $config = [])
    {
        $this->now = $now;
        parent::__construct('1', 'test-csrf', 'test-session', $config + [
            'daka_history_dir' => $directory,
            'daka_internal_wait_seconds' => 0,
            'sdk' => ['auto_anonymous_token' => false, 'cache_dir' => ''],
        ]);
    }

    protected function dakaNow(): int
    {
        return $this->now;
    }

    protected function waitDakaSettlement(int $seconds): void
    {
        $this->now += $seconds;
    }

    public function detail($uid)
    {
        if ($this->counterThrows) {
            throw new RuntimeException('Simulated counter outage');
        }
        return ['body' => json_encode($this->counterResponse ?? [
            'code' => 200, 'listenSongs' => $this->counter,
        ])];
    }

    protected function seedDakaHistoryFromPlayRecord(): void
    {
    }

    protected function dakaCandidates(string $source, array $exclude, int $limit): array
    {
        $songs = [];
        $exclude += $this->dakaHistory();
        for ($id = 1; count($songs) < $limit; $id++) {
            if (!isset($exclude[$id])) {
                $songs[$id] = ['id' => $id, 'sourceId' => 123, 'time' => 180];
            }
        }
        return $songs;
    }

    protected function weblogScrobbleBatch(array $songs): int
    {
        $ids = array_column($songs, 'id');
        $this->batches[] = $ids;
        $this->lastScrobbleStarts = count($songs);
        $this->lastScrobbleSongIds = $ids;
        $this->lastScrobbleSeconds = count($songs) * 180;
        $this->lastScrobbleRejections = [];
        $this->counter += min($this->countPerBatch, count($songs));
        if ($this->reportThrows) {
            throw new RuntimeException('Simulated interruption after sending');
        }
        return count($songs);
    }

    protected function writeDakaStateFile(string $path, string $contents): bool
    {
        return !$this->writesFail && parent::writeDakaStateFile($path, $contents);
    }
}

function settlementCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function settlementState(string $directory): array
{
    $path = $directory . DIRECTORY_SEPARATOR . hash('sha256', '1') . '.daily.json';
    return is_file($path) ? json_decode((string)file_get_contents($path), true) : [];
}

$directories = [];
$newDirectory = static function () use (&$directories): string {
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'loopdeck-settlement-' . bin2hex(random_bytes(6));
    settlementCheck(mkdir($path, 0770, true), 'Could not create settlement fixture');
    $directories[] = $path;
    return $path;
};
$morning = strtotime(date('Y-m-d') . ' 09:00:00');

try {
    // Production: 420 accepted reports were sealed as a failure at 09:11,
    // but the same account later moved from 7449 to 7749. An acknowledgement
    // without a counter increase must stay pending, with no immediate top-up.
    $directory = $newDirectory();
    $first = new SettlementProbe($directory, $morning);
    $pending = $first->daka_new();
    settlementCheck($pending['code'] !== 200, 'An uncounted batch was marked successful');
    settlementCheck(($pending['data']['retry_after_seconds'] ?? 0) > 0, 'Delayed accounting was sealed instead of scheduling verification');
    settlementCheck(count($first->batches) === 1, 'An unsettled batch consumed replacement songs immediately');
    settlementCheck(count($first->batches[0]) === 360, 'The initial batch lost its fresh-song cushion');
    $state = settlementState($directory);
    settlementCheck($state['listen_songs_baseline'] === 7449 && empty($state['sealed']), 'Pending progress was not durable');

    $early = new SettlementProbe($directory, $morning + 60);
    $earlyResult = $early->daka_new();
    settlementCheck($early->batches === [] && $earlyResult['data']['submitted'] === 0, 'An early duplicate trigger submitted again');

    // A fresh process must keep the original baseline and close the task as
    // soon as the real counter arrives, without another play request.
    $settled = new SettlementProbe($directory, $morning + 300);
    $settled->counter = 7749;
    $success = $settled->daka_new();
    settlementCheck($success['code'] === 200 && $success['data']['daily_actual_progress'] === 300, 'Delayed 300-song accounting did not complete');
    settlementCheck($settled->batches === [] && $success['data']['retry_after_seconds'] === 0, 'A completed day reported or retried again');
    settlementCheck(settlementState($directory)['listen_songs_baseline'] === 7449, 'Restarting moved the daily baseline');

    $tomorrow = new SettlementProbe($directory, $morning + 86400);
    $tomorrow->counter = 7749;
    $tomorrowResult = $tomorrow->daka_new();
    settlementCheck($tomorrowResult['data']['daily_actual_progress'] === 0, 'Yesterday\'s 300 songs were counted again today');
    settlementCheck(settlementState($directory)['listen_songs_baseline'] === 7749, 'The next day did not establish a new baseline');
    settlementCheck(count($tomorrow->batches) === 1 && array_intersect($first->batches[0], $tomorrow->batches[0]) === [], 'The next day reused yesterday\'s accepted songs');

    // A real shortfall is supplemented only after the settlement window, and
    // both the first batch and every replacement use distinct song IDs.
    $partialDirectory = $newDirectory();
    $partial = new SettlementProbe($partialDirectory, $morning);
    $partial->daka_new();
    $checking = new SettlementProbe($partialDirectory, $morning + 300);
    $checking->counter = 7745;
    $partialResult = $checking->daka_new();
    settlementCheck($checking->batches === [] && $partialResult['data']['daily_actual_progress'] === 296, 'Partial accounting triggered a premature top-up');
    $replacement = new SettlementProbe($partialDirectory, $morning + 900);
    $replacement->counter = 7745;
    $replacement->countPerBatch = 4;
    $replaced = $replacement->daka_new();
    settlementCheck($replaced['code'] === 200 && $replaced['data']['daily_actual_progress'] === 300, 'The settled four-song shortfall was not completed');
    settlementCheck(count($replacement->batches) === 1 && count($replacement->batches[0]) === 8, 'The shortfall did not use a bounded replacement cushion');
    settlementCheck(array_intersect($partial->batches[0], $replacement->batches[0]) === [], 'A replacement reused an already submitted song');

    $legacyDirectory = $newDirectory();
    file_put_contents($legacyDirectory . DIRECTORY_SEPARATOR . hash('sha256', '1') . '.daily.json', json_encode([
        'date' => date('Y-m-d', $morning),
        'listen_songs_baseline' => 7153,
        'listen_songs_observed' => 7449,
        'submitted_total' => 420,
        'submitted_song_ids' => range(1, 420),
        'attempts' => 2,
        'sealed' => true,
        'updated_at' => date('c', $morning - 3600),
    ]));
    $legacy = new SettlementProbe($legacyDirectory, $morning);
    $legacy->countPerBatch = 4;
    settlementCheck($legacy->daka_new()['code'] === 200, 'An old sealed 296/300 day could not resume');
    settlementCheck(count($legacy->batches) === 1 && array_intersect(range(1, 420), $legacy->batches[0]) === [], 'Legacy recovery replayed reserved songs');

    // Hitting the daily send budget must still allow a late counter to settle.
    $capDirectory = $newDirectory();
    $capConfig = ['daka_max_batches_per_day' => 2, 'daka_max_verification_runs' => 1, 'daka_retry_seconds' => 120];
    $capped = new SettlementProbe($capDirectory, $morning, $capConfig);
    $capped->daka_new();
    $second = new SettlementProbe($capDirectory, $morning + 120, $capConfig);
    $second->daka_new();
    $afterCap = new SettlementProbe($capDirectory, $morning + 7200, $capConfig);
    $capResult = $afterCap->daka_new();
    settlementCheck($afterCap->batches === [] && $capResult['data']['retry_after_seconds'] > 0, 'The day cap either reset or disabled late verification');
    settlementCheck(settlementState($capDirectory)['attempts'] === 2, 'The batch budget was reset by a new process');
    $afterCap->counter = 7749;
    settlementCheck($afterCap->daka_new()['code'] === 200, 'A late count could not complete an exhausted send budget');

    // A successful envelope without listenSongs must never create a zero
    // baseline that would falsely count the account's entire listening history.
    $invalidDirectory = $newDirectory();
    $invalid = new SettlementProbe($invalidDirectory, $morning);
    $invalid->counterResponse = ['code' => 200];
    $invalidResult = $invalid->daka_new();
    settlementCheck($invalid->batches === [] && $invalidResult['data']['retry_after_seconds'] > 0, 'A missing counter was treated as zero');
    settlementCheck(settlementState($invalidDirectory) === [], 'An invalid counter was saved as a daily baseline');
    $invalid->counterThrows = true;
    settlementCheck($invalid->daka_new()['data']['retry_after_seconds'] > 0, 'A counter exception was not retryable');
    $invalid->counterThrows = false;
    $invalid->counterResponse = ['code' => 301];
    settlementCheck($invalid->daka_new()['data']['retry_after_seconds'] === 0 && $invalid->cookiezt, 'Expired credentials were retried as settlement');

    // Reserve the baseline and IDs before network I/O, so a killed worker
    // cannot move the baseline or replay the same batch on its next lease.
    $crashDirectory = $newDirectory();
    $crash = new SettlementProbe($crashDirectory, $morning);
    $crash->reportThrows = true;
    $crash->daka_new();
    $crashState = settlementState($crashDirectory);
    settlementCheck(($crashState['listen_songs_baseline'] ?? null) === 7449, 'An interrupted report lost its original baseline');
    settlementCheck(count($crashState['submitted_song_ids'] ?? []) === 360, 'An interrupted report lost its reserved song IDs');
    $afterCrash = new SettlementProbe($crashDirectory, $morning + 300);
    $afterCrash->counter = 7749;
    settlementCheck($afterCrash->daka_new()['code'] === 200 && $afterCrash->batches === [], 'An interrupted successful report was replayed');

    $unwritable = new SettlementProbe($newDirectory(), $morning);
    $unwritable->writesFail = true;
    $writeResult = $unwritable->daka_new();
    settlementCheck($writeResult['code'] !== 200 && $unwritable->batches === [], 'Reporting proceeded without a durable baseline');

    $endOfDay = new SettlementProbe($newDirectory(), strtotime(date('Y-m-d') . ' 23:59:40'));
    $lastResult = $endOfDay->daka_new();
    settlementCheck($lastResult['code'] !== 200 && $lastResult['data']['retry_after_seconds'] === 0, 'An unfinished day scheduled a retry with the next day\'s baseline');

    echo "Netease delayed settlement tests passed\n";
} finally {
    foreach ($directories as $directory) {
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
}
