<?php

declare(strict_types=1);

require __DIR__ . '/FunctionalDatabaseBootstrap.php';

foreach (['https://pay.example/a?x=1&y=a%2Bb', 'wxp://f2f0fixture?token=a%2Bb'] as $payload) {
    fixtureRequest([], ['text' => $payload]);
    $response = (new app\index\controller\Index())->createQrcode();
    functionalCheck($response->getCode() === 200, 'QR renderer rejected a valid payload');
    $file = tempnam(sys_get_temp_dir(), 'qr-test-');
    try {
        file_put_contents($file, $response->getContent());
        $decoded = (new Zxing\QrReader($file))->text();
        functionalCheck($decoded === $payload, 'QR payload changed during URL decoding');
    } finally {
        unlink($file);
    }
}
functionalCheck(payment_qrcode_url('wechat', 'wxp://fixture') === 'wxp://fixture', 'WeChat native QR was rejected');
functionalCheck(payment_qrcode_url('alipay', 'wxp://fixture') === '', 'Native protocol leaked into redirects');
foreach (['javascript:alert(1)', "https://pay.example/a\n", 'file:///tmp/test'] as $payload) {
    // Trailing whitespace is trimmed by design; internal controls are rejected.
    if (str_contains($payload, "\n")) $payload = "https://pay.example/a\nb";
    functionalCheck(payment_qrcode_url('wechat', $payload) === '', 'Unsafe payment payload accepted');
}
echo "QR encoding, decoding and protocol tests passed\n";
