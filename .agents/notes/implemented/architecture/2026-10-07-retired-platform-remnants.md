# Agent Note: 清理退役平台残余并钉住其不可达性

Status: implemented

## Problem

sport(小米运动)在早于本仓库记录的时点已退役:URL 面由 cron/index 两个路由文件的 404 闭包拦截,但实现层残留五个互相关联的死件——`app/command/Sport.php`(未在 `config/console.php` 注册,`php think sport` 不可达)、其唯一依赖 `extend/sport/Step.php`、零引用的 `app/index/validate/Sport.php`、无调用方的 `Jobs::addSportJob()`、以及指向安装器不再创建的 `cloud_order` 表的 `app/index/model/Order.php`(支付移除时只删了 `app/admin/model/Order.php` 并钉住,这份 index 侧孪生被漏掉)。`tests/FeatureRemovalTest.php` 对这些没有任何「不再可达」断言,违反「功能移除必须同 commit 钉住」的根约定。

## Decision

2026-10-07 删除上述五个文件,连带移除 `Jobs::addSportJob()`、`cron Common::accountInvalid()` 的 `'sport' => '小米运动'` 死 match 分支,以及 cron 应用里冗余的 404 桩控制器 `app/cron/controller/Sport.php`(cron 应用 `url_route_must=true` + `route_complete_match=true`,桩永不派发)。FeatureRemovalTest 新增钉住:五个路径不存在、`extend/sport/` 为空、`addSportJob` 不复存在、两个路由文件的退役平台 404 映射仍在、`config/console.php` 不含 sport,并以运行时调用断言 `Ajax::sport()`/`Console::sport()` 守卫仍返回 404。iqiyi/tieba/mihoyo 在仓库里本就只剩路由映射,由同一组路由断言覆盖。

## Alternatives considered

**连 `Ajax::sport()`/`Console::sport()` 的 404 守卫一起删光。** 输因:index 应用没有 `url_route_must` 配置,控制器自动路由仍然开放,删守卫会让 `ajax/sport/x/y` 这类路由规则未枚举的多段 URL 重新落入自动路由;守卫是一行 404,不构成维护负担。

**保留 sport 命令作「历史参考」。** 输因:与支付/抖音退役的处理先例一致——不可达代码留在 main 会被误当活架构继承(2026-09-24 rejected note 的教训),且其引用的 jobs/accounts 流程已是退役流程。

## Consequences

买到:main 上不再有退役平台的实现层死件,退役面由 FeatureRemovalTest 钉住。付出:ServiceIsolationTest 失去 sport 命令这个租户隔离标本,隔离模式改由 netease/bilibili 控制器断言覆盖。
