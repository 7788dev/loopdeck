<?php

declare(strict_types=1);

namespace app\service;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Throwable;

/** HTTPS-only transport restricted to one adapter's host allow-list. */
final class CheckinHttpTransport implements CheckinTransport
{
    public const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'Chrome/139.0.0.0 Safari/537.36';

    private ClientInterface $client;

    public function __construct(private array $hosts, ?ClientInterface $client = null)
    {
        $this->client = $client ?? HttpClientFactory::create();
    }

    public function request(string $method, string $url, array $options = []): array
    {
        $this->validateUrl($url);
        $effectiveUrl = $url;
        // Header names are case-insensitive; a caller's own headers must not
        // silently drop the defaults (Guzzle would then send its own UA).
        $headers = ['Accept' => 'application/json, text/plain, */*', 'User-Agent' => self::USER_AGENT];
        foreach ((array)($options['headers'] ?? []) as $name => $value) {
            foreach (array_keys($headers) as $existing) {
                if (strcasecmp((string)$existing, (string)$name) === 0) {
                    unset($headers[$existing]);
                }
            }
            $headers[(string)$name] = $value;
        }
        $options = array_replace(['timeout' => 12.0, 'connect_timeout' => 4.0, 'verify' => true], $options);
        $options['headers'] = $headers;
        $options['allow_redirects'] = ['max' => 5, 'protocols' => ['https'],
            'on_redirect' => function (RequestInterface $request, ResponseInterface $response, UriInterface $uri): void {
                $this->validateUrl((string)$uri);
            }];
        $options['on_stats'] = static function (TransferStats $stats) use (&$effectiveUrl): void {
            $effectiveUrl = (string)$stats->getEffectiveUri();
        };
        try {
            $response = $this->client->request($method, $url, $options);
            $body = (string)$response->getBody();
            if (strlen($body) > 4 * 1024 * 1024) {
                throw new PlatformException('平台响应过大，请稍后重试');
            }
            return ['status' => $response->getStatusCode(), 'body' => $body,
                'headers' => $response->getHeaders(), 'url' => $effectiveUrl];
        } catch (PlatformException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // Guzzle exception text may contain URLs, cookies or request bodies.
            throw new PlatformException('平台连接失败或超时，已安排稍后重试');
        }
    }

    private function validateUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || !in_array(strtolower((string)($parts['host'] ?? '')), $this->hosts, true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            throw new PlatformException('平台返回了不支持的跳转地址', false, 0);
        }
    }
}
