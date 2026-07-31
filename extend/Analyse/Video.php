<?php

declare(strict_types=1);

namespace Analyse;

use DOMDocument;
use DOMXPath;

/**
 * Safe local metadata parser for public video/image pages.
 *
 * It deliberately does not proxy or download media. The returned media URLs
 * point at their original hosts.
 */
class Video
{
    private const MAX_DOCUMENT_BYTES = 2_097_152;

    public function analyse(string $input): array
    {
        $url = $this->extractUrl($input);
        if ($url === '' || !$this->isSafePublicUrl($url)) {
            return ['code' => 0, 'message' => '请输入有效的公网 HTTP/HTTPS 链接'];
        }

        $response = $this->fetch($url);
        if (!$response['ok']) {
            return ['code' => 0, 'message' => $response['message']];
        }

        $contentType = strtolower($response['content_type']);
        $finalUrl = $response['url'];
        if (str_starts_with($contentType, 'video/') || str_starts_with($contentType, 'audio/')) {
            return [
                'code' => 1,
                'message' => '识别到媒体直链',
                'data' => [
                    'title' => basename((string)parse_url($finalUrl, PHP_URL_PATH)),
                    'description' => '',
                    'cover' => '',
                    'video' => $finalUrl,
                    'music' => str_starts_with($contentType, 'audio/') ? $finalUrl : '',
                    'images' => [],
                    'source' => $finalUrl,
                ],
            ];
        }
        if (str_starts_with($contentType, 'image/')) {
            return [
                'code' => 1,
                'message' => '识别到图片直链',
                'data' => [
                    'title' => basename((string)parse_url($finalUrl, PHP_URL_PATH)),
                    'description' => '',
                    'cover' => $finalUrl,
                    'video' => '',
                    'music' => '',
                    'images' => [$finalUrl],
                    'source' => $finalUrl,
                ],
            ];
        }

        $metadata = $this->parseHtml($response['body'], $finalUrl);
        if ($metadata['video'] === '' && !$metadata['images'] && $metadata['cover'] === '') {
            return [
                'code' => 0,
                'message' => '页面未公开可解析的媒体地址；该平台可能需要登录或已调整页面协议',
            ];
        }
        return ['code' => 1, 'message' => '解析成功', 'data' => $metadata];
    }

    public function parse(string $input): array
    {
        return $this->analyse($input);
    }

    private function extractUrl(string $input): string
    {
        if (preg_match('~https?://[^\s<>"\']+~iu', trim($input), $match)) {
            return rtrim($match[0], '.,;，。；）)');
        }
        return '';
    }

    private function isSafePublicUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $host = strtolower((string)($parts['host'] ?? ''));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local')) {
            return false;
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (!$ips) {
            return false;
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }
        return true;
    }

    private function fetch(string $url): array
    {
        $body = '';
        $tooLarge = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) OneToolSafeParser/1.0',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,image/*,video/*;q=0.8'],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > self::MAX_DOCUMENT_BYTES) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error = curl_error($ch);
        curl_close($ch);

        if ($tooLarge && !str_starts_with(strtolower($contentType), 'text/')) {
            return [
                'ok' => true,
                'body' => '',
                'url' => $finalUrl ?: $url,
                'content_type' => $contentType,
                'message' => '',
            ];
        }
        if ($ok === false || $status < 200 || $status >= 400) {
            return [
                'ok' => false,
                'body' => '',
                'url' => $url,
                'content_type' => '',
                'message' => $error !== '' ? '请求失败：' . $error : '目标页面返回 HTTP ' . $status,
            ];
        }
        if ($tooLarge) {
            return [
                'ok' => false,
                'body' => '',
                'url' => $url,
                'content_type' => $contentType,
                'message' => '目标页面超过 2MB，已停止读取',
            ];
        }
        if (!$this->isSafePublicUrl($finalUrl ?: $url)) {
            return ['ok' => false, 'body' => '', 'url' => $url, 'content_type' => '', 'message' => '重定向目标不安全'];
        }
        return [
            'ok' => true,
            'body' => $body,
            'url' => $finalUrl ?: $url,
            'content_type' => $contentType,
            'message' => '',
        ];
    }

    private function parseHtml(string $html, string $baseUrl): array
    {
        libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new DOMXPath($document);
        $meta = [];
        foreach ($xpath->query('//meta[@content]') ?: [] as $node) {
            $key = strtolower(trim((string)($node->getAttribute('property') ?: $node->getAttribute('name'))));
            if ($key !== '') {
                $meta[$key] = trim((string)$node->getAttribute('content'));
            }
        }
        $titleNode = $xpath->query('//title')->item(0);
        $title = $meta['og:title'] ?? $meta['twitter:title'] ?? ($titleNode ? trim($titleNode->textContent) : '');
        $description = $meta['og:description'] ?? $meta['description'] ?? $meta['twitter:description'] ?? '';
        $cover = $this->absoluteUrl($meta['og:image'] ?? $meta['twitter:image'] ?? '', $baseUrl);
        $video = $this->absoluteUrl(
            $meta['og:video:secure_url'] ?? $meta['og:video:url'] ?? $meta['og:video'] ?? $meta['twitter:player:stream'] ?? '',
            $baseUrl
        );
        $music = $this->absoluteUrl($meta['og:audio:secure_url'] ?? $meta['og:audio'] ?? '', $baseUrl);
        $images = [];
        foreach ($xpath->query('//meta[@property="og:image"]/@content | //img/@src') ?: [] as $node) {
            $candidate = $this->absoluteUrl(trim($node->nodeValue), $baseUrl);
            if ($candidate !== '' && $this->isSafePublicUrl($candidate)) {
                $images[] = $candidate;
            }
            if (count($images) >= 20) {
                break;
            }
        }
        libxml_clear_errors();

        return [
            'title' => $title,
            'description' => $description,
            'cover' => $cover,
            'video' => $video,
            'music' => $music,
            'images' => array_values(array_unique($images)),
            'source' => $baseUrl,
        ];
    }

    private function absoluteUrl(string $url, string $base): string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5);
        if ($url === '') {
            return '';
        }
        if (preg_match('~^https?://~i', $url)) {
            return $this->isSafePublicUrl($url) ? $url : '';
        }
        $baseParts = parse_url($base);
        if (!is_array($baseParts) || empty($baseParts['host'])) {
            return '';
        }
        $origin = $baseParts['scheme'] . '://' . $baseParts['host'] . (isset($baseParts['port']) ? ':' . $baseParts['port'] : '');
        if (str_starts_with($url, '//')) {
            $absolute = $baseParts['scheme'] . ':' . $url;
        } elseif (str_starts_with($url, '/')) {
            $absolute = $origin . $url;
        } else {
            $path = (string)($baseParts['path'] ?? '/');
            $absolute = $origin . rtrim(str_replace('\\', '/', dirname($path)), '/') . '/' . $url;
        }
        return $this->isSafePublicUrl($absolute) ? $absolute : '';
    }
}
