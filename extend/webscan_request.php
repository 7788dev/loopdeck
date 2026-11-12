<?php

declare(strict_types=1);

require_once __DIR__ . '/webscan_image.php';

/** Match the effective route selected by ThinkPHP, including legacy ?s=. */
function webscan_route_path(): string
{
    if (isset($_GET['s'])) {
        return is_scalar($_GET['s']) ? '/' . ltrim((string)$_GET['s'], '/') : '/';
    }
    $route = (string)($_SERVER['PATH_INFO'] ?? '');
    if ($route === '' || $route === '/') {
        $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $route = is_string($path) && $path !== '' && $path !== '/'
            ? $path : (string)($_SERVER['SCRIPT_NAME'] ?? '/');
    }
    return '/' . ltrim($route, '/');
}

/** Resolve the authentication controller/action before Composer is loaded. */
function webscan_auth_target(): array
{
    $route = ltrim(webscan_route_path(), '/');
    $first = explode('/', $route, 2)[0];
    // MultiApp removes an explicit application name before route parsing.
    $application = strpos($first, '.') ? strstr($first, '.', true) : $first;
    if ($application === 'index') {
        $route = explode('/', $route, 2)[1] ?? '';
    } elseif (is_dir(__DIR__ . '/../app/' . $application)) {
        return [];
    }
    // Route::path() strips the suffix; auto-routing uses only the first two
    // components for controller/action, with the rest bound as path parameters.
    $route = (string)preg_replace('/\.html$/i', '', $route);
    $parts = explode('/', trim(str_replace('|', '/', $route), '/'));
    $controller = $parts[0] ?? '';
    if (str_contains($controller, '.')) {
        // Only root controllers have the audited, parameterized credential uses.
        $dot = strrpos($controller, '.');
        if ($dot !== 0) {
            return [];
        }
        $controller = substr($controller, 1);
    }
    // This is ThinkPHP Str::studly(), including its underscore/hyphen aliases.
    $controller = strtolower(strip_tags(str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $controller)))));
    $action = strtolower(strip_tags($parts[1] ?? ''));
    $argument = $parts[2] ?? '';
    if ($controller === 'ajax' && $action === 'user') {
        // The user route binds [:act]; extra named path parameters can override
        // it, and Dispatch then merges the route parameters over parsed GET.
        $parameters = $argument !== '' ? ['act' => $argument] : [];
        preg_replace_callback('/(\w+)\/([^\/]+)/', static function (array $match) use (&$parameters): void {
            $parameters[$match[1]] = strip_tags($match[2]);
        }, implode('/', array_slice($parts, 3)));
        $argument = $parameters['act'] ?? ($_GET['act'] ?? '');
    }
    return [$controller, $action, is_scalar($argument) ? (string)$argument : ''];
}

/** Credentials are opaque data only on their audited authentication targets. */
function webscan_opaque_post_field(string $key): bool
{
    if (!in_array($key, ['username', 'password', 'repass', 'outpass'], true)) {
        return false;
    }
    $target = webscan_auth_target();
    if ($target === []) {
        return false;
    }
    [$controller, $action, $argument] = $target;
    if ($controller === 'loginajax') {
        return in_array($action, $key === 'username' ? ['login', 'reg'] : ['login', 'reg', 'reset'], true);
    }
    return $key !== 'username' && $controller === 'ajax' && $action === 'user'
        && $argument === 'passWord';
}

/** Inspect each parsed leaf separately; never concatenate unrelated fields. */
function webscan_scan_fields(array $fields, string $filter, string $method): void
{
    foreach ($fields as $key => $value) {
        if ($method === 'POST' && is_scalar($value) && webscan_opaque_post_field((string)$key)) {
            webscan_StopAttack((string)$key, '', $filter, $method);
        } else {
            webscan_StopAttack((string)$key, $value, $filter, $method);
        }
    }
}

/** Scan every occurrence before PHP's duplicate-key overwrite, without an 8KB cutoff. */
function webscan_scan_pairs(string $encoded, string $filter, string $method): void
{
    $separators = (string)ini_get('arg_separator.input') ?: '&';
    $length = strlen($encoded);
    $offset = 0;
    while ($offset < $length) {
        $end = $offset + strcspn($encoded, $separators, $offset);
        $pair = substr($encoded, $offset, $end - $offset);
        $offset = $end + 1;
        if ($pair === '') {
            continue;
        }
        $parts = explode('=', $pair, 2);
        $key = urldecode($parts[0]);
        $value = urldecode($parts[1] ?? '');
        if ($method === 'POST' && webscan_opaque_post_field($key)) {
            $value = '';
        }
        webscan_StopAttack($key, $value, $filter, $method);
    }
}

