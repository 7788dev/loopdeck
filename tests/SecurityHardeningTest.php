<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/common.php';

use app\admin\model\Weblist;

function securityHardeningCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class SecurityHardeningProbe
{
    public static bool $wokeUp = false;

    public function __wakeup(): void
    {
        self::$wokeUp = true;
    }
}

$root = dirname(__DIR__);
$payload = serialize(['probe' => new SecurityHardeningProbe()]);
securityHardeningCheck(safe_unserialize_array($payload) === [], 'Object payload was not rejected');
securityHardeningCheck(!SecurityHardeningProbe::$wokeUp, 'Object wakeup executed during legacy payload decoding');
securityHardeningCheck(
    safe_unserialize_array(serialize(['nested' => ['value' => 1]])) === ['nested' => ['value' => 1]],
    'Valid legacy array payload could not be decoded'
);

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/app', FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file->isFile() || !in_array(strtolower($file->getExtension()), ['php', 'html'], true)) {
        continue;
    }
    $lines = file($file->getPathname());
    securityHardeningCheck(is_array($lines), 'Unable to inspect ' . $file->getPathname());
    foreach ($lines as $lineNumber => $line) {
        if (str_contains($line, 'unserialize(')) {
            securityHardeningCheck(
                str_contains($line, "['allowed_classes' => false]"),
                $file->getPathname() . ':' . ($lineNumber + 1) . ' permits object deserialization'
            );
        }
    }
}

securityHardeningCheck(Weblist::configTableName('cloud_') === 'cloud_configs', 'Valid database prefix rejected');
foreach (["site`;DROP TABLE users;--", '../site_', '', str_repeat('a', 57)] as $unsafePrefix) {
    securityHardeningCheck(Weblist::configTableName($unsafePrefix) === null, 'Unsafe table prefix accepted');
}

$adminAjax = file_get_contents($root . '/app/admin/controller/Ajax.php');
securityHardeningCheck(is_string($adminAjax), 'Unable to inspect admin Ajax controller');
securityHardeningCheck(
    str_contains($adminAjax, 'VALUES (:config_key, :config_value)'),
    'Site configuration writes are not parameterized'
);
securityHardeningCheck(
    !str_contains($adminAjax, "SET k='"),
    'Legacy injectable configuration query is still present'
);

$schema = file_get_contents($root . '/app/install/install.sql');
securityHardeningCheck(
    is_string($schema) && preg_match('/CREATE TABLE `cloud_configs`.*?ENGINE=InnoDB DEFAULT CHARSET=utf8mb4/s', $schema),
    'Configuration tables must use InnoDB and utf8mb4'
);

// --- Output encoding helpers -------------------------------------------------

securityHardeningCheck(
    !str_contains(safe_html('<script>alert(1)</script><p>hi</p>'), '<script'),
    'safe_html kept a script tag'
);
securityHardeningCheck(
    !str_contains(strtolower(safe_html('<p onclick="steal()">hi</p>')), 'onclick'),
    'safe_html kept an inline event handler'
);
securityHardeningCheck(
    !str_contains(strtolower(safe_html('<a href="javascript:alert(1)">x</a>')), 'javascript:'),
    'safe_html kept a javascript: URL'
);
securityHardeningCheck(
    str_contains(safe_html('<p><strong>ok</strong></p>'), '<strong>'),
    'safe_html dropped ordinary formatting'
);
// Browsers decode entities and strip tab/newline characters inside URL
// attributes before resolving the scheme, so a literal scheme match is not
// enough — every encoded or split variant must be stripped as well.
foreach ([
    '<a href="javascript&#58;alert(1)">x</a>',
    '<a href="javascript&#x3a;alert(1)">x</a>',
    "<a href=\"jav\tascript:alert(1)\">x</a>",
    "<a href=\"jav\nascript:alert(1)\">x</a>",
    '<a href=javascript&#58;alert(1)>x</a>',
    '<a href="jaVaScRiPt:alert(1)">x</a>',
    '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>',
    "<a href=\"\tjavascript:alert(1)\">x</a>",
] as $encodedUrl) {
    securityHardeningCheck(
        !preg_match('/(?:javascript|vbscript|data)\s*:/i', strtolower(safe_html($encodedUrl))),
        'safe_html kept an entity-encoded or split script URL'
    );
}
securityHardeningCheck(
    str_contains(safe_html('<a href="https://example.com/?a=1&amp;b=2">text</a>'), 'href="https://example.com/?a=1&amp;b=2"'),
    'safe_html rewrote a legitimate absolute link'
);
securityHardeningCheck(
    js_string("</script><img src=x onerror=alert(1)>") === '"\u003C\/script\u003E\u003Cimg src=x onerror=alert(1)\u003E"',
    'js_string did not neutralise a script break-out'
);
securityHardeningCheck(js_string("it's") === '"it\u0027s"', 'js_string did not escape a single quote');

securityHardeningCheck(safe_http_url('https://example.com/pay') === 'https://example.com/pay', 'A valid URL was rejected');
foreach (['javascript:alert(1)', 'data:text/html,<script>', 'ftp://x/y', '', 'not a url'] as $unsafeUrl) {
    securityHardeningCheck(safe_http_url($unsafeUrl) === '', 'An unsafe URL was accepted: ' . $unsafeUrl);
}

