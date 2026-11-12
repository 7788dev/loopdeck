<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/common.php';

function webscanHttpCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$temporary = sys_get_temp_dir() . '/loopdeck-webscan-http-' . bin2hex(random_bytes(6));
mkdir($temporary, 0700, true);
$socket = stream_socket_server('tcp://127.0.0.1:0', $socketError, $socketMessage);
webscanHttpCheck(is_resource($socket), 'Cannot reserve a disposable HTTP port: ' . $socketMessage);
$address = (string)stream_socket_get_name($socket, false);
fclose($socket);
$base = 'http://' . $address;
$phpCommand = array_merge([PHP_BINARY], PHP_OS_FAMILY === 'Windows' ? ['-d', 'extension=pdo_sqlite'] : [],
    ['-d', 'post_max_size=20M', '-d', 'upload_max_filesize=20M', '-S', $address, $root . '/tests/fixtures/webscan-router.php']);
$process = proc_open($phpCommand,
    [0 => ['pipe', 'r'], 1 => ['file', $temporary . '/server.log', 'a'], 2 => ['file', $temporary . '/server.log', 'a']],
    $pipes, $temporary, array_replace(getenv(), ['LOOPDECK_WEBSCAN_TEST_DIRECTORY' => $temporary]));
webscanHttpCheck(is_resource($process), 'Cannot start the disposable HTTP fixture');
fclose($pipes[0]);
$payload = "1' or sleep(3)-- ";
$boundary = 'loopdeck-webscan-boundary';
$multipart = static function (array $fields, array $files = []) use ($boundary): string {
    $body = '';
    foreach ($fields as $name => $value) {
        $body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . $name . "\"\r\n\r\n"
            . $value . "\r\n";
    }
    foreach ($files as [$field, $name, $content]) {
        $body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . $field . '"; filename="'
            . $name . "\"\r\nContent-Type: application/octet-stream\r\n\r\n" . $content . "\r\n";
    }
    return $body . '--' . $boundary . "--\r\n";
};
$request = static function (string $path, ?string $body = null, string $contentType = 'application/x-www-form-urlencoded',
    array $extraHeaders = []) use ($base): array {
    $curl = curl_init($base . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: ' . $contentType, 'X-Requested-With: XMLHttpRequest',
            'Origin: ' . $base, 'Expect:'], $extraHeaders)]);
    if ($body !== null) {
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body]);
    }
    $response = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    webscanHttpCheck(is_string($response), 'HTTP fixture request failed: ' . $error);
    return ['status' => $status, 'body' => $response, 'json' => json_decode($response, true),
        'blocked' => str_contains($response, '输入内容存在危险字符')];
};
$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    if (!$condition) {
        $failures[] = $message;
    }
};

