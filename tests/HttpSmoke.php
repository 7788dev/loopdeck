<?php

declare(strict_types=1);

// Explicit opt-in inside a disposable installed container, never production.
$base = rtrim((string)getenv('LOOPDECK_TEST_URL'), '/');
if (!preg_match('#\Ahttp://(?:127\.0\.0\.1|localhost)(?::\d+)?\z#', $base)
    || !str_starts_with((string)getenv('MYSQL_DATABASE'), 'loopdeck_qa_')) {
    throw new RuntimeException('Use a loopback URL inside a disposable loopdeck_qa_* installation');
}
$username = (string)getenv('LOOPDECK_TEST_ADMIN_USERNAME');
$password = (string)getenv('LOOPDECK_TEST_ADMIN_PASSWORD');
if ($username === '' || $password === '') {
    throw new RuntimeException('Explicit disposable administrator credentials are required');
}
$cookies = tempnam(sys_get_temp_dir(), 'loopdeck-http-');
$checks = 0;
$request = static function (string $path, ?array $post = null, array $headers = []) use ($base, $cookies): array {
    $curl = curl_init($base . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_COOKIEFILE => $cookies, CURLOPT_COOKIEJAR => $cookies, CURLOPT_HTTPHEADER => $headers]);
    if ($post !== null) {
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]);
    }
    $body = curl_exec($curl);
    $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($body === false) {
        throw new RuntimeException('HTTP fixture connection failed: ' . $error);
    }
    return ['code' => $code, 'body' => $body, 'json' => json_decode($body, true)];
};
$check = static function (bool $ok, string $name) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException($name);
    }
    $checks++;
    echo "PASS {$name}\n";
};
$ajaxHeaders = ['X-Requested-With: XMLHttpRequest', 'Origin: ' . $base];
$probe = dirname(__DIR__) . '/public/static/uploads/qa-execution-probe.php';
try {
    $response = $request('/healthcheck');
    $check($response['code'] === 200 && trim($response['body']) === 'ok', 'healthcheck');
    $response = $request('/version');
    $check($response['code'] === 200 && trim($response['body']) === trim(file_get_contents(dirname(__DIR__) . '/VERSION')), 'served version');
    $response = $request('/admin/ajax/data/list/users', [], $ajaxHeaders);
    $check($response['code'] === 302, 'anonymous admin access rejected');
    $response = $request('/index/loginajax/login', ['username' => $username, 'password' => $password], $ajaxHeaders);
    $check(($response['json']['code'] ?? null) === 1, 'administrator login');
    foreach (['/index/console/index', '/index/console/user/profile', '/admin/system/index', '/admin/system/update'] as $path) {
        $response = $request($path);
        $check($response['code'] === 200 && str_contains(strtolower($response['body']), '<html'), 'render ' . $path);
    }
    $response = $request('/admin/ajax/data/list/users', ['start' => 0, 'length' => 10], $ajaxHeaders);
    $check($response['code'] === 200 && ($response['json']['total'] ?? 0) >= 1, 'admin pagination');
    foreach ($response['json']['data'] ?? [] as $user) {
        $check(!isset($user['password']) && !isset($user['sid']), 'admin list redacts credentials');
    }
    $profile = ['nickname' => '<script>x</script>', 'qq' => '1234567', 'mail' => 'long.recovery.fixture@example.com'];
    $response = $request('/index/ajax/user/profile', $profile);
    $check($response['code'] === 403, 'non-AJAX profile mutation rejected');
    $response = $request('/index/ajax/user/profile', $profile, ['X-Requested-With: XMLHttpRequest', 'Origin: https://foreign.invalid']);
    $check($response['code'] === 403, 'foreign-origin profile mutation rejected');
    $response = $request('/index/ajax/user/profile', $profile, $ajaxHeaders);
    $check(($response['json']['code'] ?? null) === 1, 'valid profile save');
    $response = $request('/index/console/user/profile');
    $check(!str_contains($response['body'], $profile['nickname'])
        && str_contains($response['body'], htmlspecialchars($profile['nickname'], ENT_QUOTES, 'UTF-8')), 'profile HTML encoding');
    $response = $request('/cron/task');
    $check($response['code'] === 403, 'unauthenticated cron rejected');
    $cronHeaders = ['X-Cron-Key: ' . getenv('CRON_KEY')];
    foreach (['/cron/task', '/cron/notifications'] as $path) {
        $response = $request($path, null, $cronHeaders);
        $check(($response['json']['code'] ?? null) === 1000, 'authenticated ' . $path);
    }
    $response = $request('/cron/common/accountInvalid', null, $cronHeaders);
    $check($response['code'] === 404, 'cron helper is not routable');
    foreach (['/index/console/heybox', '/index/ajax/heybox/login'] as $path) {
        $check($request($path)['code'] === 404, 'retired route ' . $path);
    }
    file_put_contents($probe, '<?php echo "qa-script-executed";');
    $response = $request('/static/uploads/qa-execution-probe.php');
    $check($response['code'] === 404 && !str_contains($response['body'], 'qa-script-executed'), 'uploaded PHP is not executable');
    echo "HTTP smoke passed: {$checks} assertions\n";
} finally {
    unlink($cookies);
    if (is_file($probe)) {
        unlink($probe);
    }
}
