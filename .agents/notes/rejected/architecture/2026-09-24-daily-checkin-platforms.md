# Agent Note: 贴吧/夸克/天翼/阿里云盘签到功能回退

Status: rejected

## Problem

v1.2.8 尝试一次性新增贴吧、夸克、天翼、阿里云盘四个平台的每日签到(提交 `57339b7`,2026-09-24)。直接导火索是前端信息架构:四个签到入口没有归入前台控制台已有的「VIP 功能」导航分组([app/index/view/console/head.html](../../../../app/index/view/console/head.html) 中已存在 `nav-vip-heading`「VIP 功能」),而是在 `head.html` 新建了一个「每日签到」分组(`nav-main-heading`)。叠加实现本身的质量问题:单次提交 65 个文件、+4442 行,同时引入全新的平台抽象层(`PlatformRegistry`、`CheckinAdapter` 体系等十余个新 service)、四个适配器、前端控制台,并改动了生产上正在工作的 `extend/netease/sdk/GuzzleTransport.php` 与 `extend/bilibili/sdk/GuzzleTransport.php`。维护者判定不可接受,当日连同发版提交(`e1984ec`)一起回退(`84d3f79`、`d97fe3d`)。

## Decision

功能代码整体回退,main 上不保留任何残留(已核实:`extend/` 下无 tieba/quark/tianyi/aliyundrive 目录,`app/service/` 无 Checkin* / PlatformRegistry 类)。**被否决的是那次实现,不是签到功能本身**。将来若重做,必须满足:

1. 前台入口归入既有「VIP 功能」分组,**不新建导航分组**;
2. 遵循既有约定:statusTag 日志格式、调度任务名白名单、离线回归测试随功能落地;
3. 禁止从 `57339b7` cherry-pick 或以它为底稿翻修。

## Alternatives considered

**修补后保留。** 输因:导航分组本身可以小修,但维护者判定那次实现整体质量不可接受,单个缺陷的修补不改变回退结论;且发版已回退,不存在"带病留在 main 再修"的窗口。

**只回退发版、代码留在 main。** 输因:main 与实际发布长期分叉,遗留不可达代码和无人维护的平行抽象层,后续改动还会把它误当既有架构继承。

## Consequences

买到的:main 保持干净,前台导航维持「VIP 功能」单一分组的结构,现有网易/哔哩链路的 SDK transport 不被大爆炸式提交绑架。沉淀出的常设约定:**新平台任务的入口一律挂到「VIP 功能」分组下,不新建分组**(已同步为根 AGENTS.md 规则)。对将来重做的教训:新平台功能按平台逐个小步落地,不在一次提交里同时引入新抽象层、多平台、UI 并触碰现有工作代码;改动已上线的 `extend/*` SDK 必须单独提交并附回归证据。
