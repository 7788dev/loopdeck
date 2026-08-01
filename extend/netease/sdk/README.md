# NetEase Cloud Music PHP SDK

This directory contains the project's native PHP implementation of the request
layer from `NeteaseCloudMusicApiEnhanced/api-enhanced` 4.39.0, commit
`6732fc7c32518ee481b089e2a40f488c28729054`.

Supported request modes:

- `api`
- `weapi` (AES-CBC plus RSA)
- `eapi` (AES-ECB plus request digest)
- `linuxapi`
- `xeapi` (X25519, AES-GCM, AES-ECB, session reuse, and encrypted responses)

The client also handles device cookies, anonymous `MUSIC_A` registration,
XEAPI public-key refresh, v3 anti-cheat tokens, response cookies, proxy settings,
and persistent protocol state. The existing `netease\Netease` class is the
project-specific compatibility facade and sends all NetEase requests through
this SDK.

VIP growth coverage in the compatibility facade follows the upstream 4.39.0
modules: growth summary/details, legacy and v1 task lists, targeted/all reward
claims, Black Vinyl LeQian sign/detail/history/info, and the Black Vinyl time
machine. `vip_growth_task()` combines the non-destructive daily actions into the
project scheduler.

Basic use:

```php
use netease\sdk\Client;

$client = new Client([
    'user_id' => $userId,
    'csrf' => $csrf,
    'music_u' => $musicU,
]);

$response = $client->request(
    '/api/v1/discovery/recommend/resource',
    [],
    'weapi'
);
```

Verification:

```text
php tests/NeteaseSdkTest.php
php tests/NeteaseWorkflowTest.php
php tests/NeteaseLiveSmoke.php
```

The first two commands are fully offline. The last performs read-only protocol
checks against NetEase and prepares, but does not send, a comment request.
