# Agent Note: 调度器重试文案与计数契约（只在真正安排了重试时承诺重试）

Status: implemented

## Problem

有界重试（`SchedulerRetry`，`_scheduler_retry` 计数持久化在 job data）落地后，各调度入口的失败路径仍会在"重试根本没有安排"时写下「已安排稍后重试」的 `[重试中]` 文案：

- `retryJob`/`scheduleRetry` 的终态写入（cap 到达）落空（租约被接管）时返回 true，调用方随即写 `[重试中]`；
- 账号在长任务执行期间失去执行计划（`timing` 清空）时，任务被暂停（`nextExecute=0`），调用方仍写 `[重试中]`——违反「"将自动重试"只允许出现在真正按分钟级重试的结果」的仓库文案规则；
- 非终态重排写入的结果被忽略，写入落空（用户接管）同样承诺了不会发生的重试，且该次失败计数也随之丢失；
- 成功运行不清 `_scheduler_retry`：一个偶发成功夹在异常之间的任务仍会累计到 cap 被停用，终态记录声称"N 次连续失败"与事实不符；
- `Info::recordRun` 等统计写在计数路径内，一次统计失败把成功的上游执行变成调度失败（重复执行 + 计入失败计数）；
- 账号停用清扫不过滤 `state=1`：用户手动停用（`state=0`）的任务被翻成 `-1`（账号失效），`Jobs::updateJob` 在下次保存账号时复活它们。

## Decision

- `retryJob`（Task.php）/`scheduleRetry`（cron Netease）返回值收紧为：**true 当且仅当重排写入落地且 `nextExecute` 指向未来**。终态（cap）、任务暂停、写入落空一律返回 false，调用方据此跳过 `[重试中]` 文案与通知；终态记录仍由 retryJob 自身写入。`tests/SchedulerRetryCapTest.php` M2 段钉死三种"没有安排重试"的场景（接管、暂停、失效）。
- CLI 入口（command/Netease.php）补上中间 `[重试中]` 日志（经 `shouldReportTaskStatus` 门控，与 cron 入口一致），终态日志写入纳入批次异常边界。
- 所有"返回了结果"的完成写入（Task.php netease、cron Netease、Bilibili `jobUpdates`、Epic 成功路径、CLI Netease）通过 `SchedulerRetry::dataAfterSuccess()` 丢弃计数器；`tests/SchedulerRetryCapTest.php` M3/M5 段钉死。
- `Info::recordRun` 在 cron/CLI 四个入口全部包上 try/catch（统计尽力而为），不再进入重试计数。
- 账号停用/失效清扫（Task.php 两处、command Netease/Bilibili 的 accountInvalid）统一加 `->where('state', 1)`，与 `Common::accountInvalid` 对齐；`tests/SchedulerRetryCapTest.php` M4 段用功能断言钉死"手动停用的任务保持停用"。
- cron Netease/Bilibili 批次循环与 cron Epic 循环补上 per-job `catch (Throwable)`，一次查询/记账失败不再中断整批。

## Alternatives considered

**保持"返回 false 仅表示终态"的原语义，让调用方区分终态/落空。** 输了：调用方需要第二套判断，而所有调用方真正关心的只有一件事——"要不要承诺重试"。收紧为一个语义后契约可以被一句话表述并被测试钉死。

**在重排写入落空时降级为无条件写入（绕过租约比较）。** 输了：会覆盖用户刚保存的计划，正是租约模型要防的事故。

**成功路径不额外写 data（只在失败时计数）。** 输了：连续失败计数会跨越偶发成功累计，终态文案失实；完成写入本来就在同一 UPDATE 里，附带 data 字段没有额外查询成本。

## Consequences

调度入口在"任务被接管/暂停/失效"时静默（不写任何日志），与 claim 期的 unscheduled 处理一致。计数器在丢失一次写入时会少计一次——可接受：宁可提前一个窗口重置，也不能写出失实的"连续失败 N 次"。文案契约由 SchedulerRetryCapTest 全链路钉死（3 条 `[重试中]` + 1 条 `[失败]` 终态、接管场景零新增日志）。
