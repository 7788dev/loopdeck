<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use netease\Netease;

class NeteaseSelectionFixture extends Netease
{
    /** @var array<int,int> */
    public array $playlistDetailCalls = [];
    public int $recommendPlaylistCalls = 0;
    public int $highqualityCalls = 0;
    public int $personalizedCalls = 0;
    public int $searchPlaylistCalls = 0;
    /** @var array<int,int> */
    public array $recommendPlaylists = [9100, 9101];
    /** @var array<int,array<int,int>> */
    public array $searchPlaylistResults = [];
    /** @var array<int,array<int,array<string,int>>> */
    public array $tracksByPlaylist = [];
    /** @var array<int,true> */
    public array $history = [];
    public bool $strictTracks = false;
    public int $defaultTrackCount = 160;
    public int $seededHistoryCalls = 0;
    /** @var array<int,array<int,int>> */
    public array $playRecordLists = [0 => [], 1 => []];

    public function __construct(array $config = [])
    {
        $this->config = array_replace(['daka_history_dir' => ''], $config);
    }

    /**
     * @param array<int,true> $exclude
     * @return array<int,array{id:int,sourceId:int,time:int}>
     */
    public function candidates(array $exclude = [], int $limit = 300, string $source = 'daily_recommend'): array
    {
        return $this->dakaCandidates($source, $exclude, $limit);
    }

    protected function loadDakaHistory(): array
    {
        return $this->history;
    }

    protected function seedDakaHistoryFromPlayRecord(): void
    {
        $this->seededHistoryCalls++;
        foreach ($this->playRecordLists[0] ?? [] as $id) {
            $this->history[(int)$id] = true;
        }
        foreach ($this->playRecordLists[1] ?? [] as $id) {
            $this->history[(int)$id] = true;
        }
        parent::seedDakaHistoryFromPlayRecord();
    }

    protected function requestApi(
        string $uri,
        array $data = [],
        string $crypto = 'eapi',
        array $options = []
    ): array {
        if ($uri !== '/api/v1/play/record') {
            return ['body' => '{}'];
        }
        $list = [];
        foreach ($this->playRecordLists[(int)($data['type'] ?? 0)] ?? [] as $id) {
            $list[] = ['songId' => (int)$id];
        }
        return ['body' => (string)json_encode(['code' => 200, 'list' => $list])];
    }

    public function recommend_playlist()
    {
        $this->recommendPlaylistCalls++;
        return $this->recommendPlaylists;
    }

    public function get_highquality_playlist($limit, $before = 0)
    {
        $this->highqualityCalls++;
        return [9000];
    }

    public function personalized($limit)
    {
        $this->personalizedCalls++;
        return [9001];
    }

    public function get_search_playlist2($keywords = '冷门', $type = 1000, $limit = 50): array
    {
        $call = $this->searchPlaylistCalls++;
        // Each search round must return fresh playlists so the long tail can
        // actually deepen the pool.
        return $this->searchPlaylistResults[$call]
            ?? $this->searchPlaylistResults[0]
            ?? [880];
    }

    public function playlist_detail($playlist_id)
    {
        $playlistId = (int)$playlist_id;
        $this->playlistDetailCalls[] = $playlistId;
        if (array_key_exists($playlistId, $this->tracksByPlaylist)) {
            return ['code' => 200, 'playlist' => ['tracks' => $this->tracksByPlaylist[$playlistId]]];
        }
        if ($this->strictTracks) {
            return ['code' => 200, 'playlist' => ['tracks' => []]];
        }
        $tracks = [];
        for ($i = 1; $i <= $this->defaultTrackCount; $i++) {
            $tracks[] = ['id' => $playlistId * 1000 + $i, 'dt' => 180000];
        }
        return ['code' => 200, 'playlist' => ['tracks' => $tracks]];
    }
}

final class NeteaseDailyResponseFixture extends Netease
{
    public function __construct()
    {
    }

    public function recommand_songs()
    {
        return [
            'code' => 200,
            'data' => [
                'dailySongs' => [
                    ['id' => 801, 'dt' => 181000],
                    ['song' => ['id' => 802, 'duration' => 202000]],
                ],
            ],
        ];
    }
}

function selectionCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$dailyResponseFixture = new NeteaseDailyResponseFixture();
selectionCheck(
    $dailyResponseFixture->daily_recommend_songs() === [
        ['id' => 801, 'sourceId' => 0, 'time' => 181],
        ['id' => 802, 'sourceId' => 0, 'time' => 202],
    ],
    'Home daily recommendation response was not normalized into playable songs'
);

