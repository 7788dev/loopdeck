<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

function honeypotHttpCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class HoneypotHttpFixture
{
    public readonly string $directory;
    public readonly string $base;
    private mixed $process;

    public function __construct(public readonly bool $enabled, array $environment = [], ?string $router = null, array $phpOptions = [])
    {
        $this->directory = sys_get_temp_dir() . '/loopdeck-honeypot-http-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
        $socket = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
        honeypotHttpCheck(is_resource($socket), 'Cannot reserve an isolated port: ' . $message);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->base = 'http://' . $address;
        $command = array_merge([PHP_BINARY], PHP_OS_FAMILY === 'Windows' ? ['-d', 'extension=pdo_sqlite'] : [],
            ['-d', 'memory_limit=192M', '-d', 'post_max_size=20M', '-d', 'upload_max_filesize=20M'], $phpOptions, ['-S', $address,
                $router ?? __DIR__ . '/fixtures/webscan-router.php']);
        $this->process = proc_open($command, [0 => ['pipe', 'r'],
            1 => ['file', $this->directory . '/server.log', 'a'],
            2 => ['file', $this->directory . '/server.log', 'a']], $pipes, $this->directory,
            array_replace(getenv(), ['LOOPDECK_WEBSCAN_TEST_DIRECTORY' => $this->directory,
                'LOOPDECK_HONEYPOT_HTTP_TEST' => '1', 'HONEYPOT_ENABLED' => $enabled ? '1' : '0',
                'TRUSTED_PROXIES' => '', 'CRON_KEY' => 'fixture-cron-key'], $environment));
        honeypotHttpCheck(is_resource($this->process), 'Cannot start the isolated HTTP server');
        fclose($pipes[0]);
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $connection = @stream_socket_client('tcp://' . $address, $error, $message, 0.1);
            if (is_resource($connection)) {
                fclose($connection);
                return;
            }
            usleep(20000);
        }
        throw new RuntimeException('HTTP server did not start: ' . file_get_contents($this->directory . '/server.log'));
    }

    public function request(string $path, string $method = 'GET', ?string $body = null, array $headers = []): array
    {
        @unlink($this->directory . '/business-entered');
        $responseHeaders = [];
        $curl = curl_init($this->base . $path);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_HTTPHEADER => array_merge(['Origin: ' . $this->base, 'X-Requested-With: XMLHttpRequest', 'Expect:'], $headers),
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            }]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $content = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        honeypotHttpCheck(is_string($content), 'HTTP request failed: ' . $error);
        clearstatcache();
        return ['status' => $status, 'body' => $content, 'json' => json_decode($content, true),
            'headers' => $responseHeaders, 'business' => is_file($this->directory . '/business-entered')];
    }

    public function close(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        $resolved = realpath($this->directory);
        $prefix = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'loopdeck-honeypot-http-';
        honeypotHttpCheck(is_string($resolved) && str_starts_with($resolved, $prefix), 'Unsafe fixture cleanup path');
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($resolved);
    }
}

