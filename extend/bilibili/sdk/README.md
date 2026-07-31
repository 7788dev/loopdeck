# Bilibili PHP SDK

This directory contains the native PHP protocol client used by this project.
It replaces the old static `Curl` and `Sign` helpers with an injectable HTTP
transport, cookie session management, WBI signing, APP compatibility signing,
and named methods for every Bilibili workflow used by the application.

## Requirements

- PHP 8.1 or newer
- Guzzle 7 (already provided by the project Composer dependencies)
- HTTPS access to the Bilibili API domains

## Login

Password login is intentionally not supported. Use the current web QR flow or
the SMS flow:

```php
use bilibili\sdk\Client;

$client = new Client();
$qr = $client->qrGenerate();
$key = $qr['data']['qrcode_key'];

// Poll every two seconds until data.code is 0, 86038, 86090, or 86101.
$status = $client->qrPoll($key);
if (($status['data']['code'] ?? null) === 0) {
    $cookies = $client->cookies();
    $profile = $client->nav();
}
```

Successful login captures `DedeUserID`, `DedeUserID__ckMd5`, `SESSDATA`,
`bili_jct`, and `sid` from response `Set-Cookie` headers. The application stores
these values in the existing Bilibili account record and never passes them in a
Cron URL.

## Supported Project Workflows

The client exposes the protocols required by the configured project tasks:

- Account validation and profile: `nav()`
- QR and SMS login: `qrGenerate()`, `qrPoll()`, `captcha()`, `smsSend()`, `smsLogin()`
- Main-site tasks: daily reward state, popular/dynamic video selection, WBI video
  detail, watch start, heartbeat, history report, share, and coin. Coin requests
  establish the documented web device session (`buvid3`, `b_nut`, `buvid4`, and
  `bili_ticket`) before submitting the action.
- Manga tasks: clock-in and share using the Android platform parameter
- Legacy live task compatibility: daily bag, online heart, support-group sign-in,
  heart gift, live sign state, and silver-to-coin

Some legacy live protocols remain exposed so existing task names continue to
produce an accurate upstream result. They are not treated as successful when
Bilibili reports that the activity or endpoint is unavailable. In particular,
the upstream documentation marks live `DoSign` as offline.

## Tests

Run the protocol and project-workflow suites from the repository root:

```shell
php tests/BilibiliSdkTest.php
php tests/BilibiliWorkflowTest.php
```

The transport is injected through `TransportInterface`, so tests assert the
real HTTP method, endpoint, query/body fields, signatures, cookie propagation,
and all configured task workflows without using a real account.

## Attribution

Protocol research and field definitions are adapted from
`bilibili-API-collect`. See [NOTICE.md](NOTICE.md) before redistributing or using
this SDK. The referenced material is licensed under CC BY-NC 4.0 and prohibits
commercial use.
