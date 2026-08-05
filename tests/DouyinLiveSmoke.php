<?php

declare(strict_types=1);

require __DIR__ . '/DouyinTestBootstrap.php';

use douyin\sdk\Client;

$generator = new Client();
$generated = $generator->qrGenerate();
$generatedData = is_array($generated['data'] ?? null) ? $generated['data'] : [];
$qrcode = base64_decode((string)($generatedData['qrcode'] ?? ''), true);

if ((int)($generatedData['error_code'] ?? -1) !== 0
    || trim((string)($generatedData['token'] ?? '')) === ''
    || !is_string($qrcode)
    || !str_starts_with($qrcode, "\x89PNG\r\n\x1a\n")
) {
    fwrite(STDERR, json_encode([
        'stage' => 'generate',
        'message' => (string)($generated['message'] ?? ''),
        'error_code' => $generatedData['error_code'] ?? null,
        'description' => (string)($generatedData['description'] ?? ''),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(1);
}

$state = $generator->state();
$poller = new Client(config: ['state' => $state]);
$polled = $poller->qrPoll();
$polledData = is_array($polled['data'] ?? null) ? $polled['data'] : [];
$publicState = $poller->publicState();

$summary = [
    'generate' => [
        'error_code' => (int)$generatedData['error_code'],
        'qrcode_bytes' => strlen($qrcode),
        'is_frontier' => (bool)($generatedData['is_frontier'] ?? false),
        'expire_time' => (int)($generatedData['expire_time'] ?? 0),
    ],
    'restored_state' => [
        'version' => (int)($state['version'] ?? 0),
        'cookie_count' => count(is_array($state['cookies'] ?? null) ? $state['cookies'] : []),
        'dtrait_key_valid' => (bool)preg_match('/^[a-f0-9]{32}$/D', (string)($state['dtrait_key'] ?? '')),
        'portrait_valid' => (bool)preg_match(
            '/^[a-f0-9-]{36}\.login$/D',
            (string)($state['verify_portrait'] ?? '')
        ),
    ],
    'poll' => [
        'message' => (string)($polled['message'] ?? ''),
        'error_code' => $polledData['error_code'] ?? null,
        'status' => (string)($publicState['status'] ?? ''),
        'verification_required' => (bool)($publicState['verification']['required'] ?? false),
    ],
];

if ((int)($polledData['error_code'] ?? -1) !== 0
    || !in_array($summary['poll']['status'], ['new', 'scanned'], true)
    || !$summary['restored_state']['dtrait_key_valid']
    || !$summary['restored_state']['portrait_valid']
) {
    fwrite(STDERR, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(1);
}

echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
