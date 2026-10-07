<?php

declare(strict_types=1);

/**
 * Regression tests for card validation and account security.
 *
 * Covers card value validation and the password-reset token length mismatch.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

function hardeningCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);

// --- Kms: card redemption ---------------------------------------------

$kmsSource = file_get_contents($root . '/app/index/model/Kms.php');
hardeningCheck(is_string($kmsSource), 'Unable to inspect the kms model');

// Redemption must also reject out-of-range card values.
hardeningCheck(
    str_contains($kmsSource, 'RedemptionPlan::fromCard((string)$row[\'type\'], (string)$row[\'value\'])'),
    'card redemption does not validate the stored card value'
);

// --- Login: reset token length ------------------------------------------

$loginSource = file_get_contents($root . '/app/index/controller/Login.php');
hardeningCheck(is_string($loginSource), 'Unable to inspect the login controller');

// findPass mints 48 hex chars (random_bytes(24)); the GET gate must agree.
hardeningCheck(
    str_contains($loginSource, 'strlen((string)$token) !== 48'),
    'the reset page still expects a 32-char token while mails carry 48'
);
hardeningCheck(
    str_contains($loginSource, 'hash_equals((string)$user[\'sid\']'),
    'the reset page still compares the token with !='
);

// --- Cron: no RUN_KEY in URLs -------------------------------------------

foreach (['Bilibili', 'Epic'] as $controller) {
    $source = file_get_contents($root . '/app/cron/controller/' . $controller . '.php');
    hardeningCheck(is_string($source), 'Unable to inspect cron controller ' . $controller);
    hardeningCheck(
        !preg_match('/runkey.*RUN_KEY|RUN_KEY.*runkey/s', $source)
        || !preg_match('/\?[^\'"]*runkey=/i', $source),
        $controller . ' still propagates RUN_KEY through a query string'
    );
    hardeningCheck(
        !str_contains($source, 'getExecuteUrl'),
        $controller . ' still dispatches work over HTTP self-calls'
    );
}

// The epic notify endpoint must no longer accept an arbitrary recipient.
$epicSource = file_get_contents($root . '/app/cron/controller/Epic.php');
hardeningCheck(
    !str_contains($epicSource, 'send_mail($data[\'user_id\']'),
    'the epic notify endpoint still mails a GET-supplied recipient'
);

echo "Commerce hardening tests passed\n";
