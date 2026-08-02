<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use netease\Netease;

final class NeteaseSelectionFixture extends Netease
{
    public array $artists = [];
    public array $queries = [];

    public function __construct()
    {
    }

    public function selectedSongs(int $limit = 300): array
    {
        return $this->dakaSongs('highquality', [], $limit);
    }

    public function supplementSongs(array $history = [], int $limit = 300): array
    {
        return $this->dakaSupplementSongs($history, $limit);
    }

    public function get_highquality_playlist($limit, $before = 0)
    {
        return [9000];
    }

    public function personalized($limit)
    {
        return [9001];
    }

    public function playlist_detail($playlist_id)
    {
        $start = (int)$playlist_id === 9000 ? 100000 : ((int)$playlist_id * 1000);
        $tracks = [];
        for ($i = 1; $i <= 160; $i++) {
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
        $this->queries[] = ['keywords' => $keywords, 'offset' => $offset, 'artist' => $preferredArtist];
        $base = 500000 + count($this->artists) * 1000;
        $songs = [];
        for ($i = 1; $i <= 100; $i++) {
            $songs[] = ['id' => $base + $i, 'sourceId' => 0, 'time' => 200];
        }
        return $songs;
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

selectionCheck(count($songs) === 300, 'Popular song selection did not fill 300 unique songs');
selectionCheck(
    array_slice($fixture->artists, 0, 3) === ['徐良', '许嵩', '薛之谦'],
    'Core artists requested by the user were not prioritized'
);
selectionCheck(
    array_slice($fixture->artists, 3, 4) === ['汪苏泷', '周杰伦', '林俊杰', '陈奕迅'],
    'Similar mainstream artists were not used to complete the popular mix'
);
selectionCheck(
    count(array_filter($songs, static fn(array $song): bool => (int)$song['sourceId'] === 9000)) === 140,
    'Default selection did not preserve the精品歌单 quota'
);
foreach ([19723756, 3779629, 2884035, 3778678] as $chartPlaylistId) {
    selectionCheck(
        count(array_filter(
            $songs,
            static fn(array $song): bool => (int)$song['sourceId'] === $chartPlaylistId
        )) === 20,
        'Each official chart must contribute its own 20-song quota'
    );
}

$supplementFixture = new NeteaseSelectionFixture();
$supplement = $supplementFixture->supplementSongs([], 300);
selectionCheck(count($supplement) === 300, 'Supplement selection did not fill a unique 300-song batch');
foreach ([3779629, 19723756, 2884035, 3778678] as $chartPlaylistId) {
    selectionCheck(
        count(array_filter(
            $supplement,
            static fn(array $song): bool => (int)$song['sourceId'] === $chartPlaylistId
        )) === 30,
        'Supplement selection did not preserve the changing-chart quota'
    );
}
selectionCheck(
    array_slice($supplementFixture->artists, 0, 7) === ['徐良', '许嵩', '薛之谦', '汪苏泷', '周杰伦', '林俊杰', '陈奕迅'],
    'Supplement selection did not retain the requested popular artist style'
);
selectionCheck(
    count(array_filter(
        $supplementFixture->queries,
        static fn(array $query): bool => str_contains((string)$query['keywords'], date('Y'))
    )) >= 1,
    'Supplement selection did not include current-year new-song searches'
);

echo "Netease song selection tests passed\n";
