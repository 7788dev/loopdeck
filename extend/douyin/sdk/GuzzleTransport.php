<?php

declare(strict_types=1);

namespace douyin\sdk;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

final class GuzzleTransport implements TransportInterface
{
    private ClientInterface $client;

    public function __construct(?ClientInterface $client = null)
    {
        $this->client = $client ?? new GuzzleClient(['http_errors' => false]);
    }

    public function request(string $method, string $url, array $options = []): array
    {
        try {
            $response = $this->client->request(strtoupper($method), $url, $options);
            $headerText = '';
            foreach ($response->getHeaders() as $name => $values) {
                foreach ($values as $value) {
                    $headerText .= $name . ': ' . $value . "\r\n";
                }
            }

            return [
                'status' => $response->getStatusCode(),
                'headers' => $response->getHeaders(),
                'body' => (string)$response->getBody(),
                'header' => $headerText,
                'set_cookie' => $response->getHeader('Set-Cookie'),
            ];
        } catch (GuzzleException $exception) {
            return [
                'status' => 0,
                'headers' => [],
                'body' => json_encode([
                    'data' => ['error_code' => -1],
                    'message' => 'douyin sdk: ' . $exception->getMessage(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                'header' => '',
                'set_cookie' => [],
            ];
        }
    }
}