try {
    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $connection = @stream_socket_client('tcp://' . $address, $errorCode, $errorMessage, 0.1);
        if (is_resource($connection)) {
            fclose($connection);
            $ready = true;
            break;
        }
        if (!proc_get_status($process)['running']) {
            break;
        }
        usleep(50000);
    }
    webscanHttpCheck($ready, 'HTTP fixture did not start: ' . file_get_contents($temporary . '/server.log'));

    foreach ([
        'ordinary GET' => ['/probe?value=' . rawurlencode($payload), null, 'application/x-www-form-urlencoded'],
        'ordinary urlencoded' => ['/probe', http_build_query(['value' => $payload]), 'application/x-www-form-urlencoded'],
        'ordinary multipart field' => ['/probe', $multipart(['value' => $payload]), 'multipart/form-data; boundary=' . $boundary],
        'JSON body' => ['/probe', json_encode(['value' => $payload]), 'application/json'],
        'JSON escaped nested value' => ['/probe', '{"items":[{"value":"1\u0027 or sl\u0065ep(3)-- "}]}', 'application/json'],
        'JSON escaped nested key' => ['/probe', '{"items":{"sl\u0065ep(3)":"safe"}}', 'application/problem+json'],
        'JSON overwritten string' => ['/probe', '{"value":"1\u0027 or sl\u0065ep(3)-- ","value":"safe"}', 'application/json'],
        'nested JSON overwritten string' => ['/probe', '{"items":[{"value":"sl\u0065ep(3)","value":"safe"}]}', 'application/json'],
        'JSON nested credential name' => ['/index/LoginAjax/reg', json_encode(['items' => ['password' => $payload]]), 'application/json'],
        'credential field on unrelated route' => ['/probe', http_build_query(['password' => $payload]), 'application/x-www-form-urlencoded'],
        'large urlencoded body' => ['/probe', http_build_query(['value' => $payload, 'pad' => str_repeat('x', 20000)]), 'application/x-www-form-urlencoded'],
        'GET duplicate last value' => ['/probe?value=safe&value=' . rawurlencode($payload), null, 'application/x-www-form-urlencoded'],
        'GET duplicate first value' => ['/probe?value=' . rawurlencode($payload) . '&value=safe', null, 'application/x-www-form-urlencoded'],
        'POST duplicate last value' => ['/probe', 'value=safe&value=' . rawurlencode($payload), 'application/x-www-form-urlencoded'],
        'POST duplicate first value' => ['/probe', 'value=' . rawurlencode($payload) . '&value=safe', 'application/x-www-form-urlencoded'],
        'multipart PHP upload' => ['/probe', $multipart([], [['file', 'sample.txt', '<?php echo 1; ?>' . $payload]]), 'multipart/form-data; boundary=' . $boundary],
        'multipart PHP short echo upload' => ['/probe', $multipart([], [['file', 'sample.txt', '<?= 1; ?>']]), 'multipart/form-data; boundary=' . $boundary],
        'multipart text upload' => ['/probe', $multipart([], [['file', 'sample.txt', $payload]]), 'multipart/form-data; boundary=' . $boundary],
        'multipart nested upload beyond 8KB' => ['/probe', $multipart([], [['files[]', 'sample.txt', str_repeat('x', 20000) . '<?php echo 1; ?>']]), 'multipart/form-data; boundary=' . $boundary],
        'whitelist route override' => ['/admin/ajax/data/add/notice?s=/probe', http_build_query(['value' => $payload]), 'application/x-www-form-urlencoded'],
        'credential route override' => ['/index/LoginAjax/reg?s=/probe', http_build_query(['password' => $payload]), 'application/x-www-form-urlencoded'],
        'credential alias route override' => ['/index/login_ajax/reg?s=/probe', json_encode(['password' => $payload]), 'application/json'],
        'authentication alias on another app' => ['/admin/login_ajax/reg', json_encode(['password' => $payload]), 'application/json'],
        'nested authentication controller' => ['/index/other.login_ajax/reg', json_encode(['password' => $payload]), 'application/json'],
        'user route overriding password action' => ['/index/ajax/user/passWord/act/profile', json_encode(['password' => $payload]), 'application/json'],
    ] as $name => [$path, $body, $contentType]) {
        $result = $request($path, $body, $contentType);
        $check($result['blocked'] && $result['status'] === 403,
            $name . ' was not rejected by the firewall (status ' . $result['status'] . ', blocked '
            . ($result['blocked'] ? 'yes' : 'no') . ')');
    }

    $chunked = $request('/probe', http_build_query(['value' => $payload]), 'application/x-www-form-urlencoded',
        ['Transfer-Encoding: chunked']);
    $check($chunked['blocked'] && $chunked['status'] === 403, 'Chunked body escaped inspection');

    $reset = ['mail' => 'fixture@example.invalid', 'password' => 'fixture-password', 'repass' => 'fixture-password',
        'token' => $payload];
    foreach ([
        ['JSON reset', json_encode($reset), 'application/json'],
        ['padded reset', http_build_query($reset + ['pad' => str_repeat('x', 20000)]), 'application/x-www-form-urlencoded'],
    ] as [$name, $body, $contentType]) {
        $result = $request('/index/LoginAjax/reset', $body, $contentType);
        $check($result['blocked'] && $result['status'] === 403, $name . ' reached reset business logic');
    }

    $version = $request('/?s=benign&s=/version');
    $check($version['status'] === 200 && !$version['blocked']
        && trim($version['body']) === trim((string)file_get_contents($root . '/VERSION')),
        'Harmless compatibility routing changed or was treated as an attack');
    $blockedVersion = $request('/?s=benign&s=/version&value=' . rawurlencode($payload));
    $check($blockedVersion['blocked'] && $blockedVersion['status'] === 403,
        'A compatibility route hid another malicious query parameter');

    $registration = ['username' => 'normaluser', 'qq' => '1234567', 'captcha' => 'fixture-captcha',
        'password' => 'Blue-sleep(3)-password'];
    foreach ([
        ['form registration', http_build_query($registration), 'application/x-www-form-urlencoded'],
        ['JSON registration', json_encode($registration), 'application/json'],
        ['multipart registration', $multipart($registration), 'multipart/form-data; boundary=' . $boundary],
    ] as [$name, $body, $contentType]) {
        $result = $request('/index/LoginAjax/reg', $body, $contentType);
        $check(!$result['blocked'] && ($result['json']['code'] ?? null) === 1,
            $name . ' rejected a normal password containing SQL-like text');
    }
    $registration['username'] = 'sleep(3)';
    $registration['password'] = '<script>alert(1)</script>';
    foreach ([
        ['form credentials as data', http_build_query($registration), 'application/x-www-form-urlencoded'],
        ['JSON credentials as data', json_encode($registration), 'application/json'],
    ] as [$name, $body, $contentType]) {
        $result = $request('/index/LoginAjax/reg', $body, $contentType);
        $check(!$result['blocked'] && ($result['json']['code'] ?? null) === 1,
            $name . ' blocked a username/password permitted by registration validation');
    }
    $registration['username'] = 'htmlnormaluser';
    $encodedCredentials = json_encode($registration);
    $result = $request('/index.php?s=/index/LoginAjax/reg', $encodedCredentials, 'application/json');
    $check(!$result['blocked'] && ($result['json']['code'] ?? null) === 1,
        'Compatibility routing lost the credential exemption for normal registration');
    $registration['username'] = 'review-normal-user';
    $registration['password'] = 'Blue-sleep(3)-password';
    foreach ([
        '/index/login_ajax/reg', '/index/login-ajax/reg', '/login_ajax/reg',
        '/index/LoginAjax/reg/source/form', '/index.php?s=/index/login_ajax/reg',
        '/index/login_ajax/REG.html', '/index/login_ajax/reg/',
    ] as $path) {
        foreach ([
            [json_encode($registration), 'application/json'],
            [http_build_query($registration), 'application/x-www-form-urlencoded'],
        ] as [$body, $contentType]) {
            $result = $request($path, $body, $contentType);
            $check(!$result['blocked'] && ($result['json']['code'] ?? null) === 1,
                'An effective registration route rejected a normal password: ' . $path . ' (' . $contentType . ')');
        }
    }
    $validReset = $reset;
    $validReset['token'] = str_repeat('a', 48);
    $validReset['password'] = $validReset['repass'] = 'Blue-sleep(3)-password';
    $result = $request('/index/LoginAjax/reset', json_encode($validReset), 'application/json');
    $check(!$result['blocked'] && ($result['json']['code'] ?? null) === 1,
        'A valid reset password was mistaken for executable SQL');
    $result = $request('/index/login_ajax/reset/source/form', json_encode($validReset), 'application/json');
    $check(!$result['blocked'] && ($result['json']['code'] ?? null) === 1,
        'An effective reset route lost the normal-password exemption');
    $result = $request('/login_ajax/login/source/form', json_encode([
        'username' => 'fixture', 'password' => 'Blue-sleep(3)-password',
    ]), 'application/json');
    $check(!$result['blocked'] && ($result['json']['code'] ?? null) === 1,
        'An effective login route lost the normal-password exemption');
    foreach (['/index/ajax/user/passWord', '/index/ajax/user?act=passWord',
        '/index/ajax/user/profile/act/passWord'] as $path) {
        $result = $request($path, json_encode([
            'outpass' => 'Blue-sleep(3)-password', 'password' => 'Green-sleep(3)-password',
            'repass' => 'Green-sleep(3)-password',
        ]), 'application/json');
        $check(!$result['blocked'] && ($result['json']['code'] ?? null) === 1,
            'The normal-password exemption broke the actual change-password action: ' . $path);
    }

    $large = $request('/probe', http_build_query(['name' => "O'Reilly & 张三", 'pad' => str_repeat('x', 20000)]));
    $check(!$large['blocked'] && ($large['json']['post']['name'] ?? null) === "O'Reilly & 张三"
        && strlen((string)($large['json']['post']['pad'] ?? '')) === 20000, 'A large normal form was rejected or truncated');
    $normalJson = $request('/probe', json_encode(['options' => ['one', 'two'], 'empty' => [], 'enabled' => true,
        'name' => "O'Reilly & 张三"]), 'application/json');
    $check(!$normalJson['blocked'] && ($normalJson['json']['post']['options'] ?? []) === ['one', 'two'],
        'Normal JSON arrays, booleans or apostrophes were rejected');
    $normalArray = $request('/probe', 'options[]=one&options[]=two&nested[empty]=');
    $check(!$normalArray['blocked'] && ($normalArray['json']['post']['options'] ?? []) === ['one', 'two'],
        'Normal urlencoded arrays were rejected');
    $normalDuplicates = $request('/probe?value=first&value=last', 'value=first&value=last');
    $check(!$normalDuplicates['blocked'] && ($normalDuplicates['json']['get']['value'] ?? '') === 'last'
        && ($normalDuplicates['json']['post']['value'] ?? '') === 'last', 'Harmless duplicate parameters changed application parsing');

    $richText = $request('/admin/ajax/data/add/notice', http_build_query(['content' => '<p>普通公告 <a href="https://example.com/">链接</a></p>']));
    $check(!$richText['blocked'] && isset($richText['json']['post']['content']), 'The actual admin rich-text route lost its exemption');

    ob_start();
    (new netease\QRcode())->png('wxp://fixture-webscan', false, QR_ECLEVEL_M, 6, 2);
    $image = (string)ob_get_clean();
    $metadata = 'Description' . "\0" . $payload;
    $textChunk = pack('N', strlen($metadata)) . 'tEXt' . $metadata . pack('N', crc32('tEXt' . $metadata));
    $image = substr($image, 0, -12) . $textChunk . substr($image, -12);
    $normalUpload = $request('/index/ajax/qrcode/uploads', $multipart(['type' => 'wechat'],
        [['file', 'normal-sleep(3).png', $image]]), 'multipart/form-data; boundary=' . $boundary);
    $check(!$normalUpload['blocked'] && ($normalUpload['json']['code'] ?? null) === 1
        && ($normalUpload['json']['data']['url'] ?? '') === 'wxp://fixture-webscan',
        'A valid QR image or its textual metadata was mistaken for SQL');

    // This ordinary gray frame naturally produces <?= in PNG pixel data.
    // It is a valid, readable QR image, without any inserted script or metadata.
    $qr = imagecreatefromstring($image);
    $framed = imagecreatetruecolor(imagesx($qr) + 100, imagesy($qr) + 100);
    imagefill($framed, 0, 0, imagecolorallocate($framed, 60, 63, 61));
    imagecopy($framed, $qr, 50, 50, 0, 0, imagesx($qr), imagesy($qr));
    ob_start();
    imagepng($framed, null, 0, PNG_NO_FILTER);
    $binaryImage = (string)ob_get_clean();
    imagedestroy($framed);
    imagedestroy($qr);
    webscanHttpCheck(str_contains($binaryImage, '<?='), 'The normal PNG did not exercise the binary-marker regression');
    $normalBinaryUpload = $request('/index/ajax/qrcode/uploads', $multipart(['type' => 'wechat'],
        [['file', 'framed-qr.png', $binaryImage]]), 'multipart/form-data; boundary=' . $boundary);
    $check(!$normalBinaryUpload['blocked'] && ($normalBinaryUpload['json']['code'] ?? null) === 1
        && ($normalBinaryUpload['json']['data']['url'] ?? '') === 'wxp://fixture-webscan',
        'Normal PNG pixel data was mistaken for PHP source');
    $phpText = 'Comment' . "\0" . '<?php echo 1; ?>';
    $phpChunk = pack('N', strlen($phpText)) . 'tEXt' . $phpText . pack('N', crc32('tEXt' . $phpText));
    $metadataPhpImage = substr($image, 0, -12) . $phpChunk . substr($image, -12);
    $metadataPhp = $request('/probe', $multipart([], [['file', 'metadata.png', $metadataPhpImage]]),
        'multipart/form-data; boundary=' . $boundary);
    $check($metadataPhp['blocked'] && $metadataPhp['status'] === 403,
        'PHP in PNG text metadata escaped inspection');
    foreach ([
        ['normal international text', 'Description' . "\0\0\0\0\0" . '普通二维码', false],
        ['PHP international text', 'Description' . "\0\0\0\0\0" . '<?= 1; ?>', true],
        ['compressed international text', 'Description' . "\0\1\0\0\0" . gzcompress('普通二维码'), false],
    ] as [$name, $internationalText, $shouldBlock]) {
        $internationalChunk = pack('N', strlen($internationalText)) . 'iTXt' . $internationalText
            . pack('N', crc32('iTXt' . $internationalText));
        $internationalImage = substr($image, 0, -12) . $internationalChunk . substr($image, -12);
        $internationalUpload = $request('/probe', $multipart([], [['file', 'international.png', $internationalImage]]),
            'multipart/form-data; boundary=' . $boundary);
        $check($shouldBlock ? ($internationalUpload['blocked'] && $internationalUpload['status'] === 403)
            : (!$internationalUpload['blocked'] && isset($internationalUpload['json']['files']['file'])),
            'PNG ' . $name . ' was classified incorrectly');
    }
    $truncatedPhp = $request('/probe', $multipart([], [['file', 'truncated.png', substr($image, 0, -12) . '<?php echo 1; ?>']]),
        'multipart/form-data; boundary=' . $boundary);
    $check($truncatedPhp['blocked'] && $truncatedPhp['status'] === 403,
        'A malformed image hid PHP from the normal upload scan');

    $formats = [
        'JPEG' => static function ($resource): void { imagejpeg($resource, null, 95); },
        'progressive JPEG' => static function ($resource): void {
            imageinterlace($resource, true);
            imagejpeg($resource, null, 95);
            imageinterlace($resource, false);
        },
    ];
    if (gd_info()['WebP Support'] ?? false) {
        $formats['WebP'] = static function ($resource): void { imagewebp($resource, null, 100); };
    }
    $qr = imagecreatefromstring($image);
    $colorQr = imagecreatetruecolor(imagesx($qr), imagesy($qr));
    imagecopy($colorQr, $qr, 0, 0, 0, 0, imagesx($qr), imagesy($qr));
    foreach ($formats as $format => $encode) {
        ob_start();
        $encode($colorQr);
        $encodedImage = (string)ob_get_clean();
        $formatUpload = $request('/index/ajax/qrcode/uploads', $multipart(['type' => 'wechat'],
            [['file', 'qr-image', $encodedImage]]), 'multipart/form-data; boundary=' . $boundary);
        $check(!$formatUpload['blocked'] && ($formatUpload['json']['code'] ?? null) === 1
            && ($formatUpload['json']['data']['url'] ?? '') === 'wxp://fixture-webscan',
            'A normal ' . $format . ' QR image was rejected');
        $formatPhp = $request('/probe', $multipart([], [['file', 'polyglot-image', $encodedImage . '<?= 1; ?>']]),
            'multipart/form-data; boundary=' . $boundary);
        $check($formatPhp['blocked'] && $formatPhp['status'] === 403,
            'PHP appended to a ' . $format . ' image escaped inspection');
        if ($format === 'JPEG') {
            foreach (['COM' => "\xFF\xFE", 'XMP' => "\xFF\xE1"] as $metadataKind => $marker) {
                $segment = ($metadataKind === 'XMP' ? "http://ns.adobe.com/xap/1.0/\0" : '') . '<?= 1; ?>';
                $segmentImage = substr($encodedImage, 0, 2) . $marker . pack('n', strlen($segment) + 2)
                    . $segment . substr($encodedImage, 2);
                $segmentUpload = $request('/probe', $multipart([], [['file', 'metadata.jpg', $segmentImage]]),
                    'multipart/form-data; boundary=' . $boundary);
                $check($segmentUpload['blocked'] && $segmentUpload['status'] === 403,
                    'PHP in JPEG ' . $metadataKind . ' metadata escaped inspection');
            }
        } elseif ($format === 'WebP') {
            $xmp = '<?= 1; ?>';
            $xmpChunk = 'XMP ' . pack('V', strlen($xmp)) . $xmp . (strlen($xmp) % 2 ? "\0" : '');
            $xmpImage = 'RIFF' . pack('V', strlen($encodedImage) + strlen($xmpChunk) - 8)
                . substr($encodedImage, 8) . $xmpChunk;
            $xmpUpload = $request('/probe', $multipart([], [['file', 'metadata.webp', $xmpImage]]),
                'multipart/form-data; boundary=' . $boundary);
            $check($xmpUpload['blocked'] && $xmpUpload['status'] === 403,
                'PHP in WebP XMP metadata escaped inspection');
        }
    }
    imagedestroy($colorQr);
    imagedestroy($qr);
    $polyglot = $request('/probe', $multipart([], [['file', 'image.png', $image . '<?php echo 1; ?>']]),
        'multipart/form-data; boundary=' . $boundary);
    $check($polyglot['blocked'] && $polyglot['status'] === 403, 'A PHP fragment appended to a real image escaped inspection');

    webscanHttpCheck($failures === [], implode(PHP_EOL, $failures));
    echo "Webscan HTTP tests passed: {$checks} checks for parsed bodies, duplicates, uploads and normal traffic\n";
} finally {
    if (proc_get_status($process)['running']) {
        proc_terminate($process);
    }
    proc_close($process);
    $resolvedTemporary = realpath($temporary);
    $expectedPrefix = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'loopdeck-webscan-http-';
    webscanHttpCheck(is_string($resolvedTemporary) && str_starts_with($resolvedTemporary, $expectedPrefix),
        'Unexpected disposable cleanup path');
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolvedTemporary, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($resolvedTemporary);
}
