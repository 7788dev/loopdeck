<?php

declare(strict_types=1);

namespace app\service;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Throwable;

final class BarkClient
{
    public const ENDPOINT = 'https://api.day.app/push';
    public const UPSTREAM_COMMIT = 'f1fcda0b19ad21aecc5674210e723d21810bb801';

    private ClientInterface $client;

    public function __construct(?ClientInterface $client = null)
    {
        $this->client = $client ?? new Client();
    }

    public static function normalizeToken(string $token): ?string
    {
        $token = trim($token);
        if ($token === '') {
            return '';
        }

        return preg_match('/\A[A-Za-z0-9_-]{6,255}\z/', $token) === 1 ? $token : null;
    }

    /**
     * @return array{success:bool,status:int,message:string,response:array<string,mixed>}
     */
    public function send(
        string $token,
        string $title,
        string $body,
        string $group = 'LoopDeck',
        string $url = ''
    ): array {
        $token = self::normalizeToken($token);
        if ($token === null || $token === '') {
            return $this->result(false, 0, 'Bark Token 格式无效');
        }

        $payload = [
            'device_key' => $token,
            'title' => $this->boundedText($title, 200),
            'body' => $this->boundedText($body, 4000),
            'group' => $this->boundedText($group, 100),
        ];
        if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
            $payload['url'] = $url;
        }

        try {
            $response = $this->client->request('POST', self::ENDPOINT, [
                'connect_timeout' => 5.0,
                'timeout' => 10.0,
                'http_errors' => false,
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'LoopDeck/' . ApplicationVersion::current(),
                ],
                'json' => $payload,
            ]);
        } catch (Throwable $exception) {
            return $this->result(false, 0, 'Bark 官方服务器连接失败');
        }

        $status = $response->getStatusCode();
        $decoded = json_decode((string)$response->getBody(), true);
        $data = is_array($decoded) ? $decoded : [];
        $code = array_key_exists('code', $data) ? (int)$data['code'] : null;
        $success = $status >= 200 && $status < 300 && ($code === null || in_array($code, [0, 200], true));
        $message = trim((string)($data['message'] ?? $data['msg'] ?? ''));

        return $this->result(
            $success,
            $status,
            $success ? 'Bark 推送成功' : ($message !== '' ? $message : 'Bark 推送失败'),
            $data
        );
    }

    private function boundedText(string $value, int $length): string
    {
        $value = trim($value);
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length, 'UTF-8');
        }
        return substr($value, 0, $length);
    }

    /**
     * @param array<string,mixed> $response
     * @return array{success:bool,status:int,message:string,response:array<string,mixed>}
     */
    private function result(bool $success, int $status, string $message, array $response = []): array
    {
        return compact('success', 'status', 'message', 'response');
    }
}
