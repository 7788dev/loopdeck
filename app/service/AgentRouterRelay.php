<?php

declare(strict_types=1);

namespace app\service;

use anyrouter\ProxyEndpoint;
use RuntimeException;

/** A fixed relay lease; subscription engines have private files, loopback auth and a hard lifetime. */
final class AgentRouterRelay
{
    private bool $closed = false;

    private function __construct(public readonly string $url, private $process = null, private string $directory = '')
    {
    }

    public static function open(array $node, bool $publicOnly = true): self
    {
        $node = AgentRouterSubscription::normalizeNode($node);
        if ($node['type'] === 'endpoint') {
            return new self(ProxyEndpoint::resolve($node['url'], $publicOnly));
        }
        $binary = '/usr/local/bin/loopdeck-mihomo';
        if (PHP_OS_FAMILY !== 'Linux' || !is_executable($binary) || !is_executable('/usr/bin/timeout')) {
            throw new RuntimeException('当前部署缺少订阅代理组件，请站长更新 Docker 镜像');
        }
        $host = $node['server'];
        $address = str_contains($host, ':') ? '[' . trim($host, '[]') . ']' : $host;
        $fixed = ProxyEndpoint::resolve('http://' . $address . ':' . $node['port'], $publicOnly);
        $node['server'] = trim(parse_url($fixed)['host'], '[]');
        // Preserve virtual-host/TLS identity after pinning the outbound socket to one IP.
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            $key = in_array($node['type'], ['trojan', 'hysteria2'], true) ? 'sni' : 'servername';
            if (empty($node[$key])) {
                $node[$key] = $host;
            }
            if (($node['network'] ?? '') === 'ws' && empty($node['ws-opts']['headers']['Host'])) {
                $node['ws-opts']['headers']['Host'] = $host;
            }
        }
        $root = (string)config('app.agentrouter_relay_directory', root_path() . 'runtime/agentrouter-relays');
        if (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) {
            throw new RuntimeException('无法创建私有代理目录');
        }
        self::prune($root);
        $directory = $root . '/ag-' . bin2hex(random_bytes(16));
        if (!@mkdir($directory, 0700)) {
            throw new RuntimeException('无法创建私有代理目录');
        }
        $relay = new self('', null, $directory);
        try {
            $socket = @stream_socket_server('tcp://127.0.0.1:0', $error, $message);
            if (!is_resource($socket)) {
                throw new RuntimeException('无法分配私有代理端口');
            }
            $port = (int)substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
            fclose($socket);
            $username = bin2hex(random_bytes(8));
            $password = bin2hex(random_bytes(24));
            $path = $directory . '/config.json';
            if (@file_put_contents($path, json_encode(self::configuration($node, $port, $username, $password),
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) === false || !@chmod($path, 0600)) {
                throw new RuntimeException('无法保存私有代理配置');
            }
            // BusyBox timeout execs the engine and kills it even if the PHP worker disappears.
            $process = proc_open(['/usr/bin/timeout', '-s', 'KILL', '120', $binary, '-d', $directory, '-f', $path],
                [['file', '/dev/null', 'r'], ['file', '/dev/null', 'a'], ['file', '/dev/null', 'a']], $pipes, $directory);
            if (!is_resource($process)) {
                throw new RuntimeException('无法启动私有代理');
            }
            $relay->process = $process;
            $deadline = microtime(true) + 3;
            do {
                $connection = @stream_socket_client('tcp://127.0.0.1:' . $port, $error, $message, 0.05);
                if (is_resource($connection)) {
                    fclose($connection);
                    // Transfer process ownership to the returned lease before destroying the provisional one.
                    $result = new self('http://' . $username . ':' . $password . '@127.0.0.1:' . $port, $process, $directory);
                    $relay->closed = true;
                    return $result;
                }
                if (!proc_get_status($process)['running']) {
                    break;
                }
                usleep(50000);
            } while (microtime(true) < $deadline);
            throw new RuntimeException('私有代理未能启动');
        } finally {
            $relay->close();
        }
    }

    /** Never pass through subscription listeners, providers, controller, rules, paths or scripts. */
    public static function configuration(array $node, int $port, string $username, string $password): array
    {
        $node = AgentRouterSubscription::normalizeNode($node);
        $node['name'] = 'AG_PRIVATE_NODE';
        return ['mixed-port' => $port, 'bind-address' => '127.0.0.1', 'allow-lan' => false,
            'authentication' => [$username . ':' . $password], 'mode' => 'rule', 'log-level' => 'silent',
            'ipv6' => false, 'external-controller' => '', 'geo-auto-update' => false, 'find-process-mode' => 'off',
            'dns' => ['enable' => false], 'profile' => ['store-selected' => false, 'store-fake-ip' => false],
            'proxies' => [$node], 'rules' => ['MATCH,AG_PRIVATE_NODE']];
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        if (is_resource($this->process)) {
            if (proc_get_status($this->process)['running']) {
                @proc_terminate($this->process);
                $deadline = microtime(true) + 0.5;
                while (proc_get_status($this->process)['running'] && microtime(true) < $deadline) {
                    usleep(10000);
                }
                if (proc_get_status($this->process)['running']) {
                    @proc_terminate($this->process, 9);
                }
            }
            @proc_close($this->process);
        }
        if ($this->directory !== '') {
            self::cleanDirectory($this->directory);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    private static function prune(string $root): void
    {
        foreach (array_slice(glob($root . '/ag-*', GLOB_ONLYDIR) ?: [], 0, 16) as $directory) {
            if (!is_link($directory) && (int)filemtime($directory) < time() - 180) {
                self::cleanDirectory($directory);
            }
        }
    }

    private static function cleanDirectory(string $directory): void
    {
        if (preg_match('/\Aag-[0-9a-f]{32}\z/', basename($directory)) !== 1 || is_link($directory)) {
            return;
        }
        // Engine output has no configured subdirectories. Do not recursively follow anything.
        foreach (array_slice(scandir($directory) ?: [], 0, 32) as $file) {
            if ($file !== '.' && $file !== '..' && (is_file($directory . '/' . $file) || is_link($directory . '/' . $file))) {
                @unlink($directory . '/' . $file);
            }
        }
        @rmdir($directory);
    }
}
