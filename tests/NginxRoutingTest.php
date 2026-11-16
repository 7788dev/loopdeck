<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// The Docker build has nginx. PHP-only development environments can still run
// the rest of the suite without installing a second web server.
if (PHP_OS_FAMILY === 'Windows') {
    echo "Nginx routing test skipped: run it in the Linux image\n";
    exit(0);
}
$probe = @proc_open(['nginx', '-v'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $probePipes);
if (!is_resource($probe)) {
    echo "Nginx routing test skipped: nginx is not installed\n";
    exit(0);
}
fclose($probePipes[0]);
$version = stream_get_contents($probePipes[1]) . stream_get_contents($probePipes[2]);
fclose($probePipes[1]);
fclose($probePipes[2]);
if (proc_close($probe) !== 0 || !str_contains($version, 'nginx version:')) {
    echo "Nginx routing test skipped: nginx is not installed\n";
    exit(0);
}

$root = dirname(__DIR__);
$temporary = sys_get_temp_dir() . '/loopdeck-nginx-route-' . bin2hex(random_bytes(8));
mkdir($temporary . '/public', 0700, true);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if (!is_resource($socket)) {
    throw new RuntimeException('Cannot reserve the isolated nginx port: ' . $errorMessage);
}
$address = (string)stream_socket_get_name($socket, false);
fclose($socket);
$source = (string)file_get_contents($root . '/docker/nginx-security.conf');
if (!str_contains($source, 'fastcgi_pass 127.0.0.1:9000;')) {
    throw new RuntimeException('The nginx routing fixture could not find the FastCGI handoff');
}
// Observe the very variable sent as PATH_INFO, using the original rewrite and
// capture directives. No PHP-FPM, application state or upstream is involved.
$source = str_replace('fastcgi_pass 127.0.0.1:9000;', 'return 200 "$loopdeck_path_info";', $source);
file_put_contents($temporary . '/fastcgi_params', '');
file_put_contents($temporary . '/site.conf', $source);
$config = "worker_processes 1;\ndaemon off;\npid $temporary/nginx.pid;\n"
    . "error_log $temporary/error.log;\nevents { worker_connections 16; }\n"
    . "http { access_log off; default_type text/plain; server { listen $address; root $temporary/public;\n"
    . "include $temporary/site.conf;\nlocation / { try_files \$uri \$uri/ /index.php?\$query_string; }\n} }\n";
file_put_contents($temporary . '/nginx.conf', $config);
$process = null;

try {
    $process = proc_open(['nginx', '-p', $temporary . '/', '-c', $temporary . '/nginx.conf'],
        [0 => ['pipe', 'r'], 1 => ['file', $temporary . '/process.log', 'a'],
            2 => ['file', $temporary . '/process.log', 'a']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start the isolated nginx fixture');
    }
    fclose($pipes[0]);
    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $connection = @stream_socket_client('tcp://' . $address, $errorCode, $errorMessage, 0.05);
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
        throw new RuntimeException('Nginx fixture did not start: ' . file_get_contents($temporary . '/process.log'));
    }

    $paths = ['/cron/task?workers=2&worker=1', '/index.php?s=%2Findex%2Fconsole%2Fagentrouter%2Flist'];
    foreach (['fixture-user', 'fixture+daily@example.invalid', 'fixture#name', 'fixture%23name',
        'fixture%2Bname', 'fixture name', '测试账号'] as $username) {
        $paths[] = '/index/console/agentrouter/info/' . rawurlencode($username) . '?tab=logs';
    }
    foreach ($paths as $path) {
        $curl = curl_init('http://' . $address . $path);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*', CURLOPT_PATH_AS_IS => true]);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        $expected = (string)parse_url($path, PHP_URL_PATH);
        if ($status !== 200 || $body !== $expected) {
            throw new RuntimeException('Nginx changed the original PATH_INFO: ' . json_encode([
                'request' => $path, 'expected' => $expected, 'actual' => $body, 'status' => $status, 'error' => $error,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
    }
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir() && !$file->isLink()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($temporary);
}

echo "Nginx original PATH_INFO, encoded account identifiers and query separation tests passed\n";
