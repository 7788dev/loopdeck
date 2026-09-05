<?php

declare(strict_types=1);

// Explicit TCP/STARTTLS benchmark. Requires a local fixture with a trusted
// test certificate; the endpoint is fixed to loopback and never sends mail out.
$root = realpath(getenv('LOOPDECK_BENCHMARK_ROOT') ?: dirname(__DIR__));
$certificate = realpath(getenv('LOOPDECK_BENCHMARK_SMTP_CA') ?: '');
$port = (int)getenv('LOOPDECK_BENCHMARK_SMTP_PORT');
if ($root === false || $certificate === false || !is_file($certificate) || $port < 1024 || $port > 65535) {
    throw new RuntimeException('Set the benchmark checkout, local fixture port and CA file');
}
require $root . '/vendor/autoload.php';

use app\service\NotificationTransport;

$iterations = max(1, min(5000, (int)($argv[1] ?? 200)));
$start = hrtime(true);
$transport = new NotificationTransport(null, static function ($mail) use ($certificate): bool {
    $mail->SMTPOptions = ['ssl' => [
        'cafile' => $certificate, 'verify_peer' => true,
        'verify_peer_name' => true, 'peer_name' => 'localhost',
    ]];
    return $mail->send();
});
$site = ['name' => 'LoopDeck loopback fixture', 'email_available' => true, 'smtp' => [
    'mail_enabled' => 1, 'mail_smtp' => '127.0.0.1', 'mail_port' => $port,
    'mail_name' => 'sender@example.com', 'mail_pwd' => 'loopback-fixture-only',
]];
$warmMemory = null;
$lastMemory = null;
for ($index = 0; $index < $iterations; $index++) {
    $result = $transport->send('email', ['email_address' => 'recipient' . $index . '@example.com'],
        $site, 'LoopDeck loopback ' . $index, 'LoopDeck loopback body ' . $index);
    if (!$result['success']) {
        throw new RuntimeException('The local SMTP fixture rejected a message');
    }
    if ($index === 20) {
        $warmMemory = memory_get_usage();
    }
    $lastMemory = memory_get_usage();
}
unset($transport);
echo json_encode([
    'php' => PHP_VERSION, 'messages' => $iterations,
    'wall_ms' => round((hrtime(true) - $start) / 1000000, 3),
    'peak_php_bytes' => memory_get_peak_usage(true),
    'heap_growth_after_warmup_bytes' => $warmMemory === null ? null : $lastMemory - $warmMemory,
    'retained_php_bytes' => memory_get_usage(),
    'tls_peer_verification' => true,
], JSON_THROW_ON_ERROR), PHP_EOL;
