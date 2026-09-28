# Agent Note: 用运行测试覆盖页面与存储之间的断点

Status: implemented

## Problem

源码包含某个校验字符串不代表用户可以完成操作。充值金额作用域、弹窗关闭顺序、账号链接和保存失败误报均能通过旧的字符串测试。

## Decision

[功能回归引导](../../../../tests/FunctionalDatabaseBootstrap.php)使用 SQLite 内存库和真实 ORM，覆盖保存、删除、购买、回调与密码重置。测试库不读取站点配置，使用非默认表前缀。

[模板渲染](../../../../tests/TemplateRenderingTest.php)执行模板并检查生成的脚本；[前端交互测试](../../../../tests/FrontendInteractionTest.js)执行提交函数、关闭弹窗和失败回退。Node 和 SQLite 扩展仅安装在 Docker dependencies 测试阶段。

## Alternatives considered

**只加源码字符串断言。** 无法证明值流向正确，已漏过本次多处可用性缺陷。

**连接本机安装数据库。** 依赖私有配置，测试写操作可能污染用户数据，不能作为镜像构建门禁。

**让生产镜像携带测试运行时。** 应用不需要 Node 或 SQLite，增加最终镜像依赖没有运行收益。

## Consequences

本地运行全套测试需要 Node 与 pdo_sqlite。内存库不证明 MySQL 并发锁行为；线上服务调用仍需单独验证。[审查记录](../../../../docs/USABILITY-AUDIT-2026-09-28.md)说明覆盖范围和实测边界。
