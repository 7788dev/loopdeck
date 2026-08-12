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

securityHardeningCheck(Weblist::configTableName('siteabc_') === 'siteabc_configs', 'Valid site prefix rejected');
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

$siteSchema = file_get_contents($root . '/public/static/site.sql');
securityHardeningCheck(
    is_string($siteSchema) && str_contains($siteSchema, 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'),
    'New site configuration tables must use InnoDB and utf8mb4'
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
securityHardeningCheck(
    js_string("</script><img src=x onerror=alert(1)>") === '"\u003C\/script\u003E\u003Cimg src=x onerror=alert(1)\u003E"',
    'js_string did not neutralise a script break-out'
);
securityHardeningCheck(js_string("it's") === '"it\u0027s"', 'js_string did not escape a single quote');

securityHardeningCheck(safe_http_url('https://example.com/pay') === 'https://example.com/pay', 'A valid URL was rejected');
foreach (['javascript:alert(1)', 'data:text/html,<script>', 'ftp://x/y', '', 'not a url'] as $unsafeUrl) {
    securityHardeningCheck(safe_http_url($unsafeUrl) === '', 'An unsafe URL was accepted: ' . $unsafeUrl);
}

// --- Payment callbacks -------------------------------------------------------

$settlement = file_get_contents($root . '/app/service/PaymentSettlement.php');
securityHardeningCheck(is_string($settlement), 'Unable to inspect the payment settlement service');
securityHardeningCheck(
    str_contains($settlement, "(string)\$order['shop'] !== \$shop"),
    'A signed callback is not bound to the product recorded on the order'
);
securityHardeningCheck(
    str_contains($settlement, "->where('status', '=', 0)"),
    'The order is not claimed with a conditional update, so a replay can grant twice'
);
securityHardeningCheck(
    !str_contains($settlement, "Session::get"),
    'Settlement still reads the beneficiary from the session instead of the order'
);

$epay = file_get_contents($root . '/app/index/controller/Epay.php');
securityHardeningCheck(is_string($epay), 'Unable to inspect the payment controller');
securityHardeningCheck(
    !str_contains($epay, "cookie('siteUrl')"),
    'The sub-site domain is still taken from a client controlled cookie'
);
securityHardeningCheck(
    !str_contains($epay, "\$_SERVER['HTTP_HOST']"),
    'Gateway callback URLs are still built from the Host header'
);
securityHardeningCheck(
    str_contains($epay, "->where('uid', '=', Session::get('user.uid'))"),
    'Order lookup on the payment submit page is not scoped to the caller'
);

// --- Tenant isolation --------------------------------------------------------

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
    str_contains($usersModel, "->where('web_id', '=', defined('WEB_ID') ? (int)WEB_ID : -1)"),
    'Site-scoped administrative queries are missing their tenant filter'
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
    str_contains($usersModel, "->where('money', '>=', \$amount)")
        && str_contains($usersModel, "->dec('money', \$amount)"),
    'Balance spending is not a single conditional statement'
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

// --- Scheduler keys ----------------------------------------------------------

foreach (['Netease', 'Heybox', 'Epic', 'Task', 'Bilibili'] as $cronController) {
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
