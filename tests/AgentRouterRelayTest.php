<?php

declare(strict_types=1);

require __DIR__ . '/FunctionalDatabaseBootstrap.php';

use app\service\AgentRouterRelay;
use app\service\AgentRouterSubscription;

if (PHP_OS_FAMILY !== 'Linux' || !is_executable('/usr/local/bin/loopdeck-mihomo')) {
    echo "AgentRouter native relay lifecycle requires the Linux image; parser/configuration tests remain offline\n";
    exit(0);
}
$directory = sys_get_temp_dir() . '/loopdeck-relay-test-' . bin2hex(random_bytes(6));
$app->config->set(['agentrouter_relay_directory' => $directory], 'app');
$node = AgentRouterSubscription::normalizeNode(['name' => 'isolated fixture', 'type' => 'vmess',
    'server' => '203.0.113.1', 'port' => 1, 'uuid' => '11111111-2222-4333-8444-555555555555', 'cipher' => 'auto']);
$relay = null;
try {
    $relay = AgentRouterRelay::open($node, false);
    $parts = parse_url($relay->url);
    functionalCheck($parts['host'] === '127.0.0.1' && strlen($parts['pass']) >= 32, 'Relay was public or lacked random authentication');
    $files = glob($directory . '/ag-*/config.json');
    functionalCheck(count($files) === 1 && (fileperms($files[0]) & 0777) === 0600, 'Relay configuration was not private');
    $config = json_decode(file_get_contents($files[0]), true);
    functionalCheck($config['bind-address'] === '127.0.0.1' && $config['log-level'] === 'silent'
        && $config['external-controller'] === '' && $config['dns']['enable'] === false, 'Unsafe native relay settings');
    $socket = stream_socket_client('tcp://127.0.0.1:' . $parts['port'], $error, $message, 1);
    stream_set_timeout($socket, 2);
    fwrite($socket, "CONNECT fixture.example.invalid:443 HTTP/1.1\r\nHost: fixture.example.invalid\r\n\r\n");
    $reply = fgets($socket);
    fclose($socket);
    functionalCheck(is_string($reply) && str_contains($reply, '407'), 'Native loopback proxy allowed unauthenticated use');
    $relay->close();
    functionalCheck((glob($directory . '/ag-*') ?: []) === [], 'Relay files remained after closing');
    $closed = @stream_socket_client('tcp://127.0.0.1:' . $parts['port'], $error, $message, 0.2);
    functionalCheck($closed === false, 'Relay process kept listening after closing');
    $relay = AgentRouterRelay::open($node, false);
    $parts = parse_url($relay->url);
    unset($relay);
    $closed = @stream_socket_client('tcp://127.0.0.1:' . $parts['port'], $error, $message, 0.2);
    functionalCheck($closed === false && (glob($directory . '/ag-*') ?: []) === [],
        'Abandoning an unused relay leaked its process or files');
} finally {
    if (isset($relay)) $relay->close();
    @rmdir($directory);
}
echo "AgentRouter native relay authentication, process termination and private-file cleanup tests passed\n";
