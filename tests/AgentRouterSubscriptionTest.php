<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use app\service\AgentRouterRelay;
use app\service\AgentRouterSubscription;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

function agSubscriptionCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$uuid = '11111111-2222-4333-8444-555555555555';
$yaml = <<<YAML
mixed-port: 1
external-controller: 0.0.0.0:9090
proxy-providers:
  unsafe:
    url: http://127.0.0.1/private
rules: [MATCH,DIRECT]
proxies:
  - name: 'fixture VMess'
    type: vmess
    server: relay.example.invalid
    port: 443
    uuid: $uuid
    tls: true
    network: ws
    servername: tls.example.invalid
    ws-opts:
      path: /relay
      headers: {Host: ws.example.invalid, X-Secret: discarded}
    dialer-proxy: unsafe
    ca: /etc/private-file
  - name: 'fixture Hysteria2'
    type: hysteria2
    server: 8.8.8.8
    port: 8443
    password: fixture-node-password
    sni: tls.example.invalid
    skip-cert-verify: true
  - name: unsupported
    type: DIRECT
YAML;
$nodes = AgentRouterSubscription::parse($yaml);
agSubscriptionCheck(count($nodes) === 2 && $nodes[0]['uuid'] === $uuid
    && $nodes[0]['ws-opts']['headers'] === ['Host' => 'ws.example.invalid']
    && !isset($nodes[0]['dialer-proxy'], $nodes[0]['ca']) && $nodes[1]['skip-cert-verify'] === false,
    'Clash subscription retained unsafe options or lost its supported nodes');
$config = AgentRouterRelay::configuration($nodes[0], 31234, 'fixture-local-user', 'fixture-local-secret');
agSubscriptionCheck($config['bind-address'] === '127.0.0.1' && !$config['allow-lan']
    && $config['authentication'] === ['fixture-local-user:fixture-local-secret']
    && $config['external-controller'] === '' && $config['dns']['enable'] === false
    && $config['rules'] === ['MATCH,AG_PRIVATE_NODE'] && count($config['proxies']) === 1
    && !isset($config['proxy-providers']) && $config['log-level'] === 'silent',
    'The engine inherited subscription listeners, routes, DNS, providers or logging');
agSubscriptionCheck($config['proxies'][0]['servername'] === 'tls.example.invalid'
    && $config['proxies'][0]['ws-opts']['headers']['Host'] === 'ws.example.invalid',
    'Sanitizing the node lost its TLS or WebSocket host');
agSubscriptionCheck(AgentRouterSubscription::nodeId($nodes[0]) === AgentRouterSubscription::nodeId(array_reverse($nodes[0], true))
    && AgentRouterSubscription::nodeId($nodes[0]) === AgentRouterSubscription::nodeId(AgentRouterSubscription::normalizeNode($nodes[0])),
    'Node identity depended on property order or normalization count');

$vmess = 'vmess://' . base64_encode(json_encode(['ps' => 'VMess URI', 'add' => '8.8.4.4', 'port' => '443',
    'id' => $uuid, 'aid' => '0', 'net' => 'ws', 'host' => 'ws.example.invalid', 'path' => '/relay', 'tls' => 'tls']));
$uris = implode("\n", [$vmess, 'hy2://fixture-password@8.8.8.8:8443?sni=tls.example.invalid#Hy2',
    'vless://' . $uuid . '@1.1.1.1:443?security=tls&type=ws&host=ws.example.invalid&path=%2Frelay#VLESS',
    'trojan://fixture-password@9.9.9.9:443?sni=tls.example.invalid#Trojan',
    'ss://' . base64_encode('aes-256-gcm:fixture-password') . '@8.8.8.8:8388#SS',
    'ss://' . base64_encode('aes-128-gcm:fixture-password@8.8.4.4:8388'),
    'socks5://fixture-user:fixture-password@1.0.0.1:1080']);
foreach ([$uris, base64_encode($uris)] as $text) {
    $parsed = AgentRouterSubscription::parse($text);
    agSubscriptionCheck(count($parsed) === 7 && $parsed[0]['network'] === 'ws'
        && $parsed[0]['ws-opts']['headers']['Host'] === 'ws.example.invalid'
        && $parsed[1]['password'] === 'fixture-password' && $parsed[2]['uuid'] === $uuid
        && $parsed[6]['url'] === 'socks5h://fixture-user:fixture-password@1.0.0.1:1080',
        'Plain/Base64 URI conversion failed or lost credentials/transport settings');
}
foreach (['', str_repeat('x', AgentRouterSubscription::MAX_BYTES + 1),
    '!!php/object:O:8:"stdClass":0:{}', 'proxies: [!unknown value]',
    'proxy-providers: {remote: {url: "http://127.0.0.1/private"}}',
    'proxies: [{type: vmess, server: 127.0.0.1, port: 80, uuid: "' . $uuid . '"}]',
    'proxies: [{type: trojan, server: 8.8.8.8, port: 80, password: "bad\\nheader"}]'] as $bad) {
    try {
        AgentRouterSubscription::parse($bad);
        throw new RuntimeException('Unsafe, empty or unsupported subscription was accepted');
    } catch (InvalidArgumentException $expected) {
    }
}
foreach (['file:///etc/passwd', 'https://user:password@example.invalid/sub', 'https://127.0.0.1/sub',
    'https://100.100.100.200/sub', 'https://example.invalid/sub#fragment', "https://example.invalid/\nsub"] as $bad) {
    try {
        AgentRouterSubscription::normalizeUrl($bad);
        throw new RuntimeException('Unsafe subscription destination was accepted');
    } catch (InvalidArgumentException $expected) {
    }
}
$history = [];
$stack = HandlerStack::create(new MockHandler([new Response(200, [], $yaml), new Response(429, [], 'denied')]));
$stack->push(Middleware::history($history));
$fetcher = new AgentRouterSubscription(new Client(['handler' => $stack]));
$url = 'https://8.8.8.8:5000/sub?token=fixture-subscription-secret';
agSubscriptionCheck(count($fetcher->fetch($url)) === 2, 'The subscription download was not parsed');
$options = $history[0]['options'];
agSubscriptionCheck($options['verify'] === true && $options['allow_redirects'] === false
    && $options['proxy'] === '' && $options['curl'][CURLOPT_RESOLVE] === ['8.8.8.8:5000:8.8.8.8']
    && !$history[0]['request']->hasHeader('Cookie') && !$history[0]['request']->hasHeader('Authorization'),
    'The download inherited credentials, redirected, bypassed TLS or failed to pin DNS');
try {
    $fetcher->fetch($url);
    throw new RuntimeException('Failed subscription download was accepted');
} catch (RuntimeException $expected) {
    agSubscriptionCheck(!str_contains($expected->getMessage(), 'fixture-subscription-secret'),
        'Download failure exposed the subscription token');
}

echo "AgentRouter subscription conversion, safe engine configuration and private download tests passed\n";
