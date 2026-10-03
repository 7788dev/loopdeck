# Agent Note: 仅保留若依模板

Status: implemented

## Problem

用户认可当前若依视觉，并明确要求删除原版及其首页、登录模板。原有[双模板方案](../../archived/architecture/2026-10-02-site-template-switch.md)不再符合需求。

## Decision

若依视图成为 index/admin 应用的唯一视图根目录。首页、认证页和公开收款码使用固定白名单路径，不读取旧的 site_template、index_template、login_template 设置。后台模板设置路由返回 404，菜单及写入入口移除；安装页同步采用若依基础资源。

原版视图、预览资源、Codebase 资源和 Bootstrap 5 表格适配器删除。旧共享首页背景的两张图片迁入若依资源并映射旧路径，自定义背景保留。数据库旧模板字段和值保留但不参与模板选择，不做破坏性迁移；账号、任务、权限和站点信息不受影响。共享资源仍以 app_version() 失效浏览器缓存。

## Alternatives considered

**仅隐藏原版按钮。** 原版代码和写入入口仍然存在，不能满足实际删除要求。

**删除旧数据库字段。** 没有运行收益，还会妨碍旧镜像回滚；保留惰性字段即可兼容原有部署。

## Consequences

只维护一套业务视图。FeatureRemovalTest 固定旧页面和资源不再存在，AdminTemplateSettingsTest 覆盖旧设置写入拒绝，SiteTemplateTest 验证旧原版配置仍进入若依及公开收款码路径。渲染和交互测试只针对活动界面，继续覆盖账号、兑换和桌面侧栏状态。
