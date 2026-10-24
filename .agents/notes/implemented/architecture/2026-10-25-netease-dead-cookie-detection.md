# Agent Note: 网易云任务必须识别真实上游返回的登录失效形态

Status: implemented

## Problem

生产实例中一个 cookie 已过期的网易云账号（MUSIC_U 失效）连续 30+ 天每天执行 `vip_growth_task` 并产出 `[失败] 乐签打卡失败；黑胶时光机浏览失败；…；当前成长值0`。账号从未被标记失效，用户也从未收到「需要重新扫码登录」的提示。真实上游探针（2026-10-25，生产容器内、真实凭据）确认了失效会话的返回形态：

- `/api/w/nuser/account/get` 返回 **HTTP 200 + `account: null`**（不是 301）——`login_work` 原先只匹配 301，会把死会话判成「登录成功」。
- `/api/vipnewcenter/app/level/growhpoint/basic` 对死会话仍返回 200，但 `data.userLevel` 是匿名模板：`userId: 0`、`vipType: -1`、`growthPoint: 0`、`normal: true`——原先的会员校验全部放行，随后每个子接口 301。
- 其余 VIP 接口（`task/list`、`mission/user/progress/list`、`weekflow`、`checkin/history/detail`）对死会话返回 301。

## Decision

- `vip_growth_task`：进入时若 `userLevel.userId` 存在且为 0（匿名模板），置 `cookiezt` 并返回「登录已失效，请重新扫码登录」；子步骤读到 301/401 同样置位。
- `login_work`：仅 `code` 为 301/401，或 `code` 为 200 且 `account` 缺失/非数组时判失效。**其余形态（传输失败、5xx、无法解码）不得置 `cookiezt`**——否则一次网络抖动就会把健康账号连同其全部任务标记为 -1 并误发失效通知；这些形态返回「登录状态检查失败」。
- `yunbei_sign` 的签到状态探测、`musicTaskFailure`（`evaluate`/`musician_task` 共用失败出口）对 301/401 同样映射为登录失效，与 `sign`、`daka_new` 已有检测对齐。至此网易云白名单 7 个任务的失效检测全覆盖。

`cookiezt` 置位后由 cron 控制器与 CLI 命令统一走 `accountInvalid`（账号置 0、任务置 -1、发通知），无需任务自行写日志。

## Alternatives considered

- **在每个子接口调用点各自判断 301**：调用点数十处，遗漏面大；入口一次性判定 + 共用失败出口集中处理即可覆盖。
- **`login_work` 对任何非 200 都判失效**：传输失败会误杀健康账号，见上。
- **用 `vipType === -1` 作为匿名模板信号**：未登录用户与失效会话都可能给出 -1，`userId === 0` 语义更精确（登录用户该字段必为其真实 UID）。

## Consequences

- 失效账号在下一个调度周期被标记并通知，停止每日无意义失败日志；用户重新扫码后恢复正常。
- 回归测试 `tests/NeteaseLoginExpiryTest.php` 固化全部上游形态（含传输失败不误判）。
