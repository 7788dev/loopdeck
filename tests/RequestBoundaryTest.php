<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/common.php';

use app\middleware\CheckAjaxRequest;
use app\middleware\CheckLoginUser;
use app\middleware\CheckCronAccess;

function boundaryCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$server = $_SERVER;
$previousCronKey = getenv('CRON_KEY');
try {
    putenv('CRON_KEY=fixture-cron-key');
    $cronRequest = new class {
        public mixed $key = '';
        public function header($name, $default = '') { return $this->key; }
        public function get($name, $default = '') { return ''; }
    };
    $cronGuard = new CheckCronAccess();
    foreach (['', 'wrong', ['fixture-cron-key']] as $key) {
        $cronRequest->key = $key;
        boundaryCheck($cronGuard->handle($cronRequest, static fn() => 'allowed')->getCode() === 403, 'An invalid cron credential reached a controller helper');
    }
    $cronRequest->key = 'fixture-cron-key';
    boundaryCheck($cronGuard->handle($cronRequest, static fn() => 'allowed') === 'allowed', 'The container cron credential was rejected');
    $cronRoutes = require dirname(__DIR__) . '/app/cron/config/route.php';
    boundaryCheck($cronRoutes['url_route_must'] && $cronRoutes['route_complete_match'], 'Cron helper methods remain auto-routable');
    $_SERVER = ['HTTP_HOST' => 'panel.example:8001', 'REQUEST_SCHEME' => 'http'];
    $request = new class extends think\Request {
        public function __construct(bool $header = false)
        {
            parent::__construct();
            $this->get = ['_ajax' => '1'];
            $this->server = $header ? ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'] : [];
        }
    };
    boundaryCheck($request->isAjax() && !$request->isAjax(true), 'Fixture did not reproduce the _ajax bypass');
    $middleware = new CheckAjaxRequest();
    $result = $middleware->handle($request, static fn() => 'allowed');
    boundaryCheck($result instanceof think\Response && $result->getCode() === 403, 'An _ajax parameter bypassed request validation');
    $valid = new ($request::class)(true);
    boundaryCheck($middleware->handle($valid, static fn() => 'allowed') === 'allowed', 'A genuine same-origin AJAX request was rejected');
    foreach (['http://foreign.example', 'http://panel.example:9000', 'https://panel.example:8001', 'null'] as $origin) {
        $_SERVER['HTTP_ORIGIN'] = $origin;
        boundaryCheck(is_cross_origin_request(), 'Foreign origin was accepted: ' . $origin);
        boundaryCheck($middleware->handle($valid, static fn() => 'allowed')->getCode() === 403, 'A foreign-origin AJAX request reached the handler');
    }
    $_SERVER['HTTP_ORIGIN'] = 'http://panel.example:8001';
    boundaryCheck(!is_cross_origin_request(), 'The exact origin was rejected');
    $_SERVER = ['HTTP_HOST' => 'panel.example', 'REQUEST_SCHEME' => 'https', 'HTTP_ORIGIN' => 'https://panel.example:443'];
    boundaryCheck(!is_cross_origin_request(), 'An explicit default HTTPS port was rejected');

    $user = ['uid' => 1, 'sid' => 'session-secret', 'state' => 1, 'web_id' => 1];
    boundaryCheck(CheckLoginUser::validSessionUser($user, $user, 1), 'A valid session was rejected');
    foreach ([['state' => 0], ['web_id' => 2], ['sid' => ''], ['sid' => 'other-session'], ['uid' => 2]] as $change) {
        boundaryCheck(!CheckLoginUser::validSessionUser($user, array_replace($user, $change), 1), 'An invalid or blocked user session remained authorized');
    }
    boundaryCheck(html_escape('"><img src=x onerror=alert(1)>') === '&quot;&gt;&lt;img src=x onerror=alert(1)&gt;', 'HTML output encoding did not protect attribute boundaries');
    echo "Request boundary and session isolation tests passed\n";
} finally {
    $_SERVER = $server;
    putenv($previousCronKey === false ? 'CRON_KEY' : 'CRON_KEY=' . $previousCronKey);
}
