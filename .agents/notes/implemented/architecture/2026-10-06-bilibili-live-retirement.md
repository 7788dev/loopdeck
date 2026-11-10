# Agent Note: 停用旧直播任务，保留有效日常任务

Status: implemented

## Problem

旧任务中的「双端心跳（姥爷直播经验）」「每日礼包」「心跳礼物」来自旧直播权益体系。旧接口返回 code=0 和空列表，不能证明当前仍有对应收益；也不能只因当前账号没有奖励，就断言所有旧权益接口已下线。

本次查到的官方依据：

- [老爷购买功能维护公告，2020-09-14](https://link.bilibili.com/p/eden/news#/newsdetail?id=1433)：暂停月费、年费老爷购买及续费，已购买的权益继续保留。不能假设主站大会员拥有直播老爷权益。
- [银瓜子宝箱下线公告，2020-08-19](https://link.bilibili.com/p/eden/news#/newsdetail?id=1385)：宝箱在 2020-08-26 下线，对剩余老爷期限一次性补偿。该公告针对宝箱，不等于银瓜子兑换硬币下线。
- [粉丝团-小心心功能升级公告，2022-05-13](https://link.bilibili.com/p/eden/news#/newsdetail?id=2780)：2022-05-23 起小心心改为对应直播间专属互动道具，不再作为背包礼物送出，需要加入对应主播粉丝团。
- [粉丝团入团及升级功能调整，2022-07-13](https://link.bilibili.com/p/eden/news#/newsdetail?id=2886)：列明入团条件；不能在没有用户指示的情况下替账号付费入团。
- [粉丝勋章亲密度升级，2025-08-11](https://link.bilibili.com/p/eden/news#/newsdetail?id=4644) 及 [实际上线说明，2025-09-11](https://link.bilibili.com/p/eden/news#/newsdetail?id=4722)：现行普通观看奖励为每五分钟 6 亲密度，每日最多 30；不能继续使用旧的 100/300/1500 数值。
- [用户等级 UL 相关成就下线公告，2024-03-20](https://link.bilibili.com/p/eden/news#/newsdetail?id=3998)：保留已获得成就，不再提供新的 UL 成就获取途径；它没有声明一切直播等级接口都失效。

## Decision

用户选择停用过时直播项，不接入新粉丝团任务。`BilibiliTaskExecutor::OFFLINE_TASKS` 增加每日礼包、双端心跳、友爱社签到、心跳礼物及 globalroom 配置。原任务与日志保留，所有调度入口、补挂、开关、账号刷新和定时修改沿用统一停用名单，不能重新激活。

2026-11-11 用户进一步要求移除所有已下线功能的前端展示。用户账号页与管理端任务列表共用 `Tasks::visibleQuery()`：B 站仅列出执行器支持的任务，已移除平台的旧任务定义不返回。列表先同步内置任务与已知旧描述，避免先打开管理端时显示旧分享文案；保留自定义说明与状态。控制台删除「已下架」占位行、停用原因与禁用开关；旧管理编辑入口也不能再读取这些定义。管理员主动关闭的有效任务仍在管理端显示。

主站、漫画及银瓜子兑换仍可执行，调度器不再读取旧直播间配置，避免已停用配置损坏影响有效任务。任务定义、配置与日志数据继续保留，旧请求仍由执行边界的拒绝名单拦截。

## Alternatives considered

**接入现行粉丝团观看任务。** 与旧背包礼物权益不同，用户明确选择不接入。

**仅停止服务器上的几条任务。** 修改开关、定时或重新登录仍可能恢复；必须在项目执行边界统一拦截。

**保留灰色占位行或只用 CSS 隐藏。** 不符合用户要求，管理端接口和旧数据库内容仍会暴露入口；改为从共享查询中排除，并删除专用展示分支。

**删除账号、任务或历史日志。** 停用无需删除历史，保留数据供用户查看。

## Consequences

此次退役保留了漫画、银瓜子兑换、每日观看、每日投币、每日经验、大会员每日经验；之后增加的[大会员积分任务](2026-11-11-bilibili-vip-points.md)不改变旧任务的停用范围。此处是用户选择的产品停用范围，不声称所有旧上游接口均已关闭。不修改数据库结构、Compose 服务或环境变量。

FeatureRemovalTest 使用显式内存数据库，验证旧任务在用户列表、管理列表与编辑数据接口中均不可见，并渲染真实模板确认七项有效任务保留；BilibiliExperienceSummaryTest 从实际账号详情控制器核对相同过滤。BilibiliSettlementTest 继续验证停用、开关、定时修改和历史保留；模板渲染不再允许「已下架」展示分支。

停用必须一路做到用户入口：FAQ 的「填写直播间ID」教程、`console/bilibili/info` 的 `is_global` 行与 `globalRoom()` 配置弹窗、`normalizeTaskConfig()`/`bilibiliViewConfig()` 的 globalroom 分支以及 `install.sql` 里那条 `globalroom` 任务行都已删除，新装实例不再种出永久停用的行。不可达性由真实入口证明——`tests/FeatureRemovalTest.php` 渲染真实模板并断言旧弹窗与文案不再出现，`tests/BilibiliSettlementTest.php` 通过 `Ajax::bilibili('set')` 这个用户实际提交的入口断言 globalroom 的保存与开关被拒且不改写旧 job 数据，同时保留任务的配置保存仍然成功。
