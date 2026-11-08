<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use netease\sdk\Client;
use netease\sdk\Crypto;
use netease\sdk\TransportInterface;

final class XeapiValidationTransport implements TransportInterface
{
    public array $urls = [];

    public function __construct(private array $csrState, private array $validState, private bool $failCsr = false)
    {
    }

    public function request(string $method, string $url, array $options = []): array
    {
        $this->urls[] = $url;
        $csr = str_ends_with($url, '/api/bsr/sk/get');
        if (!$csr && !str_ends_with($url, '/api/gorilla/anti/crawler/security/key/get')) {
            throw new RuntimeException('Key validation sent a business request');
        }
        if ($csr && $this->failCsr) {
            return ['status' => 503, 'body' => '{"code":503}'];
        }
        $state = $csr ? $this->csrState : $this->validState;
        $keyHex = (new ReflectionClass(Crypto::class))->getConstant('XEAPI_STATIC_KEY_HEX');
        $encrypted = openssl_encrypt(
            json_encode($state, JSON_THROW_ON_ERROR),
            'aes-256-ecb',
            hex2bin($keyHex),
            OPENSSL_RAW_DATA
        );
        $form = $options['form_params'];
        return ['status' => 200, 'body' => json_encode([
            'code' => 200,
            'data' => [
                'encryptedData' => base64_encode($encrypted),
                'timestamp' => $form['timestamp'],
                'signature' => (new Crypto())->xeapiSign($form['timestamp'], $form['nonce']),
            ],
        ], JSON_THROW_ON_ERROR)];
    }
}

function resetValidationKeys(): void
{
    (new ReflectionProperty(Client::class, 'sharedXeapiPublicKeys'))->setValue(null, []);
}

function validationClient(XeapiValidationTransport $transport, array $config = []): Client
{
    return new Client([], array_replace([
        'auto_anonymous_token' => false,
        'cache_dir' => '',
        'xeapi_protocol' => 'csr',
    ], $config), $transport);
}

function validationForm(Client $client): array
{
    $prepared = $client->prepare('/api/resource/comments/add', ['content' => 'fixture'], 'xeapi', [
        'skip_anonymous' => true,
    ]);
    parse_str($prepared['options']['body'], $form);
    return $form;
}

$fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/netease-xeapi-csr.json'), true, 512, JSON_THROW_ON_ERROR);
$valid = $fixture['state'];
$invalid = [
    'invalid-base64' => array_replace($valid, ['publicKey' => '**invalid**']),
    'short-key' => array_replace($valid, ['publicKey' => base64_encode(str_repeat("\0", 16))]),
    'low-order-key' => array_replace($valid, ['publicKey' => base64_encode(str_repeat("\0", 32))]),
    'public-key-type' => array_replace($valid, ['publicKey' => ['invalid']]),
    'version-type' => array_replace($valid, ['version' => true]),
    'sk-type' => array_replace($valid, ['sk' => ['invalid']]),
];
$filter = $argv[1] ?? '';
foreach ($invalid as $name => $state) {
    if ($filter !== '' && $name !== $filter) {
        continue;
    }
    resetValidationKeys();
    $transport = new XeapiValidationTransport($state, $valid);
    try {
        $form = validationForm(validationClient($transport));
    } catch (Throwable $exception) {
        throw new RuntimeException('Malformed CSR key did not fall back before encryption: ' . $name, 0, $exception);
    }
    if (!isset($form['B']) || isset($form['C']) || count($transport->urls) !== 2) {
        throw new RuntimeException('Malformed CSR key did not use the BSR handshake: ' . $name);
    }
}

resetValidationKeys();
$strictTransport = new XeapiValidationTransport($invalid['low-order-key'], $valid);
$strictRejected = false;
try {
    validationForm(validationClient($strictTransport, ['xeapi_fallback' => false]));
} catch (RuntimeException $exception) {
    $strictRejected = str_contains($exception->getMessage(), 'public key request failed');
}
if (!$strictRejected || count($strictTransport->urls) !== 1) {
    throw new RuntimeException('Strict CSR mode did not reject the malformed handshake');
}

resetValidationKeys();
$numericVersionTransport = new XeapiValidationTransport(array_replace($valid, ['version' => 123]), $valid);
$form = validationForm(validationClient($numericVersionTransport));
if (!isset($form['C']) || count($numericVersionTransport->urls) !== 1) {
    throw new RuntimeException('Numeric upstream key version was rejected');
}

$cacheDir = sys_get_temp_dir() . '/loopdeck-xeapi-validation-' . getmypid();
mkdir($cacheDir, 0700);
$cacheFile = $cacheDir . '/xeapi_public_key_csr.json';
try {
    resetValidationKeys();
    file_put_contents($cacheFile, json_encode($invalid['low-order-key'], JSON_THROW_ON_ERROR));
    $cacheTransport = new XeapiValidationTransport($valid, $valid, true);
    $form = validationForm(validationClient($cacheTransport, ['cache_dir' => $cacheDir]));
    if (!isset($form['B']) || count($cacheTransport->urls) !== 2) {
        throw new RuntimeException('Malformed cached CSR key blocked the BSR fallback');
    }

    resetValidationKeys();
    file_put_contents($cacheFile, json_encode($valid, JSON_THROW_ON_ERROR));
    $validCacheTransport = new XeapiValidationTransport($valid, $valid, true);
    $form = validationForm(validationClient($validCacheTransport, ['cache_dir' => $cacheDir]));
    if (!isset($form['C']) || count($validCacheTransport->urls) !== 1) {
        throw new RuntimeException('Valid cached CSR key was discarded during an outage');
    }
} finally {
    foreach (['xeapi_public_key_csr.json', 'xeapi_public_key.json', 'device_id.txt'] as $file) {
        if (is_file($cacheDir . '/' . $file)) {
            unlink($cacheDir . '/' . $file);
        }
    }
    rmdir($cacheDir);
    resetValidationKeys();
}

echo "NetEase XEAPI key validation and handshake fallback passed\n";
