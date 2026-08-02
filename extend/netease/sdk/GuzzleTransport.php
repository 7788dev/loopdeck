<?php

namespace netease\sdk;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Pool;
use Psr\Http\Message\ResponseInterface;
use Throwable;

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
            return $this->responseArray($response);
        } catch (GuzzleException $exception) {
            return $this->errorArray($exception);
        }
    }

    /**
     * Send independent prepared requests concurrently while preserving their keys.
     *
     * @param array<int|string,array{method:string,url:string,options?:array}> $requests
     * @return array<int|string,array{status:int,headers:array,body:string,header:string,set_cookie:array}>
     */
    public function requestMany(array $requests, int $concurrency = 8): array
    {
        if ($requests === []) {
            return [];
        }

        $results = [];
        $generator = function () use ($requests): iterable {
            foreach ($requests as $key => $request) {
                yield $key => function () use ($request) {
                    return $this->client->requestAsync(
                        strtoupper((string)($request['method'] ?? 'POST')),
                        (string)($request['url'] ?? ''),
                        is_array($request['options'] ?? null) ? $request['options'] : []
                    );
                };
            }
        };

        $pool = new Pool($this->client, $generator(), [
            'concurrency' => max(1, min(16, $concurrency)),
            'fulfilled' => function (ResponseInterface $response, $key) use (&$results): void {
                $results[$key] = $this->responseArray($response);
            },
            'rejected' => function ($reason, $key) use (&$results): void {
                $results[$key] = $this->errorArray($reason);
            },
        ]);
        $pool->promise()->wait();

        $ordered = [];
        foreach ($requests as $key => $_request) {
            $ordered[$key] = $results[$key] ?? $this->errorArray('Request did not complete');
        }
        return $ordered;
    }

    private function responseArray(ResponseInterface $response): array
    {
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
    }

    private function errorArray($reason): array
    {
        $message = $reason instanceof Throwable ? $reason->getMessage() : (string)$reason;
        return [
            'status' => 0,
            'headers' => [],
            'body' => json_encode([
                'code' => -1,
                'message' => $message,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            'header' => '',
            'set_cookie' => [],
        ];
    }
}
