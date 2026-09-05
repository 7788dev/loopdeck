<?php

declare(strict_types=1);

namespace epic;

/**
 * Minimal client for Epic's public free-games catalog endpoint.
 */
class Epic
{
    private const CATALOG_URL = 'https://store-site-backend-static-ipv4.ak.epicgames.com/freeGamesPromotions';

    public function getWeeklyFreeGames(): array
    {
        // 周免一周才换一次；每个页面/每封邮件都同步请求上游（20s 超时）会把
        // Epic 页面拖住，缓存 1 小时即可。
        $cached = \think\facade\Cache::get('epic_weekly_free_games_v2');
        if (is_array($cached)) {
            return $cached;
        }

        $games = $this->fetchWeeklyFreeGames();
        \think\facade\Cache::set('epic_weekly_free_games_v2', $games, $games !== [] ? 3600 : 120);
        return $games;
    }

    private function fetchWeeklyFreeGames(): array
    {
        $payload = $this->request('GET', self::CATALOG_URL, [
            'locale' => 'zh-CN',
            'country' => 'CN',
            'allowCountries' => 'CN',
        ]);
        if (!is_array($payload)) {
            return [];
        }

        return $this->parseCatalog($payload);
    }

    public function parseCatalog(array $payload, ?int $now = null): array
    {
        $elements = $payload['data']['Catalog']['searchStore']['elements'] ?? [];
        if (!is_array($elements)) {
            return [];
        }

        $now ??= time();
        $current = [];
        $upcoming = [];
        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }
            $offers = $element['promotions']['promotionalOffers'] ?? [];
            $futureOffers = $element['promotions']['upcomingPromotionalOffers'] ?? [];
            $active = $this->freeOffer(is_array($offers) ? $offers : [], $now, true);
            $future = $this->freeOffer(is_array($futureOffers) ? $futureOffers : [], $now, false);
            if ($active !== []) {
                $current[] = $this->normaliseGame($element, $active, $now, true);
            } elseif ($future !== []) {
                $upcoming[] = $this->normaliseGame($element, $future, $now, false);
            }
        }

        return array_merge($current, $upcoming);
    }

    public function curl(
        string $method,
        string $url,
        array $params = [],
        string $cookie = '',
        array $headers = [],
        bool $json = true
    ) {
        return $this->request($method, $url, $params, $cookie, $headers, $json);
    }

    private function request(
        string $method,
        string $url,
        array $params = [],
        string $cookie = '',
        array $headers = [],
        bool $decodeJson = true
    ) {
        $method = strtoupper($method);
        if ($method === 'GET' && $params) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'LoopDeck/clean EpicCatalogClient',
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
        ]);
        if ($cookie !== '') {
            curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        }
        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (!is_string($body) || $status < 200 || $status >= 300) {
            return $decodeJson ? [] : '';
        }
        if (!$decodeJson) {
            return $body;
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function freeOffer(array $groups, int $now, bool $mustBeActive): array
    {
        foreach ($groups as $group) {
            foreach (($group['promotionalOffers'] ?? []) as $offer) {
                if ((int)($offer['discountSetting']['discountPercentage'] ?? -1) !== 0) {
                    continue;
                }
                $start = strtotime((string)($offer['startDate'] ?? ''));
                $end = strtotime((string)($offer['endDate'] ?? ''));
                if ($start !== false && $end !== false && $end > $start
                    && ($mustBeActive ? ($start <= $now && $end > $now) : ($start > $now))) {
                    return $offer;
                }
            }
        }
        return [];
    }

    private function normaliseGame(array $element, array $promotion, int $now, bool $available): array
    {
        $image = '';
        foreach (($element['keyImages'] ?? []) as $item) {
            if (in_array($item['type'] ?? '', ['OfferImageWide', 'DieselStoreFrontWide', 'Thumbnail'], true)) {
                $image = (string)($item['url'] ?? '');
                if ($image !== '') {
                    break;
                }
            }
        }

        $slug = (string)($element['productSlug'] ?? '');
        if ($slug === '' || $slug === '[]') {
            $slug = (string)($element['urlSlug'] ?? '');
        }
        $mapping = $element['catalogNs']['mappings'][0]['pageSlug'] ?? '';
        if ($mapping !== '') {
            $slug = (string)$mapping;
        }

        $endAt = (int)strtotime((string)$promotion['endDate']);
        $leftDay = max(0, (int)ceil(($endAt - $now) / 86400));
        $originalPrice = (string)(
            $element['price']['totalPrice']['fmtPrice']['originalPrice']
            ?? $element['price']['totalPrice']['originalPrice']
            ?? ''
        );

        return [
            'title' => (string)($element['title'] ?? 'Epic 免费游戏'),
            'description' => (string)($element['description'] ?? ''),
            'image' => preg_match('#\Ahttps?://#i', $image) && filter_var($image, FILTER_VALIDATE_URL) ? $image : '',
            'leftDay' => $leftDay,
            'available' => $available,
            'start_at' => (int)strtotime((string)$promotion['startDate']),
            'end_at' => $endAt,
            'originalPrice' => $originalPrice,
            'productUrl' => $slug !== ''
                ? 'https://store.epicgames.com/zh-CN/p/' . rawurlencode($slug)
                : 'https://store.epicgames.com/zh-CN/free-games',
        ];
    }

}