$server = new HoneypotHttpFixture(true);
try {
    $health = $server->request('/healthcheck');
    honeypotHttpCheck($health['status'] === 200 && $health['body'] === 'ok' && $health['business'], 'Health checks changed');
    $normal = $server->request('/probe?value=ordinary');
    honeypotHttpCheck($normal['business'] && !file_exists($server->directory . '/honeypot'),
        'A normal request did not reach business code or created log state');
    $config = $server->request('/api/debug/config?password=private-request-canary', 'GET', null,
        ['X-Forwarded-For: 203.0.113.70', 'Cookie: session=private-cookie-canary']);
    honeypotHttpCheck(($config['json']['data']['backup']['url'] ?? '') === '/api/backup/export',
        'The config lure did not return the inert backup chain');
    $backup = $server->request('/api/backup/export');
    foreach ([$config, $backup] as $response) {
        honeypotHttpCheck($response['status'] === 200 && !$response['business'], 'A lure booted the real application');
        honeypotHttpCheck(strlen($response['body']) <= 8192 && str_contains($response['body'], '恭喜，你成功导出了空气'),
            'A lure exceeded its body budget or lost the final remark');
        honeypotHttpCheck(str_contains($response['headers']['cache-control'] ?? '', 'no-store')
            && ($response['headers']['x-content-type-options'] ?? '') === 'nosniff'
            && str_contains($response['headers']['x-robots-tag'] ?? '', 'noindex')
            && str_contains($response['headers']['content-security-policy'] ?? '', "default-src 'none'"),
            'A lure lost its browser/cache protections');
        honeypotHttpCheck(!isset($response['headers']['set-cookie']) && !str_contains($response['body'], 'private-'),
            'A lure created a session or reflected private request data');
    }
    honeypotHttpCheck(str_starts_with($backup['headers']['content-type'] ?? '', 'text/plain')
        && str_contains($backup['headers']['content-disposition'] ?? '', 'attachment'), 'SQL export was executable browser content');
    foreach (['/api/debug/config', '/api/backup/export'] as $path) {
        $head = $server->request($path, 'HEAD');
        honeypotHttpCheck($head['status'] === 200 && $head['body'] === '' && !$head['business'], 'HEAD ran business code or sent a body');
        foreach (['POST', 'PUT', 'DELETE'] as $method) {
            $unsupported = $server->request($path, $method);
            honeypotHttpCheck($unsupported['status'] === 405 && !$unsupported['business']
                && ($unsupported['headers']['allow'] ?? '') === 'GET, HEAD', 'A decoy accepted a write method');
        }
    }
    foreach (['/index.php?s=/api/debug/config', '/admin/ajax/data?s=/api/debug/config',
        '/probe?s=normal&s=%2Fapi%2Fdebug%2Fconfig'] as $path) {
        $alias = $server->request($path);
        honeypotHttpCheck($alias['body'] === $config['body'] && !$alias['business'], 'Effective ?s= routing did not select the lure');
    }
    foreach (['/api/debug/config?s=/probe', '/api/debug/config/extra', '/api/debug/configuration',
        '/api/backup/exported', '/probe?s[]=/api/debug/config'] as $path) {
        $unrelated = $server->request($path);
        honeypotHttpCheck($unrelated['business'], 'A similar or overridden route was hijacked by a lure');
    }

    $attack = "1' UNION ALL SELECT 'private-sql-canary' -- ";
    $sql = $server->request('/probe?value=' . rawurlencode($attack));
    honeypotHttpCheck($sql['status'] === 200 && !$sql['business'] && ($sql['json']['code'] ?? 0) === 1,
        'A blocked UNION request did not terminate with fake results');
    honeypotHttpCheck(!str_contains($sql['body'], 'private-sql-canary'), 'A SQL lure reflected its payload');
    $tables = $sql['json']['data']['tables'];
    honeypotHttpCheck($sql['json']['data']['rows'] === $config['json']['data']['backup']['rows'], 'Lure row totals drifted');
    foreach ($tables as $name => $rows) {
        honeypotHttpCheck(count($rows) === $config['json']['data']['tables'][$name]
            && substr_count($backup['body'], 'INSERT INTO `' . $name . '`') === count($rows), 'Export and JSON tables drifted');
    }
    $userIds = array_column($tables['cloud_users'], 'uid');
    $accountIds = array_column($tables['cloud_accounts'], 'id');
    foreach ($tables['cloud_jobs'] as $job) {
        honeypotHttpCheck(in_array($job['uid'], $userIds, true) && in_array($job['account_id'], $accountIds, true),
            'Fake job relationships were inconsistent');
    }
    foreach ([['value=' . rawurlencode($attack), 'application/x-www-form-urlencoded'],
        [json_encode(['value' => $attack]), 'application/json'],
        ['{"value":"UNION\\u0020SELECT 1"}', 'application/json'],
        ['value=' . rawurlencode($attack) . '&value=ordinary', 'application/x-www-form-urlencoded']] as [$body, $type]) {
        $post = $server->request('/probe', 'POST', $body, ['Content-Type: ' . $type]);
        honeypotHttpCheck($post['body'] === $sql['body'] && !$post['business'], 'An encoded/duplicate SQL request reached business code');
    }
    foreach (["<script>alert('private-xss-canary')</script>", "1' or sleep(3)-- "] as $attack) {
        $blocked = $server->request('/probe?value=' . rawurlencode($attack));
        honeypotHttpCheck($blocked['status'] === 403 && !$blocked['business'], 'An existing non-UNION rejection changed');
    }
    $cookie = $server->request('/probe', 'GET', null, ['Cookie: query=' . rawurlencode('UNION SELECT 1')]);
    honeypotHttpCheck($cookie['status'] === 403 && !$cookie['business'], 'Cookie SQL interception changed to fake business success');
    $opaque = $server->request('/index/login_ajax/reg', 'POST', json_encode([
        'username' => 'fixture_union', 'password' => 'Blue-UNION SELECT 1-password',
        'repass' => 'Blue-UNION SELECT 1-password', 'qq' => '1000876', 'captcha' => 'fixture-captcha',
    ]), ['Content-Type: application/json']);
    honeypotHttpCheck($opaque['business'] && !str_contains($opaque['body'], 'loopdeck_archive'), 'Opaque credentials became a fake success');
    for ($i = 0; $i < 30; $i++) {
        $attack = $server->request('/probe?value=UNION%20SELECT%201');
        $normal = $server->request('/probe?value=still-normal');
        honeypotHttpCheck(!$attack['business'] && $normal['business'] && ($normal['json']['get']['value'] ?? '') === 'still-normal',
            'The attack affected a subsequent legitimate request from the same IP');
    }
    $logs = (new app\service\HoneypotLog($server->directory . '/honeypot'))->snapshot();
    honeypotHttpCheck($logs['total'] > 0 && $logs['recent'][0]['ip'] === '127.0.0.1', 'Untrusted forwarded IP contaminated samples');
    foreach (glob($server->directory . '/honeypot/*.jsonl') as $file) {
        honeypotHttpCheck(!str_contains(file_get_contents($file), 'private-'), 'Request secrets entered sample storage');
    }

    // Seed a hostile but bounded stored string to exercise actual HTML escaping.
    $line = json_encode(['at' => time(), 'type' => 'blocked', 'method' => 'GET',
        'entry' => '<img src=x onerror=alert(1)>', 'ip' => '198.51.100.18']) . "\n";
    file_put_contents($server->directory . '/honeypot/events.0.jsonl', $line, FILE_APPEND);
    $admin = $server->request('/admin/system/honeypot', 'GET', null, ['X-LoopDeck-Test-Role: admin']);
    honeypotHttpCheck($admin['status'] === 200 && str_contains($admin['body'], 'id="honeypot-panel"')
        && str_contains($admin['body'], '不代表全部攻击次数'), 'Administrator statistics page did not render');
    honeypotHttpCheck(str_contains($admin['body'], '&lt;img src=x onerror=alert(1)&gt;')
        && !str_contains($admin['body'], '<img src=x onerror='), 'Stored log text executed as markup');
    honeypotHttpCheck(str_contains($admin['headers']['cache-control'] ?? '', 'no-store'), 'Private statistics were cacheable');
    foreach (['guest', 'user'] as $role) {
        $denied = $server->request('/admin/system/honeypot', 'GET', null, ['X-LoopDeck-Test-Role: ' . $role]);
        honeypotHttpCheck(!str_contains($denied['body'], 'id="honeypot-panel"')
            && !str_contains($denied['body'], '198.51.100.18'), 'A non-administrator read the samples');
    }
    $write = $server->request('/admin/system/honeypot', 'POST', '', ['X-LoopDeck-Test-Role: admin']);
    honeypotHttpCheck($write['status'] === 405, 'An auto-routed statistics action accepted POST');
    $pjax = $server->request('/admin/system/honeypot', 'GET', null, ['X-LoopDeck-Test-Role: admin', 'X-PJAX: true']);
    honeypotHttpCheck(str_contains($pjax['body'], 'id="honeypot-panel"') && !str_contains($pjax['body'], '<!doctype'), 'PJAX statistics navigation broke');
    $cron = $server->request('/cron/task', 'GET', null, ['X-Cron-Key: fixture-cron-key']);
    $badCron = $server->request('/cron/task');
    honeypotHttpCheck($cron['status'] === 200 && $cron['business'] && $badCron['status'] === 403, 'Cron authentication changed');
} finally {
    $server->close();
}

