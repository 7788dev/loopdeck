<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

function loginQrCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$classMap = require $root . '/vendor/composer/autoload_classmap.php';
loginQrCheck(
    isset($classMap['netease\\QRcode']) && !isset($classMap['netease\\Qrcode']),
    'QR encoder class-map casing changed unexpectedly'
);

foreach (['Netease.php', 'Bilibili.php'] as $controllerName) {
    $source = file_get_contents($root . '/app/index/controller/' . $controllerName);
    loginQrCheck(
        is_string($source) && str_contains($source, 'use netease\\QRcode;'),
        $controllerName . ' does not import the Linux-compatible QR encoder class name'
    );
    loginQrCheck(
        !str_contains((string)$source, 'use netease\\Qrcode;'),
        $controllerName . ' still imports the case-mismatched QR encoder class name'
    );
}

loginQrCheck(class_exists(\netease\QRcode::class), 'QR encoder cannot be autoloaded');

$loginUrls = [
    'netease' => 'https://music.163.com/login?codekey=01234567-89ab-cdef-0123-456789abcdef',
    'bilibili' => 'https://account.bilibili.com/h5/account-h5/auth/scan-web?navhide=1&qrcode_key=0123456789abcdef0123456789abcdef',
];

foreach ($loginUrls as $provider => $url) {
    ob_start();
    try {
        \netease\QRcode::png($url, false, QR_ECLEVEL_L, 8, 4);
        $image = (string)ob_get_contents();
    } finally {
        ob_end_clean();
    }

    $info = getimagesizefromstring($image);
    loginQrCheck(str_starts_with($image, "\x89PNG\r\n\x1a\n"), $provider . ' QR output is not a PNG');
    loginQrCheck(
        is_array($info)
            && ($info['mime'] ?? '') === 'image/png'
            && ($info[0] ?? 0) >= 145
            && ($info[1] ?? 0) >= 145,
        $provider . ' QR output has invalid image dimensions'
    );
}

$neteaseController = file_get_contents($root . '/app/index/controller/Netease.php');
loginQrCheck(
    is_string($neteaseController)
        && str_contains($neteaseController, 'catch (\\Throwable $exception)')
        && str_contains($neteaseController, "return resultJson(0, '二维码生成失败，请稍后重试');"),
    'NetEase QR endpoint can still turn renderer failures into HTTP 500 responses'
);

echo "Login QR code tests passed\n";
