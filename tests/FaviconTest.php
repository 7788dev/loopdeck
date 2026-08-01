<?php

declare(strict_types=1);

function faviconCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$icoPath = $root . '/public/favicon.ico';
$ico = file_get_contents($icoPath);
faviconCheck(is_string($ico) && strlen($ico) > 1000, 'Favicon ICO is missing or empty');

$header = unpack('vreserved/vtype/vcount', substr($ico, 0, 6));
faviconCheck(($header['reserved'] ?? -1) === 0, 'Favicon ICO reserved field is invalid');
faviconCheck(($header['type'] ?? 0) === 1, 'Favicon file is not an ICO image');
faviconCheck(($header['count'] ?? 0) >= 7, 'Favicon ICO does not contain the expected responsive sizes');

$sizes = [];
for ($index = 0; $index < (int)$header['count']; $index++) {
    $offset = 6 + ($index * 16);
    $width = ord($ico[$offset]);
    $height = ord($ico[$offset + 1]);
    $sizes[] = [$width === 0 ? 256 : $width, $height === 0 ? 256 : $height];
}
foreach ([16, 24, 32, 48, 64, 128, 256] as $size) {
    faviconCheck(in_array([$size, $size], $sizes, true), "Favicon ICO is missing the {$size}x{$size} image");
}

$pngs = [
    'loopdeck-icon-512.png' => [512, 512],
    'favicon-192x192.png' => [192, 192],
    'apple-touch-icon-180x180.png' => [180, 180],
    'favicon-32x32.png' => [32, 32],
];
foreach ($pngs as $name => $expectedSize) {
    $path = $root . '/public/static/media/favicons/' . $name;
    $info = getimagesize($path);
    faviconCheck(
        is_array($info)
            && ($info[0] ?? 0) === $expectedSize[0]
            && ($info[1] ?? 0) === $expectedSize[1]
            && ($info['mime'] ?? '') === 'image/png',
        "{$name} has the wrong format or dimensions"
    );
}

$views = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/app', FilesystemIterator::SKIP_DOTS)
);
foreach ($views as $view) {
    if (!$view->isFile() || strtolower($view->getExtension()) !== 'html') {
        continue;
    }
    $source = file_get_contents($view->getPathname());
    if (!is_string($source) || !str_contains($source, 'favicon.ico')) {
        continue;
    }
    faviconCheck(
        str_contains($source, '/favicon.ico?v={:app_version()}'),
        $view->getPathname() . ' does not cache-bust the favicon'
    );
    faviconCheck(
        preg_match('/rel=["\']stylesheet["\'][^>]*favicon|favicon[^>]*rel=["\']stylesheet["\']/i', $source) !== 1,
        $view->getPathname() . ' incorrectly loads the favicon as a stylesheet'
    );
}

echo "Favicon asset tests passed\n";
