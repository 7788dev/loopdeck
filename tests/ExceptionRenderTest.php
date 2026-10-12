<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use app\exception\handler\AppExceptionHandler;
use think\exception\ValidateException;
use think\response\Json;

/**
 * Reproduces R5: the exception handler may only answer with JSON for the
 * panel's own strict AJAX requests; a plain browser navigation must fall back
 * to the framework HTML rendering instead of a bare JSON payload.
 */
function exceptionRenderCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$ajaxRequest = new class {
    public function isAjax(bool $strict = false): bool
    {
        return $strict;
    }
};
$htmlRequest = new class {
    public function isAjax(bool $strict = false): bool
    {
        return false;
    }
};

exceptionRenderCheck(AppExceptionHandler::wantsJson($ajaxRequest) === true,
    'A strict AJAX request must be treated as wanting JSON');
exceptionRenderCheck(AppExceptionHandler::wantsJson($htmlRequest) === false,
    'A plain navigation must not be forced to JSON');
exceptionRenderCheck(AppExceptionHandler::wantsJson(new stdClass()) === false,
    'A non-request argument must fall back to the HTML path');

$app = new think\App(dirname(__DIR__) . '/');
$app->setRuntimePath(sys_get_temp_dir() . '/loopdeck-exception-' . bin2hex(random_bytes(6)) . '/');
@mkdir($app->getRuntimePath(), 0775, true);
$app->initialize();
$handler = new AppExceptionHandler($app);

// The AJAX branch keeps returning the JSON payload the endpoints rely on.
$response = $handler->render($ajaxRequest, new ValidateException('用户名或密码错误'));
exceptionRenderCheck($response instanceof Json, 'An AJAX validation error must render a JSON response');
$payload = (array)$response->getData();
exceptionRenderCheck(($payload['message'] ?? '') === '用户名或密码错误',
    'The JSON payload lost the validation message');

echo "Exception render boundary tests passed\n";