/** JSON uses the same decoder as ThinkPHP, including Unicode escapes and arrays. */
function webscan_scan_json(string $raw, string $filter): void
{
    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        // ThinkPHP also yields no body parameters for malformed JSON.
        return;
    }
    webscan_scan_fields((array)$decoded, $filter, 'POST');

    // JSON objects can contain duplicate names too. Decode each string token,
    // including overwritten occurrences, while preserving root credential fields.
    $length = strlen($raw);
    $offset = 0;
    $depth = 0;
    $rootKey = null;
    while ($offset < $length) {
        $offset += strcspn($raw, '"{}[],', $offset);
        if ($offset >= $length) {
            break;
        }
        $character = $raw[$offset];
        if ($character === '{' || $character === '[') {
            $depth++;
            $offset++;
            continue;
        }
        if ($character === '}' || $character === ']') {
            $depth--;
            $offset++;
            continue;
        }
        if ($character === ',') {
            if ($depth === 1) {
                $rootKey = null;
            }
            $offset++;
            continue;
        }
        $end = $offset + 1;
        while ($end < $length) {
            $end += strcspn($raw, '"\\', $end);
            if ($end >= $length || $raw[$end] === '"') {
                break;
            }
            $end += 2;
        }
        $value = json_decode(substr($raw, $offset, $end - $offset + 1), true);
        $offset = $end + 1;
        $next = $offset + strspn($raw, " \t\r\n", $offset);
        $isKey = $next < $length && $raw[$next] === ':';
        if ($isKey) {
            if ($depth === 1) {
                $rootKey = $value;
            }
            webscan_StopAttack((string)$value, '', $filter, 'POST');
        } elseif (!($depth === 1 && is_string($rootKey) && webscan_opaque_post_field($rootKey))) {
            webscan_StopAttack('', $value, $filter, 'POST');
        }
    }
}

/** Only read PHP-created temporary uploads, including nested file arrays. */
function webscan_scan_upload(mixed $temporary, mixed $error, string $filter): void
{
    if (is_array($temporary)) {
        foreach ($temporary as $key => $path) {
            webscan_scan_upload($path, is_array($error) ? ($error[$key] ?? UPLOAD_ERR_NO_FILE) : $error, $filter);
        }
        return;
    }
    if (!is_string($temporary) || (int)$error !== UPLOAD_ERR_OK || !is_uploaded_file($temporary)) {
        return;
    }
    $content = file_get_contents($temporary);
    if (!is_string($content)) {
        http_response_code(403);
        exit(webscan_pape());
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporary);
    $image = in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)
        && @getimagesize($temporary) !== false;
    // Raw pixels and compressed bytes can naturally contain <?=. Inspect the
    // image's text/trailer instead; malformed framing receives the full scan.
    $imagePhp = $image ? webscan_image_contains_php($content, $mime) : null;
    if ($imagePhp === true || ($imagePhp === null && webscan_contains_php($content))) {
        http_response_code(403);
        exit(webscan_pape());
    }
    if ($imagePhp === null) {
        webscan_StopAttack('', $content, $filter, 'UPLOAD');
    }
}

function webscan_scan_request(string $getFilter, string $postFilter, string $cookieFilter): void
{
    global $webscan_get, $webscan_post, $webscan_cookie, $webscan_referre, $webscan_referer;

    if ($webscan_get) {
        webscan_scan_fields($_GET, $getFilter, 'GET');
        webscan_scan_pairs((string)($_SERVER['QUERY_STRING'] ?? ''), $getFilter, 'GET');
    }
    if ($webscan_post) {
        webscan_scan_fields($_POST, $postFilter, 'POST');
        $contentType = trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]);
        if (str_contains($contentType, 'json') || $contentType === 'application/x-www-form-urlencoded') {
            // php://input is repeatable on supported PHP versions; the framework
            // subsequently reads and parses the complete body independently.
            $raw = file_get_contents('php://input');
            if (is_string($raw)) {
                if (str_contains($contentType, 'json')) {
                    webscan_scan_json($raw, $postFilter);
                } else {
                    webscan_scan_pairs($raw, $postFilter, 'POST');
                }
            }
        }
        foreach ($_FILES ?? [] as $upload) {
            if (is_array($upload)) {
                webscan_scan_upload($upload['tmp_name'] ?? null, $upload['error'] ?? UPLOAD_ERR_NO_FILE, $postFilter);
            }
        }
    }
    if ($webscan_cookie) {
        webscan_scan_fields($_COOKIE, $cookieFilter, 'COOKIE');
    }
    if ($webscan_referre) {
        webscan_scan_fields($webscan_referer, $postFilter, 'REFERRER');
    }
}