// Exercise the upload policy through PHP multipart parsing, the firewall and
// the real controller under the shipped image's default memory budget.
ob_start();
(new netease\QRcode())->png('wxp://fixture-audit', false, QR_ECLEVEL_M, 6, 2);
$smallQr = (string)ob_get_clean();
$qrSource = imagecreatefromstring($smallQr);
imagepalettetotruecolor($qrSource);
$uploadCases = [['small PNG', $smallQr, 'image/png', 1, '']];
foreach ([3200, 4096] as $dimension) {
    $largeQr = imagecreatetruecolor($dimension, $dimension);
    imagecopyresized($largeQr, $qrSource, 0, 0, 0, 0, $dimension, $dimension, imagesx($qrSource), imagesy($qrSource));
    ob_start();
    imagepng($largeQr, null, 9);
    $uploadCases[] = ['large PNG ' . $dimension, (string)ob_get_clean(), 'image/png', 1, ''];
    imagedestroy($largeQr);
}
foreach (['jpeg', 'webp'] as $format) {
    ob_start();
    $format === 'jpeg' ? imagejpeg($qrSource, null, 100) : imagewebp($qrSource, null, IMG_WEBP_LOSSLESS);
    $uploadCases[] = [$format, (string)ob_get_clean(), 'image/' . $format, 1, ''];
}
imagedestroy($qrSource);
foreach ([2 * 1024 * 1024, 2 * 1024 * 1024 + 1] as $bytes) {
    // A legitimate PNG ancillary chunk reaches the exact byte boundary without
    // changing image pixels or relying on invalid data appended after IEND.
    $comment = "note\0" . str_repeat('a', $bytes - strlen($smallQr) - 17);
    $chunk = pack('N', strlen($comment)) . 'tEXt' . $comment . pack('N', crc32('tEXt' . $comment));
    $png = substr($smallQr, 0, -12) . $chunk . substr($smallQr, -12);
    honeypotHttpCheck(strlen($png) === $bytes, 'PNG size fixture is incorrect');
    $uploadCases[] = ['bytes ' . $bytes, $png, 'image/png', $bytes === 2 * 1024 * 1024 ? 1 : 0, '2MB'];
    if ($bytes > 2 * 1024 * 1024) {
        $oversizedQr = $png;
    }
}
$wideQr = imagecreatetruecolor(4097, 1);
ob_start();
imagepng($wideQr);
$uploadCases[] = ['excess dimensions', (string)ob_get_clean(), 'image/png', 0, '4096'];
imagedestroy($wideQr);
$sendQr = static function (HoneypotHttpFixture $server, string $bytes, string $mime = 'image/png'): array {
    $boundary = 'isolated-qr-resource-boundary';
    $body = '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"type\"\r\n\r\nwechat\r\n"
        . '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"file\"; filename=\"qr.png\"\r\nContent-Type: " . $mime . "\r\n\r\n"
        . $bytes . "\r\n--" . $boundary . "--\r\n";
    return $server->request('/index/ajax/qrcode/uploads', 'POST', $body, ['Content-Type: multipart/form-data; boundary=' . $boundary]);
};
$uploadFailures = [];
foreach ([false, true] as $enabled) {
    $server = new HoneypotHttpFixture($enabled, ['TRUSTED_PROXIES' => '127.0.0.1']);
    try {
        foreach ($uploadCases as [$label, $bytes, $mime, $code, $message]) {
            $response = $sendQr($server, $bytes, $mime);
            if ($response['status'] !== 200 || ($response['json']['code'] ?? null) !== $code
                || ($code === 1 && ($response['json']['data']['url'] ?? '') !== 'wxp://fixture-audit')
                || ($code === 0 && !str_contains($response['json']['message'] ?? '', $message))) {
                $uploadFailures[] = $label . ' (honeypot ' . (int)$enabled . '): HTTP ' . $response['status'] . ' ' . substr($response['body'], 0, 250);
            }
        }
        if ($enabled) {
            $server->request('/api/debug/config', 'GET', null, ['X-Forwarded-For: 203.0.113.71']);
            $logs = (new app\service\HoneypotLog($server->directory . '/honeypot'))->snapshot();
            honeypotHttpCheck(($logs['recent'][0]['ip'] ?? '') === '203.0.113.71', 'Trusted proxy IP was lost');
            // A held shared lock prevents all sample writes, not the response.
            $lock = fopen($server->directory . '/honeypot/sample.lock', 'r+b');
            flock($lock, LOCK_EX);
            $response = $server->request('/api/backup/export');
            $busyPage = $server->request('/admin/system/honeypot', 'GET', null, ['X-LoopDeck-Test-Role: admin']);
            flock($lock, LOCK_UN);
            fclose($lock);
            honeypotHttpCheck($response['status'] === 200 && !$response['business'], 'Log contention blocked a decoy response');
            honeypotHttpCheck(str_contains($busyPage['body'], '本次未读取') && str_contains($busyPage['body'], '<h3>—</h3>'),
                'Unavailable statistics were presented as zero attacks');
        } else {
            foreach (['/api/debug/config', '/api/backup/export'] as $path) {
                $response = $server->request($path);
                honeypotHttpCheck($response['status'] === 404 && !$response['business'], 'A disabled lure fell through to business code');
            }
            $response = $server->request('/probe?value=UNION%20SELECT%201');
            honeypotHttpCheck($response['status'] === 403 && !$response['business'], 'Disabling honeypots disabled the original firewall');
            honeypotHttpCheck(!file_exists($server->directory . '/honeypot'), 'Disabled honeypots wrote sample state');
            $normal = $server->request('/probe?value=ordinary');
            honeypotHttpCheck($normal['business'], 'Disabling honeypots affected normal requests');
            $health = $server->request('/healthcheck');
            $cron = $server->request('/cron/task', 'GET', null, ['X-Cron-Key: fixture-cron-key']);
            honeypotHttpCheck($health['body'] === 'ok' && $cron['status'] === 200 && $cron['business'],
                'Disabling honeypots affected health checks or the authenticated scheduler');
            $admin = $server->request('/admin/system/honeypot', 'GET', null, ['X-LoopDeck-Test-Role: admin']);
            honeypotHttpCheck(str_contains($admin['body'], '蜜罐已关闭'), 'Disabled state was hidden from administrators');
        }
    } finally {
        $server->close();
    }
}