// --- Administrative account scope --------------------------------------------

$adminAjaxSource = $adminAjax;
foreach (['Users::adminUpdateByUid', 'Users::adminDelByUid', 'Users::adminFindByUid'] as $scopedCall) {
    securityHardeningCheck(
        str_contains($adminAjaxSource, $scopedCall),
        'Admin user management does not use the site-scoped accessor ' . $scopedCall
    );
}
securityHardeningCheck(
    !preg_match('/Users::(updateByUid|delByUid)\(/', $adminAjaxSource),
    'Admin user management still uses an unscoped user accessor'
);

$usersModel = file_get_contents($root . '/app/index/model/Users.php');
securityHardeningCheck(is_string($usersModel), 'Unable to inspect the users model');
securityHardeningCheck(
    str_contains($usersModel, "return (new static())->where('uid', '>', 0)->where('web_id', '=', 1);"),
    'Administrative queries are not limited to the primary installation'
);
securityHardeningCheck(
    str_contains($usersModel, 'hash_equals($token, $supplied)'),
    'The password reset token is not compared in constant time'
);
securityHardeningCheck(
    str_contains($usersModel, 'RESET_TOKEN_TTL'),
    'The password reset token has no expiry'
);
securityHardeningCheck(
    str_contains($usersModel, '用户名或密码错误'),
    'Login still distinguishes an unknown user from a wrong password'
);
securityHardeningCheck(
    str_contains($usersModel, 'Session::regenerate()'),
    'Login does not rotate the session id'
);

// --- Host header trust -------------------------------------------------------

$commonSource = file_get_contents($root . '/app/common.php');
securityHardeningCheck(is_string($commonSource), 'Unable to inspect the common helpers');
securityHardeningCheck(
    str_contains($commonSource, "config('web.domain')"),
    'get_Domain does not validate the Host header against the registered domain'
);
securityHardeningCheck(
    str_contains($commonSource, '$registered !== [] && in_array($host, $registered, true)'),
    'get_Domain still echoes an arbitrary Host when no domain is registered'
);

// --- Installer request boundary ----------------------------------------------

$installSource = file_get_contents($root . '/app/install/controller/Index.php');
securityHardeningCheck(is_string($installSource), 'Unable to inspect the installer');
securityHardeningCheck(
    str_contains($installSource, 'Request::isAjax(true)'),
    'The installer still accepts forged ?_ajax=1 markers as AJAX'
);
securityHardeningCheck(
    str_contains($installSource, 'is_cross_origin_request()'),
    'The installer does not reject cross-origin form submissions'
);

// --- 360webscan whitelist anchoring ------------------------------------------

require $root . '/extend/360webscan.php';
$webscanWhite = ['index.php' => 's=/admin/ajax/data'];
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PATH_INFO'] = '/index/ajax/notice';
$_SERVER['REQUEST_URI'] = '/index/ajax/notice?x=1&s=/admin/ajax/data';
$_SERVER['QUERY_STRING'] = 'x=1&s=/admin/ajax/data';
securityHardeningCheck(
    webscan_white('', $webscanWhite) === true,
    'A query string containing whitelisted text disabled the 360webscan filters'
);
$_SERVER['PATH_INFO'] = '/admin/ajax/data/add/notice';
$_SERVER['REQUEST_URI'] = '/admin/ajax/data/add/notice';
$_SERVER['QUERY_STRING'] = '';
securityHardeningCheck(
    webscan_white('', $webscanWhite) === false,
    'The 360webscan whitelist no longer covers real admin rich-text operations'
);

// --- Scheduler keys ----------------------------------------------------------

foreach (['Netease', 'Epic', 'Task', 'Bilibili'] as $cronController) {
    $source = file_get_contents($root . '/app/cron/controller/' . $cronController . '.php');
    securityHardeningCheck(is_string($source), 'Unable to inspect cron controller ' . $cronController);
    securityHardeningCheck(
        !preg_match('/\$(?:cronkey|cronKey|data\[\'runkey\'\])\s*[!=]=\s*(?:config|RUN_KEY)/', $source),
        $cronController . ' compares its scheduler key without hash_equals'
    );
}

$cronNetease = file_get_contents($root . '/app/cron/controller/Netease.php');
securityHardeningCheck(
    !str_contains($cronNetease, 'csrf=') && !str_contains($cronNetease, 'musicu='),
    'The NetEase scheduler still puts account cookies in a URL'
);
securityHardeningCheck(
    str_contains($cronNetease, 'in_array($do, self::TASKS, true)'),
    'The NetEase scheduler still dispatches an arbitrary method name'
);

// --- Cookie hardening --------------------------------------------------------

$cookieConfig = require $root . '/config/cookie.php';
securityHardeningCheck($cookieConfig['httponly'] === true, 'Session cookies are readable by JavaScript');
securityHardeningCheck($cookieConfig['samesite'] === 'Lax', 'Session cookies are sent on cross-site requests');

echo "Security hardening tests passed\n";
