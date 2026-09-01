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
    /** @var array<int,int> */
    public array $searchPlaylists = [];
    /** @var array<int,array<int,array<string,int>>> */
    public array $tracksByPlaylist = [];
    /** @var array<int,true> */
    public array $history = [];
    public bool $strictTracks = false;
    public int $defaultTrackCount = 160;

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
        $this->searchPlaylistCalls++;
        return $this->searchPlaylists;
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
    count($fixture->playlistDetailCalls) <= 3,
    'Filling 300 songs required more than three playlist requests'
);
selectionCheck(
    array_diff($fixture->playlistDetailCalls, [9100, 9101]) === [],
    'The default source did not start from the account home recommendations'
);

// Songs already reported today must not be offered again, and re-running the
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

// Songs used on earlier days stay eligible; they are only ranked last.
$rankFixture = new NeteaseSelectionFixture(['daka_playlist_ids' => '555']);
$rankFixture->strictTracks = true;
$rankFixture->tracksByPlaylist = [555 => [
    ['id' => 11, 'dt' => 180000],
    ['id' => 12, 'dt' => 180000],
    ['id' => 13, 'dt' => 180000],
    ['id' => 14, 'dt' => 180000],
]];
$rankFixture->history = [11 => true, 12 => true];
$ranked = $rankFixture->candidates([], 4);
selectionCheck(count($ranked) === 4, 'Songs reported on earlier days were excluded instead of reused');
$rankedOrder = array_keys($ranked);
selectionCheck(
    array_slice($rankedOrder, 0, 2) === array_values(array_diff($rankedOrder, [11, 12])),
    'Never-reported songs were not ranked ahead of previously reported ones'
);
selectionCheck(
    $rankFixture->recommendPlaylistCalls === 0,
    'A configured playlist that already fills the target still queried recommendations'
);
selectionCheck($rankFixture->playlistDetailCalls === [555], 'The configured playlist was not used first');

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
$personalizedSongs = $personalized->candidates([], 300, 'personalized');
selectionCheck(count($personalizedSongs) === 300, 'The personalized source did not fill the target');
selectionCheck($personalized->personalizedCalls === 1, 'The personalized source was not queried');
selectionCheck($personalized->recommendPlaylistCalls === 0, 'The personalized source queried home recommendations');

// Official charts are the last resort and must be able to finish a day alone.
$chartsOnly = new NeteaseSelectionFixture();
$chartsOnly->recommendPlaylists = [];
$chartSongs = $chartsOnly->candidates([], 300);
selectionCheck(count($chartSongs) === 300, 'The official chart fallback could not fill the target');
selectionCheck(
    array_intersect($chartsOnly->playlistDetailCalls, [3778678, 19723756, 3779629, 2884035])
        === $chartsOnly->playlistDetailCalls,
    'The fallback used playlists outside the official charts'
);
selectionCheck($chartsOnly->searchPlaylistCalls === 0, 'The official charts still triggered the obscure-search fallback');

// When every regular pool runs dry the obscure-playlist search tier keeps the
// day fillable; its songs still name the playlist they came from.
$starvedFixture = new NeteaseSelectionFixture(['daka_playlist_ids' => '779']);
$starvedFixture->strictTracks = true;
$starvedFixture->recommendPlaylists = [];
$starvedFixture->tracksByPlaylist = [
    779 => [['id' => 41, 'dt' => 180000]],
    880 => [['id' => 42, 'dt' => 180000]],
];
$starvedFixture->searchPlaylists = [880];
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
selectionCheck(count($failingSongs) === 300, 'A failing pool prevented the official charts from filling the target');

echo "Netease song selection tests passed\n";