// A full day must be reachable, and every candidate has to name the playlist
// it came from because upstream reports `sourceid=<playlist>`.
$fixture = new NeteaseSelectionFixture();
$songs = $fixture->candidates([], 300);
selectionCheck(count($songs) === 300, 'Playlist-driven selection did not fill 300 songs');
foreach ($songs as $song) {
    selectionCheck((int)$song['sourceId'] > 0, 'A candidate was produced without a real playlist source id');
    selectionCheck((int)$song['id'] > 0, 'A candidate was produced without a song id');
    selectionCheck((int)$song['time'] >= 60, 'A candidate below the duration floor was selected');
}
selectionCheck(
    count($fixture->playlistDetailCalls) === count(array_unique($fixture->playlistDetailCalls)),
    'The same playlist was fetched more than once in a single run'
);
selectionCheck(
    array_diff($fixture->playlistDetailCalls, [9100, 9101, 880]) === [],
    'The default source did not start from the account home recommendations'
);
selectionCheck(
    $fixture->recommendPlaylistCalls === 1 && $fixture->searchPlaylistCalls === 0,
    'The default source queried beyond the home recommendations while they filled the target'
);

// Songs already submitted today must not be offered again, and re-running the
// selection must not re-download the playlists.
$firstBatch = $fixture->candidates([], 50);
$exclude = array_fill_keys(array_keys($firstBatch), true);
$detailCallsBefore = count($fixture->playlistDetailCalls);
$secondBatch = $fixture->candidates($exclude, 50);
selectionCheck(count($secondBatch) === 50, 'The top-up batch could not be filled');
selectionCheck(
    array_intersect_key($secondBatch, $exclude) === [],
    'Songs already submitted today were offered again'
);
selectionCheck(
    count($fixture->playlistDetailCalls) === $detailCallsBefore,
    'A second batch re-downloaded playlists instead of using the per-run cache'
);

// The core rule: songs already reported on earlier days can never count, so
// they must be excluded from the batch instead of padding it. The collector
// has to dig into deeper pools to reach the quota with fresh songs.
$historyFixture = new NeteaseSelectionFixture(['daka_playlist_ids' => '555']);
$historyFixture->strictTracks = true;
$historyFixture->tracksByPlaylist = [555 => [
    ['id' => 11, 'dt' => 180000],
    ['id' => 12, 'dt' => 180000],
    ['id' => 13, 'dt' => 180000],
]];
$historyFixture->searchPlaylistResults = [0 => [666], 1 => [667]];
$historyFixture->tracksByPlaylist[666] = [
    ['id' => 61, 'dt' => 180000],
    ['id' => 62, 'dt' => 180000],
    ['id' => 63, 'dt' => 180000],
];
$historyFixture->tracksByPlaylist[667] = [
    ['id' => 67, 'dt' => 180000],
];
$historyFixture->history = [11 => true, 12 => true, 61 => true];
$historySongs = $historyFixture->candidates([], 4);
selectionCheck(count($historySongs) === 4, 'Fresh-aware selection did not fill the quota from deeper pools');
selectionCheck(
    !isset($historySongs[11]) && !isset($historySongs[12]) && !isset($historySongs[61]),
    'Songs reported on earlier days were reused instead of excluded'
);
selectionCheck(
    isset($historySongs[13], $historySongs[62], $historySongs[63], $historySongs[67]),
    'Fresh songs were skipped while filling the quota'
);
selectionCheck(
    (int)($historySongs[62]['sourceId'] ?? 0) === 666,
    'A deep-pool candidate did not name the playlist it came from'
);
selectionCheck(
    $historyFixture->searchPlaylistCalls >= 1,
    'The collector stopped at a history-exhausted pool instead of digging deeper'
);
selectionCheck(
    array_slice($historyFixture->playlistDetailCalls, 0, 1) === [555],
    'The configured playlist was not consulted first'
);

// Heavy account: the first search round's fresh supply is exhausted, so the
// collector must run additional random-keyword search rounds. The default
// config allows two search rounds; the second round's fresh songs must make
// it into the batch.
$deepFixture = new NeteaseSelectionFixture();
$deepFixture->strictTracks = true;
$deepFixture->recommendPlaylists = [];
$deepFixture->searchPlaylistResults = [
    0 => [701],
    1 => [702],
];
$deepFixture->tracksByPlaylist = [
    701 => [['id' => 71, 'dt' => 180000]],
    702 => [['id' => 72, 'dt' => 180000]],
];
$deepSongs = $deepFixture->candidates([], 2);
selectionCheck(
    isset($deepSongs[71], $deepSongs[72]) && count($deepSongs) === 2,
    'Additional search rounds did not deepen the fresh-song supply'
);
selectionCheck(
    $deepFixture->searchPlaylistCalls === 2,
    'The second search round was not consulted when the first ran dry'
);

