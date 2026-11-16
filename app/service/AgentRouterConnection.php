<?php

declare(strict_types=1);

namespace app\service;

use agentrouter\Client;
use anyrouter\ProxyEndpoint;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use RuntimeException;

/** Operator configuration only; no account data is sent until the anonymous probe passes. */
final class AgentRouterConnection
{
    public function __construct(private readonly ?ClientInterface $http = null)
    {
    }

    public function connect(array $account): Client
    {
        $proxy = trim((string)(getenv('AGENTROUTER_PROXY') ?: ''));
        $origin = trim((string)(getenv('AGENTROUTER_ORIGIN') ?: '')) ?: Client::ORIGIN;
        try {
            // Resolve once so all requests use the same relay even if its DNS changes.
            $client = new Client($this->http, $proxy === '' ? '' : ProxyEndpoint::resolve($proxy, false), $origin);
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException('AgentRouter 连接配置无效，请管理员检查代理和域名');
        } catch (RuntimeException $exception) {
            throw new RuntimeException('AgentRouter 代理暂时无法解析，请稍后重试');
        }
        if (!$client->probeAsync(microtime(true) + 12)->wait()) {
            throw new RuntimeException('暂时无法访问 AgentRouter，请稍后重试');
        }
        return $client;
    }
}
