<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$directory = sys_get_temp_dir() . '/loopdeck-anyrouter-http-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if (!is_resource($socket)) throw new RuntimeException('Cannot reserve a disposable HTTP port');
$address = stream_socket_get_name($socket, false);
fclose($socket);
$base = 'http://' . $address;
$command = array_merge([PHP_BINARY], PHP_OS_FAMILY === 'Windows' ? ['-d', 'extension=pdo_sqlite'] : [],
    ['-S', $address, '-t', $root . '/public', $root . '/tests/fixtures/webscan-router.php']);
$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $directory . '/server.log', 'a'],
    2 => ['file', $directory . '/server.log', 'a']], $pipes, $directory,
    array_replace(getenv(), ['LOOPDECK_WEBSCAN_TEST_DIRECTORY' => $directory, 'LOOPDECK_ANYROUTER_ADMIN_HTTP_TEST' => '1']));
if (!is_resource($process)) throw new RuntimeException('Cannot start the isolated AnyRouter HTTP fixture');
fclose($pipes[0]);
$request = static function (string $path, ?string $body = null, string $type = 'application/x-www-form-urlencoded', string $role = 'admin') use ($base): array {
    $curl = curl_init($base . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_PROXY => '',
        CURLOPT_HTTPHEADER => ['Content-Type: ' . $type, 'X-Requested-With: XMLHttpRequest', 'Origin: ' . $base,
            'X-LoopDeck-Test-Role: ' . $role, 'Expect:']]);
    if ($body !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body]);
    $content = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if (!is_string($content)) throw new RuntimeException('The HTTP fixture request failed');
    return ['status' => $status, 'body' => $content, 'json' => json_decode($content, true)];
};
$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void { if (!$ok) $failures[] = $message; };
try {
    $node = proc_open(['node', $root . '/tests/AnyRouterProxyInteractionTest.js', '--emit-bodies'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $nodePipes, $root);
    if (!is_resource($node)) throw new RuntimeException('Cannot run the shipped serializer regression');
    fclose($nodePipes[0]);
    $serialized = stream_get_contents($nodePipes[1]);
    $serializerError = stream_get_contents($nodePipes[2]);
    fclose($nodePipes[1]);
    fclose($nodePipes[2]);
    $serializerExit = proc_close($node);
    $bodies = json_decode($serialized, true);
    if ($serializerExit !== 0 || !is_array($bodies)) throw new RuntimeException('The shipped serializer failed: ' . $serializerError);
    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $connection = @stream_socket_client('tcp://' . $address, $errorCode, $errorMessage, 0.1);
        if (is_resource($connection)) { fclose($connection); $ready = true; break; }
        if (!proc_get_status($process)['running']) break;
        usleep(50000);
    }
    if (!$ready) throw new RuntimeException('The HTTP fixture did not start');
    foreach ($bodies as $body) {
        $saved = $request('/admin/ajax/set/anyrouterProxy', $body);
        $check($saved['status'] === 200 && ($saved['json']['code'] ?? 0) === 1,
            'A real shipped-jQuery HTTP body disagreed with backend validation');
    }
    $plugin = $request('/admin/system/set/plugin');
    $check($plugin['status'] === 200 && str_contains($plugin['body'], 'data-anyrouter-platform-form')
        && str_contains($plugin['body'], 'name="http_sources"') && str_contains($plugin['body'], 'name="socks5_sources"')
        && str_contains($plugin['body'], 'cdn.jsdelivr.net/gh/monosans/proxy-list')
        && !str_contains($plugin['body'], 'fixture-any-platform-secret') && !str_contains($plugin['body'], '127.0.0.1'),
        'The administrator form did not show editable sources or exposed private credentials');
    foreach ([['kind' => 'proxy', 'source' => 'http://fixture:Blue-sleep(3)-password@127.0.0.1:7890', 'clear' => ''],
        ['kind' => 'proxy', 'source' => '', 'clear' => ''],
        ['kind' => 'subscription', 'source' => 'https://8.8.8.8/list?token=Blue-sleep(3)-secret', 'clear' => ''],
        ['kind' => 'builtin', 'http_sources' => "https://8.8.8.8/http.txt\nhttps://8.8.4.4/list", 'socks5_sources' => '', 'clear' => ''],
        ['clear' => '1']] as $input) {
        foreach (['application/x-www-form-urlencoded', 'application/json'] as $type) {
            $body = $type === 'application/json' ? json_encode($input) : http_build_query($input);
            foreach (['/admin/ajax/set/anyrouterProxy', '/index.php?s=/admin/ajax/set/anyrouterProxy',
                '/admin/ajax/set/config/act/anyrouterProxy', '/admin/.ajax/set?act=anyrouterProxy'] as $path) {
                $saved = $request($path, $body, $type);
                $check($saved['status'] === 200 && ($saved['json']['code'] ?? 0) === 1
                    && !str_contains($saved['body'], 'Blue-sleep(3)') && !str_contains($saved['body'], 'fixture-any-platform-secret'),
                    'A valid setting failed real HTTP parsing or exposed credentials: ' . $path . ' (' . $type . ')');
            }
        }
    }
    foreach (['guest', 'user'] as $role) {
        $denied = $request('/admin/system/set/plugin', null, 'application/x-www-form-urlencoded', $role);
        $check(!str_contains($denied['body'], 'data-anyrouter-platform-form'), 'A non-admin could view source configuration');
        $denied = $request('/admin/ajax/set/anyrouterProxy', http_build_query(['kind' => 'proxy', 'source' => 'http://8.8.8.8:8080']),
            'application/x-www-form-urlencoded', $role);
        $check(($denied['json']['code'] ?? 0) !== 1, 'A non-admin could save source configuration');
    }
    $check($request('/admin/ajax/set/anyrouterProxy')['status'] === 405, 'The setting endpoint accepted a GET mutation');
    foreach ([['/admin/ajax/set/config', ['source' => 'sleep(3)']],
        ['/admin/ajax/set/anyrouterProxy/act/config', ['source' => 'sleep(3)']],
        ['/admin/ajax/set/anyrouterProxy?s=/probe', ['source' => 'sleep(3)']],
        ['/admin/ajax/set/anyrouterProxy', ['source' => ['sleep(3)']]],
        ['/admin/ajax/set/anyrouterProxy', ['kind' => 'proxy', 'source' => 'http://8.8.8.8:8080', 'extra' => 'sleep(3)']]] as [$path, $input]) {
        $check($request($path, json_encode($input), 'application/json')['status'] === 403,
            'The credential exemption leaked outside its exact field and effective route: ' . $path);
    }
    $monitor = $request('/admin/system/set/cron');
    $check($monitor['status'] === 200 && str_contains($monitor['body'], 'AnyRouter') && str_contains($monitor['body'], 'AgentRouter')
        && str_contains($monitor['body'], 'cron/task') && str_contains($monitor['body'], 'cron/notifications')
        && !str_contains($monitor['body'], 'cron/anyrouter') && !str_contains($monitor['body'], 'cron/agentrouter')
        && !str_contains($monitor['body'], 'cronkey='), 'Monitoring omitted the new platforms or advertised unsafe/nonexistent endpoints');
    $help = $request('/index/console/user/faq', null, 'application/x-www-form-urlencoded', 'user');
    $check($help['status'] === 200 && str_contains($help['body'], 'AgentRouter') && str_contains($help['body'], '站长订阅链接')
        && str_contains($help['body'], '次日') && str_contains($help['body'], '登录动作本身')
        && str_contains($help['body'], 'https://agentrouter.org/register?aff=7Oga'), 'The help page omitted proxy choices or actual daily scheduling behavior');
} finally {
    proc_terminate($process);
    proc_close($process);
    $remove = static function (string $path) use (&$remove): void {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $name) if ($name !== '.' && $name !== '..') $remove($path . '/' . $name);
            rmdir($path);
        } else {
            unlink($path);
        }
    };
    $remove($directory);
}
if ($failures !== []) throw new RuntimeException(implode("\n", $failures));
echo "AnyRouter real HTTP settings, credentials, authorization, monitor and help tests passed\n";
