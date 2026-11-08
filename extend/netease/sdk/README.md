# NetEase Cloud Music PHP SDK

This directory contains the project's native PHP implementation of the request
layer from `NeteaseCloudMusicApiEnhanced/api-enhanced` 4.41.1, commit
`2db6684453297ee86dec0492842f2320b08182d9`.

Supported request modes:

- `api`
- `weapi` (AES-CBC plus RSA)
- `eapi` (AES-ECB plus request digest)
- `linuxapi`
- `xeapi` (4.41.1 CSR by default with legacy BSR fallback; X25519, AES-GCM,
  AES-ECB, session reuse, and encrypted responses)
- desktop NCBL v3 client logs (`_plv`/`_pld`, ChaCha20, RSA key wrap,
  gzip framing, and verified multipart uploads)

The client also handles device cookies, anonymous `MUSIC_A` registration,
XEAPI public-key refresh, v3 anti-cheat tokens, response cookies, proxy settings,
and persistent protocol state. The existing `netease\Netease` class is the
project-specific compatibility facade and sends all NetEase requests through
this SDK.

XEAPI uses the upstream 4.41.1 CSR shape by default: the key handshake posts
to `/api/bsr/sk/get`, form bodies travel as a UTF-8 `content` field, the mask
rotates raw bytes, and the outer ciphertext is returned as unpadded base64url
in `C`. A CSR key-handshake failure falls back to the legacy BSR key endpoint
and BSR request shape before the business request is sent; a business request
is never replayed across protocols. Set `xeapi_protocol` to `bsr` to force the
legacy shape, `auto` to try CSR then BSR, or `xeapi_fallback` to `false` to
surface CSR key failures.

CSR omits `content` and `body` for an empty payload and follows the upstream
`URLSearchParams` encoding, including unescaped `*`. Fetched and cached keys
are validated as usable X25519 points before encryption, so malformed keys
can still use the handshake-only fallback. The regression suite includes
frozen upstream `C`/`S`/`R` vectors rather than only local round trips.

VIP growth coverage in the compatibility facade follows the upstream 4.40.1
modules (unchanged in 4.41.1): growth summary/details, legacy and v1 task
lists, targeted/all reward claims, Black Vinyl LeQian sign/detail/history/info,
and the Black Vinyl time machine. `vip_growth_task()` combines the
non-destructive daily actions into the project scheduler.

音乐人任务协议已核对至 api-enhanced 4.40.1 的
`a8c781fd64faab17fedfd46e0615a2609307f163`（2026-09-12）：周期/阶段任务、
登录音乐人中心和云豆领奖继续使用 WEAPI。项目兼容层检查子任务及领奖结果，
保留 XEAPI v3 分享/评论与 EAPI 私信协议。

合伙人每日评分参考 `ACAne0320/ncmp` 的
`0517539fa44d226a3b9cf7d6a68683f08aaa2ba3` 中 `src/core/tasks/daily.py` 和
`src/core/signer.py`：通过 `interface.music.163.com` 的原始 GET 获取每日任务，
通过同域名的 WEAPI 发送评分、标签和附加字段。CSRF 放入加密请求体；
不添加 URL 中的凭据，`syncYunCircle=false` 保持评分不自动发布到云圈。
每次评分前等待 15–20 秒，并重新读取每日任务确认完成状态。

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
php tests/NeteaseXeapiProtocolTest.php
php tests/NeteaseXeapiKeyValidationTest.php
php tests/NeteaseNcblTest.php
php tests/NeteaseWorkflowTest.php
php tests/NeteaseCreatorTasksTest.php
php tests/NeteaseCreatorLiveSmoke.php
php tests/NeteaseLiveSmoke.php
```

The first six commands are fully offline. `NeteaseCreatorLiveSmoke.php` only
reads musician and partner task lists; without an account it verifies login
challenges, not successful task execution. The last command disables BSR fallback
and reads a real encrypted CSR task-list response. It prepares comment/share
requests without sending them. Supplying `NETEASE_MUSIC_U`, `NETEASE_CSRF`, and
`NETEASE_USER_ID` also verifies the logged-in account through a read-only level
request; session values never enter its output. Without an account it checks
anonymous registration with a stable guest-device cache.
