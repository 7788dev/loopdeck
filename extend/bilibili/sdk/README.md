# Bilibili PHP SDK

本目录是 LoopDeck 使用的 PHP 协议客户端，包含可注入的 HTTP 传输、Cookie 会话、WBI 签名、APP 兼容签名和具名任务接口。

## 环境与登录

- PHP 8.2+、项目锁定的 Guzzle 依赖，以及访问 B 站 API 的 HTTPS 网络。
- 使用扫码或短信登录，不支持账密登录，也不通过第三方服务换取 APP 凭据。

```php
use bilibili\sdk\Client;

$client = new Client();
$qr = $client->qrGenerate();
$key = $qr['data']['qrcode_key'];

// 每两秒查询，直到确认登录、二维码失效或取消。
$status = $client->qrPoll($key);
if (($status['data']['code'] ?? null) === 0) {
    $cookies = $client->cookies();
    $profile = $client->nav();
}
```

扫码/短信沿用当前 Web MiniLogin 的 `main-fe-header` 来源；短信发送到验证阶段复用设备 Cookie。仅当 Web 发送明确返回升级错误时，才使用兼容的 APP 短信协议（Android 9.5.0 / build 9050300，2026-08-02 核查）。成功登录保存原账号所需的 Cookie 及可用的 access/refresh token，不放入 Cron URL。

## 任务与到账

- `nav()` 校验账号；扫码、验证码和短信分别由 `qrGenerate()`、`qrPoll()`、`captcha()`、`smsSend()`、`smsLogin()` 提供。
- 主站任务读取每日奖励、实时投币经验和视频信息，使用 WBI 签名的观看开始/心跳及投币协议。观看、投币沿用现有 Web 设备会话准备。
- `vipPrivilege()` 是纯 GET，携带账号、设备及与 SDK Windows UA 一致的平台值；`claimVipExperience()` 提交 `mid`、`buvid`、`csrf`，工作流核对 type 9、领取条件及 `is_grant`。
- `VipPoints` 根据当前任务中心选择新版 JSON `sign2` 或旧活动表单 `sign`，只完成 `bonus`、`privilege`、`dress-view` 三个已观测的积分任务。不接受任意任务代码或上游跳转地址。
- 漫画签到/分享保留 Android 参数。银瓜子兑换保留 PC 与 APP 端点；余额不足如实失败。
- 旧礼包、直播心跳、应援团、心跳礼物、直播签到和主站分享协议已删除，仅保留拒绝名称及历史展示。

HTTP 接受请求不等于奖励到账。基础经验使用明确的登录/观看奖励及实时投币经验；大会员经验和积分分别确认。积分工作流在提交后重新读取签到、任务状态与余额，日志不采用 POST 返回的预估积分。

观看、每日经验、大会员经验和积分复用 `_bilibili_verification`，最多 24 次、间隔 300 秒，跨日或耗尽后结束。`claim_submitted` 标记经验领取阶段，`points_submitted` 按积分子任务记住已提交操作；暂时读取失败仍保留核验进度。后续检查不重复已提交动作，尚未领取且后来就绪的子任务可以首次提交。明确风控拒绝会终止本轮自动任务，等待用户在官方客户端完成验证。

## 验证与来源

```shell
php tests/BilibiliSdkTest.php
php tests/BilibiliWorkflowTest.php
php tests/BilibiliVipPointsTest.php
php tests/BilibiliSettlementTest.php
php tests/BilibiliExperienceSummaryTest.php
```

测试经 `TransportInterface` 注入响应，检查真实请求构造、结算与隔离数据库调度，不需要真实账号。测试环境需启用 `pdo_sqlite`。

历史协议来源和许可见 [NOTICE.md](NOTICE.md)。2026-11-11 的更新来自安装的桌面客户端、其会员中心官方脚本，以及授权账号的实际回查，详见 [协议核查记录](../../../docs/research/bilibili-desktop-2026-11-11.md)。未分发官方脚本或客户端二进制。