$server = new HoneypotHttpFixture(true, [], null, ['-d', 'upload_max_filesize=2M']);
try {
    $oversized = $sendQr($server, $oversizedQr);
    if ($oversized['status'] !== 200 || ($oversized['json']['code'] ?? null) !== 0
        || !str_contains($oversized['json']['message'] ?? '', '2MB')) {
        $uploadFailures[] = 'PHP upload limit did not return a friendly size error: HTTP ' . $oversized['status'] . ' ' . substr($oversized['body'], 0, 250);
    }
} finally {
    $server->close();
}
honeypotHttpCheck($uploadFailures === [], implode("\n", $uploadFailures));

$server = new HoneypotHttpFixture(true);
try {
    file_put_contents($server->directory . '/honeypot', 'simulate unwritable storage');
    foreach (['/api/debug/config', '/api/backup/export', '/probe?value=UNION%20SELECT%201'] as $path) {
        $response = $server->request($path);
        honeypotHttpCheck($response['status'] === 200 && !$response['business'], 'Storage failure reopened a blocked business path');
    }
    $response = $server->request('/probe?value=sleep%283%29');
    honeypotHttpCheck($response['status'] === 403 && !$response['business'], 'Storage failure lost a normal rejection');
} finally {
    $server->close();
}

// Fault injection into a disposable copy exercises the real response budget
// failure, without adding production hooks or changing the working source.
$faultRoot = sys_get_temp_dir() . '/loopdeck-honeypot-fault-' . bin2hex(random_bytes(6));
mkdir($faultRoot, 0700, true);
$source = file_get_contents(dirname(__DIR__) . '/app/service/Honeypot.php');
$source = str_replace('public const RESPONSE_BYTES = 8192;', 'public const RESPONSE_BYTES = 1;', $source, $replacements);
honeypotHttpCheck($replacements === 1, 'The response-budget fault was not applied');
$source = str_replace('__DIR__', var_export(dirname(__DIR__) . '/app/service', true), $source);
file_put_contents($faultRoot . '/Honeypot.php', $source);
file_put_contents($faultRoot . '/router.php', '<?php define("LOOPDECK_HONEYPOT_DIRECTORY", '
    . 'getenv("LOOPDECK_WEBSCAN_TEST_DIRECTORY") . "/honeypot"); require __DIR__ . "/Honeypot.php"; '
    . '\\app\\service\\Honeypot::respond("decoy_config", "/api/debug/config"); '
    . 'file_put_contents(getenv("LOOPDECK_WEBSCAN_TEST_DIRECTORY") . "/business-entered", "unexpected");');
$server = new HoneypotHttpFixture(true, [], $faultRoot . '/router.php');
try {
    $response = $server->request('/api/debug/config');
    honeypotHttpCheck($response['status'] === 403 && $response['body'] === "Forbidden\n" && !$response['business'],
        'A response-generation failure did not terminate with 403');
} finally {
    $server->close();
    $resolvedFault = realpath($faultRoot);
    honeypotHttpCheck(is_string($resolvedFault) && str_starts_with($resolvedFault,
        realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'loopdeck-honeypot-fault-'), 'Unsafe fault fixture cleanup');
    foreach (['Honeypot.php', 'router.php'] as $file) {
        unlink($resolvedFault . DIRECTORY_SEPARATOR . $file);
    }
    rmdir($resolvedFault);
}
echo "Honeypot HTTP tests passed: decoys, terminal blocking, compatibility, permissions, privacy and failure isolation\n";
