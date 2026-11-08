<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use netease\sdk\Crypto;

// Frozen output from the actual upstream crypto.js at the commit in the fixture.
// Round-trip tests alone cannot detect a request shape shared by both local layers.
$fixture = json_decode(
    file_get_contents(__DIR__ . '/fixtures/netease-xeapi-csr.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
foreach ($fixture['cases'] as $case) {
    $options = $case['options'];
    $entropy = $fixture['entropy'];
    $queue = [];
    if (empty($options['sessionKey'])) {
        $queue[] = hex2bin($entropy['dynamic_key']);
    }
    $queue[] = hex2bin($entropy['mask']);
    $queue[] = hex2bin($entropy['ephemeral_private']);
    $queue[] = hex2bin($entropy['iv']);
    $crypto = new Crypto(static function (int $length) use (&$queue): string {
        $bytes = array_shift($queue);
        if (!is_string($bytes) || strlen($bytes) !== $length) {
            throw new RuntimeException('Unexpected XEAPI entropy request');
        }
        return $bytes;
    });
    $actual = $crypto->xeapi($case['uri'], $case['data'], $fixture['state'], [
        'protocol' => 'csr',
        'os' => 'android',
        'method' => $options['method'] ?? 'POST',
        'content_type' => $options['contentType'] ?? 'application/x-www-form-urlencoded;charset=utf-8',
    ], $options['sessionId'] ?? '', $options['sessionKey'] ?? '');
    foreach (['C', 'S', 'R'] as $field) {
        if ($actual[$field] !== $case['expected'][$field]) {
            throw new RuntimeException('Upstream CSR vector mismatch: ' . $case['name'] . ' field ' . $field);
        }
    }
    if ($queue !== []) {
        throw new RuntimeException('CSR vector left unused entropy: ' . $case['name']);
    }
}

// Preserve the explicitly supported BSR envelope while aligning CSR.
$legacy = json_decode((new Crypto())->buildXeapiPlaintext('/api/test', [], ['protocol' => 'bsr']), true);
if (!array_key_exists('body', $legacy) || $legacy['body'] !== '') {
    throw new RuntimeException('Legacy BSR empty-body shape changed');
}

echo "NetEase CSR upstream protocol vectors passed\n";
