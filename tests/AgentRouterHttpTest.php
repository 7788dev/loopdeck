<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$temporary = sys_get_temp_dir() . '/loopdeck-agentrouter-http-' . bin2hex(random_bytes(6));
mkdir($temporary, 0700, true);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if (!is_resource($socket)) {
    throw new RuntimeException('Cannot reserve a disposable HTTP port: ' . $errorMessage);
}
$address = (string)stream_socket_get_name($socket, false);
fclose($socket);
$base = 'http://' . $address;
$command = array_merge([PHP_BINARY], PHP_OS_FAMILY === 'Windows' ? ['-d', 'extension=pdo_sqlite'] : [],
    ['-S', $address, $root . '/tests/fixtures/webscan-router.php']);
$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $temporary . '/server.log', 'a'],
    2 => ['file', $temporary . '/server.log', 'a']], $pipes, $temporary,
    array_replace(getenv(), ['LOOPDECK_WEBSCAN_TEST_DIRECTORY' => $temporary, 'LOOPDECK_AGENTROUTER_HTTP_TEST' => '1']));
if (!is_resource($process)) {
    throw new RuntimeException('Cannot start the disposable AgentRouter HTTP fixture');
}
fclose($pipes[0]);
$request = static function (string $path, ?string $body = null, string $type = 'application/x-www-form-urlencoded') use ($base): array {
    $curl = curl_init($base . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_PROXY => '',
        CURLOPT_HTTPHEADER => ['Content-Type: ' . $type, 'X-Requested-With: XMLHttpRequest', 'Origin: ' . $base, 'Expect:']]);
    if ($body !== null) {
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body]);
    }
    $content = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if (!is_string($content)) {
        throw new RuntimeException('HTTP fixture request failed: ' . $error);
    }
    return ['status' => $status, 'body' => $content, 'json' => json_decode($content, true)];
};
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
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
    if (!$ready) {
        throw new RuntimeException('AgentRouter HTTP fixture did not start: ' . file_get_contents($temporary . '/server.log'));
    }
    $add = $request('/index/console/agentrouter/add');
    $check($add['status'] === 200 && str_contains($add['body'], 'data-agentrouter-form="save"'),
        'The AgentRouter add URL did not dispatch and render the account form');
    $list = $request('/index/console/agentrouter/list');
    $check($list['status'] === 200 && str_contains($list['body'], '/index/console/agentrouter/info/fixture-user'),
        'The AgentRouter list URL did not render usable management links');
    foreach (['fixture-user', 'fixture+daily@example.invalid', 'fixture#name', 'fixture%23name', 'fixture%2Bname'] as $username) {
        $encoded = rawurlencode($username);
        foreach (['path', 'query', 'compatibility'] as $entry) {
            $accountUrl = static function (string $action) use ($username, $encoded, $entry): string {
                $path = '/index/console/agentrouter/' . $action;
                return match ($entry) {
                    'path' => $path . '/' . $encoded,
                    'query' => $path . '?' . http_build_query(['user_id' => $username]),
                    'compatibility' => '/index.php?' . http_build_query(['s' => $path . '/' . $username]),
                };
            };
            $info = $request($accountUrl('info'));
            $check($info['status'] === 200 && str_contains($info['body'], '$125.00')
                && str_contains($info['body'], 'data-agentrouter-account="' . $username . '"')
                && str_contains($info['body'], '/index/console/agentrouter/update/' . $encoded),
                'Account detail dispatch or encoded update link failed: ' . $username . ' (' . $entry . ')');
            $update = $request($accountUrl('update'));
            $check($update['status'] === 200 && str_contains($update['body'], 'data-agentrouter-form="save"')
                && str_contains($update['body'], 'value="' . $username . '"')
                && !str_contains($update['body'], 'fixture-private-password'),
                'Account update dispatch failed or exposed the stored password: ' . $username . ' (' . $entry . ')');
        }
        $check(str_contains($list['body'], '/index/console/agentrouter/info/' . $encoded),
            'The rendered account list did not URL-encode the login identifier: ' . $username);
    }
    $credentials = ['username' => 'fixture-user', 'password' => 'Blue-sleep(3)-password', 'nickname' => 'HTTP 测试账号'];
    foreach (['/index/ajax/agentrouter/save', '/index.php?s=/index/ajax/agentrouter/save',
        '/index/agentrouter/handle/act/save', '/index/agentrouter/handle?act=save',
        '/index/ajax/agentrouter/timing/act/save'] as $path) {
        foreach (['application/x-www-form-urlencoded', 'application/json'] as $type) {
            $body = $type === 'application/json' ? json_encode($credentials) : http_build_query($credentials);
            $saved = $request($path, $body, $type);
            $check($saved['status'] === 200 && ($saved['json']['code'] ?? null) === 1
                && ($saved['json']['data']['url'] ?? null) === '/index/console/agentrouter/info/fixture-user',
                'A valid save password or action binding failed: ' . $path . ' (' . $type . ')');
        }
    }
    foreach ([
        ['/probe', $credentials],
        ['/index/ajax/agentrouter/save?s=/probe', $credentials],
        ['/index/ajax/agentrouter/save/act/timing', $credentials],
        ['/index/agentrouter/handle/act/save/act/timing', $credentials],
        ['/index/ajax/agentrouter/save', $credentials + ['extra' => 'sleep(3)']],
        ['/index/ajax/agentrouter/save', ['username' => 'fixture-user', 'password' => ['sleep(3)']]],
        ['/index/ajax/agentrouter/save', ['username' => 'fixture-user', 'password' => 'ordinary', 'nickname' => 'sleep(3)']],
    ] as [$path, $body]) {
        $blocked = $request($path, json_encode($body), 'application/json');
        $check($blocked['status'] === 403 && str_contains($blocked['body'], '输入内容存在危险字符'),
            'The credential exemption leaked to an unrelated route, operation or field: ' . $path);
    }
    if ($failures !== []) {
        throw new RuntimeException(implode(PHP_EOL, $failures));
    }
    echo "AgentRouter HTTP routes, rendered account links and scoped credential firewall tests passed\n";
} finally {
    if (proc_get_status($process)['running']) {
        proc_terminate($process);
    }
    proc_close($process);
    $resolvedTemporary = realpath($temporary);
    $expectedPrefix = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'loopdeck-agentrouter-http-';
    if (!is_string($resolvedTemporary) || !str_starts_with($resolvedTemporary, $expectedPrefix)) {
        throw new RuntimeException('Unexpected disposable AgentRouter cleanup path');
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolvedTemporary, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($resolvedTemporary);
}
