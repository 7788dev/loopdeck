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

echo "Security hardening tests passed\n";
