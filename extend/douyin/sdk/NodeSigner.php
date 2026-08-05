<?php

declare(strict_types=1);

namespace douyin\sdk;

use JsonException;
use RuntimeException;

final class NodeSigner implements SignerInterface
{
    private string $nodeBinary;
    private string $workerPath;
    private float $timeout;

    public function __construct(
        string $nodeBinary = 'node',
        ?string $workerPath = null,
        float $timeout = 8.0
    ) {
        $this->nodeBinary = $nodeBinary;
        $this->workerPath = $workerPath ?? dirname(__DIR__) . '/runtime/worker.cjs';
        $this->timeout = max(1.0, $timeout);
    }

    public function sign(string $url, string $method = 'GET', string $body = ''): string
    {
        $result = $this->runWorker([
            'url' => $url,
            'method' => strtoupper($method),
            'body' => $body,
            'include_dtrait' => false,
        ]);
        $signedUrl = (string)($result['url'] ?? '');
        $this->assertSignedUrl($signedUrl);
        return $signedUrl;
    }

    /** @return array{url:string,headers:array<string,string>} */
    public function signRequest(string $url, string $method, string $body, string $dtraitKey): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/iD', $dtraitKey)) {
            throw new RuntimeException('Douyin DTrait key must contain exactly 32 hexadecimal characters');
        }
        $result = $this->runWorker([
            'url' => $url,
            'method' => strtoupper($method),
            'body' => $body,
            'include_dtrait' => true,
            'dtrait_key' => strtolower($dtraitKey),
        ]);
        $signedUrl = (string)($result['url'] ?? '');
        $this->assertSignedUrl($signedUrl);
        $headers = is_array($result['headers'] ?? null) ? $result['headers'] : [];
        $dtrait = trim((string)($headers['X-TT-Session-Dtrait'] ?? ''));
        $this->assertDtraitHeader($dtrait);
        return [
            'url' => $signedUrl,
            'headers' => ['X-TT-Session-Dtrait' => $dtrait],
        ];
    }

    /** @param array<string,mixed> $payload
     *  @return array<string,mixed>
     */
    private function runWorker(array $payload): array
    {
        if (!is_file($this->workerPath)) {
            throw new RuntimeException('Douyin signer worker is missing: ' . $this->workerPath);
        }
        if (!function_exists('proc_open')) {
            throw new RuntimeException('Douyin signer requires the PHP proc_open function');
        }

        try {
            $input = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Douyin signer input is not valid JSON', 0, $exception);
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open(
            [$this->nodeBinary, $this->workerPath],
            $descriptors,
            $pipes,
            dirname($this->workerPath),
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the Douyin Node signer');
        }

        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $exitCode = -1;
        $deadline = microtime(true) + $this->timeout;
        while (true) {
            $stdout .= (string)stream_get_contents($pipes[1]);
            $stderr .= (string)stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = (int)$status['exitcode'];
                break;
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($process);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                throw new RuntimeException('Douyin signer timed out');
            }
            if (strlen($stdout) + strlen($stderr) > 1048576) {
                proc_terminate($process);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                throw new RuntimeException('Douyin signer exceeded its output limit');
            }
            usleep(10000);
        }

        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closeCode = proc_close($process);
        if ($exitCode < 0) {
            $exitCode = $closeCode;
        }
        if ($exitCode !== 0) {
            throw new RuntimeException('Douyin signer failed: ' . trim($stderr ?: $stdout));
        }

        try {
            $result = json_decode(trim($stdout), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Douyin signer returned invalid JSON', 0, $exception);
        }
        return is_array($result) ? $result : [];
    }

    private function assertSignedUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') !== 'login.douyin.com'
            || !str_starts_with((string)($parts['path'] ?? ''), '/passport/')
        ) {
            throw new RuntimeException('Douyin signer returned an invalid target URL');
        }
        parse_str((string)($parts['query'] ?? ''), $query);
        if (!is_string($query['a_bogus'] ?? null) || $query['a_bogus'] === '') {
            throw new RuntimeException('Douyin signer result is missing a_bogus');
        }
    }

    private function assertDtraitHeader(string $value): void
    {
        $parts = explode('_', $value);
        if (count($parts) !== 3
            || $parts[0] !== 'd0'
            || strlen($value) < 700
            || strlen($value) > 1200
            || !preg_match('/^[A-Za-z0-9+\/]+={0,2}$/D', $parts[1])
            || !preg_match('/^[A-Za-z0-9+\/]+={0,2}$/D', $parts[2])
        ) {
            throw new RuntimeException('Douyin signer returned an invalid DTrait header');
        }
        $wrappedKey = base64_decode($parts[1], true);
        $encryptedPayload = base64_decode($parts[2], true);
        if (!is_string($wrappedKey)
            || strlen($wrappedKey) !== 256
            || !is_string($encryptedPayload)
            || strlen($encryptedPayload) < 256
        ) {
            throw new RuntimeException('Douyin signer returned malformed DTrait data');
        }
    }
}
