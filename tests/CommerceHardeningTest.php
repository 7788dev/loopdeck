<?php

declare(strict_types=1);

/**
 * Regression tests for the card / payment hardening pass.
 *
 * Covers: the agent-card "free mint" chain (agent_add value whitelist, missing
 * price key rejection, zero-amount balance spend), Submit_Pay shopid
 * whitelisting, payment settlement amount enforcement, callback signature
 * comparison and the password-reset token length mismatch.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

function hardeningCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);

// --- Kms: agent card minting -------------------------------------------

$kmsSource = file_get_contents($root . '/app/index/model/Kms.php');
hardeningCheck(is_string($kmsSource), 'Unable to inspect the kms model');

// The agent card value must be one of the three defined levels.
hardeningCheck(
    str_contains($kmsSource, "in_array(\$value, [1, 2, 3], true)"),
    'agent card values are not whitelisted to levels 1-3'
);
// A missing price key must be rejected, not cast to 0.
hardeningCheck(
    str_contains($kmsSource, "if (!is_numeric(\$unit))"),
    'a missing card price key can still resolve to a free purchase'
);
// A missing agent discount must fall back to full price, never to 0.
hardeningCheck(
    str_contains($kmsSource, '$zk <= 0') && str_contains($kmsSource, '$zk = 10.0;'),
    'an unconfigured agent discount can still zero out the price'
);
// Only agents may mint cards at all.
hardeningCheck(
    str_contains($kmsSource, "您还不是代理"),
    'non-agents can still reach the card minting pricing code'
);
// Redemption must also reject out-of-range card values.
hardeningCheck(
    str_contains($kmsSource, 'cardValueValid((string)$row[\'type\'], (string)$row[\'value\'])'),
    'card redemption does not validate the stored card value'
);

// --- Pays: order creation ----------------------------------------------

$paysSource = file_get_contents($root . '/app/index/model/Pays.php');
hardeningCheck(is_string($paysSource), 'Unable to inspect the pays model');

// shopid must be a numeric product id before any price lookup.
hardeningCheck(
    str_contains($paysSource, "ctype_digit((string)\$data['shopid'])"),
    'order creation does not reject non-numeric shop ids'
);
// Agent orders must be limited to the three defined levels.
hardeningCheck(
    str_contains($paysSource, "in_array((int)\$data['shopid'], [1, 2, 3], true)"),
    'agent orders are not whitelisted to levels 1-3'
);
// Every non-money product must resolve a positive server-side price.
hardeningCheck(
    str_contains($paysSource, "(!is_numeric(\$res_money) || (float)\$res_money <= 0)"),
    'orders can still be created for unpriced products'
);
// Site name travels into the gateway form, so it must be bounded.
hardeningCheck(
    str_contains($paysSource, 'mb_strlen($webname) > 40'),
    'the sub-site name is not length-checked before entering the order'
);

// --- PaymentSettlement --------------------------------------------------

$settleSource = file_get_contents($root . '/app/service/PaymentSettlement.php');
hardeningCheck(is_string($settleSource), 'Unable to inspect the payment settlement service');

// A callback without an amount must fail, not pass.
hardeningCheck(
    str_contains($settleSource, '|| !is_numeric($callbackAmount))'),
    'a callback without an amount is still accepted'
);
// The granted agent level must be one of the defined levels.
hardeningCheck(
    str_contains($settleSource, 'in_array($level, [1, 2, 3], true)'),
    'settlement can still write an undefined agent level'
);

// --- epay signature -----------------------------------------------------

$coreSource = file_get_contents($root . '/extend/epay/AliPayCore.php');
hardeningCheck(is_string($coreSource), 'Unable to inspect the epay core');

// The MD5 signature comparison must be constant-time.
hardeningCheck(
    str_contains($coreSource, 'hash_equals($mysgin, $sign)'),
    'the gateway signature is compared with == instead of hash_equals'
);

// The auto-generated payment form must escape attribute values.
$submitSource = file_get_contents($root . '/extend/epay/AlipaySubmit.php');
hardeningCheck(is_string($submitSource), 'Unable to inspect the epay submit class');
hardeningCheck(
    str_contains($submitSource, "htmlspecialchars((string)\$val, ENT_QUOTES, 'UTF-8')"),
    'the payment form does not escape hidden field values'
);

// --- Users: balance and reset link --------------------------------------

$usersSource = file_get_contents($root . '/app/index/model/Users.php');
hardeningCheck(is_string($usersSource), 'Unable to inspect the users model');

// Zero-amount debits must be refused (price key holes resolved to 0 before).
hardeningCheck(
    str_contains($usersSource, '0 元放行'),
    'spendBalance still treats a zero amount as a successful purchase'
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

foreach (['Bilibili', 'Heybox', 'Epic'] as $controller) {
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
