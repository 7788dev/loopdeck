# Douyin QR login protocol client

This module runs the QR login flow with direct HTTPS requests. PHP owns the
HTTP transport and cookie session. A local Node.js VM loads the pinned BDMS and
DTrait bundles to append `a_bogus` and construct `x-tt-session-dtrait`; it does
not start Chromium, CDP, WebDriver, or a network bridge.

## Requirements

- PHP 8.1 or newer with Guzzle 7
- Node.js 18 or newer available as `node`
- `proc_open` enabled for PHP

Set `DOUYIN_NODE_BINARY` when Node is installed under another executable path.

## Server-side flow

```php
use douyin\sdk\Client;

$client = new Client();
$qr = $client->qrGenerate();
$state = $client->state();

// Persist $state on the server. Do not send it to the browser.
$client = new Client(config: ['state' => $state]);
$poll = $client->qrPoll();
$public = $client->publicState();
$state = $client->state();

if ($public['authenticated']) {
    $credentials = $client->credentials();
}
```

`state()` contains cookies, CSRF, `msToken`, the login-scoped portrait ID and
DTrait key, QR token, frontier mode, expiry, and the latest status.
`publicState()` contains only browser-safe status data.
The controller stores full state in the current ThinkPHP session and gives the
browser a random opaque ID.

Statuses are normalized to `new`, `scanned`, `confirmed`, `refused`, and
`expired`. A supported HTTPS URL in `captcha` or `desc_url` is rendered in the
local modal. `bdturing-verify` and `x-vc-bdturing-parameters` response payloads
are rendered by the pinned `douyin-captcha-runtime.js` asset. Polling continues
while the user completes either verification mode.

## Signing boundary

`PassportSigner` implements `sign`, `qs`, `p_no`, source-info encoding, and
`x-tt-passport-aid-sign` in PHP. `NodeSigner` passes an unsigned passport URL,
form body, and a server-side login key to `runtime/worker.cjs`. The worker
accepts only `https://login.douyin.com/passport/` and returns the signed URL plus
the allowlisted DTrait header. All XHR, fetch, image, and DOM resource loads
inside the VM are inert.

The client sends one UUIDv4 `.login` portrait ID throughout a QR session and
keeps the DTrait key in server-side state. It does not use WebSocket frontier
transport, a browser cookie bridge, or CDP.

## Live smoke test

Run the opt-in network smoke test to verify QR generation, server-state
serialization, and polling against the current upstream protocol:

```shell
php tests/DouyinLiveSmoke.php
```

The script prints only a redacted summary. It does not expose the QR token,
cookies, DTrait key, portrait ID, or `msToken`.
