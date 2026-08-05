<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

$douyinSdk = dirname(__DIR__) . '/extend/douyin/sdk/';
foreach ([
    'SignerInterface.php',
    'TransportInterface.php',
    'CookieSession.php',
    'PassportSigner.php',
    'NodeSigner.php',
    'GuzzleTransport.php',
    'Client.php',
] as $douyinSdkFile) {
    require_once $douyinSdk . $douyinSdkFile;
}
function douyinCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
