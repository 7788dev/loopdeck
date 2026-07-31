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
        $payload = $this->request('GET', self::CATALOG_URL, [
            'locale' => 'zh-CN',
            'country' => 'CN',
            'allowCountries' => 'CN',
        ]);
        if (!is_array($payload)) {
            return [];
        }

        $elements = $payload['data']['Catalog']['searchStore']['elements'] ?? [];
        if (!is_array($elements)) {
            return [];
        }

        $now = time();
        $current = [];
        $upcoming = [];
        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }
            $offers = $element['promotions']['promotionalOffers'] ?? [];
            $futureOffers = $element['promotions']['upcomingPromotionalOffers'] ?? [];
            if ($this->hasFreeOffer($offers, $now, true)) {
                $current[] = $this->normaliseGame($element);
            } elseif ($this->hasFreeOffer($futureOffers, $now, false)) {
                $upcoming[] = $this->normaliseGame($element);
            }
        }

        return array_values(array_filter($current ?: $upcoming));
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

    private function hasFreeOffer(array $groups, int $now, bool $mustBeActive): bool
    {
        foreach ($groups as $group) {
            foreach (($group['promotionalOffers'] ?? []) as $offer) {
                if ((int)($offer['discountSetting']['discountPercentage'] ?? -1) !== 0) {
                    continue;
                }
                $start = strtotime((string)($offer['startDate'] ?? '')) ?: 0;
                $end = strtotime((string)($offer['endDate'] ?? '')) ?: PHP_INT_MAX;
                if ($mustBeActive ? ($start <= $now && $end > $now) : ($start > $now)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function normaliseGame(array $element): array
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

        $promotion = $this->findFreeOffer($element);
        $endAt = strtotime((string)($promotion['endDate'] ?? '')) ?: time();
        $leftDay = max(0, (int)ceil(($endAt - time()) / 86400));
        $originalPrice = (string)(
            $element['price']['totalPrice']['fmtPrice']['originalPrice']
            ?? $element['price']['totalPrice']['originalPrice']
            ?? ''
        );

        return [
            'title' => (string)($element['title'] ?? 'Epic 免费游戏'),
            'description' => (string)($element['description'] ?? ''),
            'image' => $image,
            'leftDay' => $leftDay,
            'originalPrice' => $originalPrice,
            'productUrl' => $slug !== ''
                ? 'https://store.epicgames.com/zh-CN/p/' . rawurlencode($slug)
                : 'https://store.epicgames.com/zh-CN/free-games',
        ];
    }

    private function findFreeOffer(array $element): array
    {
        foreach (['promotionalOffers', 'upcomingPromotionalOffers'] as $groupName) {
            foreach (($element['promotions'][$groupName] ?? []) as $group) {
                foreach (($group['promotionalOffers'] ?? []) as $offer) {
                    if ((int)($offer['discountSetting']['discountPercentage'] ?? -1) === 0) {
                        return is_array($offer) ? $offer : [];
                    }
                }
            }
        }
        return [];
    }
}
