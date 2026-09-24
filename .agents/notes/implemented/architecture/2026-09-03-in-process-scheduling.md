# Agent Note: 调度改为进程内执行,废除 URL 自调用

Status: implemented

## Problem

早期各平台(NetEase/Bilibili/Heybox/Epic)的 cron 控制器通过 HTTP 自调用触发任务,把 `RUN_KEY` 甚至账号凭据放进 URL 查询串。2026-09 维护验证发现 `/cron/common/statusTag` 这类公共辅助方法在无密钥时也返回 200——密钥会进入访问日志与错误日志,未在白名单声明的辅助方法还可被当作 HTTP 操作直接访问。

## Decision

任务执行改为进程内:`cron/` 控制器与 [app/service/AutomaticSchedule.php](../../../../app/service/AutomaticSchedule.php) 直接实例化并运行任务类,不再发起任何 HTTP 自调用;只有任务名白名单内的类可被调度。HTTP 边界仍然存在但已锁死:整个 cron 应用要求鉴权,并强制路由白名单。验证证据见 [docs/MAINTENANCE-2026-09.md](../../../../docs/MAINTENANCE-2026-09.md)「调度入口」一行。落地提交:`1221e9e`(2026-09-03)。

## Alternatives considered

**保留 HTTP 自调用,只给关键端点补 token 校验。** 输因:补丁式校验覆盖不了"公共辅助方法被自动路由暴露"这一类问题(statusTag 实测无密钥返回 200),URL 携带密钥的泄漏面依旧存在;进程内执行从结构上消除了这条链路,而不是逐个堵洞。

## Consequences

买到的:密钥不再出现在任何 URL;单任务上游失败被 `Throwable` 捕获并按 `[重试中]` 记录,不再中断整轮调度。付出的:新增调度任务必须同时更新任务名白名单;调度与 Web 共享同一进程与部署,任务异常的影响面与请求处理耦合。
