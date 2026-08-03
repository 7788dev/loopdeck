<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use netease\Netease;

final class NeteaseSelectionFixture extends Netease
{
    public array $artists = [];
    public array $playlistQueries = [];
    public int $dailySongRequests = 0;
    public int $dailyPlaylistRequests = 0;
    public int $dailyPlaylistTrackCount = 160;

    public function __construct()
    {
    }

    public function selectedSongs(string $source = 'daily_recommend', int $limit = 300): array
    {
        return $this->dakaSongs($source, [], $limit);
    }

    public function supplementSongs(array $history = [], int $limit = 300): array
    {
        return $this->dakaSupplementSongs($history, $limit);
    }

    public function daily_recommend_songs(): array
    {
        $this->dailySongRequests++;
        $songs = [];
        for ($i = 1; $i <= 30; $i++) {
            $songs[] = ['id' => 700000 + $i, 'sourceId' => 0, 'time' => 180];
        }
        return $songs;
    }

    public function recommend_playlist()
    {
        $this->dailyPlaylistRequests++;
        return [9100, 9101];
    }

    public function get_highquality_playlist($limit, $before = 0)
    {
        return [9000];
    }

    public function personalized($limit)
    {
        return [9001];
    }

    public function get_search_playlist($keywords = '冷门', $type = 1000, $limit = 100)
    {
        $this->playlistQueries[] = (string)$keywords;
        return [9200 + count($this->playlistQueries)];
    }

    public function get_new_songs()
    {
        return [];
    }

    public function playlist_detail($playlist_id)
    {
        $start = (int)$playlist_id === 9000 ? 100000 : ((int)$playlist_id * 1000);
        $trackCount = in_array((int)$playlist_id, [9100, 9101], true)
            ? $this->dailyPlaylistTrackCount
            : 160;
        $tracks = [];
        for ($i = 1; $i <= $trackCount; $i++) {
            $tracks[] = ['id' => $start + $i, 'dt' => 180000];
        }
        return ['code' => 200, 'playlist' => ['tracks' => $tracks]];
    }

    public function search_songs(
        string $keywords,
        int $limit = 100,
        int $offset = 0,
        ?string $preferredArtist = null
    ): array {
        $this->artists[] = $preferredArtist ?? $keywords;
        $base = 500000 + count($this->artists) * 1000;
        $songs = [];
        for ($i = 1; $i <= 100; $i++) {
            $songs[] = ['id' => $base + $i, 'sourceId' => 0, 'time' => 200];
        }
        return $songs;
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

$fixture = new NeteaseSelectionFixture();
$songs = $fixture->selectedSongs();

$dailyResponseFixture = new NeteaseDailyResponseFixture();
$normalizedDailySongs = $dailyResponseFixture->daily_recommend_songs();
selectionCheck(
    $normalizedDailySongs === [
        ['id' => 801, 'sourceId' => 0, 'time' => 181],
        ['id' => 802, 'sourceId' => 0, 'time' => 202],
    ],
    'Home daily recommendation response was not normalized into playable songs'
);

selectionCheck(count($songs) === 300, 'Popular song selection did not fill 300 unique songs');
selectionCheck($fixture->dailySongRequests === 1, 'Default selection did not request home daily songs');
selectionCheck($fixture->dailyPlaylistRequests === 1, 'Default selection did not request home daily playlists');
selectionCheck(
    count(array_filter(
        $songs,
        static fn(array $song): bool => (int)$song['id'] >= 700001 && (int)$song['id'] <= 700030
    )) === 30,
    'Default selection did not retain all available daily recommended songs'
);
selectionCheck(
    count(array_filter(
        $songs,
        static fn(array $song): bool => in_array((int)$song['sourceId'], [9100, 9101], true)
    )) === 110,
    'Default selection did not fill the remaining target from home daily recommended playlists'
);
selectionCheck(
    count(array_filter(
        $songs,
        static fn(array $song): bool => (int)$song['sourceId'] === 3779629
    )) === 160,
    'Default selection did not prioritize the fresh new-song chart'
);
selectionCheck(
    $fixture->artists === [],
    'Popular artist fallback ran even though daily recommendations filled 300 songs'
);

$highqualityFixture = new NeteaseSelectionFixture();
$highqualitySongs = $highqualityFixture->selectedSongs('highquality');
selectionCheck(
    count(array_filter(
        $highqualitySongs,
        static fn(array $song): bool => (int)$song['sourceId'] === 9000
    )) === 140,
    'The optional high-quality playlist source no longer preserves its quota'
);
selectionCheck(
    $highqualityFixture->dailySongRequests === 0 && $highqualityFixture->dailyPlaylistRequests === 0,
    'The optional high-quality playlist source unexpectedly called home daily recommendations'
);
selectionCheck(
    array_slice($highqualityFixture->artists, 0, 3) === ['徐良', '许嵩', '薛之谦'],
    'Core artists requested by the user were not prioritized in the popular fallback'
);
selectionCheck(
    array_slice($highqualityFixture->artists, 3, 4) === ['汪苏泷', '周杰伦', '林俊杰', '陈奕迅'],
    'Similar mainstream artists were not used in the popular fallback'
);
foreach ([19723756, 3779629, 2884035, 3778678] as $chartPlaylistId) {
    selectionCheck(
        count(array_filter(
            $highqualitySongs,
            static fn(array $song): bool => (int)$song['sourceId'] === $chartPlaylistId
        )) === 20,
        'Each official chart must contribute its own 20-song quota in the popular fallback'
    );
}

$fallbackFixture = new NeteaseSelectionFixture();
$fallbackFixture->dailyPlaylistTrackCount = 20;
$fallbackSongs = $fallbackFixture->selectedSongs();
selectionCheck(count($fallbackSongs) === 300, 'Daily recommendation fallback did not fill 300 songs');
selectionCheck(
    $fallbackFixture->artists !== [],
    'Daily recommendation fallback did not use popular artists after recommendations ran short'
);

$supplementFixture = new NeteaseSelectionFixture();
$supplementSongs = $supplementFixture->supplementSongs([], 300);
selectionCheck(count($supplementSongs) === 300, 'Supplement selection did not fill 300 unique songs');
selectionCheck(
    count(array_filter(
        $supplementSongs,
        static fn(array $song): bool => in_array((int)$song['sourceId'], [3779629, 9201], true)
    )) === 300,
    'Supplement selection did not retain real playlist source IDs'
);
selectionCheck(
    count(array_filter(
        $supplementSongs,
        static fn(array $song): bool => (int)$song['sourceId'] === 3779629
    )) === 160,
    'Supplement selection did not start from the fresh new-song chart'
);
selectionCheck(
    str_contains((string)($supplementFixture->playlistQueries[0] ?? ''), date('Y')),
    'Supplement selection did not prioritize current-year new-song playlists'
);
selectionCheck(
    $supplementFixture->artists === [],
    'Supplement selection used generic search songs before new-song playlists were exhausted'
);

echo "Netease song selection tests passed\n";