// Raising daka_search_rounds extends the digging budget with fresh keywords.
$deepBudgetFixture = new NeteaseSelectionFixture(['daka_search_rounds' => 4]);
$deepBudgetFixture->strictTracks = true;
$deepBudgetFixture->recommendPlaylists = [];
$deepBudgetFixture->searchPlaylistResults = [
    0 => [701],
    1 => [702],
    2 => [703],
    3 => [704],
];
$deepBudgetFixture->tracksByPlaylist = [
    701 => [['id' => 71, 'dt' => 180000]],
    702 => [['id' => 72, 'dt' => 180000]],
    703 => [['id' => 73, 'dt' => 180000]],
    704 => [['id' => 74, 'dt' => 180000]],
];
$deepBudgetSongs = $deepBudgetFixture->candidates([], 4);
selectionCheck(
    isset($deepBudgetSongs[71], $deepBudgetSongs[72], $deepBudgetSongs[73], $deepBudgetSongs[74])
        && count($deepBudgetSongs) === 4,
    'The extra configured search rounds did not contribute fresh songs'
);
selectionCheck(
    $deepBudgetFixture->searchPlaylistCalls === 4,
    'The extended search-round budget was not fully consulted'
);

// The duration floor relaxes when the pool cannot fill the target, but plays
// too short for NetEase to count are still refused.
$shortFixture = new NeteaseSelectionFixture(['daka_playlist_ids' => '777']);
$shortFixture->strictTracks = true;
$shortFixture->recommendPlaylists = [];
$shortFixture->tracksByPlaylist = [777 => [
    ['id' => 21, 'dt' => 20000],
    ['id' => 22, 'dt' => 45000],
    ['id' => 23, 'dt' => 180000],
]];
$shortFixture->searchPlaylistResults = [0 => []];
$relaxed = $shortFixture->candidates([], 3);
selectionCheck(isset($relaxed[23]), 'A normal-length song was dropped');
selectionCheck(isset($relaxed[22]), 'The duration floor did not relax when candidates ran short');
selectionCheck(!isset($relaxed[21]), 'A song too short to be counted was selected');

$plentyFixture = new NeteaseSelectionFixture(['daka_playlist_ids' => '778']);
$plentyFixture->strictTracks = true;
$plentyFixture->recommendPlaylists = [];
$plentyFixture->tracksByPlaylist = [778 => [
    ['id' => 31, 'dt' => 45000],
    ['id' => 32, 'dt' => 180000],
]];
$strict = $plentyFixture->candidates([], 1);
selectionCheck($strict === [32 => ['id' => 32, 'sourceId' => 778, 'time' => 180]],
    'The duration floor relaxed even though the target was already reachable');

// Optional sources keep working and never fall back to the home feed.
$highquality = new NeteaseSelectionFixture();
$highquality->searchPlaylistResults = [0 => []];
$highqualitySongs = $highquality->candidates([], 300, 'highquality');
selectionCheck(count($highqualitySongs) === 300, 'The high-quality source did not fill the target');
selectionCheck($highquality->highqualityCalls === 1, 'The high-quality source was not queried');
selectionCheck($highquality->recommendPlaylistCalls === 0, 'The high-quality source queried home recommendations');
selectionCheck(
    count(array_filter(
        $highqualitySongs,
        static fn(array $song): bool => (int)$song['sourceId'] === 9000
    )) === 160,
    'The high-quality playlist did not contribute its tracks'
);

$personalized = new NeteaseSelectionFixture();
$personalized->searchPlaylistResults = [0 => []];
$personalizedSongs = $personalized->candidates([], 300, 'personalized');
selectionCheck(count($personalizedSongs) === 300, 'The personalized source did not fill the target');
selectionCheck($personalized->personalizedCalls === 1, 'The personalized source was not queried');
selectionCheck($personalized->recommendPlaylistCalls === 0, 'The personalized source queried home recommendations');

