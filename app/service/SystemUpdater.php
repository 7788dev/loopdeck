<?php

declare(strict_types=1);

namespace app\service;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use RuntimeException;
use Throwable;

final class SystemUpdater
{
    private const DEFAULT_VERSION_URL = 'https://raw.githubusercontent.com/7788dev/loopdeck/main/VERSION';
    private const DEFAULT_UPDATE_URL = 'http://updater:8080/v1/update';
    private const DEFAULT_IMAGE = 'ghcr.io/7788dev/loopdeck:latest';

    private ClientInterface $client;
    private string $versionUrl;
    private string $updateUrl;
    private string $updateToken;
    private string $image;

    public function __construct(?ClientInterface $client = null, array $config = [])
    {
        $this->client = $client ?? new Client();
        $this->versionUrl = trim((string)($config['version_url'] ?? getenv('UPDATE_VERSION_URL') ?: self::DEFAULT_VERSION_URL));
        $this->updateUrl = trim((string)($config['update_url'] ?? getenv('UPDATE_API_URL') ?: self::DEFAULT_UPDATE_URL));
        $this->updateToken = trim((string)($config['update_token'] ?? getenv('UPDATE_TOKEN') ?: ''));
        $this->image = trim((string)($config['image'] ?? getenv('UPDATE_IMAGE') ?: self::DEFAULT_IMAGE));
    }

    public function status(): array
    {
        $current = ApplicationVersion::current();
        $status = [
            'current_version' => $current,
            'latest_version' => null,
            'update_available' => false,
            'updater_available' => $this->updateUrl !== '' && $this->updateToken !== '' && $this->image !== '',
            'version_url' => $this->versionUrl,
            'error' => null,
        ];

        if ($this->versionUrl === '') {
            $status['error'] = '未配置 GitHub 版本文件地址';
            return $status;
        }

        try {
            $response = $this->client->request('GET', $this->versionUrl, [
                'connect_timeout' => 5.0,
                'timeout' => 10.0,
                'http_errors' => false,
                'headers' => [
                    'Accept' => 'text/plain',
                    'Cache-Control' => 'no-cache',
                    'User-Agent' => 'LoopDeck/' . $current,
                ],
            ]);
            if ($response->getStatusCode() !== 200) {
                throw new RuntimeException('GitHub 返回 HTTP ' . $response->getStatusCode());
            }

            $latest = ApplicationVersion::normalize((string)$response->getBody());
            if ($latest === null) {
                throw new RuntimeException('远程 VERSION 文件格式无效');
            }

            $status['latest_version'] = $latest;
            $status['update_available'] = version_compare($latest, $current, '>');
        } catch (Throwable $exception) {
            $status['error'] = $exception->getMessage();
        }

        return $status;
    }

    public function trigger(): array
    {
        if ($this->updateUrl === '' || $this->updateToken === '' || $this->image === '') {
            throw new RuntimeException('Docker 更新服务尚未配置');
        }

        $response = $this->client->request('POST', $this->updateUrl, [
            'connect_timeout' => 5.0,
            'timeout' => 20.0,
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $this->updateToken,
                'User-Agent' => 'LoopDeck/' . ApplicationVersion::current(),
            ],
            'query' => [
                'image' => $this->image,
                'async' => 'true',
            ],
        ]);

        $statusCode = $response->getStatusCode();
        $body = json_decode((string)$response->getBody(), true);
        if (!in_array($statusCode, [200, 202], true)) {
            $message = is_array($body) ? trim((string)($body['error'] ?? '')) : '';
            throw new RuntimeException($message !== '' ? $message : '更新服务返回 HTTP ' . $statusCode);
        }

        return [
            'status' => $statusCode,
            'image' => $this->image,
            'response' => is_array($body) ? $body : [],
        ];
    }
}