// Official charts stay in the pool chain (after the search tier now) and must
// be able to finish a day alone when the search tier comes back empty.
$chartsOnly = new NeteaseSelectionFixture();
$chartsOnly->recommendPlaylists = [];
$chartsOnly->searchPlaylistResults = [0 => [], 1 => []];
$chartSongs = $chartsOnly->candidates([], 300);
selectionCheck(count($chartSongs) === 300, 'The official chart fallback could not fill the target');
selectionCheck(
    array_intersect($chartsOnly->playlistDetailCalls, [3778678, 19723756, 3779629, 2884035])
        === $chartsOnly->playlistDetailCalls,
    'The fallback used playlists outside the official charts'
);

// The obscure-search tier must come BEFORE the charts so heavy accounts find
// long-tail songs before everything's-most-heard chart material.
$orderFixture = new NeteaseSelectionFixture();
$orderFixture->strictTracks = true;
$orderFixture->recommendPlaylists = [];
$orderFixture->searchPlaylistResults = [0 => [556]];
$orderFixture->tracksByPlaylist = [
    556 => [['id' => 56, 'dt' => 180000]],
];
$orderSongs = $orderFixture->candidates([], 1);
selectionCheck(
    isset($orderSongs[56]) && (int)$orderSongs[56]['sourceId'] === 556,
    'The search tier did not supply a candidate'
);
selectionCheck(
    count(array_intersect($orderFixture->playlistDetailCalls, [3778678, 19723756, 3779629, 2884035])) === 0,
    'The charts were consulted before the search tier ran dry'
);

// When every regular pool runs dry the search tier keeps the day fillable;
// its songs still name the playlist they came from.
$starvedFixture = new NeteaseSelectionFixture(['daka_playlist_ids' => '779']);
$starvedFixture->strictTracks = true;
$starvedFixture->recommendPlaylists = [];
$starvedFixture->tracksByPlaylist = [
    779 => [['id' => 41, 'dt' => 180000]],
    880 => [['id' => 42, 'dt' => 180000]],
];
$starvedFixture->searchPlaylistResults = [0 => [880]];
$starved = $starvedFixture->candidates([], 2);
selectionCheck($starvedFixture->searchPlaylistCalls === 1, 'The obscure-search fallback pool was not consulted when earlier pools ran dry');
selectionCheck(
    isset($starved[41], $starved[42]) && count($starved) === 2,
    'The obscure-search fallback did not contribute playlist-sourced tracks'
);
selectionCheck(
    (int)($starved[42]['sourceId'] ?? 0) === 880,
    'A search-fallback candidate did not name the playlist it came from'
);

// A playlist request that blows up must not take the whole run down.
final class NeteaseSelectionFailureFixture extends NeteaseSelectionFixture
{
    public function recommend_playlist()
    {
        throw new RuntimeException('recommendation endpoint down');
    }
}

$failing = new NeteaseSelectionFailureFixture();
$failingSongs = $failing->candidates([], 300);
selectionCheck(count($failingSongs) === 300, 'A failing pool prevented the later pools from filling the target');

// First run: the account's play-record charts seed the history so songs
// heard before the tool existed are treated as repeats. The fixture needs a
// real history directory: an unresolvable path suppresses the seed entirely.
$seedDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'loopdeck-daka-seed-' . bin2hex(random_bytes(6));
selectionCheck(@mkdir($seedDirectory, 0770, true), 'Seed test directory could not be created');
$seedFixture = new NeteaseSelectionFixture([
    'daka_playlist_ids' => '560',
    'daka_history_dir' => $seedDirectory,
]);
$seedFixture->strictTracks = true;
$seedFixture->recommendPlaylists = [];
$seedFixture->tracksByPlaylist = [
    560 => [
        ['id' => 90, 'dt' => 180000],
        ['id' => 91, 'dt' => 180000],
    ],
];
$seedFixture->searchPlaylistResults = [0 => []];
$seedFixture->playRecordLists = [0 => [90], 1 => [91]];
$seedFixture->candidates([], 2);
selectionCheck(
    isset($seedFixture->history[90]) && isset($seedFixture->history[91]),
    'The play-record seed did not merge already-played songs into the history'
);
selectionCheck(
    count(glob($seedDirectory . DIRECTORY_SEPARATOR . '*.seeded') ?: []) === 1,
    'The play-record seed marker was not written next to the history file'
);
$seededSongs = $seedFixture->candidates([], 2);
selectionCheck(
    $seededSongs === [],
    'Play-record-seeded songs were still offered as fresh candidates'
);
foreach (glob($seedDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $seedFile) {
    @unlink($seedFile);
}
@rmdir($seedDirectory);

echo "Netease song selection tests passed\n";
